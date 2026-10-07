<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;

/** Actual classic Woo CRUD with only this fixture's order store selected; no HPOS option change. */
return static function ( callable $check, callable $cleanup_pressure ): void {
	global $wpdb;
	$main = $wpdb;
	$orders = [];
	$items = [];
	$product_id = 0;
	$select_classic = static fn (): string => WC_Order_Data_Store_CPT::class;
	$reader = new OrderDeliverySnapshotReader();
	$factory = new HistoricalOrderShipmentContextFactory( $reader );
	$planner = new HistoricalShipmentPlanner();
	$encode = static fn ( array $value ): string => json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	$canonical = static function ( array $facts ) use ( &$canonical ): array { if ( ! array_is_list( $facts ) ) { ksort( $facts, SORT_STRING ); } foreach ( $facts as $key => $value ) { if ( is_array( $value ) ) { $facts[$key] = $canonical( $value ); } } return $facts; };
	$rows = static function ( string $sql ) use ( $main ): array {
		$found = $main->get_results( $sql, ARRAY_A );
		if ( ! is_array( $found ) || '' !== $main->last_error ) { throw new RuntimeException( 'Classic lifecycle readback failed.' ); }
		return $found;
	};
	$physical = static function () use ( &$orders, &$items, $rows, $main ): array {
		$order_ids = implode( ',', array_map( 'intval', $orders ) );
		$item_ids = implode( ',', array_map( 'intval', $items ) );
		return [
			[] === $orders ? [] : $rows( "SELECT meta_id,post_id,meta_key,meta_value FROM `{$main->postmeta}` WHERE post_id IN ({$order_ids}) AND meta_key IN ('_cetech_de_delivery_quote_snapshot','_cetech_de_order_delivery_snapshot_version','_c06_foreign_sentinel') ORDER BY meta_id" ),
			[] === $items ? [] : $rows( "SELECT meta_id,order_item_id,meta_key,meta_value FROM `{$main->prefix}woocommerce_order_itemmeta` WHERE order_item_id IN ({$item_ids}) AND meta_key IN ('_cetech_de_delivery_snapshot','_cetech_de_delivery_snapshot_version','cetech_de_group_id','_c06_foreign_sentinel') ORDER BY meta_id" ),
		];
	};
	$history = static function ( array $fixture ) use ( $reader, $factory, $planner ): array {
		// Instantiate directly under the fixture's real CPT data-store filter;
		// wc_get_order's HPOS order-type routing is not a classic-store lookup.
		$order = new WC_Order( $fixture[0] );
		$order->read_meta_data( true );
		$item = new WC_Order_Item_Product( $fixture[1] );
		$item->read_meta_data( true );
		$line = $reader->read_line( $item );
		$package = $reader->read_package( $order );
		$plan = $planner->plan( $factory->from_order( $order ) );
		return [ $line->error, $line->snapshot?->toArray(), $package->error, $package->snapshot?->toArray(), $plan->ok, $plan->error_code?->value, array_map( static function ( $row ): array { $facts = get_object_vars( $row ); $facts['items'] = array_map( 'get_object_vars', $row->items ); return $facts; }, $plan->plans ) ];
	};
	$seed = static function ( array|string $line, string $version, array|string $package ) use ( &$orders, &$items, &$product_id, $encode ): array {
		$order = new WC_Order();
		$order->set_currency( 'GHS' );
		$order->update_meta_data( '_c06_foreign_sentinel', 'PRIVATE-C06-CLASSIC-ORDER' );
		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, is_array( $package ) ? $encode( $package ) : $package );
		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, $version );
		$order->save();
		$orders[] = $order->get_id();
		$item = new WC_Order_Item_Product();
		$item->set_product_id( $product_id ); $item->set_name( 'C06 classic historical fixture' ); $item->set_quantity( 2 ); $item->set_subtotal( '20.00' ); $item->set_total( '20.00' );
		$item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, is_array( $line ) ? $encode( $line ) : $line, true );
		$item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, $version, true );
		$item->add_meta_data( '_c06_foreign_sentinel', 'PRIVATE-C06-CLASSIC-LINE', true );
		$order->add_item( $item );
		if ( is_array( $line ) ) {
			$shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'delivery_engine_selected_offer' ); $shipping->set_method_title( 'Captured classic service' ); $shipping->set_total( '15.00' ); $shipping->add_meta_data( 'cetech_de_group_id', $line['delivery_group_id'], true ); $order->add_item( $shipping );
		}
		$order->save();
		foreach ( $order->get_items( [ 'line_item', 'shipping' ] ) as $saved ) { $items[] = $saved->get_id(); }
		if ( $order->get_id() < 1 || $item->get_id() < 1 ) { throw new RuntimeException( 'Classic lifecycle order creation failed.' ); }
		return [ $order->get_id(), $item->get_id() ];
	};
	$cleanup_ok = false;
	add_filter( 'woocommerce_order_data_store', $select_classic, PHP_INT_MAX );
	try {
		$product = new WC_Product_Simple(); $product->set_name( 'C06 classic fixture product' ); $product->set_status( 'publish' ); $product_id = $product->save();
		if ( $product_id < 1 ) { throw new RuntimeException( 'Classic lifecycle product creation failed.' ); }
		$v1 = [ 'contract_version' => '1', 'snapshot_version' => '1', 'product_id' => $product_id, 'variation_id' => null, 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 17, 'delivery_offer_public_label' => 'Captured classic offer', 'delivery_offer_public_description' => 'Captured classic description', 'estimate_text' => 'Captured classic estimate', 'rule_id' => 111, 'destination_zone_id' => 19, 'quantity' => 2, 'currency_code' => 'GHS', 'quoted_amount' => '7.5000', 'quote_status' => 'quoted', 'rate_card_id' => 29, 'rate_card_code' => 'CAPTURED-C06', 'snapshotted_at' => '2026-09-01T10:00:00+00:00', 'delivery_group_id' => 'in_warehouse|delivery|17' ];
		$package1 = [ 'snapshot_version' => '1', 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Captured classic service', 'package_total_delivery_amount' => '15.0000', 'currency_code' => 'GHS', 'destination_zone_id' => 19, 'quote_status' => 'success', 'snapshotted_at' => '2026-09-01T10:00:00+00:00', 'groups' => [ [ 'group_id' => $v1['delivery_group_id'], 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Captured classic service', 'package_total_delivery_amount' => '15.0000', 'fulfilment_choice' => 'delivery', 'is_pickup' => false, 'display_index' => 1 ] ] ];
		$address = DeliveryAddress::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Synthetic classic city', 'postcode' => '00001', 'address_1' => 'PRIVATE-C06-CLASSIC-ADDRESS', 'address_2' => '', 'first_name' => 'Synthetic', 'last_name' => 'Classic', 'company' => '', 'phone' => '0000000000' ] );
		$context = CustomerCartContext::delivery( 17, $address->matching, $address );
		$v2 = $v1; $v2['snapshot_version'] = '2'; $v2['delivery_group_id'] = (string) DeliveryGroupIdentity::forHistorical( [ 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 17 ], $context );
		$v2 += [ 'customer_context_version' => $context->contract_version, 'matching_location' => $context->matching_location?->toArray(), 'delivery_address' => $context->delivery_address?->toArray(), 'matching_identity' => $context->matching_identity, 'delivery_location_identity' => $context->delivery_location_identity, 'pickup_location_id' => null ];
		$package2 = $package1; $package2['snapshot_version'] = '2'; $package2['groups'][0]['group_id'] = $v2['delivery_group_id'];
		$one = $seed( $v1, '1', $package1 ); $two = $seed( $v2, '2', $package2 ); $bad = $seed( '{malformed-classic-history', '2', '{malformed-classic-package' );
		$before = $physical(); $facts = [ $history( $one ), $history( $two ), $history( $bad ) ];
		$classic_rows = $rows( "SELECT ID,post_type FROM `{$main->posts}` WHERE ID IN (" . implode( ',', array_map( 'intval', $orders ) ) . ') ORDER BY ID' );
		$cleanup_pressure();
		$after_facts = [ $history( $one ), $history( $two ), $history( $bad ) ];
		$preserved = $before === $physical() && $facts === $after_facts && 3 === count( $classic_rows ) && [ 'shop_order' ] === array_values( array_unique( array_column( $classic_rows, 'post_type' ) ) );
		foreach ( [ [ $facts[0], $v1, $package1, 'NATIVE-C06-CLASSIC-V1-HISTORY-CLEANUP-PRESERVED' ], [ $facts[1], $v2, $package2, 'NATIVE-C06-CLASSIC-V2-HISTORY-CLEANUP-PRESERVED' ] ] as [ $captured, $expected_line, $expected_package, $id ] ) {
			$plan = $captured[6][0] ?? [];
			$check( $id, $preserved && '' === $captured[0] && '' === $captured[2] && true === $captured[4] && is_array( $captured[1] ) && is_array( $captured[3] ) && $canonical( $expected_line ) === $canonical( $captured[1] ) && $canonical( $expected_package ) === $canonical( $captured[3] ) && '15.0000' === ( $plan['customer_paid_shipping_amount'] ?? null ) && 19 === ( $plan['destination_zone_id'] ?? null ) && 'Captured classic offer' === ( $plan['delivery_offer_public_label'] ?? null ), [ 'physical_meta_unchanged' => $before === $physical(), 'classic_crud' => true ] );
		}
		$check( 'NATIVE-C06-CLASSIC-MALFORMED-BYTES-NO-INFERENCE', $preserved && null === $facts[2][1] && null === $facts[2][3] && '' !== $facts[2][0] && '' !== $facts[2][2] && false === $facts[2][4], [ 'physical_meta_unchanged' => $before === $physical() ] );
	} finally {
		foreach ( $orders as $id ) { $order = new WC_Order( $id ); $order->delete( true ); }
		if ( $product_id > 0 ) { $product = new WC_Product_Simple( $product_id ); $product->delete( true ); }
		$all_posts = array_merge( $orders, $product_id > 0 ? [ $product_id ] : [] );
		$post_ids = implode( ',', array_map( 'intval', $all_posts ) ); $item_ids = implode( ',', array_map( 'intval', $items ) );
		$cleanup_ok = ( [] === $all_posts || ( [] === $rows( "SELECT ID FROM `{$main->posts}` WHERE ID IN ({$post_ids})" ) && [] === $rows( "SELECT meta_id FROM `{$main->postmeta}` WHERE post_id IN ({$post_ids})" ) ) ) && ( [] === $items || [] === $rows( "SELECT meta_id FROM `{$main->prefix}woocommerce_order_itemmeta` WHERE order_item_id IN ({$item_ids})" ) );
		remove_filter( 'woocommerce_order_data_store', $select_classic, PHP_INT_MAX );
		$check( 'NATIVE-C06-CLASSIC-FIXTURE-PHYSICAL-CLEANUP', $cleanup_ok && $GLOBALS['wpdb'] === $main && ! has_filter( 'woocommerce_order_data_store', $select_classic ) );
	}
};
