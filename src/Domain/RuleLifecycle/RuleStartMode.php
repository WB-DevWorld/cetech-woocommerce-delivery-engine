<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

enum RuleStartMode: string {
	case Immediate = 'immediate';
	case At = 'at';
}
