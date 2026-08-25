<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Presentation\Admin\AdminLanguage;
use PHPUnit\Framework\TestCase;

final class Stage13BR1AdminUxTest extends TestCase {

	public function test_wizard_does_not_show_setup_complete_before_finish(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/SetupWizardPage.php' );

		self::assertStringContainsString( 'AdminLanguage::wizard_title()', $source );
		self::assertStringContainsString( "__( 'Delivery setup complete'", $source );
		self::assertStringContainsString( "__( 'Almost ready'", $source );
		self::assertStringContainsString( "__( 'Activate Delivery Engine'", $source );
		self::assertStringContainsString( 'button-secondary', $source );
		self::assertStringContainsString( "__( 'Save & finish later'", $source );
		self::assertStringContainsString( 'compatible_offers', $source );
		self::assertStringContainsString( 'pickup_allowed', $source );
	}

	public function test_setup_guide_is_the_incomplete_wizard_identity(): void {
		$menu = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminMenu.php' );

		self::assertStringContainsString( "__( 'Setup Guide'", $menu );
		self::assertStringContainsString( 'should_open_on_entry()', $menu );
		self::assertStringContainsString( 'highlight_setup_guide', $menu );
		self::assertSame( 'Setup Guide', AdminLanguage::menu_setup_guide() );
	}

	public function test_profile_filtering_excludes_impossible_routes(): void {
		FulfilmentProfileRegistry::reset_for_tests();
		$warehouse     = FulfilmentProfileRegistry::get( 'in_warehouse' );
		$in_store      = FulfilmentProfileRegistry::get( 'in_store' );
		$international = FulfilmentProfileRegistry::get( 'international_fulfilment' );

		self::assertNotNull( $warehouse );
		self::assertNotNull( $in_store );
		self::assertNotNull( $international );
		self::assertSame( [ DeliveryRoute::LocalDelivery->value ], $warehouse->allowed_routes );
		self::assertNotContains( DeliveryRoute::StorePickup->value, $warehouse->allowed_routes );
		self::assertNotContains( DeliveryRoute::Air->value, $warehouse->allowed_routes );
		self::assertTrue( $in_store->pickup_allowed );
		self::assertNotContains( DeliveryRoute::Air->value, $in_store->allowed_routes );
		self::assertSame( [ DeliveryRoute::Air->value, DeliveryRoute::Sea->value ], $international->allowed_routes );
		self::assertFalse( $international->pickup_allowed );
	}

	public function test_normal_ui_uses_delivery_option_and_area_not_offer_or_zone_or_rate_card(): void {
		$files = [
			'/src/Presentation/Admin/DeliveryOffersPage.php',
			'/src/Presentation/Admin/DestinationZonesPage.php',
			'/src/Presentation/Admin/RateCardsPage.php',
			'/src/Presentation/Admin/ProductExceptionsPage.php',
			'/src/Presentation/Admin/DeliverySettingsPage.php',
		];

		foreach ( $files as $relative ) {
			$source = (string) file_get_contents( dirname( __DIR__, 4 ) . $relative );
			self::assertStringNotContainsString( "__( 'Total offers'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Active offers'", $source, $relative );
			self::assertStringNotContainsString( "__( 'All delivery offers'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Edit Delivery Offer'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Back to offers'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Save Offer'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Total zones'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Zones without pricing'", $source, $relative );
			self::assertStringNotContainsString( "__( 'All delivery zones'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Edit Delivery Zone'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Back to zones'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Save Rate Card'", $source, $relative );
			self::assertStringNotContainsString( "__( 'Save Rate Card'", $source, $relative );
		}

		$offers = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliveryOffersPage.php' );
		$areas  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );
		$charges = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/RateCardsPage.php' );

