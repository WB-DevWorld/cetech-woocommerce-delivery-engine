<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Fulfillments\WooCommerceFulfillmentsAdapter;
use PHPUnit\Framework\TestCase;

final class Stage14BRuntimeFreezeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	public function test_shipment_feature_flags_remain_off(): void {
		$flags = new FeatureFlags();

		self::assertFalse( $flags->defaults()['enable_shipment_records'] );
		self::assertFalse( $flags->defaults()['enable_tracking_links'] );
		self::assertFalse( $flags->defaults()['enable_customer_timeline'] );
		self::assertFalse( $flags->is_enabled( 'enable_shipment_records' ) );
		self::assertFalse( $flags->is_enabled( 'enable_tracking_links' ) );
		self::assertFalse( $flags->is_enabled( 'enable_customer_timeline' ) );
	}

	public function test_plugin_boot_does_not_create_shipments_or_bind_fulfillments_adapter(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$plugin      = (string) file_get_contents( $plugin_root . '/src/Bootstrap/Plugin.php' );

		self::assertStringContainsString( 'ShipmentRepositoryInterface', $plugin );
		self::assertStringContainsString( 'WpdbShipmentRepository', $plugin );
		self::assertStringContainsString( 'ShipmentService', $plugin );
		self::assertStringContainsString( 'PaidOrderShipmentSubscriber', $plugin );
		self::assertStringNotContainsString( 'WooCommerceFulfillmentsAdapter', $plugin );
		self::assertStringNotContainsString( 'woocommerce_thankyou', $plugin );
	}

	public function test_paid_order_subscriber_is_post_payment_only(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Application/Shipment/PaidOrderShipmentSubscriber.php'
		);

		self::assertStringContainsString( 'woocommerce_payment_complete', $source );
		self::assertStringContainsString( 'woocommerce_order_status_processing', $source );
		self::assertStringContainsString( 'woocommerce_order_status_completed', $source );
		self::assertStringNotContainsString( 'woocommerce_thankyou', $source );
		self::assertStringNotContainsString( 'woocommerce_new_order', $source );
		self::assertStringNotContainsString( 'woocommerce_checkout_order_processed', $source );
	}

	public function test_fulfillments_adapter_is_unimplemented_and_not_a_write_path(): void {
		$adapter = new WooCommerceFulfillmentsAdapter();

		self::assertFalse( $adapter->is_available() );

		$this->expectException( \BadMethodCallException::class );
		$adapter->persist();
	}

	public function test_settings_expose_v1_shipment_controls_and_keep_timeline_reserved(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$settings    = (string) file_get_contents( $plugin_root . '/src/Presentation/Admin/DeliverySettingsPage.php' );

		self::assertStringContainsString( "'enable_shipment_records'", $settings );
		self::assertStringContainsString( "'enable_tracking_links'", $settings );
		self::assertStringContainsString( "'enable_customer_timeline'", $settings );
		self::assertDoesNotMatchRegularExpression(
			'/UNAVAILABLE_EXPERIMENTAL_FLAGS\s*=\s*\[[^\]]*enable_shipment_records/s',
			$settings
		);
		self::assertDoesNotMatchRegularExpression(
			'/UNAVAILABLE_EXPERIMENTAL_FLAGS\s*=\s*\[[^\]]*enable_tracking_links/s',
			$settings
		);
		self::assertMatchesRegularExpression(
			'/UNAVAILABLE_EXPERIMENTAL_FLAGS\s*=\s*\[[^\]]*enable_customer_timeline/s',
			$settings
		);
		self::assertStringContainsString( 'Enable shipment records', $settings );
		self::assertStringContainsString( 'Enable customer tracking links', $settings );
	}
}
