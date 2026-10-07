<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartCustomerContextEditorService;
use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
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
use PHPUnit\Framework\TestCase;

final class EmergencyCheckoutHooksTest extends TestCase {
	private EmergencyBridgeControl $control;
	private EmergencyBridgeQuote $quote;
	private EmergencyControlRuntime $runtime;
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
		$admission = new EmergencyCheckoutAdmissionService( $this->control, $classifier, $this->quote, $latch );
		$this->runtime = new EmergencyControlRuntime( $this->control, $classifier, $latch, $admission );
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
