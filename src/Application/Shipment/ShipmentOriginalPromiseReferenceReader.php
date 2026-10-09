<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteSavedOrderPlacementEvidenceReader;
use CetechDeliveryEngine\Application\Order\{OrderDeliveryLineReadResult, OrderDeliveryPackageReadResult, OrderDeliverySnapshotReader};
use CetechDeliveryEngine\Domain\Shipment\Shipment;

/** Original accepted promise only. Operational current ETA remains owned by ShipmentEtaService. */
final class ShipmentOriginalPromiseReferenceReader {
	public function __construct( private readonly QuoteSavedOrderPlacementEvidenceReader $evidence, private readonly OrderDeliverySnapshotReader $snapshots = new OrderDeliverySnapshotReader() ) {}

	public function read( Shipment $shipment, \WC_Order $order ): ?ShipmentOriginalPromiseReference {
		try {
			if ( $shipment->id < 1 || $shipment->order_id !== $order->get_id() || 'delivery' !== $shipment->fulfilment_choice ) { return null; }
			// The concrete reader authorizes the exact order before resolving any
			// private packet, then verifies the original producers and retired ACK.
			$linkage = $this->evidence->read_original_promise_linkage( $order ); if ( null === $linkage ) { return null; }
			$package = $this->snapshots->read_package( $order ); $envelope = $package->delivery_quote?->envelope;
			if ( OrderDeliveryPackageReadResult::ERROR_NONE !== $package->error || null === $package->snapshot || null === $envelope || ! $envelope->is_promise() ) { return null; }
			$found = false; foreach ( $package->snapshot->groups as $group ) { if ( $group->group_id === $shipment->delivery_group_id ) { $found = true; break; } } if ( ! $found ) { return null; }
			$found = false; foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) { return null; }
				$line = $this->snapshots->read_line( $item );
				if ( OrderDeliveryLineReadResult::ERROR_NONE !== $line->error || null === $line->snapshot || null === $line->delivery_quote?->envelope || ! $envelope->matches( $line->delivery_quote->envelope ) ) { return null; }
				if ( $line->snapshot->delivery_group_id === $shipment->delivery_group_id ) { $found = true; }
			}
			return $found ? ShipmentOriginalPromiseReference::from_original( $shipment, $envelope, $linkage ) : null;
		} catch ( \Throwable ) { return null; }
	}
}
