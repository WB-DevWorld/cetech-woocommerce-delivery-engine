<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteStorageFixtures;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

final class StorageRepositoryTest extends TestCase {
	private StorageUnitSession $session;
	private DeliveryQuoteRepository $repository;
	protected function setUp(): void { $this->session = new StorageUnitSession(); $this->repository = new DeliveryQuoteRepository( $this->session ); }
	protected function tearDown(): void { $this->session->retire(); }

	public function test_unowned_retired_and_cross_site_writes_dispatch_no_statement(): void {
		$quote = QuoteStorageFixtures::quote(); foreach ( [ false, true ] as $retired ) { if ( $retired ) { $this->session->retire(); } try { $this->repository->insert_quote( $quote ); self::fail( 'Unowned insert ran.' ); } catch ( OperationStorageException ) { self::assertSame( 0, count( $this->session->statements ) ); } }
		$this->session = new StorageUnitSession(); $this->repository = new DeliveryQuoteRepository( $this->session ); $this->session->begin();
		try { $this->repository->insert_quote( QuoteStorageFixtures::quote( site: 2 ) ); self::fail( 'Cross-site insert ran.' ); } catch ( OperationStorageException ) { self::assertSame( 0, count( $this->session->statements ) ); }
	}
	public function test_physical_readiness_refuses_before_mutation(): void {
		$this->session->begin(); $this->session->schema_version = '8';
		try { $this->repository->insert_quote( QuoteStorageFixtures::quote() ); self::fail( 'Schema8 accepted quote writes.' ); } catch ( OperationStorageException $error ) { self::assertNull( $error->getPrevious() ); self::assertSame( 0, $this->session->writes ); }
		$this->session->schema_version = '9'; $this->session->wrong_engine = true;
		try { $this->repository->insert_quote( QuoteStorageFixtures::quote() ); self::fail( 'Wrong physical engine accepted.' ); } catch ( OperationStorageException ) { self::assertSame( 0, $this->session->writes ); }
	}
	public function test_insert_lookup_and_atomic_cas_preserve_exact_original_facts(): void {
		$this->session->begin(); $quote = QuoteStorageFixtures::quote(); $id = $this->repository->insert_quote( $quote ); self::assertSame( 1, $id ); $stored = $this->repository->find_quote( $quote->header()->id(), true ); self::assertSame( $quote->row(), $stored->row() );
		$accepted = $this->accepted( $stored ); self::assertTrue( $this->repository->replace_quote( $stored, $accepted ) ); self::assertFalse( $this->repository->replace_quote( $stored, $accepted ) );
		$current = $this->repository->find_quote( $quote->header()->id() ); self::assertSame( 'accepted', $current->state() ); self::assertSame( $quote->header()->to_private_json(), $current->header()->to_private_json() ); self::assertSame( $quote->row()['private_body_json'], $current->row()['private_body_json'] ); self::assertSame( 1, $this->session->begins ); self::assertSame( 0, $this->session->commits );
	}
	public function test_cas_rejects_another_valid_header_and_native_changed_payload(): void {
		$this->session->begin(); $quote = QuoteStorageFixtures::quote(); $this->repository->insert_quote( $quote ); $other = $this->accepted( QuoteStorageFixtures::quote() ); $writes = $this->session->writes;
		try { $this->repository->replace_quote( $quote, $other ); self::fail( 'Different immutable header overwrote quote.' ); } catch ( OperationStorageException ) { self::assertSame( $writes, $this->session->writes ); }
		$this->session->raw()->exec( "UPDATE storage_delivery_engine_delivery_quotes SET private_body_json='CORRUPTED' WHERE id=1" ); self::assertFalse( $this->repository->replace_quote( $quote, $this->accepted( $quote ) ) );
		self::assertSame( 'CORRUPTED', $this->session->raw()->query( 'SELECT private_body_json FROM storage_delivery_engine_delivery_quotes' )->fetchColumn() );
	}
	public function test_sql_error_and_duplicate_identity_are_not_reported_as_absence(): void {
		$this->session->begin(); $quote = QuoteStorageFixtures::quote(); $this->repository->insert_quote( $quote ); $this->session->failed_read = true;
		try { $this->repository->find_quote( $quote->header()->id() ); self::fail( 'Failed read became absence.' ); } catch ( OperationStorageException $error ) { self::assertNull( $error->getPrevious() ); self::assertSame( 'The operation store could not complete its database statement.', $error->getMessage() ); }
		$this->session->failed_read = false; $this->session->duplicate_read = true;
		try { $this->repository->find_quote( $quote->header()->id() ); self::fail( 'Duplicate identity was selected.' ); } catch ( OperationStorageException ) { self::assertTrue( true ); }
		$this->session->duplicate_read = false; self::assertNull( $this->repository->find_quote( QuoteId::generate() ) );
	}
	public function test_required_transport_overflow_marker_cannot_be_missing(): void {
		$this->session->begin(); $quote = QuoteStorageFixtures::quote(); $this->repository->insert_quote( $quote ); $this->session->missing_marker = true;
		$this->expectException( OperationStorageException::class ); $this->repository->find_quote( $quote->header()->id() );
	}
	public function test_corrupt_oversized_private_body_is_not_downloaded_even_for_stripped_state(): void {
		$this->session->begin(); $quote = QuoteStorageFixtures::quote(); $this->repository->insert_quote( $quote ); $body = str_repeat( 'PRIVATE', 10000 ); $statement = $this->session->raw()->prepare( "UPDATE storage_delivery_engine_delivery_quotes SET state='stripped',revision=2,retention_revision=2,transition_at='2026-10-07 05:35:00.000000',private_body_json=? WHERE id=1" ); $statement->execute( [ $body ] );
		try { $this->repository->find_quote( $quote->header()->id() ); self::fail( 'Oversized payload accepted.' ); } catch ( OperationStorageException ) { self::assertNull( $this->session->last_quote_result[0]['private_body_json'] ); self::assertSame( 1, $this->session->last_quote_result[0]['payload_oversized'] ); }
	}
	public function test_orphan_off_database_accepted_parent_cannot_insert_a_binding(): void {
		$this->session->begin(); $parent = QuoteStorageFixtures::quote( state: 'accepted' );
		try { $this->repository->insert_binding( QuoteStorageFixtures::binding( $parent ) ); self::fail( 'Off-database parent produced binding.' ); } catch ( OperationStorageException ) { self::assertSame( 0, $this->session->writes ); }
		self::assertSame( 0, (int) $this->session->raw()->query( 'SELECT COUNT(*) FROM storage_delivery_engine_delivery_quote_bindings' )->fetchColumn() );
	}
	public function test_native_parent_binding_verification_and_seal_are_finite_immutable_cas(): void {
		$this->session->begin(); $issued = QuoteStorageFixtures::quote(); $this->repository->insert_quote( $issued ); $parent = $this->accepted( $issued ); $this->repository->replace_quote( $issued, $parent ); $binding = QuoteStorageFixtures::binding( $parent ); self::assertSame( 1, $this->repository->insert_binding( $binding ) );
		$loaded = $this->repository->find_binding( $parent, true ); self::assertSame( $binding->row(), $loaded->row() ); $values = $loaded->row(); $values['snapshot_digest'] = QuoteFixtures::digest( 'physical_snapshot' ); $values['context_digest'] = QuoteFixtures::digest( 'verified_context' ); $values['verified_at'] = QuoteFixtures::time()->plus_seconds( 2 )->sql(); $values['revision'] = 2; $verified = QuoteBinding::from_row( $values, $parent ); self::assertTrue( $this->repository->replace_binding( $loaded, $verified ) );
		$values['state'] = 'sealed'; $values['sealed_at'] = QuoteFixtures::time()->plus_seconds( 3 )->sql(); $values['revision'] = 3; $sealed = QuoteBinding::from_row( $values, $parent ); self::assertTrue( $this->repository->replace_binding( $verified, $sealed ) ); self::assertFalse( $this->repository->replace_binding( $verified, $sealed ) );
		self::assertSame( $binding->row()['mapping_json'], $this->repository->find_binding_by_placement( QuoteId::from_string( $binding->row()['placement_uuid'] ) )->row()['mapping_json'] );
	}
	public function test_counter_cas_increments_once_and_admission_cannot_renew_or_reconsume(): void {
		$this->session->begin(); $counter = QuoteStorageFixtures::budget( admission: false ); $this->repository->insert_budget( $counter ); $row = $counter->row(); $row['attempt_count'] = 2; $row['revision'] = 2; $row['last_seen_at'] = QuoteFixtures::time()->plus_seconds( 1 )->sql(); $next = QuoteBudgetSlot::from_row( $row ); self::assertTrue( $this->repository->replace_budget( $counter, $next ) ); self::assertFalse( $this->repository->replace_budget( $counter, $next ) );
		$admission = QuoteStorageFixtures::budget( id: 2 ); $this->repository->insert_budget( $admission ); $row = $admission->row(); $row['lease_state'] = 'terminated'; $row['revision'] = 2; $row['last_seen_at'] = QuoteFixtures::time()->plus_seconds( 2 )->sql(); $terminal = QuoteBudgetSlot::from_row( $row ); self::assertTrue( $this->repository->replace_budget( $admission, $terminal ) ); self::assertFalse( $this->repository->replace_budget( $admission, $terminal ) );
		self::assertSame( $admission->row()['lease_expires_at'], $this->repository->find_admission( $admission->row()['admission_namespace_hash'], true )->row()['lease_expires_at'] );
	}
	public function test_orphan_consumed_receipt_cannot_update_existing_native_admission(): void {
		$this->session->begin(); $quote = QuoteStorageFixtures::quote(); $slot = $this->admission_for( $quote ); $this->repository->insert_budget( $slot ); $row = $slot->row(); $row['lease_state'] = 'consumed'; $row['revision'] = 2; $row['consumed_quote_uuid'] = $quote->header()->id()->value(); $row['consumed_at'] = QuoteFixtures::time()->plus_seconds( 1 )->sql(); $row['last_seen_at'] = $row['consumed_at']; $consumed = QuoteBudgetSlot::from_row( $row, $quote );
		try { $this->repository->replace_budget( $slot, $consumed ); self::fail( 'Off-database quote consumed admission.' ); } catch ( OperationStorageException ) { self::assertSame( 'granted', $this->repository->find_admission( $slot->row()['admission_namespace_hash'] )->row()['lease_state'] ); }
	}
	public function test_fixed_ceiling_page_excludes_later_identity_and_explicit_limit_overflow_refuses(): void {
		$this->session->begin(); $a = QuoteStorageFixtures::quote(); $this->repository->insert_quote( $a ); $ceiling = $this->repository->max_quote_id(); $this->repository->insert_quote( QuoteStorageFixtures::quote( id: 2 ) ); self::assertSame( [ 1 ], array_map( static fn( QuoteStoredRow $row ): int => $row->id(), $this->repository->quote_page( $ceiling ) ) );
		$this->expectException( OperationStorageException::class ); $this->repository->quote_page( $ceiling, 0, 101 );
	}
	private function accepted( QuoteStoredRow $quote ): QuoteStoredRow { $row = $quote->row(); $row['state'] = 'accepted'; $row['revision'] = 2; $row['accepted_at'] = QuoteFixtures::time()->plus_seconds( 1 )->sql(); $row['transition_at'] = $row['accepted_at']; return QuoteStoredRow::from_row( $row ); }
	private function admission_for( QuoteStoredRow $quote ): QuoteBudgetSlot { $row = QuoteStorageFixtures::budget()->row(); $row['admission_namespace_hash'] = $quote->row()['issue_namespace_hash']; $row['slot_key'] = QuoteBudgetSlot::admission_slot_key( $row['admission_namespace_hash'] ); return QuoteBudgetSlot::from_row( $row ); }
}

