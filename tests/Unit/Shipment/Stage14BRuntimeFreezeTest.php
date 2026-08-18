<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Fulfillments\WooCommerceFulfillmentsAdapter;
use PHPUnit\Framework\TestCase;

final class Stage14BRuntimeFreezeTest extends TestCase {

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
		self::assertStringNotContainsString( 'WooCommerceFulfillmentsAdapter', $plugin );
		self::assertStringNotContainsString( 'woocommerce_payment_complete', $plugin );
		self::assertStringNotContainsString( 'woocommerce_thankyou', $plugin );
		self::assertStringNotContainsString( 'woocommerce_order_status', $plugin );
		self::assertStringNotContainsString( 'ShipmentService', $plugin );
		self::assertStringNotContainsString( 'enable_shipment_records', $plugin );
	}

	public function test_fulfillments_adapter_is_unimplemented_and_not_a_write_path(): void {
		$adapter = new WooCommerceFulfillmentsAdapter();

		self::assertFalse( $adapter->is_available() );

		$this->expectException( \BadMethodCallException::class );
		$adapter->persist();
	}

	public function test_settings_keep_shipment_flags_unavailable(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$settings    = (string) file_get_contents( $plugin_root . '/src/Presentation/Admin/DeliverySettingsPage.php' );

		self::assertStringContainsString( "'enable_shipment_records'", $settings );
		self::assertStringContainsString( "'enable_tracking_links'", $settings );
		self::assertStringContainsString( "'enable_customer_timeline'", $settings );
		self::assertMatchesRegularExpression(
			'/UNAVAILABLE_EXPERIMENTAL_FLAGS\s*=\s*\[[^\]]*enable_shipment_records[^\]]*enable_customer_timeline[^\]]*enable_tracking_links/s',
			$settings
		);
		self::assertStringContainsString( "'unavailable'  => true", $settings );
	}
}
