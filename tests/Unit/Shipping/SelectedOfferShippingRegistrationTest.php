<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Shipping\CartLineShippingAssessorInterface;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingIntegration;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Registry listing is independent of storefront flags; rates remain gated.
 */
final class SelectedOfferShippingRegistrationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

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

	public function test_method_is_registered_when_storefront_flags_are_off(): void {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$integration = $this->integration( $flags );

		self::assertFalse( ( new ShippingRateCalculationGate( $flags, new Requirements() ) )->is_runtime_active() );

		$registered = $integration->register_shipping_method( $this->native_methods() );

		self::assertArrayHasKey( SelectedOfferShippingMethod::METHOD_ID, $registered );
		self::assertSame( SelectedOfferShippingMethod::class, $registered[ SelectedOfferShippingMethod::METHOD_ID ] );
		self::assertSame( 'Delivery', SelectedOfferShippingMethod::RATE_LABEL );
	}

	public function test_registering_twice_does_not_duplicate_the_method(): void {
		$integration = $this->integration( new FeatureFlags() );

		$first  = $integration->register_shipping_method( $this->native_methods() );
		$second = $integration->register_shipping_method( $first );

		$matches = array_filter(
			array_keys( $second ),
			static fn ( string $id ): bool => SelectedOfferShippingMethod::METHOD_ID === $id
		);

		self::assertCount( 1, $matches );
		self::assertSame( SelectedOfferShippingMethod::class, $second[ SelectedOfferShippingMethod::METHOD_ID ] );
		self::assertCount( 4, $second );
	}

	public function test_flags_off_emit_no_delivery_engine_rate(): void {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();

		$result = $this->calculator( $flags )->calculate_for_package( $this->managed_package() );

		self::assertFalse( $result->success );
		self::assertNull( $result->total_amount );
		self::assertSame( 'runtime_inactive', $result->block_reason );
	}

	public function test_flags_off_preserve_native_package_rates_on_managed_packages(): void {
		$flags = new FeatureFlags();
		$flags->ensure_defaults();
		$integration = $this->integration( $flags );

		$native = $this->rate( 'flat_rate:1', 'flat_rate' );
		$de     = $this->rate( SelectedOfferShippingMethod::METHOD_ID . ':1', SelectedOfferShippingMethod::METHOD_ID );
		$rates  = [
			'flat_rate:1' => $native,
			SelectedOfferShippingMethod::METHOD_ID . ':1' => $de,
		];

		$filtered = $integration->filter_managed_package_rates( $rates, $this->managed_package() );

		self::assertSame( $rates, $filtered );
		self::assertArrayHasKey( 'flat_rate:1', $filtered );
	}

	public function test_flags_on_quote_a_normal_delivery_engine_rate_and_hide_native_on_managed_packages(): void {
		$flags = $this->runtime_flags_on();
		$gate  = new ShippingRateCalculationGate( $flags, new Requirements() );
		self::assertTrue( $gate->is_runtime_active() );

		$result = $this->calculator( $flags )->calculate_for_package( $this->managed_package() );
		self::assertTrue( $result->success );
		self::assertSame( '25.0000', $result->total_amount );
		self::assertSame( 'USD', $result->currency );

		$integration = $this->integration( $flags );
		$native      = $this->rate( 'flat_rate:1', 'flat_rate' );
		$de          = $this->rate( SelectedOfferShippingMethod::METHOD_ID . ':1', SelectedOfferShippingMethod::METHOD_ID );
		$filtered    = $integration->filter_managed_package_rates(
			[
				'flat_rate:1' => $native,
				SelectedOfferShippingMethod::METHOD_ID . ':1' => $de,
			],
			$this->managed_package()
		);

		self::assertArrayHasKey( SelectedOfferShippingMethod::METHOD_ID . ':1', $filtered );
		self::assertArrayNotHasKey( 'flat_rate:1', $filtered );
		self::assertCount( 1, $filtered );
	}

	public function test_flags_on_managed_package_without_de_rate_hides_native_methods(): void {
		$integration = $this->integration( $this->runtime_flags_on() );
		$native      = $this->rate( 'flat_rate:1', 'flat_rate' );
		$pickup      = $this->rate( 'local_pickup:1', 'local_pickup' );
		$rates       = [
			'flat_rate:1'    => $native,
			'local_pickup:1' => $pickup,
		];

		$filtered = $integration->filter_managed_package_rates( $rates, $this->managed_package() );

		self::assertSame( [], $filtered );
		self::assertArrayNotHasKey( 'flat_rate:1', $filtered );
		self::assertArrayNotHasKey( 'local_pickup:1', $filtered );
	}

	public function test_flags_on_unmanaged_package_keeps_native_methods(): void {
		$integration = $this->integration( $this->runtime_flags_on() );
		$native      = $this->rate( 'flat_rate:1', 'flat_rate' );
		$rates       = [ 'flat_rate:1' => $native ];

		$filtered = $integration->filter_managed_package_rates( $rates, [ 'contents' => [] ] );

		self::assertSame( $rates, $filtered );
		self::assertArrayNotHasKey( SelectedOfferShippingMethod::METHOD_ID, $filtered );
	}

	/**
	 * @return array<string, class-string>
	 */
	private function native_methods(): array {
		return [
			'flat_rate'     => 'WC_Shipping_Flat_Rate',
			'free_shipping' => 'WC_Shipping_Free_Shipping',
			'local_pickup'  => 'WC_Shipping_Local_Pickup',
		];
	}

	private function integration( FeatureFlags $flags ): SelectedOfferShippingIntegration {
		return new SelectedOfferShippingIntegration(
			new ShippingRateCalculationGate( $flags, new Requirements() )
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
	private function managed_package(): array {
		$intent = [
			'contract_version'        => '1',
			'product_id'              => 101,
			'variation_id'            => null,
			'target_type'             => 'product',
			'target_id'               => 101,
			'display_key'             => 'in_warehouse:delivery:10',
			'fulfilment_availability' => 'in_warehouse',
			'fulfilment_choice'       => 'delivery',
			'delivery_offer_id'       => 10,
			'rule_id'                 => null,
			'issued_at'               => '2026-08-20T00:00:00+00:00',
		];

		return [
			'contents'      => [
				'line-a' => [
					'product_id'   => 101,
					'variation_id' => 0,
					'quantity'     => 1,
					'line_total'   => 19.99,
					CartDeliverySelectionCapture::CART_SELECTION_KEY => $intent,
				],
			],
			'destination'   => [
				'country'  => 'GH',
				'state'    => '',
				'city'     => 'Accra',
				'postcode' => '',
			],
			DeliveryGroupIdentity::PACKAGE_META_KEY => [
				'managed'   => true,
				'group_id'  => DeliveryGroupIdentity::compose( 'in_warehouse', 'delivery', '10' ),
				'is_pickup' => false,
			],
		];
	}

	private function calculator( FeatureFlags $flags ): SelectedOfferShippingRateCalculator {
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

		$cards = $this->createMock( RateCardRepositoryInterface::class );
		$cards->method( 'listActiveForQuoteMatch' )->willReturn(
			[
				[
					'id'                   => 4,
					'internal_code'        => 'REGTEST',
					'delivery_offer_id'    => 10,
					'destination_zone_id'  => 20,
					'logistics_profile_id' => null,
					'supplier_id'          => null,
					'origin_id'            => null,
					'charge_type'          => RateCardChargeType::FixedPerShipment->value,
					'base_amount'          => '25.00',
					'base_currency'        => 'USD',
					'priority'             => 100,
					'status'               => 'active',
				],
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
			new ShippingRateCalculationGate( $flags, new Requirements() ),
			$zone,
			$assessor,
			new RateQuoteEngine( $cards ),
			$rules,
			( new ReflectionClass( Logger::class ) )->newInstanceWithoutConstructor()
		);
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