/** SQLite statement proof with explicitly simulated native metadata, not MariaDB. */
final class StorageUnitSession implements OperationSession {
	private \PDO $pdo; private bool $retired = false; private int $error = 0;
	public array $statements = []; public int $writes = 0; public int $begins = 0; public int $commits = 0; public string $schema_version = '9'; public bool $wrong_engine = false; public bool $failed_read = false; public bool $duplicate_read = false; public bool $missing_marker = false; public array $last_quote_result = [];
	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] ); $this->pdo->sqliteCreateFunction( 'OCTET_LENGTH', static fn( $value ) => null === $value ? null : strlen( $value ), 1 );
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) {
			$columns = []; foreach ( DeliveryQuoteSchema::columns( $suffix ) as $name => $definition ) { $columns[] = $name . ( 'id' === $name ? ' INTEGER PRIMARY KEY AUTOINCREMENT' : ( str_contains( $definition[0], 'int' ) ? ' INTEGER' : ' TEXT' ) . ( $definition[1] ? '' : ' NOT NULL' ) ); }
			$table = 'storage_delivery_engine_' . $suffix; $this->pdo->exec( 'CREATE TABLE ' . $table . '(' . implode( ',', $columns ) . ')' ); foreach ( DeliveryQuoteSchema::indexes( $suffix ) as $name => $index ) { if ( 'PRIMARY' !== $name ) { $this->pdo->exec( 'CREATE ' . ( $index['unique'] ? 'UNIQUE ' : '' ) . 'INDEX ' . $suffix . '_' . $name . ' ON ' . $table . '(' . implode( ',', $index['columns'] ) . ')' ); } }
		}
	}
	public function raw(): \PDO { return $this->pdo; }
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'storage_'; } public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { ++$this->begins; return ! $this->retired && $this->pdo->beginTransaction(); }
	public function commit(): OperationCommitResult { ++$this->commits; return $this->pdo->commit() ? OperationCommitResult::Acknowledged : OperationCommitResult::NotSent; }
	public function rollback(): bool { return $this->pdo->inTransaction() && $this->pdo->rollBack(); }
	public function retire(): bool { if ( $this->pdo->inTransaction() ) { $this->pdo->rollBack(); } $this->retired = true; return true; }
	public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return ! $this->retired && $this->pdo->inTransaction(); }
	public function validate_tables( array $names ): bool { return $this->in_transaction() && $names === DeliveryQuoteSchema::tables( 'storage_' ); }
	public function query( string $sql ): int|false { $this->statements[] = $sql; ++$this->writes; try { $this->error = 0; return $this->pdo->exec( $this->sql( $sql ) ); } catch ( \Throwable ) { $this->error = 1062; return false; } }
	public function get_row( string $sql ): array|null|false {
		$this->statements[] = $sql; $this->error = 0;
		if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { return [ 'Engine' => $this->wrong_engine ? 'MyISAM' : 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ]; }
		if ( str_contains( $sql, SchemaVersion::OPTION_NAME ) ) { return [ 'option_value' => $this->schema_version ]; }
		if ( str_contains( $sql, MigrationStatus::OPTION_NAME ) ) { return [ 'option_value' => serialize( [ 'status' => 'success', 'to_version' => '9', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID ] ) ]; }
		try { return $this->pdo->query( $this->sql( $sql ) )->fetch( \PDO::FETCH_ASSOC ) ?: null; } catch ( \Throwable ) { $this->error = 1; return false; }
	}
	public function get_results( string $sql ): array|false {
		$this->statements[] = $sql; $this->error = 0;
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) || str_starts_with( $sql, 'SHOW INDEX' ) ) { return $this->metadata( $sql ); }
		$quote_read = str_contains( $sql, 'FROM `storage_delivery_engine_delivery_quotes`' ); if ( $quote_read && $this->failed_read ) { $this->error = 1; return false; }
		try { $rows = $this->pdo->query( $this->sql( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ); } catch ( \Throwable ) { $this->error = 1; return false; }
		if ( $quote_read ) { $this->last_quote_result = $rows; if ( $this->missing_marker ) { foreach ( $rows as &$row ) { unset( $row['payload_oversized'] ); } unset( $row ); } if ( $this->duplicate_read && [] !== $rows ) { $rows[] = $rows[0]; } }
		return $rows;
	}
	public function prepare( string $sql, mixed ...$args ): string { if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } $i = 0; return preg_replace_callback( '/%[ds]/', function( array $match ) use ( &$i, $args ): string { $value = $args[$i++]; return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value ); }, $sql ); }
	public function errno(): int { return $this->error; } public function insert_id(): int { return (int) $this->pdo->lastInsertId(); }
	private function sql( string $sql ): string { return str_replace( [ ' FOR UPDATE', 'BINARY ' ], '', $sql ); }
	private function metadata( string $sql ): array {
		preg_match( '/storage_delivery_engine_([a-z_]+)`/', $sql, $match ); $suffix = $match[1];
		if ( in_array( $suffix, DeliveryQuoteSchema::SUFFIXES, true ) ) { $columns = DeliveryQuoteSchema::columns( $suffix ); $indexes = DeliveryQuoteSchema::indexes( $suffix ); }
		elseif ( in_array( $suffix, RuleLifecycleSchema::SUFFIXES, true ) ) { $columns = RuleLifecycleSchema::columns( $suffix ); $indexes = RuleLifecycleSchema::indexes( $suffix ); }
		elseif ( in_array( $suffix, OperationStoreSchema::SUFFIXES, true ) ) { $columns = OperationStoreSchema::columns( $suffix ); $indexes = OperationStoreSchema::indexes( $suffix ); }
		else { $columns = DeliveryQuoteSchema::rate_columns(); $indexes = [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ], DeliveryQuoteSchema::RATE_INDEX => DeliveryQuoteSchema::rate_index() ]; }
		$out = [];
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { foreach ( $columns as $name => [ $type, $nullable, $default, $extra, $collation ] ) { $out[] = [ 'Field' => $name, 'Type' => $type, 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ]; } }
		else { foreach ( $indexes as $name => $definition ) { foreach ( $definition['columns'] as $position => $field ) { $out[] = [ 'Key_name' => $name, 'Seq_in_index' => $position + 1, 'Non_unique' => $definition['unique'] ? 0 : 1, 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $field, 'Expression' => null, 'Visible' => 'YES', 'Ignored' => 'NO' ]; } } }
		return $out;
	}
}
