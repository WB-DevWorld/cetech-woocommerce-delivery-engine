<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\CustomerContext\LocationAwareDeliveryOptions;
use CetechDeliveryEngine\Application\CustomerContext\LocationOfferQuoteProbe;
use CetechDeliveryEngine\Application\CustomerContext\ProductPageDeliveryPriceQuote;
use CetechDeliveryEngine\Application\CustomerContext\ShopperDeliveryLocationPrecision;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeResolution;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Shipping\CartLineShippingAssessorInterface;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use WC_Product;

/**
 * Shared PDP precision fixtures for endpoint, renderer, and add-to-cart tests.
 */
final class PdpPrecisionTestKit {

	public const PRODUCT_ID = 101;

	public const DELIVERY_OFFER_ID = 10;

	public const PICKUP_ID = 4;

	public static function enable_flags(): FeatureFlags {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_product_delivery_selector']       = 1;
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_cart_delivery_selection_capture'] = 1;
		$flags = new FeatureFlags();
		$flags->set( 'enable_product_delivery_selector', true );
		$flags->set( 'enable_cart_delivery_selection_capture', true );

		return $flags;
	}

	public static function enable_quote_runtime(): FeatureFlags {
		$flags = self::enable_flags();
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_checkout_delivery_selection_validation'] = 1;
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_woocommerce_shipping_rate_calculation'] = 1;
		$flags->set( 'enable_checkout_delivery_selection_validation', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );

		return $flags;
	}

	public static function seed_simple_product( int $product_id = self::PRODUCT_ID ): void {
		$GLOBALS['cetech_de_test_wc_products'][ $product_id ] = new WC_Product(
			[
				'id'   => $product_id,
				'type' => 'simple',
				'name' => 'Precision fixture product',
			]
		);
	}

