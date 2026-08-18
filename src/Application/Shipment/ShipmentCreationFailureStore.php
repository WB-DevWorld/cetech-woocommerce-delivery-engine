<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use WC_Order;

/**
 * Persistent paid-order shipment creation failure state.
 *
 * Order meta is the per-order source of truth. A compact option index lists
 * failed order IDs so Needs Attention does not scan WooCommerce orders.
 */
final class ShipmentCreationFailureStore {

	public const STATE_META   = '_cetech_de_shipment_creation_state';
	public const ERROR_META   = '_cetech_de_shipment_creation_error_code';
	public const ATTEMPTED_META = '_cetech_de_shipment_creation_attempted_at';

	public const STATE_FAILED    = 'failed';
	public const STATE_SUCCEEDED = 'succeeded';

	public const INDEX_OPTION = 'cetech_de_shipment_creation_failure_order_ids';

	public function mark_failed( WC_Order $order, ShipmentCreationErrorCode $error_code ): void {
		$order_id = (int) $order->get_id();

		$order->update_meta_data( self::STATE_META, self::STATE_FAILED );
		$order->update_meta_data( self::ERROR_META, $error_code->value );
		$order->update_meta_data( self::ATTEMPTED_META, gmdate( 'c' ) );
		$order->save();

		$ids = $this->failed_order_ids();

		if ( ! in_array( $order_id, $ids, true ) ) {
			$ids[] = $order_id;
			update_option( self::INDEX_OPTION, array_values( $ids ), false );
		}
	}

	public function mark_succeeded( WC_Order $order ): void {
		$order_id = (int) $order->get_id();

		$order->update_meta_data( self::STATE_META, self::STATE_SUCCEEDED );
		$order->delete_meta_data( self::ERROR_META );
		$order->update_meta_data( self::ATTEMPTED_META, gmdate( 'c' ) );
		$order->save();

		$ids = array_values(
			array_filter(
				$this->failed_order_ids(),
				static fn ( int $id ): bool => $id !== $order_id
			)
		);

		update_option( self::INDEX_OPTION, $ids, false );
	}

	public function error_code( WC_Order $order ): ?ShipmentCreationErrorCode {
		$raw = $order->get_meta( self::ERROR_META, true );

		return is_string( $raw ) ? ShipmentCreationErrorCode::tryFrom( $raw ) : null;
	}

	public function attempted_at( WC_Order $order ): ?string {
		$raw = $order->get_meta( self::ATTEMPTED_META, true );

		return is_string( $raw ) && '' !== $raw ? $raw : null;
	}

	public function is_failed( WC_Order $order ): bool {
		return self::STATE_FAILED === (string) $order->get_meta( self::STATE_META, true );
	}

	/**
	 * @return list<int>
	 */
	public function failed_order_ids(): array {
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
