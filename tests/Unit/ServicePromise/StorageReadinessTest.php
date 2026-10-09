<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\PromiseStorageSchema;
use CetechDeliveryEngine\Tests\Unit\DeliveryQuote\StorageMetadataWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/DeliveryQuote/StorageReadinessTest.php';
require_once __DIR__ . '/PersistenceFixtures.php';

final class StorageReadinessTest extends TestCase {
	public function test_runtime_checks_structures_and_acknowledged_schema_without_history_scan(): void {
		$db = new PromiseMetadataWpdb(); $db->tables['proof_delivery_engine_promise_versions']['rows'] = [ [ 'id' => 1, 'body_json' => 'PRIVATE_UNSUPPORTED_HISTORY' ] ];
		self::assertSame( [ 'ready' => true, 'code' => 'ready' ], ( new PromiseStorageReadiness( $db ) )->get_status() ); self::assertSame( [], array_values( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'MAX(id)' ) || str_contains( $sql, 'LIMIT 100' ) ) ) );
	}
	public function test_preflight_accepts_absent_and_empty_compatible_partial_units_without_mutations(): void {
		$db = new PromiseMetadataWpdb(); unset( $db->tables['proof_delivery_engine_promise_assignments'] ); unset( $db->tables['proof_delivery_engine_promise_versions']['columns']['retired_at'] ); $before = serialize( $db->tables );
		( new PromiseStorageReadiness( $db ) )->preflight(); self::assertSame( $before, serialize( $db->tables ) ); $this->expectException( \RuntimeException::class ); ( new PromiseStorageReadiness( $db ) )->verify();
	}
	public function test_populated_partial_unit_refuses_before_any_ddl_or_private_row_transport(): void {
		$db = new PromiseMetadataWpdb(); unset( $db->tables['proof_delivery_engine_promise_assignments']['columns']['generation'] ); $db->tables['proof_delivery_engine_promise_assignments']['rows'] = [ PersistenceFixtures::assignment() ]; $before = serialize( $db->tables );
		try { ( new PromiseStorageReadiness( $db ) )->preflight(); self::fail( 'Populated partial unit accepted.' ); } catch ( \RuntimeException $e ) { self::assertSame( 'Promise storage could not be verified.', $e->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) ); self::assertSame( [], array_values( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'LIMIT 100' ) ) ) );
	}
	#[DataProvider( 'invalid_structures' )]
	public function test_incompatible_owned_structure_is_not_repaired_or_exposed( string $case ): void {
		$db = new PromiseMetadataWpdb(); $table = 'proof_delivery_engine_promise_versions';
		switch ( $case ) {
			case 'engine': $db->tables[$table]['status']['Engine'] = 'MyISAM'; break;
			case 'collation': $db->tables[$table]['status']['Collation'] = 'utf8mb4_general_ci'; break;
			case 'opaque_collation': $db->tables[$table]['columns']['site_key']['Collation'] = 'ascii_general_ci'; break;
			case 'opaque_width': $db->tables[$table]['columns']['site_key']['Type'] = 'varchar(96)'; break;
			case 'signed_native': $db->tables[$table]['columns']['site_id']['Type'] = 'bigint'; break;
			case 'format': $db->tables[$table]['columns']['format_version']['Default'] = '2'; break;
			case 'nullable_body': $db->tables[$table]['columns']['body_json']['Null'] = 'YES'; break;
			case 'implicit_time': $db->tables[$table]['columns']['published_at']['Extra'] = 'on update current_timestamp()'; break;
			case 'extra_column': $db->tables[$table]['columns']['secret'] = array_replace( $db->tables[$table]['columns']['body_digest'], [ 'Field' => 'secret' ] ); break;
			case 'index_prefix': $db->tables[$table]['indexes'][1]['Sub_part'] = '8'; break;
			case 'index_nonunique': $db->tables[$table]['indexes'][1]['Non_unique'] = '1'; break;
			case 'index_direction': $db->tables[$table]['indexes'][1]['Collation'] = 'D'; break;
			case 'index_invisible': $db->tables[$table]['indexes'][1]['Visible'] = 'NO'; break;
			case 'index_ignored': $db->tables[$table]['indexes'][1]['Ignored'] = 'YES'; break;
			case 'index_expression': $db->tables[$table]['indexes'][1]['Expression'] = 'id + 1'; break;
			case 'index_extra': $db->tables[$table]['indexes'][] = array_replace( $db->tables[$table]['indexes'][0], [ 'Key_name' => 'unknown' ] ); break;
			case 'index_missing': array_pop( $db->tables[$table]['indexes'] ); break;
		}
		$before = serialize( $db->tables ); self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new PromiseStorageReadiness( $db ) )->get_status() ); self::assertSame( $before, serialize( $db->tables ) );
		$this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'Promise storage could not be verified.' ); ( new PromiseStorageReadiness( $db ) )->preflight();
	}
	public static function invalid_structures(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'engine', 'collation', 'opaque_collation', 'opaque_width', 'signed_native', 'format', 'nullable_body', 'implicit_time', 'extra_column', 'index_prefix', 'index_nonunique', 'index_direction', 'index_invisible', 'index_ignored', 'index_expression', 'index_extra', 'index_missing' ] ); }
	public function test_schema9_or_unacknowledged_schema10_cannot_activate_persistence(): void {
		$db = new PromiseMetadataWpdb(); $db->options['cetech_de_db_version'] = '9'; self::assertSame( [ 'ready' => false, 'code' => 'schema_unavailable' ], ( new PromiseStorageReadiness( $db ) )->get_status() );
		$db->options['cetech_de_db_version'] = '10'; foreach ( [ [ 'status' => 'failed', 'migration_id' => PromiseStorageReadiness::MIGRATION_ID, 'to_version' => '10' ], [ 'status' => 'success', 'migration_id' => 'other', 'to_version' => '10' ], [ 'status' => 'success', 'migration_id' => PromiseStorageReadiness::MIGRATION_ID, 'to_version' => '9' ] ] as $status ) { $db->options['cetech_de_last_migration_status'] = serialize( $status ); self::assertSame( [ 'ready' => false, 'code' => 'migration_unconfirmed' ], ( new PromiseStorageReadiness( $db ) )->get_status() ); }
	}
	public function test_inherited_quote_structure_failure_keeps_new_persistence_fail_closed(): void { $db = new PromiseMetadataWpdb(); $db->tables['proof_delivery_engine_delivery_quotes']['status']['Engine'] = 'MyISAM'; self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new PromiseStorageReadiness( $db ) )->get_status() ); }
	public function test_fixed_ceilings_are_captured_before_bounded_payload_pages_and_orphans_refuse(): void {
		$db = new PromiseMetadataWpdb(); $db->tables['proof_delivery_engine_promise_versions']['rows'] = [ PersistenceFixtures::version() ]; $before = serialize( $db->tables );
		try { ( new PromiseStorageReadiness( $db ) )->verify_stored_records(); self::fail( 'Orphan version accepted.' ); } catch ( \RuntimeException $e ) { self::assertSame( 'Promise storage could not be verified.', $e->getMessage() ); }
		self::assertSame( $before, serialize( $db->tables ) ); $ceilings = array_keys( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'MAX(id)' ) ) ); $pages = array_keys( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'LIMIT 100' ) ) ); self::assertCount( 3, $ceilings ); self::assertNotEmpty( $pages ); self::assertLessThan( min( $pages ), max( $ceilings ) ); self::assertStringContainsString( 'CASE WHEN OCTET_LENGTH(`body_json`) <= 32768', $db->queries[min( $pages )] );
	}
	public function test_unacknowledged_body_markers_and_unaccepted_assignment_baselines_refuse_migration(): void {
		$db = new PromiseMetadataWpdb(); $db->tables['proof_delivery_engine_promise_objects']['rows'] = [ PersistenceFixtures::object() ]; $db->tables['proof_delivery_engine_promise_versions']['rows'] = [ PersistenceFixtures::version() ];
		try { ( new PromiseStorageReadiness( $db ) )->verify_stored_records(); self::fail( 'Typed rows without physical original C03 acknowledged records accepted.' ); } catch ( \RuntimeException $e ) { self::assertSame( 'Promise storage could not be verified.', $e->getMessage() ); }
		$db = new PromiseMetadataWpdb(); $db->tables['proof_delivery_engine_promise_assignments']['rows'] = [ PersistenceFixtures::assignment( baseline: true ) ]; $this->expectException( \RuntimeException::class ); ( new PromiseStorageReadiness( $db ) )->verify_stored_records();
	}
	public function test_query_error_is_not_absence_and_uncertain_owned_rollback_retires_without_commit(): void {
		$db = new PromiseMetadataWpdb(); $session = new PromiseMetadataSession( $db ); self::assertTrue( ( new PromiseStorageReadiness( $session ) )->get_status()['ready'] ); self::assertSame( [ 1, 1, 0 ], [ $session->begins, $session->rollbacks, $session->commits ] );
		$session->rollback_allowed = false; self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new PromiseStorageReadiness( $session ) )->get_status() ); self::assertTrue( $session->is_retired() ); self::assertSame( 0, $session->commits );
		$db->query_failure = true; self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new PromiseStorageReadiness( $db ) )->get_status() );
	}
	public function test_verifiable_migration_does_not_write_version_or_prior_history_and_preflights_every_unit(): void {
		$migration = require dirname( __DIR__, 3 ) . '/database/migrations/20261009091124_create_promise_storage_tables.php'; self::assertInstanceOf( VerifiableMigrationInterface::class, $migration ); self::assertSame( PromiseStorageReadiness::MIGRATION_ID, $migration->get_id() ); self::assertSame( '10', $migration->get_version() );
		$previous = $GLOBALS['wpdb'] ?? null; $db = new PromiseMetadataWpdb(); $db->options['cetech_de_db_version'] = '9'; $before = serialize( [ $db->tables, $db->options ] ); $GLOBALS['wpdb'] = $db;
		try { $migration->verify(); self::assertSame( $before, serialize( [ $db->tables, $db->options ] ) ); $db->tables['proof_delivery_engine_promise_assignments']['status']['Engine'] = 'MyISAM'; $before = serialize( [ $db->tables, $db->options ] ); try { $migration->up(); self::fail( 'Later incompatible unit reached upgrade load/DDL.' ); } catch ( \RuntimeException $e ) { self::assertSame( 'Promise storage could not be verified.', $e->getMessage() ); } self::assertSame( $before, serialize( [ $db->tables, $db->options ] ) ); }
		finally { $GLOBALS['wpdb'] = $previous; }
	}
}

