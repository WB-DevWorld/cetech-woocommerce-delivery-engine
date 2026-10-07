<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliveryLineReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Loads historical snapshot facts from a WooCommerce order using CRUD only.
 */
final class HistoricalOrderShipmentContextFactory {

	public function __construct(
		private readonly OrderDeliverySnapshotReader $reader
	) {
	}

	public function from_order( WC_Order $order ): HistoricalOrderShipmentContext {
		$package_read = $this->reader->read_package( $order );
		$package_ok   = OrderDeliveryPackageReadResult::ERROR_NONE === $package_read->error && null !== $package_read->snapshot;
		$package_unreadable = $package_read->has_meta && ! $package_ok;
		$expected_quote = $package_read->delivery_quote?->envelope;

		$lines = [];

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$item_id = (int) $item->get_id();
			$name    = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
			$read    = $this->reader->read_line( $item );

			$unreadable = $read->has_meta && OrderDeliveryLineReadResult::ERROR_NONE !== $read->error;
			$has        = $read->has_meta && OrderDeliveryLineReadResult::ERROR_NONE === $read->error && null !== $read->snapshot;
			$line_quote = $read->delivery_quote?->envelope;
			// A quote-owned historical order cannot mix in a legacy fallback line.
			// This compares captured facts only; it never consults a current quote,
			// configuration, or private placement seal.
			if ( null !== $expected_quote && $read->has_meta && ( null === $line_quote || ! $expected_quote->matches( $line_quote ) ) ) { $unreadable = true; $has = false; }
			if ( null !== $line_quote && null === $expected_quote ) { $package_unreadable = true; $unreadable = true; $has = false; }

			$lines[] = new HistoricalShipmentLineContext(
				$item_id,
				$name,
				$has,
				$unreadable,
				$has ? $read->snapshot : null
			);
		}

		$shipping_lines = [];

		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			if ( ! is_object( $shipping_item ) || ! method_exists( $shipping_item, 'get_meta' ) ) {
				continue;
			}

			$group_id = trim( (string) $shipping_item->get_meta( 'cetech_de_group_id', true ) );

			if ( '' === $group_id ) {
				continue;
			}

			$total  = method_exists( $shipping_item, 'get_total' ) ? (string) $shipping_item->get_total() : '0';
			$method = method_exists( $shipping_item, 'get_method_id' ) ? (string) $shipping_item->get_method_id() : '';

			$shipping_lines[] = new HistoricalShippingLineContext( $group_id, $total, $method );
		}

		return new HistoricalOrderShipmentContext(
			(int) $order->get_id(),
			(string) $order->get_order_number(),
			$package_ok ? $package_read->snapshot : null,
			$package_read->has_meta,
			$package_unreadable,
			$lines,
			$shipping_lines
		);
	}
}
