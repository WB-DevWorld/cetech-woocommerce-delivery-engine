<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum GeographyPackStatus: string {

	case Pending = 'pending';
	case Importing = 'importing';
	case Ready = 'ready';
	case Failed = 'failed';
}
