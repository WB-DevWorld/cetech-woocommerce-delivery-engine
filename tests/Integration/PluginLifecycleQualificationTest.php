<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Bootstrap\Activator;
use CetechDeliveryEngine\Bootstrap\Deactivator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Bootstrap\Uninstaller;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Versioning\MigrationInterface;
use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;

/**
 * RC.6 plugin lifecycle: activation, deactivation, uninstall, migrations.
 */
final class PluginLifecycleQualificationTest extends TestCase {

	protected function setUp(): void {
		LifecycleHarness::reset();
	}

	public function test_fresh_activation_is_idempotent_and_does_not_seed_demo_data(): void {
		Activator::activate();

		$first_tables = LifecycleHarness::table_count();
		$first_caps   = count( $GLOBALS['cetech_de_test_roles']['administrator']->capabilities );
		$marker       = 'rc6-lifecycle-config';

		update_option( 'cetech_de_sitewide_defaults', [ 'setup_completed' => true, 'marker' => $marker ], false );
		update_option( '_cetech_de_delivery_snapshot', 'order-meta-must-remain', false );

		$flags = new FeatureFlags();
		$flags->set( 'enable_shipment_records', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );

		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
		self::assertGreaterThanOrEqual( count( ConfigurationTables::all_suffixes() ), $first_tables );
		self::assertTrue( $GLOBALS['cetech_de_test_roles']['administrator']->has_cap( Capabilities::VIEW ) );
		self::assertSame( 1, get_transient( 'cetech_de_activation_notice' ) );
		self::assertGreaterThan( 0, (int) $GLOBALS['cetech_de_test_rewrite_flushes'] );
		self::assertFalse( $flags->get( 'demo_data_on_activation' ) );

		Activator::activate();

		self::assertSame( $first_tables, LifecycleHarness::table_count() );
		self::assertSame( $first_caps, count( $GLOBALS['cetech_de_test_roles']['administrator']->capabilities ) );
		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
		self::assertTrue( $flags->get( 'enable_shipment_records' ) );
		self::assertTrue( $flags->get( 'enable_woocommerce_shipping_rate_calculation' ) );
		self::assertSame( $marker, get_option( 'cetech_de_sitewide_defaults' )['marker'] ?? null );
		self::assertSame( 'order-meta-must-remain', get_option( '_cetech_de_delivery_snapshot' ) );
		self::assertSame( 'success', MigrationStatus::get()['status'] ?? null );
	}

	public function test_deactivation_does_not_destroy_configuration_or_schema(): void {
		Activator::activate();
		update_option( 'cetech_de_sitewide_defaults', [ 'setup_completed' => true ], false );
		update_option( ShipmentOperationsIssueStore::INDEX_OPTION, [ 99 => [ 'code' => 'keep' ] ], false );

		$tables = LifecycleHarness::table_count();

		Deactivator::deactivate();

		self::assertFalse( get_transient( 'cetech_de_activation_notice' ) );
		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
		self::assertSame( $tables, LifecycleHarness::table_count() );
		self::assertTrue( get_option( 'cetech_de_sitewide_defaults' )['setup_completed'] ?? false );
		self::assertSame( [ 99 => [ 'code' => 'keep' ] ], get_option( ShipmentOperationsIssueStore::INDEX_OPTION ) );
		self::assertTrue( $GLOBALS['cetech_de_test_roles']['administrator']->has_cap( Capabilities::VIEW ) );
		self::assertGreaterThan( 0, (int) $GLOBALS['cetech_de_test_rewrite_flushes'] );
	}

	public function test_reactivation_restores_cleanly_without_duplicates(): void {
		Activator::activate();
		$tables = LifecycleHarness::table_count();
		$flags  = new FeatureFlags();
		$flags->set( 'enable_tracking_links', true );

		Deactivator::deactivate();
		Activator::activate();

		self::assertSame( $tables, LifecycleHarness::table_count() );
		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
		self::assertTrue( $flags->get( 'enable_tracking_links' ) );
		self::assertTrue( $GLOBALS['cetech_de_test_roles']['administrator']->has_cap( 'manage_shipments' ) );
	}

	public function test_same_version_reinstall_is_harmless(): void {
		Activator::activate();
		SchemaVersion::set( SchemaVersion::TARGET );
		$tables = LifecycleHarness::table_count();

		Activator::activate();

		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
		self::assertSame( $tables, LifecycleHarness::table_count() );
	}

