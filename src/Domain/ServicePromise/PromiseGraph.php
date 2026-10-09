<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Bounded dependency structure, with exact terminal endpoints and no calculator. */
final readonly class PromiseGraph implements \JsonSerializable {
	public const FORMAT = 1;
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, PromiseLimits::RECORD_BYTES ); PromiseShape::fields( $data, [ 'format_version', 'components', 'terminal_component_ids' ] );
		if ( self::FORMAT !== $data['format_version'] ) { PromiseShape::invalid(); }
		$components = PromiseShape::list( $data['components'], 1, PromiseLimits::GRAPH_NODES ); $by_id = []; $site = null; $sources = []; $calendars = [];
		foreach ( $components as &$component ) {
			$component = PromiseComponent::from_array( PromiseShape::object( $component ) )->private_facts(); $id = $component['component_id'];
			if ( isset( $by_id[':' . $id] ) || ( null !== $site && $site !== $component['source']['site_id'] ) ) { PromiseShape::invalid(); }
			$site = $component['source']['site_id'];
			foreach ( [ $component['duration']['calendar'], $component['operating_calendar'] ] as $calendar ) {
				if ( null === $calendar ) { continue; } if ( $calendar['site_id'] !== $site ) { PromiseShape::invalid(); }
				$calendar_id = ':' . $calendar['calendar_id']; $calendar_json = PromiseJson::encode( $calendar ); if ( isset( $calendars[$calendar_id] ) && $calendars[$calendar_id] !== $calendar_json ) { PromiseShape::invalid(); } $calendars[$calendar_id] = $calendar_json;
			}
			$source_id = ':' . $component['source']['source_id']; $source_json = PromiseJson::encode( $component['source'] );
			if ( isset( $sources[$source_id] ) && $sources[$source_id] !== $source_json ) { PromiseShape::invalid(); } $sources[$source_id] = $source_json; $by_id[':' . $id] = $component;
		} unset( $component );
		$edges = 0; $successors = []; $remaining = [];
		foreach ( $components as $component ) {
			$id = ':' . $component['component_id']; $remaining[$id] = count( $component['predecessors'] );
			foreach ( $component['predecessors'] as $predecessor ) { $key = ':' . $predecessor; if ( ! isset( $by_id[$key] ) || ++$edges > PromiseLimits::GRAPH_EDGES ) { PromiseShape::invalid(); } $successors[$key][] = $id; }
		}
		$ready = array_keys( array_filter( $remaining, static fn( int $count ): bool => 0 === $count ) ); $visited = 0;
		while ( [] !== $ready ) { $id = array_pop( $ready ); ++$visited; foreach ( $successors[$id] ?? [] as $successor ) { if ( 0 === --$remaining[$successor] ) { $ready[] = $successor; } } }
		if ( $visited !== count( $components ) ) { PromiseShape::invalid(); }
		$terminals = PromiseShape::list( $data['terminal_component_ids'], 1, PromiseLimits::GRAPH_NODES ); $seen = [];
		foreach ( $terminals as $terminal ) { $terminal = PromiseShape::id( $terminal ); if ( ! isset( $by_id[':' . $terminal] ) || isset( $successors[':' . $terminal] ) || isset( $seen[':' . $terminal] ) ) { PromiseShape::invalid(); } $seen[':' . $terminal] = true; }
		foreach ( $by_id as $key => $component ) { if ( ! isset( $successors[$key] ) && ! isset( $seen[$key] ) ) { PromiseShape::invalid(); } }
		sort( $terminals, SORT_STRING ); $data['terminal_component_ids'] = $terminals;
		usort( $components, static fn( array $a, array $b ): int => strcmp( $a['component_id'], $b['component_id'] ) ); $data['components'] = $components;
		return new self( PromiseJson::encode( $data, PromiseLimits::RECORD_BYTES ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, PromiseLimits::RECORD_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-promise-graph-v1:' . $this->json ); }
	/** Explicit declared endpoints only; this does not prove actual physical delivery. */
	public function terminal_endpoints(): array { $facts = $this->private_facts(); $endpoints = []; foreach ( $facts['components'] as $component ) { if ( in_array( $component['component_id'], $facts['terminal_component_ids'], true ) ) { $endpoints[] = $component['endpoint']; } } $endpoints = array_values( array_unique( $endpoints ) ); sort( $endpoints, SORT_STRING ); return $endpoints; }
	public function terminal_endpoint_facts(): array {
		$facts = $this->private_facts(); $endpoints = [];
		foreach ( $facts['components'] as $component ) { if ( in_array( $component['component_id'], $facts['terminal_component_ids'], true ) ) { $key = $component['endpoint'] . ':' . $component['endpoint_kind']; $endpoints[$key] = [ 'endpoint' => $component['endpoint'], 'endpoint_kind' => $component['endpoint_kind'] ]; } }
		ksort( $endpoints, SORT_STRING ); return array_values( $endpoints );
	}
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise graph projection is required.' ); }
}
