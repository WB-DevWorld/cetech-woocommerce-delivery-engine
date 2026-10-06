<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

enum RuleState: string {
	case Draft = 'draft';
	case Scheduled = 'scheduled';
	case Published = 'published';
	case Retired = 'retired';
}
