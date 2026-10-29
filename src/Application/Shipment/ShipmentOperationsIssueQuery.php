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
 * COR-033: list() returns one bounded page; total() is the real number of open
 * issues (index entries on live, non-cancelled shipments plus delayed shipments,
 * counted once), so the badge and the page disclose how much a page omits.
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
	public const PAGE_LIMIT = 50;

	public function list( int $limit = self::PAGE_LIMIT ): array {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return [];
		}

		$limit = max( 1, min( self::PAGE_LIMIT, $limit ) );
		$by_id = [];

		foreach ( $this->indexed_open_issues( $limit ) as $shipment_id => $open ) {
			$by_id[ $shipment_id ] = $this->item( $open['shipment'], $open['codes'] );
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
	 * Real number of open operational issues, regardless of the page size.
	 */
	public function total(): int {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return 0;
		}

		$indexed = $this->indexed_open_issues( null );
		$delayed = $this->shipments->list( [ 'status' => ShipmentStatus::Delayed->value ], 1, 1 )->total;
		$overlap = 0;

		foreach ( $indexed as $open ) {
			if ( ShipmentStatus::Delayed === $open['shipment']->status ) {
				++$overlap;
			}
		}

		return count( $indexed ) + $delayed - $overlap;
	}

	public function count(): int {
		return $this->total();
	}

	/**
	 * @return array{shown: int, total: int, truncated: bool}
	 */
	public function summary( int $limit = self::PAGE_LIMIT ): array {
		$shown = count( $this->list( $limit ) );
		$total = $this->total();

		return [
			'shown'     => $shown,
			'total'     => $total,
			'truncated' => $total > $shown,
		];
	}

	/**
	 * Index entries whose shipment exists and is not cancelled, looked up in bounded batches.
	 *
	 * @return array<int, array{shipment: Shipment, codes: list<string>}>
	 */
	private function indexed_open_issues( ?int $limit ): array {
		$index = $this->issues->all();

		if ( [] === $index ) {
			return [];
		}

		$open      = [];
		$shipments = $this->shipments->findByIds( array_keys( $index ) );

		foreach ( $index as $shipment_id => $row ) {
			$shipment = $shipments[ $shipment_id ] ?? null;

			if ( ! $shipment instanceof Shipment || ShipmentStatus::Cancelled === $shipment->status ) {
				continue;
			}

			$open[ $shipment_id ] = [
				'shipment' => $shipment,
				'codes'    => $row['codes'],
			];

			if ( null !== $limit && count( $open ) >= $limit ) {
				break;
			}
		}

		return $open;
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
