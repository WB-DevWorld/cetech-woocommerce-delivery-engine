<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

/** No historical defaults or unknown-extension payloads are retained or exposed. */
final readonly class SnapshotExtensionSet implements \JsonSerializable {
	private array $results;
	public function __construct( array $results = [], private bool $semantics_supported = true ) {
		$copy = [];
		$recorded = [];
		if ( count( $results ) > 16 ) { throw new \InvalidArgumentException( 'Invalid snapshot extension set.' ); }
		foreach ( $results as $name => $result ) {
			if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 128 || 1 !== preg_match( '//u', $name ) || ! $result instanceof SnapshotExtensionReadResult ) { throw new \InvalidArgumentException( 'Invalid snapshot extension set.' ); }
			if ( null !== $result->facts && $name !== $result->facts->namespace() ) { throw new \InvalidArgumentException( 'Invalid snapshot extension set.' ); }
			if ( null !== $result->facts ) { $recorded[$name] = $result->facts->internal_facts(); }
			$copy[(string) $name] = $result;
			if ( 'unsupported_required' === $result->status && $semantics_supported ) { throw new \InvalidArgumentException( 'Invalid snapshot extension set.' ); }
		}
		if ( strlen( json_encode( $recorded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) > 65536 ) { throw new \InvalidArgumentException( 'Invalid snapshot extension set.' ); }
		$this->results = $copy;
	}
	public function get( string $namespace ): SnapshotExtensionReadResult { return $this->results[$namespace] ?? new SnapshotExtensionReadResult( 'not_recorded' ); }
	public function required_semantics_supported(): bool { return $this->semantics_supported; }
	public function customer_facts(): array {
		$public = [];
		foreach ( SnapshotExtensionFacts::NAMES as $name ) {
			$facts = $this->get( $name )->facts;
			if ( null !== $facts ) { $public[$name] = $facts->customer_facts(); }
		}
		return $public;
	}
	public function diagnostics(): array {
		$statuses = []; $ignored = 0;
		foreach ( SnapshotExtensionFacts::NAMES as $name ) { $statuses[$name] = $this->get( $name )->status; }
		foreach ( $this->results as $result ) { if ( 'ignored_optional' === $result->status ) { ++$ignored; } }
		return [ 'statuses' => $statuses, 'ignored_optional_count' => $ignored, 'required_semantics_supported' => $this->semantics_supported ];
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Snapshot extensions require an explicit projection.' ); }
}
