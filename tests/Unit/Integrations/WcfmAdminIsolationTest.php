<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Integrations;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Capabilities\RoleAccessService;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;
use CetechDeliveryEngine\Integrations\Status\IntegrationStatus;
use CetechDeliveryEngine\Integrations\Status\IntegrationStatusCatalog;
use CetechDeliveryEngine\Integrations\WCFM\WcfmVendorIsolation;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WcfmAdminIsolationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']  = [];
		$GLOBALS['cetech_de_test_roles']    = [];
		$GLOBALS['cetech_de_test_caps']     = [];
		$GLOBALS['cetech_de_test_menus']    = [];
		$GLOBALS['cetech_de_test_submenus'] = [];
		$GLOBALS['cetech_de_test_wp_die']  = null;
		$GLOBALS['cetech_de_test_user_id'] = 42;
		$GLOBALS['cetech_de_test_wp_roles'] = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $roles = [];
		};
		AdminPageAccess::bind( null );
	}

	protected function tearDown(): void {
		AdminPageAccess::bind( null );
		$GLOBALS['cetech_de_test_caps'] = [];
		parent::tearDown();
	}

	public function test_wcfm_absent_leaves_existing_role_behavior_unchanged(): void {
		$isolation = new WcfmVendorIsolation();
		self::assertFalse( $isolation->is_wcfm_present() );
		self::assertFalse( $isolation->is_restricted_vendor_user() );

		$admin = $this->install_role( 'administrator', 'Administrator', Capabilities::ALL );
		$shop  = $this->install_role( 'shop_manager', 'Shop Manager', [ Capabilities::VIEW, 'manage_delivery_zones' ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'shop_manager' => [
					RoleAccessService::PERMISSION_VIEW  => true,
					RoleAccessService::PERMISSION_AREAS => true,
				],
			]
		);

		self::assertTrue( $admin->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $shop->has_cap( 'manage_delivery_zones' ) );
		$matrix = ( new RoleAccessService() )->current_matrix();
		self::assertArrayHasKey( 'shop_manager', $matrix );
	}

	public function test_administrator_retains_delivery_engine_access_when_wcfm_is_present(): void {
		$isolation = $this->vendor_isolation( true, true );
		$GLOBALS['cetech_de_test_caps'] = [
			'manage_options'   => true,
			Capabilities::VIEW => true,
		];
		AdminPageAccess::bind( $isolation );

		self::assertFalse( $isolation->is_restricted_vendor_user() );
		AdminPageAccess::require_capability( Capabilities::VIEW );
		self::assertNull( $GLOBALS['cetech_de_test_wp_die'] );
	}

	public function test_manage_options_wins_when_wcfm_reports_administrator_as_vendor(): void {
		$isolation = $this->vendor_isolation( true, true );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		self::assertFalse( $isolation->is_restricted_vendor_user() );
		self::assertFalse( AdminPageAccess::current_user_is_restricted() );
	}

	public function test_vendor_with_view_capability_is_denied_direct_overview_access(): void {
		$isolation = $this->vendor_isolation( true, true );
		$GLOBALS['cetech_de_test_caps'][ Capabilities::VIEW ] = true;
		AdminPageAccess::bind( $isolation );

		self::assertTrue( $isolation->is_restricted_vendor_user() );
		$this->assert_access_denied( Capabilities::VIEW );
	}

	public function test_vendor_with_all_delivery_engine_capabilities_is_still_denied(): void {
		$isolation = $this->vendor_isolation( true, true );
		foreach ( Capabilities::ALL as $capability ) {
			$GLOBALS['cetech_de_test_caps'][ $capability ] = true;
		}
		AdminPageAccess::bind( $isolation );

		foreach ( Capabilities::ALL as $capability ) {
			$this->assert_access_denied( $capability );
		}
	}

	public function test_restricted_vendor_cannot_open_named_admin_pages(): void {
		$pages = [
			'OverviewPage.php'                      => 'AdminPageAccess::require_capability',
			'DeliverySettingsHomePage.php'          => 'AdminPageAccess::require_capability',
			'DeliveryOffersPage.php'                => 'AdminPageAccess::require_capability',
			'DestinationZonesPage.php'              => 'AdminPageAccess::require_capability',
			'RateCardsPage.php'                     => 'AdminPageAccess::require_capability',
			'PickupLocationsPage.php'               => 'AdminPageAccess::require_capability',
			'ProductExceptionsPage.php'             => 'AdminPageAccess::require_capability',
			'BulkToolsPage.php'                      => 'AdminPageAccess::require_capability',
			'ShipmentsPage.php'                     => 'AdminPageAccess::require_capability',
			'NeedsAttentionPage.php'                => 'AdminPageAccess::require_capability',
			'SuppliersOriginsPage.php'              => 'AdminPageAccess::require_capability',
			'LogisticsProfilesPage.php'             => 'AdminPageAccess::require_capability',
			'SystemStatusPage.php'                  => 'AdminPageAccess::require_capability',
		];

		$admin_dir = dirname( __DIR__, 3 ) . '/src/Presentation/Admin/';
		foreach ( $pages as $file => $needle ) {
			$source = (string) file_get_contents( $admin_dir . $file );
			self::assertStringContainsString( $needle, $source, $file );
		}

		$menu = (string) file_get_contents( $admin_dir . 'AdminMenu.php' );
		self::assertMatchesRegularExpression(
			'/function add_menus\(\): void \{\s+if \( AdminPageAccess::current_user_is_restricted\(\) \)/s',
			$menu
		);
	}

	public function test_wcfm_vendor_role_is_excluded_from_access_matrix(): void {
		$this->install_role( 'administrator', 'Administrator', Capabilities::ALL );
		$this->install_role( 'shop_manager', 'Shop Manager', [ Capabilities::VIEW ] );
		$this->install_role( 'wcfm_vendor', 'Vendor', [ Capabilities::VIEW ] );
		$this->install_role( 'disable_vendor', 'Disabled Vendor', [ Capabilities::VIEW ] );
		$this->install_role( 'store_vendor', 'Custom Vendor Label', [ Capabilities::VIEW ] );

		$isolation = $this->vendor_isolation( false, true );
		$access    = new RoleAccessService( $isolation->excluded_admin_role_slugs() );
		$slugs     = array_column( $access->editable_roles(), 'slug' );

		self::assertNotContains( 'wcfm_vendor', $slugs );
		self::assertNotContains( 'disable_vendor', $slugs );
		self::assertContains( 'shop_manager', $slugs );
		self::assertContains( 'store_vendor', $slugs );
		self::assertArrayNotHasKey( 'wcfm_vendor', $access->current_matrix() );
	}

	public function test_access_save_cannot_grant_delivery_engine_caps_to_wcfm_vendor(): void {
		$vendor = $this->install_role( 'wcfm_vendor', 'Vendor', [] );
		$shop   = $this->install_role( 'shop_manager', 'Shop Manager', [] );
		$this->install_role( 'administrator', 'Administrator', Capabilities::ALL );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		$access = new RoleAccessService( [ WcfmVendorIsolation::VENDOR_ROLE ] );
		$access->apply(
			[
				'wcfm_vendor'  => [ RoleAccessService::PERMISSION_VIEW => true ],
				'shop_manager' => [ RoleAccessService::PERMISSION_VIEW => true ],
			]
		);

		self::assertTrue( empty( $vendor->capabilities[ Capabilities::VIEW ] ) );
		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
	}

	public function test_existing_wcfm_vendor_view_capability_is_cleaned(): void {
		$vendor = $this->install_role( 'wcfm_vendor', 'Vendor', [ Capabilities::VIEW ] );
		$shop   = $this->install_role( 'shop_manager', 'Shop Manager', [ Capabilities::VIEW, 'manage_delivery_zones' ] );

		( $this->vendor_isolation( false, true ) )->harden_vendor_role_capabilities();

		self::assertTrue( empty( $vendor->capabilities[ Capabilities::VIEW ] ) );
		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $shop->has_cap( 'manage_delivery_zones' ) );
	}

	public function test_existing_wcfm_vendor_all_capabilities_are_cleaned(): void {
		$vendor = $this->install_role( 'wcfm_vendor', 'Vendor', Capabilities::ALL );
		$editor = $this->install_role( 'editor', 'Editor', [ 'edit_posts', Capabilities::VIEW ] );
		$admin  = $this->install_role( 'administrator', 'Administrator', Capabilities::ALL );

		( $this->vendor_isolation( false, true ) )->harden_vendor_role_capabilities();

		foreach ( Capabilities::ALL as $capability ) {
			self::assertTrue( empty( $vendor->capabilities[ $capability ] ), $capability );
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
		self::assertTrue( $editor->has_cap( 'edit_posts' ) );
		self::assertTrue( $editor->has_cap( Capabilities::VIEW ) );
	}

	public function test_capability_version_four_does_not_reset_shop_manager(): void {
		$shop = $this->install_role(
			'shop_manager',
			'Shop Manager',
			[ Capabilities::VIEW, 'manage_delivery_zones', 'edit_shop_orders' ]
		);
		$admin = $this->install_role( 'administrator', 'Administrator', [ Capabilities::VIEW ] );
		$GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] = 3;

		( new Capabilities() )->ensure_current();

		self::assertSame( 4, Capabilities::VERSION );
		self::assertSame( 4, (int) $GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] );
		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $shop->has_cap( 'manage_delivery_zones' ) );
		self::assertTrue( $shop->has_cap( 'edit_shop_orders' ) );
		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
	}

	public function test_isolation_ignores_enable_wcfm_adapter_flag(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Integrations/WCFM/WcfmVendorIsolation.php' );
		self::assertStringNotContainsString( "is_enabled( 'enable_wcfm_adapter' )", $source );
		self::assertStringNotContainsString( 'FeatureFlags', $source );
		self::assertStringContainsString( "function_exists( 'wcfm_is_vendor' )", $source );
		self::assertStringContainsString( 'wcfm_is_vendor( $user_id )', $source );
	}

	public function test_wcfm_status_copy_is_administrative_isolation_not_a_fulfilment_adapter(): void {
		$catalog = new IntegrationStatusCatalog(
			new IntegrationRegistry( new Logger() ),
			new \CetechDeliveryEngine\Integrations\Blocks\BlocksUsageDetector()
		);
		$status = $catalog->wcfm();

		self::assertSame( IntegrationStatus::STATE_NOT_INSTALLED, $status->state );
		self::assertFalse( $status->adapter_implemented );
		self::assertStringContainsString( 'administrative isolation', $status->detail );
		self::assertStringContainsString( 'Vendor-specific Delivery Engine fulfilment controls are not provided.', $status->detail );
		self::assertStringNotContainsString( 'No marketplace adapter is implemented', $status->detail );
	}

	public function test_customer_storefront_and_checkout_surfaces_do_not_depend_on_wcfm_isolation(): void {
		$root = dirname( __DIR__, 3 );
		$files = [
			'/src/Presentation/Frontend/ProductDeliverySelectorRenderer.php',
			'/src/Presentation/Frontend/CustomerOrderDeliverySummaryRenderer.php',
			'/src/Presentation/Frontend/CustomerShipmentRenderer.php',
			'/src/Application/Checkout/CheckoutDeliverySelectionValidator.php',
			'/src/Application/Shipping/ShippingPackageBuilder.php',
			'/src/Application/Shipping/SelectedOfferShippingIntegration.php',
			'/src/Application/Cart/CartDeliverySelectionCapture.php',
			'/src/Application/Cart/CartDeliverySelectionRevalidator.php',
			'/src/Integrations/Blocks/BlocksCheckoutAdapter.php',
			'/src/Integrations/Blocks/BlocksCheckoutValidation.php',
		];

		foreach ( $files as $relative ) {
			$source = (string) file_get_contents( $root . $relative );
			self::assertStringNotContainsString( 'WcfmVendorIsolation', $source, $relative );
			self::assertStringNotContainsString( 'wcfm_is_vendor', $source, $relative );
		}
	}

	public function test_schema_remains_five(): void {
		self::assertSame( '5', SchemaVersion::TARGET );
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

	private function vendor_isolation( bool $is_vendor, bool $present ): WcfmVendorIsolation {
		return new WcfmVendorIsolation(
			static fn ( ?int $user_id = null ): bool => $is_vendor,
			static fn (): bool => $present
		);
	}

	private function assert_access_denied( string $capability ): void {
		$GLOBALS['cetech_de_test_wp_die'] = null;
		try {
			AdminPageAccess::require_capability( $capability );
			self::fail( 'Expected restricted vendor to be denied ' . $capability );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'wp_die', $exception->getMessage() );
		}
		self::assertNotNull( $GLOBALS['cetech_de_test_wp_die'] );
	}
}
