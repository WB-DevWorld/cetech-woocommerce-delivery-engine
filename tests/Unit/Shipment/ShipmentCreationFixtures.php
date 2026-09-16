<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliveryGroupSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContext;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentLineContext;
use CetechDeliveryEngine\Application\Shipment\HistoricalShippingLineContext;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

/**
 * Shared historical-snapshot fixtures for Stage 14C tests.
 */
final class ShipmentCreationFixtures {

	public static function line(
		string $group_id,
		int $product_id = 10,
		int $order_item_id = 501,
		string $label = 'International Air',
		?string $eta = '5-7 business days',
		string $amount = '25.00',
		string $availability = 'international',
		string $choice = 'delivery',
		?int $offer_id = 12,
		string $name = 'Widget',
		int $quantity = 1,
		?int $variation_id = null,
		?int $rate_card_id = 9,
		?string $rate_card_code = 'AIR-INT',
		string $currency = 'GBP',
		?int $zone_id = 4
	): HistoricalShipmentLineContext {
		$parts = explode( '|', $group_id );
		if ( count( $parts ) >= 3 ) {
			$availability = $parts[0];
			$choice       = $parts[1];
			$offer_id     = ctype_digit( $parts[2] ) ? (int) $parts[2] : $offer_id;
		}

		return new HistoricalShipmentLineContext(
			$order_item_id,
			$name,
			true,
			false,
			new OrderDeliveryLineSnapshot(
				ProductDeliverySelectionIntent::CONTRACT_VERSION,
				OrderDeliverySnapshot::VERSION,
				$product_id,
				$variation_id,
				$availability,
				$choice,
				$offer_id,
				$label,
				null,
				$eta,
				null,
				$zone_id,
				$quantity,
				$currency,
				$amount,
				OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
				$rate_card_id,
				$rate_card_code,
				'2026-08-18 12:00:00',
				$group_id
			)
		);
	}

	public static function group(
		string $group_id,
		string $amount = '25.00',
		int $display_index = 1,
		bool $is_pickup = false,
		string $choice = 'delivery'
	): OrderDeliveryGroupSnapshot {
		if ( $is_pickup ) {
			$choice = 'store_pickup';
		}

		return new OrderDeliveryGroupSnapshot(
			$group_id,
			'delivery_engine_selected_offer',
			'International Air',
			$is_pickup ? '0.00' : $amount,
			$choice,
			$is_pickup,
			$display_index
		);
	}

	public static function package(
		array $groups,
		string $currency = 'GBP',
		?int $zone_id = 4,
		?string $total = '25.00'
	): OrderDeliveryPackageSnapshot {
		return new OrderDeliveryPackageSnapshot(
			OrderDeliverySnapshot::VERSION,
			'delivery_engine_selected_offer',
			'Delivery',
			$total,
			$currency,
			$zone_id,
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			'2026-08-18 12:00:00',
			$groups
		);
	}

	public static function context(
		array $lines,
		?OrderDeliveryPackageSnapshot $package = null,
		array $shipping_lines = [],
		int $order_id = 1001,
		string $order_number = '1001',
		bool $package_meta_present = true,
		bool $package_unreadable = false
	): HistoricalOrderShipmentContext {
		return new HistoricalOrderShipmentContext(
			$order_id,
			$order_number,
			$package,
			$package_meta_present,
			$package_unreadable,
			$lines,
			$shipping_lines
		);
	}

	public static function shipping_line(
		string $group_id,
		string $total = '25.00',
		string $method_id = 'delivery_engine_selected_offer'
	): HistoricalShippingLineContext {
		return new HistoricalShippingLineContext( $group_id, $total, $method_id );
	}

	public static function paid_order(
		int $order_id,
		array $items,
		array $shipping_items = [],
		?OrderDeliveryPackageSnapshot $package = null,
		bool $paid = true,
		string $status = 'processing',
		mixed $date_paid = false,
		string $payment_method = 'bacs',
		array $extra = []
	): WC_Order {
		$meta = [];

		if ( null !== $package ) {
			$meta[ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ]    = (string) wp_json_encode( $package->toArray() );
			$meta[ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION ] = OrderDeliverySnapshot::VERSION;
		}

		$resolved_date_paid = false === $date_paid
			? ( $paid ? '2026-08-18 12:00:00' : null )
			: $date_paid;

		$order = new WC_Order(
			array_merge(
				[
					'id'              => $order_id,
					'order_number'    => (string) $order_id,
					'paid'            => $paid,
					'status'          => $status,
					'date_paid'       => $resolved_date_paid,
					'payment_method'  => $payment_method,
					'items'           => $items,
					'shipping_items'  => $shipping_items,
					'meta'            => $meta,
				],
				$extra
			)
		);
		$order->save();

		return $order;
	}

	public static function product_item_from_line( HistoricalShipmentLineContext $line ): WC_Order_Item_Product {
		$snapshot = $line->snapshot;
		$meta     = [];

		if ( null !== $snapshot ) {
			$meta[ OrderDeliverySnapshot::META_LINE_SNAPSHOT ]         = (string) wp_json_encode( $snapshot->toArray() );
			$meta[ OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION ] = OrderDeliverySnapshot::VERSION;
		}

		return new WC_Order_Item_Product(
			[
				'id'   => $line->order_item_id,
				'name' => $line->product_name,
				'meta' => $meta,
			]
		);
	}

	public static function wc_shipping_line(
		string $group_id,
		string $total = '25.00',
		string $method_id = 'delivery_engine_selected_offer'
	): WC_Order_Item_Shipping {
		return new WC_Order_Item_Shipping(
			[
				'total'     => $total,
				'method_id' => $method_id,
				'meta'      => [ 'cetech_de_group_id' => $group_id ],
			]
		);
	}

	public static function repository(): WpdbShipmentRepository {
		$wpdb = new FakeWpdb();
		$wpdb->create_table(
			'wp_delivery_engine_shipments',
			[
				[ 'idempotency_key' ],
				[ 'order_id', 'delivery_group_id' ],
			]
		);
		$wpdb->create_table(
			'wp_delivery_engine_shipment_items',
			[
				[ 'shipment_id', 'order_item_id' ],
			]
		);
		$wpdb->create_table( 'wp_delivery_engine_shipment_events' );
		$GLOBALS['wpdb'] = $wpdb;

		return new WpdbShipmentRepository();
	}
}
