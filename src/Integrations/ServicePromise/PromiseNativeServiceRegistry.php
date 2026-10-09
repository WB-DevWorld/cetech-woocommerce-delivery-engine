<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\ServicePromise;

use CetechDeliveryEngine\Application\ServicePromise\Handoff\PromiseCaptureDemand;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseJson, PromiseShape, ServiceIdentity};

/** Trusted immutable host registration; there is no browser, label, option/filter or fallback mapping. */
final readonly class PromiseNativeServiceRegistry {
	public const MAX_BYTES = 32768;
	private array $entries;
	/** @param list<array> $entries Exact native service identities, supplied by registered server composition. */
	public function __construct( array $entries = [] ) {
		PromiseShape::list( $entries, 0, 200 ); $out = [];
		foreach ( $entries as $entry ) {
			$entry = PromiseJson::detach( PromiseShape::object( $entry ) ); PromiseShape::fields( $entry, [ 'native_service_id', 'service_kind', 'service_code', 'origin_endpoint', 'origin_kind', 'destination_endpoint', 'destination_kind' ] ); $native = PromiseShape::integer( $entry['native_service_id'], 1 );
			if ( isset( $out[$native] ) ) { PromiseShape::invalid(); }
			ServiceIdentity::from_array( [ 'format_version' => 1, 'kind' => $entry['service_kind'], 'code' => $entry['service_code'], 'customer_label' => 'Native service identity' ] ); PromiseShape::id( $entry['origin_endpoint'] ); PromiseShape::choice( $entry['origin_kind'], [ 'origin', 'dispatch', 'port', 'handover', 'pickup', 'doorstep' ] ); PromiseShape::id( $entry['destination_endpoint'] ); PromiseShape::choice( $entry['destination_kind'], [ 'doorstep', 'pickup', 'port', 'handover' ] ); $out[$native] = $entry;
		}
		ksort( $out, SORT_NUMERIC ); $this->entries = $out; PromiseJson::encode( $this->private_facts(), self::MAX_BYTES );
	}
	public static function from_array( array $facts ): self { $facts = PromiseJson::detach( $facts, self::MAX_BYTES ); PromiseShape::fields( $facts, [ 'format', 'entries' ] ); if ( 1 !== $facts['format'] ) { PromiseShape::invalid(); } return new self( PromiseShape::list( $facts['entries'], 0, 200 ) ); }
	public static function from_json( string $json ): self { $registry = self::from_array( PromiseJson::decode( $json, self::MAX_BYTES ) ); if ( $json !== $registry->to_private_json() ) { PromiseShape::invalid(); } return $registry; }
	public function private_facts(): array { return [ 'format' => 1, 'entries' => array_values( $this->entries ) ]; }
	public function to_private_json(): string { return PromiseJson::encode( $this->private_facts(), self::MAX_BYTES ); }
	public function is_empty(): bool { return [] === $this->entries; }
	/** @return list<PromiseCaptureDemand> */
	public function create_demands( QuoteContext $base ): array {
		if ( 1 !== $base->format_version() || ! $base->checkout_acceptable() ) { PromiseShape::invalid(); } $demands = [];
		foreach ( $base->private_facts()['groups'] as $group ) {
			$entry = $this->entries[$group['service_id']] ?? null; if ( null === $entry ) { throw new \RuntimeException( 'The native service has no registered promise mapping.' ); }
			unset( $entry['native_service_id'] ); $demands[] = PromiseCaptureDemand::from_native( $base, [ 'component_key' => $group['component_key'] ] + $entry );
		}
		return $demands;
	}
}
