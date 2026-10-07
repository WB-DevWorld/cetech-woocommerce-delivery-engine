<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;

final class DeliveryQuoteSnapshotReaderTest extends TestCase {
	#[DataProvider( 'legacy_versions' )]
	public function test_genuine_legacy_absence_preserves_core_optional_facts_and_raw_bytes( int $version ): void {
		$line = DeliveryQuoteSnapshotFixtures::line(); $package = DeliveryQuoteSnapshotFixtures::package();
		if ( 2 === $version ) {
			$ctx = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
			$line = array_merge( $line, [ 'snapshot_version' => '2', 'customer_context_version' => 1, 'matching_location' => $ctx->matching_location?->toArray(), 'delivery_address' => $ctx->delivery_address?->toArray(), 'matching_identity' => $ctx->matching_identity, 'delivery_location_identity' => $ctx->delivery_location_identity, 'pickup_location_id' => null ] ); $package['snapshot_version'] = '2';
		}
		$line['extensions'] = [ 'return_policy' => [ 'version' => 1, 'required' => false, 'data' => [ 'reference' => [ 'id' => 'PRIVATE-POLICY', 'version' => 'v1', 'content_hash' => str_repeat( 'c', 64 ) ], 'customer_text' => 'Saved policy' ] ] ];
		$raw = json_encode( $line, JSON_THROW_ON_ERROR ); $item = $this->item( $raw ); $order_raw = json_encode( $package, JSON_THROW_ON_ERROR ); $order = $this->order( $order_raw, $item );
		$reader = new OrderDeliverySnapshotReader(); $first = $reader->read_line( $item );
		self::assertSame( '', $first->error ); self::assertNull( $first->delivery_quote ); self::assertSame( 'Saved policy', $first->extensions?->customer_facts()['return_policy']['customer_text'] ?? null );
		$original = $GLOBALS['cetech_de_test_options'] ?? null;
		try { $GLOBALS['cetech_de_test_options'] = [ 'cetech_de_schema_version' => 999, 'woocommerce_currency' => 'USD', 'enable_order_delivery_snapshot_persistence' => false ]; $again = $reader->read_line( $item ); self::assertSame( $first->snapshot?->toArray(), $again->snapshot?->toArray() ); self::assertSame( '', $reader->read_package( $order )->error ); }
		finally { $GLOBALS['cetech_de_test_options'] = $original; }
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ) ); self::assertSame( $order_raw, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) );
	}
	public static function legacy_versions(): iterable { yield [ 1 ]; yield [ 2 ]; }

	#[DataProvider( 'required_packets' )]
	public function test_present_marker_or_packet_never_downgrades_to_legacy( string $kind ): void {
		$line = DeliveryQuoteSnapshotFixtures::line(); $package = DeliveryQuoteSnapshotFixtures::package(); $marker_exists = true; $marker = '1';
		switch ( $kind ) {
			case 'absent-envelope': break;
			case 'empty-marker': $marker = ''; break;
			case 'null-marker': $marker = null; break;
			case 'false-marker': $marker = false; break;
			case 'unknown-marker': $marker = '2'; break;
			case 'null-envelope': $line['delivery_quote'] = $package['delivery_quote'] = null; $marker_exists = false; break;
			case 'empty-list-envelope': $line['delivery_quote'] = $package['delivery_quote'] = []; $marker_exists = false; break;
			case 'unknown-format': $line['delivery_quote'] = $package['delivery_quote'] = [ 'format' => 2 ]; $marker_exists = false; break;
			case 'empty-base': $line = $package = null; break;
		}
		$raw_line = null === $line ? '' : json_encode( $line, JSON_THROW_ON_ERROR ); $raw_package = null === $package ? '' : json_encode( $package, JSON_THROW_ON_ERROR );
		$item = $this->item( $raw_line ); $order = $this->order( $raw_package, $item );
		if ( $marker_exists ) { $item->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, $marker ); $order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, $marker ); }
		foreach ( [ ( new OrderDeliverySnapshotReader() )->read_line( $item ), ( new OrderDeliverySnapshotReader() )->read_package( $order ) ] as $read ) { self::assertTrue( $read->has_meta ); self::assertSame( 'version_mismatch', $read->error ); self::assertNull( $read->snapshot ); self::assertNotNull( $read->delivery_quote ); }
		self::assertSame( $raw_line, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ) ); self::assertSame( $raw_package, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) );
	}
	public static function required_packets(): iterable { foreach ( [ 'absent-envelope', 'empty-marker', 'null-marker', 'false-marker', 'unknown-marker', 'null-envelope', 'empty-list-envelope', 'unknown-format', 'empty-base' ] as $kind ) { yield $kind => [ $kind ]; } }

	public function test_known_quote_packet_reads_old_history_after_expiry_without_live_sources_or_writes(): void {
		$packet = DeliveryQuoteSnapshotFixtures::envelope()->private_facts();
		$line = DeliveryQuoteSnapshotFixtures::line() + [ 'delivery_quote' => $packet ]; $package = DeliveryQuoteSnapshotFixtures::package() + [ 'delivery_quote' => $packet ];
		$item = $this->item( json_encode( $line, JSON_THROW_ON_ERROR ) ); $item->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, '1' );
		$order = $this->order( json_encode( $package, JSON_THROW_ON_ERROR ), $item ); $order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, '1' );
		$reader = new OrderDeliverySnapshotReader();
		self::assertSame( 'recorded', $reader->read_line( $item )->delivery_quote?->status ); self::assertSame( 'recorded', $reader->read_package( $order )->delivery_quote?->status );
		$ctx = ( new HistoricalOrderShipmentContextFactory( $reader ) )->from_order( $order ); $plan = ( new HistoricalShipmentPlanner() )->plan( $ctx );
		self::assertFalse( $ctx->package_unreadable ); self::assertFalse( $ctx->lines[0]->snapshot_unreadable ); self::assertTrue( $plan->ok ); self::assertSame( '12.5000', $plan->plans[0]->customer_paid_shipping_amount );
		$summary = ( new CustomerOrderDeliverySummaryBuilder( $reader, new OrderDeliverySnapshotIntegrity() ) )->build( $order ); self::assertNotNull( $summary ); self::assertSame( 'Historical Standard', $summary->lines[0]->delivery_option_label );
	}

	#[DataProvider( 'mixed_quote_history' )]
	public function test_quote_owned_history_requires_matching_packet_on_every_managed_line_and_order( string $kind ): void {
		$packet = DeliveryQuoteSnapshotFixtures::envelope()->private_facts(); $line_packet = $packet;
		if ( 'changed-reference' === $kind ) { $line_packet['quote_id'] = '33333333-3333-4333-8333-333333333333'; }
		if ( 'changed-receipt' === $kind ) { $line_packet['money_receipt']['groups'][0]['customer_label'] = 'Different captured label'; }
		$line = DeliveryQuoteSnapshotFixtures::line(); $package = DeliveryQuoteSnapshotFixtures::package();
		if ( 'line-absent' !== $kind ) { $line['delivery_quote'] = $line_packet; }
		if ( 'order-absent' !== $kind ) { $package['delivery_quote'] = $packet; }
		$item = $this->item( json_encode( $line, JSON_THROW_ON_ERROR ) ); $order = $this->order( json_encode( $package, JSON_THROW_ON_ERROR ), $item ); $reader = new OrderDeliverySnapshotReader();
		$context = ( new HistoricalOrderShipmentContextFactory( $reader ) )->from_order( $order ); self::assertTrue( $context->package_unreadable || $context->lines[0]->snapshot_unreadable ); self::assertFalse( ( new HistoricalShipmentPlanner() )->plan( $context )->ok );
		self::assertNull( ( new CustomerOrderDeliverySummaryBuilder( $reader, new OrderDeliverySnapshotIntegrity() ) )->build( $order ) );
	}
	public static function mixed_quote_history(): iterable { foreach ( [ 'line-absent', 'order-absent', 'changed-reference', 'changed-receipt' ] as $kind ) { yield $kind => [ $kind ]; } }

	public function test_native_json_empty_rates_object_is_not_accepted_as_a_list(): void {
		$line = DeliveryQuoteSnapshotFixtures::line() + [ 'delivery_quote' => DeliveryQuoteSnapshotFixtures::envelope()->private_facts() ];
		$raw = json_encode( $line, JSON_THROW_ON_ERROR ); self::assertStringContainsString( '"rates":[]', $raw );
		$item = $this->item( str_replace( '"rates":[]', '"rates":{}', $raw ) );
		$read = ( new OrderDeliverySnapshotReader() )->read_line( $item ); self::assertSame( 'version_mismatch', $read->error ); self::assertSame( 'malformed', $read->delivery_quote?->status ); self::assertNull( $read->snapshot );
	}

	private function item( string $raw ): WC_Order_Item_Product { return new WC_Order_Item_Product( [ 'id' => 501, 'product_id' => 16, 'name' => 'Historical widget', 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => $raw ] ] ); }
	private function order( string $raw, WC_Order_Item_Product $item ): WC_Order { return new WC_Order( [ 'id' => 91, 'items' => [ $item ], 'shipping_items' => [ new WC_Order_Item_Shipping( [ 'total' => '12.5000', 'method_id' => 'delivery_engine_selected_offer', 'meta' => [ 'cetech_de_group_id' => 'in_warehouse|delivery|1' ] ] ) ], 'meta' => [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => $raw ] ] ); }
}
