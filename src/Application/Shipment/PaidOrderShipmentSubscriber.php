<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use WC_Order;

/**
 * Post-payment hooks that create Delivery Engine shipments.
 *
 * Never runs at order-created / cart / checkout. Feature-flag gated.
 */
final class PaidOrderShipmentSubscriber {

	public function __construct(
		private readonly ShipmentService $service
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_action( 'woocommerce_payment_complete', [ $this, 'handle_payment_complete' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'handle_paid_status' ], 20, 2 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'handle_paid_status' ], 20, 2 );
	}

	public function handle_payment_complete( mixed $order_id ): void {
		$order = $this->resolve_order( $order_id );

		if ( null === $order ) {
			return;
		}

		$this->service->create_for_paid_order( $order, ShipmentEventSource::System );
	}

	public function handle_paid_status( mixed $order_id, mixed $order = null ): void {
		if ( ! $order instanceof WC_Order ) {
			$order = $this->resolve_order( $order_id );
		}

		if ( null === $order ) {
			return;
		}

		if ( ! method_exists( $order, 'is_paid' ) || ! $order->is_paid() ) {
			return;
		}

		$this->service->create_for_paid_order( $order, ShipmentEventSource::System );
	}

	private function resolve_order( mixed $order_id ): ?WC_Order {
		if ( $order_id instanceof WC_Order ) {
			return $order_id;
		}

		$id = (int) $order_id;

		if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $id );

		return $order instanceof WC_Order ? $order : null;
	}
}
