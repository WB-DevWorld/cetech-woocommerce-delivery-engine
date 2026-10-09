<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\{PromiseComponent,PromiseJson};
use PHPUnit\Framework\TestCase;

final class PromiseComponentTest extends TestCase {
	public static function component( string $id = 'phase', array $predecessors = [] ): array {
		return [ 'format_version' => 1, 'component_id' => $id, 'role' => 'transit', 'duration' => PromiseDurationTest::duration(), 'operating_calendar' => null, 'completion_window_rule' => 'none', 'predecessors' => $predecessors, 'endpoint' => 'accra-port', 'endpoint_kind' => 'port', 'source' => [ 'format_version' => 1, 'site_id' => 'site-1', 'source_id' => 'carrier-policy', 'version' => 1, 'digest' => str_repeat( 'b', 64 ) ] ];
	}
	public function test_predecessors_are_a_canonical_set_but_duplicate_is_not_silently_dropped(): void {
		$first = PromiseComponent::from_array( self::component( 'join', [ 'transit', 'prepare' ] ) ); $second = PromiseComponent::from_array( self::component( 'join', [ 'prepare', 'transit' ] ) ); self::assertSame( $first->digest(), $second->digest() ); self::assertSame( [ 'prepare', 'transit' ], $first->private_facts()['predecessors'] );
		$this->expectException( \InvalidArgumentException::class ); PromiseComponent::from_array( self::component( 'join', [ 'prepare', 'prepare' ] ) );
	}
	public function test_endpoint_role_and_source_are_material_and_never_inferred(): void {
		$facts = self::component(); $original = PromiseComponent::from_array( $facts ); self::assertSame( 'accra-port', $original->private_facts()['endpoint'] );
		foreach ( [ [ 'role' => 'final_mile' ], [ 'endpoint' => 'another-port' ], [ 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep', 'role' => 'final_mile' ], [ 'source' => array_replace( $facts['source'], [ 'version' => 2 ] ) ] ] as $change ) { self::assertNotSame( $original->digest(), PromiseComponent::from_array( array_replace( $facts, $change ) )->digest() ); }
	}
	/** @dataProvider malformed */
	public function test_inexact_component_or_source_refuses( array $facts ): void { $this->expectException( \InvalidArgumentException::class ); PromiseComponent::from_array( $facts ); }
	public static function malformed(): array {
		$base = self::component(); return [ [ self::component( 'phase', [ 'phase' ] ) ], [ array_replace( $base, [ 'role' => 'delivery' ] ) ], [ array_replace( $base, [ 'endpoint' => null ] ) ], [ array_replace( $base, [ 'endpoint_kind' => 'doorstep' ] ) ], [ array_replace( $base, [ 'endpoint_kind' => 'unknown' ] ) ], [ array_replace( $base, [ 'source' => array_replace( $base['source'], [ 'version' => '1' ] ) ] ) ], [ array_replace( $base, [ 'source' => array_replace( $base['source'], [ 'digest' => '' ] ) ] ) ], [ array_replace( $base, [ 'source' => $base['source'] + [ 'private' => 1 ] ] ) ], [ $base + [ 'duration_days' => 1 ] ] ];
	}
	public function test_json_object_cannot_masquerade_as_empty_predecessor_list(): void { $json = PromiseJson::encode( self::component() ); $json = str_replace( '"predecessors":[]', '"predecessors":{}', $json ); $this->expectException( \InvalidArgumentException::class ); PromiseComponent::from_json( $json ); }
	public function test_elapsed_duration_can_have_independent_operating_and_completion_constraints(): void {
		$facts = self::component(); $original = PromiseComponent::from_array( $facts ); $facts['operating_calendar'] = PromiseDurationTest::calendar(); $facts['completion_window_rule'] = 'within_open_interval'; $component = PromiseComponent::from_array( $facts );
		self::assertNull( $component->private_facts()['duration']['calendar'] ); self::assertSame( 'elapsed_minutes', $component->private_facts()['duration']['unit'] ); self::assertSame( 'within_open_interval', $component->private_facts()['completion_window_rule'] ); self::assertNotSame( $original->digest(), $component->digest() );
	}
	public function test_completion_constraint_without_operating_calendar_refuses(): void { $facts = self::component(); $facts['completion_window_rule'] = 'within_open_interval'; $this->expectException( \InvalidArgumentException::class ); PromiseComponent::from_array( $facts ); }
	public function test_component_detaches_references_and_refuses_implicit_public_json(): void { $facts = self::component(); $alias =& $facts['source']['version']; $component = PromiseComponent::from_array( $facts ); $alias = 2; self::assertSame( 1, $component->private_facts()['source']['version'] ); $this->expectException( \LogicException::class ); json_encode( $component, JSON_THROW_ON_ERROR ); }
	public function test_native_serialization_does_not_publish_private_facts(): void { $this->expectException( \LogicException::class ); serialize( PromiseComponent::from_array( self::component() ) ); }
	public function test_native_unserialization_cannot_construct_unvalidated_carrier(): void { $name = PromiseComponent::class; $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $name ) . ':"' . $name . '":0:{}' ); }
}
