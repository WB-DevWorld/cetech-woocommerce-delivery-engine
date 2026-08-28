<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk;

use CetechDeliveryEngine\Application\Bulk\Queue\ActionSchedulerQueue;
use CetechDeliveryEngine\Application\Bulk\Queue\BackgroundQueueInterface;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;

/**
 * Technical diagnostics for Bulk Tools background execution.
 */
final class BulkQueueHealth {

	public function __construct(
		private readonly BackgroundQueueInterface $queue,
		private readonly BulkJobRepositoryInterface $jobs,
		private readonly BulkStaleJobQuery $stale
	) {
	}

	/**
	 * @return array<string, scalar|null>
	 */
	public function snapshot(): array {
		$pending_jobs = 0;
		$last_activity = null;
		foreach (
			[
				BulkJobStatus::Previewing,
				BulkJobStatus::Queued,
				BulkJobStatus::Running,
				BulkJobStatus::CancelRequested,
				BulkJobStatus::RollbackQueued,
				BulkJobStatus::RollingBack,
			] as $status
		) {
			foreach ( $this->jobs->list_jobs( 20, 0, $status ) as $job ) {
				++$pending_jobs;
				$stamp = $job->updated_at ?: $job->created_at;
				if ( null !== $stamp && ( null === $last_activity || $stamp > $last_activity ) ) {
					$last_activity = $stamp;
				}
			}
		}

		$stale = $this->stale->list( 5 );
		$queue = [
			'available'               => $this->queue->is_available(),
			'unavailable_reason'      => $this->queue->unavailable_reason(),
			'async_enqueue_supported' => false,
			'unique_actions_supported'=> false,
			'wp_cron_disabled'        => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'pending_actions'         => 0,
		];
		if ( $this->queue instanceof ActionSchedulerQueue ) {
			$queue = array_merge( $queue, $this->queue->diagnostic_snapshot() );
		}

		$runner_ok = $queue['available'] && [] === $stale;
		$runner_label = ! $queue['available']
			? 'unavailable'
			: ( [] !== $stale ? 'not_running' : 'available' );

		return array_merge(
			$queue,
			[
				'active_jobs'           => $pending_jobs,
				'stale_job_count'       => count( $stale ),
				'last_worker_activity'  => $last_activity,
				'scheduler_health'      => $runner_label,
				'runner_apparently_ok'  => $runner_ok,
			]
		);
	}

	public function stale_jobs(): array {
		return $this->stale->list( 20 );
	}
}
