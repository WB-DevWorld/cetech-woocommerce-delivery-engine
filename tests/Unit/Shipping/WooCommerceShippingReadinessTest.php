<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipping;

use CetechDeliveryEngine\Application\Shipping\WooCommerceShippingReadiness;
use CetechDeliveryEngine\Core\Requirements;
use PHPUnit\Framework\TestCase;

final class WooCommerceShippingReadinessTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_filters'] = [];
	}

	public function test_filter_can_mark_woocommerce_shipping_ready(): void {
		add_filter( 'cetech_de_woocommerce_shipping_configured', static fn () => true );
		$readiness = new WooCommerceShippingReadiness( new Requirements() );

		self::assertTrue( $readiness->is_ready() );
		self::assertSame( 'Ready', $readiness->status_label() );
		self::assertStringContainsString( 'shipping zone', $readiness->explanation() );
	}

	public function test_missing_woocommerce_shipping_assignment_is_action_needed(): void {
		add_filter( 'cetech_de_woocommerce_shipping_configured', static fn () => false );
		$readiness = new WooCommerceShippingReadiness( new Requirements() );

		self::assertFalse( $readiness->is_ready() );
		self::assertSame( 'Action needed', $readiness->status_label() );
		self::assertStringContainsString( 'CETECH Delivery shipping method', $readiness->explanation() );
	}
}
