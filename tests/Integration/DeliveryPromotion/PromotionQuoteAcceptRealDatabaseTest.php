<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetAllocationService;
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptResult, FixedPromotionQuoteIssueResult, QuoteDurableCommand, QuoteDurableService, QuoteOperationProfile, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate, QuoteProviderRegistry};
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteId, QuoteOwner};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionQuoteAcceptSqlStack as Stack, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as IssueStack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteAcceptSqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Twelve real acceptance/C03/ledger preservation cases. Source/native/eligibility observations are explicitly synthetic. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionQuoteAcceptRealDatabaseTest extends TestCase {

	private \mysqli $database;
	private string $prefix;
	private array $fixture;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix ); B::install( $this->database, $this->prefix ); A::publication( $this->database, $this->prefix, 'success' );
		$session = ( new PromotionQuoteIssueSqlFactory( $this->factory() ) )->open(); self::assertTrue( $session->begin() ); $this->fixture = Ports::capture( $session ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		$state = FixedPromotionBudgetState::create( 1, $this->fixture['slots'][0]['account_key'], F::rule()->private_facts()['funding_source'], B::money( '9.00' ) ); $values = FixedPromotionBudgetRecordCodec::account_values( $state );
		$columns = implode( ',', array_map( static fn( string $key ): string => "`{$key}`", array_keys( $values ) ) ); $quoted = array_map( fn( mixed $value ): string => "'" . $this->database->real_escape_string( (string) $value ) . "'", array_values( $values ) ); DB::execute( $this->database, "INSERT INTO `{$this->prefix}delivery_engine_promotion_budget_accounts` ({$columns}) VALUES (" . implode( ',', $quoted ) . ')' );
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); } foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function factory( int $clock = Factory::NOW ): Factory { $factory = new Factory( $this->prefix ); $factory->clock = $clock; $this->factories[] = $factory; return $factory; }
	private function stack( int $clock = Factory::NOW + 1, ?callable $configure = null, int $site_id = 1 ): Stack { $stack = new Stack( $this->prefix, $this->fixture, $clock, $configure, $site_id ); $this->factories[] = $stack->physical; return $stack; }
	private function seed(): FixedPromotionQuoteIssueResult {
		$stack = new IssueStack( $this->prefix, $this->fixture ); $this->factories[] = $stack->physical; $token = QuoteId::generate()->value(); $issue = $stack->original( $token );
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, null, new EmergencyControlStore( static fn(): bool => false ) );
		$admission = $gate->admit( QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'E11 owned synthetic server draft' ), $token ), QuotePreparationAttempt::generate() );
		self::assertSame( 'preparation_allowed', $admission->status ); self::assertNotNull( $admission->lease ); self::assertTrue( $admission->lease->claim_preparation() );
		$result = $stack->service->issue_admitted( $issue, $admission->lease->bind_issue( $issue ), $stack->inputs(), RequestContext::create(), static fn(): bool => true );
		self::assertSame( 'accepted', $result->attempt->outcome->state ); self::assertNotNull( $result->quote ); self::assertNotNull( $result->command ); self::assertNull( $result->command->lease() ); return $result;
	}
	private function rows( string $suffix ): array { $r = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned acceptance census failed.' ); } return $r->fetch_all( MYSQLI_ASSOC ); }
	private function protected_bytes(): array { $out = []; foreach ( [ 'promotion_budget_accounts', 'promotion_budget_reservations', 'delivery_quote_budget_windows' ] as $suffix ) { $out[$suffix] = $this->rows( $suffix ); } return $out; }
	private function effects(): array { return [ 'quotes' => $this->rows( 'delivery_quotes' ), 'events' => $this->rows( 'operation_changes' ), 'protected' => $this->protected_bytes() ]; }
	private function accepted( FixedPromotionQuoteAcceptResult $result ): void { self::assertSame( 'accepted', $result->attempt->outcome->state, $result->attempt->outcome->error?->code ?? '' ); self::assertTrue( $result->attempt->outcome->mutation_accepted ); self::assertNotNull( $result->quote ); self::assertSame( 'accepted', $result->quote->state() ); self::assertSame( 2, $result->quote->revision() ); self::assertNotNull( $result->quote->accepted_at() ); }
	private function no_accept( array $before ): void {
		self::assertSame( $before, $this->effects() );
		foreach ( $this->rows( 'operation_records' ) as $row ) { if ( 'delivery_quote.accept' === $row['operation'] ) { self::assertNotSame( 'accepted', $row['state'] ); self::assertNull( $row['audit_id'] ); } }
	}
	private function no_ledger_writes( Stack $stack ): void {
		foreach ( $stack->physical->transports as $transport ) { self::assertSame( [], array_values( array_filter( $transport->sql, static fn( string $sql ): bool => 1 === preg_match( '/\A(?:INSERT INTO|UPDATE|DELETE FROM) /', $sql ) && 1 === preg_match( '/delivery_engine_(?:promotion_budget_accounts|promotion_budget_reservations|delivery_quote_budget_windows)/', $sql ) ) ) ); }
	}
	private function release( FixedPromotionQuoteAcceptCommand $command, int $offset = 1 ): void {
		$session = $this->factory()->open(); self::assertTrue( $session->begin() );
		$released = ( new FixedPromotionBudgetAllocationService( new SqlFixedPromotionBudgetStore(), static fn(): bool => true ) )->release_all( $session, $command->allocation_set(), Q::time()->plus_seconds( $offset ) ); self::assertCount( 2, $released ); self::assertSame( [ 'released', 'released' ], array_map( static fn( $r ): string => $r->state(), $released ) );
		self::assertSame( OperationCommitResult::Acknowledged, $session->commit() ); self::assertTrue( $session->retire() );
	}
	private function control( string $state ): void {
		$json = EmergencyControlState::record_json( 1, $state, 2, 'enabled' === $state ? 'resume_verified' : 'operator_pause', 9, Factory::NOW );
		$s = $this->database->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value) VALUES ('cetech_de_checkout_control_v1',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" ); self::assertNotFalse( $s ); self::assertTrue( $s->bind_param( 's', $json ) ); self::assertTrue( $s->execute() ); $s->close();
	}
	private function command_file( FixedPromotionQuoteAcceptCommand $command ): string {
		$directory = OperationProofBarrier::directory(); $this->directories[] = $directory; $file = $directory . '/accept-recovery.json'; $bytes = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ); self::assertSame( strlen( $bytes ), file_put_contents( $file, $bytes, LOCK_EX ) ); self::assertTrue( chmod( $file, 0600 ) ); return $file;
	}
	private function worker( string $file, string $mode = 'reconcile', string $role = 'history', int $clock = Factory::NOW + 1 ): OperationProofProcess {
		$p = new OperationProofProcess( [ __DIR__ . '/promotion-quote-accept-process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'command_file' => $file, 'mode' => $mode, 'role' => $role, 'clock' => $clock ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p;
	}
	private function wait_for_wait( int $waiter, int $blocker ): void {
		self::assertNotSame( $waiter, $blocker ); $table = $this->prefix . 'delivery_engine_operation_records'; $end = microtime( true ) + 3.0; $waiting = false;
		$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
		do { $waiting = (int) DB::scalar( $this->database, $sql ) > 0; if ( $waiting ) { break; } usleep( 10000 ); } while ( microtime( true ) < $end ); self::assertTrue( $waiting, 'The second actual accepting process must wait on the first operation owner.' );
	}
	private function fail_after_quote( string $kind ): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $before = $this->effects(); $stack = $this->stack( configure: static function( Transport $transport ) use ( $kind ): void { $transport->{'reject_' . $kind} = true; } );
		$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->no_accept( $before ); $this->no_ledger_writes( $stack );
		$quote = null; $failure = null; foreach ( $stack->physical->transports[0]->sql as $index => $sql ) {
			if ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'delivery_engine_delivery_quotes' ) ) { $quote ??= $index; }
			if ( 'audit' === $kind ? str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'delivery_engine_operation_changes' ) : str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'delivery_engine_operation_records' ) && str_contains( $sql, 'completion_json =' ) ) { $failure ??= $index; }
		}
		self::assertNotNull( $quote ); self::assertNotNull( $failure ); self::assertLessThan( $failure, $quote ); self::assertSame( 1, $stack->physical->transports[0]->quote_writes, 'The acceptance CAS must have succeeded before the injected later failure.' );
	}

	public function test_atomic_acceptance_and_exact_replay_leave_all_budget_and_admission_bytes_unchanged(): void {
		$issued = $this->seed(); $command = FixedPromotionQuoteAcceptCommand::from_issue( $issued->command ); $protected = $this->protected_bytes(); $captures = $this->fixture['provider']->captures; $reads = $this->fixture['source']->reads; $stack = $this->stack();
		$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); $this->accepted( $result ); self::assertSame( $protected, $this->protected_bytes() ); $this->no_ledger_writes( $stack ); self::assertSame( $captures, $this->fixture['provider']->captures ); self::assertSame( $reads, $this->fixture['source']->reads );
		self::assertCount( 2, $this->rows( 'operation_records' ) ); self::assertCount( 2, $this->rows( 'operation_changes' ) ); $event = json_decode( $this->rows( 'operation_changes' )[1]['event_json'], true, 16, JSON_THROW_ON_ERROR ); self::assertSame( [ 'quote' ], $event['changed_fields'] ); self::assertSame( 'quote_accepted', $event['reason_code'] ); self::assertSame( 1, $event['operation_version'] );
		$before = $this->effects(); $replay = $stack->service->reconcile( $command, RequestContext::create() ); $this->accepted( $replay ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( $result->quote->accepted_at()->sql(), $replay->quote->accepted_at()->sql() ); self::assertSame( $before, $this->effects() );
		foreach ( $stack->eligibility->owners as $owner ) { self::assertSame( $stack->factory->sessions[0], $owner ); }
	}
	public function test_two_actual_accepting_processes_serialize_to_one_acceptance_event(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $protected = $this->protected_bytes(); $file = $this->command_file( $command ); $directory = dirname( $file );
		$left = $this->worker( $file, 'accept', 'left' ); OperationProofProcess::wait_for( $directory . '/left-held' ); $held = json_decode( (string) file_get_contents( $directory . '/left-held' ), true, 4, JSON_THROW_ON_ERROR );
		$right = $this->worker( $file, 'accept', 'right' ); OperationProofProcess::wait_for( $directory . '/right-dispatched' ); $waiting = json_decode( (string) file_get_contents( $directory . '/right-dispatched' ), true, 4, JSON_THROW_ON_ERROR ); $this->wait_for_wait( $waiting['connection_id'], $held['connection_id'] ); OperationProofBarrier::signal( $directory . '/release' );
		$a = $left->finish(); $b = $right->finish(); self::assertNotSame( getmypid(), $a['process_id'] ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertSame( [ 'accepted', 'accepted' ], [ $a['state'], $b['state'] ] ); self::assertSame( 1, (int) $a['replayed'] + (int) $b['replayed'] ); self::assertSame( $a['accepted_at'], $b['accepted_at'] ); self::assertSame( 1, $a['quote_writes'] + $b['quote_writes'] ); self::assertSame( 0, $a['ledger_writes'] + $b['ledger_writes'] ); self::assertSame( 1, $a['native_input_reads'] ); self::assertSame( 1, $b['native_input_reads'] ); self::assertCount( 2, $this->rows( 'operation_changes' ) ); self::assertSame( $protected, $this->protected_bytes() );
	}
	public function test_a_released_original_claim_refuses_acceptance_without_reallocation(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $this->release( $command ); $before = $this->effects(); $stack = $this->stack();
		$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->no_accept( $before ); $this->no_ledger_writes( $stack );
	}
	public function test_current_publication_escapes_an_old_repeatable_read_snapshot(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $before = $this->effects(); $changed = false; $old = null; $name = $this->database->real_escape_string( MigrationStatus::OPTION_NAME );
		$stack = $this->stack( configure: function( Transport $transport ) use ( &$changed, &$old, $name ): void { $transport->before = function( string $sql, Transport $owner ) use ( &$changed, &$old, $name ): void {
			if ( $changed || ! str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $snapshot = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows[0]['option_value']; A::publication( $this->database, $this->prefix, 'running' ); $again = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertSame( $old, $again->rows[0]['option_value'] );
		}; } );
		$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( 'success', unserialize( $old, [ 'allowed_classes' => false ] )['status'] ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->no_accept( $before ); $this->no_ledger_writes( $stack );
	}
	public function test_pause_changed_source_and_current_eligibility_denial_do_not_accept(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $before = $this->effects();
		foreach ( [ 'pause', 'source', 'eligibility' ] as $kind ) {
			$stack = $this->stack(); if ( 'pause' === $kind ) { $this->control( 'checkout_suspended' ); } elseif ( 'source' === $kind ) { $this->fixture['source']->same = false; } else { $stack->eligibility->allow = false; }
			$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted, $kind ); $this->no_accept( $before ); $this->no_ledger_writes( $stack ); $this->fixture['source']->same = true; $this->control( 'enabled' );
		}
	}
	public function test_exact_deadline_and_slow_final_proof_refuse_before_the_quote_cas(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $before = $this->effects();
		foreach ( [ 'deadline', 'slow' ] as $kind ) {
			$stack = $this->stack( 'deadline' === $kind ? Factory::NOW + 45 : Factory::NOW + 1 );
			if ( 'slow' === $kind ) { $stack->eligibility->on_proof = static function( $session, int $proof ) use ( $stack ): void { if ( 2 === $proof ) { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + 45 ) )->acknowledged ); } }; }
			$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted, $kind ); $this->no_accept( $before ); self::assertSame( 0, $stack->physical->transports[0]->quote_writes ); $this->no_ledger_writes( $stack ); if ( 'slow' === $kind ) { self::assertGreaterThanOrEqual( 2, count( $stack->eligibility->owners ) ); }
		}
	}
	public function test_actual_quote_compare_and_swap_miss_rolls_back_the_owned_sabotage(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $before = $this->effects(); $reached = false;
		$stack = $this->stack( configure: function( Transport $transport ) use ( &$reached ): void { $transport->before = function( string $sql, Transport $owner ) use ( &$reached ): void { if ( $reached || ! str_starts_with( $sql, 'UPDATE ' ) || ! str_contains( $sql, 'delivery_engine_delivery_quotes' ) ) { return; } $reached = true; self::assertTrue( $owner->native_execute( "UPDATE `{$this->prefix}delivery_engine_delivery_quotes` SET revision=revision+1" )->acknowledged ); }; } );
		$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $reached ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->no_accept( $before ); $this->no_ledger_writes( $stack );
	}
	public function test_audit_append_failure_rolls_back_the_staged_accepted_quote(): void { $this->fail_after_quote( 'audit' ); }
	public function test_completion_failure_rolls_back_quote_and_acceptance_event(): void { $this->fail_after_quote( 'completion' ); }
	public function test_lost_effect_commit_acknowledgement_recovers_in_a_fresh_process(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $file = $this->command_file( $command ); $protected = $this->protected_bytes(); $first = true;
		$stack = $this->stack( configure: static function( Transport $transport ) use ( &$first ): void { if ( $first ) { $first = false; $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } } );
		$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->quote ); self::assertTrue( $stack->physical->sessions[0]->is_retired() ); self::assertSame( 'accepted', $this->rows( 'delivery_quotes' )[0]['state'] ); self::assertSame( $protected, $this->protected_bytes() );
		$before = $this->effects(); $history = $this->worker( $file )->finish(); self::assertNotSame( getmypid(), $history['process_id'] ); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( [ 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['quote_writes'], $history['ledger_writes'], $history['native_input_reads'] ] ); self::assertSame( $command->header()->to_private_json(), $history['header_json'] ); self::assertSame( $before, $this->effects() ); self::assertSame( 'published', $this->rows( 'operation_records' )[1]['publication_state'] );
	}
	public function test_publication_retry_after_expiry_and_release_is_history_only_and_idempotent(): void {
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $this->seed()->command ); $protected = $this->protected_bytes(); $stack = $this->stack(); $stack->publication->allow = false;
		$result = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() );
		// C03 preserves a known accepted mutation while advisory publication is pending.
		// That outcome is deliberately unconfirmed until original-request reconciliation.
		self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertTrue( $result->attempt->outcome->mutation_accepted ); self::assertTrue( $result->attempt->outcome->publication_pending ); self::assertSame( 'accepted', $result->attempt->completion?->state );
		self::assertNotNull( $result->quote ); self::assertSame( 'accepted', $result->quote->state() ); self::assertSame( 2, $result->quote->revision() ); self::assertNotNull( $result->quote->accepted_at() ); self::assertSame( $protected, $this->protected_bytes() ); $this->no_ledger_writes( $stack ); self::assertSame( 'pending', $this->rows( 'operation_records' )[1]['publication_state'] );
		$this->release( $command, 400 ); $before = $this->effects(); $file = $this->command_file( $command ); $history = $this->worker( $file, clock: Factory::NOW + 400 )->finish(); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( $result->quote->accepted_at()->sql(), $history['accepted_at'] ); self::assertSame( [ 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['quote_writes'], $history['ledger_writes'], $history['native_input_reads'] ] ); self::assertSame( $before, $this->effects() ); self::assertSame( 'published', $this->rows( 'operation_records' )[1]['publication_state'] );
		$records = $this->rows( 'operation_records' ); $again = $this->worker( $file, clock: Factory::NOW + 400 )->finish(); self::assertSame( 'accepted', $again['state'] ); self::assertSame( $records, $this->rows( 'operation_records' ) ); self::assertSame( $before, $this->effects() );
	}
	public function test_corrupt_or_cross_site_issue_linkage_and_generic_format_three_paths_stay_closed(): void {
		$issued = $this->seed(); $command = FixedPromotionQuoteAcceptCommand::from_issue( $issued->command ); $before = $this->effects();
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET intent_hash='" . Q::digest( 'different original issue intent' ) . "' WHERE operation='delivery_quote.issue'" ); $stack = $this->stack(); $denied = $stack->service->accept( $command, $stack->inputs(), RequestContext::create() ); self::assertNotSame( true, $denied->attempt->outcome->mutation_accepted ); $this->no_accept( $before ); $this->no_ledger_writes( $stack );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET intent_hash='" . $issued->command->intent()->fingerprint() . "' WHERE operation='delivery_quote.issue'" ); $foreign = $this->stack( site_id: 2 ); $denied = $foreign->service->accept( $command, $foreign->inputs(), RequestContext::create() ); self::assertNotSame( true, $denied->attempt->outcome->mutation_accepted ); $this->no_accept( $before ); $this->no_ledger_writes( $foreign );
		$closed = $this->factory(); $generic = new QuoteDurableService( $closed, new QuoteProviderRegistry(), static fn(): bool => true ); self::assertSame( 'rejected', $generic->accept( $command->owner(), $command->reference(), $command->header(), $command->context(), RequestContext::create() )->attempt->outcome->state ); self::assertSame( [], $closed->transports );
		$generic_command = QuoteDurableCommand::accept( $command->owner(), $command->reference(), $command->header(), $command->context() ); self::assertFalse( ( new QuoteOperationProfile( 'delivery_quote.accept', $generic_command, static fn(): bool => true ) )->authorize( $generic_command->identity ) ); self::assertFalse( DeliveryQuote::issue( $command->header(), $command->context(), $command->terms() )->usable_at( Q::time()->plus_seconds( 1 ) ) ); self::assertSame( $before, $this->effects() );
	}
}
