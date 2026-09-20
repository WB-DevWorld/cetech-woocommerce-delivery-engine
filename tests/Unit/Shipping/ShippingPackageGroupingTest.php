<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\Order\OrderDeliveryGroupSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
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
use CetechDeliveryEngine\Tests\Support\RateCardActiveListingTrait;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Stage 8A — multi-product shipping grouping matrix.
 */
final class ShippingPackageGroupingTest extends TestCase {

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

	public function test_1_single_product_forms_one_managed_package(): void {
		$packages = $this->builder()->split_package(
			$this->wc_package( [
				'line-a' => $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ) ),
			] )
		);

		self::assertCount( 1, $packages );
		self::assertTrue( DeliveryGroupIdentity::is_managed_package( $packages[0] ) );
		self::assertCount( 1, $packages[0]['contents'] );
	}

	public function test_2_quantity_two_same_product_does_not_duplicate_package(): void {
		$packages = $this->builder()->split_package(
			$this->wc_package( [
				'line-a' => $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ), 2 ),
			] )
		);

		self::assertCount( 1, $packages );
		self::assertSame( 2, (int) $packages[0]['contents']['line-a']['quantity'] );
	}

	public function test_3_two_compatible_products_consolidate(): void {
		$a = $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ) );
		$b = $this->cart_item( $this->intent( 202, null, 'in_warehouse', 'delivery', 10 ) );
		$packages = $this->builder()->split_package( $this->wc_package( [ 'line-a' => $a, 'line-b' => $b ] ) );

		self::assertCount( 1, $packages );
		self::assertCount( 2, $packages[0]['contents'] );
		self::assertSame(
			DeliveryGroupIdentity::fromCartItem( $a ),
			$packages[0][ DeliveryGroupIdentity::PACKAGE_META_KEY ]['group_id']
		);
	}

	public function test_4_two_incompatible_offers_split(): void {
		$a = $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ) );
		$b = $this->cart_item( $this->intent( 202, null, 'in_warehouse', 'delivery', 20 ) );
		$packages = $this->builder()->split_package( $this->wc_package( [ 'line-a' => $a, 'line-b' => $b ] ) );

		self::assertCount( 2, $packages );
		$group_ids = array_map(
			static fn ( array $p ): string => (string) $p[ DeliveryGroupIdentity::PACKAGE_META_KEY ]['group_id'],
			$packages
		);
		self::assertCount( 2, array_unique( $group_ids ) );
	}

	public function test_5_pickup_and_delivery_split_and_pickup_not_charged(): void {
		$pickup   = $this->cart_item( $this->intent( 101, null, 'in_store', 'store_pickup', null ) );
		$delivery = $this->cart_item( $this->intent( 202, null, 'in_warehouse', 'delivery', 10 ) );
		$packages = $this->builder()->split_package(
			$this->wc_package( [ 'line-p' => $pickup, 'line-d' => $delivery ] )
		);

		self::assertCount( 2, $packages );

		$pickup_package   = null;
		$delivery_package = null;
		foreach ( $packages as $package ) {
			if ( ! empty( $package[ DeliveryGroupIdentity::PACKAGE_META_KEY ]['is_pickup'] ) ) {
				$pickup_package = $package;
			} else {
				$delivery_package = $package;
			}
		}

		self::assertNotNull( $pickup_package );
		self::assertNotNull( $delivery_package );

		$calculator      = $this->calculator( $this->valid_assessor(), '25.00', RateCardChargeType::FixedPerShipment->value );
		$pickup_result   = $calculator->calculate_for_package( $pickup_package );
		$delivery_result = $calculator->calculate_for_package( $delivery_package );

		self::assertTrue( $pickup_result->success );
		self::assertSame( '0.0000', $pickup_result->total_amount );
		self::assertTrue( $delivery_result->success );
		self::assertSame( '25.0000', $delivery_result->total_amount );
	}

	public function test_6_local_and_international_split(): void {
		$local = $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ) );
		$intl  = $this->cart_item( $this->intent( 202, null, 'international_fulfilment', 'delivery', 30 ) );
		$packages = $this->builder()->split_package( $this->wc_package( [ 'line-l' => $local, 'line-i' => $intl ] ) );

		self::assertCount( 2, $packages );
	}

	public function test_7_compatible_variations_group_by_selected_variation_intent(): void {
		$a = $this->cart_item( $this->intent( 39717, 39718, 'in_warehouse', 'delivery', 10 ) );
		$b = $this->cart_item( $this->intent( 39717, 39719, 'in_warehouse', 'delivery', 10 ) );
		$packages = $this->builder()->split_package( $this->wc_package( [ 'line-a' => $a, 'line-b' => $b ] ) );

		self::assertCount( 1, $packages );
		self::assertSame( 39718, (int) $packages[0]['contents']['line-a']['variation_id'] );
		self::assertSame( 39719, (int) $packages[0]['contents']['line-b']['variation_id'] );
	}

	public function test_8_variation_incompatible_override_splits(): void {
		$a = $this->cart_item( $this->intent( 39717, 39718, 'in_warehouse', 'delivery', 10 ) );
		$b = $this->cart_item( $this->intent( 39717, 39719, 'international_fulfilment', 'delivery', 40 ) );
		$packages = $this->builder()->split_package( $this->wc_package( [ 'line-a' => $a, 'line-b' => $b ] ) );

		self::assertCount( 2, $packages );
	}

	public function test_9_group_ids_survive_session_restore_round_trip(): void {
		$intent = $this->intent( 101, 202, 'in_warehouse', 'delivery', 10 );
		$hash   = CartDeliverySelectionFingerprint::fromIntent( $intent );
		$restored = CartDeliverySelectionSessionData::restoreFromSession(
			[
				CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
				CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [
					'fulfilment_availability_label' => 'In warehouse',
					'fulfilment_choice_label'       => 'Delivery',
					'delivery_offer_public_label'   => 'Standard Delivery',
					'estimate_text'                 => '3-6 business days',
				],
				CartDeliverySelectionCapture::CART_HASH_KEY => $hash,
			]
		);

		self::assertNotNull( $restored );
		self::assertSame(
			DeliveryGroupIdentity::fromIntent( $intent ),
			DeliveryGroupIdentity::fromIntent( $restored['intent'] )
		);
	}

	public function test_10_invalid_group_fails_closed_not_free(): void {
		$package = $this->wc_package( [
			'line-a' => $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ) ),
		] );
		$package[ DeliveryGroupIdentity::PACKAGE_META_KEY ] = [
			'managed'   => true,
			'group_id'  => DeliveryGroupIdentity::compose( 'in_warehouse', 'delivery', '10' ),
			'is_pickup' => false,
		];

		$assessor = new class() implements CartLineShippingAssessorInterface {
			public function assess_line( string $cart_item_key, array $cart_item ): array {
				return [
					'action' => 'block',
					'reason' => SelectedOfferShippingRateCalculator::BLOCK_LINE_INVALID,
				];
			}
		};

		$result = $this->calculator( $assessor, '25.00', RateCardChargeType::FixedPerShipment->value )
			->calculate_for_package( $package );

		self::assertFalse( $result->success );
		self::assertNull( $result->total_amount );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_LINE_INVALID, $result->block_reason );
	}

	public function test_11_order_snapshot_preserves_line_to_group_relationship(): void {
		$group_id = DeliveryGroupIdentity::compose( 'in_warehouse', 'delivery', '10' );
		$line     = new OrderDeliveryLineSnapshot(
			'1',
			OrderDeliverySnapshot::VERSION,
			101,
			null,
			'in_warehouse',
			'delivery',
			10,
			'Standard Delivery',
			null,
			'3-6 business days',
			null,
			20,
			1,
			'USD',
			'25.0000',
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			4,
			'STAGE0B',
			'2026-08-12T00:00:00+00:00',
			$group_id
		);

		self::assertSame( $group_id, $line->toArray()['delivery_group_id'] );

		$package = new OrderDeliveryPackageSnapshot(
			OrderDeliverySnapshot::VERSION,
			'delivery_engine_selected_offer',
			'Delivery',
			'25.0000',
			'USD',
			20,
			OrderDeliverySnapshot::PACKAGE_STATUS_SUCCESS,
			'2026-08-12T00:00:00+00:00',
			[
				new OrderDeliveryGroupSnapshot(
					$group_id,
					'delivery_engine_selected_offer',
					'Delivery',
					'25.0000',
					'delivery',
					false,
					1
				),
			]
		);

		self::assertSame( $group_id, $package->toArray()['groups'][0]['group_id'] );
	}

	public function test_12_compatible_products_fixed_per_shipment_quotes_once_not_doubled(): void {
		$a = $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ) );
		$b = $this->cart_item( $this->intent( 202, null, 'in_warehouse', 'delivery', 10 ) );
		$package = $this->wc_package( [ 'line-a' => $a, 'line-b' => $b ] );
		$package[ DeliveryGroupIdentity::PACKAGE_META_KEY ] = [
			'managed'   => true,
			'group_id'  => DeliveryGroupIdentity::compose( 'in_warehouse', 'delivery', '10' ),
			'is_pickup' => false,
		];

		$result = $this->calculator( $this->valid_assessor(), '25.00', RateCardChargeType::FixedPerShipment->value )
			->calculate_for_package( $package );

		self::assertTrue( $result->success );
		self::assertSame( '25.0000', $result->total_amount );
	}

	public function test_quantity_two_fixed_per_item_respects_quantity(): void {
		$item = $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ), 2 );
		$package = $this->wc_package( [ 'line-a' => $item ] );
		$package[ DeliveryGroupIdentity::PACKAGE_META_KEY ] = [
			'managed'   => true,
			'group_id'  => DeliveryGroupIdentity::compose( 'in_warehouse', 'delivery', '10' ),
			'is_pickup' => false,
		];

		$result = $this->calculator( $this->valid_assessor(), '10.00', RateCardChargeType::FixedPerItem->value )
			->calculate_for_package( $package );

		self::assertTrue( $result->success );
		self::assertSame( '20.0000', $result->total_amount );
	}

	public function test_filter_packages_assigns_delivery_labels_when_multiple_groups(): void {
		$a = $this->cart_item( $this->intent( 101, null, 'in_warehouse', 'delivery', 10 ) );
		$b = $this->cart_item( $this->intent( 202, null, 'international_fulfilment', 'delivery', 30 ) );
		$packages = $this->builder()->filter_packages( [ $this->wc_package( [ 'line-a' => $a, 'line-b' => $b ] ) ] );

		self::assertCount( 2, $packages );
		$labels = array_map(
			static fn ( array $p ): string => (string) $p[ DeliveryGroupIdentity::PACKAGE_META_KEY ]['rate_label'],
			$packages
		);
		self::assertContains( 'Standard Delivery (1)', $labels );
		self::assertContains( 'Standard Delivery (2)', $labels );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent(
		int $product_id,
		?int $variation_id,
		string $availability,
		string $choice,
		?int $offer_id
	): array {
		$target_id = $variation_id ?? $product_id;
		$suffix    = null === $offer_id ? 'pickup' : (string) $offer_id;

		return [
			'contract_version'        => '1',
			'product_id'              => $product_id,
			'variation_id'            => $variation_id,
			'target_type'             => null !== $variation_id ? 'variation' : 'product',
			'target_id'               => $target_id,
			'display_key'             => $availability . ':' . $choice . ':' . $suffix,
			'fulfilment_availability' => $availability,
			'fulfilment_choice'       => $choice,
			'delivery_offer_id'       => $offer_id,
			'rule_id'                 => null,
			'issued_at'               => '2026-08-12T00:00:00+00:00',
		];
	}

	/**
	 * @param array<string, mixed> $intent
	 *
	 * @return array<string, mixed>
	 */
	private function cart_item( array $intent, int $quantity = 1 ): array {
		return [
			'product_id'   => (int) $intent['product_id'],
			'variation_id' => (int) ( $intent['variation_id'] ?? 0 ),
			'quantity'     => $quantity,
			'line_total'   => 19.99 * $quantity,
			CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
			CartDeliverySelectionCapture::CART_SUMMARY_KEY   => [
				'fulfilment_availability_label' => 'Label',
				'fulfilment_choice_label'       => 'Delivery',
				'delivery_offer_public_label'   => 'Standard Delivery',
				'estimate_text'                 => '3-6 business days',
			],
			CartDeliverySelectionCapture::CART_HASH_KEY => CartDeliverySelectionFingerprint::fromIntent( $intent ),
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, mixed>
	 */
	private function wc_package( array $contents ): array {
		return [
			'contents'        => $contents,
			'contents_cost'   => 19.99,
			'destination'     => [
				'country'  => 'GH',
				'state'    => '',
				'city'     => 'Accra',
				'postcode' => '',
			],
			'applied_coupons' => [],
			'user'            => [ 'ID' => 0 ],
		];
	}

	private function builder(): ShippingPackageBuilder {
		return new ShippingPackageBuilder(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			( new ReflectionClass( CartDeliverySelectionCapture::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function valid_assessor(): CartLineShippingAssessorInterface {
		return new class() implements CartLineShippingAssessorInterface {
			public function assess_line( string $cart_item_key, array $cart_item ): array {
				$intent = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;

				return [
					'action' => 'quote',
					'intent' => is_array( $intent ) ? $intent : [],
				];
			}
		};
	}

	private function calculator(
		CartLineShippingAssessorInterface $assessor,
		string $base_amount,
		string $charge_type
	): SelectedOfferShippingRateCalculator {
		$zone = new class() implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				return 20;
			}

			public function resolve_zone_ids( array $destination ): array {
				$id = $this->resolve_zone_id( $destination );

				return null === $id ? [] : [ $id ];
			}
		};

		$rules = $this->createMock( ProductDeliveryRuleRepositoryInterface::class );
		$rules->method( 'findById' )->willReturn( null );

		return new SelectedOfferShippingRateCalculator(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			$zone,
			$assessor,
			new RateQuoteEngine(
				new Stage8aFixedRateCardRepository( [
					[
						'id'                   => 4,
						'internal_code'        => 'STAGE0B',
						'delivery_offer_id'    => 10,
						'destination_zone_id'  => 20,
						'logistics_profile_id' => null,
						'supplier_id'          => null,
						'origin_id'            => null,
						'charge_type'          => $charge_type,
						'base_amount'          => $base_amount,
						'base_currency'        => 'USD',
						'priority'             => 100,
						'status'               => 'active',
					],
				] )
			),
			$rules,
			( new ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor()
		);
	}
}

/**
 * Minimal rate-card repository for Stage 8A shipping grouping tests.
 */
final class Stage8aFixedRateCardRepository implements RateCardRepositoryInterface {

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

	public function listActiveForQuoteMatch(
		int $delivery_offer_id,
		int $destination_zone_id,
		string $currency_code
	): array {
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
