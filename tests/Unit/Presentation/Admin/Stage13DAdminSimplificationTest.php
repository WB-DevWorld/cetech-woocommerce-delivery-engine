<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Capabilities\RoleAccessService;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\FeatureFlagLabels;
use PHPUnit\Framework\TestCase;

final class Stage13DAdminSimplificationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_roles']   = [];
		$GLOBALS['cetech_de_test_caps']    = [];
		$GLOBALS['cetech_de_test_wp_roles'] = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $roles = [];
		};
	}

	public function test_legacy_and_diagnostics_are_absent_from_normal_menu(): void {
		$menu = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminMenu.php' );
		self::assertStringNotContainsString( "__( 'Legacy Delivery Rules'", $menu );
		self::assertStringNotContainsString( "__( 'Technical Diagnostic Tools'", $menu );
		self::assertStringContainsString( 'register_hidden_page', $menu );
		self::assertStringContainsString( "__( 'Logistics Profiles'", $menu );
		self::assertStringContainsString( "__( 'Suppliers & Origins'", $menu );
		self::assertContains( 'Legacy Delivery Rules', AdminMenu::retired_normal_menu_titles() );
		self::assertContains( 'Technical Diagnostic Tools', AdminMenu::retired_normal_menu_titles() );
		self::assertContains( 'Overview', AdminMenu::normal_menu_titles() );
		self::assertContains( 'Settings', AdminMenu::normal_menu_titles() );
		self::assertSame( 'options.php', AdminMenu::HIDDEN_PARENT );
		self::assertStringContainsString( 'self::HIDDEN_PARENT', $menu );
		self::assertStringContainsString( 'Capabilities::DIAGNOSTICS', $menu );
		self::assertStringContainsString( 'SYSTEM_STATUS_SLUG', $menu );
	}

	public function test_setup_guide_is_not_permanent_after_completion(): void {
		$menu = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminMenu.php' );
		self::assertStringContainsString( 'should_show_setup_guide_in_normal_menu', $menu );
		self::assertStringNotContainsString( 'remove_submenu_page', $menu );
		self::assertStringContainsString( 'STATUS_COMPLETE', $menu );
	}

	public function test_new_installs_have_no_visible_legacy_workflow(): void {
		$menu = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminMenu.php' );
		self::assertStringNotContainsString( 'ProductDeliveryRulesPage::SLUG', $menu );
		$legacy = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/ProductDeliveryRulesPage.php' );
		self::assertStringContainsString( 'Legacy create/edit is retired', $legacy );
	}

	public function test_overview_has_no_legacy_count_or_card(): void {
		$overview = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/OverviewPage.php' );
		self::assertStringNotContainsString( 'Legacy compatibility', $overview );
		self::assertStringNotContainsString( 'legacy_dependent', $overview );
		self::assertStringNotContainsString( 'New Delivery Settings System', $overview );
		$home = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsHomePage.php' );
		self::assertStringNotContainsString( 'Still using Legacy Delivery Rules', $home );
	}

	public function test_normal_ui_does_not_say_new_delivery_settings_system(): void {
		self::assertStringNotContainsString( 'New Delivery Settings System', FeatureFlagLabels::label( 'enable_effective_configuration_runtime' ) );
		$settings = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsPage.php' );
		self::assertStringNotContainsString( 'New Delivery Settings System', $settings );
		self::assertStringContainsString( "__( 'Access'", $settings );
		self::assertStringContainsString( 'RoleAccessService', $settings );
	}

	public function test_only_real_wordpress_roles_appear_and_staff_is_absent(): void {
		$administrator = $this->install_role( 'administrator', 'Administrator', [ Capabilities::VIEW, 'manage_delivery_settings' ] );
		$shop          = $this->install_role( 'shop_manager', 'Shop Manager', [ Capabilities::VIEW ] );
		unset( $administrator, $shop );

		$service = new RoleAccessService();
		$roles   = $service->roles();
		$editable = $service->editable_roles();
		$slugs   = array_column( $roles, 'slug' );
		$names   = array_column( $roles, 'name' );
		$editable_slugs = array_column( $editable, 'slug' );

		self::assertContains( 'administrator', $slugs );
		self::assertContains( 'shop_manager', $slugs );
		self::assertNotContains( 'administrator', $editable_slugs );
		self::assertContains( 'shop_manager', $editable_slugs );
		self::assertNotContains( 'staff', $slugs );
		self::assertNotContains( 'Staff', $names );
	}

	public function test_administrator_retains_required_recovery_access(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [ Capabilities::VIEW, 'manage_delivery_settings' ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'administrator' => [
					RoleAccessService::PERMISSION_VIEW     => false,
					RoleAccessService::PERMISSION_SETTINGS => false,
					RoleAccessService::PERMISSION_CHARGES  => false,
				],
			]
		);

		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), 'Missing protected administrator capability: ' . $capability );
		}
	}

	public function test_granting_manage_charges_grants_underlying_capability_and_view(): void {
		$editor = $this->install_role( 'editor', 'Editor', [ 'edit_posts' ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'editor' => [
					RoleAccessService::PERMISSION_CHARGES => true,
				],
			]
		);

		self::assertTrue( $editor->has_cap( 'manage_delivery_rate_cards' ) );
		self::assertTrue( $editor->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $editor->has_cap( 'edit_posts' ) );
		self::assertTrue( empty( $editor->capabilities['manage_options'] ) );
		self::assertTrue( empty( $editor->capabilities['manage_delivery_zones'] ) );
	}

	public function test_revoking_manage_charges_removes_the_capability(): void {
		$shop = $this->install_role(
			'shop_manager',
			'Shop Manager',
			[ Capabilities::VIEW, 'manage_delivery_rate_cards', 'edit_shop_orders' ]
		);
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'shop_manager' => [
					RoleAccessService::PERMISSION_VIEW => true,
				],
			]
		);

		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
		self::assertTrue( empty( $shop->capabilities['manage_delivery_rate_cards'] ) );
		self::assertTrue( $shop->has_cap( 'edit_shop_orders' ) );
	}

	public function test_resource_pages_enforce_capabilities_server_side(): void {
		$areas   = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/' . 'DestinationZonesPage.php' );
		$options = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/' . 'DeliveryOffersPage.php' );
		$pickup  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/' . 'PickupLocationsPage.php' );
		$except  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/' . 'ProductExceptionsPage.php' );
		$charges = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/' . 'RateCardsPage.php' );
		$site    = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/' . 'DeliverySettingsHomePage.php' );
		$status  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/' . 'SystemStatusPage.php' );

		self::assertStringContainsString( "require_capability( 'manage_delivery_zones' )", $areas );
		self::assertStringContainsString( "'manage_delivery_zones'", $areas );
		self::assertStringContainsString( "require_capability( 'manage_delivery_offers' )", $options );
		self::assertStringContainsString( 'Capabilities::PICKUP', $pickup );
		self::assertStringContainsString( "require_capability( 'manage_product_delivery_rules' )", $except );
		self::assertStringContainsString( "require_capability( 'manage_delivery_rate_cards' )", $charges );
		self::assertStringContainsString( 'Capabilities::SITE_WIDE', $site );
		self::assertStringContainsString( 'Capabilities::DIAGNOSTICS', $status );
		self::assertStringContainsString( 'current_user_can', $status );
	}

	public function test_existing_capability_assignments_survive_v3_upgrade(): void {
		$admin = $this->install_role(
			'administrator',
			'Administrator',
			[ 'manage_delivery_settings', 'manage_product_delivery_rules', Capabilities::DIAGNOSTICS ]
		);
		$shop = $this->install_role(
			'shop_manager',
			'Shop Manager',
			[ 'manage_delivery_settings', 'manage_delivery_zones', 'manage_product_delivery_rules' ]
		);
		$GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] = 2;

		( new Capabilities() )->ensure_current();

		self::assertSame( Capabilities::VERSION, (int) $GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] );
		self::assertTrue( $admin->has_cap( Capabilities::DIAGNOSTICS ) );
		self::assertTrue( $admin->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $admin->has_cap( Capabilities::SITE_WIDE ) );
		self::assertTrue( $shop->has_cap( 'manage_delivery_zones' ) );
		self::assertTrue( $shop->has_cap( Capabilities::PICKUP ) );
		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $shop->has_cap( Capabilities::SITE_WIDE ) );
		self::assertTrue( empty( $shop->capabilities[ Capabilities::DIAGNOSTICS ] ) );
	}

	public function test_permissions_changes_do_not_touch_unrelated_wordpress_capabilities(): void {
		$editor = $this->install_role( 'editor', 'Editor', [ 'edit_posts', 'publish_posts' ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'editor' => [
					RoleAccessService::PERMISSION_AREAS => true,
				],
			]
		);

		self::assertTrue( $editor->has_cap( 'edit_posts' ) );
		self::assertTrue( $editor->has_cap( 'publish_posts' ) );
		self::assertTrue( $editor->has_cap( 'manage_delivery_zones' ) );
		self::assertTrue( empty( $editor->capabilities['manage_options'] ) );
		self::assertTrue( empty( $editor->capabilities['unfiltered_html'] ) );
		self::assertSame(
			RoleAccessService::capability_for( RoleAccessService::PERMISSION_AREAS ),
			'manage_delivery_zones'
		);
	}

	public function test_qa3_product_variation_international_and_wizard_protections_remain(): void {
		$preview = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/EffectiveConfigurationPreviewPage.php' );
		$js      = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/admin/delivery-engine-admin.js' );
		$wizard  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/SetupWizardPage.php' );
		$home    = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsHomePage.php' );
		$endpoint = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/PreviewVariationsEndpoint.php' );

		self::assertStringContainsString( 'cetech_de_preview_variations', $js );
		self::assertStringContainsString( 'cetech_de_preview_variations', $endpoint );
		self::assertStringContainsString( 'data-cetech-de-preview-variation', $preview );
		self::assertStringContainsString( 'Select at least one Delivery Option before continuing.', $wizard );
		self::assertStringContainsString( 'International shipping options', $home );
		self::assertStringContainsString( 'Customer fulfilment', $home );
		self::assertStringContainsString( 'DeliveryOptionCompatibility::', $home );
		self::assertStringContainsString( 'Existing delivery configuration is still serving customers while you finish Delivery Engine setup.', $wizard );
		self::assertStringNotContainsString( 'activate the new runtime', $wizard );
	}

	/**
	 * @param list<string> $caps
	 */
	private function install_role( string $slug, string $name, array $caps = [] ): object {
		$role = new class() {
			/** @var array<string, bool> */
			public array $capabilities = [];

			public function add_cap( string $cap ): void {
				$this->capabilities[ $cap ] = true;
			}

			public function remove_cap( string $cap ): void {
				unset( $this->capabilities[ $cap ] );
			}

			public function has_cap( string $cap ): bool {
				return ! empty( $this->capabilities[ $cap ] );
			}
		};

		foreach ( $caps as $cap ) {
			$role->add_cap( $cap );
		}

		$GLOBALS['cetech_de_test_roles'][ $slug ] = $role;
		$GLOBALS['cetech_de_test_wp_roles']->roles[ $slug ] = [
			'name'         => $name,
			'capabilities' => $role->capabilities,
		];

		return $role;
	}
}