/** Metadata boundary double only. It does not execute DDL, acknowledge commits or prove durability. */
final class PromiseMetadataWpdb {
	public string $prefix = 'proof_'; public string $last_error = ''; public array $tables; public array $options; public array $queries; public bool $query_failure = false; private StorageMetadataWpdb $base;
	public function __construct() {
		$this->base = new StorageMetadataWpdb(); $this->tables =& $this->base->tables; $this->options =& $this->base->options; $this->queries =& $this->base->queries; $this->last_error =& $this->base->last_error; $this->query_failure =& $this->base->query_failure;
		$this->options = [ 'cetech_de_db_version' => '10', 'cetech_de_last_migration_status' => serialize( [ 'status' => 'success', 'migration_id' => PromiseStorageReadiness::MIGRATION_ID, 'from_version' => '9', 'to_version' => '10' ] ) ];
		foreach ( PromiseStorageSchema::SUFFIXES as $suffix ) {
			$columns = []; foreach ( PromiseStorageSchema::columns( $suffix ) as $name => [ $type, $nullable, $default, $extra, $purpose ] ) { $columns[$name] = [ 'Field' => $name, 'Type' => $type, 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $purpose ? 'utf8mb4_unicode_ci' : $purpose ]; }
			$indexes = []; foreach ( PromiseStorageSchema::indexes( $suffix ) as $name => $definition ) { foreach ( $definition['columns'] as $i => $column ) { $indexes[] = [ 'Key_name' => $name, 'Seq_in_index' => (string) ( $i + 1 ), 'Column_name' => $column, 'Non_unique' => $definition['unique'] ? '0' : '1', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Ignored' => 'NO' ]; } }
			$table = $this->prefix . 'delivery_engine_' . $suffix; $this->tables[$table] = [ 'status' => [ 'Name' => $table, 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ], 'columns' => $columns, 'indexes' => $indexes, 'rows' => [] ];
		}
	}
	public function get_charset_collate(): string { return $this->base->get_charset_collate(); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->base->prepare( $sql, ...$args ); }
	public function get_row( string $sql, mixed $format = null ): ?array { return $this->get_results( $sql, $format )[0] ?? null; }
	public function get_results( string $sql, mixed $format = null ): array {
		if ( ! $this->query_failure && preg_match( '/SELECT COUNT\(\*\) AS version_count,MAX\(domain_version\) AS max_sequence FROM `proof_delivery_engine_promise_versions` WHERE site_id = ([0-9]+) AND site_key = \'([^\']+)\' AND object_id = ([0-9]+) AND id <= ([0-9]+)/', $sql, $match ) ) {
			$this->queries[] = $sql; $this->last_error = ''; $rows = array_values( array_filter( $this->tables['proof_delivery_engine_promise_versions']['rows'], static fn( array $row ): bool => (int) $row['site_id'] === (int) $match[1] && $row['site_key'] === $match[2] && (int) $row['object_id'] === (int) $match[3] && (int) $row['id'] <= (int) $match[4] ) ); return [ [ 'version_count' => (string) count( $rows ), 'max_sequence' => [] === $rows ? null : (string) max( array_column( $rows, 'domain_version' ) ) ] ];
		}
		if ( ! $this->query_failure && str_starts_with( $sql, 'SELECT ' ) && str_contains( $sql, 'OCTET_LENGTH' ) && preg_match( '/FROM `proof_delivery_engine_(promise_[a-z]+)`/', $sql, $match ) ) {
			$this->queries[] = $sql; $this->last_error = ''; $suffix = $match[1]; $rows = $this->tables['proof_delivery_engine_' . $suffix]['rows'];
			foreach ( [ 'site_id', 'object_id', 'domain_version' ] as $field ) { if ( preg_match( '/\\b' . $field . ' = ([0-9]+)/', $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) ( $row[$field] ?? 0 ) === (int) $m[1] ) ); } }
			if ( preg_match( "/site_key = '([^']+)'/", $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => ( $row['site_key'] ?? null ) === $m[1] ) ); }
			if ( preg_match( '/(?:WHERE|AND) id = ([0-9]+)/', $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['id'] === (int) $m[1] ) ); }
			if ( preg_match( '/id > ([0-9]+) AND id <= ([0-9]+)/', $sql, $m ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['id'] > (int) $m[1] && (int) $row['id'] <= (int) $m[2] ) ); }
			usort( $rows, static fn( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] ); $rows = array_slice( $rows, 0, str_contains( $sql, 'LIMIT 2' ) ? 2 : 100 );
			foreach ( $rows as &$row ) { foreach ( PromiseStorageSchema::payload_limits( $suffix ) as $field => $limit ) { $bytes = null === $row[$field] ? null : strlen( $row[$field] ); $row['__' . $field . '_bytes'] = null === $bytes ? null : (string) $bytes; if ( null !== $bytes && $bytes > $limit ) { $row[$field] = null; } } } unset( $row ); return $rows;
		}
		return $this->base->get_results( $sql, $format );
	}
}

