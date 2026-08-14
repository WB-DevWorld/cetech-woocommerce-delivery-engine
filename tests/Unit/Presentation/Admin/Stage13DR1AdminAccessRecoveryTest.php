<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Capabilities\RoleAccessService;
use CetechDeliveryEngine\Presentation\Admin\AdministratorAccessRecovery;
use CetechDeliveryEngine\Presentation\Admin\AdminPageAccess;
use PHPUnit\Framework\TestCase;

final class Stage13DR1AdminAccessRecoveryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options']   = [];
		$GLOBALS['cetech_de_test_roles']     = [];
		$GLOBALS['cetech_de_test_caps']      = [];
		$GLOBALS['cetech_de_test_is_admin']  = true;
		$GLOBALS['cetech_de_test_wp_roles']  = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $roles = [];
		};
		$GLOBALS['cetech_de_test_nonce_ok']  = true;
		$GLOBALS['cetech_de_test_wp_die']    = null;
		$GLOBALS['cetech_de_test_redirects'] = [];
		$_POST = [];
		$_GET  = [];

		$this->ensure_recovery_stubs();
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
	}

	public function test_a_administrator_cannot_lose_capabilities_through_access_save(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [ Capabilities::VIEW ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'administrator' => array_fill_keys(
					array_column( RoleAccessService::permissions(), 'key' ),
					false
				),
			]
		);

		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
	}

	public function test_b_administrator_row_is_not_rendered_as_editable_controls(): void {
		$settings = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsPage.php' );

		self::assertStringContainsString( 'editable_roles()', $settings );
		self::assertStringContainsString( 'data-cetech-de-access-protected-admin', $settings );
		self::assertStringContainsString( 'Full Delivery Engine access', $settings );
		self::assertStringContainsString( 'Administrators always retain full Delivery Engine access.', $settings );
		self::assertStringNotContainsString( "data-cetech-de-access-role=\"' . esc_attr( 'administrator' )", $settings );
		self::assertStringNotContainsString( 'access[administrator]', $settings );
		self::assertStringNotContainsString( '$name . \'_display\'', $settings );
	}

	public function test_c_subordinate_role_capabilities_remain_editable(): void {
		$this->install_role( 'administrator', 'Administrator', Capabilities::ALL );
		$shop = $this->install_role( 'shop_manager', 'Shop Manager', [ Capabilities::VIEW ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'shop_manager' => [
					RoleAccessService::PERMISSION_VIEW  => true,
					RoleAccessService::PERMISSION_AREAS => true,
				],
			]
		);

		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $shop->has_cap( 'manage_delivery_zones' ) );
		self::assertTrue( empty( $shop->capabilities['manage_delivery_rate_cards'] ) );

		$matrix = ( new RoleAccessService() )->current_matrix();
		self::assertArrayHasKey( 'shop_manager', $matrix );
		self::assertArrayNotHasKey( 'administrator', $matrix );
	}

	public function test_d_shop_manager_permissions_are_not_reset_by_capability_self_heal(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [ Capabilities::VIEW ] );
		$shop  = $this->install_role(
			'shop_manager',
			'Shop Manager',
			[ Capabilities::VIEW, 'manage_delivery_zones', 'edit_shop_orders' ]
		);
		$GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] = Capabilities::VERSION;

		( new Capabilities() )->ensure_current();

		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
		self::assertTrue( $shop->has_cap( Capabilities::VIEW ) );
		self::assertTrue( $shop->has_cap( 'manage_delivery_zones' ) );
		self::assertTrue( $shop->has_cap( 'edit_shop_orders' ) );
		self::assertTrue( empty( $shop->capabilities[ Capabilities::DIAGNOSTICS ] ) );
		self::assertTrue( empty( $shop->capabilities['manage_delivery_rate_cards'] ) );
	}

	public function test_e_recovery_succeeds_with_manage_options_without_delivery_capabilities(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [] );
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_options' => true ];

		$recovery = new AdministratorAccessRecovery( new Capabilities() );
		self::assertTrue( $recovery->can_recover() );
		self::assertTrue( $recovery->needs_repair() );
		self::assertTrue( $recovery->restore_if_authorized() );

		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
		self::assertArrayNotHasKey( Capabilities::VIEW, $GLOBALS['cetech_de_test_caps'] );
		self::assertArrayNotHasKey( Capabilities::DIAGNOSTICS, $GLOBALS['cetech_de_test_caps'] );
	}

	public function test_f_recovery_fails_without_manage_options(): void {
		$this->install_role( 'administrator', 'Administrator', [] );
		$GLOBALS['cetech_de_test_caps'] = [
			Capabilities::VIEW        => true,
			Capabilities::DIAGNOSTICS => true,
		];

		$recovery = new AdministratorAccessRecovery( new Capabilities() );
		self::assertFalse( $recovery->can_recover() );
		self::assertFalse( $recovery->restore_if_authorized() );
		self::assertTrue( empty( get_role( 'administrator' )->capabilities[ Capabilities::VIEW ] ) );
	}

	public function test_g_recovery_requires_nonce(): void {
		$this->install_role( 'administrator', 'Administrator', [] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;
		$GLOBALS['cetech_de_test_nonce_ok'] = false;

		$recovery = new AdministratorAccessRecovery( new Capabilities() );

		try {
			$recovery->process_restore_request();
			self::fail( 'Expected nonce failure to throw.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'nonce_failed', $exception->getMessage() );
		}

		self::assertTrue( empty( get_role( 'administrator' )->capabilities[ Capabilities::VIEW ] ) );
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdministratorAccessRecovery.php' );
		self::assertStringContainsString( 'check_admin_referer( self::ACTION, \'cetech_de_nonce\' )', $source );
	}

	public function test_h_and_i_recovery_restores_all_required_capabilities_idempotently(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		$recovery = new AdministratorAccessRecovery( new Capabilities() );
		self::assertTrue( $recovery->restore_if_authorized() );
		self::assertTrue( $recovery->restore_if_authorized() );

		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
		self::assertFalse( $recovery->needs_repair() );
	}

	public function test_j_diagnostics_still_requires_view_delivery_diagnostics(): void {
		$status = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/SystemStatusPage.php' );
		$menu   = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminMenu.php' );

		self::assertStringContainsString( 'Capabilities::DIAGNOSTICS', $status );
		self::assertStringContainsString( 'Capabilities::DIAGNOSTICS', $menu );
		self::assertStringContainsString(
			'current_user_can( \\CetechDeliveryEngine\\Core\\Capabilities\\Capabilities::DIAGNOSTICS )',
			$status
		);
		self::assertStringContainsString(
			'AdminPageAccess::require_capability( \\CetechDeliveryEngine\\Core\\Capabilities\\Capabilities::DIAGNOSTICS )',
			$status
		);
	}

	public function test_k_capability_boot_self_heals_administrator_without_resetting_subordinates(): void {
		$admin  = $this->install_role( 'administrator', 'Administrator', [] );
		$editor = $this->install_role( 'editor', 'Editor', [ 'edit_posts', Capabilities::VIEW ] );
		$GLOBALS['cetech_de_test_options'][ Capabilities::VERSION_OPTION ] = Capabilities::VERSION;

		( new Capabilities() )->ensure_current();

		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
		self::assertTrue( $editor->has_cap( 'edit_posts' ) );
		self::assertTrue( $editor->has_cap( Capabilities::VIEW ) );
		self::assertTrue( empty( $editor->capabilities['manage_delivery_zones'] ) );
		self::assertTrue( empty( $editor->capabilities[ Capabilities::DIAGNOSTICS ] ) );
	}

	public function test_l_page_authorization_uses_current_user_can_not_role_name_only(): void {
		$access    = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdminPageAccess.php' );
		$recovery  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdministratorAccessRecovery.php' );
		$plugin    = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Bootstrap/Plugin.php' );
		$settings  = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsPage.php' );

		self::assertStringContainsString( 'current_user_can( $capability )', $access );
		self::assertStringNotContainsString( "=== 'administrator'", $access );
		self::assertStringContainsString( "current_user_can( 'manage_options' )", $recovery );
		self::assertStringContainsString( 'check_admin_referer( self::ACTION', $recovery );
		self::assertStringContainsString( 'AdministratorAccessRecovery::class', $plugin );
		self::assertStringContainsString( 'role_access->can_edit()', $settings );
		self::assertSame( AdminPageAccess::class, AdminPageAccess::class );
	}

	public function test_recovery_handler_rejects_unauthorized_post_and_accepts_authorized_nonce(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [] );
		$recovery = new AdministratorAccessRecovery( new Capabilities() );

		$GLOBALS['cetech_de_test_caps'] = [];
		try {
			$recovery->process_restore_request();
			self::fail( 'Expected unauthorized recovery to die.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'wp_die', $exception->getMessage() );
		}
		self::assertTrue( empty( $admin->capabilities[ Capabilities::VIEW ] ) );

		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;
		$GLOBALS['cetech_de_test_nonce_ok'] = true;
		self::assertTrue( $recovery->process_restore_request() );
		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}

		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/AdministratorAccessRecovery.php' );
		self::assertStringContainsString( 'wp_safe_redirect( $redirect )', $source );
		self::assertMatchesRegularExpression( '/\bexit\s*;/', $source );
	}

	public function test_access_ui_copy_and_protected_presentation_are_distinct(): void {
		$settings = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/DeliverySettingsPage.php' );
		$css      = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/admin/delivery-engine-admin.css' );

		self::assertStringContainsString(
			'Administrators always retain full Delivery Engine access. Configure access for other WordPress roles below.',
			$settings
		);
		self::assertStringContainsString( 'cetech-de-access-protected-admin', $css );
		self::assertStringContainsString( 'border-left: 4px solid #2271b1', $css );
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

	private function ensure_recovery_stubs(): void {
		// Keep stubs in the global namespace for WordPress function names used by recovery.
		if ( ! function_exists( 'wp_unslash' ) ) {
			eval( 'namespace { function wp_unslash( $value ) { return $value; } }' );
		}
		if ( ! function_exists( 'check_admin_referer' ) ) {
			eval(
				'namespace { function check_admin_referer( $action = -1, $query_arg = "_wpnonce" ) {
					if ( empty( $GLOBALS["cetech_de_test_nonce_ok"] ) ) {
						throw new \\RuntimeException( "nonce_failed" );
					}
					return 1;
				} }'
			);
		}
		if ( ! function_exists( 'wp_die' ) ) {
			eval(
				'namespace { function wp_die( $message = "", $title = "", $args = array() ) {
					$GLOBALS["cetech_de_test_wp_die"] = $message;
					throw new \\RuntimeException( "wp_die" );
				} }'
			);
		}
		if ( ! function_exists( 'wp_get_referer' ) ) {
			eval( 'namespace { function wp_get_referer() { return "https://example.test/wp-admin/index.php"; } }' );
		}
		if ( ! function_exists( 'add_query_arg' ) ) {
			eval(
				'namespace { function add_query_arg( ...$args ) {
					if ( count( $args ) === 3 ) {
						return $args[2] . "?" . rawurlencode( (string) $args[0] ) . "=" . rawurlencode( (string) $args[1] );
					}
					return "https://example.test/wp-admin/";
				} }'
			);
		}
		if ( ! function_exists( 'wp_safe_redirect' ) ) {
			eval(
				'namespace { function wp_safe_redirect( $location, $status = 302, $x_redirect_by = "WordPress" ) {
					$GLOBALS["cetech_de_test_redirects"][] = $location;
					return true;
				} }'
			);
		}
		if ( ! function_exists( 'esc_html' ) ) {
			eval( 'namespace { function esc_html( $text ) { return (string) $text; } }' );
		}
		if ( ! function_exists( 'esc_html__' ) ) {
			eval( 'namespace { function esc_html__( $text, $domain = "default" ) { return (string) $text; } }' );
		}
		if ( ! function_exists( 'esc_attr' ) ) {
			eval( 'namespace { function esc_attr( $text ) { return (string) $text; } }' );
		}
		if ( ! function_exists( 'wp_nonce_field' ) ) {
			eval( 'namespace { function wp_nonce_field( $action = -1, $name = "_wpnonce", $referer = true, $echo = true ) { return ""; } }' );
		}
		if ( ! function_exists( 'submit_button' ) ) {
			eval( 'namespace { function submit_button( $text = null, $type = "primary", $name = "submit", $wrap = true, $other_attributes = null ) { return ""; } }' );
		}
	}
}
