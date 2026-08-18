<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;

/**
 * One shipment aggregate plus WooCommerce order context for the staff detail screen.
 */
final class ShipmentWorkspaceDetail {

	/**
	 * @param list<ShipmentItem>                                                    $items
	 * @param list<ShipmentEvent>                                                   $events
	 * @param array<int, array{name: string, sku: string, variation_id: int|null}> $order_item_facts
	 */
	public function __construct(
		public readonly Shipment $shipment,
		public readonly array $items,
		public readonly array $events,
		public readonly string $order_number,
		public readonly ?string $order_edit_url,
		public readonly string $customer_label,
		public readonly array $order_item_facts
	) {
	}
}
