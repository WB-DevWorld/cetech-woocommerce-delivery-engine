<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Configuration\Admin\ConfigurationFieldCatalog;
use CetechDeliveryEngine\Application\Configuration\Admin\StaffChargeSummary;
use CetechDeliveryEngine\Application\Configuration\DeliveryOptionCompatibility;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Presentation\Admin\AdminLanguage;
use PHPUnit\Framework\TestCase;

final class Stage13BR2AdminUxTest extends TestCase {

	protected function setUp(): void {
		FulfilmentProfileRegistry::reset_for_tests();
	}

	public function test_one_compatibility_source_filters_all_admin_surfaces(): void {
		$warehouse = FulfilmentProfileRegistry::get( 'in_warehouse' );
		$store     = FulfilmentProfileRegistry::get( 'in_store' );
		$intl      = FulfilmentProfileRegistry::get( 'international_fulfilment' );
		self::assertNotNull( $warehouse );
		self::assertNotNull( $store );
		self::assertNotNull( $intl );

		$offers = [
			[ 'id' => 11, 'route' => DeliveryRoute::LocalDelivery->value, 'public_label' => 'Standard Delivery' ],
			[ 'id' => 12, 'route' => DeliveryRoute::StorePickup->value, 'public_label' => 'Store Pickup' ],
			[ 'id' => 13, 'route' => DeliveryRoute::Air->value, 'public_label' => 'Air Shipping' ],
			[ 'id' => 14, 'route' => DeliveryRoute::Sea->value, 'public_label' => 'Sea Shipping' ],
		];

		$warehouse_ids = array_column( DeliveryOptionCompatibility::filter_offers( $offers, $warehouse ), 'id' );
		$store_ids     = array_column( DeliveryOptionCompatibility::filter_offers( $offers, $store ), 'id' );
		$intl_ids      = array_column( DeliveryOptionCompatibility::filter_offers( $offers, $intl ), 'id' );

		self::assertSame( [ 11 ], $warehouse_ids );
		self::assertSame( [ 11 ], $store_ids );
		self::assertSame( [ 13, 14 ], $intl_ids );
		self::assertNotContains( 12, $store_ids );

		$home = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsHomePage.php' );
		$wizard = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/SetupWizardPage.php' );
		$customize = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/StaffDeliveryCustomizeView.php' );
		self::assertStringContainsString( 'DeliveryOptionCompatibility::', $home );
		self::assertStringContainsString( 'DeliveryOptionCompatibility::', $wizard );
		self::assertStringContainsString( 'DeliveryOptionCompatibility::', $customize );
		self::assertStringContainsString( 'compatible_option_labels', $home );
	}

	public function test_staff_charge_summaries_never_use_internal_codes(): void {
		$rate = [
			'internal_code'  => 'accra-standard-flat',
			'charge_type'    => RateCardChargeType::FixedPerShipment->value,
			'base_amount'    => '60.00',
			'base_currency'  => 'GHS',
		];
		$offer = [ 'public_label' => 'Standard Delivery', 'route' => DeliveryRoute::LocalDelivery->value ];
		$line  = StaffChargeSummary::line( $rate, $offer );
		self::assertStringContainsString( 'Standard Delivery', $line );
		self::assertStringContainsString( 'GHS 60.00', $line );
		self::assertStringContainsString( 'Flat amount per delivery', $line );
		self::assertStringNotContainsString( 'accra-standard-flat', $line );
		self::assertSame( 'Store Pickup — No delivery charge', StaffChargeSummary::pickup_none() );
	}

	public function test_product_and_variation_customize_map_to_inheritance_modes(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/StaffDeliveryCustomizeView.php' );
		self::assertStringContainsString( "value=\"inherit\"", $source );
		self::assertStringContainsString( "value=\"override\"", $source );
		self::assertStringContainsString( "value=\"replace\"", $source );
		self::assertStringContainsString( 'Use Product Setting', $source );
		self::assertStringContainsString( 'Use Site-wide Default', $source );
		self::assertStringContainsString( 'Save Product Delivery Settings', $source );
		self::assertStringContainsString( 'Save Variation Delivery Settings', $source );
		self::assertStringContainsString( 'Reset to Product Settings', $source );
		self::assertStringContainsString( 'Reset to Site-wide Defaults', $source );
		self::assertStringNotContainsString( 'INHERIT', $source );
		self::assertStringNotContainsString( 'OVERRIDE', $source );
		self::assertSame( 'Use Product Setting', AdminLanguage::use_product_setting() );
	}

