<?php

declare(strict_types=1);

use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;

/** Actual native dbDelta against fixture-owned tables; no schema option writes. */
return static function ( callable $check ): void {
	global $wpdb;
	if ( '1' !== (string) getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' )
		|| ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN
		|| '1' !== (string) get_option( 'cetech_opening_qualification_disposable' )
		|| ! $wpdb instanceof wpdb
	) {
		throw new RuntimeException( 'Operation migration qualification requires the native disposable fixture.' );
	}
	foreach ( get_included_files() as $included ) {
		if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) {
			throw new RuntimeException( 'Unit bootstrap is forbidden in native migration qualification.' );
		}
	}
	$root = dirname( __DIR__, 2 );
	require_once $root . '/tests/Support/Operation/OperationProofDatabase.php';
	$original = $wpdb;
	$prefix = OperationProofDatabase::prefix();
	OperationProofDatabase::validate_prefix( $prefix );
	$owned = [];
	foreach ( OperationStoreSchema::SUFFIXES as $suffix ) { $owned[] = $prefix . 'delivery_engine_' . $suffix; }
	$host = $original->parse_db_host( DB_HOST );
	if ( ! is_array( $host ) ) { throw new RuntimeException( 'Native migration database configuration is unavailable.' ); }
	$physical = new mysqli( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2] );
	if ( 0 !== $physical->connect_errno || ! $physical->set_charset( 'utf8mb4' ) ) {
		throw new RuntimeException( 'Native migration fixture connection is unavailable.' );
	}
	// All legacy tables are bounded fixture data. Keep bytes private and compare
	// them in-process, alongside the original schema/status option rows.
	$snapshot_main = static function () use ( $original ): array {
		$snapshot = [];
		foreach ( ConfigurationTables::all() as $table ) {
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
		return $snapshot;
	};
	$before_main = $snapshot_main();
	$isolated = null;
	$filter = null;
	try {
		$isolated = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$isolated->set_prefix( $prefix );
		$isolated->suppress_errors( true );
		$wpdb = $isolated;
		$sql = OperationStoreSchema::create_table_statements( $isolated->get_charset_collate(), $prefix . 'delivery_engine_' );
		$migration = require $root . '/database/migrations/20261006191156_create_operation_tables.php';
		$drop = static function () use ( $physical, $owned ): void {
			foreach ( $owned as $table ) { OperationProofDatabase::execute( $physical, "DROP TABLE IF EXISTS `{$table}`" ); }
		};
		$install = static function () use ( $physical, $sql ): void {
			foreach ( $sql as $statement ) { OperationProofDatabase::execute( $physical, $statement ); }
		};
		$records = $owned[0];
		$changes = $owned[1];
		OperationProofDatabase::execute( $physical, $sql[ OperationStoreSchema::RECORDS_SUFFIX ] );
		$migration->up();
		$migration->verify();
		$check( 'NATIVE-C03-DBDELTA-PARTIAL-FIRST-TABLE-RESUMES', null !== OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$changes}`" )
			&& 0 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$records}`" )
			&& 0 === (int) OperationProofDatabase::scalar( $physical, "SELECT COUNT(*) FROM `{$changes}`" ) );

		OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` DROP INDEX site_namespace" );
		$migration->up();
		$migration->verify();
		$index = OperationProofDatabase::row( $physical, "SHOW INDEX FROM `{$records}` WHERE Key_name='site_namespace' AND Seq_in_index=2" );
		$check( 'NATIVE-C03-DBDELTA-NAMED-MISSING-UNIQUE-REPAIRED', is_array( $index ) && 'namespace_hash' === $index['Column_name']
			&& 0 === (int) $index['Non_unique'] && null === $index['Sub_part'] );

		foreach ( [ 'ENGINE', 'COLLATION', 'UNKNOWN-COLUMN', 'CORRUPT-ROW' ] as $case ) {
			$drop();
			$install();
			if ( 'ENGINE' === $case ) { OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` ENGINE=MyISAM" ); }
			if ( 'COLLATION' === $case ) { OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` DEFAULT CHARACTER SET latin1 COLLATE latin1_bin" ); }
			if ( 'UNKNOWN-COLUMN' === $case ) { OperationProofDatabase::execute( $physical, "ALTER TABLE `{$records}` ADD COLUMN unknown_private longtext NULL" ); }
			$invalid_state = 'CORRUPT-ROW' === $case ? 'unknown_outcome' : 'pending';
			OperationProofDatabase::execute( $physical, "INSERT INTO `{$records}` (site_id,namespace_hash,intent_hash,operation,operation_version,target_hash,state,publication_state,completion_json,row_version,created_at,updated_at) VALUES (1,REPEAT('a',64),REPEAT('b',64),'unknown.fixture',1,REPEAT('c',64),'{$invalid_state}','none','PRIVATE-MIGRATION-SENTINEL',1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))" );
			$before = [ OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$records}`" ), OperationProofDatabase::row( $physical, "SELECT * FROM `{$records}` WHERE id=1" ), OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$changes}`" ) ];
			$ddl_attempts = 0;
			$filter = static function ( string $query ) use ( &$ddl_attempts, $prefix ): string {
				if ( str_contains( $query, $prefix ) && 1 === preg_match( '/\A\s*(?:ALTER|CREATE|DROP)\s/i', $query ) ) { ++$ddl_attempts; }
				return $query;
			};
			add_filter( 'query', $filter );
			$refused = false;
			try { $migration->up(); }
			catch ( RuntimeException $exception ) { $refused = 'Operation store could not be verified.' === $exception->getMessage(); }
			finally { remove_filter( 'query', $filter ); $filter = null; }
			$after = [ OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$records}`" ), OperationProofDatabase::row( $physical, "SELECT * FROM `{$records}` WHERE id=1" ), OperationProofDatabase::row( $physical, "SHOW CREATE TABLE `{$changes}`" ) ];
			$check( 'NATIVE-C03-DBDELTA-' . $case . '-REFUSED-BEFORE-ALTER', $refused && 0 === $ddl_attempts && $before === $after,
				[ 'ddl_attempts' => $ddl_attempts, 'fixture_bytes_preserved' => $before === $after ] );
		}
	} finally {
		if ( null !== $filter ) { remove_filter( 'query', $filter ); }
		$wpdb = $original;
		foreach ( $owned as $table ) { OperationProofDatabase::execute( $physical, "DROP TABLE IF EXISTS `{$table}`" ); }
		if ( $isolated instanceof wpdb ) { remove_filter( 'query', [ $isolated, 'remove_placeholder_escape' ], 0 ); $isolated->close(); }
		$physical->close();
	}
	$check( 'NATIVE-C03-DBDELTA-LEGACY-SCHEMA-SENTINELS-PRESERVED', $wpdb === $original && $before_main === $snapshot_main(),
		[ 'legacy_tables_checked' => count( $before_main ) - 1, 'bounded_rows_per_table' => 100, 'schema_options_written' => false ] );
	$remaining = $original->get_var( $original->prepare( 'SHOW TABLES LIKE %s', $original->esc_like( $prefix ) . '%' ) );
	$check( 'NATIVE-C03-DBDELTA-OWNED-TABLES-CLEANED', null === $remaining && '' === $original->last_error && $wpdb === $original && false === has_filter( 'query', [ $isolated, 'remove_placeholder_escape' ] ) );
};
