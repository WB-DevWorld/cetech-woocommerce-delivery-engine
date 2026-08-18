<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

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
}
