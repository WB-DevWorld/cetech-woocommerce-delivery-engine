<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures;
use CetechDeliveryEngine\Tests\Unit\Operation\OperationMetadataWpdb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Operation/OperationMetadataWpdb.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

final class StorageReadinessTest extends TestCase {
	public function test_runtime_checks_publication_and_structures_without_walking_private_history(): void { $db = new StorageMetadataWpdb(); $db->tables['proof_delivery_engine_delivery_quotes']['rows'] = [ [ 'id' => '1', 'header_json' => 'PRIVATE_UNSUPPORTED_HISTORY' ] ]; self::assertSame( [ 'ready' => true, 'code' => 'ready' ], ( new DeliveryQuoteReadiness( $db ) )->get_status() ); self::assertSame( [], array_values( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'MAX(id)' ) || str_contains( $sql, 'LIMIT 100' ) ) ) ); }
	public function test_migration_verification_requires_no_false_schema9_publication(): void { $db = new StorageMetadataWpdb(); $db->options['cetech_de_db_version'] = '8'; $before = serialize( $db->tables ); $check = new DeliveryQuoteReadiness( $db ); $check->verify(); $check->verify_stored_records(); self::assertSame( $before, serialize( $db->tables ) ); self::assertSame( [ 'ready' => false, 'code' => 'schema_unavailable' ], $check->get_status() ); }
	public function test_default_collation_is_resolved_once_per_probe_and_never_retained_across_calls(): void {
		$db = new StorageMetadataWpdb(); $db->declaration = 'DEFAULT CHARACTER SET utf8mb4'; $check = new DeliveryQuoteReadiness( $db ); $check->verify(); self::assertCount( 1, array_filter( $db->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SHOW CHARACTER SET' ) ) );
		$db->default_collation = 'utf8mb4_general_ci'; try { $check->verify(); self::fail( 'A prior probe cached current collation truth.' ); } catch ( \RuntimeException $exception ) { self::assertSame( 'Delivery quote storage could not be verified.', $exception->getMessage() ); } self::assertCount( 2, array_filter( $db->queries, static fn( string $sql ): bool => str_starts_with( $sql, 'SHOW CHARACTER SET' ) ) );
	}
	public function test_explicit_collation_runtime_probe_has_a_fixed_metadata_query_budget(): void { $db = new StorageMetadataWpdb(); self::assertTrue( ( new DeliveryQuoteReadiness( $db ) )->get_status()['ready'] ); self::assertCount( 31, $db->queries ); }
	public function test_preflight_accepts_absent_or_compatible_empty_partial_units_without_writing(): void { $db = new StorageMetadataWpdb(); unset( $db->tables['proof_delivery_engine_delivery_quote_bindings'] ); unset( $db->tables['proof_delivery_engine_delivery_quotes']['columns']['transition_at'] ); $db->tables['proof_delivery_engine_rate_cards']['indexes'] = array_values( array_filter( $db->tables['proof_delivery_engine_rate_cards']['indexes'], static fn( array $row ): bool => DeliveryQuoteSchema::RATE_INDEX !== $row['Key_name'] ) ); $before = serialize( $db->tables ); ( new DeliveryQuoteReadiness( $db ) )->preflight(); self::assertSame( $before, serialize( $db->tables ) ); $this->expectException( \RuntimeException::class ); ( new DeliveryQuoteReadiness( $db ) )->verify(); }
	public function test_populated_partial_table_refuses_before_any_possible_alter(): void { $db = new StorageMetadataWpdb(); unset( $db->tables['proof_delivery_engine_delivery_quotes']['columns']['transition_at'] ); $db->tables['proof_delivery_engine_delivery_quotes']['rows'] = [ QuoteStorageFixtures::quote()->row() ]; $before = serialize( $db->tables ); try { ( new DeliveryQuoteReadiness( $db ) )->preflight(); self::fail( 'Populated incomplete table accepted.' ); } catch ( \RuntimeException $exception ) { self::assertSame( 'Delivery quote storage could not be verified.', $exception->getMessage() ); } self::assertSame( $before, serialize( $db->tables ) ); }
	#[DataProvider( 'invalid_structures' )]
	public function test_incompatible_structure_is_a_finite_refusal_not_schema_repair( string $case ): void {
		$db = new StorageMetadataWpdb(); $table = 'proof_delivery_engine_delivery_quotes';
		switch ( $case ) {
			case 'engine': $db->tables[$table]['status']['Engine'] = 'MyISAM'; break;
			case 'site_collation': $db->tables[$table]['status']['Collation'] = 'utf8mb4_general_ci'; break;
			case 'hash_collation': $db->tables[$table]['columns']['body_digest']['Collation'] = 'ascii_general_ci'; break;
			case 'hash_width': $db->tables[$table]['columns']['body_digest']['Type'] = 'char(63)'; break;
			case 'revision_width': $db->tables[$table]['columns']['revision']['Type'] = 'int unsigned'; break;
			case 'signed_id': $db->tables[$table]['columns']['site_id']['Type'] = 'bigint'; break;
			case 'nullable_header': $db->tables[$table]['columns']['header_json']['Null'] = 'YES'; break;
			case 'default_version': $db->tables[$table]['columns']['format_version']['Default'] = '2'; break;
			case 'automatic_update': $db->tables[$table]['columns']['created_at']['Extra'] = 'on update current_timestamp()'; break;
			case 'extra_column': $db->tables[$table]['columns']['private_extra'] = array_replace( $db->tables[$table]['columns']['body_digest'], [ 'Field' => 'private_extra' ] ); break;
			case 'prefix_index': $db->tables[$table]['indexes'][2]['Sub_part'] = '8'; break;
			case 'nonunique_identity': $db->tables[$table]['indexes'][1]['Non_unique'] = '1'; break;
			case 'index_trailing_column': $row = end( $db->tables[$table]['indexes'] ); ++$row['Seq_in_index']; $row['Column_name'] = 'state'; $db->tables[$table]['indexes'][] = $row; break;
			case 'index_missing_column': array_pop( $db->tables[$table]['indexes'] ); break;
			case 'fulltext': $db->tables[$table]['indexes'][0]['Index_type'] = 'FULLTEXT'; break;
			case 'descending': $db->tables[$table]['indexes'][0]['Collation'] = 'D'; break;
			case 'ignored': $db->tables[$table]['indexes'][0]['Ignored'] = 'YES'; break;
			case 'invisible': $db->tables[$table]['indexes'][0]['Visible'] = 'NO'; break;
			case 'expression': $db->tables[$table]['indexes'][0]['Expression'] = 'id + 1'; break;
			case 'extra_index': $db->tables[$table]['indexes'][] = array_replace( $db->tables[$table]['indexes'][0], [ 'Key_name' => 'private_hidden_index' ] ); break;
		}
		$before = serialize( $db->tables ); self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new DeliveryQuoteReadiness( $db ) )->get_status() ); self::assertSame( $before, serialize( $db->tables ) ); $this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'Delivery quote storage could not be verified.' ); ( new DeliveryQuoteReadiness( $db ) )->preflight();
	}
	public static function invalid_structures(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'engine', 'site_collation', 'hash_collation', 'hash_width', 'revision_width', 'signed_id', 'nullable_header', 'default_version', 'automatic_update', 'extra_column', 'prefix_index', 'nonunique_identity', 'index_trailing_column', 'index_missing_column', 'fulltext', 'descending', 'ignored', 'invisible', 'expression', 'extra_index' ] ); }
	public function test_legacy_unrelated_price_columns_and_indexes_are_preserved(): void { $db = new StorageMetadataWpdb(); $table = 'proof_delivery_engine_rate_cards'; $db->tables[$table]['columns']['legacy_formula'] = [ 'Field' => 'legacy_formula', 'Type' => 'decimal(19,4)', 'Null' => 'NO', 'Default' => '0.0000', 'Extra' => '', 'Collation' => null ]; $db->tables[$table]['indexes'][] = [ 'Key_name' => 'legacy_formula', 'Seq_in_index' => '1', 'Non_unique' => '1', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => 'legacy_formula' ]; $before = serialize( $db->tables ); ( new DeliveryQuoteReadiness( $db ) )->verify_rate_index(); self::assertSame( $before, serialize( $db->tables ) ); }
	#[DataProvider( 'invalid_rate_structures' )]
	public function test_legacy_range_prerequisites_refuse_missing_or_wrong_identity( string $case ): void { $db = new StorageMetadataWpdb(); $table = 'proof_delivery_engine_rate_cards'; switch ( $case ) { case 'missing_table': unset( $db->tables[$table] ); break; case 'missing_currency': unset( $db->tables[$table]['columns']['base_currency'] ); break; case 'currency_width': $db->tables[$table]['columns']['base_currency']['Type'] = 'varchar(3)'; break; case 'currency_collation': $db->tables[$table]['columns']['base_currency']['Collation'] = 'ascii_bin'; break; case 'currency_default': $db->tables[$table]['columns']['base_currency']['Default'] = null; break; case 'offer_nullable': $db->tables[$table]['columns']['delivery_offer_id']['Null'] = 'YES'; break; case 'range_unique': foreach ( $db->tables[$table]['indexes'] as &$row ) { if ( DeliveryQuoteSchema::RATE_INDEX === $row['Key_name'] ) { $row['Non_unique'] = '0'; } } unset( $row ); break; case 'range_wrong_column': $db->tables[$table]['indexes'][3]['Column_name'] = 'currency_code'; break; } $this->expectException( \RuntimeException::class ); ( new DeliveryQuoteReadiness( $db ) )->preflight(); }
	public static function invalid_rate_structures(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'missing_table', 'missing_currency', 'currency_width', 'currency_collation', 'currency_default', 'offer_nullable', 'range_unique', 'range_wrong_column' ] ); }
	public function test_status_requires_exact_published_schema9_success(): void { $db = new StorageMetadataWpdb(); foreach ( [ 'wrong_migration' => [ 'status' => 'success', 'migration_id' => 'another_migration', 'to_version' => '9' ], 'unconfirmed' => [ 'status' => 'failed', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID, 'to_version' => '9' ], 'old_success' => [ 'status' => 'success', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID, 'to_version' => '8' ] ] as $status ) { $db->options['cetech_de_last_migration_status'] = serialize( $status ); self::assertSame( [ 'ready' => false, 'code' => 'migration_unconfirmed' ], ( new DeliveryQuoteReadiness( $db ) )->get_status() ); } }
	public function test_actual_codec_walk_freezes_all_ceilings_before_pages_and_checks_backlinks(): void {
		$db = new StorageMetadataWpdb(); $parent = QuoteStorageFixtures::quote( state: 'accepted' ); $binding = QuoteStorageFixtures::binding( $parent ); $budget = QuoteStorageFixtures::budget()->row(); $budget['admission_namespace_hash'] = $parent->row()['issue_namespace_hash']; $budget['slot_key'] = QuoteBudgetSlot::admission_slot_key( $budget['admission_namespace_hash'] ); $budget['lease_state'] = 'consumed'; $budget['consumed_quote_uuid'] = $parent->header()->id()->value(); $budget['consumed_at'] = $parent->header()->created_at()->plus_seconds( 1 )->sql(); $budget['last_seen_at'] = $budget['consumed_at']; $budget['revision'] = 2;
		$db->tables['proof_delivery_engine_delivery_quotes']['rows'] = [ $parent->row() ]; $db->tables['proof_delivery_engine_delivery_quote_bindings']['rows'] = [ $binding->row() ]; $db->tables['proof_delivery_engine_delivery_quote_budget_windows']['rows'] = [ $budget ]; $before = serialize( $db->tables ); ( new DeliveryQuoteReadiness( $db ) )->verify_stored_records(); self::assertSame( $before, serialize( $db->tables ) );
		$ceilings = array_keys( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'MAX(id)' ) ) ); $pages = array_keys( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'LIMIT 100' ) ) ); self::assertCount( 3, $ceilings ); self::assertCount( 3, $pages ); self::assertLessThan( min( $pages ), max( $ceilings ) ); self::assertTrue( count( array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'CASE WHEN OCTET_LENGTH' ) && str_contains( $sql, 'LIMIT 100' ) ) ) > 0 );
	}
	#[DataProvider( 'corrupt_rows' )]
	public function test_unknown_corrupt_or_orphaned_rows_refuse_without_disclosing_or_rewriting( string $case ): void {
		$db = new StorageMetadataWpdb(); $parent = QuoteStorageFixtures::quote( state: 'accepted' ); $db->tables['proof_delivery_engine_delivery_quotes']['rows'] = [ $parent->row() ];
		if ( 'orphan_binding' === $case ) { $binding = QuoteStorageFixtures::binding( $parent )->row(); $binding['quote_uuid'] = '550e8400-e29b-41d4-a716-446655440000'; $db->tables['proof_delivery_engine_delivery_quote_bindings']['rows'] = [ $binding ]; }
		elseif ( 'orphan_consumed' === $case ) { $budget = QuoteStorageFixtures::budget()->row(); $budget['lease_state'] = 'consumed'; $budget['consumed_quote_uuid'] = '550e8400-e29b-41d4-a716-446655440000'; $budget['consumed_at'] = $parent->accepted_at()->sql(); $budget['last_seen_at'] = $budget['consumed_at']; $budget['revision'] = 2; $db->tables['proof_delivery_engine_delivery_quote_budget_windows']['rows'] = [ $budget ]; }
		else { $row =& $db->tables['proof_delivery_engine_delivery_quotes']['rows'][0]; switch ( $case ) { case 'wrong_site': $row['site_id'] = '2'; break; case 'unknown_profile': $row['profile_code'] = 'unknown_profile'; break; case 'bad_scalar_digest': $row['body_digest'] = str_repeat( '0', 64 ); break; case 'oversize_header': $row['header_json'] = str_repeat( 'P', 4097 ); break; case 'oversize_body': $row['private_body_json'] = str_repeat( 'P', 65537 ); break; case 'extra_field': $row['secret'] = 'PRIVATE_ROW_SENTINEL'; break; } unset( $row ); }
		$before = serialize( $db->tables ); try { ( new DeliveryQuoteReadiness( $db ) )->verify_stored_records(); self::fail( 'Invalid retained facts accepted.' ); } catch ( \RuntimeException $exception ) { self::assertSame( 'Delivery quote storage could not be verified.', $exception->getMessage() ); self::assertStringNotContainsString( 'PRIVATE', $exception->getMessage() ); } self::assertSame( $before, serialize( $db->tables ) );
	}
	public static function corrupt_rows(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'wrong_site', 'unknown_profile', 'bad_scalar_digest', 'oversize_header', 'oversize_body', 'extra_field', 'orphan_binding', 'orphan_consumed' ] ); }
	public function test_query_failure_is_not_absence_and_private_error_is_suppressed(): void { $db = new StorageMetadataWpdb(); $db->query_failure = true; self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new DeliveryQuoteReadiness( $db ) )->get_status() ); $this->expectException( \RuntimeException::class ); $this->expectExceptionMessage( 'Delivery quote storage could not be verified.' ); ( new DeliveryQuoteReadiness( $db ) )->preflight(); }
	public function test_explicit_owner_probe_releases_only_its_read_unit_and_uncertain_rollback_retires(): void { $session = new StorageMetadataSession( new StorageMetadataWpdb() ); self::assertTrue( ( new DeliveryQuoteReadiness( $session ) )->get_status()['ready'] ); self::assertSame( [ 1, 1, 0, false ], [ $session->begins, $session->rollbacks, $session->commits, $session->in_transaction() ] ); $session->begin(); ( new DeliveryQuoteReadiness( $session ) )->verify(); self::assertSame( [ 2, 1, true ], [ $session->begins, $session->rollbacks, $session->in_transaction() ] ); $session->rollback(); $session->rollback_allowed = false; self::assertSame( [ 'ready' => false, 'code' => 'store_unverified' ], ( new DeliveryQuoteReadiness( $session ) )->get_status() ); self::assertTrue( $session->is_retired() ); self::assertSame( 0, $session->commits ); }
}

