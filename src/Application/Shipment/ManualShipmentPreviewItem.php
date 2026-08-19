<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * One historical order item in a staff manual-creation preview.
 */
final class ManualShipmentPreviewItem {

	public function __construct(
		public readonly string $name,
		public readonly int $quantity,
		public readonly string $delivery_option_label
	) {
	}
}
