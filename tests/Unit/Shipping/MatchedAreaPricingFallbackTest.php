<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Shipping\CartLineShippingAssessorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingIntegration;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MatchedAreaPricingFallbackTest extends TestCase {

	private const ACCRA_ID = 30;

	private const GREATER_ACCRA_ID = 3;

	private const STANDARD_OFFER_ID = 11;

	private const AIR_OFFER_ID = 101;

	private const LOCAL_OFFER_ID = 21;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];

		if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
			eval(
				'class WC_Shipping_Method {
					public string $id = "";
					public int $instance_id = 0;
					public string $title = "";
					public string $method_title = "";
					public string $method_description = "";
					public string $tax_status = "";
					public array $supports = [];
					public array $instance_form_fields = [];
					public function init_form_fields(): void {}
					public function init_settings(): void {}
					public function get_option( string $key, $default_value = "" ) { return $default_value; }
					public function process_admin_options(): void {}
				}'
			);
		}

		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			eval( 'function get_woocommerce_currency(): string { return "USD"; }' );
		}
	}

	public function test_standard_delivery_uses_accra_specific_rate_when_both_areas_have_it(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::STANDARD_OFFER_ID, self::ACCRA_ID, '50.00' ),
				$this->card( 2, self::STANDARD_OFFER_ID, self::GREATER_ACCRA_ID, '80.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::STANDARD_OFFER_ID, FulfilmentAvailability::InStore->value ) );

		self::assertTrue( $result->success );
		self::assertSame( '50.0000', $result->total_amount );
	}

	public function test_air_shipping_falls_back_to_greater_accra_when_accra_has_no_air_rate(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::STANDARD_OFFER_ID, self::ACCRA_ID, '50.00' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value ) );

		self::assertTrue( $result->success );
		self::assertSame( '150.0000', $result->total_amount );
	}

	public function test_air_shipping_uses_accra_specific_rate_when_both_areas_have_it(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::AIR_OFFER_ID, self::ACCRA_ID, '120.00' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value ) );

		self::assertTrue( $result->success );
		self::assertSame( '120.0000', $result->total_amount );
	}

	public function test_no_matched_area_rate_for_selected_offer_fails_closed(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::STANDARD_OFFER_ID, self::ACCRA_ID, '50.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value ) );

		self::assertFalse( $result->success );
		self::assertNull( $result->total_amount );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_QUOTE_FAILED, $result->block_reason );
	}

	public function test_invalid_accra_air_rate_does_not_fall_through_to_greater_accra(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::AIR_OFFER_ID, self::ACCRA_ID, 'banana' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value ) );

		self::assertFalse( $result->success );
		self::assertNull( $result->total_amount );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_QUOTE_FAILED, $result->block_reason );
	}

	public function test_negative_accra_air_rate_does_not_fall_through_to_greater_accra(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::AIR_OFFER_ID, self::ACCRA_ID, '-5.00' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value ) );

		self::assertFalse( $result->success );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_QUOTE_FAILED, $result->block_reason );
	}

	public function test_managed_package_never_exposes_native_woocommerce_fallback(): void {
		$flags       = $this->runtime_flags_on();
		$integration = new SelectedOfferShippingIntegration( new ShippingRateCalculationGate( $flags, new Requirements() ) );
		$native      = $this->rate( 'flat_rate:1', 'flat_rate' );
		$pickup      = $this->rate( 'local_pickup:1', 'local_pickup' );
		$filtered    = $integration->filter_managed_package_rates(
			[
				'flat_rate:1'    => $native,
				'local_pickup:1' => $pickup,
			],
			$this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value )
		);

		self::assertSame( [], $filtered );
	}

	public function test_pickup_remains_zero_and_does_not_use_delivery_area_rates(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::STANDARD_OFFER_ID, self::ACCRA_ID, '50.00' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package( $this->pickup_package() );

		self::assertTrue( $result->success );
		self::assertSame( '0.0000', $result->total_amount );
	}

	public function test_in_warehouse_selected_local_delivery_does_not_use_air_rate(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::LOCAL_OFFER_ID, self::ACCRA_ID, '25.00' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::LOCAL_OFFER_ID, FulfilmentAvailability::InWarehouse->value ) );

		self::assertTrue( $result->success );
		self::assertSame( '25.0000', $result->total_amount );
	}

	public function test_international_air_does_not_substitute_standard_delivery(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::STANDARD_OFFER_ID, self::ACCRA_ID, '50.00' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package( $this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value ) );

		self::assertTrue( $result->success );
		self::assertSame( '150.0000', $result->total_amount );
		self::assertNotSame( '50.0000', $result->total_amount );
	}

	public function test_constrained_greater_accra_fallback_does_not_quote_usa(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			],
			true
		)->calculate_for_package(
			$this->managed_package(
				self::AIR_OFFER_ID,
				FulfilmentAvailability::InternationalFulfilment->value,
				[
					'country'  => 'US',
					'state'    => 'NY',
					'city'     => 'New York',
					'postcode' => '10001',
				]
			)
		);

		self::assertFalse( $result->success );
		self::assertNull( $result->total_amount );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_DESTINATION_UNRESOLVED, $result->block_reason );
	}

	public function test_ruleless_fallback_quotes_unmatched_address_for_selected_offer(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::AIR_OFFER_ID, 99, '200.00' ),
			],
			false,
			true
		)->calculate_for_package(
			$this->managed_package(
				self::AIR_OFFER_ID,
				FulfilmentAvailability::InternationalFulfilment->value,
				[
					'country'  => 'US',
					'state'    => 'NY',
					'city'     => 'New York',
					'postcode' => '10001',
				]
			)
		);

		self::assertTrue( $result->success );
		self::assertSame( '200.0000', $result->total_amount );
	}

	public function test_no_fallback_unmatched_address_fails_closed(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			]
		)->calculate_for_package(
			$this->managed_package(
				self::AIR_OFFER_ID,
				FulfilmentAvailability::InternationalFulfilment->value,
				[
					'country'  => 'US',
					'state'    => 'NY',
					'city'     => 'New York',
					'postcode' => '10001',
				]
			)
		);

		self::assertFalse( $result->success );
		self::assertSame( SelectedOfferShippingRateCalculator::BLOCK_DESTINATION_UNRESOLVED, $result->block_reason );
	}

	public function test_air_still_inherits_greater_accra_when_that_area_is_constrained_fallback(): void {
		$result = $this->calculator(
			[
				$this->card( 1, self::STANDARD_OFFER_ID, self::ACCRA_ID, '50.00' ),
				$this->card( 2, self::AIR_OFFER_ID, self::GREATER_ACCRA_ID, '150.00' ),
			],
			true
		)->calculate_for_package( $this->managed_package( self::AIR_OFFER_ID, FulfilmentAvailability::InternationalFulfilment->value ) );

		self::assertTrue( $result->success );
		self::assertSame( '150.0000', $result->total_amount );
	}

	/**
	 * @param list<array<string, mixed>> $cards
	 */
	private function calculator( array $cards, bool $greater_is_fallback = false, bool $with_global_fallback = false ): SelectedOfferShippingRateCalculator {
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save( $this->zone( self::ACCRA_ID, 'Accra' ) );
		$zones->save( $this->zone( self::GREATER_ACCRA_ID, 'Greater Accra', $greater_is_fallback ) );

		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			self::ACCRA_ID,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::City, 'Accra' ),
			]
		);
		$rules->replaceForZone(
			self::GREATER_ACCRA_ID,
			[
				$this->rule( DestinationRuleType::Country, 'GH' ),
				$this->rule( DestinationRuleType::Region, 'Greater Accra' ),
			]
		);

		if ( $with_global_fallback ) {
			$zones->save( $this->zone( 99, 'Everywhere else', true ) );
			$rules->replaceForZone( 99, [] );
		}

		$catalog = new class() implements WooCommerceStateCatalogInterface {
			public function states_for_country( string $country_code ): array {
				return 'GH' === strtoupper( trim( $country_code ) )
					? [ 'AA' => 'Greater Accra' ]
					: [];
			}
		};
		$matcher  = new DestinationZoneMatcher( $zones, $rules, new RegionCodeLabelMatcher( $catalog ) );
		$resolver = new PackageDestinationZoneResolver( $matcher );
		$product_rules = $this->createMock( ProductDeliveryRuleRepositoryInterface::class );
		$product_rules->method( 'findById' )->willReturn( null );

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
			new ShippingRateCalculationGate( $this->runtime_flags_on(), new Requirements() ),
			$resolver,
			$assessor,
			new RateQuoteEngine( new InMemoryQuoteRateCardRepository( $cards ) ),
			$product_rules,
			( new ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor()
		);
	}

	private function runtime_flags_on(): FeatureFlags {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$flags->set( 'enable_product_delivery_selector', true );
		$flags->set( 'enable_cart_delivery_selection_capture', true );
		$flags->set( 'enable_checkout_delivery_selection_validation', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );

		return $flags;
	}

	/**
	 * @return array<string, mixed>
	 */
	/**
	 * @param array<string, string> $destination
	 */
	private function managed_package( int $offer_id, string $availability, array $destination = [] ): array {
		$intent = [
			'contract_version'        => '1',
			'product_id'              => 101,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => 101,
			'display_key'             => $availability . ':delivery:' . $offer_id,
			'fulfilment_availability' => $availability,
			'fulfilment_choice'       => FulfilmentChoice::Delivery->value,
			'delivery_offer_id'       => $offer_id,
			'rule_id'                 => null,
			'issued_at'               => '2026-08-31T00:00:00+00:00',
		];

		return [
			'contents'      => [
				'line-a' => [
					'product_id'   => 101,
					'variation_id' => 0,
					'quantity'     => 1,
					'line_total'   => 403,
					CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
				],
			],
			'destination'   => [] === $destination
				? [
					'country'  => 'GH',
					'state'    => 'AA',
					'city'     => 'Accra',
					'postcode' => '',
				]
				: $destination,
			DeliveryGroupIdentity::PACKAGE_META_KEY => [
				'managed'   => true,
				'group_id'  => DeliveryGroupIdentity::compose( $availability, FulfilmentChoice::Delivery->value, (string) $offer_id ),
				'is_pickup' => false,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pickup_package(): array {
		$intent = [
			'contract_version'        => '1',
			'product_id'              => 101,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => 101,
			'display_key'             => 'in_store:store_pickup:pickup',
			'fulfilment_availability' => FulfilmentAvailability::InStore->value,
			'fulfilment_choice'       => FulfilmentChoice::StorePickup->value,
			'delivery_offer_id'       => 0,
			'rule_id'                 => null,
			'issued_at'               => '2026-08-31T00:00:00+00:00',
		];

		return [
			'contents'      => [
				'line-a' => [
					'product_id'   => 101,
					'variation_id' => 0,
					'quantity'     => 1,
					CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
				],
			],
			'destination'   => [
				'country'  => 'GH',
				'state'    => 'AA',
				'city'     => 'Accra',
				'postcode' => '',
			],
			DeliveryGroupIdentity::PACKAGE_META_KEY => [
				'managed'   => true,
				'group_id'  => DeliveryGroupIdentity::compose(
					FulfilmentAvailability::InStore->value,
					FulfilmentChoice::StorePickup->value,
					'pickup'
				),
				'is_pickup' => true,
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function zone( int $id, string $name, bool $fallback = false ): array {
		return [
			'id'            => $id,
			'internal_name' => $name,
			'public_label'  => $name,
			'status'        => RecordStatus::Active->value,
			'priority'      => 100,
			'is_fallback'   => $fallback,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rule( DestinationRuleType $type, string $value ): array {
		return [
			'rule_type'  => $type->value,
			'rule_value' => $value,
			'match_mode' => 'exact',
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function card( int $id, int $offer_id, int $zone_id, string $amount ): array {
		return [
			'id'                   => $id,
			'internal_code'        => 'CARD-' . $id,
			'delivery_offer_id'    => $offer_id,
			'destination_zone_id'  => $zone_id,
			'logistics_profile_id' => null,
			'supplier_id'          => null,
			'origin_id'            => null,
			'charge_type'          => RateCardChargeType::FixedPerShipment->value,
			'base_amount'          => $amount,
			'base_currency'        => strtoupper( (string) get_woocommerce_currency() ),
			'priority'             => 100,
			'status'               => RecordStatus::Active->value,
		];
	}

	private function rate( string $id, string $method_id ): object {
		return new class( $id, $method_id ) {
			public function __construct(
				private string $id,
				private string $method_id
			) {
			}

			public function get_id(): string {
				return $this->id;
			}

			public function get_method_id(): string {
				return $this->method_id;
			}
		};
	}
}
