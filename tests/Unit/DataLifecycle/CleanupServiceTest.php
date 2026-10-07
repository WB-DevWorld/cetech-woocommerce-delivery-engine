<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DataLifecycle;

use CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleContinuation;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleProgress;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheEnvelope;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DataLifecycleOptionsStore;
use PHPUnit\Framework\TestCase;

/** Transactional unit seam; actual mysqli and fresh-process proofs are separate. */
abstract class DataLifecycleWorkerTestCase extends TestCase {
	protected const NOW = 1791331200;
	protected function cache_row( int $id, bool $expired = true ): array {
		$identity = ManagedGeographyCacheIdentity::create( 1, 'postcode', 'GH', '', '', (string) $id, 1, 'revision', 'en_US', 'fixture-salt' );
		$envelope = ManagedGeographyCacheEnvelope::create( $identity, [ 'required' => false, 'visible' => false ], self::NOW - ( $expired ? 121 : 20 ), str_pad( dechex( $id ), 32, '0', STR_PAD_LEFT ) );
		return [ 'option_id' => $id, 'option_name' => $identity->option_name(), 'option_value' => $envelope->to_json(), 'autoload' => 'off' ];
	}
	protected function fixture( array $rows = [], ?callable $authorizer = null, ?callable $clock = null ): array {
		$stats = (object) [ 'rows' => [], 'units' => [], 'now' => self::NOW, 'ceiling' => null, 'opens' => 0, 'begins' => 0, 'commits' => 0, 'rollbacks' => 0, 'retirements' => 0, 'deletes' => 0, 'checkpoints' => 0, 'inserts' => 0, 'windows' => [], 'max_reads' => 0, 'now_reads' => 0, 'current_reads' => 0, 'commit_result' => OperationCommitResult::Acknowledged, 'commit_apply' => true, 'fail_delete' => null, 'fail_checkpoint' => false, 'fail_insert' => false, 'ready' => true, 'retire_ok' => true, 'rollback_ok' => true, 'ambient' => false, 'invalidate_ok' => true, 'allowed' => true, 'on_current' => null ];
		foreach ( $rows as $row ) { $stats->rows[$row['option_id']] = $row; }
		$store = new MemoryLifecycleOptionsStore( $stats );
		$builder = function () use ( $stats ): OperationSession {
			++$stats->opens; $state = (object) [ 'active' => $stats->ambient, 'retired' => false ]; $session = $this->createMock( OperationSession::class ); $key = spl_object_id( $session );
			$session->method( 'site_id' )->willReturn( 1 ); $session->method( 'table_prefix' )->willReturn( 'proof_' );
			$session->method( 'in_transaction' )->willReturnCallback( static fn (): bool => $state->active ); $session->method( 'is_retired' )->willReturnCallback( static fn (): bool => $state->retired );
			$session->method( 'begin' )->willReturnCallback( static function () use ( $stats, $state, $key ): bool { ++$stats->begins; $state->active = true; $stats->units[$key] = $stats->rows; return true; } );
			$session->method( 'commit' )->willReturnCallback( static function () use ( $stats, $state, $key ): OperationCommitResult { ++$stats->commits; if ( $stats->commit_apply && OperationCommitResult::NotSent !== $stats->commit_result ) { $stats->rows = $stats->units[$key]; unset( $stats->units[$key] ); $state->active = false; } return $stats->commit_result; } );
			$session->method( 'rollback' )->willReturnCallback( static function () use ( $stats, $state, $key ): bool { ++$stats->rollbacks; if ( ! $stats->rollback_ok ) { return false; } unset( $stats->units[$key] ); $state->active = false; return true; } );
			$session->method( 'retire' )->willReturnCallback( static function () use ( $stats, $state, $key ): bool { ++$stats->retirements; $state->retired = true; $state->active = false; unset( $stats->units[$key] ); return $stats->retire_ok; } );
			$session->expects( self::never() )->method( 'query' );
			return $session;
		};
		$factory = new class( $builder ) implements OperationConnectionFactory { public function __construct( private \Closure $builder ) {} public function open(): OperationSession { return ( $this->builder )(); } };
		$registry = DataLifecycleRegistry::standard();
		$authorizer ??= static fn ( int $site ): bool => 1 === $site && $stats->allowed;
		return [ new DataLifecycleCleanupService( $registry, $factory, $authorizer, $store, true, $clock ), $stats, $store, $factory, $registry ];
	}
	protected function coordinator( object $stats ): array { foreach ( $stats->rows as $row ) { if ( DataLifecycleManifest::COORDINATOR_OPTION === $row['option_name'] ) { return $row; } } throw new \RuntimeException(); }
}

