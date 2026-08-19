<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use WC_Order;

/**
 * Bounded index of Cash on Delivery orders awaiting staff shipment creation.
 *
 * Order meta is the per-order source of truth. A compact option lists order IDs
 * so Needs Attention does not scan WooCommerce orders.
 */
final class CodAwaitingShipmentStore {

	public const STATE_META = '_cetech_de_cod_awaiting_shipment';

	public const STATE_AWAITING = 'awaiting';

	public const INDEX_OPTION = 'cetech_de_cod_awaiting_shipment_order_ids';

	public function mark_awaiting( WC_Order $order ): void {
		$order_id = (int) $order->get_id();

		if ( $order_id <= 0 ) {
			return;
		}

		$order->update_meta_data( self::STATE_META, self::STATE_AWAITING );
		$order->save();

		$ids = $this->awaiting_order_ids();

		if ( ! in_array( $order_id, $ids, true ) ) {
			$ids[] = $order_id;
			update_option( self::INDEX_OPTION, array_values( $ids ), false );
		}
	}

	public function mark_cleared( WC_Order $order ): void {
		$order_id = (int) $order->get_id();

		if ( $order_id <= 0 ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::STATE_META, true ) ) {
			$order->delete_meta_data( self::STATE_META );
			$order->save();
		}

		$ids = array_values(
			array_filter(
				$this->awaiting_order_ids(),
				static fn ( int $id ): bool => $id !== $order_id
			)
		);

		update_option( self::INDEX_OPTION, $ids, false );
	}

	public function is_awaiting( WC_Order $order ): bool {
		return self::STATE_AWAITING === (string) $order->get_meta( self::STATE_META, true );
	}

	public function is_indexed( WC_Order $order ): bool {
		$order_id = (int) $order->get_id();

		return $order_id > 0 && (
			$this->is_awaiting( $order )
			|| in_array( $order_id, $this->awaiting_order_ids(), true )
		);
	}

	/**
	 * @return list<int>
	 */
	public function awaiting_order_ids(): array {
		$stored = get_option( self::INDEX_OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$ids = [];

		foreach ( $stored as $id ) {
			$id = (int) $id;

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
