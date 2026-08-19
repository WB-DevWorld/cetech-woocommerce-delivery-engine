<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use WC_Order;

/**
 * Decides when a Cash on Delivery order is an actionable fulfilment task.
 *
 * Never treats WC_Order::is_paid() as payment confirmation.
 * Never creates shipments.
 */
final class CodAwaitingShipmentEvaluator {

	/** @var list<string> */
	private const INELIGIBLE_STATUSES = [
		'cancelled',
		'refunded',
		'failed',
		'trash',
		'checkout-draft',
	];

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly HistoricalOrderShipmentContextFactory $context_factory,
		private readonly HistoricalShipmentPlanner $planner,
		private readonly ShipmentRepositoryInterface $shipments,
		private readonly CodAwaitingShipmentStore $store
	) {
	}

	public function sync( WC_Order $order ): void {
		if ( $this->should_await_manual_creation( $order ) ) {
			$this->store->mark_awaiting( $order );

			return;
		}

		if ( $this->store->is_indexed( $order ) ) {
			$this->store->mark_cleared( $order );
		}
	}

	public function should_await_manual_creation( WC_Order $order ): bool {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return false;
		}

		if ( (int) $order->get_id() <= 0 ) {
			return false;
		}

		if ( ! $this->is_cash_on_delivery( $order ) ) {
			return false;
		}

		if ( $this->is_ineligible_status( $order ) ) {
			return false;
		}

		if ( $this->has_persisted_paid_date( $order ) ) {
			return false;
		}

		$context = $this->context_factory->from_order( $order );

		if ( ! $context->has_delivery_engine_snapshot() ) {
			return false;
		}

		$plan_result = $this->planner->plan( $context );

		if ( ! $plan_result->ok || [] === $plan_result->plans ) {
			return false;
		}

		foreach ( $plan_result->plans as $plan ) {
			$existing = $this->shipments->findByOrderAndGroup( $plan->order_id, $plan->delivery_group_id );

			if ( null === $existing ) {
				return true;
			}
		}

		return false;
	}

	public function is_cash_on_delivery( WC_Order $order ): bool {
		if ( ! method_exists( $order, 'get_payment_method' ) ) {
			return false;
		}

		return 'cod' === strtolower( trim( (string) $order->get_payment_method() ) );
	}

	public function is_ineligible_status( WC_Order $order ): bool {
		$status = method_exists( $order, 'get_status' ) ? (string) $order->get_status() : '';
		$status = str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

		return in_array( $status, self::INELIGIBLE_STATUSES, true );
	}

	public function has_persisted_paid_date( WC_Order $order ): bool {
		if ( ! method_exists( $order, 'get_date_paid' ) ) {
			return false;
		}

		$paid = $order->get_date_paid();

		if ( $paid instanceof \DateTimeInterface ) {
			return $paid->getTimestamp() > 0;
		}

		if ( is_numeric( $paid ) ) {
			return (int) $paid > 0;
		}

		if ( ! is_string( $paid ) ) {
			return false;
		}

		$trimmed = trim( $paid );

		if ( '' === $trimmed || '0' === $trimmed || '0000-00-00 00:00:00' === $trimmed ) {
			return false;
		}

		$timestamp = strtotime( $trimmed );

		return false !== $timestamp && $timestamp > 0;
	}

	public function payment_method_label( WC_Order $order ): string {
		if ( $this->is_cash_on_delivery( $order ) ) {
			return __( 'Cash on Delivery', 'cetech-woocommerce-delivery-engine' );
		}

		if ( method_exists( $order, 'get_payment_method_title' ) ) {
			$title = trim( (string) $order->get_payment_method_title() );

			if ( '' !== $title ) {
				return $title;
			}
		}

		$method = method_exists( $order, 'get_payment_method' ) ? trim( (string) $order->get_payment_method() ) : '';

		return '' !== $method ? $method : __( 'Payment method unavailable', 'cetech-woocommerce-delivery-engine' );
	}
}
