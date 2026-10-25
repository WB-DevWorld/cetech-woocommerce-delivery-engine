<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PolicyAssignment;

use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentMap as Map, PolicyAssignmentState as State};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentDomainFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyAssignmentStateTest extends TestCase {
	public function test_absence_is_distinct_and_exact_physical_identity_binds_digest(): void {
		$a = State::absent( 1, 'global', 0 ); $b = State::absent( 1, 'product', 33 );
		self::assertSame( 0, $a->row_id() ); self::assertSame( 0, $a->revision() ); self::assertNull( $a->generation() ); self::assertSame( '', $a->original_bytes() );
		self::assertNull( $a->changed_at() );
		self::assertSame( [ 'mode' => 'inherit' ], $a->map( 'return_policy_v1' )->facts()['default'] );
		self::assertNotSame( $a->digest(), $b->digest() );
		$state = F::state(); $other = State::from_physical( 1, 'product', 33, 0, 51, $state->original_bytes() );
		self::assertNotSame( $state->digest(), $other->digest() );
		self::assertSame( $state->map( 'return_policy_v1' )->json(), $other->map( 'return_policy_v1' )->json() );
		self::assertStringNotContainsString( '"row_id"', $state->original_bytes() );
	}
	public function test_state_preserves_both_maps_and_exact_private_audit_bytes(): void {
		$return = Map::empty( 1, 'return_policy_v1' )->with_instruction( 9, F::slot() );
		$refund = Map::empty( 1, 'refund_policy_v1' )->with_instruction( 0, [ 'mode' => 'disabled' ] );
		$state = F::state( 'variation', 44, 33, 80, 3, $return, $refund );
		self::assertSame( [ 'site_id' => 1, 'scope_type' => 'variation', 'scope_id' => 44, 'parent_id' => 33 ], $state->coordinate() );
		self::assertSame( 3, $state->revision() ); self::assertSame( F::uuid( 900 ), $state->generation() );
		self::assertSame( F::NOW, $state->changed_at()->sql() );
		self::assertSame( $return->json(), $state->map( 'return_policy_v1' )->json() );
		self::assertSame( $refund->json(), $state->map( 'refund_policy_v1' )->json() );
		$row = json_decode( $state->original_bytes(), true, 8, JSON_THROW_ON_ERROR );
		self::assertSame( 9, $row['actor_user_id'] ); self::assertSame( 'Fixture original reason', $row['reason'] ); self::assertSame( F::NOW, $row['changed_at'] );
	}
	#[DataProvider( 'coordinates' )]
	public function test_invalid_or_ambiguous_coordinates_are_refused( int $site, string $type, int $id, int $parent ): void {
		$this->expectException( \InvalidArgumentException::class ); State::make_coordinate( $site, $type, $id, $parent );
	}
	public static function coordinates(): array {
		return [ [ 0, 'global', 0, 0 ], [ 1, 'merchant', 1, 0 ], [ 1, 'global', 1, 0 ], [ 1, 'global', 0, 1 ],
			[ 1, 'product', 0, 0 ], [ 1, 'product', 1, 2 ], [ 1, 'variation', 0, 1 ], [ 1, 'variation', 1, 0 ], [ 1, 'variation', 1, 1 ] ];
	}
	#[DataProvider( 'bad_records' )]
	public function test_persisted_frame_corruption_never_becomes_inherited_absence( \Closure $mutate ): void {
		$state = F::state(); $data = json_decode( $state->original_bytes(), true, 10, JSON_THROW_ON_ERROR ); $mutate( $data );
		$bytes = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->expectException( \InvalidArgumentException::class ); State::from_physical( 1, 'product', 33, 0, 50, $bytes );
	}
	public static function bad_records(): array {
		return [
			'foreign site' => [ static function( &$d ) { $d['site_id'] = 2; } ],
			'foreign scope' => [ static function( &$d ) { $d['scope_id'] = 34; } ],
			'parent drift' => [ static function( &$d ) { $d['parent_id'] = 1; } ],
			'revision zero' => [ static function( &$d ) { $d['revision'] = 0; } ],
			'revision string' => [ static function( &$d ) { $d['revision'] = '1'; } ],
			'bad generation' => [ static function( &$d ) { $d['generation'] = 'generation'; } ],
			'actor zero' => [ static function( &$d ) { $d['actor_user_id'] = 0; } ],
			'blank reason' => [ static function( &$d ) { $d['reason'] = ' '; } ],
			'control reason' => [ static function( &$d ) { $d['reason'] = "reason\n"; } ],
			'long reason' => [ static function( &$d ) { $d['reason'] = str_repeat( 'x', 1001 ); } ],
			'non-UTC time' => [ static function( &$d ) { $d['changed_at'] = '2026-10-24T12:00:00Z'; } ],
			'swapped family' => [ static function( &$d ) { $d['return_map'] = $d['refund_map']; } ],
			'extra physical ID' => [ static function( &$d ) { $d['row_id'] = 50; } ],
		];
	}
	public function test_reparenting_and_row_zero_cannot_relabel_existing_physical_bytes(): void {
		$bytes = F::state( 'variation', 44, 33 )->original_bytes();
		foreach ( [ [ 1, 'variation', 44, 34, 50 ], [ 1, 'variation', 44, 33, 0 ] ] as $args ) {
			try { State::from_physical( ...[ ...$args, $bytes ] ); self::fail( 'Physical frame accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
	}
	public function test_private_values_refuse_generic_persistence_and_redact_debug_output(): void {
		$data = F::payload(); $values = [ Map::empty( 1, 'return_policy_v1' ), F::state(), F::command( $data ) ];
		foreach ( $values as $value ) {
			foreach ( [ static fn() => json_encode( $value, JSON_THROW_ON_ERROR ), static fn() => serialize( $value ) ] as $encode ) {
				try { $encode(); self::fail( 'Private value persisted.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
			}
			ob_start(); var_dump( $value ); $debug = ob_get_clean();
			self::assertStringContainsString( 'redacted', $debug ); self::assertStringNotContainsString( 'Fixture original reason', $debug );
			self::assertStringNotContainsString( 'Fixture assignment reason', $debug );
		}
	}
	public function test_joint_near_boundary_state_is_recoverable_and_escaped_oversize_corruption_refuses(): void {
		$maps = [];
		foreach ( Map::FAMILIES as $family ) {
			$map = Map::empty( 1, $family );
			for ( $i = 0; $i < 32; ++$i ) { $map = $map->with_instruction( PHP_INT_MAX - $i, F::slot( 101 + $i ) ); }
			$maps[] = $map;
		}
		$coordinate = State::make_coordinate( 1, 'product', 33 );
		$bytes = State::record_json( $coordinate, F::uuid( 900 ), 1, $maps[0], $maps[1], 9, str_repeat( '"', 1000 ), F::NOW );
		$state = State::from_physical( 1, 'product', 33, 0, 50, $bytes ); $payload = F::payload( $state );
		$payload['reason'] = str_repeat( '"', 1000 );
		self::assertLessThanOrEqual( 16384, strlen( json_encode( F::command( $payload )->payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		$data = json_decode( $bytes, true, 10, JSON_THROW_ON_ERROR ); $data['reason'] = str_repeat( '"', 4000 );
		$oversize = json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		self::assertLessThanOrEqual( 16384, strlen( $oversize ) );
		self::assertGreaterThan( 16384, strlen( json_encode( [ 'state' => $oversize ], JSON_THROW_ON_ERROR ) ) );
		$this->expectException( \InvalidArgumentException::class ); State::from_physical( 1, 'product', 33, 0, 50, $oversize );
	}
}
