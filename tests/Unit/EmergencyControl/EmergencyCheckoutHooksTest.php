<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

require_once __DIR__ . '/CheckoutTestFixtures.php';

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Cart\CartCustomerContextEditorService;
use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Application\CustomerContext\LocationOfferQuoteProbe;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionControlInterface;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOrderQuoteValidatorInterface;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlReadResult;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyCheckoutHooks;
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlResponse;
use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime;
use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutValidation;
use CetechDeliveryEngine\Integrations\Blocks\BlocksStoreApiExtension;
use PHPUnit\Framework\TestCase;

final class EmergencyCheckoutHooksTest extends TestCase {
	private EmergencyBridgeControl $control;
	private EmergencyBridgeQuote $quote;
	private EmergencyControlRuntime $runtime;
	private EmergencyCheckoutAdmissionService $admission;
	private EmergencyCheckoutHooks $hooks;
	private ProductDeliveryConfigurationSourceInterface $source;
	protected function setUp(): void {
		$GLOBALS['blog_id'] = 1; $GLOBALS['cetech_de_test_options'] = []; $GLOBALS['cetech_de_test_wc'] = null;
		$GLOBALS['cetech_de_test_wc_products'] = [ 101 => new \WC_Product( [ 'id' => 101, 'type' => 'simple' ] ), 102 => new \WC_Product( [ 'id' => 102, 'type' => 'simple' ] ) ];
		$this->control = new EmergencyBridgeControl(); $this->quote = new EmergencyBridgeQuote();
		$this->source = new class implements ProductDeliveryConfigurationSourceInterface {
			public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
				return new ProductDeliveryRuntimeResolution( new ProductRuleResolutionResult( true, null, $target_type, $target_id, null, [], '', 101 === $target_id ? [ [ 'rule_id' => 1 ] ] : [], [], [], [], [], null ), 'legacy' );
			}
		};
		$classifier = new EmergencyOwnershipClassifier( [ $this->source ] ); $latch = new EmergencyOwnershipLatch();
		$this->admission = new EmergencyCheckoutAdmissionService( $this->control, $classifier, $this->quote, $latch );
		$this->runtime = new EmergencyControlRuntime( $this->control, $classifier, $latch, $this->admission );
		$this->hooks = new EmergencyCheckoutHooks( $this->runtime );
	}
	protected function tearDown(): void { $GLOBALS['cetech_de_test_wc'] = null; $GLOBALS['cetech_de_test_wc_products'] = []; }
	private function line( int $id = 101, array $extra = [] ): array { return $extra + [ 'product_id' => $id, 'variation_id' => 0, 'quantity' => 1 ]; }
	private function order( bool $paid = false, string $total = '10.00', int $id = 101 ): \WC_Order { return new \WC_Order( [ 'id' => 17, 'paid' => $paid, 'total' => $total, 'items' => [ new \WC_Order_Item_Product( [ 'product_id' => $id, 'quantity' => 1 ] ) ] ] ); }
	private function capture(): CartDeliverySelectionCapture {
		$flags = new FeatureFlags(); $requirements = new Requirements(); $builder = new ProductDeliveryOptionsBuilder( $this->createMock( DeliveryOfferRepositoryInterface::class ) );
		$validator = new ProductDeliverySelectionValidator( $flags, $requirements, $this->source, $builder, $this->runtime );
		return new CartDeliverySelectionCapture( $flags, $requirements, $this->source, $builder, $validator, null, null, null, $this->runtime );
	}
	private function legacy_blocks_validation(): BlocksCheckoutValidation {
		if ( ! class_exists( 'WooCommerce', false ) ) { eval( 'class WooCommerce {}' ); }
		$flags = new FeatureFlags(); $requirements = new Requirements();
		foreach ( [ 'enable_checkout_delivery_selection_validation', 'enable_cart_delivery_selection_capture', 'enable_product_delivery_selector' ] as $flag ) { $flags->set( $flag, true ); }
		$builder = new ProductDeliveryOptionsBuilder( $this->createMock( DeliveryOfferRepositoryInterface::class ) );
		$selection = new ProductDeliverySelectionValidator( $flags, $requirements, $this->source, $builder, $this->runtime );
		$capture = new CartDeliverySelectionCapture( $flags, $requirements, $this->source, $builder, $selection, null, null, null, $this->runtime );
		$revalidator = new CartDeliverySelectionRevalidator( $flags, $requirements, $selection, $this->runtime );
		$classic = new CheckoutDeliverySelectionValidator( $flags, $requirements, $capture, $revalidator, null, $this->runtime );
		$shipping = new ShippingRateCalculationGate( $flags, $requirements );
		return new BlocksCheckoutValidation( $classic, new BlocksStoreApiExtension( $capture, $revalidator, $shipping ), $shipping );
	}
	private function install_cart( array $lines ): object {
		$cart = new class( $lines ) {
			public function __construct( private array $lines ) {}
			public function get_cart(): array { return $this->lines; }
		};
		$GLOBALS['cetech_de_test_wc'] = (object) [ 'cart' => $cart ];
		return $cart;
	}
	public function test_always_live_cart_error_hook_precedes_the_legacy_store_api_handler(): void {
		$old = $GLOBALS['cetech_de_test_actions'] ?? []; $GLOBALS['cetech_de_test_actions'] = [];
		try {
			$this->legacy_blocks_validation()->register(); $this->hooks->register();
			$callbacks = $GLOBALS['cetech_de_test_actions']['woocommerce_store_api_cart_errors'] ?? [];
			usort( $callbacks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
			self::assertSame( -100, $callbacks[0]['priority'] ); self::assertSame( 2, $callbacks[0]['args'] );
			self::assertSame( [ $this->hooks, 'append_store_cart_errors' ], $callbacks[0]['callback'] ); self::assertSame( 10, $callbacks[1]['priority'] );
		} finally { $GLOBALS['cetech_de_test_actions'] = $old; }
	}
	public function test_paused_native_store_cart_error_keeps_control_code_before_actual_legacy_wrap(): void {
		$old = $GLOBALS['cetech_de_test_actions'] ?? []; $GLOBALS['cetech_de_test_actions'] = [];
		try {
			$this->legacy_blocks_validation()->register(); $this->hooks->register(); $this->control->pause();
			$cart = $this->install_cart( [ 'managed' => $this->line( extra: [ 'private_context' => 'PRIVATE-C07-SYNTHETIC-ADDRESS' ] ) ] ); $before = $cart->get_cart();
			$callbacks = $GLOBALS['cetech_de_test_actions']['woocommerce_store_api_cart_errors'] ?? [];
			usort( $callbacks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
			$errors = new \WP_Error(); foreach ( $callbacks as $callback ) { ( $callback['callback'] )( $errors, $cart ); }
			self::assertContains( 'cetech_de_delivery_selection', $errors->get_error_codes() );
			self::assertSame( 'cetech_de_checkout_control', $errors->get_error_code(), 'Woo Checkout converts the first inserted WP_Error code before the draft-order hooks.' );
			self::assertSame( [ 'cetech_de_checkout_control', 'cetech_de_delivery_selection' ], $errors->get_error_codes() );
			self::assertStringStartsWith( 'Delivery and pickup checkouts are temporarily paused.', $errors->get_error_message() );
			self::assertMatchesRegularExpression( '/Reference: [A-Za-z0-9._:-]+$/', $errors->get_error_message() );
			$projection = $errors->get_error_data( 'cetech_de_checkout_control' );
			self::assertSame( [ 'contract_version', 'decision_kind', 'status', 'message_key', 'message', 'recovery_action', 'correlation_id' ], array_keys( $projection ) );
			self::assertSame( 'site_control', $projection['decision_kind'] ); self::assertSame( 'refused', $projection['status'] );
			foreach ( [ 'PRIVATE-C07', 'actor_user_id', 'incident_pause', 'opened_bytes', 'site_id', 'revision' ] as $private ) { self::assertStringNotContainsString( $private, json_encode( $projection, JSON_THROW_ON_ERROR ) ); }
			self::assertSame( $before, $cart->get_cart() ); self::assertSame( 0, $this->quote->validations ); self::assertSame( 0, $this->control->confirmations ); self::assertFalse( $this->admission->admitted( $this->order(), 'store_api' ) );
		} finally { $GLOBALS['cetech_de_test_actions'] = $old; }
	}
	public function test_store_cart_error_addition_preserves_draft_reads_and_enabled_or_unmanaged_errors(): void {
		$cart = $this->install_cart( [ 'managed' => $this->line() ] ); $before = $cart->get_cart();
		$errors = new \WP_Error( 'existing_selection', 'Existing selection error.' );
		$this->hooks->append_store_cart_errors( $errors, $cart );
		self::assertSame( [ 'existing_selection' ], $errors->get_error_codes() );
		$this->control->pause(); $cart = $this->install_cart( [ 'native' => $this->line( 102 ) ] ); $reads = $this->control->reads;
		$this->hooks->append_store_cart_errors( $errors, $cart );
		self::assertSame( [ 'existing_selection' ], $errors->get_error_codes() ); self::assertSame( $reads, $this->control->reads );
		$cart = $this->install_cart( $before ); $errors = new \WP_Error();
		$this->hooks->append_store_cart_errors( $errors, $cart );
		self::assertSame( 'cetech_de_checkout_control', $errors->get_error_code() ); self::assertSame( $before, $cart->get_cart() );
		self::assertSame( 0, $this->quote->validations ); self::assertSame( 0, $this->control->confirmations ); self::assertFalse( $this->admission->admitted( $this->order(), 'store_api' ) );
	}
	public function test_store_cart_error_unavailable_is_safe_and_does_not_throw_or_mint_admission(): void {
		$this->control->unavailable = true; $cart = $this->install_cart( [ 'managed' => $this->line() ] ); $errors = new \WP_Error();
		$this->hooks->append_store_cart_errors( $errors, $cart );
		self::assertSame( 'cetech_de_checkout_control', $errors->get_error_code() );
		self::assertStringStartsWith( 'Checkout could not be confirmed.', $errors->get_error_message() ); self::assertSame( 'cetech.checkout.unavailable', $errors->get_error_data()['message_key'] );
		self::assertSame( 0, $this->quote->validations ); self::assertSame( 0, $this->control->confirmations ); self::assertFalse( $this->admission->admitted( $this->order(), 'store_api' ) );
	}
	public function test_saved_order_hook_freezes_exact_coordinates_before_classic_reloads_items(): void {
		$old_actions = $GLOBALS['cetech_de_test_actions'] ?? []; $GLOBALS['cetech_de_test_actions'] = [];
		try {
			$this->hooks->register(); $registered = $GLOBALS['cetech_de_test_actions']['woocommerce_checkout_order_created'] ?? [];
			self::assertCount( 1, $registered ); self::assertSame( PHP_INT_MAX, $registered[0]['priority'] ); self::assertSame( 1, $registered[0]['args'] );
			$item = new CheckoutPersistedItemFixture( [ 'id' => 71, 'product_id' => 101, 'quantity' => 1 ], 17 );
			$original = new \WC_Order( [ 'id' => 17, 'items' => [ $item ] ] );
			$this->hooks->bind_order_line( $item, 'line', $this->line(), $original );
			( $registered[0]['callback'] )( $original );
			$reloaded = new \WC_Order( [ 'id' => 17, 'items' => [ new CheckoutPersistedItemFixture( [ 'id' => 71, 'product_id' => 101, 'quantity' => 1 ], 17 ) ] ] );
			$this->hooks->final_classic( 17, [], $reloaded );
			self::assertSame( 1, $this->quote->validations ); self::assertSame( 1, $this->control->confirmations );
		} finally { $GLOBALS['cetech_de_test_actions'] = $old_actions; }
	}
	public function test_pause_after_early_success_refuses_paid_and_free_classic_and_store_api_before_gateway(): void {
		self::assertTrue( $this->hooks->validate_add_to_cart( true, 101, 1 ) );
		$this->control->pause(); $gateway = 0;
		foreach ( [ 'classic', 'store_api' ] as $route ) {
			foreach ( [ '10.00', '0.00' ] as $total ) {
				try { if ( 'classic' === $route ) { $this->hooks->final_classic( 17, [], $this->order( total: $total ) ); } else { $this->hooks->final_store_api( $this->order( total: $total ) ); } ++$gateway; self::fail(); }
				catch ( \Exception $error ) { self::assertStringStartsWith( 'Delivery and pickup checkouts are temporarily paused.', $error->getMessage() ); self::assertMatchesRegularExpression( '/Reference: [A-Za-z0-9._:-]+$/', $error->getMessage() ); self::assertStringNotContainsString( 'incident_pause', $error->getMessage() ); }
			}
		}
		self::assertSame( 0, $gateway ); self::assertSame( 0, $this->quote->validations ); self::assertSame( 0, $this->control->confirmations );
	}
	public function test_paid_callbacks_and_authoritatively_unmanaged_orders_continue_during_pause(): void {
		$this->control->pause(); $this->hooks->final_classic( 17, [], $this->order( paid: true ) ); $this->hooks->final_store_api( $this->order( id: 102 ) );
		self::assertSame( 0, $this->quote->validations ); self::assertSame( 0, $this->control->reads );
	}
	public function test_current_package_epoch_changes_warm_hash_and_paused_pickup_has_no_native_fallback(): void {
		$package = [ 'contents' => [ 'a' => $this->line() ], DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => true ] ];
		$enabled = $this->runtime->decorate_packages( [ $package ] ); $this->control->pause(); $paused = $this->runtime->decorate_packages( [ $package ] );
		self::assertNotSame( hash( 'sha256', serialize( $enabled ) ), hash( 'sha256', serialize( $paused ) ) );
		self::assertSame( [ 'state' => 'checkout_suspended', 'revision' => 2 ], $paused[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['checkout_control'] );
		self::assertSame( [], $this->hooks->package_rates( [ 'flat_rate:1' => new \stdClass(), 'local_pickup:2' => new \stdClass() ], $paused[0] ) );
		self::assertStringNotContainsString( 'actor', serialize( $paused ) );
	}
	public function test_flags_off_malformed_owned_line_splits_from_unmanaged_native_residual(): void {
		$this->control->pause(); $builder = new ShippingPackageBuilder( new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ), $this->capture(), $this->runtime );
		$packages = $builder->filter_packages( [ [ 'contents' => [ 'owned' => $this->line( 101, [ CartDeliverySelectionCapture::CART_SELECTION_KEY => 'broken' ] ), 'native' => $this->line( 102 ) ], 'destination' => [ 'country' => 'GH' ] ] ] );
		self::assertCount( 2, $packages ); self::assertSame( [ 'owned' ], array_keys( $packages[0]['contents'] ) ); self::assertSame( [ 'native' ], array_keys( $packages[1]['contents'] ) );
		self::assertTrue( DeliveryGroupIdentity::is_managed_package( $packages[0] ) ); self::assertFalse( DeliveryGroupIdentity::is_managed_package( $packages[1] ) );
		$rates = [ 'flat_rate:1' => new \stdClass() ]; self::assertSame( [], $this->hooks->package_rates( $rates, $packages[0] ) ); self::assertSame( $rates, $this->hooks->package_rates( $rates, $packages[1] ) );
	}
	public function test_restore_latches_before_capture_discards_malformed_marker_and_verified_removal_clears_it(): void {
		$this->control->pause();
		$line = $this->line( 102 ); $values = $line + [ CartDeliverySelectionCapture::CART_SELECTION_KEY => 'broken' ];
		$this->hooks->latch_restored_line( $line, $values, 'a' ); self::assertFalse( $this->runtime->line_allowed( 'a', $line ) );
		$cart = new class { public function get_cart_item( string $key ): mixed { return null; } };
		$this->runtime->forget_removed_line( 'a', $cart ); self::assertTrue( $this->runtime->line_allowed( 'a', $line ) );
	}
	public function test_unknown_control_denies_owned_quotes_but_not_verified_native_commerce(): void {
		$this->control->unavailable = true; self::assertFalse( $this->runtime->product_allowed( 101 ) ); self::assertTrue( $this->runtime->product_allowed( 102 ) );
		self::assertSame( [], $this->hooks->package_rates( [ 'flat_rate:1' => new \stdClass() ], [ 'contents' => [ 'a' => $this->line() ] ] ) );
		$epoch = $this->runtime->package_epoch( [ 'contents' => [ 'a' => $this->line() ] ] ); self::assertSame( [ 'state' => 'unavailable', 'revision' => 0 ], $epoch );
	}
	public function test_flags_off_capture_and_reselection_assessment_refuse_new_owned_choice(): void {
		$this->control->pause(); $capture = $this->capture();
		self::assertFalse( $capture->validate_add_to_cart( true, 101, 1 ) ); self::assertTrue( $capture->validate_add_to_cart( true, 102, 1 ) );
		self::assertSame( [ 'requirement' => 'blocked', 'options' => [] ], $capture->assess_product_selection( 101, 0 ) );
		try { $capture->add_cart_item_data( [], 101, 0 ); self::fail( 'Capture after a pause must refuse the selection.' ); } catch ( \Exception $error ) { self::assertStringStartsWith( 'Delivery and pickup checkouts are temporarily paused.', $error->getMessage() ); }
	}
	public function test_pause_between_validation_and_item_data_refuses_even_when_capture_flag_is_off(): void {
		self::assertTrue( $this->hooks->validate_add_to_cart( true, 101, 1 ) ); $this->control->pause();
		try { $this->hooks->guard_item_data( [], 101, 0 ); self::fail(); } catch ( \Exception $error ) { self::assertStringStartsWith( 'Delivery and pickup checkouts are temporarily paused.', $error->getMessage() ); }
		self::assertSame( [ 'native' => true ], $this->hooks->guard_item_data( [ 'native' => true ], 102, 0 ) );
	}
	public function test_order_pay_legacy_pending_refusal_stops_execution_without_mutating_order(): void {
		$this->control->pause(); $stopped = false; $gateway = 0; $order = $this->order();
		$hooks = new EmergencyCheckoutHooks( $this->runtime, new EmergencyControlResponse( static function (): void {}, static fn() => 'https://shop.example/checkout/', static fn() => true, static function ( bool $redirected ) use ( &$stopped ): never { $stopped = true; self::assertTrue( $redirected ); throw new EmergencyBridgeStop(); } ) );
		try { $hooks->before_pay( $order ); ++$gateway; self::fail(); } catch ( EmergencyBridgeStop ) {}
		self::assertTrue( $stopped ); self::assertSame( 0, $gateway ); self::assertSame( 'pending', $order->get_status() ); self::assertSame( 0, $this->quote->validations );
	}
	public function test_final_enabled_guard_validates_then_confirms_before_payment_without_early_stamp(): void {
		self::assertTrue( $this->runtime->product_allowed( 101 ) ); self::assertSame( 0, $this->control->confirmations );
		$this->hooks->final_classic( 17, [], $this->order() ); self::assertSame( 1, $this->quote->validations ); self::assertSame( 1, $this->control->confirmations );
	}
	public function test_more_than_two_hundred_packages_refuses_as_one_explicit_guard_not_partial_rates(): void {
		$result = $this->runtime->decorate_packages( array_fill( 0, 201, [ 'contents' => [ 'a' => $this->line( 102 ) ] ] ) );
		self::assertCount( 1, $result ); self::assertTrue( $result[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['checkout_control_limit'] ); self::assertSame( [], $this->hooks->package_rates( [ 'flat_rate:1' => new \stdClass() ], $result[0] ) );
	}
	public function test_paused_address_draft_edit_remains_usable_without_new_choice_or_quote(): void {
		$this->control->pause(); $choice = $this->createMock( ProductDeliverySelectionValidatorInterface::class ); $choice->expects( self::never() )->method( 'validate' );
		$zones = $this->createMock( PackageDestinationZoneResolverInterface::class ); $zones->expects( self::never() )->method( 'resolve_zone_ids' );
		$cards = $this->createMock( RateCardRepositoryInterface::class ); $cards->expects( self::never() )->method( 'listActiveForQuoteMatch' );
		$mutation = new CartCustomerContextMutationService();
		$apply = new ApplyCustomerContextToEligibleLinesService( $mutation, $choice, new LocationOfferQuoteProbe( $zones, new RateQuoteEngine( $cards ) ) );
		$editor = new CartCustomerContextEditorService( new FeatureFlags(), new Requirements(), $mutation, $choice, $apply, $this->runtime );
		$existing = CustomerCartContext::delivery( 31, MatchingLocation::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra' ] ) );
		$item = $this->line( 101, [ CustomerCartContext::CART_KEY => $existing->toArray() ] );
		$draft = $editor->contextFromInput( $item, [ 'matching_country' => 'GH', 'matching_state' => 'AA', 'matching_city' => 'Accra', 'address_1' => '42 New Road', 'delivery_offer_id' => 999 ] );
		self::assertInstanceOf( CustomerCartContext::class, $draft ); self::assertSame( 31, $draft->delivery_offer_id ); self::assertSame( '42 New Road', $draft->delivery_address?->address_1 );
		self::assertNull( $editor->contextFromInput( $item, [ 'display_key' => 'in_warehouse:delivery:999', 'address_1' => '42 New Road' ] ) );
		$pickup = CustomerCartContext::pickup( 22 ); $pickup_item = $this->line( 101, [ CustomerCartContext::CART_KEY => $pickup->toArray() ] );
		self::assertSame( 22, $editor->contextFromInput( $pickup_item, [ 'pickup_location_id' => 999 ] )?->pickup_location_id );
	}
	#[\PHPUnit\Framework\Attributes\DataProvider( 'malformed_package_inputs' )]
	public function test_malformed_package_or_content_cannot_disappear_into_native_admission( int $which ): void {
		$inputs = [
			[ [ 'contents' => [ 'native' => $this->line( 102 ) ] ], 'broken-package' ],
			[ [ 'contents' => [ 'owned' => $this->line( 101, [ CartDeliverySelectionCapture::CART_SELECTION_KEY => 'broken' ] ), 'native' => $this->line( 102 ), 'broken-line' => 'broken' ] ] ],
			[ [ 'contents' => 'broken-contents' ] ],
		];
		$input = $inputs[ $which ];
			$builder = new ShippingPackageBuilder( new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ), $this->capture(), $this->runtime );
			$result = $builder->filter_packages( $input );
			self::assertFalse( $this->runtime->final_order( $this->order( id: 102 ), 'classic' ), 'Unknown package evidence must survive until final admission.' );
			$refused = array_filter( $result, static fn( array $package ): bool => true === ( DeliveryGroupIdentity::package_meta( $package )['checkout_control_limit'] ?? false ) );
			self::assertNotEmpty( $refused );
			foreach ( $refused as $package ) { self::assertSame( [], $this->hooks->package_rates( [ 'flat_rate:1' => new \stdClass() ], $package ) ); }
	}
	public static function malformed_package_inputs(): array { return [ 'package' => [ 0 ], 'line' => [ 1 ], 'contents' => [ 2 ] ]; }
}

final class EmergencyBridgeControl implements EmergencyAdmissionControlInterface {
	public int $reads = 0; public int $confirmations = 0; public bool $unavailable = false; private EmergencyControlState $state;
	public function __construct() { $this->state = EmergencyControlState::absent( 1 ); }
	public function pause(): void { $this->state = EmergencyControlState::from_physical( 1, 23, EmergencyControlState::record_json( 1, 'checkout_suspended', 2, 'incident_pause', 99, 1780000000 ) ); }
	public function read( int $site_id, ?RequestContext $request = null ): EmergencyControlReadResult { ++$this->reads; return $this->unavailable ? EmergencyControlReadResult::unavailable( 'temporarily_unavailable', $request ?? RequestContext::create() ) : EmergencyControlReadResult::ready( $this->state ); }
	public function confirm_enabled( int $site_id, int $expected_revision, ?callable $unchanged_facts = null, ?RequestContext $request = null ): EmergencyControlReadResult { ++$this->confirmations; if ( ! $this->state->enabled() || $this->state->revision !== $expected_revision || ( null !== $unchanged_facts && ! $unchanged_facts() ) ) { return EmergencyControlReadResult::unavailable( 'stale_revision', $request ?? RequestContext::create() ); } return EmergencyControlReadResult::ready( $this->state ); }
}
final class EmergencyBridgeQuote implements EmergencyOrderQuoteValidatorInterface {
	public int $validations = 0;
	public function validate_order( \WC_Order $order, string $route ): bool { ++$this->validations; return true; }
	public function fingerprint( \WC_Order $order ): ?string { return hash( 'sha256', (string) $order->get_id() . ':' . $order->get_status() ); }
}
final class EmergencyBridgeStop extends \RuntimeException {}

/** WP_Error's native insertion order/data behavior for this no-WordPress unit fixture. */
final class EmergencyBridgeWpError {
	private array $errors = []; private array $data = [];
	public function __construct( string $code = '', string $message = '', mixed $data = null ) { if ( '' !== $code ) { $this->add( $code, $message, $data ); } }
	public function add( string $code, string $message, mixed $data = null ): void { $this->errors[ $code ][] = $message; if ( null !== $data ) { $this->data[ $code ] = $data; } }
	public function get_error_codes(): array { return array_keys( $this->errors ); }
	public function get_error_code(): string { return $this->get_error_codes()[0] ?? ''; }
	public function get_error_message( string $code = '' ): string { return $this->errors[ '' === $code ? $this->get_error_code() : $code ][0] ?? ''; }
	public function get_error_data( string $code = '' ): mixed { return $this->data[ '' === $code ? $this->get_error_code() : $code ] ?? null; }
}
if ( ! class_exists( '\WP_Error' ) ) { class_alias( EmergencyBridgeWpError::class, '\WP_Error' ); }
