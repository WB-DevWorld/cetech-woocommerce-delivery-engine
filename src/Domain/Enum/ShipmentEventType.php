<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Machine codes for shipment history event types.
 */
enum ShipmentEventType: string {

	case Created          = 'created';
	case StatusChanged    = 'status_changed';
	case TrackingAdded    = 'tracking_added';
	case TrackingUpdated  = 'tracking_updated';
	case NoteAdded        = 'note_added';
}
