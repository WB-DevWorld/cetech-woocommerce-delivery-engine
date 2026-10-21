<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\DeliveryQuote\NativeCartQuotePreparation;
use CetechDeliveryEngine\Application\Order\{CustomerOrderDeliverySummaryBuilder, DeliveryQuoteSnapshotEnvelope, DeliveryQuoteSnapshotProjection, DeliveryQuoteSnapshotReadiness, DeliveryQuoteSnapshotReader, OrderDeliverySnapshot, OrderDeliverySnapshotIntegrity, OrderDeliverySnapshotReader};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Synthetic forward history only: no order writer, payment event or native collection claim. */
final class FixedPromotionSnapshotReaderTest extends TestCase {
	public function test_captured_commercial_body_projects_only_safe_money_and_references(): void {
		$p = F::mixed_provider(); $h = F::header( $p ); $capture = $p->context()->fixed_promotion_capture();
		$e = DeliveryQuoteSnapshotEnvelope::from_captured( $h, $p->context(), $p->terms(), $h->created_at()->plus_seconds( 1 ), QuoteId::generate(), str_repeat( 'a', 64 ) ); $facts = $e->private_facts();
		self::assertSame( 3, $e->format() ); self::assertTrue( $e->is_fixed_promotion() ); self::assertFalse( $e->is_promise() ); self::assertNull( $e->promise_packet() ); self::assertSame( $capture->digest(), $facts['promotion_capture_digest'] );
		self::assertEquals( [ 'quote_id' => $capture->original_header()->id()->value(), 'body_digest' => $capture->original_header()->body_digest(), 'material_digest' => $capture->original_header()->material_digest() ], $facts['source_quote'] );
		self::assertTrue( $e->matches( DeliveryQuoteSnapshotEnvelope::from_json( $e->to_private_json() ) ) );
		$read = ( new DeliveryQuoteSnapshotReader() )->read( [ 'snapshot_version' => '4', 'delivery_quote' => $facts ], true, '3' ); self::assertSame( 'recorded', $read->status );
		$projection = DeliveryQuoteSnapshotProjection::for_customer( $read );
		foreach ( $projection['groups'] as $group ) { self::assertSame( [ 'amount', 'state' ], array_keys( $group['promotion'] ) ); }
		self::assertEqualsCanonicalizing( [ 'applied', 'none' ], array_column( array_column( $facts['money_receipt']['groups'], 'promotion' ), 'state' ) );
		foreach ( [ 'private_fixed_funder', 'funding_source', 'original_header', 'original_context', 'original_terms', 'promotion_capture', 'eligibility', 'budget', 'provider_context' ] as $secret ) { self::assertStringNotContainsString( '"' . $secret . '"', $e->to_private_json() ); self::assertStringNotContainsString( $secret, json_encode( $projection, JSON_THROW_ON_ERROR ) ); }
		self::assertStringNotContainsString( 'rule_digest', json_encode( $projection, JSON_THROW_ON_ERROR ) ); self::assertStringNotContainsString( 'source_quote', json_encode( $projection, JSON_THROW_ON_ERROR ) );
	}
	#[DataProvider( 'invalid_envelopes' )]
	public function test_strict_codec_refuses_mutation_and_private_fields( string $case ): void {
		$d = self::envelope()->private_facts();
		switch ( $case ) {
			case 'profile': $d['profile']['version'] = 2; break;
			case 'capture': $d['promotion_capture'] = [ 'secret' => 'PRIVATE' ]; break;
			case 'source-extra': $d['source_quote']['provider_context'] = []; break;
			case 'receipt-extra': $d['provenance_receipt']['groups'][0]['economics'] = []; break;
			case 'money-private': $d['money_receipt']['groups'][0]['promotion']['funding_source'] = 'PRIVATE'; break;
			case 'reference-private': $d['provenance_receipt']['groups'][0]['promotion_reference']['rule'] = []; break;
			case 'reference-missing': unset( $d['provenance_receipt']['groups'][0]['promotion_reference']['plan_digest'] ); break;
			case 'wrong-provider': $d['provenance_receipt']['groups'][0]['promotion_provider']['code'] = 'native_no_delivery_promotion_v1'; break;
			case 'none-only': $d['money_receipt']['groups'][0]['promotion']['state'] = 'none'; break;
			case 'money': $d['money_receipt']['groups'][0]['final']['amount'] = '8.0000'; break;
			case 'native-total': $d['provenance_receipt']['groups'][0]['native_money_receipt']['native_total']['amount'] = '20.0000'; break;
			case 'group-missing': $d['provenance_receipt']['groups'] = []; break;
			case 'duplicate': $d['money_receipt']['groups'][] = $d['money_receipt']['groups'][0]; break;
			case 'expired': $d['accepted_at'] = $d['expires_at']; break;
			case 'promise-strip': $d['promise_packet'] = [ 'format' => 1 ]; break;
		}
		self::assertSame( 'malformed', ( new DeliveryQuoteSnapshotReader() )->read( [ 'snapshot_version' => '4', 'delivery_quote' => $d ], true, '3' )->status );
	}
	public static function invalid_envelopes(): iterable { foreach ( [ 'profile', 'capture', 'source-extra', 'receipt-extra', 'money-private', 'reference-private', 'reference-missing', 'wrong-provider', 'none-only', 'money', 'native-total', 'group-missing', 'duplicate', 'expired', 'promise-strip' ] as $case ) { yield $case => [ $case ]; } }

