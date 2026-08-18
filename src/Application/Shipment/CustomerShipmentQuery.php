<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use WC_Order;

/**
 * Customer View Order shipment cards. Loads only the requested order. Does not load event history.
 */
final class CustomerShipmentQuery {

	public const MAX_NAMED_ITEMS = 4;

	public function __construct(
		private readonly FeatureFlags $flags,
		private readonly ShipmentRepositoryInterface $shipments
	) {
	}

	/**
	 * @return list<CustomerShipmentCard>
	 */
	public function cards_for_order( WC_Order $order ): array {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) ) {
			return [];
		}

		$order_id = (int) $order->get_id();

		if ( $order_id <= 0 ) {
			return [];
		}

		$shipments = $this->shipments->findByOrderId( $order_id );
		$cards     = [];

		foreach ( $shipments as $shipment ) {
			$card = $this->card_for_shipment( $shipment );

			if ( $card instanceof CustomerShipmentCard ) {
				$cards[] = $card;
			}
		}

		return $cards;
	}

	/**
	 * Order item ids already represented by a delivery shipment card.
	 *
	 * @return array<int, true>
	 */
	public function covered_order_item_ids( int $order_id ): array {
		if ( ! $this->flags->is_enabled( 'enable_shipment_records' ) || $order_id <= 0 ) {
			return [];
		}

		$covered = [];

		foreach ( $this->shipments->findByOrderId( $order_id ) as $shipment ) {
			foreach ( $this->shipments->findItems( $shipment->id ) as $item ) {
				$covered[ $item->order_item_id ] = true;
			}
		}

		return $covered;
	}

	private function card_for_shipment( Shipment $shipment ): ?CustomerShipmentCard {
		if ( FulfilmentChoice::StorePickup->value === $shipment->fulfilment_choice ) {
			return null;
		}

		$items = $this->shipments->findItems( $shipment->id );

		return new CustomerShipmentCard(
			$this->reference( $shipment ),
			$this->delivery_option_label( $shipment ),
			$shipment->status->label(),
			$this->eta_text( $shipment ),
			$this->eta_was_updated( $shipment ),
			count( $items ),
			$this->item_rows( $items ),
			$this->nullable( $shipment->tracking_carrier_display ),
			$this->nullable( $shipment->tracking_number ),
			$this->safe_tracking_url( $shipment->tracking_url ),
			$this->dispatch_display( $shipment->dispatch_at ),
			$this->nullable( $shipment->public_note )
		);
	}

	private function reference( Shipment $shipment ): string {
		$number = trim( $shipment->shipment_number );

		if ( '' !== $number ) {
			return $number;
		}

		return sprintf( 'SHP-%06d', max( 0, $shipment->id ) );
	}

	private function delivery_option_label( Shipment $shipment ): string {
		$label = trim( (string) $shipment->delivery_offer_public_label );

		return '' !== $label ? $label : __( 'Delivery option unavailable', 'cetech-woocommerce-delivery-engine' );
	}

	private function eta_was_updated( Shipment $shipment ): bool {
		$original = trim( (string) $shipment->eta_original );
		$current  = trim( (string) $shipment->eta_current );

		return '' !== $original && '' !== $current && $original !== $current;
	}

	private function eta_text( Shipment $shipment ): string {
		$original = trim( (string) $shipment->eta_original );
		$current  = trim( (string) $shipment->eta_current );
		$label    = '';

		if ( $this->eta_was_updated( $shipment ) ) {
			$label = $current;
		} elseif ( '' !== $current ) {
			$label = $current;
		} elseif ( '' !== $original ) {
			$label = $original;
		}

		if ( '' === $label ) {
			return '';
		}

		if ( preg_match( '/^Estimated\s+/iu', $label ) ) {
			return trim( (string) preg_replace( '/^Estimated\s+/iu', '', $label ) );
		}

		return $label;
	}

	/**
	 * @param list<ShipmentItem> $items
	 * @return list<array{name: string, quantity: int}>
	 */
	private function item_rows( array $items ): array {
		$rows = [];

		foreach ( $items as $item ) {
			$name = trim( $item->product_name_snapshot );

			if ( '' === $name ) {
				$name = __( 'Item', 'cetech-woocommerce-delivery-engine' );
			}

			$rows[] = [
				'name'     => $name,
				'quantity' => $item->quantity,
			];
		}

		return $rows;
	}

	private function safe_tracking_url( ?string $url ): ?string {
		try {
			return TrackingUrl::normalize( (string) $url );
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	private function dispatch_display( ?string $dispatch_at ): ?string {
		$display = ShipmentDispatchDate::to_display( $dispatch_at );

		return '' === $display ? null : $display;
	}

	private function nullable( ?string $value ): ?string {
		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}
}
