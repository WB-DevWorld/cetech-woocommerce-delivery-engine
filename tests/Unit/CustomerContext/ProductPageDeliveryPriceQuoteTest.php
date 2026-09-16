<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

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

	public function test_pdp_quote_matches_cart_quote_for_identical_selection(): void {
		$calculator = $this->calculator(
			[
				$this->card( 1, 11, 20, '12.0000', RateCardChargeType::FixedPerShipment->value ),
			],
			[ 20 ]
		);
		$quote = $this->price_quote( $calculator );

		$intent    = $this->intent( 11 );
		$cart_item = $this->cart_item( 2 );
		$destination = [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00233' ];

		$pdp  = $quote->price_for_intent( $intent, $cart_item, $destination, 'GHS' );
		$cart = $calculator->quote_for_selection( $cart_item, $intent, $destination, 'GHS' );

		self::assertNotNull( $pdp );
		self::assertTrue( $cart->success );
		self::assertSame( $cart->total_amount, $pdp->amount );
		self::assertSame( 'GHS', $pdp->currency );
		self::assertSame( CustomerFacingDeliveryPrice::BASIS_PER_SHIPMENT, $pdp->basis );
		self::assertSame( 'GHS 12.00', $pdp->text );
	}

	public function test_fixed_per_shipment_does_not_multiply_quantity(): void {
		$calculator = $this->calculator(
			[
				$this->card( 1, 11, 20, '25.0000', RateCardChargeType::FixedPerShipment->value ),
			],
			[ 20 ]
		);
		$quote = $this->price_quote( $calculator );

		$one = $quote->price_for_intent( $this->intent( 11 ), $this->cart_item( 1 ), [ 'country' => 'GH' ], 'GHS' );
		$two = $quote->price_for_intent( $this->intent( 11 ), $this->cart_item( 3 ), [ 'country' => 'GH' ], 'GHS' );

		self::assertSame( '25.0000', $one?->amount );
		self::assertSame( '25.0000', $two?->amount );
	}

	public function test_fixed_per_item_scales_with_quantity(): void {
		$calculator = $this->calculator(
			[
				$this->card( 1, 11, 20, '10.0000', RateCardChargeType::FixedPerItem->value ),
			],
			[ 20 ]
		);
		$quote = $this->price_quote( $calculator );

		$one = $quote->price_for_intent( $this->intent( 11 ), $this->cart_item( 1 ), [ 'country' => 'GH' ], 'GHS' );
		$two = $quote->price_for_intent( $this->intent( 11 ), $this->cart_item( 3 ), [ 'country' => 'GH' ], 'GHS' );

		self::assertSame( '10.0000', $one?->amount );
		self::assertSame( '30.0000', $two?->amount );
		self::assertSame( CustomerFacingDeliveryPrice::BASIS_PER_ITEM, $two?->basis );
	}

	public function test_specific_area_then_broader_area_fallback_matches_checkout(): void {
		$calculator = $this->calculator(
			[
				$this->card( 2, 11, 30, '8.0000', RateCardChargeType::FixedPerShipment->value ),
			],
			[ 20, 30 ]
		);
		$quote = $this->price_quote( $calculator );

		$pdp  = $quote->price_for_intent( $this->intent( 11 ), $this->cart_item( 1 ), [ 'country' => 'GH' ], 'GHS' );
		$cart = $calculator->quote_for_selection( $this->cart_item( 1 ), $this->intent( 11 ), [ 'country' => 'GH' ], 'GHS' );

		self::assertSame( '8.0000', $pdp?->amount );
		self::assertSame( $cart->total_amount, $pdp?->amount );
	}

	public function test_pickup_is_explicit_free(): void {
		$calculator = $this->calculator( [], [] );
		$quote      = $this->price_quote( $calculator );

		$price = $quote->price_for_intent(
			[
				'fulfilment_choice' => 'store_pickup',
				'delivery_offer_id' => 0,
			],
			$this->cart_item( 1 ),
			[ 'country' => 'GH' ],
			'GHS'
		);

		self::assertNotNull( $price );
		self::assertSame( '0.0000', $price->amount );
		self::assertSame( 'Free', $price->text );
		self::assertSame( CustomerFacingDeliveryPrice::BASIS_FREE, $price->basis );
	}

	public function test_missing_quote_fails_closed_without_zero(): void {
		$calculator = $this->calculator( [], [ 20 ] );
		$quote      = $this->price_quote( $calculator );

		$price = $quote->price_for_intent( $this->intent( 11 ), $this->cart_item( 1 ), [ 'country' => 'GH' ], 'GHS' );

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
			'GHS',
			ProductPageQuoteContext::from_request( 16, 0, 1 )
		);

		self::assertSame( [], $filtered );
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
	private function calculator( array $cards, array $zone_ids ): SelectedOfferShippingRateCalculator {
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

		return new SelectedOfferShippingRateCalculator(
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() ),
			$zones,
			$this->createStub( CartLineShippingAssessorInterface::class ),
			new RateQuoteEngine( $this->cards( $cards ) ),
			$rules,
			new Logger()
		);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function cards( array $rows ): RateCardRepositoryInterface {
		return new class( $rows ) implements RateCardRepositoryInterface {
			/** @param list<array<string, mixed>> $rows */
			public function __construct( private array $rows ) {
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
					if (
						(int) ( $row['delivery_offer_id'] ?? 0 ) === $delivery_offer_id
						&& (int) ( $row['destination_zone_id'] ?? 0 ) === $destination_zone_id
						&& strtoupper( (string) ( $row['base_currency'] ?? '' ) ) === strtoupper( $currency_code )
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
			'base_currency'        => 'GHS',
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
}
