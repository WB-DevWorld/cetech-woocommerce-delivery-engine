<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use WC_Order;

/**
 * Cash on Delivery orders awaiting staff shipment creation for Needs Attention.
 *
 * Uses the compact COD index rather than scanning WooCommerce orders.
 */
final class CodAwaitingShipmentQuery {

	public function __construct(
		private readonly CodAwaitingShipmentStore $store,
		private readonly CodAwaitingShipmentEvaluator $evaluator
	) {
	}

	/**
	 * @return list<array{order_id: int, order_number: string, url: string, payment_method_label: string, title: string, detail: string}>
	 */
	public function list( int $limit = 50 ): array {
		$items = [];

		foreach ( $this->store->awaiting_order_ids() as $order_id ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				break;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( ! $this->evaluator->should_await_manual_creation( $order ) ) {
				$this->store->mark_cleared( $order );
				continue;
			}

			$url = method_exists( $order, 'get_edit_order_url' )
				? (string) $order->get_edit_order_url()
				: admin_url( 'post.php?post=' . $order_id . '&action=edit' );

			$items[] = [
				'order_id'              => $order_id,
				'order_number'          => (string) $order->get_order_number(),
				'url'                   => $url,
				'payment_method_label'  => $this->evaluator->payment_method_label( $order ),
				'title'                 => __( 'Cash on Delivery order awaiting shipment creation', 'cetech-woocommerce-delivery-engine' ),
				'detail'                => __( 'Shipment has not been created yet.', 'cetech-woocommerce-delivery-engine' ),
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
