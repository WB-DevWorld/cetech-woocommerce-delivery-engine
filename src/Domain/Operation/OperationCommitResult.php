<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

/** NotSent requires positive transport proof; a dispatched failure is uncertain. */
enum OperationCommitResult: string {
	case Acknowledged = 'acknowledged';
	case NotSent = 'not_sent';
	case Unconfirmed = 'unconfirmed';
}
