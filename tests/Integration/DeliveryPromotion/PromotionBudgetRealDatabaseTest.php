<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetSchema as Schema, SqlFixedPromotionBudgetStore as Store};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\PromotionBudgetSqlFixture as F;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier as Barrier, OperationProofProcess as Process};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteLifecycleProofFactory as Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/PromotionBudgetSqlFixture.php';

/** Owned independent MariaDB connections/processes. Missing SQL prerequisites fail, never skip. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionBudgetRealDatabaseTest extends TestCase {
	private \mysqli $db; private string $prefix; private array $factories = []; private array $processes = []; private array $directories = [];
	protected function setUp(): void { $this->db = DB::connect(); $this->prefix = DB::prefix(); F::install( $this->db, $this->prefix ); }
	protected function tearDown(): void { foreach ( $this->processes as $p ) { $p->kill(); } foreach ( $this->factories as $f ) { $f->close_all(); } foreach ( $this->directories as $d ) { Barrier::cleanup( $d ); } if ( isset( $this->db, $this->prefix ) ) { F::cleanup( $this->db, $this->prefix ); foreach ( Schema::tables( $this->prefix ) as $table ) { self::assertSame( 0, (int) DB::scalar( $this->db, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$table}'" ) ); } $this->db->close(); } }
	private function factory( int $site = 1, ?callable $configure = null ): Factory { $f = new Factory( $this->prefix, $site ); $f->configure = null === $configure ? null : \Closure::fromCallable( $configure ); $this->factories[] = $f; return $f; }
	private function owner( int $site = 1 ): OperationSession { $s = $this->factory( $site )->open(); self::assertTrue( $s->begin() ); return $s; }
	private function seed( $r, string $limit = '3.00' ): void { $s = $this->owner(); self::assertTrue( ( new Store() )->insert_account( $s, F::account( $r, $limit ) ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); }
	private function row_count( string $suffix ): int { $table = Schema::table( $this->prefix, $suffix ); return (int) DB::scalar( $this->db, "SELECT COUNT(*) FROM `{$table}`" ); }
	private function fail_storage( callable $call ): void { try { $call(); self::fail( 'The storage boundary should refuse.' ); } catch ( OperationStorageException ) { self::assertTrue( true ); } }
	private function worker( array $args ): Process { $p = new Process( [ __DIR__ . '/promotion-budget-process-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $args, JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }

	public function test_owned_provisioning_hold_commit_and_terminal_retry_preserve_exact_money(): void {
		$r = F::request(); $this->seed( $r ); $s = $this->owner(); $store = new Store(); $service = F::service( $store ); $at = $r->quote_created_at();
		$claim = $service->reserve( $s, $r, $at, $at->plus_seconds( 60 ) ); self::assertSame( 'held', $claim->state() ); self::assertSame( '3.00', $store->lock_account( $s, $r->account_key() )->held()->amount() );
		self::assertSame( $claim->to_private_json(), $service->reserve( $s, $r, $at->plus_seconds( 1 ), $at->plus_seconds( 60 ) )->to_private_json() );
		$done = $service->commit( $s, $r, $at->plus_seconds( 2 ) ); self::assertSame( $done->to_private_json(), $service->commit( $s, $r, $at->plus_seconds( 400 ) )->to_private_json() ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() );
		$fresh = $this->owner(); $account = $store->lock_account( $fresh, $r->account_key() ); self::assertSame( [ '0.00', '3.00', 3 ], [ $account->held()->amount(), $account->spent()->amount(), $account->revision() ] ); self::assertSame( 'committed', $store->lock_reservation_for_account( $fresh, $r->claim_key(), $r->account_key() )->state() ); self::assertSame( 1, $this->row_count( Schema::RESERVATIONS_SUFFIX ) );
	}

	public function test_two_actual_processes_compete_for_last_funds_without_overspend(): void {
		$a = F::request(); $b = F::request(); self::assertNotSame( $a->claim_key(), $b->claim_key() ); $this->seed( $a ); $d = Barrier::directory(); $this->directories[] = $d;
		$left = $this->worker( [ 'request' => $a->private_facts(), 'ready' => $d . '/left', 'release' => $d . '/go-left', 'hold_ready' => $d . '/held', 'hold_release' => $d . '/finish-left' ] );
		Process::wait_for( $d . '/left' ); Barrier::signal( $d . '/go-left' ); Process::wait_for( $d . '/held' );
		$right = $this->worker( [ 'request' => $b->private_facts(), 'ready' => $d . '/right', 'release' => $d . '/go-right' ] ); Process::wait_for( $d . '/right' );
		$left_id = json_decode( (string) file_get_contents( $d . '/left' ), true, 4, JSON_THROW_ON_ERROR )['connection_id']; $right_id = json_decode( (string) file_get_contents( $d . '/right' ), true, 4, JSON_THROW_ON_ERROR )['connection_id']; Barrier::signal( $d . '/go-right' );
		$deadline = microtime( true ) + 1.5; $waiting = false;
		do { $waiting = (int) DB::scalar( $this->db, "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE r.trx_mysql_thread_id={$right_id} AND b.trx_mysql_thread_id={$left_id} AND r.trx_state='LOCK WAIT'" ) > 0; if ( $waiting ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
		Barrier::signal( $d . '/finish-left' ); self::assertTrue( $waiting, 'The exact independent contender must wait on the held budget account.' ); $out = [ $left->finish(), $right->finish() ]; self::assertNotSame( $out[0]['process_id'], $out[1]['process_id'] ); self::assertNotSame( $out[0]['connection_id'], $out[1]['connection_id'] );
		$states = array_column( $out, 'state' ); sort( $states ); self::assertSame( [ 'held', 'refused' ], $states ); self::assertSame( 1, $this->row_count( Schema::RESERVATIONS_SUFFIX ) ); $s = $this->owner(); $account = ( new Store() )->lock_account( $s, $a->account_key() ); self::assertSame( [ '3.00', '0.00', 2 ], [ $account->held()->amount(), $account->remaining()->amount(), $account->revision() ] );
	}

	public function test_preopened_repeatable_read_exact_retry_observes_current_claim(): void {
		$r = F::request(); $this->seed( $r ); $reader = $this->owner(); $table = Schema::table( $this->prefix, Schema::RESERVATIONS_SUFFIX ); self::assertSame( '0', (string) $reader->get_row( "SELECT COUNT(*) AS n FROM `{$table}`" )['n'] );
		$writer = $this->owner(); $at = $r->quote_created_at(); $first = F::service()->reserve( $writer, $r, $at, $at->plus_seconds( 60 ) ); self::assertSame( OperationCommitResult::Acknowledged, $writer->commit() );
		self::assertSame( '0', (string) $reader->get_row( "SELECT COUNT(*) AS n FROM `{$table}`" )['n'], 'The real consistent RR snapshot remains older.' );
		$retry = F::service()->reserve( $reader, $r, $at->plus_seconds( 1 ), $at->plus_seconds( 60 ) ); self::assertSame( $first->to_private_json(), $retry->to_private_json() ); self::assertSame( 2, ( new Store() )->lock_account( $reader, $r->account_key() )->revision() ); self::assertSame( OperationCommitResult::Acknowledged, $reader->commit() ); self::assertSame( 1, $this->row_count( Schema::RESERVATIONS_SUFFIX ) );
	}

	public function test_claim_insert_failure_leaves_partial_hold_only_until_caller_rollback(): void {
		$r = F::request(); $this->seed( $r ); $native = $this->owner(); $s = new RejectPromotionClaimSession( $native ); $at = $r->quote_created_at();
		$this->fail_storage( fn() => F::service()->reserve( $s, $r, $at, $at->plus_seconds( 60 ) ) ); self::assertTrue( $s->in_transaction() ); self::assertSame( '3.00', ( new Store() )->lock_account( $native, $r->account_key() )->held()->amount() ); self::assertSame( 0, $this->row_count( Schema::RESERVATIONS_SUFFIX ) ); self::assertTrue( $s->rollback() );
		$fresh = $this->owner(); self::assertTrue( ( new Store() )->lock_account( $fresh, $r->account_key() )->held()->zero() );
	}

	public function test_stale_full_account_cas_refuses_without_replacing_committed_state(): void {
		$r = F::request(); $this->seed( $r, '6.00' ); $s = $this->owner(); $store = new Store(); $old = $store->lock_account( $s, $r->account_key() ); self::assertTrue( $store->replace_account( $s, $old, $old->reserve( F::money( '3.00' ) ) ) ); self::assertFalse( $store->replace_account( $s, $old, $old->reserve( F::money( '1.00' ) ) ) ); self::assertSame( '3.00', $store->lock_account( $s, $r->account_key() )->held()->amount() ); self::assertTrue( $s->rollback() );
	}

	public function test_corrupt_mirror_and_oversized_payload_are_not_usable_accounts(): void {
		$r = F::request(); $this->seed( $r ); $table = Schema::table( $this->prefix, Schema::ACCOUNTS_SUFFIX ); DB::execute( $this->db, "UPDATE `{$table}` SET state_digest=REPEAT('a',64)" ); $s = $this->owner(); $this->fail_storage( fn() => ( new Store() )->lock_account( $s, $r->account_key() ) ); self::assertTrue( $s->rollback() );
		DB::execute( $this->db, "UPDATE `{$table}` SET state_json=REPEAT('x',4097)" ); self::assertTrue( $s->begin() ); $this->fail_storage( fn() => ( new Store() )->lock_account( $s, $r->account_key() ) );
	}

	public function test_same_key_other_site_is_absent_then_has_its_own_explicit_account(): void {
		$r = F::request(); $this->seed( $r ); $s = $this->owner( 2 ); $store = new Store(); self::assertNull( $store->lock_account( $s, $r->account_key() ) ); self::assertTrue( $store->insert_account( $s, FixedPromotionBudgetState::create( 2, $r->account_key(), $r->funding_source(), F::money( '7.00' ) ) ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() );
		$one = $this->owner(); $two = $this->owner( 2 ); self::assertSame( '3.00', $store->lock_account( $one, $r->account_key() )->limit()->amount() ); self::assertSame( '7.00', $store->lock_account( $two, $r->account_key() )->limit()->amount() ); self::assertSame( 2, $this->row_count( Schema::ACCOUNTS_SUFFIX ) );
	}

	public function test_schema_readiness_is_not_cached_across_commit_and_begin(): void {
		$r = F::request(); $this->seed( $r ); $s = $this->owner(); $store = new Store(); self::assertNotNull( $store->lock_account( $s, $r->account_key() ) ); self::assertSame( OperationCommitResult::Acknowledged, $s->commit() ); $table = Schema::table( $this->prefix, Schema::ACCOUNTS_SUFFIX ); DB::execute( $this->db, "ALTER TABLE `{$table}` DROP INDEX site_account" );
		self::assertTrue( $s->begin() ); $this->fail_storage( fn() => $store->lock_account( $s, $r->account_key() ) ); self::assertTrue( $s->rollback() ); DB::execute( $this->db, "ALTER TABLE `{$table}` ADD UNIQUE KEY site_account (site_id,account_key)" ); self::assertTrue( $s->begin() ); self::assertNotNull( $store->lock_account( $s, $r->account_key() ) );
	}

	public function test_committed_lost_ack_is_replayed_by_fresh_process_without_second_hold(): void {
		$r = F::request(); $this->seed( $r ); $f = $this->factory( 1, static function( $t ): void { $t->fault_commit = 1; $t->commit_fault = 'lost_ack'; } ); $s = $f->open(); self::assertTrue( $s->begin() ); $at = $r->quote_created_at(); F::service()->reserve( $s, $r, $at, $at->plus_seconds( 60 ) ); self::assertSame( OperationCommitResult::Unconfirmed, $s->commit() ); self::assertTrue( $s->is_retired() );
		$out = $this->worker( [ 'request' => $r->private_facts() ] )->finish(); self::assertNotSame( getmypid(), $out['process_id'] ); self::assertSame( 'held', $out['state'] ); self::assertSame( 1, $this->row_count( Schema::RESERVATIONS_SUFFIX ) ); $fresh = $this->owner(); self::assertSame( 2, ( new Store() )->lock_account( $fresh, $r->account_key() )->revision() );
	}
}

/** Explicit failed second write over a real connection; caller still owns rollback. */
final class RejectPromotionClaimSession implements OperationSession {
	private bool $failed = false;
	public function __construct( private OperationSession $s ) {}
	public function site_id(): int { return $this->s->site_id(); } public function table_prefix(): string { return $this->s->table_prefix(); } public function charset_collate(): string { return $this->s->charset_collate(); }
	public function begin(): bool { return $this->s->begin(); } public function commit(): OperationCommitResult { return $this->s->commit(); } public function rollback(): bool { return $this->s->rollback(); } public function retire(): bool { return $this->s->retire(); } public function is_retired(): bool { return $this->s->is_retired(); } public function in_transaction(): bool { return $this->s->in_transaction(); } public function validate_tables( array $names ): bool { return $this->s->validate_tables( $names ); }
	public function query( string $sql ): int|false { if ( str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, 'promotion_budget_reservations' ) ) { $this->failed = true; return false; } $this->failed = false; return $this->s->query( $sql ); }
	public function get_row( string $sql ): array|null|false { $this->failed = false; return $this->s->get_row( $sql ); } public function get_results( string $sql ): array|false { $this->failed = false; return $this->s->get_results( $sql ); } public function prepare( string $sql, mixed ...$args ): string { return $this->s->prepare( $sql, ...$args ); } public function errno(): int { return $this->failed ? 45000 : $this->s->errno(); } public function insert_id(): int { return $this->s->insert_id(); }
}
