<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, DeliveryQuoteSnapshotMarker, OrderDeliverySnapshot, OrderDeliverySnapshotJson};
use CetechDeliveryEngine\Domain\Shipment\Shipment;

/** Declaration guard only: existing legacy text never substitutes for a required promise history. */
final class PromiseShipmentLegacyEtaGuard {

	public static function allows_text_edit( Shipment $shipment ): bool {
		if ( $shipment->order_id < 1 || ! class_exists( '\\WC_Order' ) ) { return false; }
		try {
			// The factory can return a dirty cached carrier with unsaved promise markers removed.
			$order = new \WC_Order( $shipment->order_id );
			return \WC_Order::class === get_class( $order ) && $order->get_id() === $shipment->order_id && self::allows_order( $order );
		} catch ( \Throwable ) { return false; }
	}

	public static function allows_order( \WC_Order $order ): bool {
		try {
			if ( ! self::legacy_declaration( $order, OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION ) ) { return false; }
			$items = $order->get_items( 'line_item' );
			if ( ! is_array( $items ) || count( $items ) > 600 ) { return false; }
			foreach ( $items as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || ! self::legacy_declaration( $item, OrderDeliverySnapshot::META_LINE_SNAPSHOT, OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION ) ) { return false; }
			}
			return true;
		} catch ( \Throwable ) { return false; }
	}

	private static function legacy_declaration( object $carrier, string $body_key, string $version_key ): bool {
		$marker = DeliveryQuoteSnapshotMarker::exists( $carrier );
		if ( null === $marker || ( $marker && ! in_array( $carrier->get_meta( DeliveryQuoteSnapshotEnvelope::META_FORMAT, true ), [ 1, '1' ], true ) ) ) { return false; }
		$version = $carrier->get_meta( $version_key, true );
		if ( ! self::legacy_version( $version ) ) { return false; }
		$raw = $carrier->get_meta( $body_key, true );
		if ( is_array( $raw ) ) { $decoded = $raw; }
		elseif ( is_string( $raw ) && '' !== $raw ) {
			try { $decoded = OrderDeliverySnapshotJson::decode( $raw ); }
			catch ( \InvalidArgumentException ) { return true; } // Malformed legacy text history is not a new promise declaration.
		} else { return true; }
		if ( ! self::legacy_version( $decoded['snapshot_version'] ?? null ) ) { return false; }
		$quote = $decoded[DeliveryQuoteSnapshotEnvelope::MEMBER] ?? null;
		$format = $quote instanceof \stdClass ? ( $quote->format ?? null ) : ( is_array( $quote ) ? ( $quote['format'] ?? null ) : null );
		return null === $format || in_array( $format, [ 1, '1' ], true );
	}

	private static function legacy_version( mixed $version ): bool { return in_array( $version, [ null, '', 1, '1', 2, '2' ], true ); }
}
