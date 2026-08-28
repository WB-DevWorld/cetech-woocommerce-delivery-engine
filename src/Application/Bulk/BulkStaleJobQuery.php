<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk;

use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;

/**
 * Durable bulk jobs that have not seen worker activity for long enough that
 * staff should be told the site's background runner is not advancing them.
 */
final class BulkStaleJobQuery {

	public function __construct(
		private readonly BulkJobRepositoryInterface $jobs
	) {
	}

	/**
	 * @return list<BulkJob>
	 */
	public function list( int $limit = 20 ): array {
		$found = [];
		foreach ( $this->active_statuses() as $status ) {
			foreach ( $this->jobs->list_jobs( 50, 0, $status ) as $job ) {
				$state = BulkJobRunnerState::from_job( $job );
				if ( ! $state->stale ) {
					continue;
				}
				$found[] = $job;
				if ( count( $found ) >= $limit ) {
					return $found;
				}
			}
		}

		return $found;
	}

	public function count(): int {
		return count( $this->list( 100 ) );
	}

	/**
	 * @return list<BulkJobStatus>
	 */
	private function active_statuses(): array {
		return [
			BulkJobStatus::Previewing,
			BulkJobStatus::Queued,
			BulkJobStatus::Running,
			BulkJobStatus::CancelRequested,
			BulkJobStatus::RollbackQueued,
			BulkJobStatus::RollingBack,
		];
	}
}
