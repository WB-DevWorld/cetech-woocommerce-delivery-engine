<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use WC_Order;

/**
 * Bounded operational shipment issues for Needs Attention.
 *
 * Delayed shipments are listed from the status index. Cancel/refund review uses
 * the compact operations-issue option. No all-order or all-shipment scans.
 */
final class ShipmentOperationsIssueQuery {

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly ShipmentRepositoryInterface $shipments,
		private readonly ShipmentOperationsIssueStore $issues
	) {
	}

	/**
	 * @return list<array{
	 *     shipment_id: int,
	 *     shipment_number: string,
	 *     order_id: int,
	 *     order_number: string,
	 *     order_url: string,
	 *     shipment_url: string,
	 *     codes: list<string>,
	 *     reasons: list<string>
	 * }>
	 */
	public function list( int $limit = 50 ): array {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return [];
		}

		$limit   = max( 1, min( 50, $limit ) );
		$by_id   = [];

		foreach ( $this->issues->all() as $shipment_id => $row ) {
			$shipment = $this->shipments->findById( $shipment_id );

			if ( ! $shipment instanceof Shipment ) {
				continue;
			}

			if ( ShipmentStatus::Cancelled === $shipment->status ) {
				continue;
			}

			$by_id[ $shipment_id ] = $this->item( $shipment, $row['codes'] );
		}

		$delayed = $this->shipments->list(
			[ 'status' => ShipmentStatus::Delayed->value ],
			1,
			$limit
		);

		foreach ( $delayed->items as $shipment ) {
			$existing = $by_id[ $shipment->id ]['codes'] ?? [];
			$codes    = array_values( array_unique( array_merge( $existing, [ 'delayed' ] ) ) );
			$by_id[ $shipment->id ] = $this->item( $shipment, $codes );
		}

		return array_values( array_slice( $by_id, 0, $limit ) );
	}

	/**
	 * @param list<string> $codes
	 * @return array{
	 *     shipment_id: int,
	 *     shipment_number: string,
	 *     order_id: int,
	 *     order_number: string,
	 *     order_url: string,
	 *     shipment_url: string,
	 *     codes: list<string>,
	 *     reasons: list<string>
	 * }
	 */
	private function item( Shipment $shipment, array $codes ): array {
		$order_id     = $shipment->order_id;
		$order_number = (string) $order_id;
		$order_url    = '';

		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				$order_number = (string) $order->get_order_number();
				$order_url    = method_exists( $order, 'get_edit_order_url' )
					? (string) $order->get_edit_order_url()
					: '';
			}
		}

		$reasons = [];

		foreach ( $codes as $code ) {
			$reasons[] = $this->reason_label( $code );
		}

		return [
			'shipment_id'     => $shipment->id,
			'shipment_number' => '' !== trim( $shipment->shipment_number )
				? $shipment->shipment_number
				: sprintf( 'SHP-%06d', $shipment->id ),
			'order_id'        => $order_id,
			'order_number'    => $order_number,
			'order_url'       => $order_url,
			'shipment_url'    => add_query_arg(
				[
					'page'     => 'cetech-delivery-engine-shipments',
					'shipment' => $shipment->id,
				],
				admin_url( 'admin.php' )
			),
			'codes'           => array_values( $codes ),
			'reasons'         => $reasons,
		];
	}

	private function reason_label( string $code ): string {
		return match ( $code ) {
			'delayed' => __( 'This shipment is delayed or has an operational issue.', 'cetech-woocommerce-delivery-engine' ),
			ShipmentOperationsIssueStore::CODE_ORDER_CANCELLED_AFTER_PROGRESS => __( 'The WooCommerce order is cancelled, but this shipment has already progressed.', 'cetech-woocommerce-delivery-engine' ),
			ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW => __( 'A refund needs a physical fulfilment review for this shipment.', 'cetech-woocommerce-delivery-engine' ),
			ShipmentOperationsIssueStore::CODE_STATUS_SYNC_FAILED => __( 'Automatic shipment status sync from the WooCommerce order failed.', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'This shipment needs operational review.', 'cetech-woocommerce-delivery-engine' ),
		};
	}
}
