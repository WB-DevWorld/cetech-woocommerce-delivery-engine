<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use WC_Order;

/**
 * Refunded quantity inspection using WooCommerce order APIs.
 *
 * Quantity evidence is never inferred from refund amount or order status.
 * Amount-only / line-item-less refunds are unproven and require review.
 */
final class ShipmentRefundInspector {

	public const NONE     = 'none';
	public const PARTIAL  = 'partial';
	public const FULL     = 'full';
	public const UNPROVEN = 'unproven';

	/**
	 * @param list<ShipmentItem> $items
	 */
	public function state( WC_Order $order, array $items ): string {
		$quantity = $this->quantity_state( $order, $items );

		if ( self::FULL === $quantity || self::PARTIAL === $quantity ) {
			return $quantity;
		}

		if ( $this->requires_review_without_quantity_proof( $order ) ) {
			return self::UNPROVEN;
		}

		return self::NONE;
	}

	/**
	 * @param list<ShipmentItem> $items
	 */
	private function quantity_state( WC_Order $order, array $items ): string {
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

	/**
	 * A refund exists that cannot be mapped to this shipment's physical quantities.
	 */
	private function requires_review_without_quantity_proof( WC_Order $order ): bool {
		if ( $this->order_has_amount_only_refund( $order ) ) {
			return true;
		}

		if ( $this->order_has_item_quantity_refunds( $order ) ) {
			return false;
		}

		return $this->order_has_monetary_refund( $order );
	}

	private function order_has_item_quantity_refunds( WC_Order $order ): bool {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return false;
		}

		foreach ( (array) $order->get_items() as $item ) {
			$id = is_object( $item ) && method_exists( $item, 'get_id' ) ? (int) $item->get_id() : 0;

			if ( $id > 0 && $this->refunded_quantity( $order, $id ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	private function order_has_monetary_refund( WC_Order $order ): bool {
		if ( method_exists( $order, 'get_total_refunded' ) && (float) $order->get_total_refunded() > 0 ) {
			return true;
		}

		if ( ! method_exists( $order, 'get_refunds' ) ) {
			return false;
		}

		$refunds = $order->get_refunds();

		return is_array( $refunds ) && [] !== $refunds;
	}

	private function order_has_amount_only_refund( WC_Order $order ): bool {
		if ( ! method_exists( $order, 'get_refunds' ) ) {
			return false;
		}

		foreach ( (array) $order->get_refunds() as $refund ) {
			if ( ! is_object( $refund ) ) {
				continue;
			}

			$items = method_exists( $refund, 'get_items' ) ? (array) $refund->get_items() : [];

			if ( $this->refund_has_item_quantity( $items ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * @param array<int|string, mixed> $items
	 */
	private function refund_has_item_quantity( array $items ): bool {
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_quantity' ) ) {
				continue;
			}

			if ( abs( (int) $item->get_quantity() ) > 0 ) {
				return true;
			}
		}

		return false;
	}
}
