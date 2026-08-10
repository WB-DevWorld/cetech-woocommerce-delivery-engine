<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum ScalarConfigurationMode: string {

	case Inherit = 'inherit';
	case Override = 'override';
	case Disable = 'disable';
}
