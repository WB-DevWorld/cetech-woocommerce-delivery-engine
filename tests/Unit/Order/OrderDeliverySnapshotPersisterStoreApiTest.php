<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\OrderDeliveryGroupSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliveryPackageSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotGate;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotPersister;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Support\Logger;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

final class OrderDeliverySnapshotPersisterStoreApiTest extends TestCase {

	private OrderDeliverySnapshotPersister $persister;

	private OrderDeliverySnapshotBuilder $builder;

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooCommerce', false ) ) {
			eval( 'class WooCommerce {}' );
		}

		$flags = new FeatureFlags();
		$flags->set( 'enable_order_delivery_snapshot_persistence', true );
		$flags->set( 'enable_woocommerce_shipping_rate_calculation', true );
		$flags->set( 'enable_product_delivery_selector', true );
		$flags->set( 'enable_cart_delivery_selection_capture', true );
		$flags->set( 'enable_checkout_delivery_selection_validation', true );

		$gate = new OrderDeliverySnapshotGate(
			$flags,
			new Requirements(),
			new ShippingRateCalculationGate( $flags, new Requirements() )
		);

		$this->builder = $this->getMockBuilder( OrderDeliverySnapshotBuilder::class )
			->disableOriginalConstructor()
			->onlyMethods( [ 'build_line_snapshot', 'build_package_snapshot' ] )
			->getMock();

		$this->persister = new OrderDeliverySnapshotPersister( $gate, $this->builder, new Logger() );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'] );
		parent::tearDown();
	}

	public function test_classic_line_item_hook_still_writes_snapshot(): void {
		$item  = new WC_Order_Item_Product( [ 'id' => 11, 'product_id' => 16, 'quantity' => 1 ] );
		$order = new WC_Order( [ 'id' => 90 ] );
		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->line_snapshot( 16, 1 ) );

		$this->persister->handle_create_order_line_item( $item, 'abc', [ 'product_id' => 16, 'quantity' => 1 ], $order );

		self::assertNotSame( '', $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertSame( '1', $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, true ) );
		self::assertSame( '', $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
	}

	public function test_store_api_backfill_writes_missing_line_snapshot_from_cart(): void {
		$item  = $this->product_item( 1, 16 );
		$order = $this->managed_order( 25, [ 1 => $item ] );

		$this->install_cart(
			[
				'chair-key' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);

		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->line_snapshot( 16, 1 ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		self::assertSame( '', $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );

		$this->persister->handle_store_api_order_update( $order, null );

		$decoded = $this->decode_line( $item );
		self::assertSame( 16, $decoded['product_id'] );
		self::assertSame( 'delivery', $decoded['fulfilment_choice'] );
		self::assertSame( 'in_warehouse|delivery|1', $decoded['delivery_group_id'] );
		self::assertSame( 'QA Local Standard', $decoded['delivery_offer_public_label'] );
		self::assertSame( '3–5 business days', $decoded['estimate_text'] );
		self::assertSame( 1, $decoded['quantity'] );
		self::assertSame( 'GHS', $decoded['currency_code'] );
	}

	public function test_blocks_package_snapshot_persists_alongside_line_snapshot(): void {
		$item  = $this->product_item( 1, 16 );
		$order = $this->managed_order( 27, [ 1 => $item ] );
		$this->install_cart(
			[
				'chair-key' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);

		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->line_snapshot( 16, 1 ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( $this->package_snapshot() );

		$this->persister->handle_store_api_order_update( $order, null );

		self::assertNotSame( '', $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		$package = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true );
		self::assertIsString( $package );
		self::assertNotSame( '', $package );
		$decoded = json_decode( $package, true );
		self::assertIsArray( $decoded );
		self::assertSame( 'in_warehouse|delivery|1', $decoded['groups'][0]['group_id'] );
		self::assertSame( OrderDeliverySnapshot::VERSION, $order->get_meta( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true ) );
	}

	public function test_classic_and_blocks_persist_the_same_line_snapshot_fields(): void {
		$classic_item = $this->product_item( 11, 16 );
		$classic_order = new WC_Order( [ 'id' => 91 ] );
		$snapshot      = $this->line_snapshot( 16, 1 );
		$this->builder->method( 'build_line_snapshot' )->willReturn( $snapshot );
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$this->persister->handle_create_order_line_item(
			$classic_item,
			'classic-key',
			[ 'product_id' => 16, 'quantity' => 1 ],
			$classic_order
		);

		$blocks_item  = $this->product_item( 1, 16 );
		$blocks_order = $this->managed_order( 28, [ 1 => $blocks_item ] );
		$this->install_cart(
			[
				'chair-key' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);
		$this->persister->handle_store_api_order_update( $blocks_order, null );

		self::assertSame(
			array_keys( $this->decode_line( $classic_item ) ),
			array_keys( $this->decode_line( $blocks_item ) )
		);
		self::assertSame( $this->decode_line( $classic_item ), $this->decode_line( $blocks_item ) );
	}

	public function test_pickup_blocks_line_snapshot_persists(): void {
		$item  = $this->product_item( 3, 18 );
		$order = $this->managed_order( 29, [ 3 => $item ], 'in_store|store_pickup|pickup' );
		$this->install_cart(
			[
				'lamp-key' => [ 'product_id' => 18, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);

		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->pickup_snapshot( 18, 1 ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( $this->pickup_package_snapshot() );

		$this->persister->handle_store_api_order_update( $order, null );

		$decoded = $this->decode_line( $item );
		self::assertSame( 'store_pickup', $decoded['fulfilment_choice'] );
		self::assertSame( 'in_store|store_pickup|pickup', $decoded['delivery_group_id'] );
		self::assertSame( 'QA Showroom', $decoded['pickup_location_label'] );
	}

	public function test_delivery_blocks_order_can_create_historical_shipment_after_backfill(): void {
		$item  = $this->product_item( 1, 16, 'QA Warehouse Chair' );
		$order = $this->managed_order( 30, [ 1 => $item ] );
		$this->install_cart(
			[
				'chair-key' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);
		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->line_snapshot( 16, 1 ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( $this->package_snapshot() );

		$this->persister->handle_store_api_order_update( $order, null );

		$context = ( new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() ) )->from_order( $order );
		$result  = ( new HistoricalShipmentPlanner() )->plan( $context );

		self::assertTrue( $result->ok );
		self::assertCount( 1, $result->plans );
		self::assertSame( 'in_warehouse|delivery|1', $result->plans[0]->delivery_group_id );
		self::assertSame( 1, $result->plans[0]->items[0]->order_item_id );
		self::assertSame( 'QA Local Standard', $result->plans[0]->delivery_offer_public_label );
	}

	public function test_store_pickup_cannot_create_delivery_shipment(): void {
		$item  = $this->product_item( 3, 18, 'QA In Store Lamp' );
		$order = $this->managed_order( 31, [ 3 => $item ], 'in_store|store_pickup|pickup' );
		$this->install_cart(
			[
				'lamp-key' => [ 'product_id' => 18, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);
		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->pickup_snapshot( 18, 1 ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( $this->pickup_package_snapshot() );

		$this->persister->handle_store_api_order_update( $order, null );

		$context = ( new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() ) )->from_order( $order );
		$result  = ( new HistoricalShipmentPlanner() )->plan( $context );

		self::assertTrue( $result->ok );
		self::assertSame( [], $result->plans );
		self::assertSame( 1, $result->pickup_groups_skipped );
		self::assertTrue( $result->is_pickup_only() );
	}

	public function test_multiple_managed_lines_each_receive_correct_snapshot(): void {
		$chair = $this->product_item( 1, 16 );
		$desk  = $this->product_item( 2, 17 );
		$order = $this->managed_order( 32, [ 1 => $chair, 2 => $desk ] );
		$this->install_cart(
			[
				'chair-key' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
				'desk-key'  => [ 'product_id' => 17, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);

		$this->builder->method( 'build_line_snapshot' )->willReturnCallback(
			function ( string $key, array $values ): ?OrderDeliveryLineSnapshot {
				$product_id = (int) ( $values['product_id'] ?? 0 );
				if ( 16 === $product_id ) {
					return $this->line_snapshot( 16, 1, 'QA Local Standard' );
				}
				if ( 17 === $product_id ) {
					return $this->line_snapshot( 17, 1, 'QA Desk Delivery' );
				}

				return null;
			}
		);
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$this->persister->handle_store_api_order_update( $order, null );

		self::assertSame( 'QA Local Standard', $this->decode_line( $chair )['delivery_offer_public_label'] );
		self::assertSame( 16, $this->decode_line( $chair )['product_id'] );
		self::assertSame( 'QA Desk Delivery', $this->decode_line( $desk )['delivery_offer_public_label'] );
		self::assertSame( 17, $this->decode_line( $desk )['product_id'] );
	}

	public function test_variation_ids_map_to_matching_order_item(): void {
		$parent = $this->product_item( 1, 20, 'Parent', 0 );
		$oak    = $this->product_item( 2, 20, 'Oak', 31 );
		$order  = $this->managed_order( 41, [ 1 => $parent, 2 => $oak ] );
		$this->install_cart(
			[
				'oak-key' => [ 'product_id' => 20, 'variation_id' => 31, 'quantity' => 1 ],
			]
		);
		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->line_snapshot( 20, 1, 'Oak', 31 ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$this->persister->handle_store_api_order_update( $order, null );

		self::assertSame( '', $parent->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertNotSame( '', $oak->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertSame( 31, $this->decode_line( $oak )['variation_id'] );
	}

	public function test_identical_products_map_by_recorded_cart_item_key(): void {
		$first  = $this->product_item( 1, 16 );
		$second = $this->product_item( 2, 16 );
		$order  = $this->managed_order( 42, [ 1 => $first, 2 => $second ] );
		$values = [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ];
		$phase  = (object) [ 'write' => false ];

		$this->builder->method( 'build_line_snapshot' )->willReturnCallback(
			function ( string $key ) use ( $phase ): ?OrderDeliveryLineSnapshot {
				if ( ! $phase->write ) {
					return null;
				}

				$label = 'cart-a' === $key ? 'Offer A' : 'Offer B';

				return $this->line_snapshot( 16, 1, $label );
			}
		);
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$this->persister->handle_create_order_line_item( $first, 'cart-a', $values, $order );
		$this->persister->handle_create_order_line_item( $second, 'cart-b', $values, $order );
		self::assertSame( 'cart-a', $first->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
		self::assertSame( 'cart-b', $second->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );

		$phase->write = true;
		$this->install_cart(
			[
				'cart-a' => $values,
				'cart-b' => $values,
			]
		);
		$this->persister->handle_store_api_order_update( $order, null );

		self::assertSame( 'Offer A', $this->decode_line( $first )['delivery_offer_public_label'] );
		self::assertSame( 'Offer B', $this->decode_line( $second )['delivery_offer_public_label'] );
		self::assertSame( '', $first->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
		self::assertSame( '', $second->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
	}

	public function test_unmanaged_cart_lines_do_not_receive_snapshots(): void {
		$item  = $this->product_item( 7, 99 );
		$order = new WC_Order(
			[
				'id'              => 40,
				'items'           => [ 7 => $item ],
				'shipping_items' => [ new WC_Order_Item_Shipping( [ 'method_id' => 'flat_rate' ] ) ],
			]
		);
		$this->install_cart(
			[
				'plain' => [ 'product_id' => 99, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);
		$this->builder->method( 'build_line_snapshot' )->willReturn( null );
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$this->persister->handle_store_api_order_update( $order, null );

		self::assertSame( '', $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	public function test_rerunning_store_api_persistence_does_not_replace_existing_line_snapshot(): void {
		$existing = $this->line_snapshot( 16, 1, 'QA Local Standard' );
		$item     = new WC_Order_Item_Product(
			[
				'id'         => 1,
				'product_id' => 16,
				'quantity'   => 1,
				'meta'       => [
					OrderDeliverySnapshot::META_LINE_SNAPSHOT         => wp_json_encode( $existing->toArray() ),
					OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => OrderDeliverySnapshot::VERSION,
				],
			]
		);
		$order = $this->managed_order( 26, [ 1 => $item ] );

		$this->install_cart(
			[
				'chair-key' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);

		$replacement = $this->line_snapshot( 16, 1, 'SHOULD NOT WRITE' );
		$this->builder->method( 'build_line_snapshot' )->willReturn( $replacement );
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$before = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		$this->persister->handle_store_api_order_update( $order, null );
		$this->persister->handle_order_created( $order );
		self::assertSame( $before, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertStringContainsString( 'QA Local Standard', (string) $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertStringNotContainsString( 'SHOULD NOT WRITE', (string) $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	public function test_historical_snapshots_are_not_rewritten_when_cart_is_gone(): void {
		$item  = $this->product_item( 1, 16 );
		$order = $this->managed_order( 50, [ 1 => $item ] );
		unset( $GLOBALS['cetech_de_test_wc'] );
		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->line_snapshot( 16, 1, 'SHOULD NOT WRITE' ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$this->persister->handle_order_created( $order );

		self::assertSame( '', $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	public function test_schema_target_remains_five(): void {
		self::assertSame( '5', SchemaVersion::TARGET );
	}

	public function test_two_same_product_lines_with_different_destinations_keep_correct_v2_snapshots(): void {
		$first  = $this->product_item( 1, 16 );
		$second = $this->product_item( 2, 16 );
		$order  = $this->managed_order( 60, [ 1 => $first, 2 => $second ] );
		$phase  = (object) [ 'write' => false ];

		$this->builder->method( 'build_line_snapshot' )->willReturnCallback(
			function ( string $key ) use ( $phase ): ?OrderDeliveryLineSnapshot {
				if ( ! $phase->write ) {
					return null;
				}

				return 'dest-east' === $key
					? $this->v2_line_snapshot( 'east-hash', '12 Boundary Rd' )
					: $this->v2_line_snapshot( 'spin-hash', '88 Spintex Road' );
			}
		);
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$values = [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ];
		$this->persister->handle_create_order_line_item( $first, 'dest-east', $values, $order );
		$this->persister->handle_create_order_line_item( $second, 'dest-spin', $values, $order );

		$phase->write = true;
		$this->install_cart(
			[
				'dest-east' => $values,
				'dest-spin' => $values,
			]
		);
		$this->persister->handle_store_api_order_update( $order, null );

		self::assertSame( 'east-hash', $this->decode_line( $first )['delivery_location_identity'] );
		self::assertSame( '12 Boundary Rd', $this->decode_line( $first )['delivery_address']['address_1'] );
		self::assertSame( 'spin-hash', $this->decode_line( $second )['delivery_location_identity'] );
		self::assertSame( '88 Spintex Road', $this->decode_line( $second )['delivery_address']['address_1'] );
		self::assertSame( '2', $first->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, true ) );
		self::assertSame( '', $first->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
		self::assertSame( '', $second->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
	}

	public function test_ambiguous_fallback_does_not_guess(): void {
		$first  = $this->product_item( 1, 16 );
		$second = $this->product_item( 2, 16 );
		$order  = $this->managed_order( 61, [ 1 => $first, 2 => $second ] );

		$this->builder->method( 'build_line_snapshot' )->willReturn( $this->v2_line_snapshot( 'should-not-attach', '12 Boundary Rd' ) );
		$this->builder->method( 'build_package_snapshot' )->willReturn( null );

		$this->install_cart(
			[
				'one' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
				'two' => [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 1 ],
			]
		);
		$this->persister->handle_store_api_order_update( $order, null );

		self::assertSame( '', $first->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
		self::assertSame( '', $second->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}

	/**
	 * @param array<int, WC_Order_Item_Product> $items
	 */
	private function managed_order( int $id, array $items, string $group_id = 'in_warehouse|delivery|1' ): WC_Order {
		return new WC_Order(
			[
				'id'              => $id,
				'items'           => $items,
				'shipping_items' => [
					new WC_Order_Item_Shipping(
						[
							'method_id' => 'delivery_engine_selected_offer',
							'meta'      => [ 'cetech_de_group_id' => $group_id ],
							'total'     => str_contains( $group_id, 'store_pickup' ) ? '0.0000' : '15.0000',
						]
					),
				],
			]
		);
	}

	private function product_item( int $id, int $product_id, string $name = 'Item', int $variation_id = 0 ): WC_Order_Item_Product {
		return new WC_Order_Item_Product(
			[
				'id'           => $id,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => 1,
				'name'         => $name,
			]
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $cart
	 */
	private function install_cart( array $cart ): void {
		$GLOBALS['cetech_de_test_wc'] = (object) [
			'cart' => new class( $cart ) {
				/** @param array<string, array<string, mixed>> $items */
				public function __construct( private array $items ) {
				}

				public function get_cart(): array {
					return $this->items;
				}
			},
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode_line( WC_Order_Item_Product $item ): array {
		$raw = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		self::assertIsString( $raw );
		self::assertNotSame( '', $raw );
		$decoded = json_decode( $raw, true );
		self::assertIsArray( $decoded );

		return $decoded;
	}

	private function line_snapshot( int $product_id, int $qty, string $label = 'QA Local Standard', ?int $variation_id = null ): OrderDeliveryLineSnapshot {
		return new OrderDeliveryLineSnapshot(
			ProductDeliverySelectionIntent::CONTRACT_VERSION,
			OrderDeliverySnapshot::VERSION,
			$product_id,
			$variation_id,
			'in_warehouse',
			'delivery',
			1,
			$label,
			null,
			'3–5 business days',
			null,
			1,
			$qty,
			'GHS',
			'15.0000',
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			null,
			null,
			'2026-09-02T15:00:00+00:00',
			'in_warehouse|delivery|1'
		);
	}

	private function v2_line_snapshot( string $identity, string $street ): OrderDeliveryLineSnapshot {
		return new OrderDeliveryLineSnapshot(
			ProductDeliverySelectionIntent::CONTRACT_VERSION,
			OrderDeliverySnapshot::VERSION_V2,
			16,
			null,
			'in_warehouse',
			'delivery',
			1,
			'QA Local Standard',
			null,
			'3–5 business days',
			null,
			1,
			1,
			'GHS',
			'15.0000',
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			null,
			null,
			'2026-09-02T15:00:00+00:00',
			'in_warehouse|delivery|1|' . substr( $identity, 0, 16 ),
			null,
			null,
			null,
			1,
			[ 'country' => 'GH', 'city' => 'Accra' ],
			[
				'address_1' => $street,
				'recipient' => [ 'first_name' => 'Ama' ],
			],
			'match-hash',
			$identity,
			null
		);
	}

	private function pickup_snapshot( int $product_id, int $qty ): OrderDeliveryLineSnapshot {
		return new OrderDeliveryLineSnapshot(
			ProductDeliverySelectionIntent::CONTRACT_VERSION,
			OrderDeliverySnapshot::VERSION,
			$product_id,
			null,
			'in_store',
			'store_pickup',
			null,
			'Store Pickup',
			null,
			null,
			null,
			null,
			$qty,
			'GHS',
			'0.0000',
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			null,
			null,
			'2026-09-02T15:00:00+00:00',
			'in_store|store_pickup|pickup',
			'QA Showroom',
			'1 Warehouse Rd',
			'Ask for QA'
		);
	}

	private function package_snapshot(): OrderDeliveryPackageSnapshot {
		return new OrderDeliveryPackageSnapshot(
			OrderDeliverySnapshot::VERSION,
			'delivery_engine_selected_offer',
			'QA Local Standard',
			'15.0000',
			'GHS',
			1,
			OrderDeliverySnapshot::PACKAGE_STATUS_SUCCESS,
			'2026-09-02T15:00:00+00:00',
			[
				new OrderDeliveryGroupSnapshot(
					'in_warehouse|delivery|1',
					'delivery_engine_selected_offer',
					'QA Local Standard',
					'15.0000',
					'delivery',
					false,
					1
				),
			]
		);
	}

	private function pickup_package_snapshot(): OrderDeliveryPackageSnapshot {
		return new OrderDeliveryPackageSnapshot(
			OrderDeliverySnapshot::VERSION,
			'delivery_engine_selected_offer',
			'Store Pickup',
			'0.0000',
			'GHS',
			null,
			OrderDeliverySnapshot::PACKAGE_STATUS_SUCCESS,
			'2026-09-02T15:00:00+00:00',
			[
				new OrderDeliveryGroupSnapshot(
					'in_store|store_pickup|pickup',
					'delivery_engine_selected_offer',
					'Store Pickup',
					'0.0000',
					'store_pickup',
					true,
					1
				),
			]
		);
	}
}
