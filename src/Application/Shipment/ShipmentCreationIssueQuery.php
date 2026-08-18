<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Presentation\Admin\ShipmentCreationErrorMessages;
use WC_Order;

/**
 * Failed paid-order shipment creations for Needs Attention.
 *
 * Uses the compact failure index rather than scanning WooCommerce orders.
 */
final class ShipmentCreationIssueQuery {

	public function __construct(
		private readonly ShipmentCreationFailureStore $failures
	) {
	}

	/**
	 * @return list<array{order_id: int, order_number: string, url: string, reason: string, attempted_at: ?string, error_code: string}>
	 */
	public function list( int $limit = 50 ): array {
		$items = [];

		foreach ( $this->failures->failed_order_ids() as $order_id ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				break;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order || ! $this->failures->is_failed( $order ) ) {
				continue;
			}

			$error = $this->failures->error_code( $order );

			if ( null === $error ) {
				continue;
			}

			$url = method_exists( $order, 'get_edit_order_url' )
				? (string) $order->get_edit_order_url()
				: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

			$items[] = [
				'order_id'     => $order_id,
				'order_number' => (string) $order->get_order_number(),
				'url'          => $url,
				'reason'       => ShipmentCreationErrorMessages::describe( $error ),
				'attempted_at' => $this->failures->attempted_at( $order ),
				'error_code'   => $error->value,
			];

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return $items;
	}

	public function count(): int {
		return count( $this->list( 500 ) );
	}
}
