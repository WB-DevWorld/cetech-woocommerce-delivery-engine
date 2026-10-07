<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreValuesTest extends TestCase {
	public function test_server_identifiers_are_uuid4_and_public_retry_shape_is_only_two_fields(): void {
		$a = QuoteId::generate(); $b = QuoteId::generate(); self::assertFalse( $a->equals( $b ) ); self::assertTrue( $a->equals( QuoteId::from_string( $a->value() ) ) );
		$ref = QuoteReference::generate( $a ); self::assertSame( [ 'quote_id', 'acceptance_handle' ], array_keys( $ref->public_fields() ) ); self::assertSame( 64, strlen( $ref->handle() ) ); self::assertSame( $ref->public_fields(), QuoteReference::from_array( $ref->public_fields() )->public_fields() ); self::assertTrue( $ref->id()->equals( $a ) );
	}
	#[DataProvider( 'invalid_ids' )]
	public function test_noncanonical_or_non_v4_ids_refuse( string $value ): void { $this->expectException( \InvalidArgumentException::class ); QuoteId::from_string( $value ); }
	public static function invalid_ids(): array { return [ [ '550e8400-e29b-11d4-a716-446655440000' ], [ '550E8400-E29B-41D4-A716-446655440000' ], [ '550e8400-e29b-41d4-0716-446655440000' ], [ '550e8400-e29b-41d4-a716-446655440000 ' ], [ 'not-a-quote' ] ]; }
	public function test_owner_identity_includes_site_customer_session_and_key_epoch(): void {
		$data = [ 'site_id' => 1, 'kind' => 'guest', 'principal_hash' => str_repeat( 'a', 64 ), 'session_hash' => str_repeat( 'b', 64 ), 'key_epoch' => 'test_key_1' ]; $owner = QuoteOwner::from_array( $data ); self::assertTrue( $owner->equals( QuoteOwner::from_array( array_reverse( $data, true ) ) ) );
		foreach ( [ 'site_id' => 2, 'kind' => 'customer', 'principal_hash' => str_repeat( 'c', 64 ), 'session_hash' => str_repeat( 'd', 64 ), 'key_epoch' => 'test_key_2' ] as $field => $value ) { $other = QuoteOwner::from_array( array_replace( $data, [ $field => $value ] ) ); self::assertFalse( $owner->equals( $other ) ); self::assertNotSame( $owner->digest(), $other->digest() ); }
		$data['session_hash'] = str_repeat( 'e', 64 ); self::assertSame( str_repeat( 'b', 64 ), $owner->facts()['session_hash'] );
	}
	public function test_utc_microseconds_are_exact_before_and_after_epoch(): void {
		$time = QuoteTime::parse( '2026-10-07 04:30:00.000001' ); self::assertSame( '2026-10-07T04:30:00.000001Z', $time->iso_utc() ); self::assertTrue( $time->equals( QuoteTime::from_epoch_microseconds( $time->epoch_microseconds() ) ) ); self::assertSame( -1, $time->compare( $time->plus_seconds( 1 ) ) );
		self::assertSame( '1969-12-31 23:59:59.999999', QuoteTime::from_epoch_microseconds( -1 )->sql() ); self::assertSame( -1, QuoteTime::from_epoch_microseconds( -1 )->epoch_microseconds() );
	}
	#[DataProvider( 'invalid_times' )]
	public function test_noncanonical_or_impossible_instants_refuse( string $value ): void { $this->expectException( \InvalidArgumentException::class ); QuoteTime::parse( $value ); }
	public static function invalid_times(): array { return [ [ '2026-02-30 00:00:00.000000' ], [ '2026-10-07T04:30:00.000000Z' ], [ '2026-10-07 04:30:00' ], [ '2026-10-07 04:30:00.000000+00:00' ], [ '0000-01-01 00:00:00.000000' ], [ '2026-10-07 24:00:00.000000' ] ]; }
	public function test_money_is_exact_and_common_precision_validation_does_not_use_floats(): void {
		$a = QuoteMoney::from_array( [ 'amount' => '12.3', 'currency' => 'GHS', 'precision' => 2 ] ); $b = QuoteMoney::from_array( [ 'amount' => '0.07', 'currency' => 'GHS', 'precision' => 2 ] );
		self::assertSame( '12.30', $a->amount() ); self::assertSame( '12.37', $a->add( $b )->amount() ); self::assertSame( '12.23', $a->subtract( $b )->amount() ); self::assertSame( [ 'amount' => '12.30', 'currency' => 'GHS', 'precision' => 2 ], $a->facts() );
		self::assertSame( '999999999999.999999', QuoteMoney::from_array( [ 'amount' => '999999999999.999999', 'currency' => 'USD', 'precision' => 6 ] )->amount() ); self::assertTrue( QuoteMoney::from_array( [ 'amount' => '0', 'currency' => 'JPY', 'precision' => 0 ] )->zero() );
	}
	#[DataProvider( 'invalid_money' )]
	public function test_ambiguous_nonfinite_negative_or_overflow_money_refuses( mixed $amount, mixed $currency = 'GHS', mixed $precision = 2 ): void { $this->expectException( \InvalidArgumentException::class ); QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $currency, 'precision' => $precision ] ); }
	public static function invalid_money(): array { return [ [ 1.0 ], [ 1 ], [ null ], [ NAN ], [ INF ], [ '-1' ], [ '+1' ], [ '1e2' ], [ '01' ], [ '.1' ], [ '1.' ], [ '1,00' ], [ ' 1' ], [ '1.001' ], [ '1000000000000' ], [ '1', 'ghs' ], [ '1', '' ], [ '1', 'GHSS' ], [ '1', 'GHS', '2' ], [ '1', 'GHS', 7 ], [ '1', 'GHS', -1 ] ]; }
	public function test_cross_currency_arithmetic_cannot_masquerade_as_conversion(): void { $a = QuoteMoney::from_array( [ 'amount' => '1', 'currency' => 'USD', 'precision' => 2 ] ); $this->expectException( \InvalidArgumentException::class ); $a->add( QuoteMoney::from_array( [ 'amount' => '1', 'currency' => 'GHS', 'precision' => 2 ] ) ); }
	public function test_money_overflow_after_addition_refuses(): void { $a = QuoteMoney::from_array( [ 'amount' => '999999999999.99', 'currency' => 'GHS', 'precision' => 2 ] ); $this->expectException( \InvalidArgumentException::class ); $a->add( QuoteMoney::from_array( [ 'amount' => '0.01', 'currency' => 'GHS', 'precision' => 2 ] ) ); }
	public function test_private_owner_refuses_generic_json_serialization(): void { $this->expectException( \LogicException::class ); json_encode( QuoteOwner::from_array( [ 'site_id' => 1, 'kind' => 'guest', 'principal_hash' => str_repeat( 'a', 64 ), 'session_hash' => str_repeat( 'b', 64 ), 'key_epoch' => '1' ] ), JSON_THROW_ON_ERROR ); }
}
