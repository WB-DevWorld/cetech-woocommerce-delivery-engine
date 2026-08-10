<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum CollectionConfigurationMode: string {

	case Inherit = 'inherit';
	case Add = 'add';
	case Remove = 'remove';
	case Replace = 'replace';
}