		self::assertStringContainsString( "__( 'Total delivery options'", $offers );
		self::assertStringContainsString( "__( 'Active delivery options'", $offers );
		self::assertStringContainsString( "__( 'All delivery options'", $offers );
		self::assertStringContainsString( "__( 'Edit Delivery Option'", $offers );
		self::assertStringContainsString( "__( 'Back to Delivery Options'", $offers );
		self::assertStringContainsString( "__( 'Save Delivery Option'", $offers );
		self::assertStringContainsString( "__( 'Total delivery areas'", $areas );
		self::assertStringContainsString( "__( 'Areas without delivery charges'", $areas );
		self::assertStringContainsString( "__( 'All delivery areas'", $areas );
		self::assertStringContainsString( "__( 'Edit Delivery Area'", $areas );
		self::assertStringContainsString( "__( 'Back to Delivery Areas'", $areas );
		self::assertStringContainsString( "__( 'Locations & delivery options'", $areas );
		self::assertStringContainsString( "__( 'Test an address'", $areas );
		self::assertStringContainsString( "__( 'Save Delivery Charge'", $charges );
		self::assertStringNotContainsString( "__( 'Save Rate Card'", $charges );
		self::assertStringContainsString( 'human_charge_name', $charges );
		self::assertStringContainsString( 'From WooCommerce', $charges );
		self::assertStringContainsString( 'get_woocommerce_currency', $charges );
		self::assertStringNotContainsString( 'Technical diagnostic tools', $charges );
	}

	public function test_reference_codes_are_optional_visible_and_can_be_generated(): void {
		$offers = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliveryOffersPage.php' );
		$areas  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );

		foreach ( [ $offers, $areas ] as $source ) {
			self::assertStringContainsString( "__( 'Reference code'", $source );
			self::assertStringContainsString( "__( 'Advanced details'", $source );
			self::assertLessThan(
				strpos( $source, "__( 'Advanced details'" ),
				strpos( $source, "__( 'Reference code'" )
			);
			self::assertStringContainsString( 'Generated from the', $source );
		}
		self::assertSame( 'greater-accra', AdminFormHelper::generate_code_from_name( 'Greater Accra', static fn () => false ) );
	}

	public function test_settings_primary_view_hides_internal_activation_chain(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsPage.php' );

		self::assertStringContainsString( "__( 'General'", $source );
		self::assertStringContainsString( "__( 'Customer experience'", $source );
		self::assertStringContainsString( "__( 'Orders'", $source );
		self::assertStringContainsString( "__( 'Access'", $source );
		self::assertStringContainsString( "__( 'Advanced'", $source );
		self::assertStringContainsString( 'runtime_settings()', $source );
		self::assertStringContainsString( "__( 'Activate Delivery Engine'", $source );
		self::assertStringContainsString( 'enable_product_delivery_selector', $source );
		self::assertTrue( str_contains( $source, 'private function runtime_settings()' ) );
	}

	public function test_product_exceptions_distinguish_variation_from_product(): void {
		$query = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Application/Configuration/Catalog/ProductExceptionsQuery.php' );
		$page  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/ProductExceptionsPage.php' );

		self::assertStringContainsString( 'Variation-specific', $query );
		self::assertStringContainsString( 'Product-specific', $query );
		self::assertStringContainsString( "__( 'Product Settings'", $query );
		self::assertStringContainsString( "__( 'Product-specific delivery settings'", $query );
		self::assertStringContainsString( 'currently_using', $page );
		self::assertStringContainsString( "__( 'Reset to Product Settings'", $page );
		self::assertStringContainsString( "__( 'Reset to Site-wide Defaults'", $page );
		self::assertStringContainsString( "__( 'Search products'", $page );
		self::assertStringContainsString( "__( 'Needs Attention'", $page );
		self::assertStringContainsString( "__( 'Ready'", $page );
	}

	public function test_technical_diagnostics_include_copy_report_action(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/SystemStatusPage.php' );

		self::assertStringContainsString( "__( 'Copy Diagnostic Report'", $source );
		self::assertStringContainsString( 'cetech-de-copy-diagnostic', $source );
	}

	public function test_destructive_row_actions_use_wordpress_trash_pattern(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminPermanentDeleteFlow.php' );

		self::assertStringContainsString( 'submitdelete', $source );
		self::assertStringContainsString( "__( 'Delete permanently'", $source );
		self::assertStringContainsString( 'class="trash"', $source );
	}
}
