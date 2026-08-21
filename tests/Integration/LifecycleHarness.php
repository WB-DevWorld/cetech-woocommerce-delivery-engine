<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use ReflectionClass;

/**
 * Shared WordPress/wpdb doubles for RC.6 lifecycle qualification tests.
 */
final class LifecycleHarness {

	public static function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	public static function reset(): FakeWpdb {
		self::define_paths();

		$GLOBALS['cetech_de_test_options']              = [];
		$GLOBALS['cetech_de_test_transients']           = [];
		$GLOBALS['cetech_de_test_filters']              = [];
		$GLOBALS['cetech_de_test_actions']              = [];
		$GLOBALS['cetech_de_test_rewrite_flushes']      = 0;
		$GLOBALS['cetech_de_test_deactivated_plugins']  = [];
		$GLOBALS['cetech_de_test_is_admin']             = false;
		$GLOBALS['cetech_de_test_caps']                 = [ 'manage_options' => true ];

		$administrator = self::role();
		$shop_manager  = self::role();

		$GLOBALS['cetech_de_test_roles'] = [
			'administrator' => $administrator,
			'shop_manager'  => $shop_manager,
		];
		$GLOBALS['cetech_de_test_wp_roles'] = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $roles = [
				'administrator' => [],
				'shop_manager'  => [],
			];
		};

		$wpdb           = new FakeWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		self::reset_plugin_singleton();

		return $wpdb;
	}

	public static function table_count(): int {
		global $wpdb;

		if ( $wpdb instanceof FakeWpdb ) {
			return count( $wpdb->table_names() );
		}

		return 0;
	}

	/**
	 * @return list<string>
	 */
	public static function plugin_table_names(): array {
		return ConfigurationTables::all();
	}

	public static function reset_plugin_singleton(): void {
		$reflection = new ReflectionClass( Plugin::class );
		$instance   = $reflection->getProperty( 'instance' );

		if ( \PHP_VERSION_ID < 80500 ) {
			$instance->setAccessible( true );
		}

		$instance->setValue( null, null );
	}

	private static function define_paths(): void {
		$root = self::plugin_root();

		if ( ! defined( 'CETECH_DE_PATH' ) ) {
			define( 'CETECH_DE_PATH', $root . DIRECTORY_SEPARATOR );
		}

		if ( ! defined( 'CETECH_DE_FILE' ) ) {
			define( 'CETECH_DE_FILE', CETECH_DE_PATH . 'cetech-woocommerce-delivery-engine.php' );
		}

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR );
		}
	}

	private static function role(): object {
		return new class() {
			/** @var array<string, bool> */
			public array $capabilities = [];

			public function add_cap( string $capability ): void {
				$this->capabilities[ $capability ] = true;
			}

			public function remove_cap( string $capability ): void {
				unset( $this->capabilities[ $capability ] );
			}

			public function has_cap( string $capability ): bool {
				return ! empty( $this->capabilities[ $capability ] );
			}
		};
	}
}
