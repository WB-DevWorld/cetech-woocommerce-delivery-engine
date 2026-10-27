<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetAllocationService, FixedPromotionBudgetService, FixedPromotionQuotePreparationService};
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetAllocationSet, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetOperationReadiness, FixedPromotionBudgetSchema as Schema, SqlFixedPromotionBudgetStore as Store};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\PromotionBudgetSqlFixture as B;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{AllocationPreparationEligibilityPort, AllocationPreparationSqlPorts, PromotionBudgetAllocationSqlFixture as F};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory as Factory;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier as Barrier, OperationProofProcess as Process};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Exactly ten mandatory actual InnoDB cases. Synthetic source/native ports in case10 are explicitly separate. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionBudgetAllocationRealDatabaseTest extends TestCase {
	private \mysqli $db;
	private string $prefix;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];
	protected function setUp(): void { $this->db = DB::connect(); $this->prefix = DB::prefix(); F::install( $this->db, $this->prefix ); }
	protected function tearDown(): void {
		foreach ( $this->processes as $p ) { $p->kill(); } foreach ( $this->factories as $f ) { $f->close_all(); } foreach ( $this->directories as $d ) { Barrier::cleanup( $d ); }
		if ( isset( $this->db, $this->prefix ) ) { DB::cleanup( $this->db, $this->prefix ); self::assertSame( '0', (string) DB::scalar( $this->db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix ) . ")='" . $this->db->real_escape_string( $this->prefix ) . "'" ) ); $this->db->close(); }
	}
	private function factory( ?callable $configure = null ): Factory { $f = new Factory( $this->prefix ); $f->configure = null === $configure ? null : \Closure::fromCallable( $configure ); $this->factories[] = $f; return $f; }
	private function owner(): OperationSession { $s = $this->factory()->open(); self::assertTrue( $s->begin() ); return $s; }
	private function seed( FixedPromotionBudgetAllocationSet $set, string $limit = '6.00' ): void {
		$s = $this->owner(); $seen = []; $store = new Store();
		foreach ( $set->requests() as $request ) { if ( isset( $seen[$request->account_key()] ) ) { continue; } $seen[$request->account_key()] = true; self::assertTrue( $store->insert_account( $s, B::account( $request, $limit ) ) ); }
		self::assertSame( OperationCommitResult::Acknowledged, $s->commit() );
	}
	private function count_rows( string $suffix, ?OperationSession $session = null ): int { $table = Schema::table( $this->prefix, $suffix ); return (int) ( null === $session ? DB::scalar( $this->db, "SELECT COUNT(*) FROM `{$table}`" ) : $session->get_row( "SELECT COUNT(*) AS n FROM `{$table}`" )['n'] ); }
	private function snapshot(): array { $out = []; foreach ( Schema::SUFFIXES as $suffix ) { $table = Schema::table( $this->prefix, $suffix ); $result = $this->db->query( "SELECT * FROM `{$table}` ORDER BY id" ); if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned allocation snapshot failed.' ); } $out[$suffix] = $result->fetch_all( MYSQLI_ASSOC ); } return $out; }
	private function refuse( callable $call, string $class = \DomainException::class ): void { try { $call(); self::fail( 'The exact allocation boundary must refuse.' ); } catch ( \Throwable $e ) { self::assertInstanceOf( $class, $e ); } }
	private function worker( array $args ): Process { $p = new Process( [ __DIR__ . '/promotion-budget-allocation-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $args, JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
	private static function bytes( array $claims ): array { return array_map( static fn( $c ): string => $c->to_private_json(), $claims ); }

	public function test_two_compiled_components_share_one_real_pool_with_exact_hold_and_retry(): void {
		$set = F::set(); self::assertCount( 2, $set->requests() ); self::assertCount( 1, $set->account_keys() ); $this->seed( $set ); $at = $set->header()->created_at(); $store = new Store(); $s = $this->owner();
		$first = F::service()->reserve_all( $s, $set, $at, $at->plus_seconds( 60 ) ); self::assertSame( [ 'held', 'held' ], array_column( array_map( static fn( $c ): array => $c->private_facts(), $first ), 'state' ) );
		self::assertSame( [ '6.00', '0.00', 3 ], [ $store->lock_account( $s, $set->account_keys()[0] )->held()->amount(), $store->lock_account( $s, $set->account_keys()[0] )->remaining()->amount(), $store->lock_account( $s, $set->account_keys()[0] )->revision() ] );
		self::assertSame( self::bytes( $first ), self::bytes( F::service()->reserve_all( $s, $set, $at->plus_seconds( 1 ), $at->plus_seconds( 60 ) ) ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); self::assertSame( 2, $this->count_rows( Schema::RESERVATIONS_SUFFIX ) );
	}
	public function test_shared_pool_aggregate_insufficiency_refuses_before_any_write(): void {
		$set = F::set(); $this->seed( $set, '5.00' ); $before = $this->snapshot(); $s = $this->owner(); $at = $set->header()->created_at();
		$this->refuse( fn() => F::service()->reserve_all( $s, $set, $at, $at->plus_seconds( 60 ) ) ); self::assertSame( 0, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $s ) ); self::assertTrue( ( new Store() )->lock_account( $s, $set->account_keys()[0] )->held()->zero() ); self::assertTrue( $s->rollback() ); self::assertSame( $before, $this->snapshot() );
	}
	public function test_independent_reversed_two_pool_inputs_take_sorted_account_locks_and_cannot_overspend(): void {
		$left_set = F::set( false ); $right_set = F::set( false, true ); $this->seed( $left_set, '3.00' ); $d = Barrier::directory(); $this->directories[] = $d;
		$left = $this->worker( [ 'packet' => F::packet( $left_set ), 'ready' => $d . '/left', 'release' => $d . '/go-left', 'hold_ready' => $d . '/held', 'hold_release' => $d . '/finish-left' ] );
		Process::wait_for( $d . '/left' ); Barrier::signal( $d . '/go-left' ); Process::wait_for( $d . '/held' );
		$right = $this->worker( [ 'packet' => F::packet( $right_set ), 'ready' => $d . '/right', 'release' => $d . '/go-right', 'lock_dispatch' => $d . '/dispatch' ] ); Process::wait_for( $d . '/right' );
		$l = json_decode( (string) file_get_contents( $d . '/left' ), true, 4, JSON_THROW_ON_ERROR ); $r = json_decode( (string) file_get_contents( $d . '/right' ), true, 4, JSON_THROW_ON_ERROR ); Barrier::signal( $d . '/go-right' ); Process::wait_for( $d . '/dispatch' );
		$dispatch = json_decode( (string) file_get_contents( $d . '/dispatch' ), true, 4, JSON_THROW_ON_ERROR ); self::assertSame( $r['connection_id'], $dispatch['connection_id'] ); self::assertSame( $right->pid(), $dispatch['process_id'] );
		$deadline = microtime( true ) + 1.5; $waiting = false;
		do {
			$waiting = (int) DB::scalar( $this->db, "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE r.trx_mysql_thread_id={$r['connection_id']} AND b.trx_mysql_thread_id={$l['connection_id']} AND r.trx_state='LOCK WAIT'" ) > 0;
			if ( $waiting ) { break; } $remaining = $deadline - microtime( true ); if ( $remaining <= 0 ) { break; } usleep( (int) min( 120000, ceil( $remaining * 1000000 ) ) );
		} while ( microtime( true ) < $deadline );
		Barrier::signal( $d . '/finish-left' ); $out_left = $left->finish(); $out_right = $right->finish(); self::assertTrue( $waiting, 'The exact independent contender must wait on the held first sorted account.' );
		self::assertSame( [ 'held', 'refused' ], [ $out_left['state'], $out_right['state'] ] ); self::assertNotSame( $out_left['process_id'], $out_right['process_id'] ); self::assertNotSame( $out_left['connection_id'], $out_right['connection_id'] );
		self::assertSame( array_map( static fn( string $k ): string => 'account:' . $k, $left_set->account_keys() ), array_slice( $out_left['locks'], 0, 2 ) ); self::assertSame( 2, $this->count_rows( Schema::RESERVATIONS_SUFFIX ) );
		$s = $this->owner(); foreach ( $left_set->account_keys() as $key ) { self::assertSame( [ '3.00', '0.00' ], [ ( new Store() )->lock_account( $s, $key )->held()->amount(), ( new Store() )->lock_account( $s, $key )->remaining()->amount() ] ); }
	}
	public function test_preopened_repeatable_read_batch_retry_reads_current_committed_claims(): void {
		$set = F::set(); $this->seed( $set ); $reader = $this->owner(); self::assertSame( 0, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $reader ) ); $writer = $this->owner(); $at = $set->header()->created_at();
		$first = F::service()->reserve_all( $writer, $set, $at, $at->plus_seconds( 60 ) ); self::assertSame( OperationCommitResult::Acknowledged, $writer->commit() ); self::assertSame( 0, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $reader ), 'The real RR consistent snapshot must remain older.' );
		self::assertSame( self::bytes( $first ), self::bytes( F::service()->reserve_all( $reader, $set, $at->plus_seconds( 1 ), $at->plus_seconds( 60 ) ) ) ); self::assertSame( 3, ( new Store() )->lock_account( $reader, $set->account_keys()[0] )->revision() ); self::assertSame( OperationCommitResult::Acknowledged, $reader->commit() ); self::assertSame( 2, $this->count_rows( Schema::RESERVATIONS_SUFFIX ) );
	}
	public function test_later_conflicting_held_deadline_refuses_before_earlier_missing_claim_is_written(): void {
		$set = F::set(); $this->seed( $set ); $at = $set->header()->created_at(); $s = $this->owner(); $single = new FixedPromotionBudgetService( new Store(), static fn(): bool => true );
		$single->reserve( $s, $set->requests()[1], $at, $at->plus_seconds( 60 ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); $before = $this->snapshot(); $fresh = $this->owner();
		$this->refuse( fn() => F::service()->reserve_all( $fresh, $set, $at, $at->plus_seconds( 30 ) ) ); self::assertSame( 1, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $fresh ) ); self::assertSame( '3.00', ( new Store() )->lock_account( $fresh, $set->account_keys()[0] )->held()->amount() ); self::assertTrue( $fresh->rollback() ); self::assertSame( $before, $this->snapshot() );
	}
	public function test_second_actual_claim_insert_denial_requires_caller_rollback_of_all_holds(): void {
		$set = F::set(); $this->seed( $set ); $s = $this->owner(); $at = $set->header()->created_at(); $store = new RejectSecondAllocationInsertStore();
		$this->refuse( fn() => ( new FixedPromotionBudgetAllocationService( $store, static fn(): bool => true ) )->reserve_all( $s, $set, $at, $at->plus_seconds( 60 ) ), OperationStorageException::class );
		self::assertSame( 2, $store->inserts ); self::assertTrue( $s->in_transaction() ); self::assertSame( 1, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $s ) ); self::assertSame( '6.00', ( new Store() )->lock_account( $s, $set->account_keys()[0] )->held()->amount() ); self::assertTrue( $s->rollback() );
		$fresh = $this->owner(); self::assertSame( 0, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $fresh ) ); self::assertTrue( ( new Store() )->lock_account( $fresh, $set->account_keys()[0] )->held()->zero() );
	}
	public function test_lost_acknowledged_commit_has_exact_fresh_process_terminal_replay_without_second_spend(): void {
		$set = F::set(); $this->seed( $set ); $factory = $this->factory( static function( $t ): void { $t->fault_commit = 1; $t->commit_fault = 'lost_ack'; } ); $s = $factory->open(); self::assertTrue( $s->begin() ); $at = $set->header()->created_at();
		F::service()->reserve_all( $s, $set, $at, $at->plus_seconds( 60 ) ); $done = F::service()->commit_all( $s, $set, $at->plus_seconds( 1 ) ); self::assertSame( [ 'committed', 'committed' ], array_map( static fn( $c ): string => $c->state(), $done ) ); self::assertSame( OperationCommitResult::Unconfirmed, $s->commit() ); self::assertTrue( $s->is_retired() );
		$out = $this->worker( [ 'packet' => F::packet( $set ), 'operation' => 'commit', 'at_offset' => 400 ] )->finish(); self::assertSame( 'committed', $out['state'] ); self::assertNotSame( getmypid(), $out['process_id'] ); $fresh = $this->owner(); $account = ( new Store() )->lock_account( $fresh, $set->account_keys()[0] );
		self::assertSame( [ '0.00', '6.00', 5 ], [ $account->held()->amount(), $account->spent()->amount(), $account->revision() ] ); self::assertSame( 2, $this->count_rows( Schema::RESERVATIONS_SUFFIX ) ); self::assertSame( self::bytes( $done ), self::bytes( F::service()->commit_all( $fresh, $set, $at->plus_seconds( 401 ) ) ) );
	}
	public function test_expired_batch_stays_held_until_explicit_release_then_replays_without_double_credit(): void {
		$set = F::set(); $this->seed( $set ); $s = $this->owner(); $at = $set->header()->created_at(); F::service()->reserve_all( $s, $set, $at, $at->plus_seconds( 60 ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); $before = $this->snapshot(); $fresh = $this->owner();
		$this->refuse( fn() => F::service()->reserve_all( $fresh, $set, $at->plus_seconds( 60 ), $at->plus_seconds( 60 ) ) ); self::assertTrue( $fresh->rollback() ); self::assertSame( $before, $this->snapshot() ); self::assertTrue( $fresh->begin() );
		$released = F::service()->release_all( $fresh, $set, $at->plus_seconds( 400 ) ); self::assertSame( [ 'released', 'released' ], array_map( static fn( $c ): string => $c->state(), $released ) ); self::assertSame( self::bytes( $released ), self::bytes( F::service()->release_all( $fresh, $set, $at->plus_seconds( 401 ) ) ) );
		$account = ( new Store() )->lock_account( $fresh, $set->account_keys()[0] ); self::assertSame( [ '0.00', '6.00', 5 ], [ $account->held()->amount(), $account->remaining()->amount(), $account->revision() ] ); self::assertSame( OperationCommitResult::Acknowledged, $fresh->commit() );
	}
	public function test_publication_readiness_uses_current_locked_status_despite_old_repeatable_read_snapshot(): void {
		$s = $this->owner(); $status_table = $this->prefix . 'options'; $status_name = $this->db->real_escape_string( MigrationStatus::OPTION_NAME ); $sql = "SELECT option_value FROM `{$status_table}` WHERE option_name='{$status_name}'"; $old_success = $s->get_row( $sql )['option_value']; F::publication( $this->db, $this->prefix, 'failed' );
		self::assertSame( $old_success, $s->get_row( $sql )['option_value'] ); $this->refuse( fn() => ( new FixedPromotionBudgetOperationReadiness() )->assert_ready( $s ), \RuntimeException::class ); self::assertTrue( $s->rollback() );
		F::publication( $this->db, $this->prefix, 'success', '11' ); self::assertTrue( $s->begin() ); self::assertSame( '11', $s->get_row( "SELECT option_value FROM `{$status_table}` WHERE option_name='" . SchemaVersion::OPTION_NAME . "'" )['option_value'] ); F::publication( $this->db, $this->prefix, 'success' );
		( new FixedPromotionBudgetOperationReadiness() )->assert_ready( $s ); self::assertTrue( $s->in_transaction() ); self::assertFalse( $s->is_retired() ); self::assertSame( 0, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $s ) ); self::assertTrue( $s->rollback() );
	}
	public function test_final_preparation_proof_denial_keeps_real_hold_uncertain_until_caller_rolls_back(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/AllocationPreparationSqlPorts.php';
		$native = $this->owner(); $fixture = AllocationPreparationSqlPorts::capture( $native ); $set = $fixture['set']; $this->seed( $set, '3.00' ); $at = $set->header()->created_at(); $eligibility = new AllocationPreparationEligibilityPort(); $session = $fixture['session'];
		$service = new FixedPromotionQuotePreparationService( new Store(), $eligibility, new FixedPromotionBudgetOperationReadiness(), static fn(): bool => true );
		$this->refuse( fn() => $service->prepare( $session, $set->owner(), $set->header(), $set->context(), $set->terms(), $fixture['original'], $fixture['fresh'], $set->account_slots(), $at, $at->plus_seconds( 60 ) ), OperationStorageException::class );
		self::assertSame( 2, $eligibility->proofs ); self::assertGreaterThanOrEqual( 2, $session->physical_writes ); self::assertTrue( $native->in_transaction() ); self::assertSame( 1, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $native ) ); self::assertSame( '3.00', ( new Store() )->lock_account( $native, $set->account_keys()[0] )->held()->amount() ); self::assertTrue( $native->rollback() );
		$fresh = $this->owner(); self::assertSame( 0, $this->count_rows( Schema::RESERVATIONS_SUFFIX, $fresh ) ); self::assertTrue( ( new Store() )->lock_account( $fresh, $set->account_keys()[0] )->held()->zero() );
	}
}

