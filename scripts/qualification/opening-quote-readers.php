<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotProjection;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotReadiness;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;

/** Real persisted reader proof. Seeded captured-shaped facts are not quote issue or placement evidence. */
return static function ( callable $check ): void {
	global $wpdb;
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN
		|| '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! defined( 'DB_HOST' )
		|| 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' )
		|| 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $wpdb instanceof wpdb ) {
		throw new RuntimeException( 'Quote reader proof requires the marked native disposable fixture.' );
	}
	foreach ( get_included_files() as $file ) { if ( str_ends_with( str_replace( '\\', '/', $file ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'Unit bootstrap is forbidden in native quote reader proof.' ); } }
	require_once dirname( __DIR__, 2 ) . '/tests/Support/DeliveryQuote/QuoteFixtures.php';
	$db = $wpdb; $orders = []; $items = []; $products = []; $original_error = null;
	$reader = new OrderDeliverySnapshotReader(); $factory = new HistoricalOrderShipmentContextFactory( $reader ); $planner = new HistoricalShipmentPlanner();
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$meta_table = $hpos ? $db->prefix . 'wc_orders_meta' : $db->postmeta; $owner_column = $hpos ? 'order_id' : 'post_id'; $meta_id = $hpos ? 'id' : 'meta_id';
	$item_meta = $db->prefix . 'woocommerce_order_itemmeta'; $item_table = $db->prefix . 'woocommerce_order_items';
	$rows = static function ( string $sql ) use ( $db ): array { $value = $db->get_results( $sql, ARRAY_A ); if ( ! is_array( $value ) || '' !== $db->last_error ) { throw new RuntimeException( 'Quote reader physical read failed.' ); } return $value; };
	$encode = static fn( array $value ): string => json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$currency_before = $rows( $db->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$db->options}` WHERE option_name=%s", 'woocommerce_currency' ) );
	$physical = static function () use ( &$orders, &$items, $rows, $meta_table, $owner_column, $meta_id, $item_meta ): array {
		if ( [] === $orders ) { return [ [], [] ]; } $order_ids = implode( ',', array_map( 'intval', $orders ) ); $item_ids = implode( ',', array_map( 'intval', $items ) );
		return [ $rows( "SELECT `{$meta_id}`,`{$owner_column}`,meta_key,meta_value FROM `{$meta_table}` WHERE `{$owner_column}` IN ({$order_ids}) ORDER BY `{$meta_id}`" ), [] === $items ? [] : $rows( "SELECT meta_id,order_item_id,meta_key,meta_value FROM `{$item_meta}` WHERE order_item_id IN ({$item_ids}) ORDER BY meta_id" ) ];
	};
	$seed = static function ( array|string $line, array|string $package, bool $marker_exists = false, mixed $marker = '1' ) use ( &$orders, &$items, $encode ): array {
		$order = wc_create_order(); if ( ! $order instanceof WC_Order || $order->get_id() < 1 ) { throw new RuntimeException( 'Quote reader order fixture failed.' ); } $orders[] = (int) $order->get_id();
		$order->set_currency( 'GHS' ); $order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, is_array( $package ) ? $encode( $package ) : $package );
		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, is_array( $package ) ? $package['snapshot_version'] : '1' );
		$order->update_meta_data( '_w2q05_reader_foreign', 'PRIVATE-READER-FOREIGN-SENTINEL' );
		$item = new WC_Order_Item_Product(); $item->set_name( 'Seeded historical reader item' ); $item->set_product_id( is_array( $line ) ? $line['product_id'] : 1 ); $item->set_quantity( 2 ); $item->set_subtotal( '20' ); $item->set_total( '20' );
		$item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, is_array( $line ) ? $encode( $line ) : $line, true ); $item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, is_array( $line ) ? $line['snapshot_version'] : '1', true );
		$item->add_meta_data( '_w2q05_reader_foreign', 'PRIVATE-READER-LINE-SENTINEL', true );
		if ( $marker_exists ) { $order->add_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, $marker, true ); $item->add_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, $marker, true ); }
		$order->add_item( $item );
		if ( is_array( $line ) ) { $shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'delivery_engine_selected_offer' ); $shipping->set_method_title( 'Captured historical delivery' ); $shipping->set_total( '12.50' ); $shipping->set_taxes( [ 'total' => [] ] ); $shipping->add_meta_data( 'cetech_de_group_id', $line['delivery_group_id'], true ); $order->add_item( $shipping ); }
		$order->save(); foreach ( $order->get_items( [ 'line_item', 'shipping' ] ) as $saved ) { $items[] = (int) $saved->get_id(); }
		if ( $item->get_id() < 1 ) { throw new RuntimeException( 'Quote reader line fixture failed.' ); }
		return [ 'order' => (int) $order->get_id(), 'item' => (int) $item->get_id() ];
	};
	$fresh = static function ( array $fixture ): array { $order = new WC_Order( $fixture['order'] ); $order->read_meta_data( true ); $item = new WC_Order_Item_Product( $fixture['item'] ); $item->read_meta_data( true ); return [ $order, $item ]; };
	$read = static function ( array $fixture ) use ( $fresh, $reader, $factory, $planner ): array { [ $order, $item ] = $fresh( $fixture ); return [ $reader->read_line( $item ), $reader->read_package( $order ), $planner->plan( $factory->from_order( $order ) ), $order, $item ]; };
	$assert_read = static function ( string $id, array $fixture, callable $predicate ) use ( $read, $physical, $check ): void { $before = $physical(); $warnings = 0; set_error_handler( static function () use ( &$warnings ): bool { ++$warnings; return true; } ); try { $value = $read( $fixture ); } finally { restore_error_handler(); } $unchanged = $before === $physical(); $check( $id, $predicate( $value ) && $unchanged && 0 === $warnings, [ 'physical_meta_unchanged' => $unchanged, 'reader_warning_count' => $warnings ] ); };
	try {
		$check( 'NATIVE-W2Q05-READER-ENVIRONMENT', $hpos && DeliveryQuoteSnapshotReadiness::supports( 1, [ 'code' => 'legacy_fixed_base_v1', 'version' => 1 ] ) && DeliveryQuoteSnapshotReadiness::contract()['reader_only'], [ 'hpos' => $hpos, 'proof' => 'seeded captured-shaped history; native CRUD; no issue or placement claim' ] );
		$product = new WC_Product_Simple(); $product->set_name( 'Q05 reader owned product' ); $product->set_status( 'publish' ); $product_id = (int) $product->save(); if ( $product_id < 1 ) { throw new RuntimeException( 'Quote reader product fixture failed.' ); } $products[] = $product_id;
		$line = [ 'contract_version' => '1', 'snapshot_version' => '1', 'product_id' => $product_id, 'variation_id' => null, 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 17, 'delivery_offer_public_label' => 'Captured historical delivery', 'delivery_offer_public_description' => 'Saved historical description', 'estimate_text' => 'Captured three days', 'rule_id' => 111, 'destination_zone_id' => 19, 'quantity' => 2, 'currency_code' => 'GHS', 'quoted_amount' => '12.5000', 'quote_status' => 'quoted', 'rate_card_id' => 29, 'rate_card_code' => 'PRIVATE-Q05-RATE', 'snapshotted_at' => '2026-10-07T05:00:10+00:00', 'delivery_group_id' => 'in_warehouse|delivery|17' ];
		$package = [ 'snapshot_version' => '1', 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Captured historical delivery', 'package_total_delivery_amount' => '12.5000', 'currency_code' => 'GHS', 'destination_zone_id' => 19, 'quote_status' => 'success', 'snapshotted_at' => '2026-10-07T05:00:10+00:00', 'groups' => [ [ 'group_id' => $line['delivery_group_id'], 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Captured historical delivery', 'package_total_delivery_amount' => '12.5000', 'fulfilment_choice' => 'delivery', 'is_pickup' => false, 'display_index' => 1 ] ] ];
		$legacy = $seed( $line, $package );
		$assert_read( 'NATIVE-W2Q05-READER-LEGACY-V1-BYTES', $legacy, static fn( array $v ): bool => '' === $v[0]->error && '' === $v[1]->error && null === $v[0]->delivery_quote && null === $v[1]->delivery_quote && $v[2]->ok && '12.5000' === $v[2]->plans[0]->customer_paid_shipping_amount );
		$address = DeliveryAddress::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Synthetic', 'postcode' => '00001', 'address_1' => 'PRIVATE-Q05-ADDRESS', 'address_2' => '', 'first_name' => 'Synthetic', 'last_name' => 'Reader', 'company' => '', 'phone' => '0000000000' ] ); $ctx = CustomerCartContext::delivery( 17, $address->matching, $address );
		$v2 = array_merge( $line, [ 'snapshot_version' => '2', 'customer_context_version' => 1, 'matching_location' => $ctx->matching_location?->toArray(), 'delivery_address' => $ctx->delivery_address?->toArray(), 'matching_identity' => $ctx->matching_identity, 'delivery_location_identity' => $ctx->delivery_location_identity, 'pickup_location_id' => null ] );
		$v2['extensions'] = [ 'quote_policy' => [ 'version' => 1, 'required' => false, 'data' => [ 'reference' => [ 'id' => 'PRIVATE-Q05-C05-REFERENCE', 'version' => 'captured-v1', 'content_hash' => hash( 'sha256', 'C05-reference' ) ], 'currency_code' => 'GHS', 'amount' => '12.5000', 'customer_text' => 'Unrelated captured optional policy' ] ] ];
		$v2package = $package; $v2package['snapshot_version'] = '2'; $legacy2 = $seed( $v2, $v2package );
		$assert_read( 'NATIVE-W2Q05-READER-LEGACY-V2-C05-BYTES', $legacy2, static fn( array $v ): bool => '' === $v[0]->error && '' === $v[1]->error && null === $v[0]->delivery_quote && $v[2]->ok && 'PRIVATE-Q05-ADDRESS' === $v[0]->snapshot?->delivery_address['address_1'] && 'recorded' === $v[0]->extensions?->get( 'quote_policy' )->status );
		$context_groups = QuoteFixtures::context()->private_facts()['groups']; $context_groups[0]['service_id'] = $context_groups[0]['offer_id']; $quote_context = QuoteFixtures::context( [ 'groups' => $context_groups ] );
		$terms_data = QuoteFixtures::terms()->private_facts(); $terms_data['groups'][0]['provider'] = DeliveryQuoteSnapshotEnvelope::PROFILE; $terms_data['groups'][0]['promotion']['provider'] = [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ]; $terms = QuoteTerms::from_array( $terms_data );
		$id = QuoteId::generate(); $header = QuoteHeader::issue( $id, QuoteFixtures::owner(), $quote_context, $terms, QuoteFixtures::time(), [ 'issue' => hash( 'sha256', 'issue:' . $id->value() ), 'accept' => hash( 'sha256', 'accept:' . $id->value() ), 'invalidate' => hash( 'sha256', 'invalidate:' . $id->value() ) ], 'legacy_fixed_base_v1', 1, QuoteFixtures::reference( $id ) );
		$before_builder = $physical(); $query_count = $db->num_queries; $packet = DeliveryQuoteSnapshotEnvelope::from_captured( $header, $quote_context, $terms, QuoteFixtures::time()->plus_seconds( 10 ), QuoteId::generate(), hash( 'sha256', 'seeded-captured-order-context' ) ); $builder_queries = $db->num_queries - $query_count;
		$check( 'NATIVE-W2Q05-READER-PURE-PACKET-BUILDER-NO-ORDER-WRITE', 0 === $builder_queries && $before_builder === $physical(), [ 'native_builder_query_count' => $builder_queries, 'proof' => 'pure typed seeded-shape packet construction, not quote issue' ] );
		$packet_data = $packet->private_facts(); $known_line = $line + [ 'delivery_quote' => $packet_data ]; $known_package = $package + [ 'delivery_quote' => $packet_data ]; $known = $seed( $known_line, $known_package, true );
		$assert_read( 'NATIVE-W2Q05-READER-KNOWN-QUOTE', $known, static fn( array $v ): bool => '' === $v[0]->error && '' === $v[1]->error && 'recorded' === $v[0]->delivery_quote?->status && $v[0]->delivery_quote?->envelope?->matches( $v[1]->delivery_quote?->envelope ) && $v[2]->ok );
		update_option( 'woocommerce_currency', 'USD' );
		$assert_read( 'NATIVE-W2Q05-READER-HISTORICAL-CURRENCY-CAPTURED', $known, static fn( array $v ): bool => 'USD' === get_option( 'woocommerce_currency' ) && 'GHS' === $v[0]->snapshot?->currency_code && 'GHS' === $v[0]->delivery_quote?->envelope?->private_facts()['money_receipt']['groups'][0]['total']['currency'] && '12.5000' === $v[2]->plans[0]->customer_paid_shipping_amount );
		$assert_read( 'NATIVE-W2Q05-READER-SAFE-PROJECTIONS', $known, static function ( array $v ) use ( $packet_data, $encode ): bool { $r = $v[0]->delivery_quote; if ( null === $r ) { return false; } $customer = DeliveryQuoteSnapshotProjection::for_customer( $r ); $diagnostic = DeliveryQuoteSnapshotProjection::diagnostic( $r ); $json = $encode( [ $customer, $diagnostic ] ); foreach ( [ $packet_data['quote_id'], $packet_data['placement_id'], $packet_data['body_digest'], $packet_data['material_digest'], $packet_data['context_digest'], $packet_data['money_receipt']['groups'][0]['component_key'], 'legacy_fixed_base_v1', 'native_no_delivery_promotion_v1', 'rates', 'tax_class', 'cost_provider_unavailable', 'PRIVATE-Q05-' ] as $private ) { if ( str_contains( $json, $private ) ) { return false; } } return [ 'status', 'groups' ] === array_keys( $customer ) && [ 'status', 'format_supported' ] === array_keys( $diagnostic ) && '12.50' === $customer['groups'][0]['display_total']['amount']; } );
		$assert_read( 'NATIVE-W2Q05-READER-PRIVATE-SERIALIZATION', $known, static function ( array $v ): bool { foreach ( [ $v[0]->delivery_quote, $v[0]->delivery_quote?->envelope ] as $private ) { if ( null === $private ) { return false; } foreach ( [ 'json', 'php' ] as $format ) { $refused = false; try { 'json' === $format ? json_encode( $private, JSON_THROW_ON_ERROR ) : serialize( $private ); } catch ( LogicException ) { $refused = true; } if ( ! $refused ) { return false; } } } return true; } );
		$refused = static fn( array $v ): bool => 'version_mismatch' === $v[0]->error && null === $v[0]->snapshot && 'version_mismatch' === $v[1]->error && null === $v[1]->snapshot && ! $v[2]->ok;
		$assert_read( 'NATIVE-W2Q05-READER-MARKER-MISSING-ENVELOPE', $seed( $line, $package, true ), $refused );
		// Native Woo CRUD uses null to delete metadata. Persist a serialized-null
		// value only in these tracked physical rows, then force actual CRUD reload.
		$null_marker = $seed( $known_line, $known_package, true );
		if ( 1 !== $db->update( $meta_table, [ 'meta_value' => 'N;' ], [ $owner_column => $null_marker['order'], 'meta_key' => DeliveryQuoteSnapshotEnvelope::META_FORMAT ] )
			|| 1 !== $db->update( $item_meta, [ 'meta_value' => 'N;' ], [ 'order_item_id' => $null_marker['item'], 'meta_key' => DeliveryQuoteSnapshotEnvelope::META_FORMAT ] ) ) { throw new RuntimeException( 'Persisted null marker fixture failed.' ); }
		$assert_read( 'NATIVE-W2Q05-READER-NULL-MARKER', $null_marker, $refused );
		$assert_read( 'NATIVE-W2Q05-READER-EMPTY-MARKER', $seed( $known_line, $known_package, true, '' ), $refused );
		$unknown = $packet_data; $unknown['format'] = 99;
		$assert_read( 'NATIVE-W2Q05-READER-UNKNOWN-FORMAT', $seed( $line + [ 'delivery_quote' => $unknown ], $package + [ 'delivery_quote' => $unknown ] ), $refused );
		$assert_read( 'NATIVE-W2Q05-READER-NULL-ENVELOPE', $seed( $line + [ 'delivery_quote' => null ], $package + [ 'delivery_quote' => null ] ), $refused );
		$assert_read( 'NATIVE-W2Q05-READER-LIST-ENVELOPE', $seed( $line + [ 'delivery_quote' => [] ], $package + [ 'delivery_quote' => [] ] ), $refused );
		$assert_read( 'NATIVE-W2Q05-READER-EMPTY-RATES-OBJECT', $seed( str_replace( '"rates":[]', '"rates":{}', $encode( $known_line ) ), str_replace( '"rates":[]', '"rates":{}', $encode( $known_package ) ) ), $refused );
		$invalid_receipt = $packet_data; $invalid_receipt['provenance_receipt']['groups'][0]['native_money_receipt']['source'] = 'future_required_source';
		$assert_read( 'NATIVE-W2Q05-READER-UNKNOWN-REQUIRED-RECEIPT', $seed( $line + [ 'delivery_quote' => $invalid_receipt ], $package + [ 'delivery_quote' => $invalid_receipt ] ), $refused );
		$different = $packet_data; $different['placement_id'] = QuoteId::generate()->value(); $mixed = $seed( $line + [ 'delivery_quote' => $different ], $known_package, true );
		$assert_read( 'NATIVE-W2Q05-READER-MIXED-LINE-ORDER-PACKET', $mixed, static function ( array $v ) use ( $reader ): bool { return '' === $v[0]->error && '' === $v[1]->error && ! $v[2]->ok && null === ( new CustomerOrderDeliverySummaryBuilder( $reader, new OrderDeliverySnapshotIntegrity() ) )->build( $v[3] ); } );
		$nested = $packet_data; $nested['money_receipt']['groups'][0]['renamed_private_context'] = [ 'nested' => [ 'PRIVATE-Q05-ADDRESS' ] ];
		$assert_read( 'NATIVE-W2Q05-READER-PRIVATE-NESTED-UNKNOWN', $seed( $line + [ 'delivery_quote' => $nested ], $package + [ 'delivery_quote' => $nested ] ), $refused );
		$assert_read( 'NATIVE-W2Q05-READER-MEMBER-WITHOUT-MARKER-STAYS-QUOTE-OWNED', $seed( $known_line, $known_package ), static fn( array $v ): bool => ! $v[3]->meta_exists( DeliveryQuoteSnapshotEnvelope::META_FORMAT ) && ! $v[4]->meta_exists( DeliveryQuoteSnapshotEnvelope::META_FORMAT ) && 'recorded' === $v[0]->delivery_quote?->status && 'recorded' === $v[1]->delivery_quote?->status && $v[2]->ok );
		$all_before = $physical(); foreach ( $orders as $order_id ) { $order = new WC_Order( $order_id ); $order->read_meta_data( true ); $reader->read_package( $order ); foreach ( $order->get_items() as $item ) { $reader->read_line( $item ); } }
		$check( 'NATIVE-W2Q05-READER-ALL-READS-BYTES-UNCHANGED', $all_before === $physical(), [ 'order_count' => count( $orders ), 'item_count' => count( $items ), 'protected_meta_unchanged' => $all_before === $physical() ] );
	} catch ( Throwable $error ) { $original_error = $error; throw $error; }
	finally {
		$cleanup_error = null; $cleanup_recorded = false;
		try {
			foreach ( array_reverse( $orders ) as $order_id ) { $order = new WC_Order( $order_id ); $order->delete( true ); }
			foreach ( array_reverse( $products ) as $product_id ) { $product = wc_get_product( $product_id ); if ( $product instanceof WC_Product ) { $product->delete( true ); } clean_post_cache( $product_id ); wp_cache_delete( 'product-' . $product_id, 'products' ); }
			$db->delete( $db->options, [ 'option_name' => 'woocommerce_currency' ] ); foreach ( $currency_before as $option ) { if ( false === $db->insert( $db->options, $option ) ) { throw new RuntimeException( 'Quote reader currency restoration failed.' ); } }
			foreach ( [ 'woocommerce_currency', 'alloptions', 'notoptions' ] as $key ) { wp_cache_delete( $key, 'options' ); }
			$order_ids = [] === $orders ? '0' : implode( ',', array_map( 'intval', $orders ) ); $product_ids = [] === $products ? '0' : implode( ',', array_map( 'intval', $products ) ); $item_ids = [] === $items ? '0' : implode( ',', array_map( 'intval', $items ) );
			$meta_removed = [ [], [] ] === $physical(); $items_removed = [] === $rows( "SELECT order_item_id FROM `{$item_table}` WHERE order_item_id IN ({$item_ids})" );
			$order_table = $hpos ? $db->prefix . 'wc_orders' : $db->posts; $order_pk = $hpos ? 'id' : 'ID'; $orders_removed = [] === $rows( "SELECT `{$order_pk}` FROM `{$order_table}` WHERE `{$order_pk}` IN ({$order_ids})" );
			$products_removed = [] === $rows( "SELECT ID FROM `{$db->posts}` WHERE ID IN ({$product_ids})" ) && [] === $rows( "SELECT meta_id FROM `{$db->postmeta}` WHERE post_id IN ({$product_ids})" );
			$currency_restored = $currency_before === $rows( $db->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$db->options}` WHERE option_name=%s", 'woocommerce_currency' ) );
			$cleanup_recorded = true;
			$check( 'NATIVE-W2Q05-READER-CLEANUP', $orders_removed && $items_removed && $meta_removed && $products_removed && $currency_restored, [ 'orders_removed' => $orders_removed, 'items_removed' => $items_removed, 'physical_meta_removed' => $meta_removed, 'products_removed' => $products_removed, 'currency_option_restored' => $currency_restored ] );
		} catch ( Throwable $error ) {
			$cleanup_error = $error;
			if ( ! $cleanup_recorded ) { try { $check( 'NATIVE-W2Q05-READER-CLEANUP', false, [ 'cleanup_completed' => false, 'cleanup_error_class' => $error instanceof RuntimeException ? 'RuntimeException' : 'Error' ] ); } catch ( Throwable ) { /* Preserve the original fixture failure after recording cleanup failure. */ } }
		}
		if ( null !== $cleanup_error && null === $original_error ) { throw $cleanup_error; }
	}
};
