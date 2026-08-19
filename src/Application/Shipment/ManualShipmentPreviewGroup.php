<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * One historical delivery group in a staff manual-creation preview.
 */
final class ManualShipmentPreviewGroup {

	public function __construct(
		public readonly string $delivery_option_label,
		public readonly string $fulfilment_label,
		public readonly string $eta_original,
		public readonly bool $is_pickup,
		public readonly bool $needs_creation,
		public readonly ?string $existing_shipment_number,
		public readonly ?int $existing_shipment_id = null
	) {
	}
}
