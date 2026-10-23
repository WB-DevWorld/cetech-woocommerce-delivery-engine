<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteBindInputs, FixedPromotionQuotePlacementEvidenceReader, FixedPromotionQuoteSealCommand, FixedPromotionQuoteSealedEvidence, FixedPromotionQuoteVerifyInputs};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteSchema, EmergencyControlStore, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory, QuoteFixtures as Q};
use CetechDeliveryEngine\Tests\Unit\DeliveryQuote\{PromotionAcceptMutationBudget, PromotionAcceptMutationEligibility, PromotionBindMutationOwner, PromotionBindOrderPort, PromotionMutationOwner, PromotionMutationTrace, PromotionSealProfileFixture, PromotionSealSavedPort};

require_once dirname( __DIR__, 2 ) . '/Unit/DeliveryQuote/FixedPromotionQuoteSealProfileTest.php';
require_once __DIR__ . '/PromotionQuoteAcceptSqlStack.php';

/** Actual SQLite original-five history with explicitly synthetic current, order, saved and budget ports. */
final class PromotionSealedReadFixture implements OperationConnectionFactory {
	/** Scalar rows/facts and a captureless immutable command only; never cache a live fixture or port. */
	private static array $seeds = [];
	public QuoteDurableFixtureFactory $db;
	public PromotionMutationTrace $trace;
	public PromotionAcceptMutationBudget $budget;
	public PromotionAcceptMutationEligibility $eligibility;
	public PromotionBindOrderPort $order;
	public PromotionSealSavedPort $saved;
	public FixedPromotionQuoteSealCommand $command;
	public FixedPromotionQuoteVerifyInputs $inputs;
	public array $sessions = [];
	public array $auth_operations = [];
	public bool $authorized = true;
	public bool $paused = false;
	public bool $published = true;
	public int $current_revision = 1;
	public ?\Closure $on_hook = null;
	public ?\Closure $on_authorize = null;
	public ?\Closure $on_open = null;
	public function __construct( bool $separate_accounts = false ) {
		$mode = $separate_accounts ? 1 : 0; $seed = self::$seeds[$mode] ??= self::genuine_seed( $separate_accounts );
		$this->db = new QuoteDurableFixtureFactory(); $this->trace = new PromotionMutationTrace(); $this->command = $seed['command'];
		foreach ( $seed['tables'] as $table => $rows ) { $this->db->pdo->exec( 'DELETE FROM ' . $table ); foreach ( $rows as $row ) { self::insert_row( $this->db->pdo, $table, $row ); } }
		$this->budget = new PromotionAcceptMutationBudget( $this->trace ); $this->eligibility = new PromotionAcceptMutationEligibility( $this->trace );
		foreach ( $seed['accounts'] as $key => $facts ) { $this->budget->accounts[$key] = FixedPromotionBudgetState::from_array( $facts ); }
		foreach ( $seed['claims'] as $key => $facts ) { $this->budget->claims[$key] = FixedPromotionBudgetReservation::from_array( $facts ); }
		$this->order = new PromotionBindOrderPort( $this->command->verification()->binding_command(), $this->trace ); $this->saved = new PromotionSealSavedPort( $this->command->verified_binding(), $this->trace );
		// Fresh current sources retain their original provenance, but never the cold seed's native source or owner.
		$owner = new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->db->open() ), $this->trace );
		try {
			if ( ! $owner->begin() ) { throw new \RuntimeException( 'Owned source reconstruction did not begin.' ); }
			$sources = PromotionQuoteAcceptSqlStack::sources_for( $owner, $this->command->verification()->binding_command()->acceptance() );
			if ( ! $owner->rollback() ) { throw new \RuntimeException( 'Owned source reconstruction did not close.' ); }
		} finally { $owner->retire(); }
		$this->inputs = FixedPromotionQuoteVerifyInputs::from_sources( FixedPromotionQuoteBindInputs::from_sources( FixedPromotionQuoteAcceptInputs::from_sources( $sources['original'], $sources['fresh'] ), $this->order ), $this->saved );
		$this->trace->events = []; $this->db->statements = [];
	}
	private static function genuine_seed( bool $separate_accounts ): array {
		$seed = new PromotionSealProfileFixture( $separate_accounts ); $mutation = $seed->mutate( $seed->lock() ); $command = $seed->command->as_recovery(); $at = Q::time()->plus_seconds( 5 ); $db = $seed->base->base->db;
		self::insert_row( $db->pdo, 'durable_delivery_engine_operation_records', [ 'id' => 5, 'site_id' => 1, 'namespace_hash' => $command->identity->namespace_digest(), 'intent_hash' => $command->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.seal', 'operation_version' => 2, 'target_hash' => WpdbOperationRecordRepository::target_hash( $command->identity ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $mutation->completion->to_json(), 'audit_id' => 5, 'row_version' => 3, 'created_at' => $at->sql(), 'updated_at' => $at->sql(), 'completed_at' => $at->sql() ] );
		self::insert_row( $db->pdo, 'durable_delivery_engine_operation_changes', [ 'id' => 5, 'site_id' => 1, 'operation_id' => 5, 'event_format' => 1, 'event_json' => $mutation->event->to_json(), 'created_at' => $at->sql() ] );
		$db->pdo->commit(); $seed->owner->retire();
		$tables = []; foreach ( [ ...array_map( static fn( string $suffix ): string => 'durable_delivery_engine_' . $suffix, DeliveryQuoteSchema::SUFFIXES ), 'durable_delivery_engine_operation_records', 'durable_delivery_engine_operation_changes', 'durable_fence' ] as $table ) { $tables[$table] = $db->pdo->query( 'SELECT * FROM ' . $table . ' ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); }
		return [ 'command' => $command, 'tables' => $tables, 'accounts' => array_map( static fn( $value ): array => $value->private_facts(), $seed->base->base->budget->accounts ), 'claims' => array_map( static fn( $value ): array => $value->private_facts(), $seed->base->base->budget->claims ) ];
	}
	private static function insert_row( \PDO $pdo, string $table, array $row ): void { $columns = implode( ',', array_keys( $row ) ); $placeholders = implode( ',', array_fill( 0, count( $row ), '?' ) ); $pdo->prepare( "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})" )->execute( array_values( $row ) ); }
	public function insert( string $suffix, array $row ): void { self::insert_row( $this->db->pdo, 'durable_delivery_engine_' . $suffix, $row ); }

	public function open(): OperationSession { $session = new PromotionSealedReadSession( new PromotionBindMutationOwner( new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->db->open() ), $this->trace ), $this->trace ), $this ); $this->sessions[] = $session; if ( null !== $this->on_open ) { ( $this->on_open )( $session ); } return $session; }
	public function reader(): FixedPromotionQuotePlacementEvidenceReader {
		$pre = new class implements OperationReadiness { public function assert_ready( OperationSession $s ): void { $s->hook( 'readiness' ); } };
		$control = new class( $this ) extends EmergencyControlStore { public function __construct( private PromotionSealedReadFixture $f ) {} public function assert_ready( OperationSession $s, int $site ): void { $s->hook( 'control_ready' ); } public function current( OperationSession $s ): EmergencyControlState { $this->f->trace->events[] = 'control'; $s->hook( 'control' ); $revision = $this->f->paused ? max( 2, $this->f->current_revision ) : $this->f->current_revision; return ! $this->f->paused && 1 === $revision ? EmergencyControlState::absent( 1 ) : EmergencyControlState::from_physical( 1, 1, EmergencyControlState::record_json( 1, $this->f->paused ? 'checkout_suspended' : 'enabled', $revision, $this->f->paused ? 'operator_pause' : 'resume_verified', 1, (int) ( Q::time()->epoch_microseconds() / 1000000 ) ) ); } };
		$current = new class( $this ) implements OperationReadiness { public function __construct( private PromotionSealedReadFixture $f ) {} public function assert_ready( OperationSession $s ): void { $this->f->trace->events[] = 'publication'; $s->hook( 'publication' ); if ( ! $this->f->published ) { throw new \RuntimeException( 'Synthetic publication unavailable.' ); } } };
		return new FixedPromotionQuotePlacementEvidenceReader( $this, $this->budget, $this->eligibility, function( $owner, string $operation ): bool { $this->auth_operations[] = $operation; if ( null !== $this->on_authorize ) { ( $this->on_authorize )( $owner, $operation, $this ); } return $this->authorized; }, $pre, $control, $current );
	}
	public function read( ?int $expected = null ): ?FixedPromotionQuoteSealedEvidence { return $this->reader()->current_sealed( $this->command, $this->inputs, $expected ?? $this->current_revision, RequestContext::create() ); }
	public function quote(): QuoteStoredRow { return QuoteStoredRow::from_row( $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quotes' )->fetch( \PDO::FETCH_ASSOC ) ); }
	public function binding(): QuoteBinding { return QuoteBinding::from_row( $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetch( \PDO::FETCH_ASSOC ), $this->quote() ); }
	public function bytes(): array { return [ $this->quote()->row(), $this->binding()->row(), $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_operation_records ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ), $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_operation_changes ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ), array_map( static fn( $v ): string => $v->to_private_json(), $this->budget->accounts ), array_map( static fn( $v ): string => $v->to_private_json(), $this->budget->claims ), $this->order->saved, $this->saved->packet ]; }
}

final class PromotionSealedReadSession implements OperationSession {
	public string $prefix = 'durable_'; public int $site = 1; public string $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; public string $fail = ''; public array $clock_times = []; public int $clock_reads = 0; public int $writes = 0; public array $receipt_sql = []; public int $rollback_calls = 0; public int $retire_calls = 0;
	public function __construct( public readonly PromotionBindMutationOwner $inner, private PromotionSealedReadFixture $f ) {}
	public function hook( string $phase ): void { if ( null !== $this->f->on_hook ) { ( $this->f->on_hook )( $this, $phase ); } if ( $this->fail === 'throw:' . $phase ) { throw new \RuntimeException( 'Synthetic boundary failure.' ); } }
	public function site_id(): int { $this->hook( 'site_id' ); return $this->site; } public function table_prefix(): string { $this->hook( 'table_prefix' ); return $this->prefix; } public function charset_collate(): string { $this->hook( 'charset_collate' ); return $this->charset; }
	public function begin(): bool { $this->hook( 'begin' ); return 'begin' !== $this->fail && $this->inner->begin(); }
	public function commit(): OperationCommitResult { throw new \LogicException( 'Read-only observations cannot commit.' ); }
	public function rollback(): bool { ++$this->rollback_calls; $r = $this->inner->rollback(); $this->hook( 'rollback' ); return 'rollback' !== $this->fail && $r; }
	public function retire(): bool { ++$this->retire_calls; $r = $this->inner->retire(); $this->hook( 'retire' ); return 'retire' !== $this->fail && $r; }
	public function is_retired(): bool { $this->hook( 'is_retired' ); return $this->inner->is_retired(); } public function in_transaction(): bool { $this->hook( 'in_transaction' ); return $this->inner->in_transaction(); }
	public function validate_tables( array $tables ): bool { $this->hook( 'validation' ); return 'validation' !== $this->fail && $this->inner->validate_tables( $tables ); }
	public function query( string $sql ): int|false { ++$this->writes; throw new \LogicException( 'Read-only observations cannot mutate.' ); }
	public function get_row( string $sql ): array|null|false { if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { ++$this->clock_reads; $this->hook( 'clock' ); return [ 'utc' => ( $this->clock_times[$this->clock_reads - 1] ?? Q::time()->plus_seconds( 6 ) )->sql() ]; } return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false { if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_operation_records`' ) ) { $this->f->trace->events[] = 'receipt'; $this->receipt_sql[] = $sql; $this->hook( 'receipt' ); } elseif ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_delivery_quotes`' ) ) { $this->hook( 'quote' ); } elseif ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_delivery_quote_bindings`' ) ) { $this->hook( 'binding' ); } return $this->inner->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); }
}
