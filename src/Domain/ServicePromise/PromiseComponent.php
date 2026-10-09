<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** One immutable phase and explicit endpoint/source; not an executable fulfilment leg. */
final readonly class PromiseComponent implements \JsonSerializable {
	public const FORMAT = 1;
	public const ROLES = [ 'preparation', 'dispatch', 'transit', 'buffer', 'final_mile' ];
	public const ENDPOINT_KINDS = [ 'origin', 'dispatch', 'port', 'handover', 'pickup', 'doorstep' ];
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, PromiseLimits::RECORD_BYTES ); PromiseShape::fields( $data, [ 'format_version', 'component_id', 'role', 'duration', 'operating_calendar', 'completion_window_rule', 'predecessors', 'endpoint', 'endpoint_kind', 'source' ] );
		if ( self::FORMAT !== $data['format_version'] ) { PromiseShape::invalid(); }
		$id = PromiseShape::id( $data['component_id'] ); PromiseShape::choice( $data['role'], self::ROLES ); PromiseShape::id( $data['endpoint'] );
		PromiseShape::choice( $data['endpoint_kind'], self::ENDPOINT_KINDS ); if ( 'doorstep' === $data['endpoint_kind'] && 'final_mile' !== $data['role'] ) { PromiseShape::invalid(); }
		$data['duration'] = PromiseDuration::from_array( PromiseShape::object( $data['duration'] ) )->private_facts();
		// Operating admission is independent of duration arithmetic, including elapsed units.
		if ( null !== $data['operating_calendar'] ) { $data['operating_calendar'] = PromiseCalendarReference::from_array( PromiseShape::object( $data['operating_calendar'] ) )->private_facts(); }
		PromiseShape::choice( $data['completion_window_rule'], [ 'none', 'within_open_interval' ] );
		if ( 'within_open_interval' === $data['completion_window_rule'] && null === $data['operating_calendar'] ) { PromiseShape::invalid(); }
		$predecessors = PromiseShape::list( $data['predecessors'], 0, PromiseLimits::GRAPH_NODES - 1 ); $seen = [];
		foreach ( $predecessors as $predecessor ) { $predecessor = PromiseShape::id( $predecessor ); if ( $predecessor === $id || isset( $seen[':' . $predecessor] ) ) { PromiseShape::invalid(); } $seen[':' . $predecessor] = true; }
		sort( $predecessors, SORT_STRING ); $data['predecessors'] = $predecessors;
		$source = PromiseShape::object( $data['source'] ); PromiseShape::fields( $source, [ 'format_version', 'site_id', 'source_id', 'version', 'digest' ] );
		if ( self::FORMAT !== $source['format_version'] ) { PromiseShape::invalid(); }
		PromiseShape::id( $source['site_id'] ); PromiseShape::id( $source['source_id'] ); PromiseShape::integer( $source['version'], 1, PromiseLimits::VERSION_MAX ); PromiseShape::digest( $source['digest'] ); $data['source'] = $source;
		return new self( PromiseJson::encode( $data, PromiseLimits::RECORD_BYTES ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, PromiseLimits::RECORD_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-promise-component-v1:' . $this->json ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise component projection is required.' ); }
}