	public function test_preview_uses_product_selector_and_hides_private_fields(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/EffectiveConfigurationPreviewPage.php' );
		self::assertStringContainsString( "__( 'Product'", $source );
		self::assertStringContainsString( 'Search/select product', $source );
		self::assertStringContainsString( "__( 'Variation'", $source );
		self::assertStringNotContainsString( "__( 'Product ID'", $source );
		self::assertStringContainsString( 'LOGISTICS_PROFILE_ID', $source );
		self::assertStringContainsString( 'SUPPLIER_ID', $source );
		self::assertStringContainsString( 'hidden_keys', $source );
		self::assertStringContainsString( 'Fix Product Delivery Settings', $source );
		self::assertStringContainsString( 'assess_field_for', $source );
		self::assertStringNotContainsString( 'New inherited delivery settings', $source );
	}

	public function test_legacy_page_is_retired_from_normal_product(): void {
		$menu = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminMenu.php' );
		self::assertStringNotContainsString( "__( 'Legacy Delivery Rules'", $menu );
		self::assertStringNotContainsString( 'product_delivery_rules_page, \'render\'', $menu );
		self::assertContains( 'Legacy Delivery Rules', \CetechDeliveryEngine\Presentation\Admin\AdminMenu::retired_normal_menu_titles() );
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/ProductDeliveryRulesPage.php' );
		self::assertStringContainsString( 'Legacy create/edit is retired', $source );
		self::assertStringNotContainsString( 'What is a product rule?', $source );
	}

	public function test_diagnostics_use_dedicated_capability(): void {
		self::assertSame( 'view_delivery_diagnostics', Capabilities::DIAGNOSTICS );
		self::assertContains( Capabilities::DIAGNOSTICS, Capabilities::ALL );
		$menu = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminMenu.php' );
		$status = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/SystemStatusPage.php' );
		$caps = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Core/Capabilities/Capabilities.php' );
		self::assertStringContainsString( 'Capabilities::DIAGNOSTICS', $menu );
		self::assertStringContainsString( 'Capabilities::DIAGNOSTICS', $status );
		self::assertStringContainsString( 'ADMINISTRATOR_ONLY', $caps );
	}

	public function test_charge_editor_preserves_numeric_option_keys(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/RateCardsPage.php' );
		self::assertStringContainsString( "[ '' => __( '— Select —'", $source );
		self::assertStringNotContainsString( 'array_merge(', $source );
	}

	public function test_normal_field_labels_use_business_language(): void {
		self::assertSame( 'Fulfilment', ConfigurationFieldCatalog::label( ConfigurationFieldKey::FULFILMENT_AVAILABILITY ) );
		self::assertSame( 'Default customer choice', ConfigurationFieldCatalog::label( ConfigurationFieldKey::FULFILMENT_CHOICE ) );
		$panel = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/ProductDeliveryPanel.php' );
		self::assertStringContainsString( 'cetech-de-product-panel', $panel );
		$css = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/admin/delivery-engine-admin.css' );
		self::assertStringContainsString( 'cetech-de-delivery-tab-active', $css );
		self::assertStringContainsString( 'auto-fit', $css );
	}

	public function test_site_wide_defaults_explain_charges_without_duplicating_pricing(): void {
		$home = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsHomePage.php' );
		self::assertStringContainsString( 'Delivery charges are determined by the customer', $home );
		self::assertStringContainsString( 'Manage Delivery Charges', $home );
	}

	public function test_settings_use_single_save_changes_action(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsPage.php' );
		self::assertStringContainsString( "__( 'Save Changes'", $source );
		self::assertStringNotContainsString( "__( 'Save Settings'", $source );
		self::assertStringContainsString( 'Shown automatically whenever the Delivery Option or Site-wide Default contains an estimate.', $source );
	}
}
