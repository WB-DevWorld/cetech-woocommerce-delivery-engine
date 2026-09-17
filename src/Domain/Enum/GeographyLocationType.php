<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

enum GeographyLocationType: string {

	case Country = 'country';
	case Administrative = 'administrative';
	case Locality = 'locality';
}
