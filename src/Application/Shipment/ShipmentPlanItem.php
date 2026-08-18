<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * One WooCommerce order item belonging to a historical delivery group.
 */
final class ShipmentPlanItem {

	public function __construct(
		public readonly int $order_item_id,
		public readonly int $order_id,
		public readonly ?int $product_id,
		public readonly ?int $variation_id,
		public readonly int $quantity,
		public readonly string $product_name_snapshot
	) {
	}
}
