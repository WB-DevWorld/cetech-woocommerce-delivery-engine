<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkJobRunnerState;
use CetechDeliveryEngine\Application\Bulk\BulkStaleJobQuery;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use CetechDeliveryEngine\Presentation\Admin\BulkJobAdminCopy;
use PHPUnit\Framework\TestCase;

final class BulkJobRunnerStateTest extends TestCase {

	public function test_fresh_preview_is_starting_not_waiting(): void {
		$job   = $this->previewing_job();
		$state = BulkJobRunnerState::from_job( $job, time() );

		self::assertSame( BulkJobRunnerState::PHASE_STARTING, $state->phase );
		self::assertFalse( $state->waiting_for_runner );
		self::assertFalse( $state->can_resume );
		self::assertSame( 'Starting background work', BulkJobAdminCopy::public_status_label( $job ) );
	}

	public function test_unclaimed_job_older_than_threshold_is_waiting(): void {
		$now = time();
		$job = $this->previewing_job()->with(
			[
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 30 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 30 ),
			]
		);
		$state = BulkJobRunnerState::from_job( $job, $now );

		self::assertTrue( $state->waiting_for_runner );
		self::assertTrue( $state->can_resume );
		self::assertFalse( $state->stale );
		self::assertSame( 'Waiting for the site\'s background runner', BulkJobAdminCopy::public_status_label( $job ) );
		self::assertStringContainsString( 'You can safely leave this page', BulkJobAdminCopy::waiting_notice() );
	}

	public function test_ten_minute_stall_is_stale(): void {
		$now = time();
		$job = $this->previewing_job()->with(
			[
				'processed_count' => 25,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now - 700 ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s', $now - 700 ),
			]
		);
		$state = BulkJobRunnerState::from_job( $job, $now );

		self::assertTrue( $state->stale );
		self::assertTrue( $state->waiting_for_runner );
		self::assertTrue( $state->can_resume );

		$repo = new InMemoryBulkJobRepository();
		$repo->save_job( $job );
		$query = new BulkStaleJobQuery( $repo );
		self::assertSame( 1, $query->count() );
		self::assertSame( $job->job_code, $query->list( 5 )[0]->job_code );
	}

	public function test_claimed_job_is_processing(): void {
		$job = $this->previewing_job()->with_claim( 'tick-1', gmdate( 'Y-m-d H:i:s' ) );
		$state = BulkJobRunnerState::from_job( $job );

		self::assertSame( BulkJobRunnerState::PHASE_PROCESSING, $state->phase );
		self::assertTrue( $state->claimed );
		self::assertFalse( $state->waiting_for_runner );
	}

	public function test_started_job_with_recent_activity_is_processing(): void {
		$now = time();
		$job = $this->previewing_job()->with(
			[
				'processed_count' => 25,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now - 40 ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s', $now - 2 ),
			]
		);
		$state = BulkJobRunnerState::from_job( $job, $now );

		self::assertSame( BulkJobRunnerState::PHASE_PROCESSING, $state->phase );
		self::assertFalse( $state->waiting_for_runner );
		self::assertFalse( $state->can_resume );
		self::assertFalse( $state->stale );
	}

	public function test_started_job_with_stalled_runner_is_waiting_and_resumable(): void {
		$now = time();
		$job = $this->previewing_job()->with(
			[
				'processed_count' => 25,
				'total_count'     => 80,
				'created_at'      => gmdate( 'Y-m-d H:i:s', $now - 40 ),
				'updated_at'      => gmdate( 'Y-m-d H:i:s', $now - 30 ),
			]
		);
		$state = BulkJobRunnerState::from_job( $job, $now );

		self::assertTrue( $state->waiting_for_runner );
		self::assertTrue( $state->can_resume );
		self::assertFalse( $state->stale );
		self::assertSame( 'Waiting for the site\'s background runner', BulkJobAdminCopy::public_status_label( $job ) );
	}

	public function test_ready_and_completed_are_not_resumable(): void {
		$ready = $this->previewing_job()->with_status( BulkJobStatus::Ready );
		$done  = $this->previewing_job()->with_status( BulkJobStatus::Completed );

		self::assertFalse( BulkJobRunnerState::from_job( $ready )->can_resume );
		self::assertFalse( BulkJobRunnerState::from_job( $done )->waiting_for_runner );
		self::assertSame( 'Ready to apply', BulkJobAdminCopy::public_status_label( $ready ) );
		self::assertSame( 'Completed', BulkJobAdminCopy::public_status_label( $done ) );
	}

	public function test_progress_payload_carries_waiting_and_resume_flags(): void {
		$now = time();
		$job = $this->previewing_job()->with(
			[
				'created_at' => gmdate( 'Y-m-d H:i:s', $now - 30 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s', $now - 30 ),
			]
		);
		$payload = BulkJobAdminCopy::progress_payload( $job );

		self::assertTrue( $payload['waiting_for_runner'] );
		self::assertTrue( $payload['can_resume'] );
		self::assertSame( 'previewing', $payload['status'] );
		self::assertSame( 'Waiting for the site\'s background runner', $payload['status_label'] );
		self::assertStringContainsString( 'You can safely leave this page', $payload['waiting_notice'] );
		self::assertStringNotContainsString( 'Action Scheduler', $payload['waiting_notice'] );
	}

	private function previewing_job(): BulkJob {
		return BulkJob::create(
			BulkOperationType::CatalogUpdate,
			1,
			[ 'scope' => 'selected_ids', 'selected_ids' => [ 1 ] ],
			[ 'field_actions' => [] ]
		)->with(
			[
				'id'     => 1,
				'status' => BulkJobStatus::Previewing,
			]
		);
	}
}
