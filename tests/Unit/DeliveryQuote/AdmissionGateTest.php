<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionAttempt;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionGate;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteFixtures.php';

/** SQLite transaction and transport-state proof only; native locking has separate SQL proofs. */
final class AdmissionGateTest extends TestCase {
	private AdmissionUnitFactory $factory;
	private AdmissionUnitControl $control;
	private QuoteAdmissionGate $gate;
	protected function setUp(): void { $this->factory = new AdmissionUnitFactory(); $this->control = new AdmissionUnitControl( $this->factory ); $this->gate = $this->service(); }
	private function service( ?callable $authorize = null, ?callable $readiness = null ): QuoteAdmissionGate { return new QuoteAdmissionGate( $this->factory, $authorize ?? static fn(): bool => true, $readiness ?? static function(): void {}, $this->control ); }
	private function command( string $token = 'original_token', ?QuoteOwner $owner = null, ?QuoteContext $context = null ): QuoteIssueCommand { return QuoteIssueCommand::create( $owner ?? QuoteFixtures::owner(), $context ?? QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $token ); }
	private function counts(): array { $out = []; foreach ( $this->factory->rows() as $row ) { $out[$row['slot_kind']][] = (int) $row['attempt_count']; } return $out; }

	public function test_confirmed_grant_charges_once_and_allows_only_one_live_capture(): void {
		$command = $this->command(); $attempt = QuoteAdmissionAttempt::generate(); $result = $this->gate->admit( $command, $attempt );
		self::assertSame( 'capture_allowed', $result->status ); self::assertSame( [ 'site_minute' => [ 1 ], 'session_minute' => [ 1 ], 'admission' => [ 1 ] ], $this->counts() );
		self::assertSame( $command->identity()->namespace_digest(), $result->lease->namespace_hash() ); self::assertSame( $command->intent_digest(), $result->lease->intent_digest() );
		self::assertSame( $command->owner()->digest(), $result->lease->owner_digest() ); self::assertSame( $attempt->digest(), $result->lease->server_attempt_digest() );
		self::assertSame( '2026-10-07 05:01:00.000000', $result->lease->expires_at()->sql() ); self::assertTrue( $result->lease->claim_capture() ); self::assertFalse( $result->lease->claim_capture() );
		self::assertSame( 'pending', $this->gate->admit( $command, $attempt )->status ); self::assertSame( 'pending', $this->gate->reconcile( $command, $attempt )->status );
		self::assertSame( 3, count( $this->factory->rows() ) ); self::assertSame( 1, $this->factory->commits ); self::assertSame( 0, $this->factory->c03_statements() );
		self::assertSame( [ 'control', 'site_minute', 'session_minute', 'admission' ], array_slice( $this->factory->lock_order, 0, 4 ) );
	}
	public function test_twenty_session_attempts_then_denial_has_no_new_lease_or_capture(): void {
		for ( $i = 0; $i < 20; ++$i ) { self::assertSame( 'capture_allowed', $this->gate->admit( $this->command( 'token_' . $i ), QuoteAdmissionAttempt::generate() )->status ); }
		$before = $this->factory->rows(); $writes = $this->factory->writes; $denied = $this->gate->admit( $this->command( 'overflow' ), QuoteAdmissionAttempt::generate() );
		self::assertSame( 'denied', $denied->status ); self::assertSame( 'budget_exhausted', $denied->reason ); self::assertNull( $denied->lease ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes ); self::assertSame( 0, $this->factory->c03_statements() );
	}
	public function test_two_hundred_site_attempts_across_sessions_then_denial_is_atomic(): void {
		for ( $i = 0; $i < 200; ++$i ) { $owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'session_hash' => QuoteFixtures::digest( 'session_' . $i ) ] ) ); self::assertSame( 'capture_allowed', $this->gate->admit( $this->command( 'token_' . $i, $owner ), QuoteAdmissionAttempt::generate() )->status ); }
		$before = $this->factory->rows(); $owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'session_hash' => QuoteFixtures::digest( 'overflow_session' ) ] ) );
		$result = $this->gate->admit( $this->command( 'overflow', $owner ), QuoteAdmissionAttempt::generate() ); self::assertSame( 'budget_exhausted', $result->reason ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( 401, count( $before ) );
	}
	public function test_lost_gate_ack_reconciles_same_live_attempt_once_without_recapture_or_budget(): void {
		$command = $this->command(); $attempt = QuoteAdmissionAttempt::generate(); $this->factory->commit_mode = 'lost_ack';
		$result = $this->gate->admit( $command, $attempt ); self::assertSame( 'unconfirmed', $result->status ); self::assertNull( $result->lease ); self::assertSame( 3, count( $this->factory->rows() ) );
		$rows = $this->factory->rows(); $writes = $this->factory->writes; $this->factory->commit_mode = 'acknowledged';
		self::assertSame( 'pending', $this->gate->reconcile( $command )->status );
		$resolved = $this->gate->reconcile( $command, $attempt ); self::assertSame( 'capture_allowed', $resolved->status ); self::assertSame( $rows, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes );
		self::assertTrue( $resolved->lease->claim_capture() ); self::assertSame( 'pending', $this->gate->reconcile( $command, $attempt )->status ); self::assertSame( 1, $this->factory->commits ); self::assertGreaterThan( 1, $this->factory->opens );
	}
	public function test_sent_commit_exception_is_unknown_not_an_unsent_denial(): void {
		$this->factory->commit_mode = 'throw_after_commit'; $attempt = QuoteAdmissionAttempt::generate(); $command = $this->command(); $result = $this->gate->admit( $command, $attempt );
		self::assertSame( 'unconfirmed', $result->status ); self::assertSame( 3, count( $this->factory->rows() ) ); $this->factory->commit_mode = 'acknowledged'; self::assertSame( 'capture_allowed', $this->gate->reconcile( $command, $attempt )->status );
	}
	public function test_pause_after_lost_gate_ack_prevents_reconciled_capture(): void {
		$command = $this->command(); $attempt = QuoteAdmissionAttempt::generate(); $this->factory->commit_mode = 'lost_ack'; self::assertSame( 'unconfirmed', $this->gate->admit( $command, $attempt )->status );
		$this->factory->commit_mode = 'acknowledged'; $this->control->paused = true; $before = $this->factory->rows(); $result = $this->gate->reconcile( $command, $attempt );
		self::assertSame( 'denied', $result->status ); self::assertSame( 'checkout_suspended', $result->reason ); self::assertNull( $result->lease ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( 0, $this->factory->c03_statements() );
	}
	public function test_definitely_unsent_commit_rolls_back_no_lease_no_budget_and_no_implicit_retry(): void {
		$this->factory->commit_mode = 'not_sent'; $attempt = QuoteAdmissionAttempt::generate(); $command = $this->command(); $result = $this->gate->admit( $command, $attempt );
		self::assertSame( 'commit_not_sent', $result->reason ); self::assertSame( [], $this->factory->rows() ); $this->factory->commit_mode = 'acknowledged'; self::assertSame( 'no_admission', $this->gate->admit( $command, $attempt )->reason ); self::assertSame( [], $this->factory->rows() );
	}
	#[DataProvider( 'refused_statement' )]
	public function test_refused_counter_or_grant_rolls_back_whole_gate( int $statement ): void {
		$this->factory->fail_write = $statement; $result = $this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() ); self::assertSame( 'storage_unavailable', $result->reason ); self::assertSame( [], $this->factory->rows() ); self::assertSame( 0, $this->factory->commits );
	}
	public static function refused_statement(): array { return [ 'site' => [ 1 ], 'session' => [ 2 ], 'lease' => [ 3 ] ]; }
	public function test_refused_cas_keeps_previously_accepted_counters_and_grant_bytes(): void {
		$this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() ); $before = $this->factory->rows(); $this->factory->fail_write = $this->factory->writes + 2;
		self::assertSame( 'storage_unavailable', $this->gate->admit( $this->command( 'second' ), QuoteAdmissionAttempt::generate() )->reason ); self::assertSame( $before, $this->factory->rows() );
	}
	public function test_fresh_replay_after_minute_turnover_does_not_charge_or_capture(): void {
		$command = $this->command(); $first = $this->gate->admit( $command, QuoteAdmissionAttempt::generate() ); $before = $this->factory->rows(); $this->factory->utc = '2026-10-07 05:01:00.000000';
		$result = $this->gate->admit( $command, QuoteAdmissionAttempt::generate() ); self::assertSame( 'lease_expired', $result->reason ); self::assertNull( $result->lease ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( '2026-10-07 05:01:00.000000', $first->lease->expires_at()->sql() );
	}
	public function test_expired_original_is_terminated_once_and_never_resurrected(): void {
		$command = $this->command(); $this->gate->admit( $command, QuoteAdmissionAttempt::generate() ); $this->factory->utc = '2026-10-07 05:01:00.000000'; $result = $this->gate->reconcile( $command );
		self::assertSame( 'lease_terminated', $result->reason ); $rows = $this->factory->rows(); $writes = $this->factory->writes; self::assertSame( 'lease_terminated', $this->gate->admit( $command, QuoteAdmissionAttempt::generate() )->reason ); self::assertSame( 'lease_terminated', $this->gate->reconcile( $command )->reason );
		self::assertSame( $rows, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes ); self::assertSame( 'capture_allowed', $this->gate->admit( $this->command( 'explicit_new' ), QuoteAdmissionAttempt::generate() )->status );
	}
	public function test_original_token_with_changed_intent_conflicts_without_new_budget(): void {
		$this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() ); $rows = $this->factory->rows(); $context = QuoteFixtures::context( [ 'selection_digest' => QuoteFixtures::digest( 'changed' ) ] );
		$result = $this->gate->admit( $this->command( context: $context ), QuoteAdmissionAttempt::generate() ); self::assertSame( 'intent_conflict', $result->reason ); self::assertSame( $rows, $this->factory->rows() );
	}
	public function test_authorization_is_required_before_any_lookup_and_rechecked_before_disclosure(): void {
		$result = $this->service( static fn(): bool => false )->admit( $this->command(), QuoteAdmissionAttempt::generate() ); self::assertSame( 'not_authorized', $result->reason ); self::assertSame( 0, $this->factory->opens );
		$result = $this->service( static fn(): bool => false )->inspect( $this->command() ); self::assertSame( 'not_authorized', $result->reason ); self::assertSame( 0, $this->factory->opens );
		$checks = 0; $gate = $this->service( static function() use ( &$checks ): bool { return ++$checks < 7; } ); $result = $gate->admit( $this->command(), QuoteAdmissionAttempt::generate() );
		self::assertSame( 'not_authorized', $result->reason ); self::assertNull( $result->lease ); self::assertSame( 3, count( $this->factory->rows() ) );
	}
	public function test_revocation_before_mutation_rolls_back_and_runs_no_provider(): void {
		$checks = 0; $gate = $this->service( static function() use ( &$checks ): bool { return ++$checks < 4; } ); $result = $gate->admit( $this->command(), QuoteAdmissionAttempt::generate() );
		self::assertSame( 'not_authorized', $result->reason ); self::assertNull( $result->lease ); self::assertSame( [], $this->factory->rows() ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_paused_or_unknown_control_denies_before_any_budget_lookup(): void {
		$this->control->paused = true; $result = $this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() ); self::assertSame( 'checkout_suspended', $result->reason ); self::assertSame( [], $this->factory->rows() ); self::assertSame( [ 'control' ], $this->factory->lock_order );
		$this->control->paused = false; $this->control->failed = true; self::assertSame( 'storage_unavailable', $this->gate->admit( $this->command( 'unknown' ), QuoteAdmissionAttempt::generate() )->reason ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_wait_crossing_fixed_window_refuses_without_recapturing_minute(): void {
		$this->factory->after_admission_read = function(): void { $this->factory->utc = '2026-10-07 05:01:00.000000'; }; $result = $this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() ); self::assertSame( 'storage_unavailable', $result->reason ); self::assertSame( [], $this->factory->rows() );
	}
	public function test_rollback_or_retirement_uncertainty_withholds_capture(): void {
		$this->factory->commit_mode = 'not_sent'; $this->factory->rollback_ok = false; self::assertSame( 'unconfirmed', $this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() )->status );
		$this->factory = new AdmissionUnitFactory(); $this->control = new AdmissionUnitControl( $this->factory ); $this->factory->retire_ok = false; $gate = $this->service(); $attempt = QuoteAdmissionAttempt::generate(); $command = $this->command(); self::assertSame( 'unconfirmed', $gate->admit( $command, $attempt )->status ); self::assertSame( 3, count( $this->factory->rows() ) );
		$this->factory->retire_ok = true; self::assertSame( 'capture_allowed', $gate->reconcile( $command, $attempt )->status );
	}
	public function test_ambient_owner_is_not_rolled_back_or_used(): void {
		$this->factory->ambient = true; self::assertSame( 'denied', $this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() )->status ); self::assertSame( 0, $this->factory->rollbacks ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_corrupt_read_and_failed_readiness_never_become_absence(): void {
		$this->factory->fail_reads = true; self::assertSame( 'storage_unavailable', $this->gate->admit( $this->command(), QuoteAdmissionAttempt::generate() )->reason ); self::assertSame( [], $this->factory->rows() );
		$this->factory->fail_reads = false; $gate = $this->service( readiness: static function(): void { throw new \RuntimeException( 'private database message' ); } ); self::assertSame( [ 'status' => 'denied', 'reason' => 'storage_unavailable' ], $gate->admit( $this->command( 'readiness' ), QuoteAdmissionAttempt::generate() )->safe() ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_capture_capability_cannot_be_serialized_cloned_or_transferred_to_other_attempt(): void {
		$command = $this->command(); $attempt = QuoteAdmissionAttempt::generate(); $grant = $this->gate->admit( $command, $attempt ); $other = QuoteAdmissionAttempt::generate(); self::assertSame( 'pending', $this->gate->reconcile( $command, $other )->status );
		foreach ( [ $attempt, $grant->lease, $grant ] as $private ) { try { json_encode( $private, JSON_THROW_ON_ERROR ); self::fail( 'Private carrier was serialized.' ); } catch ( \LogicException ) { self::assertTrue( true ); } try { serialize( $private ); self::fail( 'Private capability was persisted.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
		try { $copy = clone $attempt; self::fail( 'Capability copied.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		self::assertTrue( $grant->lease->claim_capture() ); self::assertFalse( $attempt->claim_capture() );
	}
}

final class AdmissionUnitFactory implements OperationConnectionFactory {
	public readonly \PDO $pdo;
	public string $utc = '2026-10-07 05:00:00.000000'; public string $commit_mode = 'acknowledged'; public int $opens = 0; public int $writes = 0; public int $commits = 0; public int $rollbacks = 0; public int $fail_write = 0; public bool $rollback_ok = true; public bool $retire_ok = true; public bool $ambient = false; public bool $fail_reads = false; public array $statements = []; public array $lock_order = []; public ?\Closure $after_admission_read = null;
	public function __construct() {
		$this->pdo = new \PDO( 'sqlite::memory:' ); $this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION ); $this->pdo->sqliteCreateFunction( 'OCTET_LENGTH', static fn( ?string $value ): ?int => null === $value ? null : strlen( $value ), 1 );
		foreach ( DeliveryQuoteSchema::SUFFIXES as $suffix ) { $columns = []; foreach ( DeliveryQuoteSchema::columns( $suffix ) as $name => $definition ) { $columns[] = $name . ( 'id' === $name ? ' INTEGER PRIMARY KEY AUTOINCREMENT' : ( str_contains( $definition[0], 'int' ) ? ' INTEGER' : ' TEXT' ) . ( $definition[1] ? '' : ' NOT NULL' ) ); } $table = 'admission_delivery_engine_' . $suffix; $this->pdo->exec( 'CREATE TABLE ' . $table . '(' . implode( ',', $columns ) . ')' ); foreach ( DeliveryQuoteSchema::indexes( $suffix ) as $name => $index ) { if ( 'PRIMARY' !== $name ) { $this->pdo->exec( 'CREATE ' . ( $index['unique'] ? 'UNIQUE ' : '' ) . 'INDEX ' . $suffix . '_' . $name . ' ON ' . $table . '(' . implode( ',', $index['columns'] ) . ')' ); } } }
	}
	public function open(): OperationSession { ++$this->opens; return new AdmissionUnitSession( $this ); }
	public function rows(): array { return $this->pdo->query( 'SELECT * FROM admission_delivery_engine_delivery_quote_budget_windows ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); }
	public function c03_statements(): int { return count( array_filter( $this->statements, static fn( string $sql ): bool => str_contains( $sql, 'operation_records' ) || str_contains( $sql, 'operation_changes' ) ) ); }
}
final class AdmissionUnitSession implements OperationSession {
	private bool $retired = false; private int $error = 0;
	public function __construct( private readonly AdmissionUnitFactory $factory ) {}
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'admission_'; } public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { return ! $this->retired && ! $this->in_transaction() && $this->factory->pdo->beginTransaction(); }
	public function commit(): OperationCommitResult { ++$this->factory->commits; if ( 'not_sent' === $this->factory->commit_mode ) { return OperationCommitResult::NotSent; } $this->factory->pdo->commit(); if ( 'throw_after_commit' === $this->factory->commit_mode ) { throw new \RuntimeException( 'private transport fault' ); } return 'lost_ack' === $this->factory->commit_mode ? OperationCommitResult::Unconfirmed : OperationCommitResult::Acknowledged; }
	public function rollback(): bool { ++$this->factory->rollbacks; return $this->factory->rollback_ok && $this->factory->pdo->inTransaction() && $this->factory->pdo->rollBack(); }
	public function retire(): bool { if ( $this->factory->pdo->inTransaction() ) { $this->factory->pdo->rollBack(); } $this->retired = $this->factory->retire_ok; return $this->retired; }
	public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return ! $this->retired && ( $this->factory->ambient || $this->factory->pdo->inTransaction() ); }
	public function validate_tables( array $table_names ): bool { return $this->in_transaction() && $table_names === [ 'admission_delivery_engine_delivery_quote_budget_windows' ]; }
	public function query( string $sql ): int|false { $this->factory->statements[] = $sql; ++$this->factory->writes; if ( $this->factory->writes === $this->factory->fail_write ) { return false; } try { return $this->factory->pdo->exec( $this->sql( $sql ) ); } catch ( \Throwable ) { $this->error = 1062; return false; } }
	public function get_row( string $sql ): array|null|false { $this->factory->statements[] = $sql; return 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ? [ 'utc' => $this->factory->utc ] : false; }
	public function get_results( string $sql ): array|false {
		$this->factory->statements[] = $sql; if ( $this->factory->fail_reads ) { return false; }
		if ( str_contains( $sql, 'slot_kind=\'site_minute\'' ) ) { $this->factory->lock_order[] = 'site_minute'; } elseif ( str_contains( $sql, 'slot_kind=\'session_minute\'' ) ) { $this->factory->lock_order[] = 'session_minute'; } elseif ( str_contains( $sql, 'AND admission_namespace_hash=' ) ) { $this->factory->lock_order[] = 'admission'; }
		try { $rows = $this->factory->pdo->query( $this->sql( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ); } catch ( \Throwable ) { return false; }
		if ( str_contains( $sql, 'AND admission_namespace_hash=' ) && null !== $this->factory->after_admission_read ) { $callback = $this->factory->after_admission_read; $this->factory->after_admission_read = null; $callback(); } return $rows;
	}
	public function prepare( string $sql, mixed ...$args ): string { $i = 0; return preg_replace_callback( '/%[ds]/', function( array $match ) use ( &$i, $args ): string { $value = $args[$i++]; return '%d' === $match[0] ? (string) (int) $value : $this->factory->pdo->quote( (string) $value ); }, $sql ); }
	public function errno(): int { return $this->error; } public function insert_id(): int { return (int) $this->factory->pdo->lastInsertId(); }
	private function sql( string $sql ): string { return str_replace( [ ' FOR UPDATE', 'BINARY ' ], '', $sql ); }
}
final class AdmissionUnitControl extends EmergencyControlStore {
	public bool $paused = false; public bool $failed = false;
	public function __construct( private readonly AdmissionUnitFactory $factory ) { parent::__construct(); }
	public function assert_ready( OperationSession $session, int $site ): void { if ( $this->failed ) { throw new \RuntimeException(); } }
	public function current( OperationSession $session ): EmergencyControlState { $this->factory->lock_order[] = 'control'; if ( $this->failed ) { throw new \RuntimeException(); } return $this->paused ? EmergencyControlState::from_physical( 1, 1, EmergencyControlState::record_json( 1, 'checkout_suspended', 2, 'operator_pause', 1, 1800000000 ) ) : EmergencyControlState::absent( 1 ); }
}
