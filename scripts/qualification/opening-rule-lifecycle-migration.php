<?php

declare(strict_types=1);

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleStartMode;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;

/** Actual native dbDelta against fixture-owned tables; no schema option writes. */
return static function ( callable $check ): void {
	global $wpdb;
	if ( '1' !== (string) getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' )
		|| ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN
		|| '1' !== (string) get_option( 'cetech_opening_qualification_disposable' )
		|| ! $wpdb instanceof wpdb
	) {
		throw new RuntimeException( 'Rule migration qualification requires the native disposable fixture.' );
	}
	foreach ( get_included_files() as $included ) {
		if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) {
			throw new RuntimeException( 'Unit bootstrap is forbidden in native migration qualification.' );
		}
	}
	$root = dirname( __DIR__, 2 );
	require_once $root . '/tests/Support/Operation/OperationProofDatabase.php';
	require_once $root . '/tests/Support/RuleLifecycle/RuleProofFamily.php';
	$original = $wpdb;
	$prefix = OperationProofDatabase::prefix();
	OperationProofDatabase::validate_prefix( $prefix );
	$owned = [];
	foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { $owned[] = $prefix . 'delivery_engine_' . $suffix; }
	$host = $original->parse_db_host( DB_HOST );
	if ( ! is_array( $host ) ) { throw new RuntimeException( 'Native migration database configuration is unavailable.' ); }
	$physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] );
	if ( 0 !== $physical->connect_errno || ! $physical->set_charset( 'utf8mb4' ) ) {
		throw new RuntimeException( 'Native migration fixture connection is unavailable.' );
	}
	$foreign_option = 'foreign_c04_rule_migration_' . bin2hex( random_bytes( 8 ) );
	update_option( $foreign_option, 'PRIVATE-C04-FOREIGN-SENTINEL', false );
	$order = wc_create_order();
	if ( ! $order instanceof WC_Order || $order->get_id() < 1 ) { throw new RuntimeException( 'Native migration order sentinel setup failed.' ); }
	$order_id = $order->get_id();
	$order->update_meta_data( '_c04_rule_migration_sentinel', 'PRIVATE-C04-ORDER-SENTINEL' ); $order->save();
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$order_meta_table = $hpos ? $original->prefix . 'wc_orders_meta' : $original->postmeta;
	$order_id_column = $hpos ? 'order_id' : 'post_id';
	$order_meta_pk = $hpos ? 'id' : 'meta_id';
	// All legacy tables are bounded fixture data. Keep bytes private and compare
	// them in-process, alongside the original schema/status option rows.
	$snapshot_main = static function () use ( $original, $foreign_option, $order_id, $order_meta_table, $order_id_column, $order_meta_pk ): array {
		$snapshot = [];
		foreach ( array_merge( ConfigurationTables::all(), [ $original->prefix . 'delivery_engine_operation_records', $original->prefix . 'delivery_engine_operation_changes' ] ) as $table ) {
			$create = $original->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_A );
			$rows = $original->get_results( "SELECT * FROM `{$table}` ORDER BY id ASC LIMIT 100", ARRAY_A );
			if ( ! is_array( $create ) || ! is_array( $rows ) || '' !== $original->last_error ) {
				throw new RuntimeException( 'Native legacy migration sentinel read failed.' );
			}
			$snapshot[ $table ] = [ $create, $rows ];
		}
		$snapshot['options'] = $original->get_results( $original->prepare(
			"SELECT option_name,option_value,autoload FROM `{$original->options}` WHERE option_name IN (%s,%s) ORDER BY option_name",
			'cetech_de_db_version', 'cetech_de_last_migration_status'
		), ARRAY_A );
		if ( '' !== $original->last_error ) { throw new RuntimeException( 'Native schema sentinel read failed.' ); }
		$snapshot['foreign_option'] = $original->get_row( $original->prepare( "SELECT option_name,option_value,autoload FROM `{$original->options}` WHERE option_name=%s", $foreign_option ), ARRAY_A );
		$snapshot['order_meta'] = $original->get_results( $original->prepare( "SELECT meta_key,meta_value FROM `{$order_meta_table}` WHERE `{$order_id_column}`=%d AND meta_key=%s ORDER BY `{$order_meta_pk}` ASC", $order_id, '_c04_rule_migration_sentinel' ), ARRAY_A );
		if ( '' !== $original->last_error || ! is_array( $snapshot['foreign_option'] ) || ! is_array( $snapshot['order_meta'] ) || 1 !== count( $snapshot['order_meta'] ) ) { throw new RuntimeException( 'Native foreign/order migration sentinel read failed.' ); }
		return $snapshot;
	};
	$before_main = $snapshot_main();
	$isolated = null;
	$filter = null;
	$main_preserved = false;
	$sentinels_cleaned = false;
	try {
		$isolated = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$isolated->set_prefix( $prefix );
		$isolated->suppress_errors( true );
		$wpdb = $isolated;
		$sql = RuleLifecycleSchema::create_table_statements( $isolated->get_charset_collate(), $prefix . 'delivery_engine_' );
		$migration = require $root . '/database/migrations/20261006214731_create_rule_lifecycle_tables.php';
		$drop = static function () use ( $physical, $owned ): void {
			foreach ( $owned as $table ) { OperationProofDatabase::execute( $physical, "DROP TABLE IF EXISTS `{$table}`" ); }
		};
		$install = static function () use ( $physical, $sql ): void {
			foreach ( $sql as $statement ) { OperationProofDatabase::execute( $physical, $statement ); }
		};
		$records = $owned[0];
		$changes = $owned[1];
		$versions = $owned[2];
		OperationProofDatabase::execute( $physical, $sql[ 'rule_family_guards' ] );
		$migration->up();
		$migration->verify();
		$check( 'NATIVE-C04-DBDELTA-PARTIAL-FIRST-TABLE-RESUMES', null !== OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$changes}`" )
			&& 0 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$records}`" )
			&& 0 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$changes}`" ) );

		OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` DROP INDEX site_family" );
		$migration->up();
		$migration->verify();
		$index = OperationProofDatabase::row( $physical, "SHOW INDEX FROM `{$records}` WHERE Key_name='site_family' AND Seq_in_index=2" );
		$check( 'NATIVE-C04-DBDELTA-NAMED-MISSING-UNIQUE-REPAIRED', is_array( $index ) && 'family_code' === $index['Column_name']
			&& 0 === (int) $index['Non_unique'] && null === $index['Sub_part'] );

		foreach ( [ 'ENGINE', 'COLLATION', 'PREFIX-INDEX', 'UNKNOWN-COLUMN', 'CORRUPT-ROW' ] as $case ) {
			$drop();
			$install();
			if ( 'ENGINE' === $case ) { OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` ENGINE=MyISAM" ); }
			if ( 'COLLATION' === $case ) { OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` DEFAULT CHARACTER SET latin1 COLLATE latin1_bin" ); }
			if ( 'PREFIX-INDEX' === $case ) {
				OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` DROP INDEX site_family, ADD UNIQUE KEY site_family(site_id,family_code(8))" );
			}
			if ( 'UNKNOWN-COLUMN' === $case ) { OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` ADD COLUMN unknown_private longtext NULL" ); }
			// A populated unknown family refuses before DDL, preserving private bytes.
			if ( 'CORRUPT-ROW' === $case ) {
				OperationProofDatabase::execute( $physical, "INSERT INTO `{$records}` (site_id,family_code,family_format,policy_hash,revision,created_at,updated_at) VALUES (1,'unknown_fixture',1,REPEAT('a',64),1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" );
			}
			$before = [ OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$records}`" ), OperationProofDatabase::row( $physical, "SELECT * FROM `{$records}` WHERE id=1" ), OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$changes}`" ) ];
			$ddl_attempts = 0;
			$filter = static function ( string $query ) use ( &$ddl_attempts, $prefix ): string {
				if ( str_contains( $query, $prefix ) && 1 === preg_match( '/\A\s*(?:ALTER|CREATE|DROP)\s/i', $query ) ) { ++$ddl_attempts; }
				return $query;
			};
			add_filter( 'query', $filter );
			$refused = false;
			try { $migration->up(); }
			catch ( RuntimeException $exception ) { $refused = 'Rule lifecycle store could not be verified.' === $exception->getMessage(); }
			finally { remove_filter( 'query', $filter ); $filter = null; }
			$after = [ OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$records}`" ), OperationProofDatabase::row( $physical, "SELECT * FROM `{$records}` WHERE id=1" ), OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$changes}`" ) ];
			$check( 'NATIVE-C04-DBDELTA-' . $case . '-REFUSED-BEFORE-ALTER', $refused && 0 === $ddl_attempts && $before === $after,
				[ 'ddl_attempts' => $ddl_attempts, 'fixture_bytes_preserved' => $before === $after ] );
		}

		// The production default registry rejects all unregistered population.
		// An explicitly registered test family isolates physical row-link validation
		// from that separate unknown-family refusal, using the same native preflight.
		$drop(); $install();
		$family = new RuleProofFamily();
		$scope = [ 'type' => 'global', 'id' => 0, 'parent_id' => 0 ];
		$payload = [ 'availability' => 'allow' ];
		$scope_json = $physical->real_escape_string( $family->scope_schema()->encode( $scope, 4096 ) );
		$payload_json = $physical->real_escape_string( $family->payload_schema()->encode( $payload, 16384 ) );
		$scope_hash = RuleContent::scope_hash( $family, $scope );
		$content_hash = RuleContent::hash( $family, $payload, RuleStartMode::Immediate, null, null, 0, null );
		$policy_hash = $family->policy_hash();
		$now = (string) OperationProofDatabase::scalar( $physical, 'SELECT UTC_TIMESTAMP(6)' );
		OperationProofDatabase::execute( $physical, "INSERT INTO `{$records}` (id,site_id,family_code,family_format,policy_hash,revision,created_at,updated_at) VALUES (1,1,'fixture_availability_v1',1,'{$policy_hash}',2,'{$now}','{$now}')" );
		OperationProofDatabase::execute( $physical, "INSERT INTO `{$changes}` (id,site_id,family_guard_id,logical_uuid,scope_format,scope_json,scope_hash,revision,last_version_sequence,draft_version_id,created_at,updated_at) VALUES (1,1,1,'00000000-0000-4000-8000-000000000001',1,'{$scope_json}','{$scope_hash}',1,1,1,'{$now}','{$now}')" );
		OperationProofDatabase::execute( $physical, "INSERT INTO `{$versions}` (id,site_id,logical_rule_id,version_uuid,version_sequence,row_revision,state,payload_format,payload_json,content_hash,priority,start_mode,author_user_id,change_reason,created_at,updated_at) VALUES (1,1,1,'00000000-0000-4000-8000-000000000101',1,1,'draft',1,'{$payload_json}','{$content_hash}',0,'immediate',9,'fixture','{$now}','{$now}')" );
		$inspection = new RuleLifecycleReadiness( $isolated, new RuleFamilyRegistry( [ $family ] ) );
		$inspection->preflight();
		OperationProofDatabase::execute( $physical, "UPDATE `{$changes}` SET draft_version_id=2 WHERE id=1" );
		$before = [ OperationProofDatabase::row( $physical, "SELECT * FROM `{$changes}` WHERE id=1" ), OperationProofDatabase::row( $physical, "SELECT * FROM `{$versions}` WHERE id=1" ) ];
		$ddl_attempts = 0;
		$filter = static function ( string $query ) use ( &$ddl_attempts, $prefix ): string {
			if ( str_contains( $query, $prefix ) && 1 === preg_match( '/\A\s*(?:ALTER|CREATE|DROP)\s/i', $query ) ) { ++$ddl_attempts; }
			return $query;
		};
		add_filter( 'query', $filter ); $refused = false;
		try { $inspection->preflight(); }
		catch ( RuntimeException $exception ) { $refused = 'Rule lifecycle store could not be verified.' === $exception->getMessage(); }
		finally { remove_filter( 'query', $filter ); $filter = null; }
		$after = [ OperationProofDatabase::row( $physical, "SELECT * FROM `{$changes}` WHERE id=1" ), OperationProofDatabase::row( $physical, "SELECT * FROM `{$versions}` WHERE id=1" ) ];
		$check( 'NATIVE-C04-REGISTERED-ROW-RELATIONSHIP-REFUSED-BEFORE-ALTER', $refused && 0 === $ddl_attempts && $before === $after, [ 'native_preflight' => true, 'registered_family' => true, 'ddl_attempts' => $ddl_attempts, 'fixture_bytes_preserved' => $before === $after ] );
	} finally {
		if ( null !== $filter ) { remove_filter( 'query', $filter ); }
		$wpdb = $original;
		$main_preserved = $before_main === $snapshot_main();
		$order->delete( true ); delete_option( $foreign_option );
		$sentinels_cleaned = false === wc_get_order( $order_id ) && false === get_option( $foreign_option, false );
		foreach ( $owned as $table ) { OperationProofDatabase::execute( $physical, "DROP TABLE IF EXISTS `{$table}`" ); }
		if ( $isolated instanceof wpdb ) { remove_filter( 'query', [ $isolated, 'remove_placeholder_escape' ], 0 ); $isolated->close(); }
		$physical->close();
	}
	$check( 'NATIVE-C04-DBDELTA-LEGACY-SCHEMA-SENTINELS-PRESERVED', $wpdb === $original && $main_preserved,
		[ 'legacy_and_operation_tables_checked' => count( $before_main ) - 3, 'bounded_rows_per_table' => 100, 'foreign_option_and_order_meta_preserved' => $main_preserved, 'schema_options_written' => false ] );
	$remaining = $original->get_var( $original->prepare( 'SHOW TABLES LIKE %s', $original->esc_like( $prefix ) . '%' ) );
	$check( 'NATIVE-C04-DBDELTA-OWNED-TABLES-CLEANED', null === $remaining && '' === $original->last_error && $wpdb === $original && $sentinels_cleaned && false === has_filter( 'query', [ $isolated, 'remove_placeholder_escape' ] ) );
};
