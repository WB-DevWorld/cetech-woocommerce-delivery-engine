<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk;

use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;

/**
 * Derived runner presentation. Not a persisted job status and not a schema change.
 */
final class BulkJobRunnerState {

	public const PHASE_QUEUED = 'queued';

	public const PHASE_STARTING = 'starting';

	public const PHASE_PROCESSING = 'processing';

	public const PHASE_WAITING = 'waiting';

	public const PHASE_PAUSED = 'paused';

	public const PHASE_COMPLETED = 'completed';

	public const PHASE_FAILED = 'failed';

	public const PHASE_READY = 'ready';

	public const PHASE_CANCELLED = 'cancelled';

	public const PHASE_FINALIZING = 'finalizing';

	public const WAITING_THRESHOLD_SECONDS = 12;

	public const STALE_THRESHOLD_SECONDS = 600;

	public function __construct(
		public readonly string $phase,
		public readonly bool $waiting_for_runner,
		public readonly bool $can_resume,
		public readonly bool $stale,
		public readonly bool $claimed
	) {
	}

	public static function from_job( BulkJob $job, ?int $now = null ): self {
		$now = $now ?? time();

		if ( BulkJobStatus::Ready === $job->status ) {
			if ( ! self::counters_coherent( $job ) ) {
				return new self( self::PHASE_FINALIZING, false, false, false, false );
			}

			return new self( self::PHASE_READY, false, false, false, false );
		}
		if ( BulkJobStatus::Failed === $job->status ) {
			return new self( self::PHASE_FAILED, false, false, false, false );
		}
		if ( in_array( $job->status, [ BulkJobStatus::Cancelled ], true ) ) {
			return new self( self::PHASE_CANCELLED, false, false, false, false );
		}
		if ( $job->status->is_terminal() ) {
			if ( ! self::counters_coherent( $job ) ) {
				return new self( self::PHASE_FINALIZING, false, false, false, false );
			}

			return new self( self::PHASE_COMPLETED, false, false, false, false );
		}
		if ( BulkJobStatus::CancelRequested === $job->status ) {
			$claimed = self::is_claimed( $job, $now );

			return new self( self::PHASE_PAUSED, false, ! $claimed, false, $claimed );
		}
		if ( ! $job->status->is_active_worker_state() ) {
			return new self( self::PHASE_QUEUED, false, false, false, false );
		}

		$claimed = self::is_claimed( $job, $now );
		if ( $claimed ) {
			return new self( self::PHASE_PROCESSING, false, false, false, true );
		}

		$age = self::age_seconds( $job, $now );
		if ( $age >= self::STALE_THRESHOLD_SECONDS ) {
			return new self( self::PHASE_WAITING, true, true, true, false );
		}
		if ( $age >= self::WAITING_THRESHOLD_SECONDS ) {
			return new self( self::PHASE_WAITING, true, true, false, false );
		}

		if ( $job->processed_count > 0 ) {
			return new self( self::PHASE_PROCESSING, false, false, false, false );
		}

		if ( in_array( $job->status, [ BulkJobStatus::Queued, BulkJobStatus::RollbackQueued ], true ) && $age < 2 ) {
			return new self( self::PHASE_QUEUED, false, false, false, false );
		}

		return new self( self::PHASE_STARTING, false, false, false, false );
	}

	public static function is_claimed( BulkJob $job, ?int $now = null ): bool {
		if ( null === $job->claim_token || null === $job->claimed_at ) {
			return false;
		}
		$claimed_at = strtotime( $job->claimed_at . ' UTC' );
		if ( false === $claimed_at ) {
			return false;
		}

		return $claimed_at >= ( ( $now ?? time() ) - BulkJobWorker::CLAIM_TTL );
	}

	public static function age_seconds( BulkJob $job, ?int $now = null ): int {
		$now      = $now ?? time();
		$stamp    = $job->updated_at ?: $job->created_at;
		$unix     = $stamp ? strtotime( $stamp . ' UTC' ) : false;

		if ( false === $unix ) {
			return 0;
		}

		return max( 0, $now - $unix );
	}

	/**
	 * Terminal/ready presentation is allowed only when job-row counters add up.
	 * Partial counter writes must not be shown as Completed/Ready.
	 */
	public static function counters_coherent( BulkJob $job ): bool {
		$accounted = $job->changed_count + $job->skipped_count + $job->failed_count;
		if ( BulkOperationType::Rollback === $job->operation_type ) {
			$accounted = (int) ( $job->summary['rollback_restored'] ?? 0 )
				+ (int) ( $job->summary['rollback_skipped'] ?? $job->skipped_count )
				+ (int) ( $job->summary['rollback_failed'] ?? $job->failed_count );
		}

		if ( $job->processed_count !== $accounted ) {
			return false;
		}

		if ( BulkJobStatus::Cancelled === $job->status ) {
			return true;
		}

		if ( $job->total_count > 0 && $job->processed_count !== $job->total_count ) {
			return false;
		}

		return true;
	}
}
