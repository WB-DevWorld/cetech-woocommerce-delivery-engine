<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson,PromiseLimits};
use PHPUnit\Framework\TestCase;

final class PromiseJsonTest extends TestCase {
	public function test_canonical_keys_preserve_ordered_lists_and_detach_references(): void {
		$source = [ 'z' => 2, 'a' => [ 'z' => 3, 'a' => 1 ], 'ordered' => [ 'b', 'a' ] ]; $alias =& $source['a']['a']; $detached = PromiseJson::detach( $source ); $alias = 9;
		self::assertSame( '{"a":{"a":1,"z":3},"ordered":["b","a"],"z":2}', PromiseJson::encode( $detached ) ); self::assertSame( [ 'b', 'a' ], $detached['ordered'] );
		self::assertSame( PromiseJson::encode( [ 'b' => 2, 'a' => 1 ] ), PromiseJson::encode( [ 'a' => 1, 'b' => 2 ] ) );
	}
	/** @dataProvider invalid_json */
	public function test_inexact_json_shape_duplicate_keys_and_scalar_types_refuse( string $json ): void { $this->expectException( \InvalidArgumentException::class ); PromiseJson::decode( $json ); }
	public static function invalid_json(): array {
		return [ [ '{}' ], [ '[]' ], [ '{"value":1,"value":2}' ], [ '{"value":1,"\\u0076alue":2}' ], [ '{"outer":{"a":1,"\\u0061":2}}' ], [ '{"value":1.0}' ], [ '{"value":1e0}' ], [ '{"value":9223372036854775808}' ], [ '{"items":{}}' ], [ '{"items":{"0":"a","1":"b"}}' ], [ '{"items":{"\\u0030":"a"}}' ], [ '{"value":1} trailing' ] ];
	}
	public function test_byte_limits_accept_exact_boundary_and_refuse_plus_one(): void {
		$facts = [ 'x' => str_repeat( 'a', PromiseLimits::RECORD_BYTES - 8 ) ]; $json = PromiseJson::encode( $facts, PromiseLimits::RECORD_BYTES ); self::assertSame( PromiseLimits::RECORD_BYTES, strlen( $json ) ); self::assertSame( $facts, PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) );
		$this->expectException( \InvalidArgumentException::class ); PromiseJson::encode( [ 'x' => $facts['x'] . 'a' ], PromiseLimits::RECORD_BYTES );
	}
	public function test_decode_checks_raw_record_bytes_before_whitespace_normalization(): void { $this->expectException( \InvalidArgumentException::class ); PromiseJson::decode( str_repeat( ' ', PromiseLimits::RECORD_BYTES ) . '{"x":1}', PromiseLimits::RECORD_BYTES ); }
	public function test_packet_limits_and_retained_quote_budgets_are_independent(): void {
		self::assertSame( 65536, PromiseLimits::PACKET_BYTES ); self::assertSame( 16, QuoteJson::MAX_DEPTH ); self::assertSame( 4096, QuoteJson::MAX_NODES );
		$facts = [ 'items' => array_fill( 0, PromiseJson::MAX_NODES - 2, 0 ) ]; self::assertSame( $facts, PromiseJson::decode( PromiseJson::encode( $facts ) ) );
		$this->expectException( \InvalidArgumentException::class ); PromiseJson::encode( [ 'items' => array_fill( 0, PromiseJson::MAX_NODES - 1, 0 ) ] );
	}
	public function test_depth_is_bounded_at_and_beyond_exact_limit(): void {
		$nested = 0; for ( $i = 0; $i < PromiseJson::MAX_DEPTH - 1; ++$i ) { $nested = [ $nested ]; } self::assertSame( [ 'nested' => $nested ], PromiseJson::decode( PromiseJson::encode( [ 'nested' => $nested ] ) ) );
		$this->expectException( \InvalidArgumentException::class ); PromiseJson::encode( [ 'nested' => [ $nested ] ] );
	}
	public function test_arbitrary_serializer_is_not_invoked(): void {
		$value = new class implements \JsonSerializable { public bool $called = false; public function jsonSerialize(): mixed { $this->called = true; return 'secret'; } };
		try { PromiseJson::encode( [ 'private' => $value ] ); self::fail( 'A custom object must refuse.' ); } catch ( \InvalidArgumentException ) { self::assertFalse( $value->called ); }
	}
}
