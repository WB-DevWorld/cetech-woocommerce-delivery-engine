<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;

/**
 * One product order item plus its historical line snapshot when present.
 */
final class HistoricalShipmentLineContext {

	public function __construct(
		public readonly int $order_item_id,
		public readonly string $product_name,
		public readonly bool $has_snapshot,
		public readonly bool $snapshot_unreadable,
		public readonly ?OrderDeliveryLineSnapshot $snapshot
	) {
	}
}