final class MemoryLifecycleOptionsStore extends DataLifecycleOptionsStore {
	public function __construct( public object $stats ) {}
	private function &unit( OperationSession $session ): array { if ( ! $session->in_transaction() || $session->is_retired() || ! isset( $this->stats->units[spl_object_id( $session )] ) ) { throw new \RuntimeException(); } return $this->stats->units[spl_object_id( $session )]; }
	public function assert_ready( OperationSession $session, int $site_id ): void { $this->unit( $session ); if ( ! $this->stats->ready || $site_id !== $session->site_id() ) { throw new \RuntimeException( 'PRIVATE_SQL_FIXTURE' ); } }
	public function assert_standard_wordpress_route( OperationSession $session ): void {}
	public function now( OperationSession $session ): int { $this->unit( $session ); ++$this->stats->now_reads; return $this->stats->now; }
	public function max_id( OperationSession $session ): int { $rows = $this->unit( $session ); ++$this->stats->max_reads; return $this->stats->ceiling ?? ( [] === $rows ? 0 : max( array_keys( $rows ) ) ); }
	public function current_by_name( OperationSession $session, string $name, int $max_bytes = 67584 ): ?array { $rows = $this->unit( $session ); ++$this->stats->current_reads; foreach ( $rows as $row ) { if ( $name === $row['option_name'] ) { return self::bounded_row( $row, $max_bytes ); } } return null; }
	public function current_by_id( OperationSession $session, int $id, int $max_bytes = 67584 ): ?array { $rows =& $this->unit( $session ); ++$this->stats->current_reads; if ( null !== $this->stats->on_current ) { ( $this->stats->on_current )( $id, $rows ); } return isset( $rows[$id] ) ? self::bounded_row( $rows[$id], $max_bytes ) : null; }
	public function window( OperationSession $session, int $after, int $upper, int $limit = 201 ): array { $rows = $this->unit( $session ); $this->stats->windows[] = [ $after, $upper, $limit ]; if ( $upper < $after || $upper - $after > 1000 || $limit > 201 ) { throw new \RuntimeException(); } ksort( $rows ); $out = []; foreach ( $rows as $row ) { if ( $row['option_id'] > $after && $row['option_id'] <= $upper && str_starts_with( $row['option_name'], DataLifecycleManifest::CACHE_PREFIX ) ) { $out[] = [ 'option_id' => $row['option_id'], 'option_name' => $row['option_name'], 'byte_length' => strlen( $row['option_value'] ), 'autoload' => $row['autoload'] ]; if ( count( $out ) === $limit ) { break; } } } return $out; }
	public function insert( OperationSession $session, string $name, string $value ): int { $rows =& $this->unit( $session ); ++$this->stats->inserts; if ( $this->stats->fail_insert ) { throw new \RuntimeException( 'PRIVATE_INSERT' ); } $id = [] === $rows ? 1 : max( array_keys( $rows ) ) + 1; $rows[$id] = [ 'option_id' => $id, 'option_name' => $name, 'option_value' => $value, 'autoload' => 'off' ]; return $id; }
	public function replace( OperationSession $session, int $id, string $name, string $old_value, string $new_value ): bool { $rows =& $this->unit( $session ); ++$this->stats->checkpoints; if ( $this->stats->fail_checkpoint ) { return false; } if ( ! isset( $rows[$id] ) || $rows[$id]['option_name'] !== $name || $rows[$id]['option_value'] !== $old_value ) { return false; } $rows[$id]['option_value'] = $new_value; return true; }
	public function delete( OperationSession $session, int $id, string $name, string $old_value ): bool { $rows =& $this->unit( $session ); ++$this->stats->deletes; if ( $this->stats->fail_delete === $this->stats->deletes ) { return false; } if ( ! isset( $rows[$id] ) || $rows[$id]['option_name'] !== $name || $rows[$id]['option_value'] !== $old_value ) { return false; } unset( $rows[$id] ); return true; }
	public function invalidate( array $option_names ): bool { return $this->stats->invalidate_ok; }
	private static function bounded_row( array $row, int $max ): array { $row['byte_length'] = strlen( $row['option_value'] ); if ( $row['byte_length'] > $max ) { $row['option_value'] = null; } return $row; }
}