/** Metadata-only boundary double. It neither executes DDL nor proves SQL durability. */
final class StorageMetadataWpdb {
	public string $prefix = 'proof_'; public string $last_error = ''; public array $tables; public array $options; public array $queries; public bool $query_failure = false; public string $declaration = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; public string $default_collation = 'utf8mb4_unicode_ci';
	private OperationMetadataWpdb $base;
	public function __construct() {
		$this->base = new OperationMetadataWpdb(); $this->tables =& $this->base->tables; $this->options =& $this->base->options; $this->queries =& $this->base->queries; $this->last_error =& $this->base->last_error; $this->query_failure =& $this->base->query_failure;
		$this->options = [ 'cetech_de_db_version' => '9', 'cetech_de_last_migration_status' => serialize( [ 'status' => 'success', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID, 'from_version' => '8', 'to_version' => '9' ] ) ];
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { $this->add_table( $suffix, RuleLifecycleSchema::columns( $suffix ), RuleLifecycleSchema::indexes( $suffix ) ); }
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) { $this->add_table( $suffix, DeliveryQuoteSchema::columns( $suffix ), DeliveryQuoteSchema::indexes( $suffix ) ); }
		$this->add_table( 'rate_cards', DeliveryQuoteSchema::rate_columns(), [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ], DeliveryQuoteSchema::RATE_INDEX => DeliveryQuoteSchema::rate_index() ] );
	}
	private function add_table( string $suffix, array $definitions, array $index_definitions ): void {
		$columns = []; foreach ( $definitions as $name => [ $type, $nullable, $default, $extra, $purpose ] ) { $columns[$name] = [ 'Field' => $name, 'Type' => preg_replace( '/\A(bigint|smallint|int)( unsigned)?\z/', '$1(20)$2', $type ), 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $purpose ? 'utf8mb4_unicode_ci' : $purpose ]; }
		$indexes = []; foreach ( $index_definitions as $name => $definition ) { foreach ( $definition['columns'] as $at => $column ) { $indexes[] = [ 'Key_name' => $name, 'Seq_in_index' => (string) ( $at + 1 ), 'Column_name' => $column, 'Non_unique' => $definition['unique'] ? '0' : '1', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Ignored' => 'NO' ]; } }
		$table = $this->prefix . 'delivery_engine_' . $suffix; $this->tables[$table] = [ 'status' => [ 'Name' => $table, 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ], 'columns' => $columns, 'indexes' => $indexes, 'rows' => [] ];
	}
	public function get_charset_collate(): string { return $this->declaration; }
	public function prepare( string $sql, mixed ...$args ): string { return $this->base->prepare( $sql, ...$args ); }
	public function get_row( string $sql, mixed $format = null ): ?array { return $this->get_results( $sql, $format )[0] ?? null; }
	public function get_results( string $sql, mixed $format = null ): array {
		if ( $this->query_failure ) { return $this->base->get_results( $sql, $format ); }
		if ( str_starts_with( $sql, 'SHOW CHARACTER SET' ) ) { $this->queries[] = $sql; $this->last_error = ''; return [ [ 'Default collation' => $this->default_collation ] ]; }
		if ( preg_match( "/FROM `proof_options` WHERE option_name = '([^']+)'/", $sql, $match ) ) { $this->queries[] = $sql; $this->last_error = ''; $value = $this->options[$match[1]] ?? null; return null === $value ? [] : [ [ 'option_value' => strlen( $value ) <= 16384 ? $value : null ] ]; }
		if ( str_contains( $sql, 'ORDER BY id ASC LIMIT 100' ) || str_contains( $sql, 'quote_uuid =' ) ) {
			$this->queries[] = $sql; $this->last_error = ''; preg_match( '/FROM `([^`]+)`/', $sql, $table_match ); $rows = $this->tables[$table_match[1]]['rows'];
			if ( preg_match( '/WHERE id > ([0-9]+) AND id <= ([0-9]+)/', $sql, $match ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['id'] > (int) $match[1] && (int) $row['id'] <= (int) $match[2] ) ); }
			if ( preg_match( "/WHERE site_id = ([0-9]+) AND quote_uuid = '([^']+)' AND id <= ([0-9]+)/", $sql, $match ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) $row['site_id'] === (int) $match[1] && $row['quote_uuid'] === $match[2] && (int) $row['id'] <= (int) $match[3] ) ); }
			usort( $rows, static fn( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] ); $rows = array_slice( $rows, 0, str_contains( $sql, 'LIMIT 2' ) ? 2 : 100 );
			foreach ( $rows as &$row ) { foreach ( [ 'header_json' => 4096, 'private_body_json' => 65536, 'mapping_json' => 65536 ] as $field => $limit ) { if ( str_contains( $sql, '__' . $field . '_bytes' ) ) { $bytes = null === $row[$field] ? null : strlen( $row[$field] ); $row['__' . $field . '_bytes'] = null === $bytes ? null : (string) $bytes; if ( null !== $bytes && $bytes > $limit ) { $row[$field] = null; } } } } unset( $row ); return $rows;
		}
		return $this->base->get_results( $sql, $format );
	}
}

/** Owned metadata probe only; native transaction/isolation proof belongs to SQL. */
final class StorageMetadataSession implements OperationSession {
	public int $begins = 0; public int $rollbacks = 0; public int $commits = 0; public bool $rollback_allowed = true; private bool $active = false; private bool $retired = false;
	public function __construct( private StorageMetadataWpdb $db ) {}
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
