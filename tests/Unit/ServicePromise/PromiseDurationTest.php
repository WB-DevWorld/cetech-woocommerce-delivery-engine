<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseDuration;
use PHPUnit\Framework\TestCase;

final class PromiseDurationTest extends TestCase {
	public static function calendar(): array { return [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'dispatch-calendar', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ]; }
	public static function duration( string $unit = 'elapsed_minutes' ): array { return [ 'format_version' => 1, 'min' => 0, 'max' => 0, 'unit' => $unit, 'calendar' => 'elapsed_minutes' === $unit ? null : self::calendar() ]; }
	public function test_explicit_zero_and_distinct_units_have_distinct_immutable_facts(): void {
		$digests = []; foreach ( PromiseDuration::UNITS as $unit ) { $duration = PromiseDuration::from_array( self::duration( $unit ) ); $digests[] = $duration->digest(); self::assertSame( 0, $duration->private_facts()['min'] ); self::assertSame( 0, $duration->private_facts()['max'] ); self::assertSame( $duration->to_private_json(), PromiseDuration::from_json( $duration->to_private_json() )->to_private_json() ); }
		self::assertCount( 4, array_unique( $digests ) );
	}
	public function test_calendar_version_and_source_changes_are_digest_material(): void { $facts = self::duration( 'business_days' ); $original = PromiseDuration::from_array( $facts ); $facts['calendar']['version'] = 2; self::assertNotSame( $original->digest(), PromiseDuration::from_array( $facts )->digest() ); $facts['calendar']['digest'] = str_repeat( 'b', 64 ); self::assertNotSame( $original->digest(), PromiseDuration::from_array( $facts )->digest() ); }
	/** @dataProvider malformed */
	public function test_missing_unknown_reversed_or_coerced_duration_refuses( array $facts ): void { $this->expectException( \InvalidArgumentException::class ); PromiseDuration::from_array( $facts ); }
	public static function malformed(): array {
		$base = self::duration(); $missing = $base; unset( $missing['min'] ); return [ [ array_replace( $base, [ 'min' => '0' ] ) ], [ array_replace( $base, [ 'max' => 0.0 ] ) ], [ array_replace( $base, [ 'min' => -1 ] ) ], [ array_replace( $base, [ 'min' => 1, 'max' => 0 ] ) ], [ array_replace( $base, [ 'unit' => 'days' ] ) ], [ array_replace( $base, [ 'unit' => 'business_days' ] ) ], [ array_replace( $base, [ 'calendar' => self::calendar() ] ) ], [ array_replace( $base, [ 'min' => null ] ) ], [ $missing ], [ $base + [ 'unknown' => 0 ] ] ];
	}
	public function test_read_returns_detached_array_and_json_projection_refuses(): void { $facts = self::duration( 'calendar_days' ); $alias =& $facts['calendar']['version']; $duration = PromiseDuration::from_array( $facts ); $alias = 99; $read = $duration->private_facts(); $read['calendar']['version'] = 88; self::assertSame( 1, $duration->private_facts()['calendar']['version'] ); $this->expectException( \LogicException::class ); json_encode( $duration, JSON_THROW_ON_ERROR ); }
	public function test_native_serialization_does_not_publish_private_facts(): void { $this->expectException( \LogicException::class ); serialize( PromiseDuration::from_array( self::duration() ) ); }
	public function test_native_unserialization_cannot_construct_unvalidated_carrier(): void { $name = PromiseDuration::class; $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $name ) . ':"' . $name . '":0:{}' ); }
}
