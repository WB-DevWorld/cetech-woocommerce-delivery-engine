<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotProjection;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotReader;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotReadResult;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotReadiness;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliveryQuoteSnapshotTest extends TestCase {
	public function test_exact_packet_detaches_and_projects_only_captured_customer_money(): void {
		$packet = DeliveryQuoteSnapshotFixtures::envelope(); $facts = $packet->private_facts();
		self::assertCount( 12, $facts ); self::assertSame( 1, $facts['format'] );
		$read = ( new DeliveryQuoteSnapshotReader() )->read( [ 'delivery_quote' => $facts ], true, '1' );
		self::assertSame( 'recorded', $read->status ); $public = DeliveryQuoteSnapshotProjection::for_customer( $read );
		self::assertSame( [ 'status', 'groups' ], array_keys( $public ) );
		self::assertSame( 'Fixture delivery', $public['groups'][0]['customer_label'] );
		self::assertSame( '12.50', $public['groups'][0]['total']['amount'] );
		self::assertSame( '0.00', $public['groups'][0]['rounded_tax']['amount'] );
		$encoded = json_encode( $public, JSON_THROW_ON_ERROR );
		foreach ( [ $facts['quote_id'], $facts['placement_id'], $facts['body_digest'], $facts['material_digest'], $facts['context_digest'], $facts['money_receipt']['groups'][0]['component_key'], 'legacy_fixed_base_v1', 'cost_provider_unavailable', 'tax_class', 'rates' ] as $private ) { self::assertStringNotContainsString( $private, $encoded ); }
		$facts['money_receipt']['groups'][0]['customer_label'] = 'MUTATED';
		self::assertSame( 'Fixture delivery', $packet->private_facts()['money_receipt']['groups'][0]['customer_label'] );
	}

	#[DataProvider( 'invalid_packets' )]
	public function test_malformed_nested_or_unknown_packet_never_projects_partial_money( string $kind ): void {
		$data = DeliveryQuoteSnapshotFixtures::envelope()->private_facts();
		switch ( $kind ) {
			case 'unknown-root': $data['private_internal'] = 'SECRET'; break;
			case 'missing-root': unset( $data['accepted_at'] ); break;
			case 'unknown-profile': $data['profile']['code'] = 'arbitrary'; break;
			case 'profile-version': $data['profile']['version'] = 2; break;
			case 'upper-uuid': $data['quote_id'] = 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA'; break;
			case 'non-v4-placement': $data['placement_id'] = '22222222-2222-1222-8222-222222222222'; break;
			case 'bad-hash': $data['context_digest'] = str_repeat( 'A', 64 ); break;
			case 'accept-before': $data['accepted_at'] = '2026-10-07 04:59:59.999999'; break;
			case 'accept-expiry': $data['accepted_at'] = $data['expires_at']; break;
			case 'extended-expiry': $data['expires_at'] = '2026-10-07 05:05:00.000001'; break;
			case 'noncanonical-time': $data['issued_at'] = '2026-10-07T05:00:00Z'; break;
			case 'unknown-money': $data['money_receipt']['groups'][0]['renamed_cost'] = 'SECRET'; break;
			case 'float-money': $data['money_receipt']['groups'][0]['final']['amount'] = 12.5; break;
			case 'missing-money': unset( $data['money_receipt']['groups'][0]['final'] ); break;
			case 'foreign-currency': $data['money_receipt']['groups'][0]['total']['currency'] = 'USD'; break;
			case 'discount-unknown': $data['money_receipt']['groups'][0]['promotion']['state'] = 'unavailable'; break;
			case 'display-tax-confusion': $data['money_receipt']['groups'][0]['display_total'] = $data['money_receipt']['groups'][0]['rounded_tax']; break;
			case 'unknown-provenance': $data['provenance_receipt']['groups'][0]['raw_provider'] = [ 'private_address' => 'SECRET' ]; break;
			case 'foreign-group': $data['provenance_receipt']['groups'][0]['component_key'] = str_repeat( 'a', 64 ); break;
			case 'duplicate-group': $data['money_receipt']['groups'][] = $data['money_receipt']['groups'][0]; break;
			case 'missing-source': $data['provenance_receipt']['groups'] = []; break;
			case 'cost-zero-inference': $data['provenance_receipt']['groups'][0]['cost'] = [ 'state' => 'known', 'amount' => $data['money_receipt']['groups'][0]['tax'] ]; break;
			case 'tax-unavailable': $data['provenance_receipt']['groups'][0]['native_tax_receipt'] = [ 'state' => 'not_recorded' ]; break;
			case 'money-unavailable': $data['provenance_receipt']['groups'][0]['native_money_receipt'] = [ 'state' => 'not_recorded' ]; break;
			case 'tax-extra': $data['provenance_receipt']['groups'][0]['native_tax_receipt']['private_address'] = 'SECRET'; break;
			case 'invalid-zero-tax': $data['provenance_receipt']['groups'][0]['native_tax_receipt']['rates'] = [ [ 'rate_id' => 1, 'amount' => $data['money_receipt']['groups'][0]['total'] ] ]; break;
			case 'oversized': $data['money_receipt']['groups'][0]['customer_label'] = str_repeat( 'x', 65537 ); break;
			case 'deep-private': $v = 'SECRET'; for ( $i = 0; $i < 20; ++$i ) { $v = [ 'nested' => $v ]; } $data['private'] = $v; break;
		}
		$read = ( new DeliveryQuoteSnapshotReader() )->read( [ 'delivery_quote' => $data ] );
		self::assertFalse( $read->supported() ); self::assertNull( $read->envelope );
		self::assertSame( [], DeliveryQuoteSnapshotProjection::for_customer( $read )['groups'] );
	}
	public static function invalid_packets(): iterable {
		foreach ( [ 'unknown-root', 'missing-root', 'unknown-profile', 'profile-version', 'upper-uuid', 'non-v4-placement', 'bad-hash', 'accept-before', 'accept-expiry', 'extended-expiry', 'noncanonical-time', 'unknown-money', 'float-money', 'missing-money', 'foreign-currency', 'discount-unknown', 'display-tax-confusion', 'unknown-provenance', 'foreign-group', 'duplicate-group', 'missing-source', 'cost-zero-inference', 'tax-unavailable', 'money-unavailable', 'tax-extra', 'invalid-zero-tax', 'oversized', 'deep-private' ] as $kind ) { yield $kind => [ $kind ]; }
	}

	public function test_zero_is_real_money_and_unknown_money_is_never_zero(): void {
		$data = DeliveryQuoteSnapshotFixtures::envelope()->private_facts();
		foreach ( [ 'list', 'final', 'tax', 'total', 'rounded_tax', 'display_total' ] as $field ) { $data['money_receipt']['groups'][0][$field]['amount'] = '0.00'; }
		$data['provenance_receipt']['groups'][0]['native_money_receipt']['native_total']['amount'] = '0.00';
		$data['provenance_receipt']['groups'][0]['native_money_receipt']['display_total']['amount'] = '0.00';
		$read = ( new DeliveryQuoteSnapshotReader() )->read( [ 'delivery_quote' => $data ] );
		self::assertSame( 'recorded', $read->status ); self::assertSame( '0.00', DeliveryQuoteSnapshotProjection::for_customer( $read )['groups'][0]['total']['amount'] );
	}

	public function test_packet_json_duplicate_members_and_list_roots_refuse(): void {
		$raw = DeliveryQuoteSnapshotFixtures::envelope()->to_private_json();
		foreach ( [ substr( $raw, 0, -1 ) . ',"format":1}', '[{}]', str_replace( '"rates":[]', '"rates":{}', $raw ) ] as $invalid ) {
			try { DeliveryQuoteSnapshotEnvelope::from_json( $invalid ); self::fail( 'Malformed protected JSON was accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
	}

	public function test_generic_private_carrier_serialization_refuses(): void {
		$packet = DeliveryQuoteSnapshotFixtures::envelope(); $read = new DeliveryQuoteSnapshotReadResult( 'recorded', $packet );
		foreach ( [ $packet, $read ] as $value ) { foreach ( [ 'json', 'php' ] as $format ) {
			try { 'json' === $format ? json_encode( $value, JSON_THROW_ON_ERROR ) : serialize( $value ); self::fail( 'Private facts serialized.' ); } catch ( \LogicException $e ) { self::assertStringNotContainsString( $packet->private_facts()['quote_id'], $e->getMessage() ); }
		} }
	}

	public function test_marker_missing_empty_unknown_or_present_packet_are_distinct(): void {
		$reader = new DeliveryQuoteSnapshotReader();
		self::assertSame( 'not_recorded', $reader->read( [] )->status );
		self::assertSame( 'missing', $reader->read( [], true, '1' )->status );
		foreach ( [ null, '', false, '01', 2, [] ] as $marker ) { self::assertSame( 'unsupported', $reader->read( [], true, $marker )->status ); }
		foreach ( [ null, false, [], '' ] as $packet ) { self::assertSame( 'malformed', $reader->read( [ 'delivery_quote' => $packet ] )->status ); }
		self::assertSame( 'unsupported', $reader->read( [ 'delivery_quote' => [ 'format' => 2 ] ] )->status );
	}

	public function test_compiled_readiness_cannot_claim_a_writer_or_unknown_profile(): void {
		self::assertTrue( DeliveryQuoteSnapshotReadiness::supports( 1, DeliveryQuoteSnapshotEnvelope::PROFILE ) );
		self::assertFalse( DeliveryQuoteSnapshotReadiness::supports( '1', DeliveryQuoteSnapshotEnvelope::PROFILE ) );
		self::assertFalse( DeliveryQuoteSnapshotReadiness::supports( 2, DeliveryQuoteSnapshotEnvelope::PROFILE ) );
		self::assertFalse( DeliveryQuoteSnapshotReadiness::supports( 1, [ 'code' => 'future', 'version' => 1 ] ) );
		self::assertFalse( DeliveryQuoteSnapshotReadiness::supports( 1, DeliveryQuoteSnapshotEnvelope::PROFILE + [ 'writer_enabled' => true ] ) );
		self::assertSame( true, DeliveryQuoteSnapshotReadiness::contract()['reader_only'] );
	}
}
