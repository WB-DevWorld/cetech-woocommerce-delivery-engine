<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\{PromiseGraph,PromiseLimits};
use PHPUnit\Framework\TestCase;

final class PromiseGraphTest extends TestCase {
	public static function graph(): array { return [ 'format_version' => 1, 'components' => [ PromiseComponentTest::component( 'prepare' ), PromiseComponentTest::component( 'transit', [ 'prepare' ] ), PromiseComponentTest::component( 'final', [ 'transit' ] ) ], 'terminal_component_ids' => [ 'final' ] ]; }
	public function test_graph_member_order_is_canonical_but_dependencies_are_material(): void {
		$facts = self::graph(); $original = PromiseGraph::from_array( $facts ); $facts['components'] = array_reverse( $facts['components'] ); self::assertSame( $original->digest(), PromiseGraph::from_array( $facts )->digest() ); self::assertSame( $original->to_private_json(), PromiseGraph::from_json( $original->to_private_json() )->to_private_json() );
		$facts = self::graph(); $facts['components'][2]['predecessors'] = [ 'prepare', 'transit' ]; self::assertNotSame( $original->digest(), PromiseGraph::from_array( $facts )->digest() );
	}
	public function test_parallel_sinks_remain_explicit_independent_endpoints(): void {
		$one = PromiseComponentTest::component( 'port' ); $two = PromiseComponentTest::component( 'warehouse' ); $two['endpoint'] = 'merchant-warehouse'; $two['endpoint_kind'] = 'handover'; $facts = [ 'format_version' => 1, 'components' => [ $one, $two ], 'terminal_component_ids' => [ 'warehouse', 'port' ] ]; $graph = PromiseGraph::from_array( $facts ); self::assertSame( [ 'accra-port', 'merchant-warehouse' ], $graph->terminal_endpoints() ); self::assertSame( [ [ 'endpoint' => 'accra-port', 'endpoint_kind' => 'port' ], [ 'endpoint' => 'merchant-warehouse', 'endpoint_kind' => 'handover' ] ], $graph->terminal_endpoint_facts() ); self::assertNotContains( 'doorstep', $graph->terminal_endpoints() ); $facts['terminal_component_ids'] = array_reverse( $facts['terminal_component_ids'] ); self::assertSame( $graph->digest(), PromiseGraph::from_array( $facts )->digest() );
	}
	/** @dataProvider invalid_graphs */
	public function test_incomplete_cyclic_or_conflicting_graph_refuses( array $facts ): void { $this->expectException( \InvalidArgumentException::class ); PromiseGraph::from_array( $facts ); }
	public static function invalid_graphs(): array {
		$duplicate = self::graph(); $duplicate['components'][] = $duplicate['components'][0];
		$missing = self::graph(); $missing['components'][1]['predecessors'] = [ 'absent' ];
		$cycle = self::graph(); $cycle['components'][0]['predecessors'] = [ 'final' ];
		$false_terminal = self::graph(); $false_terminal['terminal_component_ids'] = [ 'prepare' ];
		$incomplete = self::graph(); $incomplete['components'][] = PromiseComponentTest::component( 'other' );
		$duplicate_terminal = self::graph(); $duplicate_terminal['terminal_component_ids'] = [ 'final', 'final' ];
		$foreign_site = self::graph(); $foreign_site['components'][1]['source']['site_id'] = 'site-2';
		$conflicting_source = self::graph(); $conflicting_source['components'][1]['source']['version'] = 2;
		$calendar_site = self::graph(); $calendar_site['components'][0]['duration'] = PromiseDurationTest::duration( 'business_days' ); $calendar_site['components'][0]['duration']['calendar']['site_id'] = 'site-2';
		$calendar_version = self::graph(); $calendar_version['components'][0]['duration'] = PromiseDurationTest::duration( 'business_days' ); $calendar_version['components'][1]['duration'] = PromiseDurationTest::duration( 'business_days' ); $calendar_version['components'][1]['duration']['calendar']['version'] = 2;
		$operating_site = self::graph(); $operating_site['components'][0]['operating_calendar'] = PromiseDurationTest::calendar(); $operating_site['components'][0]['operating_calendar']['site_id'] = 'site-2';
		$operating_version = self::graph(); $operating_version['components'][0]['duration'] = PromiseDurationTest::duration( 'business_days' ); $operating_version['components'][1]['operating_calendar'] = PromiseDurationTest::calendar(); $operating_version['components'][1]['operating_calendar']['version'] = 2;
		return [ [ $duplicate ], [ $missing ], [ $cycle ], [ $false_terminal ], [ $incomplete ], [ $duplicate_terminal ], [ $foreign_site ], [ $conflicting_source ], [ $calendar_site ], [ $calendar_version ], [ $operating_site ], [ $operating_version ] ];
	}
	public static function bounded_graph( int $nodes, int $edges ): array {
		$components = []; $used_as_predecessor = [];
		for ( $i = 0; $i < $nodes; ++$i ) { $predecessors = []; for ( $j = 0; $j < $i && $edges > 0; ++$j ) { $id = 'n' . $j; $predecessors[] = $id; $used_as_predecessor[$id] = true; --$edges; } $components[] = PromiseComponentTest::component( 'n' . $i, $predecessors ); }
		$terminals = []; foreach ( $components as $component ) { if ( ! isset( $used_as_predecessor[$component['component_id']] ) ) { $terminals[] = $component['component_id']; } }
		return [ 'format_version' => 1, 'components' => $components, 'terminal_component_ids' => $terminals ];
	}
	public function test_exact_node_and_edge_limits_accept_complete_graph(): void { $graph = PromiseGraph::from_array( self::bounded_graph( PromiseLimits::GRAPH_NODES, PromiseLimits::GRAPH_EDGES ) ); self::assertCount( 16, $graph->private_facts()['components'] ); self::assertSame( 32, array_sum( array_map( static fn( array $component ): int => count( $component['predecessors'] ), $graph->private_facts()['components'] ) ) ); }
	public function test_plus_one_node_refuses_without_partial_graph(): void { $this->expectException( \InvalidArgumentException::class ); PromiseGraph::from_array( self::bounded_graph( 17, 0 ) ); }
	public function test_plus_one_edge_refuses_without_partial_graph(): void { $this->expectException( \InvalidArgumentException::class ); PromiseGraph::from_array( self::bounded_graph( 16, 33 ) ); }
	public function test_private_graph_has_no_implicit_json_projection(): void { $graph = PromiseGraph::from_array( self::graph() ); $this->expectException( \LogicException::class ); json_encode( $graph, JSON_THROW_ON_ERROR ); }
	public function test_native_serialization_does_not_publish_private_facts(): void { $this->expectException( \LogicException::class ); serialize( PromiseGraph::from_array( self::graph() ) ); }
	public function test_native_unserialization_cannot_construct_unvalidated_carrier(): void { $name = PromiseGraph::class; $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $name ) . ':"' . $name . '":0:{}' ); }
}
