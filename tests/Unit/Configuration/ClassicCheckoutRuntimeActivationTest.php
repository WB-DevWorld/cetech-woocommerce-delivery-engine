<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use PHPUnit\Framework\TestCase;

final class ClassicCheckoutRuntimeActivationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
	}

	public function test_incomplete_setup_cannot_activate_customer_runtime(): void {
		$settings = new SiteWideDefaultsSettings();
		$flags    = new FeatureFlags();
		$runtime  = new ClassicCheckoutRuntimeActivation( $flags, $settings );

		self::assertFalse( $runtime->can_activate() );
		self::assertFalse( $runtime->activate() );
		self::assertFalse( $runtime->is_active() );
		self::assertFalse( $flags->is_enabled( 'enable_product_delivery_selector' ) );
		self::assertFalse( $flags->is_enabled( 'enable_woocommerce_shipping_rate_calculation' ) );
	}

	public function test_completed_setup_activates_supported_classic_chain(): void {
		$settings = new SiteWideDefaultsSettings();
		$settings->save(
			[
				'setup_completed' => true,
				'active_profiles' => [ FulfilmentAvailability::InWarehouse->value ],
				'primary_profile' => FulfilmentAvailability::InWarehouse->value,
			]
		);
		$flags   = new FeatureFlags();
		$runtime = new ClassicCheckoutRuntimeActivation( $flags, $settings );

		self::assertTrue( $runtime->can_activate() );
		self::assertTrue( $runtime->activate() );
		self::assertTrue( $runtime->is_active() );

		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			self::assertTrue( $flags->is_enabled( $flag ), $flag . ' should be enabled after activation' );
		}
	}
}
