<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Qualification;

use PHPUnit\Framework\TestCase;

/** Protect the authored receipt protocol and its honest pure/native boundaries. */
final class PromiseQualificationBoundsProtocolTest extends TestCase {
	public function test_all_pure_observations_have_exact_unique_inventory_and_measured_closed_fields(): void {
		$root = dirname( __DIR__, 4 ); require_once $root . '/scripts/qualification/promise-calculation-vectors.php'; $module = require $root . '/scripts/qualification/promise-qualification-bounds-cases.php'; $cases = [];
		$module( static function ( string $id, bool $condition, array $evidence, array $observations ) use ( &$cases ): void { self::assertTrue( $condition, $id ); self::assertNotContains( false, $evidence, $id ); self::assertArrayNotHasKey( $id, $cases ); self::assertNotEmpty( $evidence ); self::assertNotEmpty( $observations ); foreach ( $evidence as $name => $value ) { self::assertMatchesRegularExpression( '/\A[a-z0-9_]+\z/D', $name ); self::assertIsBool( $value ); } foreach ( $observations as $name => $value ) { self::assertMatchesRegularExpression( '/\A[a-z0-9_]+\z/D', $name ); self::assertIsInt( $value ); self::assertGreaterThanOrEqual( 0, $value ); } $cases[$id] = [ $evidence, $observations ]; } );
		$expected = [
			'PURE-W2P06-GRAPH-EXACT-NODES-EDGES' => [ 'nodes' => 16, 'edges' => 32 ],
			'PURE-W2P06-GRAPH-NODES-PLUS-ONE' => [ 'nodes' => 17, 'limit' => 16 ],
			'PURE-W2P06-GRAPH-EDGES-PLUS-ONE' => [ 'edges' => 33, 'limit' => 32 ],
			'PURE-W2P06-RECORD-BYTES-EXACT' => [ 'encoded_bytes' => 32768, 'limit' => 32768 ],
			'PURE-W2P06-RECORD-BYTES-PLUS-ONE' => [ 'attempted_bytes' => 32769, 'limit' => 32768 ],
			'PURE-W2P06-PACKET-BYTES-EXACT' => [ 'encoded_bytes' => 65536, 'limit' => 65536 ],
			'PURE-W2P06-PACKET-BYTES-PLUS-ONE' => [ 'attempted_bytes' => 65537, 'limit' => 65536 ],
			'PURE-W2P06-CART-STEPS-EXACT' => [ 'used_steps' => 100000, 'limit' => 100000 ],
			'PURE-W2P06-CART-STEPS-PLUS-ONE' => [ 'attempted_steps' => 100001, 'used_steps' => 100000 ],
			'PURE-W2P06-HORIZON-EXACT' => [ 'days' => 730, 'limit' => 730 ],
			'PURE-W2P06-HORIZON-PLUS-ONE' => [ 'days' => 731, 'limit' => 730 ],
			'PURE-W2P06-CALENDAR-INTERVALS-EXACT-PLUS-ONE' => [ 'accepted_intervals' => 8, 'attempted_intervals' => 9 ],
			'PURE-W2P06-CALENDAR-EXCEPTIONS-EXACT-PLUS-ONE' => [ 'accepted_dates' => 366, 'attempted_dates' => 367 ],
			'PURE-W2P06-CALENDAR-REFERENCES-EXACT' => [ 'unique_calendars' => 16 ],
			'PURE-W2P06-CALENDAR-REFERENCES-PLUS-ONE' => [ 'attempted_calendars' => 17, 'limit' => 16 ],
			'PURE-W2P06-CART-GROUPS-EXACT-PACKET-REFUSAL' => [ 'inputs' => 200, 'outputs' => 200 ],
			'PURE-W2P06-CART-GROUPS-PLUS-ONE' => [ 'attempted_inputs' => 201 ],
			'PURE-W2P06-ACTUAL-CART-SHARED-STEP-EXHAUSTION' => [ 'inputs' => 30, 'used_steps' => 100000, 'limit' => 100000 ],
		];
		self::assertSame( array_keys( $expected ), array_keys( $cases ) ); foreach ( $expected as $id => $observations ) { foreach ( $observations as $name => $value ) { self::assertSame( $value, $cases[$id][1][$name], $id . ':' . $name ); } }
	}
}
