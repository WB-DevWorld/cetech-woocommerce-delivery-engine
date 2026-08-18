<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

enum ShipmentStatusChangeMode: string {

	case Normal     = 'normal';
	case Correction = 'correction';
	case Automatic  = 'automatic';
}
