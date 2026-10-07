<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

require_once __DIR__ . '/CheckoutTestFixtures.php';

use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutQuoteValidator;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class QuoteValidationTest extends TestCase {
	private CheckoutSourceFixture $source;
	private object $rate;
	private PackageDestinationZoneResolverInterface $destination;
	protected function setUp(): void {
		$GLOBALS['blog_id'] = 1; $this->source = new CheckoutSourceFixture(); $this->rate = (object) [ 'amount' => '10.00', 'type' => 'fixed_per_shipment', 'zone_calls' => [] ];
		$this->destination = $this->createMock( PackageDestinationZoneResolverInterface::class ); $this->destination->method( 'resolve_zone_ids' )->willReturn( [ 3 ] );
		if ( ! class_exists( '\WC_Shipping_Method', false ) ) {
			eval( 'class WC_Shipping_Method { public string $id=""; public int $instance_id=0; public string $title=""; public string $method_title=""; public string $method_description=""; public string $tax_status=""; public array $supports=[]; public array $instance_form_fields=[]; public function init_form_fields():void{} public function init_settings():void{} public function get_option(string $key,$default_value=""){return $default_value;} public function process_admin_options():void{} }' );
		}
	}
	protected function tearDown(): void { unset( $GLOBALS['blog_id'] ); }
	private function validator( ?callable $refresh = null ): EmergencyCheckoutQuoteValidator {
		$offers = $this->createMock( DeliveryOfferRepositoryInterface::class ); $offers->method( 'findById' )->willReturn( [ 'id' => 21, 'status' => 'active', 'route' => 'local_delivery', 'public_label' => 'Delivery' ] );
		$pickups = $this->createMock( PickupLocationRepositoryInterface::class ); $pickups->method( 'findById' )->willReturn( [ 'id' => 55, 'status' => 'active', 'location_name' => 'Pickup' ] );
		$cards = $this->createMock( RateCardRepositoryInterface::class ); $state = $this->rate;
		$cards->method( 'listActiveForQuoteMatch' )->willReturnCallback( static function ( int $offer, int $zone, string $currency ) use ( $state ): array { $state->zone_calls[] = $zone; return [ [ 'id' => 9, 'internal_code' => 'PRIVATE_RATE', 'base_amount' => $state->amount, 'charge_type' => $state->type, 'priority' => 100 ] ]; } );
		return new EmergencyCheckoutQuoteValidator( $this->source, new ProductDeliveryOptionsBuilder( $offers, $pickups ), $this->destination, new RateQuoteEngine( $cards ), null, $refresh );
	}
	private function line_snapshot( array $changes = [] ): array {
		return $changes + [ 'contract_version' => '1', 'snapshot_version' => '1', 'product_id' => 10, 'variation_id' => null, 'fulfilment_availability' => 'in_store', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 21, 'delivery_offer_public_label' => 'Delivery', 'delivery_offer_public_description' => null, 'estimate_text' => null, 'rule_id' => 7, 'destination_zone_id' => 3, 'quantity' => 1, 'currency_code' => 'GHS', 'quoted_amount' => '10.0000', 'quote_status' => 'quoted', 'rate_card_id' => 9, 'rate_card_code' => 'PRIVATE_RATE', 'snapshotted_at' => '2026-10-07T01:00:00+00:00', 'delivery_group_id' => DeliveryGroupIdentity::fromIntent( [ 'fulfilment_availability' => 'in_store', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 21 ] ) ];
	}
	private function order( array $line_changes = [], string $shipping_amount = '10.00', int $quantity = 1 ): CheckoutOrderFixture {
		$line = $this->line_snapshot( $line_changes );
		$group = $line['delivery_group_id']; $pickup = 'store_pickup' === $line['fulfilment_choice'];
		$item = new \WC_Order_Item_Product( [ 'id' => 1, 'product_id' => 10, 'quantity' => $quantity, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => json_encode( $line, JSON_THROW_ON_ERROR ), OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => $line['snapshot_version'] ] ] );
		$shipping = new CheckoutShippingFixture( [ 'method_id' => 'delivery_engine_selected_offer', 'total' => $shipping_amount, 'meta' => [ 'cetech_de_group_id' => $group ] ] );
		$package = [ 'snapshot_version' => $line['snapshot_version'], 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Delivery', 'package_total_delivery_amount' => $shipping_amount, 'currency_code' => 'GHS', 'destination_zone_id' => 3, 'quote_status' => 'success', 'snapshotted_at' => '2026-10-07T01:00:00+00:00', 'groups' => [ [ 'group_id' => $group, 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Delivery', 'package_total_delivery_amount' => $shipping_amount, 'fulfilment_choice' => $line['fulfilment_choice'], 'is_pickup' => $pickup, 'display_index' => 1 ] ] ];
		$order = new CheckoutOrderFixture( [ 'id' => 19, 'items' => [ $item ], 'shipping_items' => [ $shipping ], 'meta' => [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => json_encode( $package, JSON_THROW_ON_ERROR ), OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => $line['snapshot_version'] ] ] );
		$order->facts['shipping'] = $shipping_amount; return $order;
	}
	public function test_saved_money_and_snapshot_bytes_match_fresh_quote_without_rewrite(): void {
		$order = $this->order(); $before = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ); $line = $order->get_items()[0]->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		self::assertTrue( $this->validator()->validate_order( $order, 'order_pay' ) ); self::assertSame( $before, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) ); self::assertSame( $line, $order->get_items()[0]->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) ); self::assertSame( [ 3, 3 ], $this->rate->zone_calls );
	}
	public function test_changed_current_price_refuses_old_order_without_repricing(): void {
		$order = $this->order(); $this->rate->amount = '11'; self::assertFalse( $this->validator()->validate_order( $order, 'order_pay' ) ); self::assertSame( '10.00', $order->get_items( 'shipping' )[0]->get_total() );
	}
	public function test_missing_saved_line_quote_cannot_be_rebuilt_from_current_price(): void { self::assertFalse( $this->validator()->validate_order( $this->order( [ 'quoted_amount' => null, 'quote_status' => 'selection_only' ] ), 'order_pay' ) ); }
	public function test_package_group_identity_cannot_be_forged_to_hide_saved_group_money(): void {
		$order = $this->order(); $raw = json_decode( $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ), true, 32, JSON_THROW_ON_ERROR ); $raw['groups'][0]['group_id'] = 'in_store|delivery|o99'; $raw['groups'][0]['package_total_delivery_amount'] = '999'; $order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, json_encode( $raw, JSON_THROW_ON_ERROR ) ); self::assertFalse( $this->validator()->validate_order( $order, 'order_pay' ) );
	}
	public function test_aggregate_saved_shipping_money_must_match_actual_shipping_rows(): void { $order = $this->order(); $order->facts['shipping'] = '0'; self::assertFalse( $this->validator()->validate_order( $order, 'order_pay' ) ); }
	public function test_changed_currency_and_quantity_or_partial_snapshot_refuse(): void {
		$order = $this->order(); $order->facts['currency'] = 'USD'; self::assertFalse( $this->validator()->validate_order( $order, 'order_pay' ) );
		self::assertFalse( $this->validator()->validate_order( $this->order( [], '10', 2 ), 'order_pay' ) );
		self::assertFalse( $this->validator()->validate_order( $this->order( [ 'quantity' => '1garbage' ] ), 'order_pay' ) );
	}
	public function test_current_selection_removed_or_resolver_failed_refuses(): void { $this->source->managed = false; self::assertFalse( $this->validator()->validate_order( $this->order(), 'classic' ) ); $this->source->managed = true; $this->source->failed = true; self::assertFalse( $this->validator()->validate_order( $this->order(), 'classic' ) ); }
	public function test_per_item_quote_uses_group_quantity_not_unit_price(): void { $this->rate->type = 'fixed_per_item'; self::assertTrue( $this->validator()->validate_order( $this->order( [ 'quantity' => 2, 'quoted_amount' => '20' ], '20', 2 ), 'order_pay' ) ); self::assertFalse( $this->validator()->validate_order( $this->order( [ 'quantity' => 2 ], '10', 2 ), 'order_pay' ) ); }
	public function test_two_saved_lines_in_one_group_preserve_fixed_shipment_and_per_item_economics(): void {
		foreach ( [ 'fixed_per_shipment' => '10', 'fixed_per_item' => '20' ] as $type => $amount ) {
			$this->rate->type = $type; $base = $this->order( [], $amount ); $items = $base->get_items(); $items[] = new \WC_Order_Item_Product( [ 'id' => 2, 'product_id' => 10, 'quantity' => 1, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => json_encode( $this->line_snapshot(), JSON_THROW_ON_ERROR ), OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '1' ] ] );
			$order = new CheckoutOrderFixture( [ 'id' => 19, 'items' => $items, 'shipping_items' => $base->get_items( 'shipping' ), 'meta' => [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => $base->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ), OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '1' ] ] ); $order->facts['shipping'] = $amount;
			self::assertTrue( $this->validator()->validate_order( $order, 'order_pay' ), $type );
		}
	}
	public function test_pickup_zero_is_real_eligible_selection_not_paused_delivery_fallback(): void {
		$this->source->pickup = true; $context = CustomerCartContext::pickup( 55 );
		$changes = [ 'snapshot_version' => '2', 'fulfilment_choice' => 'store_pickup', 'delivery_offer_id' => null, 'quoted_amount' => null, 'quote_status' => 'selection_only', 'delivery_group_id' => DeliveryGroupIdentity::fromIntentAndContext( [ 'fulfilment_availability' => 'in_store', 'fulfilment_choice' => 'store_pickup' ], $context, true ), 'customer_context_version' => 1, 'pickup_location_id' => 55, 'matching_location' => null, 'delivery_address' => null, 'matching_identity' => null, 'delivery_location_identity' => null ];
		self::assertTrue( $this->validator()->validate_order( $this->order( $changes, '0' ), 'order_pay' ) ); self::assertSame( [], $this->rate->zone_calls );
		$changes['pickup_location_id'] = 56; self::assertFalse( $this->validator()->validate_order( $this->order( $changes, '0' ), 'order_pay' ) );
	}
	public function test_unproved_tax_facts_never_pass_as_zero(): void { $order = $this->order(); $order->get_items( 'shipping' )[0]->tax = '1'; self::assertFalse( $this->validator()->validate_order( $order, 'order_pay' ) ); }
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
	public function test_native_tax_per_rate_precision_and_rounded_total_are_distinct(): void {
		eval( 'function wc_tax_enabled():bool{return true;} function wc_round_tax_total($amount){return round((float)$amount,2);} class WC_Tax { public static function get_tax_class_slugs():array{return ["reduced-rate"];} public static function find_shipping_rates(array $location):array{return [5=>["rate"=>2.35]];} public static function calc_tax($amount,array $rates,bool $inclusive):array{return [5=>0.235];} }' );
		$GLOBALS['cetech_de_test_options']['woocommerce_tax_round_at_subtotal'] = 'no'; $GLOBALS['cetech_de_test_options']['woocommerce_shipping_tax_class'] = 'inherit';
		$order = $this->order(); $shipping = $order->get_items( 'shipping' )[0]; $shipping->tax = '0.24'; $shipping->taxes = [ 'total' => [ 5 => '0.2350' ] ]; $order->facts['shipping_tax'] = '0.24';
		self::assertTrue( $this->validator()->validate_order( $order, 'order_pay' ) );
		$shipping->tax = '0.2350'; $order->facts['shipping_tax'] = '0.2350'; self::assertFalse( $this->validator()->validate_order( $order, 'order_pay' ) );
	}
	public function test_refresh_happens_before_current_eligibility_and_price_read(): void { $this->source->managed = false; self::assertTrue( $this->validator( function (): void { $this->source->managed = true; } )->validate_order( $this->order(), 'classic' ) ); }
	public function test_warmed_zone_match_cannot_admit_after_the_zone_is_deactivated(): void {
		$zones = new InMemoryDestinationZoneRepository();
		$zone = [ 'id' => 3, 'internal_name' => 'Fixture area', 'internal_code' => 'FIXTURE_AREA', 'status' => 'active', 'priority' => 100, 'is_fallback' => false ];
		$zones->save( $zone );
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone( 3, [ [ 'rule_type' => 'country', 'rule_value' => 'GH', 'match_mode' => 'exact' ] ] );
		$matcher = new DestinationZoneMatcher( $zones, $rules );
		$this->destination = new PackageDestinationZoneResolver( $matcher );
		$validator = $this->validator( static function () use ( $matcher ): void { $matcher->clearMemoization(); } );
		$order = $this->order();
		$before = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true );
		self::assertTrue( $validator->validate_order( $order, 'order_pay' ) );
		$zone['status'] = 'inactive';
		$zones->save( $zone );
		self::assertFalse( $validator->validate_order( $order, 'order_pay' ) );
		self::assertSame( $before, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ) );
		self::assertSame( '10.00', $order->get_items( 'shipping' )[0]->get_total() );
	}
	public function test_warmed_zone_match_cannot_admit_after_its_country_rule_changes(): void {
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( [ 'id' => 3, 'internal_name' => 'Fixture area', 'internal_code' => 'FIXTURE_AREA', 'status' => 'active', 'priority' => 100, 'is_fallback' => false ] );
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone( 3, [ [ 'rule_type' => 'country', 'rule_value' => 'GH', 'match_mode' => 'exact' ] ] );
		$matcher = new DestinationZoneMatcher( $zones, $rules );
		$this->destination = new PackageDestinationZoneResolver( $matcher );
		$validator = $this->validator( static function () use ( $matcher ): void { $matcher->clearMemoization(); } );
		$order = $this->order();
		$before = $order->get_items()[0]->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		self::assertTrue( $validator->validate_order( $order, 'classic' ) );
		$rules->replaceForZone( 3, [ [ 'rule_type' => 'country', 'rule_value' => 'US', 'match_mode' => 'exact' ] ] );
		self::assertFalse( $validator->validate_order( $order, 'classic' ) );
		self::assertSame( $before, $order->get_items()[0]->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}
	public function test_fingerprint_binds_actual_context_money_and_private_bytes_without_exposing_them(): void { $order = $this->order(); $before = EmergencyCheckoutFacts::order_fingerprint( $order ); self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $before ); $order->facts['city'] = 'Kumasi'; self::assertNotSame( $before, EmergencyCheckoutFacts::order_fingerprint( $order ) ); }
	public function test_money_equality_is_strict_and_does_not_lose_four_decimal_precision(): void { self::assertSame( 100001, EmergencyCheckoutQuoteValidator::money_units( '10.0001' ) ); self::assertSame( 100000, EmergencyCheckoutQuoteValidator::money_units( '10.00' ) ); foreach ( [ '1e2', '-1', '1.00001', '10 trailing', 10.0, '99999999999999' ] as $bad ) { self::assertNull( EmergencyCheckoutQuoteValidator::money_units( $bad ) ); } }
}
