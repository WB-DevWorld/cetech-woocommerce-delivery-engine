<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use WC_Order;

/**
 * Refunded quantity inspection using WooCommerce order APIs.
 */
final class ShipmentRefundInspector {

	public const NONE    = 'none';
	public const PARTIAL = 'partial';
	public const FULL    = 'full';

	/**
	 * @param list<ShipmentItem> $items
	 */
	public function state( WC_Order $order, array $items ): string {
		if ( [] === $items ) {
			return self::NONE;
		}

		$shipped  = 0;
		$refunded = 0;

		foreach ( $items as $item ) {
			$qty = max( 0, $item->quantity );
			$shipped += $qty;
			$refunded += min( $qty, $this->refunded_quantity( $order, $item->order_item_id ) );
		}

		if ( $shipped < 1 || $refunded < 1 ) {
			return self::NONE;
		}

		return $refunded >= $shipped ? self::FULL : self::PARTIAL;
	}

	public function refunded_quantity( WC_Order $order, int $order_item_id ): int {
		if ( $order_item_id <= 0 || ! method_exists( $order, 'get_qty_refunded_for_item' ) ) {
			return 0;
		}

		return abs( (int) $order->get_qty_refunded_for_item( $order_item_id ) );
	}
}
