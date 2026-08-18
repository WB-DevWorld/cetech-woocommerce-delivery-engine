<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Shipment\Shipment;

/**
 * One bounded list-page row. Events and item names are not loaded here.
 */
final class ShipmentListRow {

	public function __construct(
		public readonly Shipment $shipment,
		public readonly int $item_count,
		public readonly string $order_number,
		public readonly ?string $order_edit_url,
		public readonly string $customer_label
	) {
	}
}
