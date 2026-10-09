<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\{CustomerOrderDeliverySummaryBuilder, DeliveryQuoteSnapshotEnvelope, DeliveryQuoteSnapshotReader, OrderDeliverySnapshot, OrderDeliverySnapshotIntegrity, OrderDeliverySnapshotReader, QuoteNativeOrderHistory, RequiredPromiseSnapshotReadiness, SnapshotExtensionParser};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

final class PromiseSnapshotReaderTest extends TestCase {
	public function test_actual_captured_v2_body_produces_exact_new_historical_envelope_without_final_event(): void {
		$context = PromiseHandoffFixture::context(); $terms = PromiseHandoffFixture::terms(); $header = PromiseHandoffFixture::header( $context, $terms );
		$envelope = DeliveryQuoteSnapshotEnvelope::from_captured( $header, $context, $terms, PromiseHandoffFixture::time()->plus_seconds( 1 ), QuoteId::from_string( '00000000-0000-4000-8000-000000000011' ), str_repeat( 'a', 64 ) );
		self::assertTrue( $envelope->is_promise() ); self::assertSame( 2, $envelope->format() ); self::assertSame( $header->body_digest(), $envelope->private_facts()['body_digest'] );
		self::assertSame( $terms->promise_packet()->to_private_json(), $envelope->promise_packet()->to_private_json() ); self::assertSame( $terms->promise_packet()->digest(), $envelope->private_facts()['promise_packet_digest'] );
		self::assertArrayNotHasKey( 'final_event', $envelope->private_facts() ); self::assertArrayNotHasKey( 'accepted_promise_receipt', $envelope->private_facts() ); self::assertTrue( $envelope->matches( DeliveryQuoteSnapshotEnvelope::from_json( $envelope->to_private_json() ) ) );
	}
	#[DataProvider( 'states' )]
	public function test_reader_uses_frozen_original_packet_and_text_without_live_configuration( string $state ): void {
		$envelope = PromiseSnapshotFixtures::envelope( $state ); $line = PromiseSnapshotFixtures::line( $envelope ); $package = PromiseSnapshotFixtures::package( $envelope ); [ $order, $item ] = $this->fixture( $line, $package ); $raw = [ $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ), $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) ];
		$old = $GLOBALS['cetech_de_test_options'] ?? null;
		try {
			$GLOBALS['cetech_de_test_options'] = [ 'timezone_string' => 'Pacific/Auckland', 'date_format' => 'd/m/Y', 'time_format' => 'H:i', 'cetech_de_schema_version' => 999, 'enable_order_delivery_snapshot_persistence' => false ]; $reader = new OrderDeliverySnapshotReader(); $line_read = $reader->read_line( $item ); $package_read = $reader->read_package( $order );
			self::assertSame( '', $line_read->error ); self::assertSame( '', $package_read->error ); self::assertSame( '3', $line_read->snapshot?->snapshot_version ); self::assertSame( $line['delivery_address'], $line_read->snapshot?->toArray()['delivery_address'] ); self::assertTrue( $envelope->matches( $line_read->delivery_quote->envelope ) ); self::assertTrue( QuoteNativeOrderHistory::owned( $order ) ); self::assertTrue( QuoteNativeOrderHistory::verify( $order ) );
			$summary = ( new CustomerOrderDeliverySummaryBuilder( $reader, new OrderDeliverySnapshotIntegrity() ) )->build( $order ); self::assertNotNull( $summary ); self::assertSame( $line['estimate_text'], $summary->lines[0]->estimate_text );
		} finally { $GLOBALS['cetech_de_test_options'] = $old; }
		self::assertSame( $raw, [ $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ), $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) ] );
	}
	public static function states(): iterable { foreach ( [ 'absolute_window', 'relative_window', 'unavailable', 'ineligible' ] as $state ) { yield $state => [ $state ]; } }

	#[DataProvider( 'invalid_outer' )]
	public function test_required_outer_never_downgrades_or_projects_mutated_partial_history( string $case ): void {
		$line = PromiseSnapshotFixtures::line(); $package = PromiseSnapshotFixtures::package(); $marker = '2'; $meta = '3';
		switch ( $case ) {
			case 'missing-packet': unset( $line['delivery_quote'], $package['delivery_quote'] ); break;
			case 'old-envelope': $line['delivery_quote'] = $package['delivery_quote'] = DeliveryQuoteSnapshotFixtures::envelope()->private_facts(); break;
			case 'missing-marker': $marker = null; break;
			case 'old-marker': $marker = '1'; break;
			case 'unknown-marker': $marker = '4'; break;
			case 'metadata-missing': $meta = null; break;
			case 'metadata-old': $meta = '2'; break;
			case 'outer-extra': $line['private_extra'] = $package['private_extra'] = 'SECRET'; break;
			case 'outer-missing': unset( $line['estimate_text'], $package['groups'] ); break;
			case 'tampered-text': $line['estimate_text'] = 'A fabricated updated promise'; break;
			case 'tampered-money': $line['quoted_amount'] = '99.00'; $package['package_total_delivery_amount'] = '99.00'; break;
			case 'foreign-group': $line['delivery_group_id'] = $package['groups'][0]['group_id'] = 'another|delivery|1'; break;
			case 'location-extra': $line['delivery_address']['unexpected'] = 'SECRET'; break;
			case 'recipient-extra': $line['delivery_address']['recipient']['unexpected'] = 'SECRET'; break;
			case 'integer-string': $line['quantity'] = '2'; break;
			case 'package-duplicate': $package['groups'][] = $package['groups'][0]; break;
			case 'future-format': $line['delivery_quote']['format'] = $package['delivery_quote']['format'] = 9; break;
			case 'future-outer': $line['snapshot_version'] = $package['snapshot_version'] = '9'; $meta = '9'; break;
		}
		[ $order, $item ] = $this->fixture( $line, $package, $marker, $meta ); $reader = new OrderDeliverySnapshotReader(); $a = $reader->read_line( $item ); $b = $reader->read_package( $order );
		self::assertTrue( '' !== $a->error || '' !== $b->error ); self::assertTrue( QuoteNativeOrderHistory::owned( $order ) ); self::assertFalse( QuoteNativeOrderHistory::verify( $order ) ); self::assertNull( ( new CustomerOrderDeliverySummaryBuilder( $reader, new OrderDeliverySnapshotIntegrity() ) )->build( $order ) );
	}
	public static function invalid_outer(): iterable { foreach ( [ 'missing-packet', 'old-envelope', 'missing-marker', 'old-marker', 'unknown-marker', 'metadata-missing', 'metadata-old', 'outer-extra', 'outer-missing', 'tampered-text', 'tampered-money', 'foreign-group', 'location-extra', 'recipient-extra', 'integer-string', 'package-duplicate', 'future-format', 'future-outer' ] as $case ) { yield $case => [ $case ]; } }

	public function test_new_readiness_does_not_repurpose_the_optional_c05_contract_or_enable_writes(): void {
		self::assertTrue( RequiredPromiseSnapshotReadiness::supports( '3', 2, DeliveryQuoteSnapshotEnvelope::PROMISE_PROFILE, 1 ) );
		foreach ( [ [ '2', 2, DeliveryQuoteSnapshotEnvelope::PROMISE_PROFILE, 1 ], [ '3', 1, DeliveryQuoteSnapshotEnvelope::PROFILE, 1 ], [ '3', 2, DeliveryQuoteSnapshotEnvelope::PROMISE_PROFILE, 2 ], [ '3', 2, DeliveryQuoteSnapshotEnvelope::PROMISE_PROFILE + [ 'enabled' => true ], 1 ] ] as $args ) { self::assertFalse( RequiredPromiseSnapshotReadiness::supports( ...$args ) ); }
		self::assertTrue( RequiredPromiseSnapshotReadiness::contract()['reader_only'] ); $set = ( new SnapshotExtensionParser() )->read( [ 'extensions' => [ 'promise' => [ 'version' => 1, 'required' => true, 'data' => [] ] ] ] ); self::assertFalse( $set->required_semantics_supported() ); self::assertSame( 'unsupported_required', $set->get( 'promise' )->status );
	}
	public function test_known_envelope_requires_exact_new_outer_and_marker_and_rejects_self_referential_receipts(): void {
		$packet = PromiseSnapshotFixtures::envelope()->private_facts(); $reader = new DeliveryQuoteSnapshotReader(); self::assertSame( 'recorded', $reader->read( [ 'snapshot_version' => '3', 'delivery_quote' => $packet ], true, '2' )->status );
		foreach ( [ [ 'delivery_quote' => $packet ], [ 'snapshot_version' => '2', 'delivery_quote' => $packet ] ] as $outer ) { self::assertFalse( $reader->read( $outer, true, '2' )->supported() ); }
		$packet['accepted_promise_receipt'] = [ 'snapshot_digest' => str_repeat( '0', 64 ) ]; self::assertSame( 'malformed', $reader->read( [ 'snapshot_version' => '3', 'delivery_quote' => $packet ], true, '2' )->status );
	}
	public function test_duplicate_nested_json_and_object_list_substitution_leave_bytes_untouched(): void {
		$raw = json_encode( PromiseSnapshotFixtures::line(), JSON_THROW_ON_ERROR );
		$object = json_decode( $raw, false, 32, JSON_THROW_ON_ERROR ); $views = $object->delivery_quote->promise_packet->groups[0]->packet->public_views; $object->delivery_quote->promise_packet->groups[0]->packet->public_views = (object) [ '0' => $views[0] ];
		foreach ( [ substr( $raw, 0, -1 ) . ',"snapshot_version":"3"}', str_replace( '"rates":[]', '"rates":{}', $raw ), json_encode( $object, JSON_THROW_ON_ERROR ) ] as $bad ) {
			$item = new \WC_Order_Item_Product( [ 'id' => 501, 'product_id' => 16, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => $bad, OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '3', DeliveryQuoteSnapshotEnvelope::META_FORMAT => '2' ] ] ); $read = ( new OrderDeliverySnapshotReader() )->read_line( $item ); self::assertNotSame( '', $read->error ); self::assertNull( $read->snapshot ); self::assertSame( $bad, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ) );
		}
	}
	public function test_future_and_corrupted_new_outer_remains_owned_without_marker_or_member(): void {
		foreach ( [ '{"snapshot_version":"3","broken":', '{"snapshot_vers\\u0069on":"999","broken":', '{"snapshot_version":"9"}' ] as $raw ) {
			$order = new \WC_Order( [ 'id' => 91, 'meta' => [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => $raw ] ] ); self::assertTrue( QuoteNativeOrderHistory::owned( $order ) ); self::assertFalse( QuoteNativeOrderHistory::verify( $order ) ); self::assertNull( ( new CustomerOrderDeliverySummaryBuilder( new OrderDeliverySnapshotReader(), new OrderDeliverySnapshotIntegrity() ) )->build( $order ) ); self::assertSame( $raw, $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) );
		}
	}
	public function test_new_unknown_or_corrupted_outer_metadata_alone_preserves_ownership(): void {
		foreach ( [ '3', '99', 'future', null ] as $version ) {
			$order = new \WC_Order( [ 'id' => 91, 'meta' => [ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => $version ] ] );
			self::assertTrue( QuoteNativeOrderHistory::owned( $order ) ); self::assertFalse( QuoteNativeOrderHistory::verify( $order ) ); self::assertNull( ( new CustomerOrderDeliverySummaryBuilder( new OrderDeliverySnapshotReader(), new OrderDeliverySnapshotIntegrity() ) )->build( $order ) );
		}
		foreach ( [ '1', '2', 1, 2 ] as $version ) {
			$order = new \WC_Order( [ 'id' => 91, 'meta' => [ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => $version ] ] ); self::assertFalse( QuoteNativeOrderHistory::owned( $order ) );
		}
	}
	public function test_new_outer_overflow_refuses_without_truncating_saved_history(): void {
		$line = PromiseSnapshotFixtures::line(); $line['delivery_offer_public_description'] = str_repeat( 'x', 65536 ); $package = PromiseSnapshotFixtures::package(); [ $order, $item ] = $this->fixture( $line, $package ); $raw = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ); self::assertGreaterThan( 65536, strlen( $raw ) ); $read = ( new OrderDeliverySnapshotReader() )->read_line( $item ); self::assertNotSame( '', $read->error ); self::assertNull( $read->snapshot ); self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ) );
	}
	private function fixture( array $line, array $package, ?string $marker = '2', ?string $meta = '3' ): array {
		$line_meta = [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => json_encode( $line, JSON_THROW_ON_ERROR ) ]; $order_meta = [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => json_encode( $package, JSON_THROW_ON_ERROR ) ];
		if ( null !== $marker ) { $line_meta[DeliveryQuoteSnapshotEnvelope::META_FORMAT] = $order_meta[DeliveryQuoteSnapshotEnvelope::META_FORMAT] = $marker; } if ( null !== $meta ) { $line_meta[OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION] = $order_meta[OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION] = $meta; }
		$item = new \WC_Order_Item_Product( [ 'id' => 501, 'product_id' => 16, 'name' => 'Historical widget', 'meta' => $line_meta ] ); $order = new \WC_Order( [ 'id' => 91, 'items' => [ $item ], 'meta' => $order_meta ] ); return [ $order, $item ];
	}
}
