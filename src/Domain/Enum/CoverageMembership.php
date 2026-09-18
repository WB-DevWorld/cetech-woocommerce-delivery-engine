<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum CoverageMembership: string {

	case Include = 'include';
	case Exclude = 'exclude';
}
