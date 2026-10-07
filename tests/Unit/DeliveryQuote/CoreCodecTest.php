<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreCodecTest extends TestCase {
	public function test_golden_typed_canonical_fixture_preserves_scalar_and_list_types(): void {
		$data = [ 'z' => [ 0, false, null, '0', [] ], 'a' => [ 'second' => 2, 'first' => 1 ] ]; self::assertSame( '{"a":{"first":1,"second":2},"z":[0,false,null,"0",[]]}', QuoteJson::encode( $data ) ); self::assertSame( [ 'a' => [ 'first' => 1, 'second' => 2 ], 'z' => [ 0, false, null, '0', [] ] ], QuoteJson::decode( QuoteJson::encode( $data ) ) );
		self::assertNotSame( QuoteJson::encode( [ 'items' => [ 1, 2 ] ] ), QuoteJson::encode( [ 'items' => [ 2, 1 ] ] ) );
	}
	#[DataProvider( 'invalid_json' )]
	public function test_untrusted_json_refuses_duplicate_unknown_shapes_or_structural_overflow( string $json ): void { $this->expectException( \InvalidArgumentException::class ); QuoteJson::decode( $json ); }
	public static function invalid_json(): array { return [ 'duplicate' => [ '{"format_version":1,"format_version":1}' ], 'escaped_duplicate' => [ '{"format_version":1,"format_\\u0076ersion":1}' ], 'nested_duplicate' => [ '{"object":{"same":0,"same":1}}' ], 'list' => [ '[]' ], 'null' => [ 'null' ], 'float' => [ '{"amount":1.0}' ], 'exponent' => [ '{"amount":1e2}' ], 'oversized' => [ '{"text":"' . str_repeat( 'a', 65536 ) . '"}' ], 'depth' => [ '{"a":' . str_repeat( '[', 17 ) . '1' . str_repeat( ']', 17 ) . '}' ], 'nodes' => [ '{"a":[' . implode( ',', array_fill( 0, 4096, '1' ) ) . ']}' ], 'utf8' => [ '{"text":"' . chr( 255 ) . '"}' ] ]; }
	public function test_detach_breaks_nested_input_references(): void { $nested = [ 'secret' => 'original' ]; $data = [ 'nested' => &$nested ]; $copy = QuoteJson::detach( $data ); $nested['secret'] = 'changed'; self::assertSame( 'original', $copy['nested']['secret'] ); }
	public function test_cyclic_arrays_stop_at_a_finite_budget(): void { $cycle = []; $cycle['cycle'] = &$cycle; $this->expectException( \InvalidArgumentException::class ); QuoteJson::encode( $cycle ); }
	public function test_unsupported_objects_never_invoke_custom_serializers(): void { $value = new class implements \JsonSerializable { public function jsonSerialize(): mixed { throw new \RuntimeException( 'PRIVATE_SERIALIZER_WAS_CALLED' ); } }; $this->expectException( \InvalidArgumentException::class ); $this->expectExceptionMessage( 'Invalid delivery quote facts.' ); QuoteJson::encode( [ 'value' => $value ] ); }
	public function test_resource_and_finite_float_are_not_semantic_facts(): void { $handle = fopen( 'php://memory', 'r' ); try { $this->expectException( \InvalidArgumentException::class ); QuoteJson::encode( [ 'resource' => $handle ] ); } finally { fclose( $handle ); } }
}
