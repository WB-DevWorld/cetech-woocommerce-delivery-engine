<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Queue\ActionSchedulerQueue;
use PHPUnit\Framework\TestCase;

final class ActionSchedulerQueueTest extends TestCase {

	public function test_prefers_async_enqueue_and_kicks_without_draining_the_queue(): void {
		$gateway = new RecordingActionSchedulerGateway();
		$queue   = new ActionSchedulerQueue( $gateway );

		self::assertTrue( $queue->enqueue_job_tick( 12 ) );
		self::assertSame( [ 'async', 'kick' ], $gateway->ops() );
		self::assertTrue( $gateway->calls[0]['unique'] );
		self::assertSame( ActionSchedulerQueue::HOOK, $gateway->calls[0]['hook'] );
		self::assertSame( [ 'job_id' => 12 ], $gateway->calls[0]['args'] );
		self::assertNotContains( 'process_queue', $gateway->ops() );
	}

	public function test_falls_back_to_due_now_schedule_when_async_is_missing(): void {
		$gateway        = new RecordingActionSchedulerGateway();
		$gateway->async = false;
		$queue          = new ActionSchedulerQueue( $gateway );

		$before = time();
		self::assertTrue( $queue->enqueue_job_tick( 9 ) );
		$after = time();

		self::assertSame( 'schedule', $gateway->calls[0]['op'] );
		self::assertGreaterThanOrEqual( $before, $gateway->calls[0]['timestamp'] );
		self::assertLessThanOrEqual( $after + 1, $gateway->calls[0]['timestamp'] );
		self::assertSame( 'kick', $gateway->calls[1]['op'] );
	}

	public function test_delayed_requeue_uses_schedule_not_async(): void {
		$gateway = new RecordingActionSchedulerGateway();
		$queue   = new ActionSchedulerQueue( $gateway );

		self::assertTrue( $queue->enqueue_job_tick( 3, 1 ) );
		self::assertSame( 'schedule', $gateway->calls[0]['op'] );
		self::assertGreaterThan( time(), $gateway->calls[0]['timestamp'] );
	}

	public function test_unavailable_scheduler_does_not_enqueue(): void {
		$gateway            = new RecordingActionSchedulerGateway();
		$gateway->schedule  = false;
		$queue              = new ActionSchedulerQueue( $gateway );

		self::assertFalse( $queue->is_available() );
		self::assertFalse( $queue->enqueue_job_tick( 1 ) );
		self::assertSame( [], $gateway->calls );
		self::assertSame( 'action_scheduler_unavailable', $queue->unavailable_reason() );
	}

	public function test_diagnostics_include_async_and_cron_flags(): void {
		$gateway                 = new RecordingActionSchedulerGateway();
		$gateway->cron_disabled  = true;
		$gateway->pending        = 1;
		$queue                   = new ActionSchedulerQueue( $gateway );
		$snap                    = $queue->diagnostic_snapshot( 4 );

		self::assertTrue( $snap['available'] );
		self::assertTrue( $snap['async_enqueue_supported'] );
		self::assertTrue( $snap['wp_cron_disabled'] );
		self::assertSame( 1, $snap['pending_actions'] );
	}

	public function test_group_diagnostics_query_any_pending_args_not_empty_payload(): void {
		$gateway          = new RecordingActionSchedulerGateway();
		$gateway->pending = 3;
		$queue            = new ActionSchedulerQueue( $gateway );
		$snap             = $queue->diagnostic_snapshot();

		self::assertSame( 3, $snap['pending_actions'] );
		self::assertNull( $gateway->pending_queries[0]['args'] );
	}

	public function test_job_diagnostics_query_that_job_id_only(): void {
		$gateway = new RecordingActionSchedulerGateway();
		$queue   = new ActionSchedulerQueue( $gateway );
		$queue->diagnostic_snapshot( 44 );

		self::assertSame( [ 'job_id' => 44 ], $gateway->pending_queries[0]['args'] );
	}
}
