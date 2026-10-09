<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseShape;
use PHPUnit\Framework\TestCase;

final class PromiseShapeTest extends TestCase {
	public function test_explicit_scalar_and_time_values_do_not_depend_on_default_timezone(): void {
		$before = date_default_timezone_get(); date_default_timezone_set( 'Pacific/Auckland' );
		try { self::assertSame( '2026-10-09 08:04:41.000001', PromiseShape::instant( '2026-10-09 08:04:41.000001' )->sql() ); self::assertSame( 'Africa/Accra', PromiseShape::timezone( 'Africa/Accra' ) ); self::assertSame( 'UTC', PromiseShape::timezone( 'UTC' ) ); }
		finally { date_default_timezone_set( $before ); }
		self::assertSame( 0, PromiseShape::integer( 0 ) ); self::assertFalse( PromiseShape::boolean( false ) ); self::assertSame( 'merchant:air-01', PromiseShape::id( 'merchant:air-01' ) ); self::assertSame( 'Délai', PromiseShape::text( 'Délai', 20 ) );
	}
	/** @dataProvider malformed_values */
	public function test_coercions_and_inexact_schema_refuse( string $method, array $arguments ): void { $this->expectException( \InvalidArgumentException::class ); PromiseShape::$method( ...$arguments ); }
	public static function malformed_values(): array {
		return [
			[ 'integer', [ '0' ] ], [ 'integer', [ false ] ], [ 'integer', [ 1.0 ] ], [ 'integer', [ -1 ] ], [ 'integer', [ 2, 0, 1 ] ],
			[ 'boolean', [ 1 ] ], [ 'boolean', [ 'false' ] ], [ 'id', [ '' ] ], [ 'id', [ 'with space' ] ], [ 'id', [ str_repeat( 'a', 65 ) ] ],
			[ 'digest', [ str_repeat( 'A', 64 ) ] ], [ 'digest', [ str_repeat( 'a', 63 ) ] ], [ 'text', [ '<b>Express</b>', 120 ] ], [ 'text', [ "two\nlines", 120 ] ], [ 'text', [ "\xff", 120 ] ], [ 'text', [ ' ', 120 ] ],
			[ 'instant', [ '2026-10-09T08:04:41Z' ] ], [ 'instant', [ '2026-02-30 08:04:41.000001' ] ], [ 'instant', [ 1 ] ], [ 'timezone', [ '+01:00' ] ], [ 'timezone', [ 'Unknown/Zone' ] ],
			[ 'fields', [ [ 'known' => 1, 'extra' => 2 ], [ 'known' ] ] ], [ 'fields', [ [ 'known' => 1 ], [ 'known', 'required' ] ] ], [ 'object', [ [] ] ], [ 'list', [ [ 'key' => 1 ] ] ], [ 'list', [ [], 1, 2 ] ], [ 'choice', [ 1, [ '1' ] ] ],
		];
	}
}
