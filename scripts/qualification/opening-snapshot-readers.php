<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageReadResult;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase;

/**
 * C05 T12-T14, real Woo CRUD and physical protected-meta readbacks.
 * These are deliberately seeded historical fixtures, not checkout-placement or
 * business policy writer evidence. No unit bootstrap, snapshot repair or backfill.
 * Private JSON/address/policy facts are compared only in-process.
 */
return static function ( callable $check ): void {
	global $wpdb;
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' )
		|| ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN
		|| '1' !== (string) get_option( 'cetech_opening_qualification_disposable' )
		|| ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST )
		|| ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME )
		|| ! $wpdb instanceof wpdb || ! class_exists( WC_Order::class )
	) { throw new RuntimeException( 'Snapshot proof requires the marked native disposable fixture.' ); }
	foreach ( get_included_files() as $included ) {
		if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) {
			throw new RuntimeException( 'Unit bootstrap is forbidden in native snapshot qualification.' );
		}
	}
	$main_db = $wpdb;
	$root = dirname( __DIR__, 2 );
	$old_user = get_current_user_id();
	$orders = [];
	$products = [];
	$items = [];
	$config_rows = [];
	$isolated = null;
	$migration_tables = [];
	$fixture_prefix = null;
	$reader = new OrderDeliverySnapshotReader();
	$factory = new HistoricalOrderShipmentContextFactory( $reader );
	$planner = new HistoricalShipmentPlanner();
	$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	$order_meta_table = $hpos ? $main_db->prefix . 'wc_orders_meta' : $main_db->postmeta;
	$order_id_column = $hpos ? 'order_id' : 'post_id';
	$order_meta_pk = $hpos ? 'id' : 'meta_id';
	$item_meta_table = $main_db->prefix . 'woocommerce_order_itemmeta';
	$sql_rows = static function ( string $sql ) use ( $main_db ): array {
		$rows = $main_db->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $main_db->last_error ) { throw new RuntimeException( 'Snapshot fixture readback failed.' ); }
		return $rows;
	};
	$options_before = $sql_rows( $main_db->prepare( "SELECT option_name,option_value,autoload FROM `{$main_db->options}` WHERE option_name IN (%s,%s) ORDER BY option_name", 'cetech_de_db_version', 'cetech_de_last_migration_status' ) );
	$physical = static function () use ( &$orders, &$items, $sql_rows, $main_db, $order_meta_table, $order_id_column, $order_meta_pk, $item_meta_table ): array {
		$order_rows = [];
		$item_rows = [];
		if ( [] !== $orders ) {
			$ids = implode( ',', array_map( 'intval', $orders ) );
			$order_rows = $sql_rows( "SELECT `{$order_meta_pk}`,`{$order_id_column}`,meta_key,meta_value FROM `{$order_meta_table}` WHERE `{$order_id_column}` IN ({$ids}) AND meta_key IN ('_cetech_de_delivery_quote_snapshot','_cetech_de_order_delivery_snapshot_version','_c05_foreign_sentinel') ORDER BY `{$order_meta_pk}`" );
		}
		if ( [] !== $items ) {
			$ids = implode( ',', array_map( 'intval', $items ) );
			$item_rows = $sql_rows( "SELECT meta_id,order_item_id,meta_key,meta_value FROM `{$item_meta_table}` WHERE order_item_id IN ({$ids}) AND meta_key IN ('_cetech_de_delivery_snapshot','_cetech_de_delivery_snapshot_version','cetech_de_group_id','_c05_foreign_sentinel') ORDER BY meta_id" );
		}
		return [ $order_rows, $item_rows ];
	};
	$encode = static fn ( array $data ): string => json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	$canonical_facts = static function ( array $facts ) use ( &$canonical_facts ): array { if ( ! array_is_list( $facts ) ) { ksort( $facts, SORT_STRING ); } foreach ( $facts as $key => $value ) { if ( is_array( $value ) ) { $facts[ $key ] = $canonical_facts( $value ); } } return $facts; };
	$same_facts = static fn ( array $expected, ?array $actual ): bool => null !== $actual && $canonical_facts( $expected ) === $canonical_facts( $actual );
	$seed = static function ( array|string|null $line, mixed $version, array|string|null $package, mixed $package_version, int $product, ?int $variation = null, ?array $pickup = null ) use ( &$orders, &$items, $encode ): array {
		$order = wc_create_order();
		if ( ! $order instanceof WC_Order || $order->get_id() < 1 ) { throw new RuntimeException( 'Snapshot fixture order creation failed.' ); }
		$orders[] = (int) $order->get_id();
		$order->set_currency( 'GHS' );
		$order->update_meta_data( '_c05_foreign_sentinel', 'PRIVATE-C05-ORDER-SENTINEL' );
		if ( null !== $package ) { $order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, is_array( $package ) ? $encode( $package ) : $package ); }
		if ( null !== $package_version ) { $order->update_meta_data( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, $package_version ); }
		$item = new WC_Order_Item_Product();
		$item->set_product_id( $product );
		if ( null !== $variation ) { $item->set_variation_id( $variation ); }
		$item->set_name( 'Historical synthetic C05 item' );
		$item->set_quantity( 2 );
		$item->set_subtotal( '20.00' );
		$item->set_total( '20.00' );
		$item->add_meta_data( '_c05_foreign_sentinel', 'PRIVATE-C05-LINE-SENTINEL', true );
		if ( null !== $line ) { $item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, is_array( $line ) ? $encode( $line ) : $line, true ); }
		if ( null !== $version ) { $item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, $version, true ); }
		$order->add_item( $item );
		if ( is_array( $line ) && is_string( $line['delivery_group_id'] ?? null ) ) {
			$shipping = new WC_Order_Item_Shipping();
			$shipping->set_method_id( 'delivery_engine_selected_offer' );
			$shipping->set_method_title( 'Captured delivery service' );
			$shipping->set_total( '15.00' );
			$shipping->add_meta_data( 'cetech_de_group_id', $line['delivery_group_id'], true );
			$order->add_item( $shipping );
		}
		if ( null !== $pickup ) {
			$pickup_item = new WC_Order_Item_Product();
			$pickup_item->set_product_id( $product ); $pickup_item->set_name( 'Historical synthetic pickup item' ); $pickup_item->set_quantity( 1 );
			$pickup_item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $encode( $pickup ), true );
			$pickup_item->add_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, '2', true );
			$order->add_item( $pickup_item );
		}
		$order->save();
		foreach ( $order->get_items( [ 'line_item', 'shipping' ] ) as $saved ) { $items[] = (int) $saved->get_id(); }
		if ( $item->get_id() < 1 ) { throw new RuntimeException( 'Snapshot fixture item was not persisted.' ); }
		return [ 'order' => (int) $order->get_id(), 'line' => (int) $item->get_id() ];
	};
	$fresh = static function ( array $fixture ): array {
		$order = new WC_Order( $fixture['order'] ); $order->read_meta_data( true );
		$item = new WC_Order_Item_Product( $fixture['line'] ); $item->read_meta_data( true );
		return [ $order, $item ];
	};
	$historical = static function ( array $fixture ) use ( $fresh, $reader, $factory, $planner ): array {
		[ $order, $item ] = $fresh( $fixture );
		$line = $reader->read_line( $item ); $package = $reader->read_package( $order );
		$context = $factory->from_order( $order ); $plan = $planner->plan( $context );
		return [ $line->error, $line->snapshot?->toArray(), $package->error, $package->snapshot?->toArray(), $plan->ok, $plan->pickup_groups_skipped, $plan->error_code?->value, array_map( static function ( $row ): array { $facts = get_object_vars( $row ); $facts['items'] = array_map( 'get_object_vars', $row->items ); return $facts; }, $plan->plans ), array_map( static fn ( $row ): ?array => $row->snapshot?->toArray(), $context->lines ) ];
	};
	$assert_unchanged = static function ( string $id, callable $read, callable $predicate ) use ( $physical, $check ): void {
		$before = $physical(); $warnings = 0;
		set_error_handler( static function () use ( &$warnings ): bool { ++$warnings; return true; } );
		try { $result = $read(); } finally { restore_error_handler(); }
		$after = $physical();
		$check( $id, $predicate( $result ) && 0 === $warnings && $before === $after, [ 'protected_meta_rows_unchanged' => $before === $after, 'reader_warning_count' => $warnings ] );
	};
	$service = Plugin::instance()->container()->get( ScopedConfigurationAdminService::class );
	$repository = Plugin::instance()->container()->get( ScopedConfigurationRepositoryInterface::class );
	$config_product = 0;
	$cleanup_ok = false;
	try {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( [] === $admins ) { throw new RuntimeException( 'Snapshot fixture requires a native administrator.' ); }
		wp_set_current_user( 0 ); wp_set_current_user( (int) $admins[0] );
		$check( 'NATIVE-C05-NATIVE-CRUD-DISPOSABLE-ENVIRONMENT', $hpos && $service instanceof ScopedConfigurationAdminService && current_user_can( 'manage_options' ), [ 'hpos' => $hpos, 'schema' => (string) get_option( 'cetech_de_db_version' ), 'proof' => 'seeded protected meta, native CRUD and actual physical readbacks' ] );
		$simple = new WC_Product_Simple(); $simple->set_name( 'C05 synthetic historical product' ); $simple->set_status( 'publish' ); $config_product = $simple->save(); $products[] = $config_product;
		$parent = new WC_Product_Variable(); $parent->set_name( 'C05 synthetic variable parent' ); $parent->set_status( 'publish' ); $parent_id = $parent->save(); $products[] = $parent_id;
		$variation = new WC_Product_Variation(); $variation->set_parent_id( $parent_id ); $variation->set_status( 'publish' ); $variation_id = $variation->save(); $products[] = $variation_id;
		if ( min( $config_product, $parent_id, $variation_id ) < 1 ) { throw new RuntimeException( 'Snapshot fixture product creation failed.' ); }
		$v1 = [ 'contract_version' => '1', 'snapshot_version' => '1', 'product_id' => $config_product, 'variation_id' => null, 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 17, 'delivery_offer_public_label' => 'Captured synthetic offer', 'delivery_offer_public_description' => 'Captured synthetic description', 'estimate_text' => 'Captured three days', 'rule_id' => 111, 'destination_zone_id' => 19, 'quantity' => 2, 'currency_code' => 'GHS', 'quoted_amount' => '7.5000', 'quote_status' => 'quoted', 'rate_card_id' => 29, 'rate_card_code' => 'CAPTURED-C05', 'snapshotted_at' => '2026-09-01T10:00:00+00:00', 'delivery_group_id' => 'in_warehouse|delivery|17' ];
		$package1 = [ 'snapshot_version' => '1', 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Captured delivery service', 'package_total_delivery_amount' => '15.0000', 'currency_code' => 'GHS', 'destination_zone_id' => 19, 'quote_status' => 'success', 'snapshotted_at' => '2026-09-01T10:00:00+00:00', 'groups' => [ [ 'group_id' => $v1['delivery_group_id'], 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => 'Captured delivery service', 'package_total_delivery_amount' => '15.0000', 'fulfilment_choice' => 'delivery', 'is_pickup' => false, 'display_index' => 1 ] ] ];
		$address = DeliveryAddress::fromInput( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Synthetic fixture city', 'postcode' => '00001', 'address_1' => 'PRIVATE-C05-SYNTHETIC-DESTINATION', 'address_2' => '', 'first_name' => 'Synthetic', 'last_name' => 'Fixture', 'company' => '', 'phone' => '0000000000' ] );
		$customer_context = CustomerCartContext::delivery( 17, $address->matching, $address );
		$v2 = $v1; $v2['snapshot_version'] = '2'; $v2['product_id'] = $parent_id; $v2['variation_id'] = $variation_id; $v2['delivery_group_id'] = (string) DeliveryGroupIdentity::forHistorical( [ 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 17 ], $customer_context );
		$v2 += [ 'customer_context_version' => $customer_context->contract_version, 'matching_location' => $customer_context->matching_location?->toArray(), 'delivery_address' => $customer_context->delivery_address?->toArray(), 'matching_identity' => $customer_context->matching_identity, 'delivery_location_identity' => $customer_context->delivery_location_identity, 'pickup_location_id' => null ];
		$pickup_context = CustomerCartContext::pickup( 23 );
		$pickup = $v2; $pickup['product_id'] = $config_product; $pickup['variation_id'] = null; $pickup['fulfilment_availability'] = 'in_store'; $pickup['fulfilment_choice'] = 'store_pickup'; $pickup['delivery_offer_id'] = null; $pickup['quantity'] = 1; $pickup['quote_status'] = 'selection_only'; $pickup['quoted_amount'] = null; $pickup['delivery_group_id'] = (string) DeliveryGroupIdentity::forHistorical( [ 'fulfilment_availability' => 'in_store', 'fulfilment_choice' => 'store_pickup' ], $pickup_context ); $pickup['pickup_location_id'] = $pickup_context->pickup_location_id; $pickup['pickup_location_label'] = 'Captured pickup label'; $pickup['pickup_address'] = 'PRIVATE-C05-SYNTHETIC-PICKUP'; $pickup['pickup_instructions'] = 'Captured pickup instruction'; $pickup['matching_location'] = null; $pickup['delivery_address'] = null; $pickup['matching_identity'] = null; $pickup['delivery_location_identity'] = null;
		$package2 = $package1; $package2['snapshot_version'] = '2'; $package2['groups'][0]['group_id'] = $v2['delivery_group_id']; $package2['groups'][] = [ 'group_id' => $pickup['delivery_group_id'], 'shipping_method_id' => null, 'shipping_method_label' => 'Captured pickup', 'package_total_delivery_amount' => null, 'fulfilment_choice' => 'store_pickup', 'is_pickup' => true, 'display_index' => 0 ];
		$base1 = $seed( $v1, '1', $package1, '1', $config_product );
		$base2 = $seed( $v2, '2', $package2, '2', $parent_id, $variation_id, $pickup );
		$base_rows = $physical(); $base_facts = [ $historical( $base1 ), $historical( $base2 ) ];
		foreach ( [ [ $base1, $v1, $package1, 0 ], [ $base2, $v2, $package2, 1 ] ] as $index => [ $fixture, $line_data, $package_data, $pickup_count ] ) {
			$facts = $historical( $fixture ); $plan = $facts[7][0] ?? [];
			$pickup_ok = 0 === $pickup_count || ( 23 === ( $facts[8][1]['pickup_location_id'] ?? null ) && 'Captured pickup label' === ( $facts[8][1]['pickup_location_label'] ?? null ) && 'PRIVATE-C05-SYNTHETIC-PICKUP' === ( $facts[8][1]['pickup_address'] ?? null ) && 'Captured pickup instruction' === ( $facts[8][1]['pickup_instructions'] ?? null ) );
			$line_and_package_match = $same_facts( $line_data, $facts[1] ) && $same_facts( $package_data, $facts[3] );
			$check( 'NATIVE-C05-V' . ( $index + 1 ) . '-PERSISTED-HISTORICAL-READ', '' === $facts[0] && '' === $facts[2] && $line_and_package_match && true === $facts[4] && $pickup_count === $facts[5] && $pickup_ok && 1 === count( $facts[7] ) && '15.0000' === ( $plan['customer_paid_shipping_amount'] ?? null ) && 19 === ( $plan['destination_zone_id'] ?? null ) && 'Captured synthetic offer' === ( $plan['delivery_offer_public_label'] ?? null ) && 'CAPTURED-C05' === ( $plan['rate_card_code'] ?? null ), [ 'line_and_package_facts_match' => $line_and_package_match, 'historical_planner_ok' => $facts[4], 'pickup_groups_skipped' => $facts[5], 'captured_pickup_preserved' => $pickup_ok ] );
		}
		$check( 'NATIVE-C05-READS-PRESERVE-PHYSICAL-META-BYTES', $base_rows === $physical(), [ 'order_meta_rows' => count( $base_rows[0] ), 'item_meta_rows' => count( $base_rows[1] ), 'protected_meta_sha256' => hash( 'sha256', $encode( $base_rows ) ) ] );
		$created = $service->save( new ScopedConfigurationWriteCommand( ConfigurationScopeType::Product, $config_product, 'in_warehouse', null, [ ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '9' ] ], true, 0, 'c05-create-' . bin2hex( random_bytes( 8 ) ), 0 ) );
		$opened = $repository->findByScopeAndSlice( ConfigurationScopeType::Product, $config_product, 'in_warehouse' );
		if ( ! $created->success || null === $opened ) { throw new RuntimeException( 'Snapshot configuration fixture creation failed.' ); }
		$config_rows[] = (int) $opened->scope->id;
		$changed = $service->save( new ScopedConfigurationWriteCommand( ConfigurationScopeType::Product, $config_product, 'in_warehouse', null, [ ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => '99' ] ], false, $opened->scope->config_version, 'c05-edit-' . bin2hex( random_bytes( 8 ) ), $opened->scope->id ) );
		$after_edit = $repository->findByScopeAndSlice( ConfigurationScopeType::Product, $config_product, 'in_warehouse' );
		$check( 'NATIVE-C05-CURRENT-CONFIGURATION-EDIT-PRESERVES-HISTORY', $changed->success && null !== $after_edit && $opened->fingerprint() !== $after_edit->fingerprint() && $base_facts === [ $historical( $base1 ), $historical( $base2 ) ] && $base_rows === $physical() );
		if ( null === $after_edit ) { throw new RuntimeException( 'Snapshot configuration fixture disappeared before reset.' ); }
		$reset = $service->reset( ConfigurationScopeType::Product, $config_product, 'in_warehouse', null, $after_edit->scope->config_version, 'c05-reset-' . bin2hex( random_bytes( 8 ) ), $after_edit->scope->id );
		$check( 'NATIVE-C05-CURRENT-CONFIGURATION-DELETION-PRESERVES-HISTORY', $reset && null === $repository->findByScopeAndSlice( ConfigurationScopeType::Product, $config_product, 'in_warehouse' ) && $base_facts === [ $historical( $base1 ), $historical( $base2 ) ] && $base_rows === $physical() );
		// Run the actual additive schema8 migration through native dbDelta on
		// only owned empty fixture tables. No hypothetical future upgrade claim.
		require_once $root . '/tests/Support/Operation/OperationProofDatabase.php';
		$fixture_prefix = OperationProofDatabase::prefix(); OperationProofDatabase::validate_prefix( $fixture_prefix );
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { $migration_tables[] = $fixture_prefix . 'delivery_engine_' . $suffix; }
		$isolated = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); $isolated->set_prefix( $fixture_prefix ); $isolated->suppress_errors( true ); $isolated->hide_errors();
		$wpdb = $isolated;
		$migration = require $root . '/database/migrations/20261006214731_create_rule_lifecycle_tables.php'; $migration->up(); $migration->verify();
		$wpdb = $main_db;
		$check( 'NATIVE-C05-ACTUAL-SCHEMA8-MIGRATION-PRESERVES-HISTORY', $base_rows === $physical() && $base_facts === [ $historical( $base1 ), $historical( $base2 ) ] && $options_before === $sql_rows( $main_db->prepare( "SELECT option_name,option_value,autoload FROM `{$main_db->options}` WHERE option_name IN (%s,%s) ORDER BY option_name", 'cetech_de_db_version', 'cetech_de_last_migration_status' ) ), [ 'actual_migration_version' => $migration->get_version(), 'fixture_tables' => count( $migration_tables ), 'source_schema_options_unchanged' => true ] );

		// Extension and negative fixtures follow the same physical read-before/
		// read-after checks as old snapshots; no parse path may update metadata.
		$assert_unchanged( 'NATIVE-C05-OLD-SNAPSHOTS-NEW-FACTS-NOT-RECORDED', static function () use ( $fresh, $base1, $base2, $reader ): array { [ $o1, $i1 ] = $fresh( $base1 ); [ $o2, $i2 ] = $fresh( $base2 ); return [ $reader->read_line( $i1 ), $reader->read_line( $i2 ), $reader->read_package( $o1 ), $reader->read_package( $o2 ) ]; }, static function ( array $reads ): bool { foreach ( $reads as $read ) { if ( null === $read->extensions || [] !== $read->extensions->customer_facts() || ! $read->extensions->required_semantics_supported() ) { return false; } foreach ( [ 'quote_policy', 'promise', 'fulfilment_labels', 'return_policy', 'refund_policy' ] as $name ) { if ( 'not_recorded' !== $read->extensions->get( $name )->status || null !== $read->extensions->get( $name )->facts ) { return false; } } } return true; } );

		$read_fixture = static function ( array $fixture ) use ( $fresh, $reader ): array { [ $order, $item ] = $fresh( $fixture ); return [ $reader->read_line( $item ), $reader->read_package( $order ) ]; };
		$unknown = $v2; $unknown['extensions'] = [ 'private_future_namespace' => [ 'version' => 91, 'required' => false, 'data' => [ 'private_value' => 'PRIVATE-C05-UNKNOWN-OPTIONAL' ] ] ];
		$fixture = $seed( $unknown, '2', $package2, '2', $parent_id, $variation_id );
		$assert_unchanged( 'NATIVE-C05-UNKNOWN-OPTIONAL-IGNORED-WITHOUT-DISCLOSURE', static fn (): array => $read_fixture( $fixture ), static function ( array $reads ) use ( $v2, $encode, $same_facts ): bool { $read = $reads[0]; $safe = $encode( [ $read->extensions?->diagnostics(), $read->extensions?->customer_facts() ] ); return '' === $read->error && $same_facts( $v2, $read->snapshot?->toArray() ) && 'ignored_optional' === $read->extensions?->get( 'private_future_namespace' )->status && ! str_contains( $safe, 'private_future_namespace' ) && ! str_contains( $safe, 'PRIVATE-C05-UNKNOWN-OPTIONAL' ); } );
		$reference = static fn ( string $name ): array => [ 'id' => 'PRIVATE-C05-' . $name, 'version' => 'captured-v1', 'content_hash' => hash( 'sha256', 'PRIVATE-C05-' . $name ) ];
		$extension_data = [
			'quote_policy' => [ 'reference' => $reference( 'QUOTE' ), 'currency_code' => 'GHS', 'amount' => '15.0000', 'customer_text' => 'Captured quote policy' ],
			'promise' => [ 'reference' => $reference( 'PROMISE' ), 'from' => '2026-09-03T10:00:00Z', 'until' => '2026-09-04T10:00:00Z', 'label' => 'Captured promise window' ],
			'fulfilment_labels' => [ 'reference' => $reference( 'LABELS' ), 'labels' => [ 'Captured fragile label', 'Captured collection label' ] ],
			'return_policy' => [ 'reference' => $reference( 'RETURN' ), 'customer_text' => 'Captured independent return terms' ],
			'refund_policy' => [ 'reference' => $reference( 'REFUND' ), 'customer_text' => 'Captured independent refund terms' ],
		];
		$extended = $v2; $extended['extensions'] = [];
		foreach ( $extension_data as $name => $data ) { $extended['extensions'][ $name ] = [ 'version' => 1, 'required' => false, 'data' => $data ]; }
		$extended_package = $package2; $extended_package['extensions'] = $extended['extensions'];
		$extended_fixture = $seed( $extended, '2', $extended_package, '2', $parent_id, $variation_id );
		$assert_unchanged( 'NATIVE-C05-FIVE-TYPED-EXTENSIONS-ARE-CAPTURED-FACTS', static fn (): array => $read_fixture( $extended_fixture ), static function ( array $reads ) use ( $extension_data, $v2, $package2, $same_facts ): bool { foreach ( $reads as $read ) { if ( '' !== $read->error || null === $read->snapshot || null === $read->extensions || ! $read->extensions->required_semantics_supported() ) { return false; } foreach ( $extension_data as $name => $data ) { $entry = $read->extensions->get( $name ); $facts = $entry->facts; if ( 'recorded' !== $entry->status || null === $facts || $data !== $facts->internal_facts() ) { return false; } } } return $same_facts( $v2, $reads[0]->snapshot->toArray() ) && $same_facts( $package2, $reads[1]->snapshot->toArray() ); } );
		$assert_unchanged( 'NATIVE-C05-EXTENSION-EXPORTS-DETACHED-AND-RAW-JSON-REFUSED', static function () use ( $read_fixture, $extended_fixture, $extension_data ): array {
			$reads = $read_fixture( $extended_fixture ); $facts = $reads[0]->extensions?->get( 'return_policy' )->facts;
			if ( null === $facts ) { return [ false, false, false ]; }
			$copy = $facts->internal_facts(); $copy['reference']['id'] = 'ALTERED-DETACHED'; $copy['customer_text'] = 'ALTERED-DETACHED';
			$customer = $facts->customer_facts(); $customer['customer_text'] = 'ALTERED-DETACHED';
			$refused = false; $safe_error = false;
			try { json_encode( $facts, JSON_THROW_ON_ERROR ); } catch ( LogicException $error ) { $refused = true; $safe_error = ! str_contains( $error->getMessage(), 'PRIVATE-C05-' ) && ! str_contains( $error->getMessage(), 'Captured independent' ); }
			$fresh_facts = $read_fixture( $extended_fixture )[0]->extensions?->get( 'return_policy' )->facts;
			return [ $extension_data['return_policy'] === $facts->internal_facts() && $extension_data['return_policy'] === $fresh_facts?->internal_facts(), $refused, $safe_error ];
		}, static fn ( array $result ): bool => [ true, true, true ] === $result );
		$assert_unchanged( 'NATIVE-C05-CUSTOMER-AND-DIAGNOSTIC-PROJECTIONS-EXCLUDE-PRIVATE-REFERENCES', static fn (): array => $read_fixture( $extended_fixture ), static function ( array $reads ) use ( $extension_data, $encode ): bool {
			foreach ( $reads as $read ) {
				$customer = $read->extensions?->customer_facts(); $diagnostic = $read->extensions?->diagnostics();
				if ( ! is_array( $customer ) || ! is_array( $diagnostic ) ) { return false; }
				$customer_json = $encode( $customer ); $diagnostic_json = $encode( $diagnostic );
				foreach ( $extension_data as $data ) { foreach ( $data['reference'] as $value ) { if ( str_contains( $customer_json, $value ) || str_contains( $diagnostic_json, $value ) ) { return false; } } }
				if ( ! str_contains( $customer_json, 'Captured independent return terms' ) || ! str_contains( $customer_json, 'Captured independent refund terms' ) || str_contains( $diagnostic_json, 'Captured independent' ) ) { return false; }
			}
			return true;
		} );
		$bad_optional = $v2; $bad_optional['extensions'] = [ 'quote_policy' => [ 'version' => 1, 'required' => false, 'data' => [ 'reference' => $reference( 'BAD' ), 'currency_code' => 'GHS', 'amount' => 'PRIVATE-NON-DECIMAL' ] ] ];
		$fixture = $seed( $bad_optional, '2', $package2, '2', $parent_id, $variation_id );
		$assert_unchanged( 'NATIVE-C05-MALFORMED-OPTIONAL-DOES-NOT-ERASE-VALID-BASE', static fn (): array => $read_fixture( $fixture ), static fn ( array $reads ): bool => '' === $reads[0]->error && null !== $reads[0]->snapshot && 'malformed' === $reads[0]->extensions?->get( 'quote_policy' )->status && null === $reads[0]->extensions?->get( 'quote_policy' )->facts && [] === $reads[0]->extensions?->customer_facts() );
		$unknown_version = $extended; $unknown_version['extensions'] = [ 'quote_policy' => $extended['extensions']['quote_policy'] ]; $unknown_version['extensions']['quote_policy']['version'] = 91;
		$fixture = $seed( $unknown_version, '2', $package2, '2', $parent_id, $variation_id );
		$assert_unchanged( 'NATIVE-C05-UNKNOWN-OPTIONAL-VERSION-NOT-INFERRED-AS-V1', static fn (): array => $read_fixture( $fixture ), static fn ( array $reads ): bool => '' === $reads[0]->error && null !== $reads[0]->snapshot && 'ignored_optional' === $reads[0]->extensions?->get( 'quote_policy' )->status && null === $reads[0]->extensions?->get( 'quote_policy' )->facts );
		foreach ( [ 'KNOWN' => 'quote_policy', 'UNKNOWN' => 'private_future_namespace' ] as $kind => $namespace ) {
			$required = $v2; $required['extensions'] = [ $namespace => [ 'version' => 1, 'required' => true, 'data' => 'KNOWN' === $kind ? $extension_data['quote_policy'] : [ 'private_value' => 'PRIVATE-C05-UNKNOWN-REQUIRED' ] ] ];
			$fixture = $seed( $required, '2', $package2, '2', $parent_id, $variation_id );
			$assert_unchanged( 'NATIVE-C05-' . $kind . '-REQUIRED-SEMANTICS-REFUSE-LEGACY-FORMAT', static fn (): array => $read_fixture( $fixture ), static function ( array $reads ) use ( $namespace, $encode ): bool { $read = $reads[0]; return 'version_mismatch' === $read->error && null === $read->snapshot && null !== $read->extensions && ! $read->extensions->required_semantics_supported() && 'unsupported_required' === $read->extensions->get( $namespace )->status && [] === $read->extensions->customer_facts() && ! str_contains( $encode( $read->extensions->diagnostics() ), 'PRIVATE-C05-' ); } );
		}
		// Each refused physical fixture proves its exact error and null facts,
		// without deriving a replacement from current products/settings.
		$line_negatives = [
			'MALFORMED' => [ '{PRIVATE-C05-BROKEN-JSON', '2', 'malformed' ],
			'PARTIAL' => [ array_diff_key( $v2, [ 'currency_code' => true ] ), '2', 'partial' ],
			'METADATA-VERSION-MISMATCH' => [ $v2, '1', 'version_mismatch' ],
			'UNSUPPORTED-MANDATORY-FORMAT' => [ array_replace( $v2, [ 'snapshot_version' => '99' ] ), '99', 'version_mismatch' ],
			'SELECTION-CONTRACT-MISMATCH' => [ array_replace( $v2, [ 'contract_version' => '99' ] ), '2', 'version_mismatch' ],
			'ARRAY-SHAPED-VERSION' => [ array_replace( $v2, [ 'snapshot_version' => [ '2' ] ] ), '2', 'version_mismatch' ],
			'NONNUMERIC-QUANTITY' => [ array_replace( $v2, [ 'quantity' => 'PRIVATE-C05-NOT-A-NUMBER' ] ), '2', 'partial' ],
			'DUPLICATE-JSON-MEMBERS' => [ '{"snapshot_version":"1","snapshot_version":"2"}', '2', 'malformed' ],
		];
		foreach ( $line_negatives as $name => [ $line_data, $stored_version, $error ] ) {
			$fixture = $seed( $line_data, $stored_version, $package2, '2', $parent_id, $variation_id );
			$assert_unchanged( 'NATIVE-C05-LINE-' . $name . '-NO-RECONSTRUCTION', static fn (): array => $read_fixture( $fixture ), static function ( array $reads ) use ( $error ): bool { $read = $reads[0]; return true === $read->has_meta && $error === $read->error && null === $read->snapshot && ( null === $read->extensions || [] === $read->extensions->customer_facts() ); } );
		}
		$package_negatives = [
			'MALFORMED' => [ 'PRIVATE-C05-BROKEN-JSON', '2', 'malformed' ],
			'PARTIAL' => [ array_diff_key( $package2, [ 'currency_code' => true ] ), '2', 'partial' ],
			'METADATA-VERSION-MISMATCH' => [ $package2, '1', 'version_mismatch' ],
			'MIXED-GROUPS' => [ array_replace( $package2, [ 'groups' => [ $package2['groups'][0], 'PRIVATE-C05-BAD-GROUP' ] ] ), '2', 'partial' ],
		];
		foreach ( $package_negatives as $name => [ $package_data, $stored_version, $error ] ) {
			$fixture = $seed( $v2, '2', $package_data, $stored_version, $parent_id, $variation_id );
			$assert_unchanged( 'NATIVE-C05-PACKAGE-' . $name . '-NO-RECONSTRUCTION', static fn (): array => $read_fixture( $fixture ), static function ( array $reads ) use ( $error ): bool { $read = $reads[1]; return true === $read->has_meta && $error === $read->error && null === $read->snapshot && ( null === $read->extensions || [] === $read->extensions->customer_facts() ); } );
		}
		$fixture = $seed( null, null, null, null, $config_product );
		$assert_unchanged( 'NATIVE-C05-MISSING-META-IS-UNAVAILABLE-NOT-REBUILT', static fn (): array => $read_fixture( $fixture ), static function ( array $reads ): bool { foreach ( $reads as $read ) { if ( $read->has_meta || 'missing' !== $read->error || null !== $read->snapshot || null !== $read->extensions ) { return false; } } return true; } );
	} finally {
		$wpdb = $main_db;
		if ( $isolated instanceof wpdb ) { $isolated->close(); }
		foreach ( $migration_tables as $table ) { if ( 1 !== preg_match( '/\Aop_proof_[a-zA-Z0-9_]+_delivery_engine_(rule_family_guards|logical_rules|rule_versions)\z/D', $table ) ) { throw new RuntimeException( 'Refusing foreign snapshot migration cleanup.' ); } $main_db->query( "DROP TABLE IF EXISTS `{$table}`" ); }
		foreach ( $orders as $order_id ) { $order = new WC_Order( $order_id ); $order->delete( true ); }
		foreach ( array_reverse( $products ) as $product_id ) { $product = wc_get_product( $product_id ); if ( $product instanceof WC_Product ) { $product->delete( true ); } }
		if ( [] !== $config_rows ) {
			$ids = implode( ',', array_map( 'intval', $config_rows ) );
			foreach ( [ ScopedConfigurationSchema::FIELDS_SUFFIX, ScopedConfigurationSchema::COLLECTIONS_SUFFIX ] as $suffix ) { $table = TableNames::for( $suffix ); $main_db->query( "DELETE FROM `{$table}` WHERE scope_row_id IN ({$ids})" ); }
			$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX ); $main_db->query( "DELETE FROM `{$table}` WHERE id IN ({$ids})" );
			$table = TableNames::for( 'audit_log' ); $main_db->query( "DELETE FROM `{$table}` WHERE entity_type='configuration_scope' AND entity_id IN ({$ids})" );
		}
		$orders_gone = true; foreach ( $orders as $order_id ) { $orders_gone = $orders_gone && false === wc_get_order( $order_id ); }
		// Woo 11.1.2's factory can return an instance-cache object before a
		// datastore read, and wc_get_product's unavailable contract is null|false.
		// Establish physical deletion first; refreshing only our fixture IDs must
		// never turn a retained post or retained product meta into a cleanup PASS.
		$product_lookup_before_refresh = [ 'false' => 0, 'null' => 0, 'product' => 0, 'other' => 0 ];
		foreach ( $products as $product_id ) {
			$lookup = wc_get_product( $product_id );
			$type = false === $lookup ? 'false' : ( null === $lookup ? 'null' : ( $lookup instanceof WC_Product ? 'product' : 'other' ) );
			++$product_lookup_before_refresh[ $type ];
		}
		$product_ids = implode( ',', array_map( 'intval', $products ) );
		$product_posts_gone = '' === $product_ids || [] === $sql_rows( "SELECT ID FROM `{$main_db->posts}` WHERE ID IN ({$product_ids})" );
		$product_meta_gone = '' === $product_ids || [] === $sql_rows( "SELECT meta_id FROM `{$main_db->postmeta}` WHERE post_id IN ({$product_ids})" );
		foreach ( $products as $product_id ) {
			clean_post_cache( $product_id );
			WC_Cache_Helper::invalidate_cache_group( 'product_' . $product_id );
			if ( \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'product_instance_caching' ) ) {
				wc_get_container()->get( \Automattic\WooCommerce\Internal\Caches\ProductCache::class )->remove( $product_id );
			}
		}
		$products_unavailable = true;
		foreach ( $products as $product_id ) { $lookup = wc_get_product( $product_id ); $products_unavailable = $products_unavailable && ( false === $lookup || null === $lookup ); }
		$products_gone = $product_posts_gone && $product_meta_gone && $products_unavailable;
		$items_gone = true; if ( [] !== $items ) { $ids = implode( ',', array_map( 'intval', $items ) ); $items_gone = [] === $sql_rows( "SELECT meta_id FROM `{$item_meta_table}` WHERE order_item_id IN ({$ids})" ); }
		$protected_meta_gone = [ [], [] ] === $physical();
		$config_gone = true;
		if ( [] !== $config_rows ) {
			$ids = implode( ',', array_map( 'intval', $config_rows ) );
			$table = TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX ); $config_gone = [] === $sql_rows( "SELECT id FROM `{$table}` WHERE id IN ({$ids})" );
			foreach ( [ ScopedConfigurationSchema::FIELDS_SUFFIX, ScopedConfigurationSchema::COLLECTIONS_SUFFIX ] as $suffix ) { $table = TableNames::for( $suffix ); $config_gone = $config_gone && [] === $sql_rows( "SELECT id FROM `{$table}` WHERE scope_row_id IN ({$ids})" ); }
			$table = TableNames::for( 'audit_log' ); $config_gone = $config_gone && [] === $sql_rows( "SELECT id FROM `{$table}` WHERE entity_type='configuration_scope' AND entity_id IN ({$ids})" );
		}
		$tables_gone = true; foreach ( $migration_tables as $table ) { $tables_gone = $tables_gone && null === $main_db->get_var( $main_db->prepare( 'SHOW TABLES LIKE %s', $main_db->esc_like( $table ) ) ); }
		wp_set_current_user( 0 ); wp_set_current_user( $old_user );
		$cleanup_ok = $orders_gone && $products_gone && $items_gone && $protected_meta_gone && $config_gone && $tables_gone && $wpdb === $main_db && $old_user === get_current_user_id() && $options_before === $sql_rows( $main_db->prepare( "SELECT option_name,option_value,autoload FROM `{$main_db->options}` WHERE option_name IN (%s,%s) ORDER BY option_name", 'cetech_de_db_version', 'cetech_de_last_migration_status' ) );
		$check( 'NATIVE-C05-FIXTURE-CLEANUP-AND-CONTEXT-RESTORED', $cleanup_ok, [ 'orders_removed' => $orders_gone, 'products_removed' => $products_gone, 'product_posts_removed' => $product_posts_gone, 'product_meta_removed' => $product_meta_gone, 'product_lookup_before_cache_refresh' => $product_lookup_before_refresh, 'refreshed_products_unavailable' => $products_unavailable, 'item_meta_removed' => $items_gone, 'protected_meta_removed' => $protected_meta_gone, 'fixture_configuration_and_audits_removed' => $config_gone, 'owned_migration_tables_removed' => $tables_gone, 'native_context_restored' => $wpdb === $main_db && $old_user === get_current_user_id() ] );
	}
};
