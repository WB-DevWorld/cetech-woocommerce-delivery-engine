<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use PHPUnit\Framework\TestCase;

final class ConstrainedFallbackAdminUsabilityTest extends TestCase {

	public function test_address_tester_uses_woocommerce_country_selector(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );

		self::assertStringContainsString( 'AdminFormHelper::country_select_field(', $source );
		self::assertStringContainsString( "__( 'Country', 'cetech-woocommerce-delivery-engine' )", $source );
		self::assertStringContainsString( 'WooCommerceCountryCatalog::canonical_iso2', $source );
		self::assertStringContainsString( 'Choose the country the customer would select at checkout', $source );
		self::assertStringNotContainsString( "__( 'Country code', 'cetech-woocommerce-delivery-engine' )", $source );
	}

	public function test_fallback_wording_distinguishes_constrained_from_global(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );

		self::assertStringContainsString( "__( 'Use as fallback for unmatched addresses'", $source );
		self::assertStringContainsString( 'If this area has no location rules, it is a global fallback', $source );
		self::assertStringContainsString( 'Greater Accra fallback with Ghana + Greater Accra never matches the United States', $source );
		self::assertStringContainsString( "__( 'Everywhere else (global fallback)'", $source );
		self::assertStringContainsString( 'fallback only inside this area', $source );
		self::assertStringContainsString( 'This is the global fallback (Everywhere else)', $source );
		self::assertStringContainsString( 'This match is a constrained fallback', $source );
		self::assertStringNotContainsString( "__( 'Fallback delivery area'", $source );
	}
}
