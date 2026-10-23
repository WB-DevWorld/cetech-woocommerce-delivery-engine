<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetAllocationService;
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptResult, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindResult, FixedPromotionQuoteSealCommand, FixedPromotionQuoteSealResult, FixedPromotionQuoteSealedEvidence, FixedPromotionQuoteVerifyCommand as VerifyCommand, FixedPromotionQuoteVerifyResult as VerifyResult, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate};
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteId, QuoteOwner};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionQuoteAcceptSqlStack as AcceptStack, PromotionQuoteBindOrderPort as OrderPort, PromotionQuoteBindSqlStack as BindStack, PromotionQuoteVerifySavedPort as SavedPort, PromotionQuoteSealSqlStack as SealStack, PromotionQuoteSealedReadSqlStack as Stack, PromotionQuoteVerifySqlStack as VerifyStack, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as IssueStack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteSealedReadSqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Ten real sealed-promotion observation cases. Native/source/order/saved/scheduler ports are synthetic; no Woo certification. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionQuoteSealedReadRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $fixture;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix ); B::install( $this->database, $this->prefix ); A::publication( $this->database, $this->prefix, 'success' ); OrderPort::install( $this->database, $this->prefix ); SavedPort::install( $this->database, $this->prefix );
		$session = ( new PromotionQuoteIssueSqlFactory( $this->factory() ) )->open(); self::assertTrue( $session->begin() ); $this->fixture = Ports::capture( $session ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		// Both original 3.00 holds share this fully reserved 6.00 campaign account.
		$state = FixedPromotionBudgetState::create( 1, $this->fixture['slots'][0]['account_key'], F::rule()->private_facts()['funding_source'], B::money( '6.00' ) ); $values = FixedPromotionBudgetRecordCodec::account_values( $state );
		$columns = implode( ',', array_map( static fn( string $key ): string => "`{$key}`", array_keys( $values ) ) ); $quoted = array_map( fn( mixed $value ): string => "'" . $this->database->real_escape_string( (string) $value ) . "'", array_values( $values ) ); DB::execute( $this->database, "INSERT INTO `{$this->prefix}delivery_engine_promotion_budget_accounts` ({$columns}) VALUES (" . implode( ',', $quoted ) . ')' );
		// Synthetic physical queue sentinels prove observation does not dispatch or rewrite jobs.
		DB::execute( $this->database, "CREATE TABLE {$this->prefix}e16_scheduler_rows (id bigint unsigned NOT NULL PRIMARY KEY,hook varchar(191) NOT NULL,status varchar(32) NOT NULL,claim_id bigint unsigned NOT NULL,attempts int NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		DB::execute( $this->database, "INSERT INTO {$this->prefix}e16_scheduler_rows VALUES (1,'synthetic_pending_cleanup','pending',0,0),(2,'synthetic_completed_cleanup','complete',0,1)" );
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { try { $process->kill_and_wait(); } catch ( \Throwable ) { $process->kill(); } } foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function factory( int $clock = Factory::NOW ): Factory { $f = new Factory( $this->prefix ); $f->clock = $clock; $this->factories[] = $f; return $f; }
	private function accepted(): FixedPromotionQuoteAcceptResult {
		$issue = new IssueStack( $this->prefix, $this->fixture ); $this->factories[] = $issue->physical; $token = QuoteId::generate()->value(); $original = $issue->original( $token );
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, null, new EmergencyControlStore( static fn(): bool => false ) );
		$admission = $gate->admit( QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'E16 owned synthetic server draft' ), $token ), QuotePreparationAttempt::generate() );
		self::assertSame( 'preparation_allowed', $admission->status ); self::assertNotNull( $admission->lease ); self::assertTrue( $admission->lease->claim_preparation() );
		$issued = $issue->service->issue_admitted( $original, $admission->lease->bind_issue( $original ), $issue->inputs(), RequestContext::create(), static fn(): bool => true );
		self::assertSame( 'accepted', $issued->attempt->outcome->state ); self::assertNotNull( $issued->command );
		$accept = new AcceptStack( $this->prefix, $this->fixture ); $this->factories[] = $accept->physical; $accepted = $accept->service->accept( FixedPromotionQuoteAcceptCommand::from_issue( $issued->command ), $accept->inputs(), RequestContext::create() );
		self::assertSame( 'accepted', $accepted->attempt->outcome->state ); self::assertNotNull( $accepted->quote ); self::assertNotNull( $accepted->command ); return $accepted;
	}
	private function bound( int $order = 1001 ): FixedPromotionQuoteBindResult {
		$accepted = $this->accepted(); $mapping = OrderPort::mapping( $accepted->quote, $order ); $packet = OrderPort::packet( $accepted->quote, $order, $mapping );
		$command = FixedPromotionQuoteBindCommand::create( $accepted->command, $accepted->quote, $order, QuoteId::generate(), $mapping, OrderPort::digest( $packet ) );
		OrderPort::put( $this->database, $this->prefix, $packet ); $stack = new BindStack( $this->prefix, $this->fixture, $command ); $this->factories[] = $stack->physical;
		$bound = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'accepted', $bound->attempt->outcome->state ); self::assertNotNull( $bound->quote ); self::assertNotNull( $bound->binding ); self::assertNotNull( $bound->command ); self::assertSame( 1, $bound->binding->revision() ); return $bound;
	}
	private function verified( int $order = 1001 ): VerifyResult {
		$bound = $this->bound( $order );
		$command = VerifyCommand::create( $bound->command, $bound->binding, Q::digest( 'E16 owned synthetic saved snapshot' ), Q::digest( 'E16 owned synthetic saved context' ) );
		SavedPort::put( $this->database, $this->prefix, SavedPort::packet( $command->binding() ) );
		$stack = new VerifyStack( $this->prefix, $this->fixture, $command ); $this->factories[] = $stack->physical;
		$verified = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() );
		self::assertSame( 'accepted', $verified->attempt->outcome->state ); self::assertNotNull( $verified->quote ); self::assertNotNull( $verified->binding ); self::assertNotNull( $verified->command ); self::assertSame( 2, $verified->binding->revision() ); return $verified;
	}
	private function command( VerifyResult $verified, int $control_revision = 1 ): FixedPromotionQuoteSealCommand {
		return FixedPromotionQuoteSealCommand::create( $verified->command, $verified->binding, $control_revision );
	}
	private function seed(): FixedPromotionQuoteSealResult {
		$command = $this->command( $this->verified() ); $stack = new SealStack( $this->prefix, $this->fixture, $command ); $this->factories[] = $stack->physical;
		$r = $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'accepted', $r->attempt->outcome->state ); self::assertNotNull( $r->command ); self::assertNotNull( $r->quote ); self::assertNotNull( $r->binding ); self::assertSame( 3, $r->binding->revision() ); return $r;
	}
	private function stack( FixedPromotionQuoteSealCommand $command, int $clock = Factory::NOW + 5, ?callable $configure = null, int $site_id = 1 ): Stack { $s = new Stack( $this->prefix, $this->fixture, $command, $clock, $configure, $site_id ); $this->factories[] = $s->physical; return $s; }
	private function rows( string $suffix ): array { return $this->table_rows( $this->prefix . 'delivery_engine_' . $suffix ); }
	private function table_rows( string $table ): array { $r = $this->database->query( "SELECT * FROM `{$table}` ORDER BY 1" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned E16 observation census failed.' ); } return $r->fetch_all( MYSQLI_ASSOC ); }
	private function census(): array {
		$out = []; foreach ( [ 'delivery_quotes', 'delivery_quote_bindings', 'promotion_budget_accounts', 'promotion_budget_reservations', 'delivery_quote_budget_windows', 'operation_records', 'operation_changes' ] as $suffix ) { $out[$suffix] = $this->rows( $suffix ); }
		foreach ( [ 'options', 'e13_candidate_orders', 'e14_saved_snapshots', 'e16_scheduler_rows' ] as $suffix ) { $out[$suffix] = $this->table_rows( $this->prefix . $suffix ); } return $out;
	}
	private function unchanged( array $before, Stack $stack ): void { self::assertSame( $before, $this->census() ); self::assertSame( [], $stack->effect_sql() ); }
	private function read( FixedPromotionQuoteSealCommand $command, Stack $stack, int $revision = 1 ): ?FixedPromotionQuoteSealedEvidence { return $stack->reader->current_sealed( $command, $stack->inputs(), $revision, RequestContext::create() ); }
	private function unavailable( FixedPromotionQuoteSealCommand $command, Stack $stack, array $before, int $revision = 1 ): void { self::assertNull( $this->read( $command, $stack, $revision ) ); $this->unchanged( $before, $stack ); }
	private function control( string $state, int $revision = 2 ): void {
		$json = EmergencyControlState::record_json( 1, $state, $revision, 'enabled' === $state ? 'resume_verified' : 'operator_pause', 9, Factory::NOW + 5 );
		$s = $this->database->prepare( "INSERT INTO {$this->prefix}options (option_name,option_value) VALUES ('cetech_de_checkout_control_v1',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" ); self::assertNotFalse( $s ); self::assertTrue( $s->bind_param( 's', $json ) ); self::assertTrue( $s->execute() ); $s->close();
	}
	private function allocation( FixedPromotionQuoteSealCommand $command, string $action, int $offset = 6 ): void {
		$s = $this->factory()->open(); self::assertTrue( $s->begin() ); $service = new FixedPromotionBudgetAllocationService( new SqlFixedPromotionBudgetStore(), static fn(): bool => true );
		$r = 'commit' === $action ? $service->commit_all( $s, $command->allocation_set(), Q::time()->plus_seconds( $offset ) ) : $service->release_all( $s, $command->allocation_set(), Q::time()->plus_seconds( $offset ) ); self::assertCount( 2, $r ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); self::assertTrue( $s->retire() );
	}
	private function command_file( FixedPromotionQuoteSealCommand $command ): string {
		$directory = OperationProofBarrier::directory(); $this->directories[] = $directory; $file = $directory . '/sealed-read-recovery.json'; $bytes = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ); self::assertSame( strlen( $bytes ), file_put_contents( $file, $bytes, LOCK_EX ) ); self::assertTrue( chmod( $file, 0600 ) ); return $file;
	}
	private function worker( string $file, string $role = 'read', int $revision = 1, string $kind = 'control', int $clock = Factory::NOW + 5 ): OperationProofProcess {
		$p = new OperationProofProcess( [ __DIR__ . '/promotion-quote-sealed-read-process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'command_file' => $file, 'role' => $role, 'revision' => $revision, 'kind' => $kind, 'clock' => $clock ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p;
	}
	private function finish( OperationProofProcess $p ): array { $result = $p->finish(); $this->processes = array_values( array_filter( $this->processes, static fn( OperationProofProcess $active ): bool => $active !== $p ) ); return $result; }
	private function wait_for_lock( int $waiter, int $blocker, string $table ): void {
		self::assertNotSame( $waiter, $blocker ); $end = microtime( true ) + 1.5; $waiting = false;
		$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
		// Allow the MariaDB observation cache to refresh without extending the existing deadline.
		do { $waiting = (int) DB::scalar( $this->database, $sql ) > 0; if ( $waiting ) { break; } $remaining = $end - microtime( true ); if ( $remaining <= 0 ) { break; } usleep( (int) min( 120000, ceil( $remaining * 1000000 ) ) ); } while ( microtime( true ) < $end ); self::assertTrue( $waiting, 'The exact physical writer must wait for the reader on the declared table.' );
	}

	public function test_genuine_sealed_history_returns_detached_current_observation_and_preserves_every_owned_row(): void {
		$sealed = $this->seed(); $command = $sealed->command; $this->control( 'enabled' ); $before = $this->census(); $captures = $this->fixture['provider']->captures; $reads = $this->fixture['source']->reads; $stack = $this->stack( $command );
		$e = $this->read( $command, $stack, 2 ); self::assertNotNull( $e ); self::assertSame( $sealed->quote->row(), $e->quote_record()->row() ); self::assertSame( $sealed->binding->row(), $e->binding()->row() ); self::assertSame( Q::time()->plus_seconds( 5 )->sql(), $e->evaluated_at()->sql() ); self::assertSame( $sealed->binding->row()['sealed_at'], $e->sealed_at()->sql() ); self::assertSame( 2, $e->control_revision() ); self::assertSame( 1, $command->control_revision() ); self::assertTrue( $e->validity()->valid_at( $e->evaluated_at() ) );
		self::assertSame( $captures, $this->fixture['provider']->captures ); self::assertSame( $reads, $this->fixture['source']->reads ); self::assertCount( 1, $stack->factory->sessions ); self::assertTrue( $stack->factory->sessions[0]->is_retired() ); self::assertFalse( $stack->factory->sessions[0]->in_transaction() ); self::assertCount( 5, $before['operation_records'] ); self::assertCount( 5, $before['operation_changes'] ); self::assertSame( [ 'held', 'held' ], array_column( $before['promotion_budget_reservations'], 'state' ) ); self::assertSame( '0.00', FixedPromotionBudgetRecordCodec::account_from_row( $before['promotion_budget_accounts'][0] )->remaining()->amount() ); $this->unchanged( $before, $stack );
		$this->unavailable( $command, $this->stack( $command ), $before, 1 );
	}

	public function test_independent_writer_processes_wait_for_current_control_and_saved_locks_until_reader_cleanup(): void {
		$sealed = $this->seed(); $command = $sealed->command; $clean = $this->stack( $command );
		foreach ( [ 'control', 'saved' ] as $kind ) {
			$this->control( 'enabled' ); SavedPort::put( $this->database, $this->prefix, $clean->saved->captured ); $before = $this->census(); $file = $this->command_file( $command ); $d = dirname( $file );
			$reader = $this->worker( $file, 'reader-left', 2, $kind ); OperationProofProcess::wait_for( $d . '/reader-held' ); $held = json_decode( (string) file_get_contents( $d . '/reader-held' ), true, 4, JSON_THROW_ON_ERROR );
			$writer = $this->worker( $file, 'writer-right', 2, $kind ); OperationProofProcess::wait_for( $d . '/writer-dispatched' ); $waiting = json_decode( (string) file_get_contents( $d . '/writer-dispatched' ), true, 4, JSON_THROW_ON_ERROR ); $table = $this->prefix . ( 'control' === $kind ? 'options' : 'e14_saved_snapshots' ); self::assertSame( $table, $waiting['table'] ); $this->wait_for_lock( $waiting['connection_id'], $held['connection_id'], $table ); OperationProofBarrier::signal( $d . '/release' );
			$a = $this->finish( $reader ); $b = $this->finish( $writer ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertNotSame( getmypid(), $a['process_id'] ); self::assertTrue( $a['ready'] ); self::assertSame( [], $a['effect_sql'] ); self::assertTrue( $a['retired'] ); self::assertFalse( $a['in_transaction'] ); self::assertSame( 1, $b['changed'] ); self::assertSame( $waiting['connection_id'], $b['connection_id'] );
			$after = $this->census(); foreach ( array_diff( array_keys( $before ), [ 'options', 'e14_saved_snapshots' ] ) as $key ) { self::assertSame( $before[$key], $after[$key] ); } $this->unavailable( $command, $this->stack( $command ), $after, 2 );
		}
	}

	public function test_current_publication_and_control_locks_refuse_changes_hidden_from_old_repeatable_read(): void {
		$command = $this->seed()->command;
		foreach ( [ 'publication', 'control' ] as $kind ) {
			A::publication( $this->database, $this->prefix, 'success' ); $this->control( 'enabled' ); $expected = $this->census(); $changed = false; $old = null;
			$stack = $this->stack( $command, configure: function( Transport $t ) use ( $kind, &$changed, &$old, &$expected ): void { $t->before = function( string $sql, Transport $owner ) use ( $kind, &$changed, &$old, &$expected ): void {
				$target = 'publication' === $kind ? str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) : str_starts_with( $sql, 'SELECT option_id, option_name, CASE WHEN' ); if ( $changed || ! $target || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true;
				$name = 'publication' === $kind ? MigrationStatus::OPTION_NAME : 'cetech_de_checkout_control_v1'; $name = $this->database->real_escape_string( $name ); $select = "SELECT option_value FROM {$this->prefix}options WHERE option_name='{$name}'"; $r = $owner->native_execute( $select ); self::assertTrue( $r->acknowledged ); $old = $r->rows[0]['option_value'];
				if ( 'publication' === $kind ) { A::publication( $this->database, $this->prefix, 'running' ); } else { $this->control( 'checkout_suspended', 3 ); } $expected = $this->census(); $again = $owner->native_execute( $select ); self::assertSame( $old, $again->rows[0]['option_value'] );
			}; } );
			self::assertNull( $this->read( $command, $stack, 2 ) ); self::assertTrue( $changed ); self::assertNotNull( $old ); $this->unchanged( $expected, $stack );
		}
	}

	public function test_current_claim_locks_refuse_a_separately_committed_release_despite_old_snapshot(): void {
		$command = $this->seed()->command; $expected = $this->census(); $changed = false; $old = null;
		$stack = $this->stack( $command, configure: function( Transport $t ) use ( $command, &$expected, &$changed, &$old ): void { $t->before = function( string $sql, Transport $owner ) use ( $command, &$expected, &$changed, &$old ): void {
			if ( $changed || ! str_contains( $sql, 'delivery_engine_promotion_budget_accounts' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $select = "SELECT state FROM {$this->prefix}delivery_engine_promotion_budget_reservations ORDER BY id"; $snapshot = $owner->native_execute( $select ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows; $this->allocation( $command, 'release' ); $expected = $this->census(); self::assertSame( $old, $owner->native_execute( $select )->rows );
		}; } );
		self::assertNull( $this->read( $command, $stack ) ); self::assertTrue( $changed ); self::assertSame( [ [ 'state' => 'held' ], [ 'state' => 'held' ] ], $old ); self::assertSame( [ 'released', 'released' ], array_column( $expected['promotion_budget_reservations'], 'state' ) ); $this->unchanged( $expected, $stack );
	}

	public function test_current_order_and_saved_packets_refuse_new_rows_hidden_from_old_snapshot(): void {
		$command = $this->seed()->command; $clean = $this->stack( $command );
		foreach ( [ 'order', 'saved' ] as $kind ) {
			OrderPort::put( $this->database, $this->prefix, $clean->order->captured ); SavedPort::put( $this->database, $this->prefix, $clean->saved->captured ); $expected = $this->census(); $changed = false; $old = null; $stack = $this->stack( $command ); $port = 'order' === $kind ? $stack->order : $stack->saved; $table = 'order' === $kind ? OrderPort::table( $this->prefix ) : SavedPort::table( $this->prefix );
			$port->on_proof = function( $session, int $proof ) use ( $kind, $stack, $port, $table, &$changed, &$old, &$expected ): void {
				if ( 1 !== $proof ) { return; } $changed = true; $owner = $stack->physical->transports[0]; $select = "SELECT packet_json FROM {$table}"; $snapshot = $owner->native_execute( $select ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows[0]['packet_json']; $packet = $port->captured;
				if ( 'order' === $kind ) { $packet['mapping']['groups'][0]['lines'][0]['quantity'] = '99'; OrderPort::put( $this->database, $this->prefix, $packet ); } else { $packet['snapshot_digest'] = Q::digest( 'E16 current separately committed saved packet' ); SavedPort::put( $this->database, $this->prefix, $packet ); } $expected = $this->census(); self::assertSame( $old, $owner->native_execute( $select )->rows[0]['packet_json'] );
			};
			self::assertNull( $this->read( $command, $stack ) ); self::assertTrue( $changed ); self::assertNotNull( $old ); $this->unchanged( $expected, $stack );
		}
	}

	public function test_all_five_original_receipts_and_exact_physical_sealed_membership_are_required_before_exposure(): void {
		$sealed = $this->seed(); $command = $sealed->command;
		foreach ( [ 'delivery_quote.issue', 'delivery_quote.accept', 'delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal' ] as $operation ) {
			$table = $this->prefix . 'delivery_engine_operation_records'; $op = $this->database->real_escape_string( $operation ); $row = DB::row( $this->database, "SELECT id,intent_hash FROM {$table} WHERE operation='{$op}'" ); $id = (int) $row['id']; DB::execute( $this->database, "UPDATE {$table} SET intent_hash='" . Q::digest( 'E16 corrupt original intent' ) . "' WHERE id={$id}" );
			try { $stack = $this->stack( $command ); $this->unavailable( $command, $stack, $this->census() ); self::assertCount( 0, $stack->eligibility->owners ); self::assertCount( 0, $stack->order->owners ); self::assertCount( 0, $stack->saved->owners ); } finally { DB::execute( $this->database, "UPDATE {$table} SET intent_hash='" . $row['intent_hash'] . "' WHERE id={$id}" ); }
		}
		foreach ( $this->rows( 'operation_changes' ) as $event ) {
			$id = (int) $event['id']; $table = $this->prefix . 'delivery_engine_operation_changes'; DB::execute( $this->database, "UPDATE {$table} SET created_at='" . Q::time()->plus_seconds( -1 )->sql() . "' WHERE id={$id}" );
			try { $stack = $this->stack( $command ); $this->unavailable( $command, $stack, $this->census() ); self::assertCount( 0, $stack->order->owners ); self::assertCount( 0, $stack->saved->owners ); } finally { DB::execute( $this->database, "UPDATE {$table} SET created_at='" . $this->database->real_escape_string( $event['created_at'] ) . "' WHERE id={$id}" ); }
		}
		foreach ( [ 'snapshot_digest', 'context_digest', 'native_money_digest', 'created_at', 'verified_at', 'sealed_at', 'id' ] as $field ) {
			$row = $sealed->binding->row(); $value = match ( $field ) { 'id' => (string) ( $row['id'] + 100 ), 'created_at' => Q::time()->plus_seconds( 3 )->sql(), 'verified_at' => Q::time()->plus_seconds( 4 )->sql(), 'sealed_at' => Q::time()->plus_seconds( 5 )->sql(), default => Q::digest( 'E16 different physical member' ) }; $table = $this->prefix . 'delivery_engine_delivery_quote_bindings'; $quote = $command->header()->id()->value(); DB::execute( $this->database, "UPDATE {$table} SET `{$field}`='" . $this->database->real_escape_string( $value ) . "' WHERE quote_uuid='{$quote}'" );
			try { $this->unavailable( $command, $this->stack( $command ), $this->census() ); } finally { DB::execute( $this->database, "UPDATE {$table} SET `{$field}`='" . $this->database->real_escape_string( (string) $row[$field] ) . "' WHERE quote_uuid='{$quote}'" ); }
		}
	}

	public function test_current_source_policy_order_saved_and_captured_digest_fail_closed_without_repair(): void {
		$command = $this->seed()->command; $before = $this->census();
		foreach ( [ 'source', 'policy', 'order', 'saved', 'initial_digest', 'saved_replaces_digest', 'late_policy', 'late_source', 'foreign_owner' ] as $kind ) {
			$stack = $this->stack( $command, site_id: 'foreign_owner' === $kind ? 2 : 1 );
			if ( 'source' === $kind ) { $this->fixture['source']->same = false; } elseif ( 'policy' === $kind ) { $stack->eligibility->allow = false; } elseif ( 'order' === $kind ) { $stack->order->allow = false; } elseif ( 'saved' === $kind ) { $stack->saved->allow = false; } elseif ( 'initial_digest' === $kind ) { $stack->order->digest_override = Q::digest( 'E16 wrong captured order' ); } elseif ( 'saved_replaces_digest' === $kind ) { $stack->saved->on_proof = static function() use ( $stack ): void { $stack->order->digest_override = Q::digest( 'E16 replaced after saved proof' ); }; } elseif ( 'late_policy' === $kind ) { $stack->saved->on_proof = static function( $session, int $proof ) use ( $stack ): void { if ( 1 === $proof ) { $stack->eligibility->allow = false; } }; } elseif ( 'late_source' === $kind ) { $stack->saved->on_proof = function() : void { $this->fixture['source']->same = false; }; }
			try { $this->unavailable( $command, $stack, $before ); } finally { $this->fixture['source']->same = true; }
		}
	}

	public function test_original_deadlines_actual_seal_floor_and_late_callback_clock_are_preserved(): void {
		$command = $this->seed()->command; $before = $this->census();
		foreach ( [ 'hold_deadline', 'before_seal', 'late_deadline', 'late_reversed', 'initial_policy_deadline' ] as $kind ) {
			$clock = match ( $kind ) { 'hold_deadline' => Factory::NOW + 45, 'before_seal' => Factory::NOW + 3, default => Factory::NOW + 5 }; $stack = $this->stack( $command, $clock );
			if ( 'late_deadline' === $kind || 'late_reversed' === $kind ) { $stack->saved->on_proof = static function( $session, int $proof ) use ( $stack, $kind ): void { if ( 2 === $proof ) { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + ( 'late_deadline' === $kind ? 45 : 3 ) ) )->acknowledged ); } }; }
			if ( 'initial_policy_deadline' === $kind ) { $stack->eligibility->on_proof = static function() use ( $stack ): void { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + 45 ) )->acknowledged ); }; }
			$this->unavailable( $command, $stack, $before ); if ( 'late_deadline' === $kind || 'late_reversed' === $kind ) { self::assertGreaterThanOrEqual( 2, count( $stack->saved->owners ) ); }
		}
	}

	public function test_owner_loss_cleanup_acknowledgment_and_corrupt_current_rows_never_expose_evidence(): void {
		$command = $this->seed()->command; $before = $this->census();
		foreach ( [ 'rollback_ack', 'retirement', 'owner_lost', 'late_authorization', 'zero_revision' ] as $kind ) {
			$stack = $this->stack( $command, configure: static function( Transport $t ) use ( $kind ): void { if ( 'rollback_ack' === $kind ) { $t->lose_rollback_ack = true; } elseif ( 'retirement' === $kind ) { $t->reject_close = true; } } );
			if ( 'owner_lost' === $kind ) { $stack->saved->on_proof = static function() use ( $stack ): void { $stack->physical->transports[0]->force_close(); }; } elseif ( 'late_authorization' === $kind ) { $stack->saved->on_proof = static function() use ( $stack ): void { $stack->allow = false; }; }
			$this->unavailable( $command, $stack, $before, 'zero_revision' === $kind ? 0 : 1 ); $stack->physical->close_all();
		}
		foreach ( [ 'promotion_budget_accounts' => 'state_digest', 'promotion_budget_reservations' => 'reservation_digest' ] as $suffix => $field ) {
			$row = $this->rows( $suffix )[0]; $table = $this->prefix . 'delivery_engine_' . $suffix; $id = (int) $row['id']; DB::execute( $this->database, "UPDATE {$table} SET {$field}='" . Q::digest( 'E16 malformed mirror' ) . "' WHERE id={$id}" );
			try { $this->unavailable( $command, $this->stack( $command ), $this->census() ); } finally { DB::execute( $this->database, "UPDATE {$table} SET {$field}='" . $row[$field] . "' WHERE id={$id}" ); }
		}
	}

	public function test_canonical_recovery_is_reread_in_fresh_process_and_expiry_or_release_never_becomes_history_admission(): void {
		$sealed = $this->seed(); $command = $sealed->command; $file = $this->command_file( $command ); $before = $this->census(); $out = $this->finish( $this->worker( $file ) );
		self::assertNotSame( getmypid(), $out['process_id'] ); self::assertTrue( $out['ready'] ); self::assertSame( $sealed->binding->id(), $out['binding_id'] ); self::assertSame( 3, $out['binding_revision'] ); self::assertSame( $sealed->binding->row()['sealed_at'], $out['sealed_at'] ); self::assertSame( $command->snapshot_digest(), $out['snapshot_digest'] ); self::assertSame( $command->context_digest(), $out['context_digest'] ); self::assertSame( [], $out['effect_sql'] ); self::assertTrue( $out['retired'] ); self::assertFalse( $out['in_transaction'] ); self::assertSame( 1, $out['synthetic_native_captures'] ); self::assertSame( $before, $this->census() );
		$this->allocation( $command, 'release', 400 ); $released = $this->census(); $later = $this->finish( $this->worker( $file, clock: Factory::NOW + 400 ) ); self::assertFalse( $later['ready'] ); self::assertSame( [], $later['effect_sql'] ); self::assertSame( $released, $this->census() ); $history = $this->finish( $this->worker( $file, 'history', clock: Factory::NOW + 400 ) ); self::assertSame( 'accepted', $history['state'] ); self::assertSame( $sealed->binding->id(), $history['binding_id'] ); self::assertSame( 0, $history['current_proofs'] ); self::assertSame( 0, $history['protected_writes'] ); self::assertSame( 0, $history['binding_updates'] ); self::assertSame( $released, $this->census() );
	}
}
