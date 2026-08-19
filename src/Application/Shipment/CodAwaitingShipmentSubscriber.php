<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use WC_Order;

/**
 * Keeps the Cash on Delivery action-required index in sync from Woo hooks.
 *
 * Does not create shipments. Paid-order automatic creation remains separate.
 */
final class CodAwaitingShipmentSubscriber {

	public function __construct(
		private readonly CodAwaitingShipmentEvaluator $evaluator
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_action( 'woocommerce_checkout_order_created', [ $this, 'handle_order' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'handle_status' ], 25, 2 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'handle_status' ], 25, 2 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'handle_status' ], 25, 2 );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'handle_status' ], 25, 2 );
		add_action( 'woocommerce_order_status_failed', [ $this, 'handle_status' ], 25, 2 );
		add_action( 'woocommerce_order_status_trash', [ $this, 'handle_status' ], 25, 2 );
	}

	public function handle_order( mixed $order ): void {
		$resolved = $this->resolve_order( $order, null );

		if ( null === $resolved ) {
			return;
		}

		$this->evaluator->sync( $resolved );
	}

	public function handle_status( mixed $order_id, mixed $order = null ): void {
		$resolved = $this->resolve_order( $order_id, $order );

		if ( null === $resolved ) {
			return;
		}

		$this->evaluator->sync( $resolved );
	}

	private function resolve_order( mixed $order_id, mixed $order ): ?WC_Order {
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		if ( $order_id instanceof WC_Order ) {
			return $order_id;
		}

		$id = (int) $order_id;

		if ( $id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$found = wc_get_order( $id );

		return $found instanceof WC_Order ? $found : null;
	}
}
