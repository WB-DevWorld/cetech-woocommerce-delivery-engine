<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Customer-safe shipment card. Never includes private logistics or event payloads.
 */
final class CustomerShipmentCard {

	/**
	 * @param list<array{name: string, quantity: int}> $items
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $delivery_option_label,
		public readonly string $status_label,
		public readonly string $eta_text,
		public readonly bool $eta_was_updated,
		public readonly int $item_count,
		public readonly array $items,
		public readonly ?string $carrier,
		public readonly ?string $tracking_number,
		public readonly ?string $tracking_url,
		public readonly ?string $dispatch_date_display,
		public readonly ?string $public_note
	) {
	}
}
