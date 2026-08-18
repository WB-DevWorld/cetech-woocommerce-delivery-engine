<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Machine codes for who/what recorded a shipment event.
 */
enum ShipmentEventSource: string {

	case System = 'system';
	case Staff  = 'staff';
	case Retry  = 'retry';
}
