<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetAllocationService;
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptResult, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindResult, FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyResult, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate};
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteJson, QuoteOwner};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionQuoteAcceptSqlStack as AcceptStack, PromotionQuoteBindOrderPort as OrderPort, PromotionQuoteBindSqlStack as BindStack, PromotionQuoteVerifySavedPort as SavedPort, PromotionQuoteVerifySqlStack as Stack, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as IssueStack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteVerifySqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Twelve real saved-binding verification/C03 preservation cases; native/source/order/saved ports are synthetic. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionQuoteVerifyRealDatabaseTest extends TestCase {
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
	private function stack( FixedPromotionQuoteVerifyCommand $command, int $clock = Factory::NOW + 3, ?callable $configure = null, int $site_id = 1 ): Stack { $s = new Stack( $this->prefix, $this->fixture, $command, $clock, $configure, $site_id ); $this->factories[] = $s->physical; return $s; }
	private function accepted(): FixedPromotionQuoteAcceptResult {
		$issue = new IssueStack( $this->prefix, $this->fixture ); $this->factories[] = $issue->physical; $token = QuoteId::generate()->value(); $original = $issue->original( $token );
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, null, new EmergencyControlStore( static fn(): bool => false ) );
		$admission = $gate->admit( QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'E14 owned synthetic server draft' ), $token ), QuotePreparationAttempt::generate() );
		self::assertSame( 'preparation_allowed', $admission->status ); self::assertNotNull( $admission->lease ); self::assertTrue( $admission->lease->claim_preparation() );
		$issued = $issue->service->issue_admitted( $original, $admission->lease->bind_issue( $original ), $issue->inputs(), RequestContext::create(), static fn(): bool => true );
		self::assertSame( 'accepted', $issued->attempt->outcome->state ); self::assertNotNull( $issued->command );
		$accept = new AcceptStack( $this->prefix, $this->fixture ); $this->factories[] = $accept->physical; $accepted = $accept->service->accept( FixedPromotionQuoteAcceptCommand::from_issue( $issued->command ), $accept->inputs(), RequestContext::create() );
		self::assertSame( 'accepted', $accepted->attempt->outcome->state ); self::assertNotNull( $accepted->quote ); self::assertNotNull( $accepted->command ); return $accepted;
	}
	private function seed( int $order = 1001 ): FixedPromotionQuoteBindResult {
		$accepted = $this->accepted(); $mapping = OrderPort::mapping( $accepted->quote, $order ); $packet = OrderPort::packet( $accepted->quote, $order, $mapping );
		$command = FixedPromotionQuoteBindCommand::create( $accepted->command, $accepted->quote, $order, QuoteId::generate(), $mapping, OrderPort::digest( $packet ) );
		OrderPort::put( $this->database, $this->prefix, $packet ); $stack = new BindStack( $this->prefix, $this->fixture, $command ); $this->factories[] = $stack->physical;
		$bound = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'accepted', $bound->attempt->outcome->state ); self::assertNotNull( $bound->quote ); self::assertNotNull( $bound->binding ); self::assertNotNull( $bound->command ); self::assertSame( 1, $bound->binding->revision() ); return $bound;
	}
	private function command( FixedPromotionQuoteBindResult $bound, ?string $snapshot = null, ?string $context = null, bool $write_saved = true ): FixedPromotionQuoteVerifyCommand {
		$command = FixedPromotionQuoteVerifyCommand::create( $bound->command, $bound->binding, $snapshot ?? Q::digest( 'E14 owned synthetic saved snapshot' ), $context ?? Q::digest( 'E14 owned synthetic saved context' ) );
		if ( $write_saved ) { SavedPort::put( $this->database, $this->prefix, SavedPort::packet( $command->binding() ) ); } return $command;
	}

	private function rows( string $suffix ): array { $r = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned bind census failed.' ); } return $r->fetch_all( MYSQLI_ASSOC ); }
	private function protected_bytes(): array { $out = []; foreach ( [ 'delivery_quotes', 'promotion_budget_accounts', 'promotion_budget_reservations', 'delivery_quote_budget_windows' ] as $suffix ) { $out[$suffix] = $this->rows( $suffix ); } foreach ( [ OrderPort::table( $this->prefix ), SavedPort::table( $this->prefix ) ] as $table ) { $r = $this->database->query( "SELECT * FROM {$table} ORDER BY site_id,order_id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned E14 packet census failed.' ); } $out[$table] = $r->fetch_all( MYSQLI_ASSOC ); } return $out; }
	private function effects(): array { return [ 'bindings' => $this->rows( 'delivery_quote_bindings' ), 'events' => $this->rows( 'operation_changes' ), 'protected' => $this->protected_bytes() ]; }
	private function verified( FixedPromotionQuoteVerifyResult $r, ?FixedPromotionQuoteVerifyCommand $command = null ): void {
		self::assertSame( 'accepted', $r->attempt->outcome->state, $r->attempt->outcome->error?->code ?? '' ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertNotNull( $r->quote ); self::assertSame( 'accepted', $r->quote->state() ); self::assertSame( 2, $r->quote->revision() ); self::assertNotNull( $r->binding ); self::assertSame( 'prepared', $r->binding->state() ); self::assertSame( 2, $r->binding->revision() ); self::assertNull( $r->binding->row()['sealed_at'] ); self::assertNotNull( $r->binding->row()['verified_at'] );
		if ( null !== $command ) { self::assertSame( $command->snapshot_digest(), $r->binding->row()['snapshot_digest'] ); self::assertSame( $command->context_digest(), $r->binding->row()['context_digest'] ); foreach ( array_diff( QuoteBinding::FIELDS, [ 'revision', 'snapshot_digest', 'context_digest', 'verified_at' ] ) as $field ) { self::assertSame( $command->prepared_binding()->row()[$field], $r->binding->row()[$field] ); } }
	}
	private function no_verify( array $before, Stack $stack, FixedPromotionQuoteVerifyResult $r ): void { self::assertNotSame( true, $r->attempt->outcome->mutation_accepted ); self::assertNull( $r->binding ); self::assertSame( $before, $this->effects() ); self::assertSame( [], $stack->protected_writes() ); }

	private function release( FixedPromotionQuoteVerifyCommand $command, int $offset = 2 ): void {
		$s = $this->factory()->open(); self::assertTrue( $s->begin() ); $rows = ( new FixedPromotionBudgetAllocationService( new SqlFixedPromotionBudgetStore(), static fn(): bool => true ) )->release_all( $s, $command->allocation_set(), Q::time()->plus_seconds( $offset ) ); self::assertCount( 2, $rows ); self::assertSame( [ 'released', 'released' ], array_map( static fn( $r ): string => $r->state(), $rows ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); self::assertTrue( $s->retire() );
	}
	private function control( string $state ): void { $json = EmergencyControlState::record_json( 1, $state, 2, 'enabled' === $state ? 'resume_verified' : 'operator_pause', 9, Factory::NOW ); $s = $this->database->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value) VALUES ('cetech_de_checkout_control_v1',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" ); self::assertNotFalse( $s ); self::assertTrue( $s->bind_param( 's', $json ) ); self::assertTrue( $s->execute() ); $s->close(); }
	private function command_file( FixedPromotionQuoteVerifyCommand $command, ?string $directory = null, string $name = 'verify-recovery.json' ): string {
		if ( null === $directory ) { $directory = OperationProofBarrier::directory(); $this->directories[] = $directory; } $file = $directory . '/' . $name; $bytes = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ); self::assertSame( strlen( $bytes ), file_put_contents( $file, $bytes, LOCK_EX ) ); self::assertTrue( chmod( $file, 0600 ) ); return $file;
	}
	private function worker( string $file, string $mode = 'reconcile', string $role = 'history', int $clock = Factory::NOW + 3, string $barrier = 'publication' ): OperationProofProcess { $p = new OperationProofProcess( [ __DIR__ . '/promotion-quote-verify-process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'command_file' => $file, 'mode' => $mode, 'role' => $role, 'clock' => $clock, 'barrier' => $barrier ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
	private function finish_worker( OperationProofProcess $process ): array { $result = $process->finish(); $this->processes = array_values( array_filter( $this->processes, static fn( OperationProofProcess $active ): bool => $active !== $process ) ); return $result; }
	private function wait_for_lock( int $waiter, int $blocker, string $kind ): void {
		self::assertNotSame( $waiter, $blocker ); $table = $this->prefix . ( 'namespace' === $kind ? 'delivery_engine_operation_records' : 'options' ); $end = microtime( true ) + 3.0; $waiting = false;
		$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
		// Allow the MariaDB observation cache to refresh without extending the existing deadline.
		do { $waiting = (int) DB::scalar( $this->database, $sql ) > 0; if ( $waiting ) { break; } $remaining = $end - microtime( true ); if ( $remaining <= 0 ) { break; } usleep( (int) min( 120000, ceil( $remaining * 1000000 ) ) ); } while ( microtime( true ) < $end ); self::assertTrue( $waiting, 'The competing verification process must wait on the exact declared owner and table.' );
	}

	public function test_atomic_verified_binding_exact_history_and_changed_digest_intent(): void {
		$bound = $this->seed(); $command = $this->command( $bound ); $protected = $this->protected_bytes(); $captures = $this->fixture['provider']->captures; $reads = $this->fixture['source']->reads; $stack = $this->stack( $command );
		$r = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ); $this->verified( $r, $command );
		self::assertSame( Q::time()->plus_seconds( 3 )->sql(), $r->binding->row()['verified_at'] ); self::assertSame( $bound->binding->row()['created_at'], $r->binding->row()['created_at'] ); self::assertSame( $protected, $this->protected_bytes() ); self::assertSame( [], $stack->protected_writes() ); self::assertSame( 1, $stack->binding_updates() ); self::assertSame( $captures, $this->fixture['provider']->captures ); self::assertSame( $reads, $this->fixture['source']->reads );
		self::assertCount( 4, $this->rows( 'operation_records' ) ); self::assertCount( 4, $this->rows( 'operation_changes' ) ); $event = json_decode( $this->rows( 'operation_changes' )[3]['event_json'], true, 16, JSON_THROW_ON_ERROR ); self::assertSame( 'quote_binding_verified', $event['reason_code'] ); self::assertSame( [ 'binding' ], $event['changed_fields'] ); self::assertSame( 1, $event['operation_version'] );
		$before = $this->effects(); $stack->eligibility->poison = true; $stack->order->poison = true; $stack->saved->poison = true; $again = $stack->service->reconcile( $command, RequestContext::create() ); $this->verified( $again, $command ); self::assertTrue( $again->attempt->replayed ); self::assertSame( $r->binding->row(), $again->binding->row() ); self::assertSame( $before, $this->effects() );
		foreach ( [ [ Q::digest( 'different saved snapshot' ), null ], [ null, Q::digest( 'different saved context' ) ] ] as [ $snapshot, $context ] ) {
			$changed = $this->command( $bound, $snapshot, $context, false ); $other = $this->stack( $changed ); $this->no_verify( $before, $other, $other->service->verify( $changed, $other->inputs(), RequestContext::create() ) ); self::assertSame( 0, $other->binding_updates() );
		}
	}

	public function test_actual_processes_serialize_same_namespace_and_shared_publication_without_duplicate_cas(): void {
		// The same-placement retry first waits on C03's unique operation namespace.
		$same = $this->command( $this->seed( 2001 ) ); $protected = $this->protected_bytes();
		$left_file = $this->command_file( $same, name: 'verify-left.json' ); $directory = dirname( $left_file ); $right_file = $this->command_file( $same, $directory, 'verify-right.json' );
		$left = $this->worker( $left_file, 'verify', 'left', barrier: 'namespace' ); OperationProofProcess::wait_for( $directory . '/left-held' ); $held = json_decode( (string) file_get_contents( $directory . '/left-held' ), true, 4, JSON_THROW_ON_ERROR );
		$right = $this->worker( $right_file, 'verify', 'right', barrier: 'namespace' ); OperationProofProcess::wait_for( $directory . '/right-dispatched' ); $waiter = json_decode( (string) file_get_contents( $directory . '/right-dispatched' ), true, 4, JSON_THROW_ON_ERROR ); $this->wait_for_lock( $waiter['connection_id'], $held['connection_id'], 'namespace' ); OperationProofBarrier::signal( $directory . '/release' );
		$a = $this->finish_worker( $left ); $b = $this->finish_worker( $right ); self::assertNotSame( getmypid(), $a['process_id'] ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertSame( 'accepted', $a['state'] ); self::assertSame( 'accepted', $b['state'] ); self::assertTrue( $b['replayed'] ); self::assertSame( 1, $a['binding_updates'] + $b['binding_updates'] ); self::assertSame( $a['binding_verified_at'], $b['binding_verified_at'] ); self::assertSame( 0, $a['protected_writes'] + $b['protected_writes'] ); self::assertCount( 4, $this->rows( 'operation_changes' ) ); self::assertSame( $protected, $this->protected_bytes() );
		// Distinct placement namespaces instead contend on the current publication.
		$first = $this->command( $this->seed( 2002 ) ); $second = $this->command( $this->seed( 2003 ) ); $protected = $this->protected_bytes(); self::assertSame( [ 'held', 'held', 'held', 'held', 'held', 'held' ], array_column( $this->rows( 'promotion_budget_reservations' ), 'state' ) );
		$left_file = $this->command_file( $first, name: 'verify-left.json' ); $directory = dirname( $left_file ); $right_file = $this->command_file( $second, $directory, 'verify-right.json' );
		$left = $this->worker( $left_file, 'verify', 'left' ); OperationProofProcess::wait_for( $directory . '/left-held' ); $held = json_decode( (string) file_get_contents( $directory . '/left-held' ), true, 4, JSON_THROW_ON_ERROR );
		$right = $this->worker( $right_file, 'verify', 'right' ); OperationProofProcess::wait_for( $directory . '/right-dispatched' ); $waiter = json_decode( (string) file_get_contents( $directory . '/right-dispatched' ), true, 4, JSON_THROW_ON_ERROR ); $this->wait_for_lock( $waiter['connection_id'], $held['connection_id'], 'publication' ); OperationProofBarrier::signal( $directory . '/release' );
		$a = $this->finish_worker( $left ); $b = $this->finish_worker( $right ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertSame( 'accepted', $a['state'] ); self::assertSame( 'accepted', $b['state'] ); self::assertFalse( $a['replayed'] ); self::assertFalse( $b['replayed'] ); self::assertSame( 2, $a['binding_updates'] + $b['binding_updates'] ); self::assertSame( 0, $a['protected_writes'] + $b['protected_writes'] ); self::assertCount( 3, $this->rows( 'delivery_quote_bindings' ) ); self::assertSame( [ 2, 2, 2 ], array_map( 'intval', array_column( $this->rows( 'delivery_quote_bindings' ), 'revision' ) ) ); self::assertCount( 12, $this->rows( 'operation_changes' ) ); self::assertSame( $protected, $this->protected_bytes() );
	}

	public function test_saved_digest_and_captured_physical_membership_must_match_original_bind_receipt(): void {
		// Distinct placement namespaces ensure every negative reaches its own proof,
		// rather than reusing a rejected operation with a conflicting intent.
		foreach ( [ 'snapshot', 'context', 'id', 'created_at' ] as $index => $kind ) {
			$bound = $this->seed( 3001 + $index ); $original = $this->command( $bound );
			if ( 'snapshot' === $kind || 'context' === $kind ) { $changed = $this->command( $bound, 'snapshot' === $kind ? Q::digest( 'unstored snapshot' ) : null, 'context' === $kind ? Q::digest( 'unstored context' ) : null, false ); }
			else { $value = 'id' === $kind ? $bound->binding->id() + 100 : Q::time()->plus_seconds( 3 )->sql(); $forged = QuoteBinding::from_row( array_replace( $bound->binding->row(), [ $kind => $value ] ), $bound->quote ); $changed = FixedPromotionQuoteVerifyCommand::create( $bound->command, $forged, $original->snapshot_digest(), $original->context_digest() ); }
			$before = $this->effects(); $stack = $this->stack( $changed ); $r = $stack->service->verify( $changed, $stack->inputs(), RequestContext::create() ); $this->no_verify( $before, $stack, $r ); self::assertNotSame( 'intent_conflict', $r->attempt->outcome->error?->code ); self::assertSame( 0, $stack->binding_updates() );
			if ( 'id' === $kind || 'created_at' === $kind ) { self::assertCount( 0, $stack->order->owners ); self::assertCount( 0, $stack->saved->owners ); } else { self::assertGreaterThanOrEqual( 1, count( $stack->saved->owners ) ); }
			// Explicit disposable-fixture compensation allows the next independent quote.
			$this->release( $original, 4 );
		}
	}

	public function test_current_publication_refuses_running_migration_hidden_from_old_repeatable_read(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects(); $changed = false; $old = null; $name = $this->database->real_escape_string( MigrationStatus::OPTION_NAME );
		$stack = $this->stack( $command, configure: function( Transport $transport ) use ( &$changed, &$old, $name ): void { $transport->before = function( string $sql, Transport $owner ) use ( &$changed, &$old, $name ): void {
			if ( $changed || ! str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $r = $owner->native_execute( "SELECT option_value FROM {$this->prefix}options WHERE option_name='{$name}'" ); self::assertTrue( $r->acknowledged ); $old = $r->rows[0]['option_value']; A::publication( $this->database, $this->prefix, 'running' ); $again = $owner->native_execute( "SELECT option_value FROM {$this->prefix}options WHERE option_name='{$name}'" ); self::assertSame( $old, $again->rows[0]['option_value'] );
		}; } );
		$r = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( 'success', unserialize( $old, [ 'allowed_classes' => false ] )['status'] ); $this->no_verify( $before, $stack, $r );
	}

	public function test_current_account_claim_locks_refuse_release_hidden_from_old_repeatable_read(): void {
		$command = $this->command( $this->seed() ); $expected = $this->effects(); $changed = false; $old = null;
		$stack = $this->stack( $command, configure: function( Transport $transport ) use ( $command, &$changed, &$old, &$expected ): void { $transport->before = function( string $sql, Transport $owner ) use ( $command, &$changed, &$old, &$expected ): void {
			if ( $changed || ! str_contains( $sql, 'delivery_engine_promotion_budget_accounts' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $snapshot = $owner->native_execute( "SELECT state FROM {$this->prefix}delivery_engine_promotion_budget_reservations ORDER BY id" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows; $this->release( $command ); $expected = $this->effects(); $again = $owner->native_execute( "SELECT state FROM {$this->prefix}delivery_engine_promotion_budget_reservations ORDER BY id" ); self::assertSame( $old, $again->rows );
		}; } );
		$r = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( [ [ 'state' => 'held' ], [ 'state' => 'held' ] ], $old ); self::assertSame( [ 'released', 'released' ], array_column( $this->rows( 'promotion_budget_reservations' ), 'state' ) ); $this->no_verify( $expected, $stack, $r ); self::assertSame( 0, $stack->binding_updates() );
	}

	public function test_current_order_and_saved_packet_locks_refuse_old_snapshot_money_and_mapping(): void {
		$command = $this->command( $this->seed() ); $clean_stack = $this->stack( $command );
		foreach ( [ 'order', 'saved' ] as $kind ) {
			OrderPort::put( $this->database, $this->prefix, $clean_stack->order->captured ); SavedPort::put( $this->database, $this->prefix, $clean_stack->saved->captured ); $expected = $this->effects(); $changed = false; $old = null; $stack = $this->stack( $command ); $port = 'order' === $kind ? $stack->order : $stack->saved; $table = 'order' === $kind ? OrderPort::table( $this->prefix ) : SavedPort::table( $this->prefix );
			$port->on_proof = function( $session, int $proof ) use ( $kind, $stack, $port, $table, &$changed, &$old, &$expected ): void {
				if ( 1 !== $proof ) { return; } $changed = true; $owner = $stack->physical->transports[0]; $snapshot = $owner->native_execute( "SELECT packet_json FROM {$table}" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows[0]['packet_json']; $new = $port->captured; if ( 'order' === $kind ) { $new['mapping']['groups'][0]['lines'][0]['quantity'] = '99'; OrderPort::put( $this->database, $this->prefix, $new ); } else { $new['snapshot_digest'] = Q::digest( 'newly committed saved snapshot' ); SavedPort::put( $this->database, $this->prefix, $new ); } $expected = $this->effects(); $again = $owner->native_execute( "SELECT packet_json FROM {$table}" ); self::assertSame( $old, $again->rows[0]['packet_json'] );
			};
			$r = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); $this->no_verify( $expected, $stack, $r ); self::assertSame( 0, $stack->binding_updates() );
		}
		foreach ( [ 'native_money_digest', 'manifest_digest', 'snapshot_digest', 'context_digest', 'binding_id', 'placement_uuid' ] as $field ) {
			OrderPort::put( $this->database, $this->prefix, $clean_stack->order->captured ); $packet = $clean_stack->saved->captured; $packet[$field] = 'binding_id' === $field ? 999 : ( 'placement_uuid' === $field ? QuoteId::generate()->value() : Q::digest( 'foreign saved facts' ) ); SavedPort::put( $this->database, $this->prefix, $packet ); $before = $this->effects(); $stack = $this->stack( $command ); $this->no_verify( $before, $stack, $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() );
		}
	}

	public function test_pause_source_policy_order_digest_and_corrupt_original_history_refuse_before_cas(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects();
		foreach ( [ 'pause', 'source', 'eligibility', 'order', 'saved', 'digest', 'receipt', 'foreign' ] as $kind ) {
			$stack = $this->stack( $command, site_id: 'foreign' === $kind ? 2 : 1 );
			if ( 'pause' === $kind ) { $this->control( 'checkout_suspended' ); } elseif ( 'source' === $kind ) { $this->fixture['source']->same = false; } elseif ( 'eligibility' === $kind ) { $stack->eligibility->allow = false; } elseif ( 'order' === $kind ) { $stack->order->allow = false; } elseif ( 'saved' === $kind ) { $stack->saved->allow = false; } elseif ( 'digest' === $kind ) { $stack->order->on_proof = static function() use ( $stack ): void { $stack->order->digest_override = Q::digest( 'changed original order identity' ); }; } elseif ( 'receipt' === $kind ) { DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_records SET intent_hash='" . Q::digest( 'wrong original binding intent' ) . "' WHERE operation='delivery_quote.bind'" ); }
			$this->no_verify( $before, $stack, $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() );
			$this->fixture['source']->same = true; $this->control( 'enabled' ); if ( 'receipt' === $kind ) { DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_records SET intent_hash='" . $command->binding_command()->intent()->fingerprint() . "' WHERE operation='delivery_quote.bind'" ); }
		}
		foreach ( [ 1, 2 ] as $index ) {
			$event = $this->rows( 'operation_changes' )[$index]; $id = (int) $event['id']; $original = $this->database->real_escape_string( $event['created_at'] ); DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_changes SET created_at='" . Q::time()->sql() . "' WHERE id={$id}" );
			try { $corrupt_before = $this->effects(); $stack = $this->stack( $command ); $this->no_verify( $corrupt_before, $stack, $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() ); self::assertCount( 0, $stack->order->owners ); self::assertCount( 0, $stack->saved->owners ); } finally { DB::execute( $this->database, "UPDATE {$this->prefix}delivery_engine_operation_changes SET created_at='{$original}' WHERE id={$id}" ); }
		}
		self::assertSame( $before, $this->effects() );
	}

	public function test_original_deadline_reversed_time_and_late_saved_proof_do_not_verify(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects();
		foreach ( [ 'deadline', 'reversed', 'slow' ] as $kind ) {
			$clock = match ( $kind ) { 'deadline' => Factory::NOW + 45, 'reversed' => Factory::NOW + 1, default => Factory::NOW + 3 }; $stack = $this->stack( $command, $clock );
			if ( 'slow' === $kind ) { $stack->saved->on_proof = static function( $session, int $proof ) use ( $stack ): void { if ( 2 === $proof ) { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + 45 ) )->acknowledged ); } }; }
			$this->no_verify( $before, $stack, $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_updates() ); if ( 'slow' === $kind ) { self::assertGreaterThanOrEqual( 2, count( $stack->saved->owners ) ); }
		}
	}

	public function test_actual_binding_update_failure_rolls_back_without_a_verification_event(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects(); $name = $this->prefix . 'reject_verification';
		DB::execute( $this->database, "CREATE TRIGGER {$name} BEFORE UPDATE ON {$this->prefix}delivery_engine_delivery_quote_bindings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned E14 verification rejection'" );
		$stack = $this->stack( $command ); $this->no_verify( $before, $stack, $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 1, $stack->binding_updates() );
	}

	public function test_audit_and_completion_faults_roll_back_a_physically_updated_binding(): void {
		$command = $this->command( $this->seed() );
		foreach ( [ 'audit', 'completion' ] as $kind ) {
			$before = $this->effects(); $reached = false;
			$stack = $this->stack( $command, configure: function( Transport $transport ) use ( $kind, &$reached ): void { $transport->{'reject_' . $kind} = true; $transport->after = function( string $sql, Transport $owner, $result ) use ( &$reached ): void { if ( $result->acknowledged && 1 === $result->affected_rows && str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, 'delivery_engine_delivery_quote_bindings' ) ) { $reached = true; } }; } );
			$this->no_verify( $before, $stack, $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ) ); self::assertTrue( $reached, 'The physical binding CAS must succeed before the later fault.' ); self::assertSame( 1, $stack->binding_updates() );
		}
	}

	public function test_lost_effect_commit_acknowledgement_recovers_exact_verified_binding_in_fresh_process(): void {
		$command = $this->command( $this->seed() ); $file = $this->command_file( $command ); $protected = $this->protected_bytes(); $first = true;
		$stack = $this->stack( $command, configure: static function( Transport $transport ) use ( &$first ): void { if ( $first ) { $first = false; $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } } );
		$r = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->quote ); self::assertNull( $r->binding ); self::assertSame( 2, (int) $this->rows( 'delivery_quote_bindings' )[0]['revision'] ); self::assertSame( $protected, $this->protected_bytes() );
		$before = $this->effects(); $history = $this->finish_worker( $this->worker( $file ) ); self::assertNotSame( getmypid(), $history['process_id'] ); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( 'prepared', $history['binding_state'] ); self::assertSame( 2, $history['binding_revision'] ); self::assertSame( $command->prepared_binding()->row()['order_id'], $history['order_id'] ); self::assertSame( $command->header()->to_private_json(), $history['header_json'] ); self::assertSame( $command->snapshot_digest(), $history['snapshot_digest'] ); self::assertSame( $command->context_digest(), $history['context_digest'] ); self::assertSame( [ 0, 0, 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['order_proofs'], $history['saved_proofs'], $history['binding_updates'], $history['protected_writes'], $history['native_input_reads'] ] ); self::assertSame( $before, $this->effects() );
	}

	public function test_pending_publication_after_expiry_and_release_reconciles_history_only_idempotently(): void {
		$command = $this->command( $this->seed() ); $stack = $this->stack( $command ); $stack->publication->allow = false; $protected = $this->protected_bytes();
		$r = $stack->service->verify( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertTrue( $r->attempt->outcome->publication_pending ); self::assertSame( 'accepted', $r->attempt->completion?->state ); self::assertNotNull( $r->quote ); self::assertNotNull( $r->binding ); self::assertSame( 2, $r->binding->revision() ); self::assertSame( $protected, $this->protected_bytes() );
		$this->release( $command, 400 ); $before = $this->effects(); $file = $this->command_file( $command ); $history = $this->finish_worker( $this->worker( $file, clock: Factory::NOW + 400 ) ); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( $r->binding->id(), $history['binding_id'] ); self::assertSame( $r->binding->row()['verified_at'], $history['binding_verified_at'] ); self::assertSame( [ 0, 0, 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['order_proofs'], $history['saved_proofs'], $history['binding_updates'], $history['protected_writes'], $history['native_input_reads'] ] ); self::assertSame( $before, $this->effects() );
		$records = $this->rows( 'operation_records' ); self::assertSame( 'published', $records[3]['publication_state'] ); $again = $this->finish_worker( $this->worker( $file, clock: Factory::NOW + 400 ) ); self::assertSame( 'accepted', $again['state'] ); self::assertSame( $records, $this->rows( 'operation_records' ) ); self::assertSame( $before, $this->effects() );
	}
}
