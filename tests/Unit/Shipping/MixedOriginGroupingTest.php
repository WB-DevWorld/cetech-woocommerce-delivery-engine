<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Shipping\CartLineShippingAssessorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Support\RateCardActiveListingTrait;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Decision D1 (option A): items whose origin / supplier / logistics-profile tuple differs form
 * separate delivery groups, each with its own charge; an item with no tuple never matches a
 * scoped rate card; quantity never changes group identity; totals are order-independent (C7).
 */
final class MixedOriginGroupingTest extends TestCase {

	private const OFFER = 10;
	private const ZONE  = 20;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_product_delivery_selector'              => 1,
			'cetech_de_enable_cart_delivery_selection_capture'        => 1,
			'cetech_de_enable_checkout_delivery_selection_validation' => 1,
			'cetech_de_enable_woocommerce_shipping_rate_calculation'  => 1,
		];
		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' );
		}
	}

	// ---- identity ---------------------------------------------------------------------------------

	public function test_delivery_segment_carries_only_the_assigned_dimensions(): void {
		self::assertSame( '10', DeliveryGroupIdentity::deliverySegment( 10, [] ) );
		self::assertSame( '10-s5', DeliveryGroupIdentity::deliverySegment( 10, [ 'supplier_id' => 5 ] ) );
		self::assertSame( '10-l2-s5-o3', DeliveryGroupIdentity::deliverySegment( 10, [ 'origin_id' => 3, 'supplier_id' => '5', 'logistics_profile_id' => 2 ] ) );
		self::assertSame( '10', DeliveryGroupIdentity::deliverySegment( 10, [ 'supplier_id' => 0, 'origin_id' => null ] ), 'zero and null are no assignment' );

		self::assertSame( 10, DeliveryGroupIdentity::offerIdFromSegment( '10-l2-s5-o3' ) );
		self::assertSame( 10, DeliveryGroupIdentity::offerIdFromSegment( '10' ) );
		self::assertNull( DeliveryGroupIdentity::offerIdFromSegment( 'p7' ) );
		self::assertSame( [ 'logistics_profile_id' => 2, 'supplier_id' => 5, 'origin_id' => 3 ], DeliveryGroupIdentity::dimensionsFromSegment( '10-l2-s5-o3' ) );
		self::assertSame( [ 'logistics_profile_id' => null, 'supplier_id' => null, 'origin_id' => null ], DeliveryGroupIdentity::dimensionsFromSegment( '10' ) );
	}

	public function test_identity_contract_and_length_budget_hold_with_the_tuple(): void {
		$plain  = DeliveryGroupIdentity::fromCartItem( $this->cart_item( $this->intent( 101, [] ) ) );
		$scoped = DeliveryGroupIdentity::fromCartItem( $this->cart_item( $this->intent( 102, [ 'supplier_id' => 5, 'origin_id' => 3 ] ) ) );

		self::assertNotNull( $plain );
		self::assertNotNull( $scoped );
		self::assertSame( '10', DeliveryGroupIdentity::parse( $plain )['offer_segment'] ?? null, 'no tuple keeps the plain offer segment' );
		self::assertNotSame( $plain, $scoped );

		$parsed = DeliveryGroupIdentity::parse( $scoped );
		self::assertNotNull( $parsed, 'the identity contract still parses' );
		self::assertSame( DeliveryGroupIdentity::parse( $plain )['version'], $parsed['version'], 'the tuple adds no segment' );
		self::assertSame( '10-s5-o3', $parsed['offer_segment'] );
		self::assertSame( 'delivery', $parsed['choice'] );
		self::assertSame( $scoped, DeliveryGroupIdentity::stripRuntimeSuffix( $scoped ) );
		self::assertFalse( DeliveryGroupIdentity::is_pickup_group( $scoped ) );

		self::assertLessThanOrEqual( DeliveryGroupIdentity::COLUMN_LENGTH, DeliveryGroupIdentity::worstCaseLength( true ) );
		self::assertLessThanOrEqual( DeliveryGroupIdentity::IDEMPOTENCY_KEY_LENGTH, 20 + 1 + DeliveryGroupIdentity::worstCaseLength( true ) );
	}

	public function test_session_normalization_keeps_positive_dimensions_only(): void {
		$intent = CartDeliverySelectionSessionData::normalizeIntent( $this->intent( 101, [ 'supplier_id' => '5', 'origin_id' => 0, 'logistics_profile_id' => null ] ) );

		self::assertSame( 5, $intent['supplier_id'] ?? null );
		self::assertArrayNotHasKey( 'origin_id', $intent );
		self::assertArrayNotHasKey( 'logistics_profile_id', $intent );

		$legacy = CartDeliverySelectionSessionData::normalizeIntent( $this->intent( 101, [] ) );
		self::assertArrayNotHasKey( 'supplier_id', $legacy );
	}

	public function test_selection_intent_round_trips_the_tuple_from_the_runtime(): void {
		$option = new ProductDeliveryOption( 'in_warehouse:delivery:10', 'in_warehouse', 'In Warehouse', 'delivery', 'Delivery', self::OFFER, 'Standard Delivery', null, '2–4 business days', true, null );
		$intent = ProductDeliverySelectionIntent::fromValidatedOption( 101, null, 'product', 101, $option, null, 'fp', [ 'logistics_profile_id' => null, 'supplier_id' => 5, 'origin_id' => 3 ] );

		$data = $intent->toArray();
		self::assertSame( 5, $data['supplier_id'] );
		self::assertSame( 3, $data['origin_id'] );
		self::assertArrayNotHasKey( 'logistics_profile_id', $data );
		self::assertSame( 5, ProductDeliverySelectionIntent::fromArray( $data )->supplier_id );
		self::assertNull( ProductDeliverySelectionIntent::fromValidatedOption( 101, null, 'product', 101, $option, null )->supplier_id );
	}

	// ---- grouping and charges ---------------------------------------------------------------------

	public function test_lines_with_different_tuples_form_separate_packages_regardless_of_order_and_quantity(): void {
		$a = $this->cart_item( $this->intent( 101, [ 'supplier_id' => 5 ] ), 1 );
		$b = $this->cart_item( $this->intent( 102, [] ), 1 );

		$ab = $this->group_ids( $this->builder()->split_package( $this->wc_package( [ 'a' => $a, 'b' => $b ] ) ) );
		$ba = $this->group_ids( $this->builder()->split_package( $this->wc_package( [ 'b' => $b, 'a' => $a ] ) ) );
		self::assertCount( 2, $ab );
		self::assertSame( $ab, $ba, 'the set of groups does not depend on add order' );

		$a3  = $this->cart_item( $this->intent( 101, [ 'supplier_id' => 5 ] ), 3 );
		$qty = $this->group_ids( $this->builder()->split_package( $this->wc_package( [ 'a' => $a3, 'b' => $b ] ) ) );
		self::assertSame( $ab, $qty, 'quantity never changes group identity' );

		$same_tuple = $this->group_ids( $this->builder()->split_package( $this->wc_package( [
			'a' => $this->cart_item( $this->intent( 101, [ 'supplier_id' => 5 ] ) ),
			'c' => $this->cart_item( $this->intent( 103, [ 'supplier_id' => 5 ] ) ),
		] ) ) );
		self::assertCount( 1, $same_tuple, 'the same tuple consolidates' );
	}

	public function test_c7_two_line_cart_totals_the_same_in_both_orders_and_after_a_quantity_change(): void {
		$cards = [
			$this->card( 4, null, '10.0000', RateCardChargeType::FixedPerShipment->value ),
			$this->card( 5, 5, '25.0000', RateCardChargeType::FixedPerShipment->value ),
		];

		$a = $this->cart_item( $this->intent( 101, [ 'supplier_id' => 5 ] ), 1 );
		$b = $this->cart_item( $this->intent( 102, [] ), 1 );

		$first  = $this->total( $cards, [ 'a' => $a, 'b' => $b ] );
		$second = $this->total( $cards, [ 'b' => $b, 'a' => $a ] );
		$more   = $this->total( $cards, [ 'a' => $this->cart_item( $this->intent( 101, [ 'supplier_id' => 5 ] ), 3 ), 'b' => $b ] );

		self::assertSame( [ '25.0000', '10.0000' ], $first, 'scoped group pays the scoped card, unscoped group pays the global card' );
		self::assertSame( array_sum( $first ), array_sum( $second ) );
		self::assertSame( array_sum( $first ), array_sum( $more ), 'fixed-per-shipment totals ignore quantity' );
	}

	public function test_per_item_charges_scale_with_quantity_but_not_with_order(): void {
		$cards = [ $this->card( 4, null, '2.0000', RateCardChargeType::FixedPerItem->value ) ];
		$a     = $this->cart_item( $this->intent( 101, [ 'supplier_id' => 5 ] ), 2 );
		$b     = $this->cart_item( $this->intent( 102, [] ), 1 );

		self::assertSame( 6.0, array_sum( $this->total( $cards, [ 'a' => $a, 'b' => $b ] ) ) );
		self::assertSame( 6.0, array_sum( $this->total( $cards, [ 'b' => $b, 'a' => $a ] ) ) );
	}

	// ---- rate-card scoping ------------------------------------------------------------------------

	public function test_unscoped_item_never_matches_a_scoped_card(): void {
		$engine = new RateQuoteEngine( new MixedOriginRateCardRepository( [ $this->card( 5, 5, '25.0000', RateCardChargeType::FixedPerShipment->value ) ] ) );

		$unscoped = $engine->quote( $this->request( null ) );
		self::assertFalse( $unscoped->success );
		self::assertSame( RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD, $unscoped->error_code );

		$scoped = $engine->quote( $this->request( 5 ) );
		self::assertTrue( $scoped->success );
		self::assertSame( 5, $scoped->matched_rate_card_id );

		$other = $engine->quote( $this->request( 6 ) );
		self::assertFalse( $other->success );
	}

	public function test_scoped_item_matches_a_global_card_and_prefers_its_own_scoped_card(): void {
		$engine = new RateQuoteEngine( new MixedOriginRateCardRepository( [
			$this->card( 4, null, '10.0000', RateCardChargeType::FixedPerShipment->value ),
			$this->card( 5, 5, '25.0000', RateCardChargeType::FixedPerShipment->value ),
		] ) );

		self::assertSame( 4, $engine->quote( $this->request( null ) )->matched_rate_card_id );
		self::assertSame( 5, $engine->quote( $this->request( 5 ) )->matched_rate_card_id );
		self::assertSame( 4, $engine->quote( $this->request( 6 ) )->matched_rate_card_id, 'a different supplier falls back to the global card' );
	}

	public function test_admin_tester_shares_the_same_scoping_rule(): void {
		$engine = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/RateQuote/RateQuoteEngine.php' );
		$tester = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Calculator/AdminRateCardTester.php' );
		preg_match( '/private function optional_dimension_matches\(.*?\n\t}\n/s', $engine, $engine_match );
		preg_match( '/private function optional_dimension_matches\(.*?\n\t}\n/s', $tester, $tester_match );

		self::assertNotEmpty( $engine_match );
		self::assertSame( $engine_match[0], $tester_match[0] ?? null );
	}

	// ---- helpers ----------------------------------------------------------------------------------

	/**
	 * @param array<string, int|string|null> $dimensions
	 * @return array<string, mixed>
	 */
	private function intent( int $product_id, array $dimensions ): array {
		return [
			'contract_version'        => '1',
			'product_id'              => $product_id,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => $product_id,
			'display_key'             => 'in_warehouse:delivery:' . self::OFFER,
			'fulfilment_availability' => 'in_warehouse',
			'fulfilment_choice'       => 'delivery',
			'delivery_offer_id'       => self::OFFER,
			'rule_id'                 => null,
			'issued_at'               => '2026-10-29T00:00:00+00:00',
		] + $dimensions;
	}

	/**
	 * @param array<string, mixed> $intent
	 * @return array<string, mixed>
	 */
	private function cart_item( array $intent, int $quantity = 1 ): array {
		return [
			'product_id'   => (int) $intent['product_id'],
			'variation_id' => 0,
			'quantity'     => $quantity,
			'line_total'   => 19.99 * $quantity,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [
				'fulfilment_availability_label' => 'In Warehouse',
				'fulfilment_choice_label'       => 'Delivery',
				'delivery_offer_public_label'   => 'Standard Delivery',
				'estimate_text'                 => '2–4 business days',
			],
			CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $intent ),
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 * @return array<string, mixed>
	 */
	private function wc_package( array $contents ): array {
		return [
			'contents'        => $contents,
			'contents_cost'   => 19.99,
			'destination'     => [ 'country' => 'GH', 'state' => '', 'city' => 'Accra', 'postcode' => '' ],
			'applied_coupons' => [],
			'user'            => [ 'ID' => 0 ],
		];
	}

	/**
	 * @param list<array<string, mixed>> $packages
	 * @return list<string>
	 */
	private function group_ids( array $packages ): array {
		$ids = [];
		foreach ( $packages as $package ) {
			$meta = DeliveryGroupIdentity::package_meta( $package );
			if ( is_array( $meta ) && isset( $meta['group_id'] ) ) {
				$ids[] = (string) $meta['group_id'];
			}
		}
		sort( $ids );

		return $ids;
	}

	/**
	 * @param list<array<string, mixed>>          $cards
	 * @param array<string, array<string, mixed>> $contents
	 * @return list<string> Per-package totals in package order.
	 */
	private function total( array $cards, array $contents ): array {
		$calculator = $this->calculator( $cards );
		$totals     = [];
		foreach ( $this->builder()->split_package( $this->wc_package( $contents ) ) as $package ) {
			$result = $calculator->calculate_for_package( $package );
			self::assertTrue( $result->success, (string) $result->block_reason );
			$totals[] = (string) $result->total_amount;
		}

		return $totals;
	}

	private function builder(): ShippingPackageBuilder {
		return new ShippingPackageBuilder(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor()
		);
	}

	/**
	 * @param list<array<string, mixed>> $cards
	 */
	private function calculator( array $cards ): SelectedOfferShippingRateCalculator {
		$zone = new class() implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				return 20;
			}

			public function resolve_zone_ids( array $destination ): array {
				return [ 20 ];
			}
		};
		$assessor = new class() implements CartLineShippingAssessorInterface {
			public function assess_line( string $cart_item_key, array $cart_item ): array {
				$intent = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;

				return [ 'action' => 'quote', 'intent' => is_array( $intent ) ? $intent : [] ];
			}
		};
		$rules = $this->createMock( ProductDeliveryRuleRepositoryInterface::class );
		$rules->method( 'findById' )->willReturn( null );

		return new SelectedOfferShippingRateCalculator(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			$zone,
			$assessor,
			new RateQuoteEngine( new MixedOriginRateCardRepository( $cards ) ),
			$rules,
			( new ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function card( int $id, ?int $supplier_id, string $amount, string $charge_type ): array {
		return [
			'id'                   => $id,
			'internal_code'        => 'CARD' . $id,
			'delivery_offer_id'    => self::OFFER,
			'destination_zone_id'  => self::ZONE,
			'logistics_profile_id' => null,
			'supplier_id'          => $supplier_id,
			'origin_id'            => null,
			'charge_type'          => $charge_type,
			'base_amount'          => $amount,
			'base_currency'        => 'USD',
			'priority'             => 100,
			'status'               => 'active',
		];
	}

	private function request( ?int $supplier_id ): RateQuoteRequest {
		return RateQuoteRequest::fromArray( [
			'delivery_offer_id'   => self::OFFER,
			'destination_zone_id' => self::ZONE,
			'quantity'            => 1,
			'currency_code'       => 'USD',
			'supplier_id'         => $supplier_id,
		] );
	}
}

/**
 * Minimal in-memory rate-card repository for the D1 grouping tests (self-contained so the file runs alone).
 */
final class MixedOriginRateCardRepository implements RateCardRepositoryInterface {

	use RateCardActiveListingTrait;

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function __construct( private array $rows ) {
	}

	public function findById( int $id ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return $row;
			}
		}

		return null;
	}

	public function findByCode( string $code ): ?array {
		return null;
	}

	public function save( array $data ): int {
		return 0;
	}

	public function list( array $criteria = [] ): array {
		return $this->rows;
	}

	public function softDelete( int $id ): bool {
		return false;
	}

	public function hardDelete( int $id ): bool {
		return false;
	}

	public function count_all(): int {
		return count( $this->rows );
	}

	public function countByDeliveryOfferId( int $delivery_offer_id ): int {
		return 0;
	}

	public function countByDestinationZoneId( int $destination_zone_id ): int {
		return 0;
	}

	public function countOrderSnapshotReferences( int $rate_card_id ): int {
		return 0;
	}

	public function countByLogisticsProfileId( int $logistics_profile_id ): int {
		return 0;
	}

	public function listActiveForQuoteMatch( int $delivery_offer_id, int $destination_zone_id, string $currency_code ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if (
				(int) ( $row['delivery_offer_id'] ?? 0 ) === $delivery_offer_id
				&& (int) ( $row['destination_zone_id'] ?? 0 ) === $destination_zone_id
				&& strtoupper( (string) ( $row['base_currency'] ?? '' ) ) === strtoupper( $currency_code )
				&& 'active' === (string) ( $row['status'] ?? '' )
			) {
				$out[] = $row;
			}
		}

		return $out;
	}
}
