<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum BulkJobItemStatus: string {

	case Pending = 'pending';
	case Claimed = 'claimed';
	case Unchanged = 'unchanged';
	case Changed = 'changed';
	case Skipped = 'skipped';
	case Failed = 'failed';
	case Cancelled = 'cancelled';
	case RolledBack = 'rolled_back';
	case RollbackSkipped = 'rollback_skipped';
	case RollbackFailed = 'rollback_failed';

	public function is_finished(): bool {
		return self::Pending !== $this && self::Claimed !== $this;
	}
}
