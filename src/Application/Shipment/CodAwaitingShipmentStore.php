<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use WC_Order;

/**
 * Bounded index of Cash on Delivery orders awaiting staff shipment creation.
 *
 * Order meta is the per-order source of truth. A compact option lists order IDs
 * so Needs Attention does not scan WooCommerce orders. COR-033: index writes run
 * under AttentionIndexLock so concurrent writers keep each other's entries, and
 * report false when the index could not be written (the order meta still holds).
 */
final class CodAwaitingShipmentStore {

	public const STATE_META = '_cetech_de_cod_awaiting_shipment';

	public const STATE_AWAITING = 'awaiting';

	public const INDEX_OPTION = 'cetech_de_cod_awaiting_shipment_order_ids';

	public function mark_awaiting( WC_Order $order ): bool {
		$order_id = (int) $order->get_id();

		if ( $order_id <= 0 ) {
			return false;
		}

		$order->update_meta_data( self::STATE_META, self::STATE_AWAITING );
		$order->save();

		return $this->mutate_index(
			function ( array $ids ) use ( $order_id ): array {
				if ( ! in_array( $order_id, $ids, true ) ) {
					$ids[] = $order_id;
				}

				return $ids;
			}
		);
	}

	public function mark_cleared( WC_Order $order ): bool {
		$order_id = (int) $order->get_id();

		if ( $order_id <= 0 ) {
			return false;
		}

		if ( '' !== (string) $order->get_meta( self::STATE_META, true ) ) {
			$order->delete_meta_data( self::STATE_META );
			$order->save();
		}

		return $this->mutate_index(
			static fn ( array $ids ): array => array_values(
				array_filter( $ids, static fn ( int $id ): bool => $id !== $order_id )
			)
		);
	}

	public function count(): int {
		return count( $this->awaiting_order_ids() );
	}

	/**
	 * @param callable(list<int>): list<int> $change
	 */
	private function mutate_index( callable $change ): bool {
		try {
			return AttentionIndexLock::run(
				self::INDEX_OPTION,
				function () use ( $change ): bool {
					$current = $this->awaiting_order_ids();
					$next    = array_values( array_unique( $change( $current ) ) );

					if ( $next === $current ) {
						return true;
					}

					return (bool) update_option( self::INDEX_OPTION, $next, false );
				}
			);
		} catch ( AttentionIndexBusyException ) {
			return false;
		}
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