/** Actual SQL store with one declared second-insert denial; no invented persisted state. */
final class RejectSecondAllocationInsertStore implements FixedPromotionBudgetAccountStore {
	public int $inserts = 0;
	private Store $store;
	public function __construct() { $this->store = new Store(); }
	public function tables( OperationSession $s ): array { return $this->store->tables( $s ); }
	public function lock_account( OperationSession $s, string $key ): ?FixedPromotionBudgetState { return $this->store->lock_account( $s, $key ); }
	public function lock_reservation( OperationSession $s, string $key ): ?FixedPromotionBudgetReservation { return $this->store->lock_reservation( $s, $key ); }
	public function lock_reservation_for_account( OperationSession $s, string $claim, string $account ): ?FixedPromotionBudgetReservation { return $this->store->lock_reservation_for_account( $s, $claim, $account ); }
	public function replace_account( OperationSession $s, FixedPromotionBudgetState $a, FixedPromotionBudgetState $b ): bool { return $this->store->replace_account( $s, $a, $b ); }
	public function insert_reservation( OperationSession $s, FixedPromotionBudgetReservation $r ): bool { return 2 === ++$this->inserts ? false : $this->store->insert_reservation( $s, $r ); }
	public function replace_reservation( OperationSession $s, FixedPromotionBudgetReservation $a, FixedPromotionBudgetReservation $b ): bool { return $this->store->replace_reservation( $s, $a, $b ); }
}
