<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\CustomerContext\CustomerFacingDeliveryPrice;
use CetechDeliveryEngine\Application\CustomerContext\LocationAwareDeliveryOptions;
use CetechDeliveryEngine\Application\CustomerContext\LocationOfferQuoteProbe;
use CetechDeliveryEngine\Application\CustomerContext\ProductPageDeliveryPriceQuote;
use CetechDeliveryEngine\Application\CustomerContext\ProductPageQuoteContext;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Application\Shipping\CartLineShippingAssessorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;

final class ProductPageDeliveryPriceQuoteTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test bootstrap only.
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test bootstrap only.
		}
	}

	public function test_pdp_matches_calculate_for_package_fixed_per_shipment(): void {
		$this->assert_pdp_matches_package(
			RateCardChargeType::FixedPerShipment->value,
			'12.0000',
			2,
			'12.0000',
			CustomerFacingDeliveryPrice::BASIS_PER_SHIPMENT
		);
	}

	public function test_pdp_matches_calculate_for_package_fixed_per_item_quantity_one(): void {
		$this->assert_pdp_matches_package(
			RateCardChargeType::FixedPerItem->value,
			'10.0000',
			1,
			'10.0000',
			CustomerFacingDeliveryPrice::BASIS_PER_ITEM
		);
	}

	public function test_pdp_matches_calculate_for_package_fixed_per_item_quantity_greater_than_one(): void {
		$this->assert_pdp_matches_package(
			RateCardChargeType::FixedPerItem->value,
			'10.0000',
			3,
			'30.0000',
			CustomerFacingDeliveryPrice::BASIS_PER_ITEM
		);
	}

	public function test_specific_area_then_broader_area_fallback_matches_calculate_for_package(): void {
		$calculator = $this->calculator(
			[
				$this->card( 2, 11, 30, '8.0000', RateCardChargeType::FixedPerShipment->value ),
			],
			[ 20, 30 ]
		);
		$quote = $this->price_quote( $calculator );
		$intent = $this->intent( 11 );
		$cart_item = $this->cart_item( 1 );
		$destination = $this->destination();
		$currency = $this->store_currency();

		$pdp     = $quote->price_for_intent( $intent, $cart_item, $destination, $currency );
		$package = $calculator->calculate_for_package( $this->managed_package( $cart_item, $intent, $destination ) );

		self::assertNotNull( $pdp );
		self::assertTrue( $package->success );
		self::assertSame( '8.0000', $pdp->amount );
		self::assertSame( $package->total_amount, $pdp->amount );
		self::assertSame( $currency, $pdp->currency );
	}

	public function test_delivery_quotes_when_runtime_active(): void {
		$calculator = $this->calculator(
			[
				$this->card( 1, 11, 20, '12.0000', RateCardChargeType::FixedPerShipment->value ),
			],
			[ 20 ],
			true
		);

		self::assertTrue( $calculator->is_runtime_active() );

		$pdp = $this->price_quote( $calculator )->price_for_intent(
			$this->intent( 11 ),
			$this->cart_item( 1 ),
			$this->destination(),
			$this->store_currency()
		);

		self::assertNotNull( $pdp );
		self::assertSame( '12.0000', $pdp->amount );
	}

	public function test_delivery_quote_fails_closed_when_runtime_inactive(): void {
		$calculator = $this->calculator(
			[
				$this->card( 1, 11, 20, '12.0000', RateCardChargeType::FixedPerShipment->value ),
			],
			[ 20 ],
			false
		);
		$intent     = $this->intent( 11 );
		$cart_item  = $this->cart_item( 1 );
		$destination = $this->destination();
		$currency   = $this->store_currency();

		self::assertFalse( $calculator->is_runtime_active() );

		$pdp     = $this->price_quote( $calculator )->price_for_intent( $intent, $cart_item, $destination, $currency );
		$direct  = $calculator->quote_for_selection( $cart_item, $intent, $destination, $currency );
		$package = $calculator->calculate_for_package( $this->managed_package( $cart_item, $intent, $destination ) );

		self::assertNull( $pdp );
		self::assertFalse( $direct->success );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_RUNTIME_INACTIVE, $direct->block_reason );
		self::assertFalse( $package->success );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_RUNTIME_INACTIVE, $package->block_reason );
	}

	public function test_pickup_free_does_not_imply_shipping_runtime_is_active(): void {
		$calculator = $this->calculator( [], [], false );
		$quote      = $this->price_quote( $calculator );

		self::assertFalse( $calculator->is_runtime_active() );

		$price = $quote->price_for_intent(
			[
				'fulfilment_choice' => 'store_pickup',
				'delivery_offer_id' => 0,
			],
			$this->cart_item( 1 ),
			$this->destination(),
			$this->store_currency()
		);

		self::assertNotNull( $price );
		self::assertSame( '0.0000', $price->amount );
		self::assertSame( 'Free', $price->text );
		self::assertSame( CustomerFacingDeliveryPrice::BASIS_FREE, $price->basis );
	}

	public function test_pickup_is_explicit_free_when_runtime_active(): void {
		$calculator = $this->calculator( [], [], true );
		$quote      = $this->price_quote( $calculator );

		self::assertTrue( $calculator->is_runtime_active() );

		$price = $quote->price_for_intent(
			[
				'fulfilment_choice' => 'store_pickup',
				'delivery_offer_id' => 0,
			],
			$this->cart_item( 1 ),
			$this->destination(),
			$this->store_currency()
		);

		self::assertNotNull( $price );
		self::assertSame( '0.0000', $price->amount );
		self::assertSame( 'Free', $price->text );
		self::assertSame( CustomerFacingDeliveryPrice::BASIS_FREE, $price->basis );
	}

	public function test_missing_quote_fails_closed_without_zero(): void {
		$calculator = $this->calculator( [], [ 20 ] );
		$quote      = $this->price_quote( $calculator );

		$price = $quote->price_for_intent( $this->intent( 11 ), $this->cart_item( 1 ), $this->destination(), $this->store_currency() );

		self::assertNull( $price );
	}

	public function test_public_payload_omits_internal_pricing_metadata(): void {
		$option = ( new ProductDeliveryOption(
			'in_store:delivery:11',
			'in_store',
			'In Store',
			'delivery',
			'Delivery',
			11,
			'Same Day Delivery',
			null,
			'Arrives today',
			true,
			null
		) )->withCustomerPrice( CustomerFacingDeliveryPrice::from_quoted_amount( '25.0000', 'GHS', RateCardChargeType::FixedPerShipment->value ) );

		$payload = $option->toArray();
		$joined  = wp_json_encode( $payload ) ?: '';

		self::assertSame( '25.0000', $payload['price_amount'] );
		self::assertSame( 'GHS', $payload['price_currency'] );
		self::assertSame( 'GHS 25.00', $payload['price_text'] );
		self::assertSame( 'per_shipment', $payload['price_basis'] );
		self::assertStringNotContainsString( 'supplier', $joined );
		self::assertStringNotContainsString( 'origin', $joined );
		self::assertStringNotContainsString( 'logistics_profile', $joined );
		self::assertStringNotContainsString( 'rate_card', $joined );
		self::assertStringNotContainsString( 'fingerprint', $joined );
		self::assertStringNotContainsString( 'internal_code', $joined );
		self::assertArrayNotHasKey( 'matched_rate_card_id', $payload );
	}

	public function test_location_filter_omits_unquoted_delivery_options(): void {
		$calculator = $this->calculator( [], [ 20 ] );
		$probe      = new LocationOfferQuoteProbe(
			$this->createStub( PackageDestinationZoneResolverInterface::class ),
			new RateQuoteEngine( $this->cards( [] ) )
		);
		$validator  = $this->createStub( ProductDeliverySelectionValidatorInterface::class );
		$validator->method( 'validate' )->willReturn(
			ProductDeliverySelectionValidationResult::invalid( 'unquoted', 'Delivery cannot be priced.' )
		);
		$prices     = new ProductPageDeliveryPriceQuote( $calculator, $validator );

		$filter = new LocationAwareDeliveryOptions( $probe, $prices );
		$option = new ProductDeliveryOption(
			'in_store:delivery:11',
			'in_store',
			'In Store',
			'delivery',
			'Delivery',
			11,
			'Standard Delivery',
			null,
			'2-3 days',
			true,
			null
		);

		$filtered = $filter->filter(
			[ $option ],
			\CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation::fromInput(
				[
					'country'  => 'GH',
					'state'    => 'AA',
					'city'     => 'Accra',
					'postcode' => '00233',
				]
			),
			$this->store_currency(),
			ProductPageQuoteContext::from_request( 16, 0, 1 )
		);

		self::assertSame( [], $filtered );
	}

	private function assert_pdp_matches_package(
		string $charge_type,
		string $base_amount,
		int $quantity,
		string $expected_amount,
		string $expected_basis
	): void {
		$calculator = $this->calculator(
			[
				$this->card( 1, 11, 20, $base_amount, $charge_type ),
			],
			[ 20 ]
		);
		$quote       = $this->price_quote( $calculator );
		$intent      = $this->intent( 11 );
		$cart_item   = $this->cart_item( $quantity );
		$destination = $this->destination();
		$currency    = $this->store_currency();

		$pdp     = $quote->price_for_intent( $intent, $cart_item, $destination, $currency );
		$package = $calculator->calculate_for_package( $this->managed_package( $cart_item, $intent, $destination ) );

		self::assertNotNull( $pdp );
		self::assertTrue( $package->success );
		self::assertSame( $expected_amount, $pdp->amount );
		self::assertSame( $package->total_amount, $pdp->amount );
		self::assertSame( $currency, $pdp->currency );
		self::assertSame( $currency, $package->currency );
		self::assertSame( $expected_basis, $pdp->basis );
		self::assertSame( 16, (int) $cart_item['product_id'] );
		self::assertSame( 11, (int) $intent['delivery_offer_id'] );
		self::assertSame( 44, (int) $intent['rule_id'] );
	}

	private function price_quote( SelectedOfferShippingRateCalculator $calculator ): ProductPageDeliveryPriceQuote {
		return new ProductPageDeliveryPriceQuote(
			$calculator,
			$this->createStub( ProductDeliverySelectionValidatorInterface::class )
		);
	}

	/**
	 * @param list<array<string, mixed>> $cards
	 * @param list<int>                  $zone_ids
	 */
	private function calculator( array $cards, array $zone_ids, bool $runtime_active = true ): SelectedOfferShippingRateCalculator {
		$zones = $this->createStub( PackageDestinationZoneResolverInterface::class );
		$zones->method( 'resolve_zone_ids' )->willReturn( $zone_ids );

		$rules = $this->createStub( ProductDeliveryRuleRepositoryInterface::class );
		$rules->method( 'findById' )->willReturn(
			[
				'logistics_profile_id' => 7,
				'supplier_id'          => 8,
				'origin_id'            => 9,
			]
		);

		$assessor = new class() implements CartLineShippingAssessorInterface {
			public function assess_line( string $cart_item_key, array $cart_item ): array {
				$intent = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;

				return [
					'action' => 'quote',
					'intent' => is_array( $intent ) ? $intent : [],
				];
			}
		};

		return new SelectedOfferShippingRateCalculator(
			new ShippingRateCalculationGate( $this->flags( $runtime_active ), new Requirements() ),
			$zones,
			$assessor,
			new RateQuoteEngine( $this->cards( $cards ) ),
			$rules,
			new Logger()
		);
	}

	private function flags( bool $runtime_active ): FeatureFlags {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$flags->set( 'enable_product_delivery_selector', $runtime_active );
		$flags->set( 'enable_cart_delivery_selection_capture', $runtime_active );
		$flags->set( 'enable_checkout_delivery_selection_validation', $runtime_active );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', $runtime_active );

		return $flags;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $destination
	 *
	 * @return array<string, mixed>
	 */
	private function managed_package( array $cart_item, array $intent, array $destination ): array {
		$line = $cart_item;
		$line[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] = $intent;

		return [
			'contents'    => [
				'line-a' => $line,
			],
			'destination' => $destination,
			DeliveryGroupIdentity::PACKAGE_META_KEY => [
				'managed'   => true,
				'group_id'  => DeliveryGroupIdentity::compose(
					(string) $intent['fulfilment_availability'],
					(string) $intent['fulfilment_choice'],
					(string) (int) $intent['delivery_offer_id']
				),
				'is_pickup' => false,
			],
		];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function cards( array $rows ): RateCardRepositoryInterface {
		$currency = $this->store_currency();

		return new class( $rows, $currency ) implements RateCardRepositoryInterface {
			/**
			 * @param list<array<string, mixed>> $rows
			 */
			public function __construct( private array $rows, private string $currency ) {
			}

			public function findById( int $id ): ?array {
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
					$row_currency = strtoupper( (string) ( $row['base_currency'] ?? $this->currency ) );
					if (
						(int) ( $row['delivery_offer_id'] ?? 0 ) === $delivery_offer_id
						&& (int) ( $row['destination_zone_id'] ?? 0 ) === $destination_zone_id
						&& $row_currency === strtoupper( $currency_code )
					) {
						$out[] = $row;
					}
				}

				return $out;
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function card( int $id, int $offer_id, int $zone_id, string $amount, string $charge_type ): array {
		return [
			'id'                   => $id,
			'internal_code'        => 'CARD-' . $id,
			'delivery_offer_id'    => $offer_id,
			'destination_zone_id'  => $zone_id,
			'logistics_profile_id' => 7,
			'supplier_id'          => 8,
			'origin_id'            => 9,
			'charge_type'          => $charge_type,
			'base_amount'          => $amount,
			'base_currency'        => $this->store_currency(),
			'priority'             => 100,
			'status'               => 'active',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function intent( int $offer_id ): array {
		return [
			'delivery_offer_id'       => $offer_id,
			'product_id'              => 16,
			'variation_id'            => 0,
			'rule_id'                 => 44,
			'fulfilment_availability' => 'in_store',
			'fulfilment_choice'       => 'delivery',
			'target_type'             => 'product',
			'target_id'               => 16,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cart_item( int $quantity ): array {
		return [
			'product_id'   => 16,
			'variation_id' => 0,
			'quantity'     => $quantity,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function destination(): array {
		return [
			'country'  => 'GH',
			'state'    => 'AA',
			'city'     => 'Accra',
			'postcode' => '00233',
		];
	}

	private function store_currency(): string {
		return function_exists( 'get_woocommerce_currency' )
			? strtoupper( (string) get_woocommerce_currency() )
			: 'USD';
	}
}
