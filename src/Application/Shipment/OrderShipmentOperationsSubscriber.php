<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use WC_Order;

/**
 * Conservative WooCommerce order-cancelled / refund sync for existing shipments.
 *
 * Never refunds money, never changes paid shipping snapshots, never completes orders.
 */
final class OrderShipmentOperationsSubscriber {

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly ShipmentRepositoryInterface $shipments,
		private readonly ShipmentStatusService $status,
		private readonly ShipmentRefundInspector $refunds,
		private readonly ShipmentOperationsIssueStore $issues
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_cancelled', [ $this, 'handle_order_cancelled' ], 20, 2 );
		add_action( 'woocommerce_order_refunded', [ $this, 'handle_order_refunded' ], 20, 2 );
	}

	public function handle_order_cancelled( mixed $order_id, mixed $order = null ): void {
		$resolved = $this->resolve_order( $order_id, $order );

		if ( null === $resolved ) {
			return;
		}

		foreach ( $this->order_shipments( $resolved ) as $shipment ) {
			$this->sync_cancelled_order( $resolved, $shipment );
		}
	}

	public function handle_order_refunded( mixed $order_id, mixed $refund_id = null ): void {
		unset( $refund_id );

		$resolved = $this->resolve_order( $order_id, null );

		if ( null === $resolved ) {
			return;
		}

		foreach ( $this->order_shipments( $resolved ) as $shipment ) {
			$this->sync_refund( $resolved, $shipment );
		}
	}

	private function sync_cancelled_order( WC_Order $order, Shipment $shipment ): void {
		if ( ShipmentStatus::Cancelled === $shipment->status ) {
			return;
		}

		if ( ShipmentStatusTransitionPolicy::allows_automatic_cancel( $shipment->status ) ) {
			$this->auto_cancel(
				$order,
				$shipment,
				ShipmentStatusService::REASON_ORDER_CANCELLED
			);

			return;
		}

		if ( ShipmentStatusTransitionPolicy::has_physically_progressed( $shipment->status ) ) {
			$this->issues->add(
				$shipment->id,
				(int) $order->get_id(),
				ShipmentOperationsIssueStore::CODE_ORDER_CANCELLED_AFTER_PROGRESS
			);
		}
	}

	private function sync_refund( WC_Order $order, Shipment $shipment ): void {
		if ( ShipmentStatus::Cancelled === $shipment->status ) {
			return;
		}

		$items = $this->shipments->findItems( $shipment->id );
		$state = $this->refunds->state( $order, $items );

		if ( ShipmentRefundInspector::NONE === $state ) {
			return;
		}

		// FULL quantity evidence is the only auto-cancel path. UNPROVEN
		// (amount-only / no refund line items) and PARTIAL never change status.
		if (
			ShipmentRefundInspector::FULL === $state
			&& ShipmentStatusTransitionPolicy::allows_automatic_cancel( $shipment->status )
		) {
			$this->auto_cancel(
				$order,
				$shipment,
				ShipmentStatusService::REASON_SHIPMENT_QUANTITIES_REFUNDED
			);

			return;
		}

		$this->issues->add(
			$shipment->id,
			(int) $order->get_id(),
			ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW
		);
	}

	private function auto_cancel( WC_Order $order, Shipment $shipment, string $reason ): void {
		try {
			$result = $this->status->change(
				$shipment->id,
				ShipmentStatus::Cancelled,
				ShipmentStatusChangeRequest::automatic( ShipmentEventSource::WooCommerce, $reason )
			);
		} catch ( \Throwable ) {
			$this->issues->add(
				$shipment->id,
				(int) $order->get_id(),
				ShipmentOperationsIssueStore::CODE_STATUS_SYNC_FAILED
			);

			return;
		}

		if ( $result->ok ) {
			return;
		}

		$this->issues->add(
			$shipment->id,
			(int) $order->get_id(),
			ShipmentOperationsIssueStore::CODE_STATUS_SYNC_FAILED
		);
	}

	/**
	 * @return list<Shipment>
	 */
	private function order_shipments( WC_Order $order ): array {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return [];
		}

		$order_id = (int) $order->get_id();

		if ( $order_id <= 0 ) {
			return [];
		}

		return $this->shipments->findByOrderId( $order_id );
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

		$resolved = wc_get_order( $id );

		return $resolved instanceof WC_Order ? $resolved : null;
	}
}