/** Owned metadata probe only; no commit is possible. */
final class PromiseMetadataSession implements OperationSession {
	public int $begins = 0; public int $rollbacks = 0; public int $commits = 0; public bool $rollback_allowed = true; private bool $active = false; private bool $retired = false;
	public function __construct( private PromiseMetadataWpdb $db ) {}
	public function site_id(): int { return 1; } public function table_prefix(): string { return $this->db->prefix; } public function charset_collate(): string { return $this->db->get_charset_collate(); }
	public function begin(): bool { ++$this->begins; if ( $this->active || $this->retired ) { return false; } return $this->active = true; }
	public function commit(): OperationCommitResult { ++$this->commits; return OperationCommitResult::NotSent; }
	public function rollback(): bool { ++$this->rollbacks; if ( ! $this->rollback_allowed ) { return false; } $this->active = false; return true; }
	public function retire(): bool { $this->retired = true; $this->active = false; return true; } public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return $this->active; }
	public function validate_tables( array $table_names ): bool { return true; } public function query( string $sql ): int|false { return false; }
	public function get_row( string $sql ): array|null|false { return $this->active && ! $this->retired ? $this->db->get_row( $sql ) : false; }
	public function get_results( string $sql ): array|false { return $this->active && ! $this->retired ? $this->db->get_results( $sql ) : false; }
	public function prepare( string $sql, mixed ...$args ): string { return $this->db->prepare( $sql, ...$args ); } public function errno(): int { return '' === $this->db->last_error ? 0 : 1; } public function insert_id(): int { return 0; }
}
