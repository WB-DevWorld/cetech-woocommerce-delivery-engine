<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Bootstrap\Deactivator;
use CetechDeliveryEngine\Bootstrap\Uninstaller;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProfile;

/**
 * Native C03-19: real lifecycle handlers with isolated per-site tables, options,
 * roles and an independent default object cache. No unit bootstrap or substitutes.
 */
return static function ( callable $check ): void {
	global $wpdb;
	if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_ADMIN' ) || ! WP_ADMIN
		|| '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' )
		|| '1' !== (string) get_option( 'cetech_opening_qualification_disposable' )
		|| ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST )
		|| ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME )
		|| is_multisite()
		|| ! $wpdb instanceof wpdb || ! isset( $GLOBALS['wp_object_cache'] ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] )
	) {
		throw new RuntimeException( 'Lifecycle proof requires the marked native default-cache fixture.' );
	}
	foreach ( get_included_files() as $included ) {
		if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) {
			throw new RuntimeException( 'Unit bootstrap is forbidden in native lifecycle qualification.' );
		}
	}
	$root = dirname( __DIR__, 2 );
	foreach ( [ 'OperationProofCommand', 'OperationProofDatabase', 'OperationProofProfile' ] as $fixture ) {
		require_once $root . '/tests/Support/Operation/' . $fixture . '.php';
	}
	$host = $wpdb->parse_db_host( DB_HOST );
	if ( ! is_array( $host ) ) { throw new RuntimeException( 'Native lifecycle authority is unavailable.' ); }
	try {
		$physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] );
		if ( $physical->connect_errno || ! $physical->set_charset( 'utf8mb4' ) ) { throw new RuntimeException(); }
	} catch ( Throwable ) {
		throw new RuntimeException( 'Native lifecycle database is unavailable.' );
	}
	$prefix = OperationProofDatabase::prefix();
	$site = (int) get_current_blog_id();
	$original = [];
	foreach ( [ 'wpdb', 'wp_roles', 'wp_user_roles', 'wp_object_cache', 'wp_rewrite', 'current_user', 'user_ID' ] as $key ) {
		$original[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ];
	}
	$main_db = $wpdb;
	$main_roles_key = $main_db->get_blog_prefix( $site ) . 'user_roles';
	$main_roles_before = $main_db->get_var( $main_db->prepare( "SELECT option_value FROM `{$main_db->options}` WHERE option_name=%s", $main_roles_key ) );
	$main_schema_before = $main_db->get_var( $main_db->prepare( "SELECT option_value FROM `{$main_db->options}` WHERE option_name=%s", SchemaVersion::OPTION_NAME ) );
	$main_connection_id = (int) $main_db->get_var( 'SELECT CONNECTION_ID()' );
	$fixture_db = null;
	$temporary = null;
	$installed = false;
	$no_hard_flush = static fn (): bool => false;
	$filter_installed = false;
	$cleanup_ok = false;
	try {
		if ( 0 !== (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,LENGTH('{$prefix}'))='{$prefix}'" ) ) {
			throw new RuntimeException( 'Lifecycle proof prefix is already occupied.' );
		}
		$installed = true;
		OperationProofDatabase::install( $physical, $prefix );
		// Real WordPress has autoloaded options. Without one, wp_load_alloptions()
		// falls back to every row, including off rows whose deletion only clears
		// their individual cache entry. Keep this isolated fixture on the normal path.
		OperationProofDatabase::execute( $physical, "INSERT INTO `{$prefix}options` (option_name, option_value, autoload) VALUES ('operation_fixture_autoload_marker','1','on')" );
		$fixture_autoload_present = 1 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$prefix}options` WHERE option_name='operation_fixture_autoload_marker' AND option_value='1' AND autoload='on'" );
		$fixture_roles = [
			'administrator' => [ 'name' => 'Administrator', 'capabilities' => [ 'manage_options' => true, 'view_delivery_engine' => true ] ],
			'shop_manager' => [ 'name' => 'Shop Manager', 'capabilities' => [ 'view_delivery_engine' => true ] ],
		];
		OperationProofDatabase::option( $physical, $prefix, $prefix . 'user_roles', serialize( $fixture_roles ) );
		$configuration = [ 'host' => $host[0], 'port' => $host[1] ?: 3306, 'socket' => $host[2], 'user' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci' ];
		$factory = OperationConnectionFactory::from_server_configuration( $site, $prefix, $configuration );
		$profile = new OperationProofProfile( $prefix );
		$registry = new OperationProfileRegistry( [ $profile ] );
		$identity = new OperationIdentity( $site, 'wordpress', 'staff:9', 'fixture.counter_update', 1, 'counter:1', 'lifecycle-preserved' );
		$payload = [ 'row_id' => 1, 'expected_revision' => 1, 'value' => 73 ];
		$accepted = ( new OperationCoordinator( $registry, $factory ) )->attempt( $identity, $payload, RequestContext::create() );
		$pair = static function () use ( $physical, $prefix ): array {
			return [
				OperationProofDatabase::row( $physical, "SELECT * FROM `{$prefix}delivery_engine_operation_records` ORDER BY id LIMIT 1" ),
				OperationProofDatabase::row( $physical, "SELECT * FROM `{$prefix}delivery_engine_operation_changes` ORDER BY id LIMIT 1" ),
				OperationProofDatabase::resource( $physical, $prefix ),
			];
		};
		$before = $pair();
		$check( 'NATIVE-C03-LIFECYCLE-ACCEPTED-PAIR-SEEDED', $fixture_autoload_present && 'accepted' === $accepted->outcome->state && null !== $before[0] && null !== $before[1] && 'accepted' === $before[0]['state'] && (int) $before[0]['audit_id'] === (int) $before[1]['id'] && 1 === $profile->mutation_calls, [ 'fixture_autoload_present' => $fixture_autoload_present ] );

		$disabled = ( new OperationCoordinator( new OperationProfileRegistry(), $factory ) )->attempt( $identity, $payload, RequestContext::create() );
		$check( 'NATIVE-C03-WRITER-DISABLE-ROLLBACK-PRESERVES-PAIR', 'unsupported_contract' === $disabled->outcome->error?->code && $before === $pair() );
		$diagnostics = $prefix . 'delivery_engine_audit_log';
		OperationProofDatabase::execute( $physical, "CREATE TABLE `{$diagnostics}` (id bigint unsigned PRIMARY KEY, diagnostic varchar(32) NOT NULL) ENGINE=InnoDB" );
		OperationProofDatabase::execute( $physical, "INSERT INTO `{$diagnostics}` VALUES (1,'optional-fixture')" );
		OperationProofDatabase::execute( $physical, "DELETE FROM `{$diagnostics}`" );
		$check( 'NATIVE-C03-DIAGNOSTIC-CLEANUP-PRESERVES-PAIR', $before === $pair() && 0 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$diagnostics}`" ) );

		// Use real wpdb/roles/cache, isolated from the qualification site's root
		// option cache and roles. Hard rewrite file writes are explicitly suppressed.
		$fixture_db = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$fixture_db->suppress_errors( true );
		$fixture_db->hide_errors();
		$fixture_db->set_prefix( $prefix );
		$GLOBALS['wpdb'] = $fixture_db;
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
		$GLOBALS['wp_user_roles'] = null;
		$GLOBALS['wp_roles'] = new WP_Roles( $site );
		if ( isset( $original['wp_rewrite'][1] ) && is_object( $original['wp_rewrite'][1] ) ) {
			$GLOBALS['wp_rewrite'] = clone $original['wp_rewrite'][1];
		}
		add_filter( 'flush_rewrite_rules_hard', $no_hard_flush, PHP_INT_MAX );
		$filter_installed = true;
		Deactivator::deactivate();
		$check( 'NATIVE-C03-DEACTIVATE-PRESERVES-PAIR', $before === $pair() );
		delete_option( Uninstaller::DELETE_DATA_OPTION );
		Uninstaller::uninstall();
		$check( 'NATIVE-C03-DEFAULT-UNINSTALL-PRESERVES-PAIR', $before === $pair() );
		update_option( Uninstaller::DELETE_DATA_OPTION, 1, false );
		if ( 1 !== (int) get_option( Uninstaller::DELETE_DATA_OPTION ) ) { throw new RuntimeException( 'Native lifecycle explicit policy was not stored.' ); }
		Uninstaller::uninstall();
		$after_explicit = $pair();
		$schema_read = get_option( SchemaVersion::OPTION_NAME, false );
		$schema_row = OperationProofDatabase::row( $physical, "SELECT option_value FROM `{$prefix}options` WHERE option_name='cetech_de_db_version' LIMIT 1" );
		$policy_row = OperationProofDatabase::row( $physical, "SELECT option_value FROM `{$prefix}options` WHERE option_name='cetech_de_delete_data_on_uninstall' LIMIT 1" );
		$schema_cache_found = false;
		$schema_cached = wp_cache_get( SchemaVersion::OPTION_NAME, 'options', false, $schema_cache_found );
		$fixture_alloptions = wp_cache_get( 'alloptions', 'options' );
		$diagnostics_preserved = 1 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$diagnostics}'" );
		$check( 'NATIVE-C03-EXPLICIT-UNINSTALL-PRESERVES-PAIR', $before === $after_explicit && '7' === ($schema_row['option_value'] ?? null) && '7' === $schema_read && $diagnostics_preserved, [
			'record_bytes_unchanged' => $before[0] === $after_explicit[0],
			'event_bytes_unchanged' => $before[1] === $after_explicit[1],
			'resource_unchanged' => $before[2] === $after_explicit[2],
			'schema_preserved_in_database' => '7' === ($schema_row['option_value'] ?? null),
						'schema_read_is_original_value' => '7' === $schema_read,
			'schema_cache_found' => $schema_cache_found,
			'schema_cache_is_original_value' => '7' === $schema_cached,
			'fixture_autoload_marker_cached' => is_array( $fixture_alloptions ) && '1' === ( $fixture_alloptions['operation_fixture_autoload_marker'] ?? null ),
			'schema_in_alloptions_cache' => is_array( $fixture_alloptions ) && array_key_exists( SchemaVersion::OPTION_NAME, $fixture_alloptions ),
			'delete_policy_deleted_in_database' => null === $policy_row,
			'legacy_diagnostic_table_preserved' => $diagnostics_preserved,
			'isolated_native_connection_still_selected' => $GLOBALS['wpdb'] === $fixture_db && $fixture_db->prefix === $prefix,
		] );

		// An exact root-only copy intentionally lacks the fixed own-source helper.
		// It must retain the intent and records; C06 separately proves the complete loader.
		$temporary = sys_get_temp_dir() . '/cetech-operation-uninstall-' . bin2hex( random_bytes( 8 ) );
		if ( ! mkdir( $temporary, 0700 ) || ! copy( $root . '/uninstall.php', $temporary . '/uninstall.php' ) ) { throw new RuntimeException( 'Native fallback lifecycle fixture could not be prepared.' ); }
		update_option( Uninstaller::DELETE_DATA_OPTION, 1, false );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'cetech-operation-disposable-proof' ); }
		$run_fallback = static function ( string $path ): void { require $path; };
		$run_fallback( $temporary . '/uninstall.php' );
		$check( 'NATIVE-C03-NO-VENDOR-UNINSTALL-PRESERVES-PAIR', ! is_readable( $temporary . '/vendor/autoload.php' ) && $before === $pair() && 1 === get_option( Uninstaller::DELETE_DATA_OPTION, false ) );
	} finally {
		if ( $filter_installed ) { remove_filter( 'flush_rewrite_rules_hard', $no_hard_flush, PHP_INT_MAX ); }
		foreach ( $original as $key => [ $exists, $value ] ) {
			if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); }
		}
		if ( $fixture_db instanceof wpdb ) { remove_filter( 'query', [ $fixture_db, 'remove_placeholder_escape' ], 0 ); $fixture_db->close(); }
		if ( is_string( $temporary ) ) {
			if ( is_file( $temporary . '/uninstall.php' ) ) { unlink( $temporary . '/uninstall.php' ); }
			if ( is_dir( $temporary ) ) { rmdir( $temporary ); }
		}
		if ( $installed ) {
			OperationProofDatabase::execute( $physical, "DROP TABLE IF EXISTS `{$prefix}delivery_engine_audit_log`" );
			OperationProofDatabase::cleanup( $physical, $prefix );
			$cleanup_ok = 0 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,LENGTH('{$prefix}'))='{$prefix}'" );
		}
		$physical->close();
		$globals_same = true;
		foreach ( $original as $key => [ $exists, $value ] ) {
			$globals_same = $globals_same && $exists === array_key_exists( $key, $GLOBALS ) && ( ! $exists || $value === $GLOBALS[$key] );
		}
		$main_roles_after = $main_db->get_var( $main_db->prepare( "SELECT option_value FROM `{$main_db->options}` WHERE option_name=%s", $main_roles_key ) );
		$main_schema_after = $main_db->get_var( $main_db->prepare( "SELECT option_value FROM `{$main_db->options}` WHERE option_name=%s", SchemaVersion::OPTION_NAME ) );
		$check( 'NATIVE-C03-LIFECYCLE-FIXTURE-CLEANUP-RESTORE', $cleanup_ok && $globals_same && $main_roles_before === $main_roles_after && $main_schema_before === $main_schema_after && $main_connection_id === (int) $main_db->get_var( 'SELECT CONNECTION_ID()' ) && false === has_filter( 'query', [ $fixture_db, 'remove_placeholder_escape' ] ) );
	}
};