	public function test_schema_three_to_four_is_idempotent_and_preserves_scoped_data(): void {
		Activator::activate();
		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );

		$wpdb = $GLOBALS['wpdb'];
		$wpdb->register_table( 'wp_delivery_engine_configuration_scopes' );
		$wpdb->insert(
			'wp_delivery_engine_configuration_scopes',
			[
				'scope_type'     => 'global',
				'scope_id'       => 0,
				'slice_key'      => 'rc6-marker',
				'status'         => 'active',
				'config_version' => 1,
				'source'         => 'native',
			]
		);

		foreach ( [ 'shipments', 'shipment_items', 'shipment_events', 'bulk_jobs', 'bulk_job_items', 'bulk_recipes' ] as $suffix ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'delivery_engine_' . $suffix . '`' );
		}

		SchemaVersion::set( '3' );
		self::assertFalse( ConfigurationTables::exists( 'shipments' ) );

		Activator::activate();

		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
		self::assertTrue( ConfigurationTables::exists( 'shipments' ) );
		self::assertTrue( ConfigurationTables::exists( 'bulk_jobs' ) );
		self::assertTrue( ConfigurationTables::exists( 'configuration_scopes' ) );

		$row = $wpdb->get_row(
			"SELECT * FROM `wp_delivery_engine_configuration_scopes` WHERE slice_key = 'rc6-marker' LIMIT 1"
		);
		self::assertIsArray( $row );
		self::assertSame( 'rc6-marker', $row['slice_key'] );

		$tables = LifecycleHarness::table_count();
		Activator::activate();
		self::assertSame( $tables, LifecycleHarness::table_count() );
		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
	}

	public function test_failed_migration_does_not_advance_schema_and_retries_without_duplicate_success(): void {
		$ok = new class() implements MigrationInterface {
			public int $runs = 0;

			public function get_id(): string {
				return 'ok_v1';
			}

			public function get_version(): string {
				return '1';
			}

			public function up(): void {
				++$this->runs;
			}
		};

		$fail = new class() implements MigrationInterface {
			public int $runs = 0;

			public function get_id(): string {
				return 'fail_v2';
			}

			public function get_version(): string {
				return '2';
			}

			public function up(): void {
				++$this->runs;
				throw new \RuntimeException( 'simulated partial migration' );
			}
		};

		$runner = new MigrationRunner( new Logger() );
		$runner->set_migrations( [ $ok, $fail ] );
		$runner->run();

		self::assertSame( '1', SchemaVersion::get() );
		self::assertSame( 'failed', MigrationStatus::get()['status'] ?? null );
		self::assertSame( 'fail_v2', MigrationStatus::get()['migration_id'] ?? null );
		self::assertSame( 1, $ok->runs );
		self::assertSame( 1, $fail->runs );

		$runner->run();

		self::assertSame( '1', SchemaVersion::get() );
		self::assertSame( 1, $ok->runs );
		self::assertGreaterThanOrEqual( 2, $fail->runs );
		self::assertSame( 'failed', MigrationStatus::get()['status'] ?? null );
	}

	public function test_default_uninstall_preserves_business_data(): void {
		Activator::activate();
		update_option( 'cetech_de_sitewide_defaults', [ 'setup_completed' => true ], false );
		update_option( '_cetech_de_delivery_snapshot', 'keep-snapshot', false );
		$tables = LifecycleHarness::table_count();

		Uninstaller::uninstall();

		self::assertSame( SchemaVersion::TARGET, SchemaVersion::get() );
		self::assertSame( $tables, LifecycleHarness::table_count() );
		self::assertTrue( get_option( 'cetech_de_sitewide_defaults' )['setup_completed'] ?? false );
		self::assertSame( 'keep-snapshot', get_option( '_cetech_de_delivery_snapshot' ) );
		self::assertTrue( $GLOBALS['cetech_de_test_roles']['administrator']->has_cap( Capabilities::VIEW ) );
	}

	public function test_delete_data_uninstall_drops_plugin_tables_and_keeps_order_meta_and_foreign_options(): void {
		Activator::activate();
		update_option( Uninstaller::DELETE_DATA_OPTION, 1, false );
		update_option( 'cetech_de_sitewide_defaults', [ 'setup_completed' => true ], false );
		update_option( '_cetech_de_delivery_snapshot', 'order-meta-retained', false );
		update_option( 'woocommerce_unrelated', 'keep-wc', false );
		update_option( ShipmentOperationsIssueStore::INDEX_OPTION, [ 1 => true ], false );

		Uninstaller::uninstall();

		self::assertSame( 0, LifecycleHarness::table_count() );
		self::assertNull( get_option( SchemaVersion::OPTION_NAME, null ) );
		self::assertNull( get_option( 'cetech_de_sitewide_defaults', null ) );
		self::assertNull( get_option( FeatureFlags::OPTION_PREFIX . 'enable_shipment_records', null ) );
		self::assertFalse( $GLOBALS['cetech_de_test_roles']['administrator']->has_cap( Capabilities::VIEW ) );
		self::assertSame( 'order-meta-retained', get_option( '_cetech_de_delivery_snapshot' ) );
		self::assertSame( 'keep-wc', get_option( 'woocommerce_unrelated' ) );
		self::assertSame(
			[ 1 => true ],
			get_option( ShipmentOperationsIssueStore::INDEX_OPTION ),
			'Known RC.6 residual: shipment ops issue index is not removed on delete-data uninstall.'
		);
	}

	public function test_wordpress_delete_runner_preserve_and_delete_paths(): void {
		$php    = PHP_BINARY;
		$runner = LifecycleHarness::plugin_root() . DIRECTORY_SEPARATOR . 'tests/Integration/fixtures/uninstall-runner.php';

		$preserve = $this->run_json_fixture( $php, $runner, 'preserve' );
		self::assertSame( '4', $preserve['db_version'] );
		self::assertSame( 1, $preserve['shipment_flag'] );
		self::assertSame( 'keep-me', $preserve['unrelated'] );
		self::assertNotEmpty( $preserve['tables'] );
		self::assertTrue( $preserve['admin_has_view'] );

		$delete = $this->run_json_fixture( $php, $runner, 'delete' );
		self::assertNull( $delete['db_version'] );
		self::assertNull( $delete['shipment_flag'] );
		self::assertSame( 'keep-me', $delete['unrelated'] );
		self::assertSame( [], $delete['tables'] );
		self::assertFalse( $delete['admin_has_view'] );
	}

	public function test_uninstall_php_is_not_equivalent_to_folder_deletion(): void {
		$source = (string) file_get_contents( LifecycleHarness::plugin_root() . '/uninstall.php' );

		self::assertStringContainsString( "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) )", $source );
		self::assertStringContainsString( 'cetech_de_delete_data_on_uninstall', $source );
		self::assertStringNotContainsString( '_cetech_de_delivery_snapshot', $source );
		self::assertStringContainsString( 'This file must not fatal when WooCommerce is absent', $source );
	}

	public function test_plugin_boot_without_woocommerce_does_not_fatal_or_register_shipping(): void {
		$php    = PHP_BINARY;
		$runner = LifecycleHarness::plugin_root() . DIRECTORY_SEPARATOR . 'tests/Integration/fixtures/woocommerce-missing-boot.php';
		$decoded = $this->run_json_fixture( $php, $runner );

		self::assertFalse( $decoded['woocommerce_class'] );
		self::assertFalse( $decoded['shipping_filter'] );
		self::assertTrue( $decoded['notice'] );
		self::assertSame( SchemaVersion::TARGET, $decoded['schema'] );
	}

	public function test_capabilities_register_twice_does_not_duplicate_or_touch_unrelated_caps(): void {
		$role = $GLOBALS['cetech_de_test_roles']['administrator'];
		$role->add_cap( 'manage_options' );

		$caps = new Capabilities();
		$caps->register();
		$caps->register();

		self::assertTrue( $role->has_cap( 'manage_options' ) );
		self::assertTrue( $role->has_cap( Capabilities::VIEW ) );
		self::assertSame( 1, (int) $role->capabilities[ Capabilities::VIEW ] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_json_fixture( string $php, string $runner, string ...$args ): array {
		$command = escapeshellarg( $php ) . ' ' . escapeshellarg( $runner );
		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$output = [];
		$code   = 0;
		exec( $command, $output, $code );

		self::assertSame( 0, $code, implode( "\n", $output ) );
		$decoded = json_decode( implode( "\n", $output ), true );
		self::assertIsArray( $decoded );

		return $decoded;
	}
}
