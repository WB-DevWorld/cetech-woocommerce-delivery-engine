<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleScheduler;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleProgress;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleResult;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;

require_once __DIR__ . '/CleanupServiceTest.php';

/** Real cleanup service plus an argument-aware AS uniqueness fixture. */
final class SchedulerTest extends DataLifecycleWorkerTestCase {

	private SchedulerActionStore $actions;
	private array $saved_globals = [];

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 2 ) . '/Support/action-scheduler-test-functions.php';
		foreach ( [ 'blog_id', 'wp_actions', 'cetech_de_as_store', 'cetech_de_test_actions' ] as $key ) {
			$this->saved_globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ];
		}
		$GLOBALS['blog_id'] = 1;
		$GLOBALS['wp_actions']['action_scheduler_init'] = 1;
		$this->actions = new SchedulerActionStore();
		$GLOBALS['cetech_de_as_store'] = $this->actions;
		$GLOBALS['cetech_de_test_actions'] = [];
	}

	protected function tearDown(): void {
		foreach ( [ 1, 2 ] as $site ) {
			$GLOBALS['blog_id'] = $site;
			DataLifecycleScheduler::suspend_current_site();
		}
		foreach ( $this->saved_globals as $key => [ $present, $value ] ) {
			if ( $present ) { $GLOBALS[$key] = $value; }
			else { unset( $GLOBALS[$key] ); }
		}
		parent::tearDown();
	}

	public function test_not_initialized_means_no_service_write_no_enqueue_and_no_sync_fallback(): void {
		[ $service, $stats ] = $this->fixture();
		$GLOBALS['wp_actions']['action_scheduler_init'] = 0;
		$scheduler = new DataLifecycleScheduler( $service, static fn (): int => 1000 );
		$scheduler->register();
		self::assertNull( $scheduler->ensure_dispatch() );
		self::assertSame( 0, $stats->opens );
		self::assertSame( [], $this->actions->scheduled );
		self::assertSame( 3, $GLOBALS['cetech_de_test_actions'][DataLifecycleScheduler::HOOK][0]['args'] );
		self::assertSame( 0, $GLOBALS['cetech_de_test_actions']['action_scheduler_init'][0]['args'] );
	}

	public function test_storage_refusal_is_not_absence_and_never_starts_a_replacement_run(): void {
		[ $service, $stats ] = $this->fixture();
		$stats->ready = false;
		$result = ( new DataLifecycleScheduler( $service ) )->ensure_dispatch();
		self::assertSame( 'refused', $result?->status );
		self::assertSame( 'storage_refused', $result?->reason );
		self::assertSame( 0, $stats->commits );
		self::assertSame( 0, $stats->checkpoints );
		self::assertSame( [], $this->actions->scheduled );
	}

	public function test_positive_exact_pending_id_deduplicates_without_foreign_args_suppression(): void {
		[ $service, , , , $registry ] = $this->fixture();
		$progress = DataLifecycleProgress::initial( 1, $registry->policy_digest(), 'expired', 1000, 3000 );
		$scheduler = new DataLifecycleScheduler( $service, static fn (): int => 1000 );
		$foreign = self::args( $progress ); $foreign['site_id'] = 2;
		$this->actions->schedule_single( 1001, DataLifecycleScheduler::HOOK, $foreign, DataLifecycleScheduler::GROUP, false );
		$result = new DataLifecycleResult( 'accepted', $progress );
		self::assertFalse( $scheduler->publish( $result )->publication_pending );
		self::assertFalse( $scheduler->publish( $result )->publication_pending );
		self::assertCount( 2, $this->actions->scheduled );
		self::assertSame( self::args( $progress ), $this->actions->scheduled[1]['args'] );
		self::assertSame( [ 'site_id', 'run_id', 'checkpoint_token' ], array_keys( $this->actions->scheduled[1]['args'] ) );
		self::assertSame( 1, $this->actions->queries[0]['per_page'] );
		self::assertSame( 'pending', $this->actions->queries[0]['status'] );
	}

	public function test_running_unique_action_does_not_suppress_acknowledged_successor(): void {
		[ $service, $stats ] = $this->fixture(); $stats->ceiling = 2500;
		$started = $service->start( 1 ); self::assertSame( 'accepted', $started->status );
		$scheduler = new DataLifecycleScheduler( $service, static fn (): int => 1000 );
		$scheduler->publish( $started ); $running = $this->actions->last_id(); $this->actions->actions[$running]['status'] = 'in-progress';
		$before = $stats->commits;
		$result = $scheduler->tick( ...array_values( self::args( $started->progress ) ) );
		self::assertSame( 'accepted', $result?->status );
		self::assertSame( 1000, $result?->progress?->cursor_id );
		self::assertSame( 1, $result?->progress?->last_batch_sequence );
		self::assertSame( $before + 1, $stats->commits );
		self::assertCount( 2, $this->actions->scheduled );
		self::assertFalse( $this->actions->scheduled[1]['unique'] );
		self::assertSame( 1001, $this->actions->scheduled[1]['timestamp'] );
		self::assertSame( 'in-progress', $this->actions->get_status( $running ) );
		self::assertSame( 'pending', $this->actions->get_status( $this->actions->last_id() ) );
	}

	public function test_late_old_checkpoint_and_foreign_or_malformed_site_cannot_repeat_batch(): void {
		[ $service, $stats ] = $this->fixture(); $stats->ceiling = 2500;
		$started = $service->start( 1 ); $scheduler = new DataLifecycleScheduler( $service, static fn (): int => 1000 );
		$args = self::args( $started->progress ); $scheduler->tick( ...array_values( $args ) ); $after = $stats->commits;
		$scheduler->tick( ...array_values( $args ) );
		self::assertNull( $scheduler->tick( 2, $args['run_id'], $args['checkpoint_token'] ) );
		self::assertNull( $scheduler->tick( '1', $args['run_id'], $args['checkpoint_token'] ) );
		self::assertNull( $scheduler->tick( 1, 'PRIVATE_TOKEN', $args['checkpoint_token'] ) );
		self::assertSame( $after, $stats->commits );
		self::assertCount( 1, $this->actions->scheduled );
	}

	public function test_completed_maintenance_waits_300_seconds_and_performs_at_most_one_new_batch(): void {
		[ $service, $stats ] = $this->fixture(); $stats->ceiling = 0;
		$started = $service->start( 1 ); $scheduler = new DataLifecycleScheduler( $service, static fn (): int => 1000 );
		$scheduler->publish( $started ); self::assertSame( 1300, $this->actions->scheduled[0]['timestamp'] );
		$this->actions->actions[$this->actions->last_id()]['status'] = 'in-progress';
		$stats->ceiling = 2500; $before = $stats->commits;
		$result = $scheduler->tick( ...array_values( self::args( $started->progress ) ) );
		self::assertSame( 1000, $result?->progress?->cursor_id );
		self::assertSame( 1, $result?->progress?->last_batch_sequence );
		self::assertSame( $before + 2, $stats->commits ); // One new run and one bounded batch.
		self::assertNotSame( $started->progress->run_id, $result?->progress?->run_id );
		self::assertSame( 1001, $this->actions->scheduled[1]['timestamp'] );
	}

	public function test_completed_action_cannot_start_again_after_another_run_finishes_in_the_read_start_gap(): void {
		$stats = null; $newer = null; $calls = 0; $armed = false;
		$authorizer = static function ( int $site ) use ( &$stats, &$newer, &$calls, &$armed ): bool {
			if ( $armed && ++$calls === 3 ) {
				// After read/rollback, before the next start locks its coordinator.
				foreach ( $stats->rows as &$row ) {
					if ( 'cetech_de_gc_state_geo_v1' === $row['option_name'] ) { $row['option_value'] = $newer->to_json(); }
				}
				unset( $row );
			}
			return 1 === $site;
		};
		[ $service, $stats, , , $registry ] = $this->fixture( authorizer: $authorizer ); $stats->ceiling = 0;
		$old = $service->start( 1 ); $newer = DataLifecycleProgress::initial( 1, $registry->policy_digest(), 'expired', self::NOW + 1, 0 );
		$armed = true; $before = $stats->commits;
		$result = ( new DataLifecycleScheduler( $service ) )->tick( ...array_values( self::args( $old->progress ) ) );
		self::assertSame( 'refused', $result?->status ); self::assertSame( 'checkpoint_conflict', $result?->reason );
		self::assertSame( $before, $stats->commits ); self::assertSame( $newer->to_json(), $this->coordinator( $stats )['option_value'] );
		self::assertSame( [], $this->actions->scheduled );
	}

	public function test_zero_enqueue_and_missing_initialization_preserve_accepted_progress(): void {
		[ $service, $stats ] = $this->fixture(); $stats->ceiling = 2500;
		$started = $service->start( 1 ); $before = $stats->commits;
		$scheduler = new DataLifecycleScheduler( $service, static fn (): int => 1000 );
		$this->actions->zero_enqueue = true;
		$refused = $scheduler->publish( $started ); self::assertTrue( $refused->publication_pending );
		self::assertSame( $started->progress->to_json(), $refused->progress->to_json() );
		$GLOBALS['wp_actions']['action_scheduler_init'] = 0;
		self::assertTrue( $scheduler->publish( $started )->publication_pending );
		self::assertSame( $before, $stats->commits );
		self::assertSame( [], $this->actions->actions );
	}

	public function test_unknown_commit_does_not_enqueue_or_reissue_the_batch(): void {
		[ $service, $stats ] = $this->fixture(); $stats->ceiling = 2500;
		$started = $service->start( 1 ); $stats->commit_result = OperationCommitResult::Unconfirmed;
		$result = ( new DataLifecycleScheduler( $service ) )->tick( ...array_values( self::args( $started->progress ) ) );
		self::assertSame( 'outcome_unknown', $result?->status );
		self::assertSame( [], $this->actions->scheduled );
		self::assertSame( 2, $stats->commits );
	}

	public function test_suspend_stops_current_site_handlers_preserves_all_as_rows_and_reactivation_resumes(): void {
		[ $service, $stats ] = $this->fixture(); $stats->ceiling = 2500;
		$one = new DataLifecycleScheduler( $service, static fn (): int => 1000 ); $one->register();
		$before = $service->read( 1 )->progress; $args = self::args( $before );
		$running = $this->actions->schedule_single( 1000, DataLifecycleScheduler::HOOK, $args, DataLifecycleScheduler::GROUP, false ); $this->actions->actions[$running]['status'] = 'in-progress';
		$foreign = $args; $foreign['site_id'] = 2; $this->actions->schedule_single( 1000, DataLifecycleScheduler::HOOK, $foreign, DataLifecycleScheduler::GROUP, false );
		$this->actions->schedule_single( 1000, 'cetech_de_bulk_job_tick', [], 'cetech-delivery-engine-bulk', false );
		$rows = $this->actions->actions;
		$GLOBALS['blog_id'] = 2; $two = new DataLifecycleScheduler( $service ); $two->register();
		$GLOBALS['blog_id'] = 1; $report = DataLifecycleScheduler::suspend_current_site();
		self::assertTrue( $report['dispatch_stopped'] ); self::assertSame( 2, $report['handlers_removed'] );
		self::assertFalse( $report['cancellation_supported'] ); self::assertSame( 1, $report['pending_retained'] );
		self::assertSame( $rows, $this->actions->actions ); self::assertSame( 0, $this->actions->cancel_calls );
		self::assertNull( $one->tick( ...array_values( $args ) ) ); self::assertNull( $one->boot() );
		self::assertCount( 1, $GLOBALS['cetech_de_test_actions'][DataLifecycleScheduler::HOOK] );
		self::assertSame( $two, array_values( $GLOBALS['cetech_de_test_actions'][DataLifecycleScheduler::HOOK] )[0]['callback'][0] );
		$one->register(); self::assertSame( $before->to_json(), $service->read( 1 )->progress->to_json() );
	}

	public function test_suspension_observation_is_bounded_and_never_reports_a_global_pending_total(): void {
		[ $service ] = $this->fixture(); $progress = $service->start( 1 )->progress;
		for ( $i = 0; $i < 205; $i++ ) { $this->actions->schedule_single( 1000, DataLifecycleScheduler::HOOK, self::args( $progress ), DataLifecycleScheduler::GROUP, false ); }
		$report = DataLifecycleScheduler::suspend_current_site();
		self::assertSame( 200, $report['pending_inspected'] ); self::assertSame( 200, $report['pending_retained'] );
		self::assertTrue( $report['limit_reached'] ); self::assertCount( 205, $this->actions->actions );
		self::assertArrayNotHasKey( 'total', $report ); self::assertArrayNotHasKey( 'complete', $report );
	}

	private static function args( DataLifecycleProgress $progress ): array {
		return [ 'site_id' => $progress->site_id, 'run_id' => $progress->run_id, 'checkpoint_token' => $progress->checkpoint_token ];
	}
}

