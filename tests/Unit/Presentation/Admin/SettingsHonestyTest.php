<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsPage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SettingsHonestyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']   = [];
		$GLOBALS['cetech_de_test_caps']      = [ 'manage_delivery_settings' => true ];
		$GLOBALS['cetech_de_test_is_admin']  = true;
		$GLOBALS['cetech_de_test_logged_in'] = true;
		$GLOBALS['cetech_de_test_redirects'] = [];
		$_POST = [];
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_settings_source_removes_stale_experimental_controls(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsPage.php' );

		self::assertStringNotContainsString( 'Leave off unless CETECH support', $source );
		self::assertStringNotContainsString( 'Unavailable in this release. Reserved for a future Delivery Engine version.', $source );
		self::assertStringNotContainsString( 'Support WooCommerce Blocks checkout', $source );
		self::assertStringNotContainsString( 'Support classic WooCommerce checkout', $source );
		self::assertStringNotContainsString( "'enable_bulk_import'", $source );
		self::assertStringNotContainsString( "'enable_category_rules'", $source );
		self::assertStringNotContainsString( "'enable_site_fallback_rule'", $source );
		self::assertStringNotContainsString( "'demo_data_on_activation'", $source );
		self::assertStringNotContainsString( "'enable_blocks_adapter'", $source );
		self::assertStringNotContainsString( "'enable_classic_checkout_adapter'", $source );
		self::assertStringNotContainsString( "'enable_effective_configuration_runtime'", $source );
		self::assertStringNotContainsString( "'enable_variable_product_ecr_runtime'", $source );
		self::assertStringNotContainsString( "'enable_product_delivery_selector'", $source );
		self::assertStringNotContainsString( "'enable_cart_delivery_selection_capture'", $source );
		self::assertStringNotContainsString( "'enable_checkout_delivery_selection_validation'", $source );
		self::assertStringNotContainsString( "'enable_woocommerce_shipping_rate_calculation'", $source );
		self::assertStringContainsString( 'ADMINISTRATOR_EDITABLE_FLAGS', $source );
		self::assertStringContainsString( 'Future / unavailable', $source );
		self::assertStringContainsString( "'enable_customer_timeline'", $source );
		self::assertStringContainsString( 'WooCommerce Cart & Checkout Blocks', $source );
		self::assertStringContainsString( 'Automatically available', $source );
		self::assertStringContainsString( 'does not create demo Delivery Areas', $source );
	}

	public function test_settings_save_does_not_disable_internal_runtime_flags(): void {
		$flags = new FeatureFlags();
		$flags->set( 'enable_effective_configuration_runtime', true );
		$flags->set( 'enable_variable_product_ecr_runtime', true );
		$flags->set( 'enable_classic_checkout_adapter', true );
		$flags->set( 'enable_product_delivery_selector', true );
		$flags->set( 'enable_cart_delivery_selection_capture', true );
		$flags->set( 'enable_checkout_delivery_selection_validation', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );

		$_POST = [
			'cetech_de_action' => 'cetech_de_save_delivery_settings',
			'cetech_de_nonce'  => 'test-nonce-cetech_de_save_delivery_settings',
			'flags'            => [
				'enable_shipment_records' => '1',
				'enable_tracking_links'   => '1',
				'enable_effective_configuration_runtime' => '0',
				'enable_blocks_adapter' => '1',
				'enable_bulk_import' => '1',
			],
		];

		$page = new DeliverySettingsPage(
			$flags,
			new Requirements(),
			$this->createStub( RateCardRepositoryInterface::class ),
			new ShippingRateCalculationGate( $flags, new Requirements() ),
			new AdminActionHandler( new AdminNoticeService() )
		);

		try {
			$page->handle_actions();
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}

		self::assertTrue( $flags->is_enabled( 'enable_shipment_records' ) );
		self::assertTrue( $flags->is_enabled( 'enable_effective_configuration_runtime' ) );
		self::assertTrue( $flags->is_enabled( 'enable_variable_product_ecr_runtime' ) );
		self::assertTrue( $flags->is_enabled( 'enable_classic_checkout_adapter' ) );
		self::assertTrue( $flags->is_enabled( 'enable_product_delivery_selector' ) );
		self::assertFalse( $flags->is_enabled( 'enable_blocks_adapter' ) );
		self::assertFalse( $flags->is_enabled( 'enable_bulk_import' ) );
		self::assertFalse( $flags->is_enabled( 'enable_customer_timeline' ) );
	}
}
