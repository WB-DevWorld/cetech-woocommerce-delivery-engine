<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Enum;

/**
 * Machine codes for genuine paid-order shipment creation failures.
 */
enum ShipmentCreationErrorCode: string {

	case MissingSnapshot          = 'missing_snapshot';
	case MalformedGroupSnapshot   = 'malformed_group_snapshot';
	case MissingOrderItem         = 'missing_order_item';
	case GroupItemMismatch        = 'group_item_mismatch';
	case ShippingAmountMismatch   = 'shipping_amount_mismatch';
	case RepositoryWriteFailed    = 'repository_write_failed';
	case AggregateIncomplete      = 'aggregate_incomplete';
}
