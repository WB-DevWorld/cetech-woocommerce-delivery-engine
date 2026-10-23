<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetAllocationService;
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptResult, FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindResult, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate};
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteId, QuoteOwner};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionQuoteAcceptSqlStack as AcceptStack, PromotionQuoteBindOrderPort as OrderPort, PromotionQuoteBindSqlStack as Stack, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as IssueStack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteBindSqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Twelve real binding/C03 preservation cases; source/native/eligibility/candidate-order ports are synthetic. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionQuoteBindRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $fixture;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix ); B::install( $this->database, $this->prefix ); A::publication( $this->database, $this->prefix, 'success' ); OrderPort::install( $this->database, $this->prefix );
		$session = ( new PromotionQuoteIssueSqlFactory( $this->factory() ) )->open(); self::assertTrue( $session->begin() ); $this->fixture = Ports::capture( $session ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		// Enough for two independent 6.00 quotes when uniqueness cases require them.
		$state = FixedPromotionBudgetState::create( 1, $this->fixture['slots'][0]['account_key'], F::rule()->private_facts()['funding_source'], B::money( '12.00' ) ); $values = FixedPromotionBudgetRecordCodec::account_values( $state );
		$columns = implode( ',', array_map( static fn( string $key ): string => "`{$key}`", array_keys( $values ) ) ); $quoted = array_map( fn( mixed $value ): string => "'" . $this->database->real_escape_string( (string) $value ) . "'", array_values( $values ) ); DB::execute( $this->database, "INSERT INTO `{$this->prefix}delivery_engine_promotion_budget_accounts` ({$columns}) VALUES (" . implode( ',', $quoted ) . ')' );
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); } foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function factory( int $clock = Factory::NOW ): Factory { $f = new Factory( $this->prefix ); $f->clock = $clock; $this->factories[] = $f; return $f; }
	private function stack( FixedPromotionQuoteBindCommand $command, int $clock = Factory::NOW + 2, ?callable $configure = null, int $site_id = 1 ): Stack { $s = new Stack( $this->prefix, $this->fixture, $command, $clock, $configure, $site_id ); $this->factories[] = $s->physical; return $s; }
	private function seed(): FixedPromotionQuoteAcceptResult {
		$issue = new IssueStack( $this->prefix, $this->fixture ); $this->factories[] = $issue->physical; $token = QuoteId::generate()->value(); $original = $issue->original( $token );
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, null, new EmergencyControlStore( static fn(): bool => false ) );
		$admission = $gate->admit( QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'E13 owned synthetic server draft' ), $token ), QuotePreparationAttempt::generate() );
		self::assertSame( 'preparation_allowed', $admission->status ); self::assertNotNull( $admission->lease ); self::assertTrue( $admission->lease->claim_preparation() );
		$issued = $issue->service->issue_admitted( $original, $admission->lease->bind_issue( $original ), $issue->inputs(), RequestContext::create(), static fn(): bool => true );
		self::assertSame( 'accepted', $issued->attempt->outcome->state ); self::assertNotNull( $issued->command );
		$accept = new AcceptStack( $this->prefix, $this->fixture ); $this->factories[] = $accept->physical; $accepted = $accept->service->accept( FixedPromotionQuoteAcceptCommand::from_issue( $issued->command ), $accept->inputs(), RequestContext::create() );
		self::assertSame( 'accepted', $accepted->attempt->outcome->state ); self::assertNotNull( $accepted->quote ); self::assertNotNull( $accepted->command ); return $accepted;
	}
	private function command( FixedPromotionQuoteAcceptResult $accepted, int $order = 1001, ?QuoteId $placement = null, ?array $mapping = null ): FixedPromotionQuoteBindCommand {
		$mapping ??= OrderPort::mapping( $accepted->quote, $order ); $packet = OrderPort::packet( $accepted->quote, $order, $mapping );
		$command = FixedPromotionQuoteBindCommand::create( $accepted->command, $accepted->quote, $order, $placement ?? QuoteId::generate(), $mapping, OrderPort::digest( $packet ) );
		OrderPort::put( $this->database, $this->prefix, $packet ); return $command;
	}
	private function rows( string $suffix ): array { $r = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned bind census failed.' ); } return $r->fetch_all( MYSQLI_ASSOC ); }
	private function protected_bytes(): array { $out = []; foreach ( [ 'delivery_quotes', 'promotion_budget_accounts', 'promotion_budget_reservations', 'delivery_quote_budget_windows' ] as $suffix ) { $out[$suffix] = $this->rows( $suffix ); } return $out; }
	private function effects(): array { return [ 'bindings' => $this->rows( 'delivery_quote_bindings' ), 'events' => $this->rows( 'operation_changes' ), 'protected' => $this->protected_bytes() ]; }
	private function bound( FixedPromotionQuoteBindResult $r ): void { self::assertSame( 'accepted', $r->attempt->outcome->state, $r->attempt->outcome->error?->code ?? '' ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertNotNull( $r->quote ); self::assertSame( 'accepted', $r->quote->state() ); self::assertSame( 2, $r->quote->revision() ); self::assertNotNull( $r->binding ); self::assertSame( 'prepared', $r->binding->state() ); self::assertSame( 1, $r->binding->revision() ); }
	private function no_bind( array $before, Stack $stack, FixedPromotionQuoteBindResult $r ): void { self::assertNotSame( true, $r->attempt->outcome->mutation_accepted ); self::assertNull( $r->binding ); self::assertSame( $before, $this->effects() ); self::assertSame( [], $stack->ledger_writes() ); }
	private function release( FixedPromotionQuoteBindCommand $command, int $offset = 2 ): void {
		$s = $this->factory()->open(); self::assertTrue( $s->begin() ); $rows = ( new FixedPromotionBudgetAllocationService( new SqlFixedPromotionBudgetStore(), static fn(): bool => true ) )->release_all( $s, $command->allocation_set(), Q::time()->plus_seconds( $offset ) ); self::assertCount( 2, $rows ); self::assertSame( [ 'released', 'released' ], array_map( static fn( $r ): string => $r->state(), $rows ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); self::assertTrue( $s->retire() );
	}
	private function control( string $state ): void { $json = EmergencyControlState::record_json( 1, $state, 2, 'enabled' === $state ? 'resume_verified' : 'operator_pause', 9, Factory::NOW ); $s = $this->database->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value) VALUES ('cetech_de_checkout_control_v1',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" ); self::assertNotFalse( $s ); self::assertTrue( $s->bind_param( 's', $json ) ); self::assertTrue( $s->execute() ); $s->close(); }
	private function command_file( FixedPromotionQuoteBindCommand $command, ?string $directory = null, string $name = 'bind-recovery.json' ): string {
		if ( null === $directory ) { $directory = OperationProofBarrier::directory(); $this->directories[] = $directory; } $file = $directory . '/' . $name; $bytes = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ); self::assertSame( strlen( $bytes ), file_put_contents( $file, $bytes, LOCK_EX ) ); self::assertTrue( chmod( $file, 0600 ) ); return $file;
	}
	private function worker( string $file, string $mode = 'reconcile', string $role = 'history', int $clock = Factory::NOW + 2 ): OperationProofProcess { $p = new OperationProofProcess( [ __DIR__ . '/promotion-quote-bind-process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'command_file' => $file, 'mode' => $mode, 'role' => $role, 'clock' => $clock ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
	/** This test-local diagnostic never copies worker stdout, stderr or private recovery facts. */
	private function bind_worker_status( string $directory, string $role ): array {
		$status = [ 'phase' => 'unknown', 'state' => 'unknown', 'exception_class' => 'none' ];
		$file = $directory . '/bind-' . $role . '-status.json'; clearstatcache( true, $file );
		$size = is_file( $file ) ? filesize( $file ) : false; if ( ! is_int( $size ) || $size < 1 || $size > 1024 ) { return $status; }
		$bytes = file_get_contents( $file ); if ( ! is_string( $bytes ) || strlen( $bytes ) > 1024 ) { return $status; }
		try { $row = json_decode( $bytes, true, 3, JSON_THROW_ON_ERROR ); } catch ( \Throwable ) { return $status; }
		if ( ! is_array( $row ) || array_keys( $row ) !== [ 'phase', 'state', 'exception_class' ] ) { return $status; }
		$phases = [ 'bootstrap', 'recovery', 'source_open', 'source_capture', 'source_cleanup', 'configured', 'running', 'namespace_reservation', 'namespace_lock', 'control_lock', 'publication_dispatched', 'account_lock', 'order_lock', 'quote_lock', 'binding_lock', 'effect_held', 'effect_released', 'cleanup' ];
		$states = [ 'running', 'accepted', 'rejected', 'unconfirmed', 'not_applicable', 'failed' ];
		$classes = [ 'none', 'RuntimeException', 'InvalidArgumentException', 'LogicException', 'TypeError', 'ErrorException', 'Error', 'Exception', 'Throwable' ];
		if ( in_array( $row['phase'], $phases, true ) && in_array( $row['state'], $states, true ) && in_array( $row['exception_class'], $classes, true ) ) { return $row; } return $status;
	}
	private function wait_for_bind_marker( string $directory, string $marker, string $role, float $seconds ): void {
		if ( ! in_array( [ $marker, $role ], [ [ 'left-held', 'left' ], [ 'right-dispatched', 'right' ] ], true ) || 20.0 !== $seconds ) { throw new \LogicException( 'Owned E13 barrier descriptor refused.' ); }
		$end = microtime( true ) + $seconds;
		do {
			clearstatcache( true, $directory . '/' . $marker ); if ( is_file( $directory . '/' . $marker ) ) { return; }
			$status = $this->bind_worker_status( $directory, $role );
			if ( ! in_array( $status['state'], [ 'unknown', 'running' ], true ) ) { break; }
			usleep( 10000 );
		} while ( microtime( true ) < $end );
		clearstatcache( true, $directory . '/' . $marker ); if ( is_file( $directory . '/' . $marker ) ) { return; }
		$status = $this->bind_worker_status( $directory, $role );
		throw new \RuntimeException( 'Owned E13 marker not reached: role=' . $role . ' phase=' . $status['phase'] . ' state=' . $status['state'] . ' exception_class=' . $status['exception_class'] . '.' );
	}
	private function wait_for_publication_wait( int $waiter, int $blocker ): void {
		self::assertNotSame( $waiter, $blocker ); $table = $this->prefix . 'options'; $end = microtime( true ) + 3.0; $waiting = false;
		$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT('`',DATABASE(),'`.`{$table}`') AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
		do { $waiting = (int) DB::scalar( $this->database, $sql ) > 0; if ( $waiting ) { break; } usleep( 10000 ); } while ( microtime( true ) < $end ); self::assertTrue( $waiting, 'The competing binding process must wait on the original current-publication owner.' );
	}

	public function test_atomic_binding_and_exact_history_replay_preserve_money_and_reject_changed_intent(): void {
		$accepted = $this->seed(); $command = $this->command( $accepted ); $protected = $this->protected_bytes(); $captures = $this->fixture['provider']->captures; $reads = $this->fixture['source']->reads; $stack = $this->stack( $command );
		$r = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); $this->bound( $r ); self::assertSame( $protected, $this->protected_bytes() ); self::assertSame( [], $stack->ledger_writes() ); self::assertSame( $captures, $this->fixture['provider']->captures ); self::assertSame( $reads, $this->fixture['source']->reads );
		self::assertSame( $command->binding()->mapping(), $r->binding->mapping() ); self::assertSame( Q::time()->plus_seconds( 2 )->sql(), $r->binding->row()['created_at'] ); self::assertNull( $r->binding->row()['verified_at'] ); self::assertNull( $r->binding->row()['sealed_at'] ); self::assertNull( $r->binding->row()['snapshot_digest'] );
		self::assertCount( 3, $this->rows( 'operation_records' ) ); self::assertCount( 3, $this->rows( 'operation_changes' ) ); $event = json_decode( $this->rows( 'operation_changes' )[2]['event_json'], true, 16, JSON_THROW_ON_ERROR ); self::assertSame( 'quote_bound', $event['reason_code'] ); self::assertSame( [ 'binding' ], $event['changed_fields'] ); self::assertSame( 1, $event['operation_version'] );
		$before = $this->effects(); $stack->eligibility->poison = true; $stack->order->poison = true; $again = $stack->service->reconcile( $command, RequestContext::create() ); $this->bound( $again ); self::assertTrue( $again->attempt->replayed ); self::assertSame( $r->binding->row(), $again->binding->row() ); self::assertSame( $before, $this->effects() );
		$changed = $this->command( $accepted, 1002, QuoteId::from_string( $command->binding()->row()['placement_uuid'] ) ); $other = $this->stack( $changed ); $denied = $other->service->bind( $changed, $other->inputs(), RequestContext::create() ); $this->no_bind( $before, $other, $denied );
	}
	public function test_two_actual_processes_competing_for_one_quote_make_only_one_binding(): void {
		$accepted = $this->seed(); $left_command = $this->command( $accepted, 2001 ); $right_command = $this->command( $accepted, 2002 ); $protected = $this->protected_bytes();
		$left_file = $this->command_file( $left_command, name: 'bind-left.json' ); $directory = dirname( $left_file ); $right_file = $this->command_file( $right_command, $directory, 'bind-right.json' );
		$left = $this->worker( $left_file, 'bind', 'left' ); $this->wait_for_bind_marker( $directory, 'left-held', 'left', 20.0 ); $held = json_decode( (string) file_get_contents( $directory . '/left-held' ), true, 4, JSON_THROW_ON_ERROR );
		$right = $this->worker( $right_file, 'bind', 'right' ); $this->wait_for_bind_marker( $directory, 'right-dispatched', 'right', 20.0 ); $waiter = json_decode( (string) file_get_contents( $directory . '/right-dispatched' ), true, 4, JSON_THROW_ON_ERROR ); $this->wait_for_publication_wait( $waiter['connection_id'], $held['connection_id'] ); OperationProofBarrier::signal( $directory . '/release' );
		$a = $left->finish(); $b = $right->finish(); self::assertNotSame( getmypid(), $a['process_id'] ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertSame( 'accepted', $a['state'] ); self::assertSame( 'rejected', $b['state'] ); self::assertSame( 1, $a['binding_inserts'] + $b['binding_inserts'] ); self::assertSame( 0, $a['protected_writes'] + $b['protected_writes'] ); self::assertSame( 1, $a['native_input_reads'] ); self::assertSame( 1, $b['native_input_reads'] ); self::assertCount( 1, $this->rows( 'delivery_quote_bindings' ) ); self::assertCount( 3, $this->rows( 'operation_changes' ) ); self::assertSame( $protected, $this->protected_bytes() );
	}
	public function test_distinct_quotes_cannot_duplicate_a_placement_or_order_manifest(): void {
		$first = $this->seed(); $second = $this->seed(); $a = $this->command( $first, 3001 ); $stack = $this->stack( $a ); $this->bound( $stack->service->bind( $a, $stack->inputs(), RequestContext::create() ) ); $before = $this->effects();
		foreach ( [ [ 3002, QuoteId::from_string( $a->binding()->row()['placement_uuid'] ) ], [ 3001, QuoteId::generate() ] ] as [ $order, $placement ] ) { $b = $this->command( $second, $order, $placement ); $other = $this->stack( $b ); $r = $other->service->bind( $b, $other->inputs(), RequestContext::create() ); $this->no_bind( $before, $other, $r ); self::assertSame( 1, $other->binding_inserts() ); }
	}
	public function test_current_publication_refuses_running_migration_hidden_from_old_repeatable_read(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects(); $changed = false; $old = null; $name = $this->database->real_escape_string( MigrationStatus::OPTION_NAME );
		$stack = $this->stack( $command, configure: function( Transport $transport ) use ( &$changed, &$old, $name ): void { $transport->before = function( string $sql, Transport $owner ) use ( &$changed, &$old, $name ): void {
			if ( $changed || ! str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $r = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertTrue( $r->acknowledged ); $old = $r->rows[0]['option_value']; A::publication( $this->database, $this->prefix, 'running' ); $again = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertSame( $old, $again->rows[0]['option_value'] );
		}; } );
		$r = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( 'success', unserialize( $old, [ 'allowed_classes' => false ] )['status'] ); $this->no_bind( $before, $stack, $r );
	}
	public function test_current_account_claim_lock_refuses_release_hidden_from_old_repeatable_read(): void {
		$command = $this->command( $this->seed() ); $before_bindings = $this->rows( 'delivery_quote_bindings' ); $before_events = $this->rows( 'operation_changes' ); $quotes = $this->rows( 'delivery_quotes' ); $changed = false; $old = null;
		$stack = $this->stack( $command, configure: function( Transport $transport ) use ( $command, &$changed, &$old ): void { $transport->before = function( string $sql, Transport $owner ) use ( $command, &$changed, &$old ): void {
			if ( $changed || ! str_contains( $sql, 'delivery_engine_promotion_budget_accounts`' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $snapshot = $owner->native_execute( "SELECT state FROM `{$this->prefix}delivery_engine_promotion_budget_reservations` ORDER BY id" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows; $this->release( $command ); $again = $owner->native_execute( "SELECT state FROM `{$this->prefix}delivery_engine_promotion_budget_reservations` ORDER BY id" ); self::assertSame( $old, $again->rows );
		}; } );
		$r = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( [ [ 'state' => 'held' ], [ 'state' => 'held' ] ], $old ); self::assertNotSame( true, $r->attempt->outcome->mutation_accepted ); self::assertNull( $r->binding ); self::assertSame( [ 'released', 'released' ], array_column( $this->rows( 'promotion_budget_reservations' ), 'state' ) ); self::assertSame( $before_bindings, $this->rows( 'delivery_quote_bindings' ) ); self::assertSame( $before_events, $this->rows( 'operation_changes' ) ); self::assertSame( $quotes, $this->rows( 'delivery_quotes' ) ); self::assertSame( [], $stack->ledger_writes() );
	}
	public function test_current_synthetic_order_lock_refuses_stale_items_money_and_owner_packets(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects(); $stack = $this->stack( $command ); $changed = false; $old = null; $table = OrderPort::table( $this->prefix );
		$stack->order->on_proof = function( $session, int $proof ) use ( $stack, $table, &$changed, &$old ): void {
			if ( 1 !== $proof ) { return; } $changed = true; $owner = $stack->physical->transports[0]; $snapshot = $owner->native_execute( "SELECT packet_json FROM `{$table}`" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows[0]['packet_json']; $new = $stack->order->captured; $new['mapping']['groups'][0]['lines'][0]['quantity'] = '99'; OrderPort::put( $this->database, $this->prefix, $new ); $again = $owner->native_execute( "SELECT packet_json FROM `{$table}`" ); self::assertSame( $old, $again->rows[0]['packet_json'] );
		};
		$r = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); $this->no_bind( $before, $stack, $r );
		foreach ( [ 'owner_digest', 'native_money_digest', 'site_id', 'order_id', 'product_id', 'variation_id' ] as $field ) {
			$other = $this->stack( $command ); $packet = $other->order->captured;
			if ( in_array( $field, [ 'product_id', 'variation_id' ], true ) ) { $packet['mapping']['groups'][0]['lines'][0][$field] = 999; } else { $packet[$field] = in_array( $field, [ 'site_id', 'order_id' ], true ) ? 999 : Q::digest( 'foreign synthetic order money or owner' ); }
			// Keep the physical lookup address constant; only the captured payload is stale.
			$stmt = $this->database->prepare( "UPDATE `{$table}` SET packet_json=?" ); $json = json_encode( $packet, JSON_THROW_ON_ERROR ); self::assertTrue( $stmt->bind_param( 's', $json ) ); self::assertTrue( $stmt->execute() ); $stmt->close(); $this->no_bind( $before, $other, $other->service->bind( $command, $other->inputs(), RequestContext::create() ) );
		}
	}
	public function test_pause_source_policy_order_digest_and_corrupt_or_foreign_history_refuse(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects();
		foreach ( [ 'pause', 'source', 'eligibility', 'order', 'digest', 'receipt', 'foreign' ] as $kind ) {
			$stack = $this->stack( $command, site_id: 'foreign' === $kind ? 2 : 1 );
			if ( 'pause' === $kind ) { $this->control( 'checkout_suspended' ); } elseif ( 'source' === $kind ) { $this->fixture['source']->same = false; } elseif ( 'eligibility' === $kind ) { $stack->eligibility->allow = false; } elseif ( 'order' === $kind ) { $stack->order->allow = false; } elseif ( 'digest' === $kind ) { $stack->order->on_proof = static function() use ( $stack ): void { $stack->order->digest_override = Q::digest( 'changed candidate identity after proof' ); }; } elseif ( 'receipt' === $kind ) { DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET intent_hash='" . Q::digest( 'wrong original acceptance intent' ) . "' WHERE operation='delivery_quote.accept'" ); }
			$this->no_bind( $before, $stack, $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ) );
			$this->fixture['source']->same = true; $this->control( 'enabled' ); if ( 'receipt' === $kind ) { DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET intent_hash='" . $command->acceptance()->intent()->fingerprint() . "' WHERE operation='delivery_quote.accept'" ); }
		}
		// A structurally correct receipt cannot borrow an event timestamp before its own effect.
		$event = $this->rows( 'operation_changes' )[1]; $event_id = (int) $event['id']; $original_at = $this->database->real_escape_string( $event['created_at'] );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_changes` SET created_at='" . Q::time()->sql() . "' WHERE id={$event_id}" );
		try {
			$corrupt_before = $this->effects(); $stack = $this->stack( $command );
			$this->no_bind( $corrupt_before, $stack, $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ) ); self::assertCount( 0, $stack->order->owners ); self::assertSame( 0, $stack->binding_inserts() );
		} finally { DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_changes` SET created_at='{$original_at}' WHERE id={$event_id}" ); }
		self::assertSame( $before, $this->effects() );
	}
	public function test_original_deadline_and_order_proof_that_stalls_to_final_deadline_do_not_bind(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects();
		foreach ( [ 'deadline', 'slow' ] as $kind ) {
			$stack = $this->stack( $command, 'deadline' === $kind ? Factory::NOW + 45 : Factory::NOW + 2 );
			if ( 'slow' === $kind ) { $stack->order->on_proof = static function( $session, int $proof ) use ( $stack ): void { if ( 2 === $proof ) { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + 45 ) )->acknowledged ); } }; }
			$this->no_bind( $before, $stack, $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 0, $stack->binding_inserts() ); if ( 'slow' === $kind ) { self::assertGreaterThanOrEqual( 2, count( $stack->order->owners ) ); }
		}
	}
	public function test_actual_binding_insert_failure_rolls_back_without_a_binding_event(): void {
		$command = $this->command( $this->seed() ); $before = $this->effects(); $name = $this->prefix . 'reject_binding';
		DB::execute( $this->database, "CREATE TRIGGER `{$name}` BEFORE INSERT ON `{$this->prefix}delivery_engine_delivery_quote_bindings` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned E13 binding rejection'" );
		$stack = $this->stack( $command ); $this->no_bind( $before, $stack, $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ) ); self::assertSame( 1, $stack->binding_inserts() );
	}
	public function test_audit_and_completion_faults_each_roll_back_a_physically_inserted_binding(): void {
		$accepted = $this->seed();
		foreach ( [ 'audit', 'completion' ] as $index => $kind ) {
			$command = $this->command( $accepted, 4001 + $index ); $before = $this->effects(); $reached = false;
			$stack = $this->stack( $command, configure: function( Transport $transport ) use ( $kind, &$reached ): void { $transport->{'reject_' . $kind} = true; $transport->after = function( string $sql, Transport $owner, $result ) use ( &$reached ): void { if ( $result->acknowledged && str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'delivery_engine_delivery_quote_bindings' ) ) { $reached = true; } }; } );
			$this->no_bind( $before, $stack, $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ) ); self::assertTrue( $reached, 'The binding INSERT must succeed before the later fault.' ); self::assertSame( 1, $stack->binding_inserts() );
		}
	}
	public function test_lost_effect_commit_acknowledgement_recovers_exact_binding_in_a_fresh_process(): void {
		$command = $this->command( $this->seed() ); $file = $this->command_file( $command ); $protected = $this->protected_bytes(); $first = true;
		$stack = $this->stack( $command, configure: static function( Transport $transport ) use ( &$first ): void { if ( $first ) { $first = false; $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } } );
		$r = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->quote ); self::assertNull( $r->binding ); self::assertCount( 1, $this->rows( 'delivery_quote_bindings' ) ); self::assertSame( $protected, $this->protected_bytes() );
		$before = $this->effects(); $history = $this->worker( $file )->finish(); self::assertNotSame( getmypid(), $history['process_id'] ); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( 'prepared', $history['binding_state'] ); self::assertSame( 1, $history['binding_revision'] ); self::assertSame( $command->binding()->row()['order_id'], $history['order_id'] ); self::assertSame( $command->header()->to_private_json(), $history['header_json'] ); self::assertSame( [ 0, 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['order_proofs'], $history['binding_inserts'], $history['protected_writes'], $history['native_input_reads'] ] ); self::assertSame( $before, $this->effects() );
	}
	public function test_pending_publication_after_expiry_and_release_is_history_only_and_idempotent(): void {
		$command = $this->command( $this->seed() ); $stack = $this->stack( $command ); $stack->publication->allow = false; $protected = $this->protected_bytes();
		$r = $stack->service->bind( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertTrue( $r->attempt->outcome->publication_pending ); self::assertSame( 'accepted', $r->attempt->completion?->state ); self::assertNotNull( $r->quote ); self::assertNotNull( $r->binding ); self::assertSame( 'prepared', $r->binding->state() ); self::assertSame( 1, $r->binding->revision() ); self::assertSame( $protected, $this->protected_bytes() );
		$this->release( $command, 400 ); $before = $this->effects(); $file = $this->command_file( $command ); $history = $this->worker( $file, clock: Factory::NOW + 400 )->finish(); self::assertSame( 'accepted', $history['state'] ); self::assertTrue( $history['replayed'] ); self::assertSame( $r->binding->id(), $history['binding_id'] ); self::assertSame( $r->binding->row()['created_at'], $history['binding_created_at'] ); self::assertSame( [ 0, 0, 0, 0, 0 ], [ $history['eligibility_proofs'], $history['order_proofs'], $history['binding_inserts'], $history['protected_writes'], $history['native_input_reads'] ] ); self::assertSame( $before, $this->effects() );
		$records = $this->rows( 'operation_records' ); self::assertSame( 'published', $records[2]['publication_state'] ); $again = $this->worker( $file, clock: Factory::NOW + 400 )->finish(); self::assertSame( 'accepted', $again['state'] ); self::assertSame( $records, $this->rows( 'operation_records' ) ); self::assertSame( $before, $this->effects() );
	}
}
