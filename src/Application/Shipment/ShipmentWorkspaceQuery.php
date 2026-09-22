<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use WC_Order;

/**
 * Staff workspace reads. List queries stay paginated in the repository.
 *
 * Does not consult current product configuration, EffectiveConfigurationResolver,
 * or Rate Cards. Historical shipment fields are the delivery truth.
 */
final class ShipmentWorkspaceQuery {

	public const PER_PAGE = 20;

	public function __construct(
		private readonly ShipmentRepositoryInterface $shipments
	) {
	}

	public function list( string $search = '', string $status = '', int $page = 1, int $per_page = self::PER_PAGE ): ShipmentWorkspaceListResult {
		$criteria = [];

		if ( '' !== $search ) {
			$criteria['search'] = $search;
		}

		if ( '' !== $status ) {
			if ( null === ShipmentStatus::tryFromMachineCode( $status ) ) {
				return new ShipmentWorkspaceListResult( [], 0, max( 1, $page ), max( 1, min( 100, $per_page ) ) );
			}

			$criteria['status'] = $status;
		}

		$result = $this->shipments->list( $criteria, $page, $per_page );
		$ids    = [];

		foreach ( $result->items as $shipment ) {
			$ids[] = $shipment->id;
		}

		$item_counts = $this->shipments->countItemsByShipmentIds( $ids );
		$orders      = $this->load_orders( $this->order_ids( $result->items ) );
		$rows        = [];

		foreach ( $result->items as $shipment ) {
			$order = $orders[ $shipment->order_id ] ?? null;
			$rows[] = new ShipmentListRow(
				$shipment,
				$item_counts[ $shipment->id ] ?? 0,
				$this->order_number( $shipment, $order ),
				$this->order_edit_url( $order ),
				$this->customer_label( $order )
			);
		}

		return new ShipmentWorkspaceListResult( $rows, $result->total, $result->page, $result->per_page );
	}

	public function detail( int $shipment_id ): ?ShipmentWorkspaceDetail {
		if ( $shipment_id <= 0 ) {
			return null;
		}

		$shipment = $this->shipments->findById( $shipment_id );

		if ( null === $shipment ) {
			return null;
		}

		$items  = $this->shipments->findItems( $shipment->id );
		$events = $this->shipments->findEvents( $shipment->id );
		$orders = $this->load_orders( [ $shipment->order_id ] );
		$order  = $orders[ $shipment->order_id ] ?? null;

		return new ShipmentWorkspaceDetail(
			$shipment,
			$items,
			$events,
			$this->order_number( $shipment, $order ),
			$this->order_edit_url( $order ),
			$this->customer_label( $order ),
			$this->order_item_facts( $order )
		);
	}

	/**
	 * @param list<Shipment> $shipments
	 * @return list<int>
	 */
	private function order_ids( array $shipments ): array {
		$ids = [];

		foreach ( $shipments as $shipment ) {
			$ids[ $shipment->order_id ] = $shipment->order_id;
		}

		return array_values( $ids );
	}

	/**
	 * @param list<int> $order_ids
	 * @return array<int, WC_Order>
	 */
	private function load_orders( array $order_ids ): array {
		$ids = [];

		foreach ( $order_ids as $order_id ) {
			$order_id = (int) $order_id;

			if ( $order_id > 0 ) {
				$ids[ $order_id ] = $order_id;
			}
		}

		if ( [] === $ids ) {
			return [];
		}

		$ids    = array_values( $ids );
		$orders = [];

		if ( function_exists( 'wc_get_orders' ) ) {
			$found = wc_get_orders(
				[
					'include' => $ids,
					'limit'   => count( $ids ),
					'type'    => 'shop_order',
					'return'  => 'objects',
				]
			);

			if ( is_array( $found ) ) {
				foreach ( $found as $order ) {
					if ( $order instanceof WC_Order ) {
						$orders[ $order->get_id() ] = $order;
					}
				}
			}
		}

		if ( function_exists( 'wc_get_order' ) ) {
			foreach ( $ids as $order_id ) {
				if ( isset( $orders[ $order_id ] ) ) {
					continue;
				}

				$order = wc_get_order( $order_id );

				if ( $order instanceof WC_Order ) {
					$orders[ $order_id ] = $order;
				}
			}
		}

		return $orders;
	}

	private function order_number( Shipment $shipment, ?WC_Order $order ): string {
		if ( $order instanceof WC_Order && method_exists( $order, 'get_order_number' ) ) {
			$number = trim( (string) $order->get_order_number() );

			if ( '' !== $number ) {
				return $number;
			}
		}

		return (string) $shipment->order_id;
	}

	private function order_edit_url( ?WC_Order $order ): ?string {
		if ( ! $order instanceof WC_Order || ! method_exists( $order, 'get_edit_order_url' ) ) {
			return null;
		}

		$can_edit = current_user_can( 'edit_shop_orders' )
			|| current_user_can( 'edit_shop_order', $order->get_id() );

		if ( ! $can_edit ) {
			return null;
		}

		$url = (string) $order->get_edit_order_url();

		return '' !== $url ? $url : null;
	}

	private function customer_label( ?WC_Order $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return __( 'Customer unavailable', 'cetech-woocommerce-delivery-engine' );
		}

		if ( method_exists( $order, 'get_formatted_billing_full_name' ) ) {
			$name = trim( (string) $order->get_formatted_billing_full_name() );

			if ( '' !== $name ) {
				return $name;
			}
		}

		$first = method_exists( $order, 'get_billing_first_name' ) ? trim( (string) $order->get_billing_first_name() ) : '';
		$last  = method_exists( $order, 'get_billing_last_name' ) ? trim( (string) $order->get_billing_last_name() ) : '';
		$name  = trim( $first . ' ' . $last );

		if ( '' !== $name ) {
			return $name;
		}

		if ( method_exists( $order, 'get_billing_company' ) ) {
			$company = trim( (string) $order->get_billing_company() );

			if ( '' !== $company ) {
				return $company;
			}
		}

		return __( 'Guest', 'cetech-woocommerce-delivery-engine' );
	}

	/**
	 * @return array<int, array{name: string, sku: string, variation_id: int|null}>
	 */
	private function order_item_facts( ?WC_Order $order ): array {
		if ( ! $order instanceof WC_Order || ! method_exists( $order, 'get_items' ) ) {
			return [];
		}

		$facts = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_id' ) ) {
				continue;
			}

			$id = (int) $item->get_id();

			if ( $id <= 0 ) {
				continue;
			}

			$facts[ $id ] = [
				'name'         => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '',
				'sku'          => method_exists( $item, 'get_sku' ) ? (string) $item->get_sku() : '',
				'variation_id' => method_exists( $item, 'get_variation_id' ) ? (int) $item->get_variation_id() : null,
			];
		}

		return $facts;
	}
}