/** AS DB stores return decimal-string query IDs and uniqueness ignores arguments. */
final class SchedulerActionStore {
	public array $actions = [];
	public array $scheduled = [];
	public array $queries = [];
	public bool $zero_enqueue = false;
	public int $cancel_calls = 0;
	private int $next_id = 0;
	public function last_id(): int { return $this->next_id; }
	public function schedule_single( int $timestamp, string $hook, array $args, string $group, bool $unique ): int {
		$this->scheduled[] = [ 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group, 'unique' => $unique ];
		if ( $this->zero_enqueue ) { return 0; }
		if ( $unique ) { foreach ( $this->actions as $id => $row ) { if ( $row['hook'] === $hook && $row['group'] === $group && in_array( $row['status'], [ 'pending', 'in-progress' ], true ) ) { return $id; } } }
		$id = ++$this->next_id; $this->actions[$id] = [ 'hook' => $hook, 'args' => $args, 'group' => $group, 'status' => 'pending' ]; return $id;
	}
	public function query( array $query, string $format ): array {
		unset( $format ); $this->queries[] = $query; $ids = [];
		foreach ( $this->actions as $id => $row ) {
			if ( $row['hook'] !== ( $query['hook'] ?? '' ) || $row['group'] !== ( $query['group'] ?? '' ) || $row['status'] !== ( $query['status'] ?? '' ) || ( isset( $query['args'] ) && $row['args'] !== $query['args'] ) ) { continue; }
			$ids[] = (string) $id;
		}
		return array_slice( $ids, 0, (int) $query['per_page'] );
	}
	public function get_status( int $id ): string { return $this->actions[$id]['status']; }
	public function cancel_action( int $id ): void { ++$this->cancel_calls; $this->actions[$id]['status'] = 'canceled'; }
	public function fetch_action( int $id ): object {
		return new class( $this->actions[$id] ) {
			public function __construct( private array $row ) {}
			public function get_hook(): string { return $this->row['hook']; }
			public function get_group(): string { return $this->row['group']; }
			public function get_args(): array { return $this->row['args']; }
		};
	}
}
