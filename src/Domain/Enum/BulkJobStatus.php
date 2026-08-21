<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Machine-authoritative bulk job lifecycle codes. Never store translated labels.
 */
enum BulkJobStatus: string {

	case Draft = 'draft';
	case Previewing = 'previewing';
	case Ready = 'ready';
	case Queued = 'queued';
	case Running = 'running';
	case CancelRequested = 'cancel_requested';
	case Cancelled = 'cancelled';
	case Completed = 'completed';
	case CompletedWithErrors = 'completed_with_errors';
	case Failed = 'failed';
	case RollbackQueued = 'rollback_queued';
	case RollingBack = 'rolling_back';
	case RolledBack = 'rolled_back';
	case PartiallyRolledBack = 'partially_rolled_back';

	public function is_terminal(): bool {
		return in_array(
			$this,
			[
				self::Cancelled,
				self::Completed,
				self::CompletedWithErrors,
				self::Failed,
				self::RolledBack,
				self::PartiallyRolledBack,
			],
			true
		);
	}

	public function allows_apply(): bool {
		return self::Ready === $this;
	}

	public function allows_cancel(): bool {
		return in_array(
			$this,
			[
				self::Draft,
				self::Previewing,
				self::Ready,
				self::Queued,
				self::Running,
				self::RollbackQueued,
				self::RollingBack,
			],
			true
		);
	}

	public function allows_rollback(): bool {
		return in_array(
			$this,
			[
				self::Completed,
				self::CompletedWithErrors,
			],
			true
		);
	}

	public function is_active_worker_state(): bool {
		return in_array(
			$this,
			[
				self::Previewing,
				self::Queued,
				self::Running,
				self::CancelRequested,
				self::RollbackQueued,
				self::RollingBack,
			],
			true
		);
	}
}
