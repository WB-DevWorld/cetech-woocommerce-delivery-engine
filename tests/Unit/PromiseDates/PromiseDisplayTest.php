<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PromiseDates;

use CetechDeliveryEngine\Application\PromiseDates\NullPromiseDateProvider;
use CetechDeliveryEngine\Application\PromiseDates\PromiseDateRequest;
use CetechDeliveryEngine\Application\PromiseDates\PromiseDisplay;
use PHPUnit\Framework\TestCase;

/**
 * The M3 promise display contract (docs/m3-m8/LANE-PLAN.md §3): calendar days in one named
 * zone, a stable array shape for snapshots, and strict refusal of anything malformed.
 */
final class PromiseDisplayTest extends TestCase {

	public function test_window_keeps_calendar_days_and_round_trips_through_the_snapshot_shape(): void {
		$promise = PromiseDisplay::window( '2026-12-01', '2026-12-03', 'Africa/Accra', 'promise-rule:12:v3' );

		self::assertFalse( $promise->is_single_day() );
		self::assertSame( [ 'format' => 1, 'from' => '2026-12-01', 'to' => '2026-12-03', 'timezone' => 'Africa/Accra', 'rule' => 'promise-rule:12:v3' ], $promise->to_array() );
		self::assertEquals( $promise, PromiseDisplay::from_array( $promise->to_array() ) );
		self::assertSame( '2026-12-01 00:00:00 Africa/Accra', $promise->from()->format( 'Y-m-d H:i:s e' ) );
		self::assertSame( '2026-12-03', $promise->to()->format( 'Y-m-d' ) );
	}

	public function test_single_day_window(): void {
		self::assertTrue( PromiseDisplay::window( '2026-12-02', '2026-12-02', 'UTC', 'site-wide:1' )->is_single_day() );
	}

	/**
	 * @return iterable<string, array{string, string, string, string}>
	 */
	public static function invalid_windows(): iterable {
		yield 'end before start' => [ '2026-12-04', '2026-12-02', 'UTC', 'rule:1' ];
		yield 'not a calendar day' => [ '2026-02-30', '2026-03-01', 'UTC', 'rule:1' ];
		yield 'not Y-m-d' => [ '2/12/2026', '2026-12-04', 'UTC', 'rule:1' ];
		yield 'unknown zone' => [ '2026-12-02', '2026-12-04', 'Mars/Olympus', 'rule:1' ];
		yield 'empty rule reference' => [ '2026-12-02', '2026-12-04', 'UTC', '' ];
		yield 'markup in rule reference' => [ '2026-12-02', '2026-12-04', 'UTC', '<b>rule</b>' ];
	}

	/**
	 * @dataProvider invalid_windows
	 */
	public function test_malformed_windows_are_refused( string $from, string $to, string $zone, string $rule ): void {
		$this->expectException( \InvalidArgumentException::class );
		PromiseDisplay::window( $from, $to, $zone, $rule );
	}

	/**
	 * @return iterable<string, array{array<mixed>}>
	 */
	public static function invalid_arrays(): iterable {
		$valid = [ 'format' => 1, 'from' => '2026-12-01', 'to' => '2026-12-03', 'timezone' => 'UTC', 'rule' => 'r:1' ];
		yield 'unknown format' => [ [ 'format' => 2 ] + $valid ];
		yield 'missing key' => [ array_diff_key( $valid, [ 'rule' => true ] ) ];
		yield 'extra key' => [ $valid + [ 'supplier' => 'x' ] ];
		yield 'reordered keys' => [ [ 'from' => '2026-12-01', 'format' => 1, 'to' => '2026-12-03', 'timezone' => 'UTC', 'rule' => 'r:1' ] ];
		yield 'non-string day' => [ [ 'format' => 1, 'from' => 20261201, 'to' => '2026-12-03', 'timezone' => 'UTC', 'rule' => 'r:1' ] ];
	}

	/**
	 * @dataProvider invalid_arrays
	 * @param array<mixed> $data
	 */
	public function test_unknown_snapshot_shapes_are_refused( array $data ): void {
		$this->expectException( \InvalidArgumentException::class );
		PromiseDisplay::from_array( $data );
	}

	public function test_request_validates_its_server_built_facts(): void {
		$request = new PromiseDateRequest( 10, 0, 'delivery', 3, 7, null, 'in_warehouse', new \DateTimeImmutable( '2026-12-01 09:00:00' ) );
		self::assertFalse( $request->is_pickup() );
		self::assertTrue( ( new PromiseDateRequest( 10, 11, 'store_pickup', null, null, null, null, new \DateTimeImmutable() ) )->is_pickup() );

		foreach ( [
			static fn () => new PromiseDateRequest( 0, 0, 'delivery', null, null, null, null, new \DateTimeImmutable() ),
			static fn () => new PromiseDateRequest( 10, -1, 'delivery', null, null, null, null, new \DateTimeImmutable() ),
			static fn () => new PromiseDateRequest( 10, 0, 'air', null, null, null, null, new \DateTimeImmutable() ),
			static fn () => new PromiseDateRequest( 10, 0, 'delivery', 0, null, null, null, new \DateTimeImmutable() ),
			static fn () => new PromiseDateRequest( 10, 0, 'delivery', null, null, null, 'In Store!', new \DateTimeImmutable() ),
			static fn () => new PromiseDateRequest( 10, 0, 'delivery', null, null, null, null, new \DateTimeImmutable(), 0 ),
		] as $invalid ) {
			try {
				$invalid();
				self::fail( 'A malformed promise request was accepted.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
		}
	}

	public function test_null_provider_never_promises(): void {
		self::assertNull( ( new NullPromiseDateProvider() )->promise_for( new PromiseDateRequest( 10, 0, 'delivery', 3, 7, null, null, new \DateTimeImmutable() ) ) );
	}
}