	public static function source( int $product_id = self::PRODUCT_ID ): ProductDeliveryConfigurationSourceInterface {
		$result = new ProductRuleResolutionResult(
			true,
			null,
			ProductTargetType::Product->value,
			$product_id,
			null,
			[],
			'precision fixture',
			[],
			[
				FulfilmentAvailability::InStore->value => new ResolvedProductDeliveryRule(
					1,
					ProductTargetType::Product->value,
					$product_id,
					null,
					1,
					FulfilmentAvailability::InStore->value,
					FulfilmentChoice::Delivery->value,
					[ self::DELIVERY_OFFER_ID ],
					null,
					null,
					null,
					100,
					self::PICKUP_ID
				),
			],
			[],
			[],
			[],
			null
		);

		return new class( $result ) implements ProductDeliveryConfigurationSourceInterface {
			public function __construct( private ProductRuleResolutionResult $result ) {
			}

			public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
				return new ProductDeliveryRuntimeResolution( $this->result, RuntimeConfigurationSource::ECR, 'fp_precision' );
			}
		};
	}

	public static function builder(): ProductDeliveryOptionsBuilder {
		$offers = new InMemoryDeliveryOfferRepository();
		$offers->seed(
			self::DELIVERY_OFFER_ID,
			[
				'status'                 => 'active',
				'public_label'           => 'Standard Delivery',
				'public_description'     => 'Door delivery',
				'route'                  => 'local_delivery',
				'duration_unit'          => 'business_days',
				'default_processing_min' => 1,
				'default_processing_max' => 2,
				'default_transit_min'    => 1,
				'default_transit_max'    => 2,
				'default_final_mile_min' => 0,
				'default_final_mile_max' => 0,
			]
		);
		$pickup = new InMemoryPickupLocationRepository();
		$pickup->seed(
			self::PICKUP_ID,
			[
				'id'            => self::PICKUP_ID,
				'status'        => 'active',
				'location_name' => 'Accra showroom',
				'public_address'=> 'Independence Avenue',
			]
		);

		return new ProductDeliveryOptionsBuilder( $offers, $pickup );
	}

	/**
	 * Faithful Accra GH₵50 / Greater Accra GH₵30 quote path using DestinationZoneMatcher
	 * and SelectedOfferShippingRateCalculator. Amounts live only in this fixture.
	 */
	public static function location_aware_quote( ShopperLocationPrecisionFixture $fx ): LocationAwareDeliveryOptions {
		$flags    = self::enable_quote_runtime();
		$source   = self::source();
		$builder  = self::builder();
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'GHS';
		$cards    = new InMemoryQuoteRateCardRepository(
			[
				[
					'id'                   => 50,
					'delivery_offer_id'    => self::DELIVERY_OFFER_ID,
					'destination_zone_id'  => 1,
					'logistics_profile_id' => null,
					'supplier_id'          => null,
					'origin_id'            => null,
					'charge_type'          => RateCardChargeType::FixedPerShipment->value,
					'base_amount'          => '50.00',
					'base_currency'        => $currency,
					'priority'             => 10,
					'status'               => 'active',
				],
				[
					'id'                   => 30,
					'delivery_offer_id'    => self::DELIVERY_OFFER_ID,
					'destination_zone_id'  => 3,
					'logistics_profile_id' => null,
					'supplier_id'          => null,
					'origin_id'            => null,
					'charge_type'          => RateCardChargeType::FixedPerShipment->value,
					'base_amount'          => '30.00',
					'base_currency'        => $currency,
					'priority'             => 100,
					'status'               => 'active',
				],
			]
		);
		$engine  = new RateQuoteEngine( $cards );
		$zones   = new PackageDestinationZoneResolver(
			new DestinationZoneMatcher(
				$fx->zones,
				new InMemoryDestinationRuleRepository(),
				new RegionCodeLabelMatcher(),
				new CoverageGroupMatcher( $fx->groups, $fx->geo->locations ),
				$fx->resolver
			)
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
		$rules = new class() implements ProductDeliveryRuleRepositoryInterface {
			public function findById( int $id ): ?array {
				return null;
			}

			public function findByTarget( string $target_type, int $target_id ): array {
				return [];
			}

			public function findByTargetAndAvailability( string $target_type, int $target_id, string $availability ): array {
				return [];
			}

			public function list( array $filters = [] ): array {
				return [];
			}

			public function listActive( array $filters = [] ): array {
				return [];
			}

			public function findActiveByTargets( array $targets ): array {
				return [];
			}

			public function save( array $data ): int {
				return 0;
			}

			public function deactivate( int $id ): bool {
				return false;
			}

			public function hardDelete( int $id ): bool {
				return false;
			}

			public function count_all(): int {
				return 0;
			}

			public function countBySupplierId( int $supplier_id ): int {
				return 0;
			}

			public function countByOriginId( int $origin_id ): int {
				return 0;
			}

			public function countByLogisticsProfileId( int $logistics_profile_id ): int {
				return 0;
			}
		};
		$calculator = new SelectedOfferShippingRateCalculator(
			new ShippingRateCalculationGate( $flags, new Requirements() ),
			$zones,
			$assessor,
			$engine,
			$rules,
			new Logger(),
			$source
		);

		return new LocationAwareDeliveryOptions(
			new LocationOfferQuoteProbe( $zones, $engine ),
			new ProductPageDeliveryPriceQuote(
				$calculator,
				new ProductDeliverySelectionValidator( $flags, new Requirements(), $source, $builder )
			),
			$fx->precision
		);
	}

	public static function always_quote_ghana( ?ShopperDeliveryLocationPrecision $precision = null ): LocationAwareDeliveryOptions {
		$zone = new class() implements PackageDestinationZoneResolverInterface {
			public function resolve_zone_id( array $destination ): ?int {
				$ids = $this->resolve_zone_ids( $destination );

				return $ids[0] ?? null;
			}

			public function resolve_zone_ids( array $destination ): array {
				return 'GH' === strtoupper( (string) ( $destination['country'] ?? '' ) ) ? [ 20 ] : [];
			}
		};
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'GHS';

		return new LocationAwareDeliveryOptions(
			new LocationOfferQuoteProbe(
				$zone,
				new RateQuoteEngine(
					new InMemoryQuoteRateCardRepository(
						[
							[
								'id'                   => 1,
								'delivery_offer_id'    => self::DELIVERY_OFFER_ID,
								'destination_zone_id'  => 20,
								'logistics_profile_id' => null,
								'supplier_id'          => null,
								'origin_id'            => null,
								'charge_type'          => RateCardChargeType::FixedPerShipment->value,
								'base_amount'          => '30.00',
								'base_currency'        => $currency,
								'priority'             => 100,
								'status'               => 'active',
							],
						]
					)
				)
			),
			null,
			$precision
		);
	}

	public static function capture( ShopperDeliveryLocationPrecision $precision ): CartDeliverySelectionCapture {
		$flags  = self::enable_flags();
		$source = self::source();
		$builder = self::builder();

		return new CartDeliverySelectionCapture(
			$flags,
			new Requirements(),
			$source,
			$builder,
			new ProductDeliverySelectionValidator( $flags, new Requirements(), $source, $builder ),
			null,
			null,
			$precision
		);
	}
}