final class CleanupServiceTest extends DataLifecycleWorkerTestCase {
	public function test_preview_is_read_only_and_keeps_fixed_ceiling_across_pages(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1500 ) ] ); $preview = $service->preview( 1 );
		self::assertSame( 'preview', $preview->status ); self::assertSame( 1000, $preview->progress->cursor_id ); self::assertSame( 0, $preview->eligible_candidates ); self::assertSame( 0, $stats->commits ); self::assertSame( 0, $stats->inserts ); self::assertSame( 0, $stats->deletes ); self::assertSame( 0, $stats->checkpoints );
		$stats->rows[1501] = $this->cache_row( 1501 ); $stats->now += 100; $next = $service->preview( 1, $preview->continuation );
		self::assertSame( 1500, $next->progress->ceiling_id ); self::assertSame( self::NOW, $next->progress->cutoff_utc ); self::assertSame( 1, $next->eligible_candidates ); self::assertSame( 'completed', $next->progress->status ); self::assertCount( 2, $stats->rows ); self::assertSame( 1, $stats->max_reads );
	}
	public function test_failed_start_resume_reuses_original_cutoff_and_ceiling(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 2 ) ] ); $stats->fail_insert = true; $failed = $service->start( 1 );
		self::assertSame( 'refused', $failed->status ); self::assertNull( $failed->progress ); self::assertSame( 'start', $failed->continuation->kind ); self::assertCount( 1, $stats->rows );
		$stats->fail_insert = false; $stats->rows[3] = $this->cache_row( 3 ); $stats->now += 100; $resumed = $service->resume( 1, $failed->continuation ); $done = $service->batch( 1, $resumed->continuation );
		self::assertSame( self::NOW, $done->progress->cutoff_utc ); self::assertSame( 2, $done->progress->ceiling_id ); self::assertArrayHasKey( 3, $stats->rows ); self::assertArrayNotHasKey( 2, $stats->rows ); self::assertSame( 1, $stats->max_reads );
	}
	public function test_inspection_201_probe_never_skips_the_uninspected_identity(): void {
		$rows = []; for ( $id = 1; $id <= 201; ++$id ) { $rows[] = $this->cache_row( $id, false ); } [ $service, $stats ] = $this->fixture( $rows ); $start = $service->start( 1 ); $first = $service->batch( 1, $start->continuation );
		self::assertSame( 200, $first->progress->inspected ); self::assertSame( 200, $first->progress->cursor_id ); self::assertSame( 0, $first->progress->deleted ); self::assertSame( 'running', $first->progress->status );
		$last = $service->batch( 1, $first->continuation ); self::assertSame( 201, $last->progress->inspected ); self::assertSame( 'completed', $last->progress->status ); self::assertSame( [ 0, 201, 201 ], $stats->windows[0] );
	}
	public function test_fifty_delete_limit_retains_next_candidate_for_next_batch(): void {
		$rows = []; for ( $id = 1; $id <= 51; ++$id ) { $rows[] = $this->cache_row( $id ); } [ $service, $stats ] = $this->fixture( $rows ); $first = $service->batch( 1, $service->start( 1 )->continuation );
		self::assertSame( 50, $first->progress->deleted ); self::assertSame( 50, $first->progress->cursor_id ); self::assertArrayHasKey( 51, $stats->rows ); self::assertSame( 'running', $first->progress->status );
		$last = $service->batch( 1, $first->continuation ); self::assertSame( 51, $last->progress->deleted ); self::assertSame( 'completed', $last->progress->status );
	}
	public function test_sparse_foreign_windows_do_not_mean_early_completion(): void {
		$foreign = [ 'option_id' => 3000, 'option_name' => 'foreign_option', 'option_value' => 'PRIVATE_FOREIGN', 'autoload' => 'on' ]; [ $service, $stats ] = $this->fixture( [ $this->cache_row( 2500 ), $foreign ] ); $first = $service->batch( 1, $service->start( 1 )->continuation );
		self::assertSame( 1000, $first->progress->cursor_id ); self::assertSame( 'running', $first->progress->status ); self::assertSame( 0, $first->progress->deleted ); $second = $service->batch( 1, $first->continuation ); $third = $service->batch( 1, $second->continuation );
		self::assertSame( 3000, $third->progress->cursor_id ); self::assertSame( 'completed', $third->progress->status ); self::assertSame( $foreign, $stats->rows[3000] ); self::assertSame( 1, $third->progress->deleted );
	}
	public function test_disappeared_renewed_and_invalid_rows_advance_without_deletion(): void {
		$invalid = $this->cache_row( 3 ); $invalid['option_value'] = '{"PRIVATE_RENAMED":"token/query/IP/path/SQL"}'; [ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ), $this->cache_row( 2 ), $invalid ] ); $start = $service->start( 1 ); $renewed = $this->cache_row( 2, false );
		$stats->on_current = static function ( int $id, array &$rows ) use ( $renewed ): void { if ( 1 === $id ) { unset( $rows[1] ); } if ( 2 === $id ) { $rows[2] = $renewed; } }; $done = $service->batch( 1, $start->continuation );
		self::assertSame( 'completed', $done->progress->status ); self::assertSame( 3, $done->progress->inspected ); self::assertSame( 1, $done->progress->disappeared ); self::assertSame( 1, $done->progress->renewed ); self::assertSame( 1, $done->progress->invalid ); self::assertSame( 0, $done->progress->deleted ); self::assertSame( $renewed, $stats->rows[2] ); self::assertSame( $invalid, $stats->rows[3] );
	}
	public function test_delete_refusal_rolls_back_earlier_effect_and_checkpoint(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ), $this->cache_row( 2 ) ] ); $start = $service->start( 1 ); $before = $stats->rows; $stats->fail_delete = 2; $failed = $service->batch( 1, $start->continuation );
		self::assertSame( 'refused', $failed->status ); self::assertSame( $before, $stats->rows ); self::assertSame( 0, $failed->progress->cursor_id ); self::assertSame( 0, $failed->progress->deleted ); $stats->fail_delete = null;
		$resumed = $service->resume( 1, $failed->continuation ); self::assertSame( 'accepted', $resumed->status ); self::assertSame( 2, $resumed->progress->deleted );
	}
	public function test_checkpoint_refusal_rolls_back_all_candidate_deletes(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $start = $service->start( 1 ); $before = $stats->rows; $stats->fail_checkpoint = true; $failed = $service->batch( 1, $start->continuation );
		self::assertSame( 'refused', $failed->status ); self::assertSame( $before, $stats->rows ); self::assertSame( $start->progress->cutoff_utc, $failed->progress->cutoff_utc ); self::assertSame( $start->progress->ceiling_id, $failed->progress->ceiling_id ); $stats->fail_checkpoint = false;
		self::assertSame( 1, $service->resume( 1, $failed->continuation )->progress->deleted );
	}
	public function test_unsent_commit_and_sent_lost_ack_have_distinct_retry_rules(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $start = $service->start( 1 ); $before = $stats->rows; $stats->commit_result = OperationCommitResult::NotSent;
		$unsent = $service->batch( 1, $start->continuation ); self::assertSame( 'refused', $unsent->status ); self::assertFalse( $unsent->continuation->uncertain ); self::assertSame( $before, $stats->rows );
		$stats->commit_result = OperationCommitResult::Unconfirmed; $lost = $service->resume( 1, $unsent->continuation ); self::assertSame( 'outcome_unknown', $lost->status ); self::assertNull( $lost->progress ); self::assertTrue( $lost->continuation->uncertain ); self::assertArrayNotHasKey( 1, $stats->rows );
		$deletes = $stats->deletes; $opens = $stats->opens; self::assertSame( 'outcome_unknown', $service->resume( 1, $lost->continuation )->status ); self::assertSame( $opens, $stats->opens ); self::assertSame( $deletes, $stats->deletes );
		$private = DataLifecycleContinuation::from_private_array( $lost->continuation->to_private_array() ); $confirmed = $service->reconcile( 1, $private ); self::assertSame( 'accepted', $confirmed->status ); self::assertSame( 1, $confirmed->progress->deleted ); self::assertSame( $deletes, $stats->deletes );
	}
	public function test_unknown_unchanged_checkpoint_needs_verified_retirement_to_retry(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $start = $service->start( 1 ); $stats->commit_result = OperationCommitResult::Unconfirmed; $stats->commit_apply = false; $stats->retire_ok = false; $lost = $service->batch( 1, $start->continuation );
		self::assertSame( 'outcome_unknown', $lost->status ); self::assertFalse( $lost->continuation->retirement_confirmed ); $stats->retire_ok = true; $reconciled = $service->reconcile( 1, $lost->continuation ); self::assertSame( 'outcome_unknown', $reconciled->status ); self::assertArrayHasKey( 1, $stats->rows );
	}
	public function test_unknown_rolled_back_unit_can_reconcile_then_retry_original_page(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $start = $service->start( 1 ); $stats->commit_result = OperationCommitResult::Unconfirmed; $stats->commit_apply = false; $lost = $service->batch( 1, $start->continuation );
		$read = $service->reconcile( 1, $lost->continuation ); self::assertSame( 'refused', $read->status ); self::assertFalse( $read->continuation->uncertain ); self::assertSame( $start->progress->checkpoint_token, $read->progress->checkpoint_token );
		$stats->commit_result = OperationCommitResult::Acknowledged; $stats->commit_apply = true; self::assertSame( 1, $service->resume( 1, $read->continuation )->progress->deleted );
	}
	public function test_stale_checkpoint_cannot_repeat_or_start_a_new_pass(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $start = $service->start( 1 ); $accepted = $service->batch( 1, $start->continuation ); $deletes = $stats->deletes; $repeat = $service->batch( 1, $start->continuation );
		self::assertSame( 'refused', $repeat->status ); self::assertSame( 'checkpoint_conflict', $repeat->reason ); self::assertSame( $deletes, $stats->deletes ); self::assertSame( $accepted->progress->run_id, $service->read( 1 )->progress->run_id );
	}
	public function test_start_requires_the_terminal_checkpoint_that_the_caller_opened(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $first = $service->batch( 1, $service->start( 1 )->continuation ); $opened = $first->progress;
		$second = $service->batch( 1, $service->start( 1, expected_previous: $opened )->continuation ); $current = $this->coordinator( $stats ); $max_reads = $stats->max_reads; $now_reads = $stats->now_reads; $commits = $stats->commits;
		$stale = $service->start( 1, expected_previous: $opened );
		self::assertSame( 'checkpoint_conflict', $stale->reason ); self::assertSame( $current, $this->coordinator( $stats ) ); self::assertSame( $second->progress->run_id, $service->read( 1 )->progress->run_id ); self::assertSame( $max_reads, $stats->max_reads ); self::assertSame( $now_reads, $stats->now_reads ); self::assertSame( $commits, $stats->commits );
	}
	public function test_start_expected_absence_does_not_recapture_an_intervening_run(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); self::assertSame( 'no_checkpoint', $service->read( 1 )->reason ); $service->start( 1 ); $current = $this->coordinator( $stats ); $max_reads = $stats->max_reads;
		$stale = $service->start( 1, expect_absent: true );
		self::assertSame( 'checkpoint_conflict', $stale->reason ); self::assertSame( $current, $this->coordinator( $stats ) ); self::assertSame( $max_reads, $stats->max_reads ); self::assertSame( 1, $stats->commits );
	}
	public function test_acknowledged_commit_with_failed_retirement_requires_fresh_reconciliation(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $start = $service->start( 1 ); $stats->retire_ok = false; $unknown = $service->batch( 1, $start->continuation );
		self::assertSame( 'outcome_unknown', $unknown->status ); self::assertNull( $unknown->progress ); self::assertFalse( $unknown->continuation->retirement_confirmed ); self::assertArrayNotHasKey( 1, $stats->rows ); $deletes = $stats->deletes; $opens = $stats->opens;
		self::assertSame( 'outcome_unknown', $service->resume( 1, $unknown->continuation )->status ); self::assertSame( $opens, $stats->opens );
		$stats->retire_ok = true; $confirmed = $service->reconcile( 1, $unknown->continuation );
		self::assertSame( 'accepted', $confirmed->status ); self::assertSame( 1, $confirmed->progress->deleted ); self::assertSame( $deletes, $stats->deletes ); self::assertTrue( $confirmed->publication_pending );
	}
	public function test_uncertain_retry_still_requires_current_site_and_grant_without_loading(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $start = $service->start( 1 ); $stats->commit_result = OperationCommitResult::Unconfirmed; $unknown = $service->batch( 1, $start->continuation ); $opens = $stats->opens;
		self::assertSame( 'not_authorized', $service->resume( 2, $unknown->continuation )->reason ); $stats->allowed = false; $denied = $service->resume( 1, $unknown->continuation );
		self::assertSame( 'not_authorized', $denied->reason ); self::assertNull( $denied->continuation ); self::assertSame( $opens, $stats->opens );
	}
	public function test_advisory_failure_keeps_acknowledged_sql_progress(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $stats->invalidate_ok = false; $start = $service->start( 1 ); $done = $service->batch( 1, $start->continuation );
		self::assertSame( 'accepted', $done->status ); self::assertTrue( $done->publication_pending ); self::assertSame( 1, $done->progress->deleted ); self::assertArrayNotHasKey( 1, $stats->rows ); self::assertSame( $done->progress->checkpoint_token, $service->read( 1 )->progress->checkpoint_token );
	}
	public function test_soft_time_bound_stops_before_next_uninspected_candidate(): void {
		$ticks = [ 0.0, 0.0, 2.1 ]; $clock = static function () use ( &$ticks ): float { return array_shift( $ticks ) ?? 2.1; }; [ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ), $this->cache_row( 2 ) ], clock: $clock ); $done = $service->batch( 1, $service->start( 1 )->continuation );
		self::assertSame( 1, $done->progress->cursor_id ); self::assertSame( 1, $done->progress->deleted ); self::assertSame( 'running', $done->progress->status ); self::assertArrayHasKey( 2, $stats->rows );
	}
	public function test_explicit_cache_removal_is_bounded_and_does_not_clear_malformed_rows(): void {
		$rows = []; for ( $id = 1; $id <= 51; ++$id ) { $rows[] = $this->cache_row( $id, false ); } $invalid = $this->cache_row( 52 ); $invalid['option_value'] = '{"unknown":true}'; $rows[] = $invalid; [ $service, $stats ] = $this->fixture( $rows ); $start = $service->start( 1, 'uninstall_cache' ); $first = $service->batch( 1, $start->continuation );
		self::assertSame( 'running', $first->progress->status ); self::assertSame( 50, $first->progress->deleted ); $last = $service->batch( 1, $first->continuation ); self::assertSame( 51, $last->progress->deleted ); self::assertSame( 1, $last->progress->invalid ); self::assertSame( $invalid, $stats->rows[52] );
	}
	public function test_nonterminal_expired_run_cannot_be_replaced_by_uninstall_mode(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 2 ) ] ); $start = $service->start( 1 ); $original = $this->coordinator( $stats ); $refused = $service->start( 1, 'uninstall_cache' );
		self::assertSame( 'checkpoint_conflict', $refused->reason ); self::assertSame( $original, $this->coordinator( $stats ) ); self::assertSame( $start->progress->run_id, $service->read( 1 )->progress->run_id );
	}
	public function test_known_empty_is_distinct_from_corrupt_or_unready_storage(): void {
		[ $service, $stats ] = $this->fixture(); self::assertSame( 'no_checkpoint', $service->read( 1 )->reason ); $stats->rows[1] = [ 'option_id' => 1, 'option_name' => DataLifecycleManifest::COORDINATOR_OPTION, 'option_value' => '{"PRIVATE":"secret"}', 'autoload' => 'off' ];
		self::assertSame( 'storage_refused', $service->read( 1 )->reason ); $stats->ready = false; self::assertSame( 'storage_refused', $service->read( 1 )->reason ); self::assertSame( 0, $stats->commits );
	}
	public function test_denied_site_loads_nothing_and_revoked_batch_rolls_back(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $stats->allowed = false; $denied = $service->start( 1 ); self::assertSame( 'not_authorized', $denied->reason ); self::assertSame( 0, $stats->opens ); $stats->allowed = true; $start = $service->start( 1 ); $before = $stats->rows;
		$stats->on_current = static function () use ( $stats ): void { $stats->allowed = false; }; $failed = $service->batch( 1, $start->continuation ); self::assertSame( 'not_authorized', $failed->reason ); self::assertSame( $before, $stats->rows );
	}
	public function test_ambient_owner_is_refused_without_rollback_or_effects(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $stats->ambient = true; $result = $service->start( 1 ); self::assertSame( 'refused', $result->status ); self::assertSame( 0, $stats->begins ); self::assertSame( 0, $stats->rollbacks ); self::assertSame( 0, $stats->deletes );
	}
	public function test_near_maximum_ceiling_window_does_not_overflow(): void {
		[ $service, $stats, , , $registry ] = $this->fixture(); $data = DataLifecycleProgress::initial( 1, $registry->policy_digest(), 'expired', self::NOW, PHP_INT_MAX )->to_array(); $data['cursor_id'] = PHP_INT_MAX - 5; $progress = DataLifecycleProgress::from_array( $data ); $stats->rows[1] = [ 'option_id' => 1, 'option_name' => DataLifecycleManifest::COORDINATOR_OPTION, 'option_value' => $progress->to_json(), 'autoload' => 'off' ];
		$done = $service->batch( 1, DataLifecycleContinuation::checkpoint( $progress ) ); self::assertSame( 'completed', $done->progress->status ); self::assertSame( [ PHP_INT_MAX - 5, PHP_INT_MAX, 201 ], $stats->windows[0] );
	}
	public function test_safe_result_has_no_private_checkpoint_or_payload_and_direct_json_refuses(): void {
		[ $service ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $result = $service->start( 1 ); $json = json_encode( $result->safe(), JSON_THROW_ON_ERROR ); foreach ( [ 'checkpoint_token', 'run_id', 'identity_digest', 'payload', 'option_name', 'PRIVATE' ] as $private ) { self::assertStringNotContainsString( $private, $json ); }
		$this->expectException( \LogicException::class ); json_encode( $result, JSON_THROW_ON_ERROR );
	}
	public function test_safe_disclosure_rechecks_current_grant_and_omits_progress_after_revocation(): void {
		[ $service, $stats ] = $this->fixture( [ $this->cache_row( 1 ) ] ); $accepted = $service->start( 1 ); self::assertSame( 'accepted', $service->safe_result( 1, $accepted )['status'] ); $stats->allowed = false; $safe = $service->safe_result( 1, $accepted );
		self::assertSame( 'refused', $safe['status'] ); self::assertSame( 'not_authorized', $safe['reason'] ); self::assertNull( $safe['progress'] ); self::assertStringNotContainsString( $accepted->progress->run_id, json_encode( $safe, JSON_THROW_ON_ERROR ) );
	}
	public function test_exact_cutoff_and_autoloaded_rows_are_retained(): void {
		$exact = $this->cache_row( 1 ); $envelope = json_decode( $exact['option_value'], true, 512, JSON_THROW_ON_ERROR ); $envelope['expires_at'] = self::NOW; $exact['option_value'] = json_encode( $envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); $autoloaded = $this->cache_row( 2 ); $autoloaded['autoload'] = 'on';
		[ $service, $stats ] = $this->fixture( [ $exact, $autoloaded ] ); $done = $service->batch( 1, $service->start( 1 )->continuation );
		self::assertSame( 0, $done->progress->deleted ); self::assertSame( 1, $done->progress->renewed ); self::assertSame( 1, $done->progress->invalid ); self::assertSame( $exact, $stats->rows[1] ); self::assertSame( $autoloaded, $stats->rows[2] );
	}
	public function test_corrupt_private_progress_unknown_fields_and_duplicate_keys_are_refused(): void {
		[ , , , , $registry ] = $this->fixture(); $progress = DataLifecycleProgress::initial( 1, $registry->policy_digest(), 'expired', self::NOW, 10 ); $duplicate = str_replace( '"format":1', '"format":1,"format":1', $progress->to_json() );
		$this->expectException( \InvalidArgumentException::class ); DataLifecycleProgress::from_json( $duplicate );
	}
}