	public function test_outer_four_reads_frozen_final_amount_without_claiming_promise_text(): void {
		[ $line, $package ] = self::snapshots(); [ $order, $item ] = self::objects( $line, $package ); $before = [ $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ), $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) ];
		$r = new OrderDeliverySnapshotReader(); $a = $r->read_line( $item ); $b = $r->read_package( $order );
		self::assertSame( '', $a->error ); self::assertSame( '', $b->error ); self::assertSame( '4', $a->snapshot->snapshot_version ); self::assertSame( $line['delivery_address'], $a->snapshot->toArray()['delivery_address'] );
		self::assertSame( $line['estimate_text'], $a->snapshot->estimate_text ); self::assertSame( $before, [ $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ), $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ) ] );
		self::assertNotNull( ( new CustomerOrderDeliverySummaryBuilder( $r, new OrderDeliverySnapshotIntegrity() ) )->build( $order ) );
	}
	#[DataProvider( 'invalid_outer' )]
	public function test_invalid_outer_never_downgrades_or_changes_saved_bytes( string $case ): void {
		[ $line, $package ] = self::snapshots(); $marker = '3'; $meta = '4';
		switch ( $case ) {
			case 'marker-missing': $marker = null; break;
			case 'marker-old': $marker = '2'; break;
			case 'metadata-missing': $meta = null; break;
			case 'metadata-old': $meta = '3'; break;
			case 'outer-integer': $line['snapshot_version'] = $package['snapshot_version'] = 4; break;
			case 'envelope-missing': unset( $line['delivery_quote'], $package['delivery_quote'] ); break;
			case 'envelope-old': $line['delivery_quote'] = $package['delivery_quote'] = PromiseSnapshotFixtures::envelope()->private_facts(); break;
			case 'label': $line['delivery_offer_public_label'] = $package['groups'][0]['shipping_method_label'] = 'Changed'; break;
			case 'amount': $line['quoted_amount'] = $package['package_total_delivery_amount'] = '12.5000'; break;
			case 'foreign': $line['delivery_group_id'] = $package['groups'][0]['group_id'] = 'foreign|delivery|20'; break;
			case 'extra': $line['private_rule'] = $package['private_rule'] = []; break;
			case 'incomplete': $package['groups'] = []; unset( $line['customer_context_version'] ); break;
			case 'duplicate-group': $package['groups'][] = $package['groups'][0]; $line['quantity'] = '2'; break;
		}
		[ $o, $i ] = self::objects( $line, $package, $marker, $meta ); $raw = $i->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ); $r = new OrderDeliverySnapshotReader();
		self::assertNotSame( '', $r->read_line( $i )->error ); self::assertNotSame( '', $r->read_package( $o )->error ); self::assertSame( $raw, $i->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ) );
	}
	public static function invalid_outer(): iterable { foreach ( [ 'marker-missing', 'marker-old', 'metadata-missing', 'metadata-old', 'outer-integer', 'envelope-missing', 'envelope-old', 'label', 'amount', 'foreign', 'extra', 'incomplete', 'duplicate-group' ] as $case ) { yield $case => [ $case ]; } }
	public function test_duplicate_json_and_object_list_substitution_refuse_and_reader_contract_cannot_enable_writer(): void {
		[ $line, $package ] = self::snapshots(); [ $o, $i ] = self::objects( $line, $package ); $raw = $i->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT );
		$object = json_decode( $raw, false, 32, JSON_THROW_ON_ERROR ); $rates = $object->delivery_quote->provenance_receipt->groups[0]->native_tax_receipt->rates; $object->delivery_quote->provenance_receipt->groups[0]->native_tax_receipt->rates = (object) [ '0' => $rates[0] ];
		foreach ( [ substr( $raw, 0, -1 ) . ',"snapshot_version":"4"}', json_encode( $object, JSON_THROW_ON_ERROR ) ] as $bad ) { $i->update_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $bad ); self::assertNotSame( '', ( new OrderDeliverySnapshotReader() )->read_line( $i )->error ); self::assertSame( $bad, $i->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT ) ); }
		$c = DeliveryQuoteSnapshotReadiness::fixed_promotion_contract(); self::assertTrue( $c['reader_only'] ); self::assertFalse( $c['writer_enabled'] ); self::assertTrue( DeliveryQuoteSnapshotReadiness::supports( 3, DeliveryQuoteSnapshotEnvelope::FIXED_PROMOTION_PROFILE ) ); self::assertFalse( DeliveryQuoteSnapshotReadiness::supports( 3, DeliveryQuoteSnapshotEnvelope::PROMISE_PROFILE ) );
		$e = self::envelope()->private_facts(); foreach ( [ [ 'delivery_quote' => $e ], [ 'snapshot_version' => '3', 'delivery_quote' => $e ], [ 'snapshot_version' => '4', 'delivery_quote' => $e ] ] as $outer ) { self::assertFalse( ( new DeliveryQuoteSnapshotReader() )->read( $outer, true, '2' )->supported() ); }
		self::assertSame( 'unsupported', ( new DeliveryQuoteSnapshotReader() )->read( [ 'snapshot_version' => '4' ] )->status );
	}
	public function test_complete_package_aggregates_mixed_native_scales_by_padding_without_rounding(): void {
		$p = F::mixed_provider(); $h = F::header( $p ); $d = DeliveryQuoteSnapshotEnvelope::from_captured( $h, $p->context(), $p->terms(), $h->created_at()->plus_seconds( 1 ), QuoteId::generate(), str_repeat( 'a', 64 ) )->private_facts();
		// Preserve exact numeric facts while one native group is recorded at its display scale.
		$key = F::second_key();
		foreach ( $d['money_receipt']['groups'] as &$g ) { if ( $key !== $g['component_key'] ) { continue; } foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) { $g[$field]['amount'] = substr( $g[$field]['amount'], 0, -2 ); $g[$field]['precision'] = 2; } $g['promotion']['amount']['amount'] = '0.00'; $g['promotion']['amount']['precision'] = 2; } unset( $g );
		foreach ( $d['provenance_receipt']['groups'] as &$g ) { if ( $key !== $g['component_key'] ) { continue; } foreach ( $g['native_tax_receipt']['rates'] as &$rate ) { $rate['amount']['amount'] = substr( $rate['amount']['amount'], 0, -2 ); $rate['amount']['precision'] = 2; } unset( $rate ); $g['native_money_receipt']['native_total']['amount'] = '15.00'; $g['native_money_receipt']['native_total']['precision'] = 2; } unset( $g );
		$d = DeliveryQuoteSnapshotEnvelope::from_array( $d )->private_facts(); [ $line, $package ] = self::snapshots(); $package['delivery_quote'] = $d; $package['groups'] = []; $package['shipping_method_label'] = 'Multiple deliveries'; $package['package_total_delivery_amount'] = '22.00';
		foreach ( $d['money_receipt']['groups'] as $index => $g ) { $package['groups'][] = [ 'group_id' => F::key() === $g['component_key'] ? 'in_store|delivery|20' : 'second|delivery|20', 'shipping_method_id' => 'delivery_engine_selected_offer', 'shipping_method_label' => $g['customer_label'], 'package_total_delivery_amount' => $g['final']['amount'], 'fulfilment_choice' => 'delivery', 'is_pickup' => false, 'display_index' => $index + 1 ]; }
		[ $order ] = self::objects( $line, $package ); self::assertSame( '', ( new OrderDeliverySnapshotReader() )->read_package( $order )->error );
		foreach ( [ '22.000001', '22.0000001', '21.999999' ] as $bad ) { $package['package_total_delivery_amount'] = $bad; [ $order ] = self::objects( $line, $package ); self::assertNotSame( '', ( new OrderDeliverySnapshotReader() )->read_package( $order )->error ); }
		$package['package_total_delivery_amount'] = '22.00'; array_pop( $package['groups'] ); [ $order ] = self::objects( $line, $package ); self::assertNotSame( '', ( new OrderDeliverySnapshotReader() )->read_package( $order )->error );
	}
	public function test_captured_constructor_requires_the_exact_header_body(): void {
		$p = F::provider(); $h = F::header( $p ); $other = F::mixed_provider();
		$this->expectException( \InvalidArgumentException::class ); DeliveryQuoteSnapshotEnvelope::from_captured( $h, $p->context(), $other->terms(), $h->created_at()->plus_seconds( 1 ), QuoteId::generate(), str_repeat( 'a', 64 ) );
	}
	private static function envelope(): DeliveryQuoteSnapshotEnvelope { $p = F::provider(); $h = F::header( $p ); return DeliveryQuoteSnapshotEnvelope::from_captured( $h, $p->context(), $p->terms(), $h->created_at()->plus_seconds( 1 ), QuoteId::generate(), str_repeat( 'a', 64 ) ); }
	private static function snapshots(): array {
		$d = self::envelope()->private_facts(); $id = 'in_store|delivery|20'; $key = NativeCartQuotePreparation::component_key( $id );
		// Deliberate synthetic reader fixture: no claim that a writer accepted these public bytes.
		foreach ( [ 'money_receipt', 'provenance_receipt' ] as $name ) { $d[$name]['groups'][0]['component_key'] = $key; } $e = DeliveryQuoteSnapshotEnvelope::from_array( $d ); $money = $d['money_receipt']['groups'][0];
		$line = PromiseSnapshotFixtures::line(); $line['snapshot_version'] = '4'; $line['delivery_group_id'] = $id; $line['delivery_offer_public_label'] = $money['customer_label']; $line['quoted_amount'] = $money['final']['amount']; $line['estimate_text'] = 'Historical non-promise note'; $line['delivery_quote'] = $e->private_facts();
		$package = PromiseSnapshotFixtures::package(); $package['snapshot_version'] = '4'; $package['shipping_method_label'] = $package['groups'][0]['shipping_method_label'] = $money['customer_label']; $package['groups'][0]['group_id'] = $id; $package['groups'][0]['package_total_delivery_amount'] = $package['package_total_delivery_amount'] = $money['final']['amount']; $package['delivery_quote'] = $e->private_facts();
		return [ $line, $package ];
	}
	private static function objects( array $line, array $package, ?string $marker = '3', ?string $meta = '4' ): array {
		$lm = [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => json_encode( $line, JSON_THROW_ON_ERROR ) ]; $om = [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT => json_encode( $package, JSON_THROW_ON_ERROR ) ];
		if ( null !== $marker ) { $lm[DeliveryQuoteSnapshotEnvelope::META_FORMAT] = $om[DeliveryQuoteSnapshotEnvelope::META_FORMAT] = $marker; } if ( null !== $meta ) { $lm[OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION] = $om[OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION] = $meta; }
		$i = new \WC_Order_Item_Product( [ 'id' => 501, 'product_id' => 16, 'name' => 'Synthetic history', 'meta' => $lm ] ); return [ new \WC_Order( [ 'id' => 91, 'items' => [ $i ], 'meta' => $om ] ), $i ];
	}
}
