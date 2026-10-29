<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;

/**
 * Deterministic shipment plan for one historical delivery group.
 *
 * Private operational fields that RC.4 did not snapshot remain null.
 */
final class ShipmentPlan {

	/**
	 * @param list<ShipmentPlanItem> $items
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $delivery_group_id,
		public readonly string $shipment_number,
		public readonly string $fulfilment_availability,
		public readonly string $fulfilment_choice,
		public readonly ?int $delivery_offer_id,
		public readonly ?string $delivery_offer_public_label,
		public readonly ?int $destination_zone_id,
		public readonly string $currency_code,
		public readonly string $customer_paid_shipping_amount,
		public readonly ?int $rate_card_id,
		public readonly ?string $rate_card_code,
		public readonly ?string $eta_original,
		public readonly array $items
	) {
	}

	/**
	 * The frozen required item set of this group as shipment items (COR-031).
	 *
	 * @return list<ShipmentItem>
	 */
	public function shipment_items(): array {
		$items = [];

		foreach ( $this->items as $item ) {
			$items[] = ShipmentItem::create(
				0,
				$item->order_id,
				$item->order_item_id,
				$item->quantity,
				$item->product_id,
				$item->variation_id,
				$item->product_name_snapshot
			);
		}

		return $items;
	}
}
