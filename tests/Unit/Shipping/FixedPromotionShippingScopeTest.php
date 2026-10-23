<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Cart\{CartDeliverySelectionCapture, CartDeliverySelectionFingerprint};
use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionNativeRequests;
use CetechDeliveryEngine\Application\DeliveryQuote\{NativeCartQuotePreparation, QuoteNativeContextIdentity};
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Shipping\{CartLineShippingAssessorInterface, DeliveryGroupIdentity, SelectedOfferShippingRateCalculator, ShippingRateCalculationGate};
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\{CustomerCartContext, DeliveryAddress};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteOwner, QuoteTerms};
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{LegacyQuoteProviderFixtures, QuoteFixtures};
use PHPUnit\Framework\Attributes\{DataProvider, PreserveGlobalState, RunTestsInSeparateProcesses};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/LegacyQuoteProviderFixtures.php';

/** The scope supplies a pre-tax cost to the retained rate path; native Woo tax qualification is separate. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class FixedPromotionShippingScopeTest extends TestCase {
	protected function setUp(): void {
		if ( ! class_exists( 'WooCommerce', false ) ) { eval( 'class WooCommerce {}' ); }
		if ( ! function_exists( 'get_woocommerce_currency' ) ) { eval( 'function get_woocommerce_currency(): string { return $GLOBALS["p06_shipping_currency"] ?? "GHS"; }' ); }
		if ( ! function_exists( 'wp_salt' ) ) { eval( 'function wp_salt(string $scheme = "auth"): string { return $GLOBALS["p06_shipping_key"] ?? "private-promotion-native-shipping-key"; }' ); }
		$GLOBALS['p06_shipping_currency'] = 'GHS'; $GLOBALS['p06_shipping_key'] = 'private-promotion-native-shipping-key';
		$GLOBALS['cetech_de_test_options'] = [];
		foreach ( [ 'enable_product_delivery_selector', 'enable_cart_delivery_selection_capture', 'enable_checkout_delivery_selection_validation', 'enable_woocommerce_shipping_rate_calculation' ] as $flag ) { $GLOBALS['cetech_de_test_options']['cetech_de_' . $flag] = 1; }
	}

	public function test_explicit_scope_replaces_the_original_list_once_and_clears_after_return(): void {
		$f = $this->fixture(); $calculator = $this->calculator(); $before = $f['packages']; $request_digest = $f['requests']->digest();
		self::assertSame( '12.5000', $calculator->calculate_for_package( $f['packages'][0] )->total_amount );
		$value = $calculator->with_promotion( $f['requests'], static fn(): bool => true, function () use ( $calculator, $f ): string {
			$first = $calculator->calculate_for_package( $f['packages'][0] ); $second = $calculator->calculate_for_package( $f['packages'][0] );
			self::assertTrue( $first->success ); self::assertSame( '9.50', $first->total_amount ); self::assertSame( '9.50', $second->total_amount );
			self::assertSame( 'GHS', $first->currency ); self::assertSame( 'fixed_per_shipment', $first->charge_type );
			return 'native-totals-result';
		} );
		self::assertSame( 'native-totals-result', $value ); self::assertSame( $before, $f['packages'] ); self::assertSame( $request_digest, $f['requests']->digest() );
		self::assertSame( '12.5000', $calculator->calculate_for_package( $f['packages'][0] )->total_amount );
	}

	public function test_complete_mixed_batch_preserves_unselected_component(): void {
		$f = $this->fixture( true ); $calculator = $this->calculator();
		$amounts = $calculator->with_promotion( $f['requests'], static fn(): bool => true, static function () use ( $calculator, $f ): array {
			return array_map( static fn( array $package ): ?string => $calculator->calculate_for_package( $package )->total_amount, array_reverse( $f['packages'] ) );
		} );
		self::assertSame( [ '12.50', '9.50' ], $amounts );
	}

	#[DataProvider( 'changed_packages' )]
	public function test_stale_or_ambiguous_package_refuses_and_does_not_fall_back_to_list( string $change ): void {
		$f = $this->fixture(); $package = $f['packages'][0]; $rate = (object) [ 'amount' => '12.50' ]; $calculator = $this->calculator( $rate );
		switch ( $change ) {
			case 'quantity': $package['contents']['line_one']['quantity'] = 3; break;
			case 'product': $package['contents']['line_one']['product_id'] = 11; break;
			case 'variation': $package['contents']['line_one']['variation_id'] = 15; break;
			case 'line_key': $package['contents']['foreign_line'] = $package['contents']['line_one']; unset( $package['contents']['line_one'] ); break;
			case 'extra_member': $package['contents']['foreign_line'] = $package['contents']['line_one']; break;
			case 'group': $package['cetech_de']['group_id'] = 'foreign|delivery|20'; break;
			case 'unmanaged': $package['cetech_de']['managed'] = false; break;
			case 'pickup': $package['cetech_de']['is_pickup'] = true; break;
			case 'destination': $package['destination']['address'] = 'Changed Street'; break;
			case 'list': $rate->amount = '12.51'; break;
			case 'fractional_list': $rate->amount = '12.5001'; break;
			case 'selection_body': $package['contents']['line_one'][CartDeliverySelectionCapture::CART_SELECTION_KEY]['rule_id'] = 4; break;
			case 'selection_hash': $package['contents']['line_one'][CartDeliverySelectionCapture::CART_HASH_KEY] = str_repeat( 'a', 64 ); break;
			case 'currency': $GLOBALS['p06_shipping_currency'] = 'USD'; break;
			case 'key_epoch': $GLOBALS['p06_shipping_key'] = 'rotated-private-promotion-native-key'; break;
		}
		try {
			$calculator->with_promotion( $f['requests'], static fn(): bool => true, static function () use ( $calculator, $package ): void {
				$result = $calculator->calculate_for_package( $package ); self::assertFalse( $result->success ); self::assertNull( $result->total_amount );
			} );
			self::fail( 'A blocked component must fail the complete scope.' );
		} catch ( \RuntimeException $error ) { self::assertSame( 'Native promotion shipping unavailable.', $error->getMessage() ); }
		$GLOBALS['p06_shipping_currency'] = 'GHS'; $GLOBALS['p06_shipping_key'] = 'private-promotion-native-shipping-key'; $rate->amount = '12.50';
		self::assertSame( '12.5000', $calculator->calculate_for_package( $f['packages'][0] )->total_amount );
	}

	public static function changed_packages(): iterable {
		foreach ( [ 'quantity', 'product', 'variation', 'line_key', 'extra_member', 'group', 'unmanaged', 'pickup', 'destination', 'list', 'fractional_list', 'selection_body', 'selection_hash', 'currency', 'key_epoch' ] as $change ) { yield $change => [ $change ]; }
	}

	public function test_a_complete_native_cache_hit_cannot_qualify_without_rate_callbacks(): void {
		$f = $this->fixture(); $calculator = $this->calculator(); $this->expectException( \RuntimeException::class );
		$calculator->with_promotion( $f['requests'], static fn(): bool => true, static fn(): string => 'cached-full-price-native-totals' );
	}

	public function test_cached_or_missing_group_cannot_qualify_without_a_real_rate_callback(): void {
		$f = $this->fixture( true ); $calculator = $this->calculator();
		$this->expectException( \RuntimeException::class );
		$calculator->with_promotion( $f['requests'], static fn(): bool => true, static fn() => $calculator->calculate_for_package( $f['packages'][0] ) );
	}

	public function test_callback_failure_always_clears_scope(): void {
		$f = $this->fixture(); $calculator = $this->calculator(); $failure = new \LogicException( 'Native failure.' );
		try { $calculator->with_promotion( $f['requests'], static fn(): bool => true, static function () use ( $failure ): never { throw $failure; } ); self::fail(); }
		catch ( \LogicException $actual ) { self::assertSame( $failure, $actual ); }
		self::assertSame( '12.5000', $calculator->calculate_for_package( $f['packages'][0] )->total_amount );
	}

	public function test_late_current_guard_failure_refuses_after_the_native_calculation(): void {
		$f = $this->fixture(); $calculator = $this->calculator(); $current = true;
		$this->expectException( \RuntimeException::class );
		$calculator->with_promotion( $f['requests'], static function () use ( &$current ): bool { return $current; }, static function () use ( $calculator, $f, &$current ): void {
			self::assertSame( '9.50', $calculator->calculate_for_package( $f['packages'][0] )->total_amount ); $current = false;
		} );
	}

	public function test_initial_guard_denial_does_not_enter_native_work_and_scope_stays_off(): void {
		$f = $this->fixture(); $calculator = $this->calculator(); $entered = false;
		try { $calculator->with_promotion( $f['requests'], static fn(): bool => false, static function () use ( &$entered ): void { $entered = true; } ); self::fail(); }
		catch ( \RuntimeException ) { self::assertFalse( $entered ); }
		self::assertSame( '12.5000', $calculator->calculate_for_package( $f['packages'][0] )->total_amount );
	}

	public function test_nesting_cannot_replace_the_active_batch_and_outer_failure_clears_it(): void {
		$f = $this->fixture(); $calculator = $this->calculator();
		try { $calculator->with_promotion( $f['requests'], static fn(): bool => true, static fn() => $calculator->with_promotion( $f['requests'], static fn(): bool => true, static fn() => null ) ); self::fail(); }
		catch ( \RuntimeException ) { self::assertSame( '12.5000', $calculator->calculate_for_package( $f['packages'][0] )->total_amount ); }
	}

	public function test_a_caught_nested_scope_attempt_still_invalidates_the_outer_calculation(): void {
		$f = $this->fixture(); $calculator = $this->calculator(); $this->expectException( \RuntimeException::class );
		$calculator->with_promotion( $f['requests'], static fn(): bool => true, static function () use ( $calculator, $f ): void {
			try { $calculator->with_promotion( $f['requests'], static fn(): bool => true, static fn() => null ); } catch ( \RuntimeException ) {}
			self::assertFalse( $calculator->calculate_for_package( $f['packages'][0] )->success );
		} );
	}

	public function test_scoped_calculation_preserves_the_existing_default_off_runtime_gate(): void {
		$f = $this->fixture(); $calculator = $this->calculator(); unset( $GLOBALS['cetech_de_test_options']['cetech_de_enable_woocommerce_shipping_rate_calculation'] );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_RUNTIME_INACTIVE, $calculator->calculate_for_package( $f['packages'][0] )->block_reason );
		$this->expectException( \RuntimeException::class );
		$calculator->with_promotion( $f['requests'], static fn(): bool => true, static function () use ( $calculator, $f ): void { self::assertFalse( $calculator->calculate_for_package( $f['packages'][0] )->success ); } );
	}

	private function calculator( ?object $rate = null ): SelectedOfferShippingRateCalculator {
		$rate ??= (object) [ 'amount' => '12.50' ];
		$cards = $this->createMock( RateCardRepositoryInterface::class );
		$cards->method( 'listActiveForQuoteMatch' )->willReturnCallback( static fn( int $offer, int $zone, string $currency ): array => [ LegacyQuoteProviderFixtures::card( amount: $rate->amount, overrides: [ 'delivery_offer_id' => $offer, 'destination_zone_id' => $zone, 'base_currency' => $currency ] ) ] );
		$zones = new class implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int { return 50; }
			public function resolve_zone_ids( array $destination ): array { return [ 50 ]; }
		};
		$assessor = new class implements CartLineShippingAssessorInterface {
			public function assess_line( string $cart_item_key, array $cart_item ): array { return [ 'action' => 'quote', 'intent' => $cart_item[CartDeliverySelectionCapture::CART_SELECTION_KEY] ]; }
		};
		return new SelectedOfferShippingRateCalculator( new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ), $zones, $assessor, new RateQuoteEngine( $cards ), $this->createMock( ProductDeliveryRuleRepositoryInterface::class ), ( new \ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor() );
	}

	private function fixture( bool $mixed = false ): array {
		$identity = QuoteNativeContextIdentity::from_server(); $owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'key_epoch' => $identity->key_epoch() ] ) );
		$destination = [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address' => 'Private Fixture Street', 'address_2' => '' ];
		$context = LegacyQuoteProviderFixtures::context()->private_facts(); $terms = LegacyQuoteProviderFixtures::terms()->private_facts(); $line_seed = $context['lines'][0]; $group_seed = $context['groups'][0]; $term_seed = $terms['groups'][0];
		$context['lines'] = []; $context['groups'] = []; $terms['groups'] = []; $packages = []; $mapping = []; $selection = [];
		foreach ( $mixed ? [ 'line_one', 'line_two' ] : [ 'line_one' ] as $index => $key ) {
			$product = 10 + $index; $offer = 20 + $index;
			$intent = [ 'contract_version' => '1', 'product_id' => $product, 'variation_id' => null, 'target_type' => 'product', 'target_id' => $product, 'display_key' => 'in_warehouse:delivery:' . $offer, 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => $offer, 'rule_id' => 3 ];
			$customer = CustomerCartContext::delivery( $offer, address: DeliveryAddress::fromInput( $destination ) );
			$item = [ 'product_id' => $product, 'variation_id' => 0, 'quantity' => 2, CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent, CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $intent ), CustomerCartContext::CART_KEY => $customer->toArray() ];
			$group = DeliveryGroupIdentity::fromCartItem( $item ); $component = NativeCartQuotePreparation::component_key( $group );
			$context['lines'][] = array_replace( $line_seed, [ 'line_key' => $key, 'product_id' => $product, 'component_key' => $component ] );
			$context['groups'][] = array_replace( $group_seed, [ 'component_key' => $component, 'line_keys' => [ $key ], 'offer_id' => $offer, 'service_id' => $offer, 'endpoint_digest' => $identity->destination_digest( 1, $destination ) ] );
			$terms['groups'][] = array_replace( $term_seed, [ 'component_key' => $component ] );
			$packages[] = [ 'contents' => [ $key => $item ], 'destination' => $destination, 'cetech_de' => [ 'managed' => true, 'is_pickup' => false, 'group_id' => $group, 'rate_label' => 'Original Customer Delivery' ] ];
			$mapping[] = [ 'component_key' => $component, 'legacy_group_id' => $group ]; $selection[$key] = $item[CartDeliverySelectionCapture::CART_HASH_KEY];
		}
		ksort( $selection, SORT_STRING ); $context['selection_digest'] = hash( 'sha256', QuoteJson::encode( $selection ) );
		$context['destination'] = [ 'kind' => 'full', 'digest' => $identity->destination_digest( 1, $destination ), 'key_epoch' => $identity->key_epoch() ];
		$context = QuoteContext::from_array( $context ); $terms = QuoteTerms::from_array( $terms ); $slots = [];
		foreach ( $mapping as $index => $entry ) {
			$component = $entry['component_key']; $rule = FixedDeliveryPromotion::from_array( [ 'format_version' => 1, 'code' => 'fixture_shipping', 'version' => 1, 'discount' => [ 'amount' => '3.00', 'currency' => 'GHS', 'precision' => 2 ], 'funding_source' => [ 'code' => 'fixture_funder', 'version' => 1 ] ] );
			$plan = 0 === $index ? DeliveryPromotionPlan::from_evidence( $terms, $component, $rule, 'fixture_planning', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'fixture_planning', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => $component, 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => QuoteFixtures::digest( 'eligible' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => QuoteFixtures::digest( 'available' ) ] ] ) : null;
			$slots[] = [ 'component_key' => $component, 'plan' => $plan ];
		}
		$header = QuoteHeader::issue( QuoteId::generate(), $owner, $context, $terms, QuoteFixtures::time(), [ 'issue' => QuoteFixtures::digest( 'scope-issue' ), 'accept' => QuoteFixtures::digest( 'scope-accept' ), 'invalidate' => QuoteFixtures::digest( 'scope-invalidate' ) ], 'legacy_fixed_base_v1' );
		return [ 'packages' => $packages, 'requests' => FixedPromotionNativeRequests::from_captured( $header, $context, $terms, $slots, $mapping ) ];
	}
}
