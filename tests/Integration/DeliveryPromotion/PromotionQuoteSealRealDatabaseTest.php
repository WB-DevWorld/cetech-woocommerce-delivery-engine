<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetAllocationService;
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptResult, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindResult, FixedPromotionQuoteSealCommand, FixedPromotionQuoteSealResult, FixedPromotionQuoteVerifyCommand as VerifyCommand, FixedPromotionQuoteVerifyResult as VerifyResult, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate};
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteJson, QuoteOwner};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionQuoteAcceptSqlStack as AcceptStack, PromotionQuoteBindOrderPort as OrderPort, PromotionQuoteBindSqlStack as BindStack, PromotionQuoteVerifySavedPort as SavedPort, PromotionQuoteSealSqlStack as Stack, PromotionQuoteVerifySqlStack as VerifyStack, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as IssueStack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteSealSqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Twelve real verified-binding sealing/C03 preservation cases; native/source/order/saved ports are synthetic. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionQuoteSealRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $fixture;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix ); B::install( $this->database, $this->prefix ); A::publication( $this->database, $this->prefix, 'success' ); OrderPort::install( $this->database, $this->prefix ); SavedPort::install( $this->database, $this->prefix );
		$session = ( new PromotionQuoteIssueSqlFactory( $this->factory() ) )->open(); self::assertTrue( $session->begin() ); $this->fixture = Ports::capture( $session ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		// Three independent 6.00 claims support both concurrent-process subcases without fixture release.
		$state = FixedPromotionBudgetState::create( 1, $this->fixture['slots'][0]['account_key'], F::rule()->private_facts()['funding_source'], B::money( '18.00' ) ); $values = FixedPromotionBudgetRecordCodec::account_values( $state );
		$columns = implode( ',', array_map( static fn( string $key ): string => "`{$key}`", array_keys( $values ) ) ); $quoted = array_map( fn( mixed $value ): string => "'" . $this->database->real_escape_string( (string) $value ) . "'", array_values( $values ) ); DB::execute( $this->database, "INSERT INTO `{$this->prefix}delivery_engine_promotion_budget_accounts` ({$columns}) VALUES (" . implode( ',', $quoted ) . ')' );
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { try { $process->kill_and_wait(); } catch ( \Throwable ) { $process->kill(); } } foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function factory( int $clock = Factory::NOW ): Factory { $f = new Factory( $this->prefix ); $f->clock = $clock; $this->factories[] = $f; return $f; }
	private function stack( FixedPromotionQuoteSealCommand $command, int $clock = Factory::NOW + 4, ?callable $configure = null, int $site_id = 1 ): Stack { $s = new Stack( $this->prefix, $this->fixture, $command, $clock, $configure, $site_id ); $this->factories[] = $s->physical; return $s; }
	private function accepted(): FixedPromotionQuoteAcceptResult {
		$issue = new IssueStack( $this->prefix, $this->fixture ); $this->factories[] = $issue->physical; $token = QuoteId::generate()->value(); $original = $issue->original( $token );
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, null, new EmergencyControlStore( static fn(): bool => false ) );
		$admission = $gate->admit( QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'E15 owned synthetic server draft' ), $token ), QuotePreparationAttempt::generate() );
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
	private function seed( int $order = 1001 ): VerifyResult {
		$bound = $this->bound( $order );
		$command = VerifyCommand::create( $bound->command, $bound->binding, Q::digest( 'E15 owned synthetic saved snapshot' ), Q::digest( 'E15 owned synthetic saved context' ) );
		SavedPort::put( $this->database, $this->prefix, SavedPort::packet( $command->binding() ) );
		$stack = new VerifyStack( $this->prefix, $this->fixture, $command ); $this->factories[] = $stack->physical;
		$verified = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() );
		self::assertSame( 'accepted', $verified->attempt->outcome->state ); self::assertNotNull( $verified->quote ); self::assertNotNull( $verified->binding ); self::assertNotNull( $verified->command ); self::assertSame( 2, $verified->binding->revision() ); return $verified;
	}
	private function command( VerifyResult $verified, int $control_revision = 1 ): FixedPromotionQuoteSealCommand {
		return FixedPromotionQuoteSealCommand::create( $verified->command, $verified->binding, $control_revision );
	}
	/** Structurally valid foreign E14 history must fail exact producer authentication. */
	private function foreign_command( VerifyResult $verified, string $field ): FixedPromotionQuoteSealCommand {
		$c = $verified->command; $prepared = $c->prepared_binding()->row(); $row = $verified->binding->row();
		if ( 'snapshot_digest' === $field || 'context_digest' === $field ) { $row[$field] = Q::digest( 'unstored E15 saved proof' ); }
		elseif ( 'id' === $field ) { $prepared['id'] += 100; $row['id'] = $prepared['id']; }
		elseif ( 'created_at' === $field ) { $prepared['created_at'] = Q::time()->plus_seconds( 3 )->sql(); $row['created_at'] = $prepared['created_at']; }
		elseif ( 'verified_at' === $field ) { $row['verified_at'] = Q::time()->plus_seconds( 4 )->sql(); }
		else { throw new \LogicException( 'Unknown owned E15 foreign history case.' ); }
		$foreign = VerifyCommand::create( $c->binding_command(), QuoteBinding::from_row( $prepared, $verified->quote ), $row['snapshot_digest'], $row['context_digest'] );
		return FixedPromotionQuoteSealCommand::create( $foreign, QuoteBinding::from_row( $row, $verified->quote ), 1 );
	}

	private function rows( string $suffix ): array { $r = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned E15 seal census failed.' ); } return $r->fetch_all( MYSQLI_ASSOC ); }
	private function protected_bytes(): array { $out = []; foreach ( [ 'delivery_quotes', 'promotion_budget_accounts', 'promotion_budget_reservations', 'delivery_quote_budget_windows' ] as $suffix ) { $out[$suffix] = $this->rows( $suffix ); } foreach ( [ OrderPort::table( $this->prefix ), SavedPort::table( $this->prefix ) ] as $table ) { $r = $this->database->query( "SELECT * FROM {$table} ORDER BY site_id,order_id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned E15 packet census failed.' ); } $out[$table] = $r->fetch_all( MYSQLI_ASSOC ); } return $out; }
	private function effects(): array { return [ 'bindings' => $this->rows( 'delivery_quote_bindings' ), 'events' => $this->rows( 'operation_changes' ), 'protected' => $this->protected_bytes() ]; }
	private function sealed( FixedPromotionQuoteSealResult $r, ?FixedPromotionQuoteSealCommand $command = null ): void {
		self::assertSame( 'accepted', $r->attempt->outcome->state, $r->attempt->outcome->error?->code ?? '' ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertNotNull( $r->quote ); self::assertSame( 'accepted', $r->quote->state() ); self::assertSame( 2, $r->quote->revision() ); self::assertNotNull( $r->binding ); self::assertSame( 'sealed', $r->binding->state() ); self::assertSame( 3, $r->binding->revision() ); self::assertNotNull( $r->binding->row()['sealed_at'] ); self::assertNotNull( $r->binding->row()['verified_at'] );
		if ( null !== $command ) { self::assertSame( $command->control_revision(), $r->attempt->completion->result['control_revision'] ); foreach ( array_diff( QuoteBinding::FIELDS, [ 'state', 'revision', 'sealed_at' ] ) as $field ) { self::assertSame( $command->verified_binding()->row()[$field], $r->binding->row()[$field] ); } }
	}
	private function no_seal( array $before, Stack $stack, FixedPromotionQuoteSealResult $r ): void { self::assertNotSame( true, $r->attempt->outcome->mutation_accepted ); self::assertNull( $r->binding ); self::assertSame( $before, $this->effects() ); self::assertSame( [], $stack->protected_writes() ); }

	private function release( FixedPromotionQuoteSealCommand $command, int $offset = 2 ): void {
		$s = $this->factory()->open(); self::assertTrue( $s->begin() ); $rows = ( new FixedPromotionBudgetAllocationService( new SqlFixedPromotionBudgetStore(), static fn(): bool => true ) )->release_all( $s, $command->allocation_set(), Q::time()->plus_seconds( $offset ) ); self::assertCount( 2, $rows ); self::assertSame( [ 'released', 'released' ], array_map( static fn( $r ): string => $r->state(), $rows ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); self::assertTrue( $s->retire() );
	}
	private function reset_control(): void { DB::execute( $this->database, "DELETE FROM {$this->prefix}options WHERE option_name='cetech_de_checkout_control_v1'" ); }
	private function control( string $state ): void { $json = EmergencyControlState::record_json( 1, $state, 2, 'enabled' === $state ? 'resume_verified' : 'operator_pause', 9, Factory::NOW ); $s = $this->database->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value) VALUES ('cetech_de_checkout_control_v1',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" ); self::assertNotFalse( $s ); self::assertTrue( $s->bind_param( 's', $json ) ); self::assertTrue( $s->execute() ); $s->close(); }
	private function command_file( FixedPromotionQuoteSealCommand $command, ?string $directory = null, string $name = 'seal-recovery.json' ): string {
		if ( null === $directory ) { $directory = OperationProofBarrier::directory(); $this->directories[] = $directory; } $file = $directory . '/' . $name; $bytes = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ); self::assertSame( strlen( $bytes ), file_put_contents( $file, $bytes, LOCK_EX ) ); self::assertTrue( chmod( $file, 0600 ) ); return $file;
	}
	private function worker( string $file, string $mode = 'reconcile', string $role = 'history', int $clock = Factory::NOW + 4, string $barrier = 'publication' ): OperationProofProcess { $p = new OperationProofProcess( [ __DIR__ . '/promotion-quote-seal-process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'command_file' => $file, 'mode' => $mode, 'role' => $role, 'clock' => $clock, 'barrier' => $barrier ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
	private function finish_worker( OperationProofProcess $process ): array { $result = $process->finish(); $this->processes = array_values( array_filter( $this->processes, static fn( OperationProofProcess $active ): bool => $active !== $process ) ); return $result; }
	private function wait_for_lock( int $waiter, int $blocker, string $kind ): void {
		self::assertNotSame( $waiter, $blocker ); $table = $this->prefix . ( 'namespace' === $kind ? 'delivery_engine_operation_records' : 'options' ); $end = microtime( true ) + 3.0; $waiting = false;
		$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
		do { $waiting = (int) DB::scalar( $this->database, $sql ) > 0; if ( $waiting ) { break; } usleep( 10000 ); } while ( microtime( true ) < $end ); self::assertTrue( $waiting, 'The competing seal process must wait on the exact declared owner and table.' );
	}

	public function test_atomic_sealed_binding_exact_history_and_changed_control_or_saved_intent(): void {
		$verified = $this->seed(); $command = $this->command( $verified ); $protected = $this->protected_bytes(); $captures = $this->fixture['provider']->captures; $reads = $this->fixture['source']->reads; $stack = $this->stack( $command );
		$r = $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ); $this->sealed( $r, $command );
		self::assertSame( Q::time()->plus_seconds( 4 )->sql(), $r->binding->row()['sealed_at'] ); self::assertSame( $verified->binding->row()['verified_at'], $r->binding->row()['verified_at'] ); self::assertSame( $protected, $this->protected_bytes() ); self::assertSame( [], $stack->protected_writes() ); self::assertSame( 1, $stack->binding_updates() ); self::assertSame( $captures, $this->fixture['provider']->captures ); self::assertSame( $reads, $this->fixture['source']->reads ); self::assertSame( 'retain_original_hold_v1', $command::FUNDING_POLICY );
		self::assertCount( 5, $this->rows( 'operation_records' ) ); self::assertCount( 5, $this->rows( 'operation_changes' ) ); $event = json_decode( $this->rows( 'operation_changes' )[4]['event_json'], true, 16, JSON_THROW_ON_ERROR ); self::assertSame( 'quote_sealed', $event['reason_code'] ); self::assertSame( [ 'binding' ], $event['changed_fields'] ); self::assertSame( 2, $event['operation_version'] ); self::assertSame( 2, $event['before_revision'] ); self::assertSame( 3, $event['after_revision'] );
		$before = $this->effects(); $stack->eligibility->poison = true; $stack->order->poison = true; $stack->saved->poison = true; $again = $stack->service->reconcile( $command, RequestContext::create() ); $this->sealed( $again, $command ); self::assertTrue( $again->attempt->replayed ); self::assertSame( $r->binding->row(), $again->binding->row() ); self::assertSame( $before, $this->effects() );
		foreach ( [ $this->command( $verified, 2 ), $this->foreign_command( $verified, 'snapshot_digest' ), $this->foreign_command( $verified, 'context_digest' ) ] as $changed ) {
			$other = $this->stack( $changed ); $this->no_seal( $before, $other, $other->service->seal( $changed, $other->inputs(), RequestContext::create() ) ); self::assertSame( 'intent_conflict', $other->service->reconcile( $changed, RequestContext::create() )->attempt->outcome->error?->code ); self::assertSame( 0, $other->binding_updates() );
		}
	}

	public function test_actual_processes_serialize_same_namespace_and_shared_publication_without_duplicate_cas(): void {
		// The same-placement retry first waits on C03's unique operation namespace.
		$same = $this->command( $this->seed( 2001 ) ); $protected = $this->protected_bytes();
		$left_file = $this->command_file( $same, name: 'seal-left.json' ); $directory = dirname( $left_file ); $right_file = $this->command_file( $same, $directory, 'seal-right.json' );
		$left = $this->worker( $left_file, 'seal', 'left', barrier: 'namespace' ); OperationProofProcess::wait_for( $directory . '/left-held' ); $held = json_decode( (string) file_get_contents( $directory . '/left-held' ), true, 4, JSON_THROW_ON_ERROR );
		$right = $this->worker( $right_file, 'seal', 'right', barrier: 'namespace' ); OperationProofProcess::wait_for( $directory . '/right-dispatched' ); $waiter = json_decode( (string) file_get_contents( $directory . '/right-dispatched' ), true, 4, JSON_THROW_ON_ERROR ); $this->wait_for_lock( $waiter['connection_id'], $held['connection_id'], 'namespace' ); OperationProofBarrier::signal( $directory . '/release' );
		$a = $this->finish_worker( $left ); $b = $this->finish_worker( $right ); self::assertNotSame( getmypid(), $a['process_id'] ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertSame( 'accepted', $a['state'] ); self::assertSame( 'accepted', $b['state'] ); self::assertTrue( $b['replayed'] ); self::assertSame( 1, $a['binding_updates'] + $b['binding_updates'] ); self::assertSame( $a['binding_sealed_at'], $b['binding_sealed_at'] ); self::assertSame( 0, $a['protected_writes'] + $b['protected_writes'] ); self::assertCount( 5, $this->rows( 'operation_changes' ) ); self::assertSame( $protected, $this->protected_bytes() );
		// Distinct quote/placement namespaces share one held account and wait on current publication.
		$first = $this->command( $this->seed( 2002 ) ); $second = $this->command( $this->seed( 2003 ) ); $protected = $this->protected_bytes(); self::assertCount( 1, $this->rows( 'promotion_budget_accounts' ) ); self::assertCount( 1, array_unique( array_column( $this->rows( 'promotion_budget_reservations' ), 'account_key' ) ) ); self::assertSame( [ 'held', 'held', 'held', 'held', 'held', 'held' ], array_column( $this->rows( 'promotion_budget_reservations' ), 'state' ) );
		$left_file = $this->command_file( $first, name: 'seal-left.json' ); $directory = dirname( $left_file ); $right_file = $this->command_file( $second, $directory, 'seal-right.json' );
		$left = $this->worker( $left_file, 'seal', 'left' ); OperationProofProcess::wait_for( $directory . '/left-held' ); $held = json_decode( (string) file_get_contents( $directory . '/left-held' ), true, 4, JSON_THROW_ON_ERROR );
		$right = $this->worker( $right_file, 'seal', 'right' ); OperationProofProcess::wait_for( $directory . '/right-dispatched' ); $waiter = json_decode( (string) file_get_contents( $directory . '/right-dispatched' ), true, 4, JSON_THROW_ON_ERROR ); $this->wait_for_lock( $waiter['connection_id'], $held['connection_id'], 'publication' ); OperationProofBarrier::signal( $directory . '/release' );
		$a = $this->finish_worker( $left ); $b = $this->finish_worker( $right ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertSame( 'accepted', $a['state'] ); self::assertSame( 'accepted', $b['state'] ); self::assertFalse( $a['replayed'] ); self::assertFalse( $b['replayed'] ); self::assertSame( 2, $a['binding_updates'] + $b['binding_updates'] ); self::assertSame( 0, $a['protected_writes'] + $b['protected_writes'] ); self::assertCount( 3, $this->rows( 'delivery_quote_bindings' ) ); self::assertSame( [ 3, 3, 3 ], array_map( 'intval', array_column( $this->rows( 'delivery_quote_bindings' ), 'revision' ) ) ); self::assertCount( 15, $this->rows( 'operation_changes' ) ); self::assertSame( $protected, $this->protected_bytes() );
	}

	public function test_verified_digest_membership_time_and_expected_control_revision_must_match(): void {
		foreach ( [ 'snapshot_digest', 'context_digest', 'id', 'created_at', 'verified_at', 'control_revision' ] as $index => $kind ) {
			$verified = $this->seed( 3001 + $index ); $original = $this->command( $verified );
			$changed = 'control_revision' === $kind ? $this->command( $verified, 2 ) : $this->foreign_command( $verified, $kind );
			$before = $this->effects(); $stack = $this->stack( $changed ); $r = $stack->service->seal( $changed, $stack->inputs(), RequestContext::create() ); $this->no_seal( $before, $stack, $r ); self::assertNotSame( 'intent_conflict', $r->attempt->outcome->error?->code ); self::assertSame( 0, $stack->binding_updates() ); self::assertCount( 0, $stack->order->owners ); self::assertCount( 0, $stack->saved->owners );
			// Only owned fixture setup explicitly releases old test holds for an independent namespace.
			$this->release( $original, 5 );
		}
	}

	public function test_current_publication_refuses_running_migration_hidden_from_old_repeatable_read(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects(); $changed = false; $old = null; $name = $this->database->real_escape_string( MigrationStatus::OPTION_NAME );
		$stack = $this->stack( $command, configure: function( Transport $transport ) use ( &$changed, &$old, $name ): void { $transport->before = function( string $sql, Transport $owner ) use ( &$changed, &$old, $name ): void {
			if ( $changed || ! str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $r = $owner->native_execute( "SELECT option_value FROM {$this->prefix}options WHERE option_name='{$name}'" ); self::assertTrue( $r->acknowledged ); $old = $r->rows[0]['option_value']; A::publication( $this->database, $this->prefix, 'running' ); $again = $owner->native_execute( "SELECT option_value FROM {$this->prefix}options WHERE option_name='{$name}'" ); self::assertSame( $old, $again->rows[0]['option_value'] );
		}; } );
		$r = $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( 'success', unserialize( $old, [ 'allowed_classes' => false ] )['status'] ); $this->no_seal( $before, $stack, $r );
	}

	public function test_current_account_claim_locks_refuse_release_hidden_from_old_repeatable_read(): void {
		$command = $this->command( $this->seed() ); $expected = $this->effects(); $changed = false; $old = null;
		$stack = $this->stack( $command, configure: function( Transport $transport ) use ( $command, &$changed, &$old, &$expected ): void { $transport->before = function( string $sql, Transport $owner ) use ( $command, &$changed, &$old, &$expected ): void {
			if ( $changed || ! str_contains( $sql, 'delivery_engine_promotion_budget_accounts' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $snapshot = $owner->native_execute( "SELECT state FROM {$this->prefix}delivery_engine_promotion_budget_reservations ORDER BY id" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows; $this->release( $command ); $expected = $this->effects(); $again = $owner->native_execute( "SELECT state FROM {$this->prefix}delivery_engine_promotion_budget_reservations ORDER BY id" ); self::assertSame( $old, $again->rows );
		}; } );
		$r = $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( [ [ 'state' => 'held' ], [ 'state' => 'held' ] ], $old ); self::assertSame( [ 'released', 'released' ], array_column( $this->rows( 'promotion_budget_reservations' ), 'state' ) ); $this->no_seal( $expected, $stack, $r ); self::assertSame( 0, $stack->binding_updates() );
	}

	public function test_current_order_and_saved_packet_locks_refuse_old_snapshot_money_and_mapping(): void {
		$command = $this->command( $this->seed() ); $clean_stack = $this->stack( $command );
		foreach ( [ 'order', 'saved' ] as $kind ) {
			OrderPort::put( $this->database, $this->prefix, $clean_stack->order->captured ); SavedPort::put( $this->database, $this->prefix, $clean_stack->saved->captured ); $expected = $this->effects(); $changed = false; $old = null; $stack = $this->stack( $command ); $port = 'order' === $kind ? $stack->order : $stack->saved; $table = 'order' === $kind ? OrderPort::table( $this->prefix ) : SavedPort::table( $this->prefix );
			$port->on_proof = function( $session, int $proof ) use ( $kind, $stack, $port, $table, &$changed, &$old, &$expected ): void {
				if ( 1 !== $proof ) { return; } $changed = true; $owner = $stack->physical->transports[0]; $snapshot = $owner->native_execute( "SELECT packet_json FROM {$table}" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows[0]['packet_json']; $new = $port->captured; if ( 'order' === $kind ) { $new['mapping']['groups'][0]['lines'][0]['quantity'] = '99'; OrderPort::put( $this->database, $this->prefix, $new ); } else { $new['snapshot_digest'] = Q::digest( 'newly committed saved snapshot' ); SavedPort::put( $this->database, $this->prefix, $new ); } $expected = $this->effects(); $again = $owner->native_execute( "SELECT packet_json FROM {$table}" ); self::assertSame( $old, $again->rows[0]['packet_json'] );
			};
			$r = $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); $this->no_seal( $expected, $stack, $r ); self::assertSame( 0, $stack->binding_updates() );
		}
		foreach ( [ 'native_money_digest', 'manifest_digest', 'snapshot_digest', 'context_digest', 'binding_id', 'placement_uuid' ] as $field ) {
			OrderPort::put( $this->database, $this->prefix, $clean_stack->order->captured ); $packet = $clean_stack->saved->captured; $packet[$field] = 'binding_id' === $field ? 999 : ( 'placement_uuid' === $field ? QuoteId::generate()->value() : Q::digest( 'foreign saved facts' ) ); SavedPort::put( $this->database, $this->prefix, $packet ); $before = $this->effects(); $stack = $this->stack( $command ); $this->no_seal( $before, $stack, $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() );
		}
	}

	public function test_pause_source_policy_order_digest_and_corrupt_original_history_refuse_before_cas(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects();
		foreach ( [ 'pause', 'control_revision', 'source', 'eligibility', 'order', 'saved', 'digest', 'receipt', 'foreign' ] as $kind ) {
			$stack = $this->stack( $command, site_id: 'foreign' === $kind ? 2 : 1 );
			if ( 'pause' === $kind ) { $this->control( 'checkout_suspended' ); } elseif ( 'control_revision' === $kind ) { $this->control( 'enabled' ); } elseif ( 'source' === $kind ) { $this->fixture['source']->same = false; } elseif ( 'eligibility' === $kind ) { $stack->eligibility->allow = false; } elseif ( 'order' === $kind ) { $stack->order->allow = false; } elseif ( 'saved' === $kind ) { $stack->saved->allow = false; } elseif ( 'digest' === $kind ) { $stack->order->on_proof = static function() use ( $stack ): void { $stack->order->digest_override = Q::digest( 'changed original order identity' ); }; } elseif ( 'receipt' === $kind ) { DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_records SET intent_hash='" . Q::digest( 'wrong original verification intent' ) . "' WHERE operation='delivery_quote.verify_binding'" ); }
			$this->no_seal( $before, $stack, $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() );
			$this->fixture['source']->same = true; $this->reset_control(); if ( 'receipt' === $kind ) { DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_records SET intent_hash='" . $command->verification()->intent()->fingerprint() . "' WHERE operation='delivery_quote.verify_binding'" ); }
		}
		foreach ( [ 1, 2, 3 ] as $index ) {
			$event = $this->rows( 'operation_changes' )[$index]; $id = (int) $event['id']; $original = $this->database->real_escape_string( $event['created_at'] ); DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_changes SET created_at='" . Q::time()->sql() . "' WHERE id={$id}" );
			try { $corrupt_before = $this->effects(); $stack = $this->stack( $command ); $this->no_seal( $corrupt_before, $stack, $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() ); self::assertCount( 0, $stack->order->owners ); self::assertCount( 0, $stack->saved->owners ); } finally { DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_changes SET created_at='{$original}' WHERE id={$id}" ); }
		}
		self::assertSame( $before, $this->effects() );
	}

	public function test_original_deadline_reversed_time_and_late_saved_proof_do_not_seal(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects();
		foreach ( [ 'deadline', 'reversed', 'slow', 'late_reversed' ] as $kind ) {
			$clock = match ( $kind ) { 'deadline' => Factory::NOW + 45, 'reversed' => Factory::NOW + 2, default => Factory::NOW + 4 }; $stack = $this->stack( $command, $clock );
			if ( 'slow' === $kind || 'late_reversed' === $kind ) { $stack->saved->on_proof = static function( $session, int $proof ) use ( $stack, $kind ): void { if ( 2 === $proof ) { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + ( 'slow' === $kind ? 45 : 2 ) ) )->acknowledged ); } }; }
			$this->no_seal( $before, $stack, $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() ); if ( 'slow' === $kind || 'late_reversed' === $kind ) { self::assertGreaterThanOrEqual( 2, count( $stack->saved->owners ) ); }
		}
	}

	public function test_actual_binding_update_failure_rolls_back_without_a_seal_event(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects(); $name = $this->prefix . 'reject_seal';
		DB::execute( $this->database, "CREATE TRIGGER {$name} BEFORE UPDATE ON {$this->prefix}delivery_engine_delivery_quote_bindings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned E15 seal rejection'" );
		$stack = $this->stack( $command ); $this->no_seal( $before, $stack, $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 1, $stack->binding_updates() );
	}

	public function test_audit_and_completion_faults_roll_back_a_physically_updated_binding(): void {
		$command = $this->command( $this->seed() );
		foreach ( [ 'audit', 'completion' ] as $kind ) {
			$before = $this->effects(); $reached = false;
			$stack = $this->stack( $command, configure: function( Transport $transport ) use ( $kind, &$reached ): void { $transport->{'reject_' . $kind} = true; $transport->after = function( string $sql, Transport $owner, $result ) use ( &$reached ): void { if ( $result->acknowledged && 1 === $result->affected_rows && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'delivery_engine_delivery_quote_bindings' ) ) { $reached = true; } }; } );
			$this->no_seal( $before, $stack, $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ) ); self::assertTrue( $reached, 'The physical binding CAS must succeed before the later fault.' ); self::assertSame( 1, $stack->binding_updates() );
		}
	}

	public function test_lost_effect_commit_acknowledgement_recovers_exact_sealed_binding_in_fresh_process(): void {
		$command = $this->command( $this->seed() ); $file = $this->command_file( $command ); $protected = $this->protected_bytes(); $first = true;
		$stack = $this->stack( $command, configure: static function( Transport $transport ) use ( &$first ): void { if ( $first ) { $first = false; $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } } );
		$r = $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->quote ); self::assertNull( $r->binding ); self::assertSame( 3, (int) $this->rows( 'delivery_quote_bindings' )[0]['revision'] ); self::assertSame( $protected, $this->protected_bytes() );
		$before = $this->effects(); $history = $this->finish_worker( $this->worker( $file ) ); self::assertNotSame( getmypid(), $history['process_id'] ); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( 'sealed', $history['binding_state'] ); self::assertSame( 3, $history['binding_revision'] ); self::assertSame( $command->verified_binding()->row()['order_id'], $history['order_id'] ); self::assertSame( $command->header()->to_private_json(), $history['header_json'] ); self::assertSame( $command->snapshot_digest(), $history['snapshot_digest'] ); self::assertSame( $command->context_digest(), $history['context_digest'] ); self::assertSame( [ 0, 0, 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['order_proofs'], $history['saved_proofs'], $history['binding_updates'], $history['protected_writes'], $history['native_input_reads'] ] ); self::assertSame( $before, $this->effects() );
	}

	public function test_pending_publication_after_expiry_and_release_reconciles_history_only_idempotently(): void {
		$command = $this->command( $this->seed() ); $stack = $this->stack( $command ); $stack->publication->allow = false; $protected = $this->protected_bytes();
		$r = $stack->service->seal( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertTrue( $r->attempt->outcome->publication_pending ); self::assertSame( 'accepted', $r->attempt->completion?->state ); self::assertNotNull( $r->quote ); self::assertNotNull( $r->binding ); self::assertSame( 3, $r->binding->revision() ); self::assertSame( $protected, $this->protected_bytes() );
		$this->release( $command, 400 ); $before = $this->effects(); $file = $this->command_file( $command ); $history = $this->finish_worker( $this->worker( $file, clock: Factory::NOW + 400 ) ); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( $r->binding->id(), $history['binding_id'] ); self::assertSame( $r->binding->row()['sealed_at'], $history['binding_sealed_at'] ); self::assertSame( [ 0, 0, 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['order_proofs'], $history['saved_proofs'], $history['binding_updates'], $history['protected_writes'], $history['native_input_reads'] ] ); self::assertSame( $before, $this->effects() );
		$records = $this->rows( 'operation_records' ); self::assertSame( 'published', $records[4]['publication_state'] ); $again = $this->finish_worker( $this->worker( $file, clock: Factory::NOW + 400 ) ); self::assertSame( 'accepted', $again['state'] ); self::assertSame( $records, $this->rows( 'operation_records' ) ); self::assertSame( $before, $this->effects() );
	}
}
