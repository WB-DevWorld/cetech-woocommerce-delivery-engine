<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Shipment;

/**
 * Order line captured onto a shipment from historical order data.
 */
final class ShipmentItem {

	public function __construct(
		public readonly int $id,
		public readonly int $shipment_id,
		public readonly int $order_id,
		public readonly int $order_item_id,
		public readonly ?int $product_id,
		public readonly ?int $variation_id,
		public readonly int $quantity,
		public readonly string $product_name_snapshot,
		public readonly string $created_at
	) {
		if ( $order_id <= 0 || $order_item_id <= 0 ) {
			throw new \InvalidArgumentException( 'Shipment items require positive order and order-item IDs.' );
		}

		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( 'Shipment item quantity must be at least 1.' );
		}
	}

	public static function create(
		int $shipment_id,
		int $order_id,
		int $order_item_id,
		int $quantity = 1,
		?int $product_id = null,
		?int $variation_id = null,
		string $product_name_snapshot = '',
		int $id = 0,
		string $created_at = ''
	): self {
		return new self(
			$id,
			$shipment_id,
			$order_id,
			$order_item_id,
			$product_id,
			$variation_id,
			$quantity,
			$product_name_snapshot,
			$created_at
		);
	}
}
