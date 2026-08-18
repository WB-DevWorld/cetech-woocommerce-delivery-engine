<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsPage;
use CetechDeliveryEngine\Presentation\Admin\FeatureFlagLabels;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ShipmentReleaseSettingsTest extends TestCase {

	private FeatureFlags $flags;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']    = [];
		$GLOBALS['cetech_de_test_caps']       = [];
		$GLOBALS['cetech_de_test_is_admin']   = true;
		$GLOBALS['cetech_de_test_logged_in']  = true;
		$GLOBALS['cetech_de_test_redirects']  = [];
		$_POST = [];

		$this->flags = new FeatureFlags();
	}

	protected function tearDown(): void {
		$_POST = [];
		parent::tearDown();
	}

	public function test_v1_shipment_settings_default_off(): void {
		self::assertFalse( $this->flags->defaults()['enable_shipment_records'] );
		self::assertFalse( $this->flags->defaults()['enable_tracking_links'] );
		self::assertFalse( $this->flags->defaults()['enable_customer_timeline'] );
		self::assertFalse( $this->flags->is_enabled( 'enable_shipment_records' ) );
		self::assertFalse( $this->flags->is_enabled( 'enable_tracking_links' ) );
		self::assertFalse( $this->flags->is_enabled( 'enable_customer_timeline' ) );
	}

	public function test_ensure_defaults_does_not_enable_stage14_on_upgrade(): void {
		$this->flags->ensure_defaults();

		self::assertSame( 0, $GLOBALS['cetech_de_test_options']['cetech_de_enable_shipment_records'] );
		self::assertSame( 0, $GLOBALS['cetech_de_test_options']['cetech_de_enable_tracking_links'] );
		self::assertSame( 0, $GLOBALS['cetech_de_test_options']['cetech_de_enable_customer_timeline'] );
		self::assertFalse( $this->flags->is_enabled( 'enable_shipment_records' ) );
	}

	public function test_authorized_settings_save_enables_v1_flags_and_rejects_timeline(): void {
		$GLOBALS['cetech_de_test_caps']['manage_delivery_settings'] = true;
		$_POST = [
			'cetech_de_action' => 'cetech_de_save_delivery_settings',
			'cetech_de_nonce'  => 'test-nonce-cetech_de_save_delivery_settings',
			'flags'            => [
				'enable_shipment_records'  => '1',
				'enable_tracking_links'    => '1',
				'enable_customer_timeline' => '1',
				'enable_bulk_import'       => '1',
			],
		];

		$this->save_settings();

		self::assertTrue( $this->flags->is_enabled( 'enable_shipment_records' ) );
		self::assertTrue( $this->flags->is_enabled( 'enable_tracking_links' ) );
		self::assertFalse( $this->flags->is_enabled( 'enable_customer_timeline' ) );
		self::assertFalse( $this->flags->is_enabled( 'enable_bulk_import' ) );
	}

	public function test_non_boolean_flag_values_do_not_enable(): void {
		$GLOBALS['cetech_de_test_caps']['manage_delivery_settings'] = true;
		$_POST = [
			'cetech_de_action' => 'cetech_de_save_delivery_settings',
			'cetech_de_nonce'  => 'test-nonce-cetech_de_save_delivery_settings',
			'flags'            => [
				'enable_shipment_records' => 'yes',
				'enable_tracking_links'   => 'true',
			],
		];

		$this->save_settings();

		self::assertFalse( $this->flags->is_enabled( 'enable_shipment_records' ) );
		self::assertFalse( $this->flags->is_enabled( 'enable_tracking_links' ) );
	}

	public function test_unauthorized_user_cannot_save_shipment_flags(): void {
		$GLOBALS['cetech_de_test_caps'] = [];
		$_POST = [
			'cetech_de_action' => 'cetech_de_save_delivery_settings',
			'cetech_de_nonce'  => 'test-nonce-cetech_de_save_delivery_settings',
			'flags'            => [
				'enable_shipment_records' => '1',
				'enable_tracking_links'   => '1',
			],
		];

		$this->save_settings();

		self::assertFalse( $this->flags->is_enabled( 'enable_shipment_records' ) );
		self::assertFalse( $this->flags->is_enabled( 'enable_tracking_links' ) );
	}

	public function test_missing_nonce_cannot_save_shipment_flags(): void {
		$GLOBALS['cetech_de_test_caps']['manage_delivery_settings'] = true;
		$_POST = [
			'cetech_de_action' => 'cetech_de_save_delivery_settings',
			'flags'            => [
				'enable_shipment_records' => '1',
				'enable_tracking_links'   => '1',
			],
		];

		$this->save_settings();

		self::assertFalse( $this->flags->is_enabled( 'enable_shipment_records' ) );
		self::assertFalse( $this->flags->is_enabled( 'enable_tracking_links' ) );
	}

	public function test_administrator_labels_are_operational(): void {
		$records = FeatureFlagLabels::describe( 'enable_shipment_records' );
		$links   = FeatureFlagLabels::describe( 'enable_tracking_links' );
		$timeline = FeatureFlagLabels::describe( 'enable_customer_timeline' );

		self::assertSame( 'Enable shipment records', $records['label'] );
		self::assertSame( 'Enable customer tracking links', $links['label'] );
		self::assertStringContainsString( 'future', strtolower( $timeline['label'] ) );
		self::assertStringNotContainsString( 'enable_', $records['label'] );
		self::assertStringNotContainsString( 'feature flag', strtolower( $records['description'] ) );
	}

	private function save_settings(): void {
		$requirements = new Requirements();
		$page         = new DeliverySettingsPage(
			$this->flags,
			$requirements,
			$this->createStub( RateCardRepositoryInterface::class ),
			new ShippingRateCalculationGate( $this->flags, $requirements ),
			new AdminActionHandler( new AdminNoticeService() )
		);

		try {
			$page->handle_actions();
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}
}
