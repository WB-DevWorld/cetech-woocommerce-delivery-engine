<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations\Blocks;

use CetechDeliveryEngine\Application\Checkout\CheckoutDeliveryValidationResult;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\FeaturesCompatibility;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutAdapter;
use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutValidation;
use CetechDeliveryEngine\Integrations\Blocks\BlocksStoreApiExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BlocksCheckoutAdapterTest extends TestCase {

	public function test_adapter_is_implemented_with_store_api_namespace(): void {
		$adapter = ( new ReflectionClass( BlocksCheckoutAdapter::class ) )->newInstanceWithoutConstructor();

		self::assertTrue( $adapter->is_implemented() );
		self::assertSame( 'blocks', $adapter->getKey() );
		self::assertSame( 'cetech-delivery-engine', BlocksCheckoutAdapter::NAMESPACE );
	}

	public function test_compatibility_declaration_uses_cart_checkout_blocks(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Core/FeaturesCompatibility.php'
		);

		self::assertStringContainsString( "'cart_checkout_blocks'", $source );
		self::assertStringContainsString( 'declare_blocks_compatibility', $source );
		FeaturesCompatibility::register_hpos_declaration( dirname( __DIR__, 4 ) . '/cetech-woocommerce-delivery-engine.php' );
		self::assertTrue( FeaturesCompatibility::blocks_declaration_attempted() );
	}

	public function test_classic_checkout_hooks_remain_registered_in_classic_classes(): void {
		$validator = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Application/Checkout/CheckoutDeliverySelectionValidator.php'
		);
		$persister = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Application/Order/OrderDeliverySnapshotPersister.php'
		);

		self::assertStringContainsString( 'woocommerce_after_checkout_validation', $validator );
		self::assertStringContainsString( 'woocommerce_checkout_create_order_line_item', $persister );
		self::assertStringContainsString( 'woocommerce_checkout_order_created', $persister );
		self::assertStringContainsString( 'woocommerce_store_api_checkout_order_processed', $persister );
		self::assertStringContainsString( 'woocommerce_store_api_checkout_update_order_from_request', $persister );
	}

	public function test_store_api_extension_registers_cart_and_checkout_endpoints(): void {
		$GLOBALS['cetech_de_test_store_api_endpoints'] = [];

		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			eval(
				'function woocommerce_store_api_register_endpoint_data( array $args ): void {
					$GLOBALS["cetech_de_test_store_api_endpoints"][] = $args;
				}'
			);
		}

		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$extension->register();

		$endpoints = array_column( $GLOBALS['cetech_de_test_store_api_endpoints'], 'endpoint' );
		self::assertContains( 'cart-item', $endpoints );
		self::assertContains( 'cart', $endpoints );
		self::assertContains( 'checkout', $endpoints );

		foreach ( $GLOBALS['cetech_de_test_store_api_endpoints'] as $args ) {
			self::assertSame( BlocksCheckoutAdapter::NAMESPACE, $args['namespace'] );
			$schema = ( $args['schema_callback'] )();
			self::assertIsArray( $schema );
		}
	}

	public function test_managed_package_without_de_rate_fails_closed_unmanaged_unaffected(): void {
		if ( ! class_exists( 'WC_Shipping_Method', false ) ) {
			eval(
				'class WC_Shipping_Method {
					public string $id = "";
					public function get_method_id(): string { return $this->id; }
				}'
			);
		}

		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();

		$managed = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => false ],
		];
		$unmanaged = [ 'contents' => [] ];
		$native_only = [ 'flat_rate:1' => (object) [] ];

		self::assertFalse( $extension->managed_package_has_delivery_engine_rate( $managed, [] ) );
		self::assertFalse( $extension->managed_package_has_delivery_engine_rate( $managed, $native_only ) );
		self::assertTrue( $extension->managed_package_has_delivery_engine_rate( $unmanaged, $native_only ) );

		$rate = new class() {
			public function get_method_id(): string {
				return SelectedOfferShippingMethod::METHOD_ID;
			}
		};
		self::assertTrue(
			$extension->managed_package_has_delivery_engine_rate(
				$managed,
				[ SelectedOfferShippingMethod::METHOD_ID . ':0' => $rate ]
			)
		);
	}

	public function test_store_api_validation_rejects_stale_classic_result(): void {
		$validation = new BlocksCheckoutValidation(
			( new ReflectionClass( \CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor(),
			new ShippingRateCalculationGate( new FeatureFlags(), new Requirements() )
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no longer available' );
		$validation->enforce_selection_result(
			CheckoutDeliveryValidationResult::invalid(
				[ 'A delivery option in your cart is no longer available. Please return to your cart and update the affected product.' ]
			)
		);
	}

	public function test_plugin_registers_blocks_adapter(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Bootstrap/Plugin.php' );
		self::assertStringContainsString( 'BlocksCheckoutAdapter::class', $plugin );
		self::assertStringContainsString( 'IntegrationStatusCatalog::class', $plugin );
		self::assertStringNotContainsString( "is_enabled( 'enable_blocks_adapter' )", $plugin );
	}

	public function test_address_change_triggers_shipping_recalculation(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Integrations/Blocks/BlocksCheckoutValidation.php'
		);

		self::assertStringContainsString( 'woocommerce_store_api_cart_update_customer_from_request', $source );
		self::assertStringContainsString( 'woocommerce_store_api_checkout_update_order_from_request', $source );
		self::assertStringContainsString( 'calculate_shipping()', $source );
		self::assertStringContainsString( 'assert_cart_and_rates_valid', $source );
	}

	public function test_store_api_snapshot_reuses_classic_persister(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Application/Order/OrderDeliverySnapshotPersister.php'
		);

		self::assertStringContainsString( 'woocommerce_checkout_order_created', $source );
		self::assertStringContainsString( 'woocommerce_store_api_checkout_order_processed', $source );
		self::assertStringContainsString( '$this->handle_order_created( $order )', $source );
		self::assertStringNotContainsString( 'ShipmentRepository', $source );
	}

	public function test_blocks_adapter_does_not_create_shipments(): void {
		$adapter = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Integrations/Blocks/BlocksCheckoutAdapter.php'
		);
		$validation = (string) file_get_contents(
			dirname( __DIR__, 4 ) . '/src/Integrations/Blocks/BlocksCheckoutValidation.php'
		);

		self::assertStringNotContainsString( 'PaidOrderShipmentSubscriber', $adapter );
		self::assertStringNotContainsString( 'HistoricalShipmentPlanner', $adapter );
		self::assertStringNotContainsString( 'create_shipment', $validation );
	}

	public function test_add_to_cart_bridge_preserves_air_sea_display_key(): void {
		$capture = ( new ReflectionClass( \CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture::class ) )
			->newInstanceWithoutConstructor();
		$bridge  = new \CetechDeliveryEngine\Integrations\Blocks\BlocksAddToCartBridge( $capture );

		$bridge->prime_cart_item_data(
			[],
			[
				'extensions' => [
					BlocksCheckoutAdapter::NAMESPACE => [
						'delivery_option_key' => 'international:delivery:89',
					],
				],
			]
		);

		self::assertSame(
			'international:delivery:89',
			$bridge->filter_submitted_option_key( '' )
		);
	}

	public function test_array_shaped_calculated_packages_are_read_as_rates(): void {
		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$managed   = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => false ],
		];
		$rate      = $this->de_rate( 50.0 );

		$from_object_only = BlocksStoreApiExtension::rates_from_calculated_entry(
			(object) [],
			$managed
		);
		self::assertSame( [], $from_object_only );

		$from_wc_array = BlocksStoreApiExtension::rates_from_calculated_entry(
			[ 'rates' => [ SelectedOfferShippingMethod::METHOD_ID . ':0' => $rate ] ],
			$managed
		);
		self::assertNotEmpty( $from_wc_array );
		self::assertTrue( $extension->managed_package_is_validly_quoted( $managed, $from_wc_array ) );
	}

	public function test_valid_delivery_only_package_has_no_fail_closed_warning(): void {
		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$delivery  = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => false ],
		];
		$rate      = $this->de_rate( 50.0 );

		self::assertTrue(
			$extension->managed_package_is_validly_quoted(
				$delivery,
				[ SelectedOfferShippingMethod::METHOD_ID . ':0' => $rate ]
			)
		);
	}

	public function test_valid_pickup_only_zero_charge_is_not_fail_closed(): void {
		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$pickup    = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => true ],
		];
		$rate      = $this->de_rate( 0.0 );

		self::assertSame( 0.0, $extension->delivery_engine_rate_cost( $rate, SelectedOfferShippingMethod::METHOD_ID . ':p' ) );
		self::assertTrue(
			$extension->managed_package_is_validly_quoted(
				$pickup,
				[ SelectedOfferShippingMethod::METHOD_ID . ':p' => $rate ]
			)
		);
	}

	public function test_valid_mixed_delivery_and_pickup_is_not_fail_closed(): void {
		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$delivery  = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => false ],
		];
		$pickup    = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => true ],
		];

		self::assertTrue(
			$extension->managed_packages_are_validly_quoted(
				[
					[
						'package' => $delivery,
						'rates'   => [ SelectedOfferShippingMethod::METHOD_ID . ':0' => $this->de_rate( 50.0 ) ],
					],
					[
						'package' => $pickup,
						'rates'   => [ SelectedOfferShippingMethod::METHOD_ID . ':p' => $this->de_rate( 0.0 ) ],
					],
				]
			)
		);
	}

	public function test_unquoted_managed_delivery_fails_closed_and_strips_native_fallback(): void {
		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$managed   = [
			DeliveryGroupIdentity::PACKAGE_META_KEY => [ 'managed' => true, 'is_pickup' => false ],
		];
		$unmanaged = [ 'contents' => [] ];
		$native    = [ 'flat_rate:1' => $this->native_rate() ];

		self::assertFalse( $extension->managed_package_is_validly_quoted( $managed, [] ) );
		self::assertFalse( $extension->managed_package_is_validly_quoted( $managed, $native ) );
		self::assertTrue( $extension->has_native_shipping_fallback( $native ) );
		self::assertTrue( $extension->managed_package_is_validly_quoted( $unmanaged, $native ) );
	}

	public function test_store_api_schema_has_no_private_fields(): void {
		$extension = ( new ReflectionClass( BlocksStoreApiExtension::class ) )->newInstanceWithoutConstructor();
		$schema    = array_merge( $extension->cart_item_schema(), $extension->cart_schema() );

		self::assertArrayHasKey( 'ui_anchor', $schema );
		self::assertArrayHasKey( 'first_incomplete_anchor', $schema );
		self::assertArrayHasKey( 'address_needed', $schema );
		self::assertArrayHasKey( 'address_action_label', $schema );

		foreach ( array_keys( $schema ) as $key ) {
			self::assertFalse(
				\CetechDeliveryEngine\Integrations\Blocks\BlocksPublicPayload::contains_forbidden( [ (string) $key => true ] ),
				(string) $key
			);
		}
	}

	private function de_rate( float $cost ): object {
		return new class( $cost ) {
			public function __construct( private float $cost ) {
			}

			public function get_method_id(): string {
				return SelectedOfferShippingMethod::METHOD_ID;
			}

			public function get_cost(): string {
				return (string) $this->cost;
			}
		};
	}

	private function native_rate(): object {
		return new class() {
			public function get_method_id(): string {
				return 'flat_rate';
			}
		};
	}
}
