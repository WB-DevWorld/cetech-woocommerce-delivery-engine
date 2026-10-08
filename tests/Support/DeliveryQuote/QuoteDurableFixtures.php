<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Support\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteAdmissionGate,QuoteCurrentEvidenceGuard,QuoteDurableService,QuotePrivatePublication,QuoteProviderInterface,QuoteProviderRegistry};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus,SchemaVersion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteId,QuoteOwner,QuoteTerms};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult,OperationConnectionFactory,OperationSession,OperationPhaseObserver};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteReadiness,DeliveryQuoteSchema,OperationStoreSchema,RuleLifecycleSchema,EmergencyControlStore};
require_once __DIR__ . '/QuoteFixtures.php';

/** Owned SQLite units and explicit native metadata simulation; not a MariaDB proof. */
final class QuoteDurableFixtureFactory implements OperationConnectionFactory {
	public readonly \PDO $pdo;
	public string $utc = '2026-10-07 05:00:00.000000'; public bool $authorized = true; public bool $paused = false; public bool $guard_ok = true; public bool $reject_audit = false; public bool $wrong_schema = false;
	public bool $lose_read_rollback_ack = false; public string $effect_fault = ''; public string $gate_fault = ''; public int $captures = 0; public int $opens = 0; public array $statements = []; public array $sessions = []; public ?\Closure $on_retire = null; public ?\Closure $before_guard = null; public ?\Closure $before_capture = null;
	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] ); $this->pdo->sqliteCreateFunction( 'OCTET_LENGTH', static fn( ?string $v ): ?int => null === $v ? null : strlen( $v ), 1 ); $this->pdo->sqliteCreateFunction( 'UTC_NOW', fn(): string => $this->utc );
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) {
			$columns = []; foreach ( DeliveryQuoteSchema::columns( $suffix ) as $name => $d ) { $columns[] = $name . ( 'id' === $name ? ' INTEGER PRIMARY KEY AUTOINCREMENT' : ( str_contains( $d[0], 'int' ) ? ' INTEGER' : ' TEXT' ) . ( $d[1] ? '' : ' NOT NULL' ) ); }
			$table = 'durable_delivery_engine_' . $suffix; $this->pdo->exec( 'CREATE TABLE ' . $table . '(' . implode( ',', $columns ) . ')' ); foreach ( DeliveryQuoteSchema::indexes( $suffix ) as $name => $i ) { if ( 'PRIMARY' !== $name ) { $this->pdo->exec( 'CREATE ' . ( $i['unique'] ? 'UNIQUE ' : '' ) . 'INDEX ' . $suffix . '_' . $name . ' ON ' . $table . '(' . implode( ',', $i['columns'] ) . ')' ); } }
		}
		$this->pdo->exec( 'CREATE TABLE durable_delivery_engine_operation_records(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,namespace_hash TEXT NOT NULL,intent_hash TEXT NOT NULL,namespace_format INTEGER NOT NULL,intent_format INTEGER NOT NULL,record_format INTEGER NOT NULL,operation TEXT NOT NULL,operation_version INTEGER NOT NULL,target_hash TEXT NOT NULL,state TEXT NOT NULL,publication_state TEXT NOT NULL,completion_json TEXT NULL,audit_id INTEGER NULL,row_version INTEGER NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,completed_at TEXT NULL,UNIQUE(site_id,namespace_hash))' );
		$this->pdo->exec( 'CREATE TABLE durable_delivery_engine_operation_changes(id INTEGER PRIMARY KEY AUTOINCREMENT,site_id INTEGER NOT NULL,operation_id INTEGER NOT NULL,event_format INTEGER NOT NULL,event_json TEXT NOT NULL,created_at TEXT NOT NULL,UNIQUE(site_id,operation_id))' );
		$this->pdo->exec( 'CREATE TABLE durable_fence(id INTEGER PRIMARY KEY,context_digest TEXT NOT NULL)' ); $this->pdo->exec( "INSERT INTO durable_fence VALUES(1,'" . QuoteFixtures::context()->digest() . "')" );
	}
	public function open(): OperationSession { ++$this->opens; $session = new QuoteDurableFixtureSession( $this ); $this->sessions[] = $session; return $session; }
	public function count( string $suffix ): int { return (int) $this->pdo->query( 'SELECT COUNT(*) FROM durable_delivery_engine_' . $suffix )->fetchColumn(); }
	public function service( ?QuoteFixturePublication $publication = null, ?OperationPhaseObserver $observer = null ): QuoteDurableService {
		$control = new QuoteFixtureControl( $this ); $authorizer = fn(): bool => $this->authorized;
		$gate = new QuoteAdmissionGate( $this, $authorizer, static function(): void {}, $control );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		return new QuoteDurableService( $this, new QuoteProviderRegistry( [ new QuoteFixtureProvider( $this ) ] ), $authorizer, $gate, $ready, $observer, $control, new QuoteFixtureEvidence( $this ), $publication ?? new QuoteFixturePublication() );
	}
}
final class QuoteDurableFixtureSession implements OperationSession {
	private bool $retired = false; private int $error = 0; private bool $quote_write = false; private bool $admission_write = false;
	public function __construct( private QuoteDurableFixtureFactory $f ) {}
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'durable_'; } public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { return ! $this->retired && ! $this->f->pdo->inTransaction() && $this->f->pdo->beginTransaction(); }
	public function commit(): OperationCommitResult {
		$fault = $this->quote_write ? $this->f->effect_fault : ( $this->admission_write ? $this->f->gate_fault : '' ); if ( $this->quote_write ) { $this->f->effect_fault = ''; } if ( $this->admission_write ) { $this->f->gate_fault = ''; }
		if ( 'not_sent' === $fault ) { return OperationCommitResult::NotSent; } $this->f->pdo->commit(); $this->quote_write = false; $this->admission_write = false;
		if ( 'lost_ack' === $fault ) { $this->retire(); return OperationCommitResult::Unconfirmed; } return OperationCommitResult::Acknowledged;
	}
	public function rollback(): bool { $read = ! $this->quote_write && ! $this->admission_write; $this->quote_write = false; $this->admission_write = false; $ok = ! $this->retired && $this->f->pdo->inTransaction() && $this->f->pdo->rollBack(); if ( $ok && $read && $this->f->lose_read_rollback_ack ) { $this->f->lose_read_rollback_ack = false; $this->retire(); return false; } return $ok; }
	public function retire(): bool { if ( $this->f->pdo->inTransaction() ) { $this->f->pdo->rollBack(); } $this->retired = true; if ( null !== $this->f->on_retire ) { ($this->f->on_retire)(); } return true; }
	public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return ! $this->retired && $this->f->pdo->inTransaction(); }
	public function validate_tables( array $names ): bool { if ( ! $this->in_transaction() ) { return false; } foreach ( $names as $name ) { if ( ! str_starts_with( $name, 'durable_' ) ) { return false; } } return true; }
	public function query( string $sql ): int|false {
		$this->f->statements[] = $sql; $this->error = 0;
		if ( $this->f->reject_audit && str_starts_with( $sql, 'INSERT INTO `durable_delivery_engine_operation_changes`' ) ) { $this->f->reject_audit = false; return false; }
		$this->quote_write = $this->quote_write || preg_match( '/\A(?:INSERT INTO|UPDATE) `durable_delivery_engine_delivery_quote(?:s|_bindings)`/', $sql ) === 1;
		$this->admission_write = $this->admission_write || preg_match( '/\A(?:INSERT INTO|UPDATE) `durable_delivery_engine_delivery_quote_budget_windows`/', $sql ) === 1;
		try { return $this->f->pdo->exec( $this->sql( $sql ) ); } catch ( \Throwable $e ) { $this->error = str_contains( $e->getMessage(), 'UNIQUE' ) ? 1062 : 1; return false; }
	}
	public function get_row( string $sql ): array|null|false {
		$this->f->statements[] = $sql;
		if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { return [ 'utc' => $this->f->utc ]; }
		if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { return [ 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ]; }
		if ( str_contains( $sql, SchemaVersion::OPTION_NAME ) ) { return [ 'option_value' => $this->f->wrong_schema ? '8' : '9' ]; }
		if ( str_contains( $sql, MigrationStatus::OPTION_NAME ) ) { return [ 'option_value' => serialize( [ 'status' => 'success', 'to_version' => '9', 'migration_id' => DeliveryQuoteReadiness::MIGRATION_ID ] ) ]; }
		try { return $this->f->pdo->query( $this->sql( $sql ) )->fetch( \PDO::FETCH_ASSOC ) ?: null; } catch ( \Throwable ) { return false; }
	}
	public function get_results( string $sql ): array|false { $this->f->statements[] = $sql; if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) || str_starts_with( $sql, 'SHOW INDEX' ) ) { return $this->metadata( $sql ); } try { return $this->f->pdo->query( $this->sql( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ); } catch ( \Throwable ) { return false; } }
	public function prepare( string $sql, mixed ...$args ): string { if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } $i = 0; return preg_replace_callback( '/%[ds]/', function( array $m ) use ( &$i, $args ): string { $v = $args[$i++]; return '%d' === $m[0] ? (string) (int) $v : $this->f->pdo->quote( (string) $v ); }, $sql ); }
	public function errno(): int { return $this->error; } public function insert_id(): int { return (int) $this->f->pdo->lastInsertId(); }
	private function sql( string $sql ): string { return str_replace( [ ' FOR UPDATE', 'BINARY ', 'UTC_TIMESTAMP(6)' ], [ '', '', 'UTC_NOW()' ], $sql ); }
	private function metadata( string $sql ): array {
		preg_match( '/durable_delivery_engine_([a-z_]+)`/', $sql, $m ); $suffix = $m[1] ?? '';
		if ( in_array( $suffix, DeliveryQuoteSchema::SUFFIXES, true ) ) { $columns = DeliveryQuoteSchema::columns( $suffix ); $indexes = DeliveryQuoteSchema::indexes( $suffix ); }
		elseif ( in_array( $suffix, RuleLifecycleSchema::SUFFIXES, true ) ) { $columns = RuleLifecycleSchema::columns( $suffix ); $indexes = RuleLifecycleSchema::indexes( $suffix ); }
		elseif ( in_array( $suffix, OperationStoreSchema::SUFFIXES, true ) ) { $columns = OperationStoreSchema::columns( $suffix ); $indexes = OperationStoreSchema::indexes( $suffix ); }
		else { $columns = DeliveryQuoteSchema::rate_columns(); $indexes = [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ], DeliveryQuoteSchema::RATE_INDEX => DeliveryQuoteSchema::rate_index() ]; }
		$out = []; if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { foreach ( $columns as $name => [ $type, $nullable, $default, $extra, $collation ] ) { $out[] = [ 'Field' => $name, 'Type' => $type, 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ]; } }
		else { foreach ( $indexes as $name => $index ) { foreach ( $index['columns'] as $pos => $field ) { $out[] = [ 'Key_name' => $name, 'Seq_in_index' => $pos + 1, 'Non_unique' => $index['unique'] ? 0 : 1, 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $field, 'Expression' => null, 'Visible' => 'YES', 'Ignored' => 'NO' ]; } } } return $out;
	}
}
final class QuoteFixtureControl extends EmergencyControlStore {
	public function __construct( private QuoteDurableFixtureFactory $f ) { parent::__construct(); }
	public function assert_ready( OperationSession $session, int $site ): void {}
	public function current( OperationSession $session ): EmergencyControlState { $this->f->statements[] = 'FIXTURE CURRENT CONTROL FOR UPDATE'; return $this->f->paused ? EmergencyControlState::from_physical( 1, 1, EmergencyControlState::record_json( 1, 'checkout_suspended', 2, 'operator_pause', 1, 1791349200 ) ) : EmergencyControlState::absent( 1 ); }
}
final class QuoteFixtureEvidence implements QuoteCurrentEvidenceGuard {
	public function __construct( private QuoteDurableFixtureFactory $f ) {}
	public function tables( OperationSession $session ): array { return [ 'durable_fence' ]; }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool {
		if ( null !== $this->f->before_guard ) { $callback = $this->f->before_guard; $this->f->before_guard = null; $callback(); }
		$r = $session->get_row( 'SELECT context_digest FROM durable_fence WHERE id=1 FOR UPDATE' ); return $this->f->guard_ok && is_array( $r ) && $r['context_digest'] === $context->digest();
	}
}
final class QuoteFixtureProvider implements QuoteProviderInterface {
	public function __construct( private QuoteDurableFixtureFactory $f ) {}
	public function code(): string { return 'fixture_v1'; } public function version(): int { return 1; } public function profile(): string { return 'fixture_v1'; } public function profile_version(): int { return 1; } public function evidence_providers(): array { return [ 'fixture_none_v1' => [ 1 ] ]; }
	public function capture( QuoteContext $context ): QuoteTerms { ++$this->f->captures; if ( null !== $this->f->before_capture ) { ($this->f->before_capture)(); } return QuoteFixtures::terms(); }
}
final class QuoteFixturePublication implements QuotePrivatePublication {
	public bool $allowed = true; public int $calls = 0; public array $invalidated = [];
	public function invalidate( int $site_id, string $owner_digest, QuoteId $quote_id ): bool { ++$this->calls; if ( $this->allowed ) { $this->invalidated[] = $quote_id->value(); } return $this->allowed; }
}
