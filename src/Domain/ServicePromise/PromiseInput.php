<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Detached captured evidence, not a collector, authorization decision or current-clock lookup. */
final readonly class PromiseInput implements \JsonSerializable {
	private function __construct( private array $data ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data );
		PromiseShape::fields( $data, [ 'format_version', 'site_id', 'evaluated_at', 'owner', 'material', 'origin', 'destination', 'policy', 'calendar_refs', 'source_receipts', 'anchor', 'service_day', 'capacity', 'runtime' ] );
		PromiseShape::integer( $data['format_version'], 1, 1 );
		$site = PromiseShape::id( $data['site_id'] ); $evaluated = PromiseShape::instant( $data['evaluated_at'] );
		$policy = ServicePromisePolicy::from_array( PromiseShape::object( $data['policy'] ) );
		$anchor = PromiseAnchor::from_array( PromiseShape::object( $data['anchor'] ) );
		$owner = PromiseShape::object( $data['owner'] ); PromiseShape::fields( $owner, [ 'site_id', 'kind', 'principal_hash', 'session_hash', 'key_epoch' ] );
		$owner = [ 'site_id' => PromiseShape::id( $owner['site_id'] ), 'kind' => PromiseShape::choice( $owner['kind'], [ 'customer', 'guest' ] ), 'principal_hash' => PromiseShape::digest( $owner['principal_hash'] ), 'session_hash' => PromiseShape::digest( $owner['session_hash'] ), 'key_epoch' => PromiseShape::id( $owner['key_epoch'] ) ];
		if ( $site !== $policy->site_id() || $site !== $owner['site_id'] || $anchor->kind() !== $policy->anchor() || ! $evaluated->equals( $anchor->evaluated_at() ) || $evaluated->compare( $policy->effective_from() ) < 0 || ( null !== $policy->effective_until() && $evaluated->compare( $policy->effective_until() ) >= 0 ) ) { PromiseShape::invalid(); }
		if ( null !== $anchor->accept_until() && null !== $policy->effective_until() && $anchor->accept_until()->compare( $policy->effective_until() ) > 0 ) { PromiseShape::invalid(); }
		$material = PromiseShape::object( $data['material'] ); PromiseShape::fields( $material, [ 'group_id', 'material_digest' ] );
		$material = [ 'group_id' => PromiseShape::id( $material['group_id'] ), 'material_digest' => PromiseShape::digest( $material['material_digest'] ) ];
		$origin = self::endpoint( $data['origin'] ); $destination = self::endpoint( $data['destination'] );
		if ( $destination['endpoint'] !== $policy->endpoint() || $destination['endpoint_kind'] !== $policy->endpoint_kind() ) { PromiseShape::invalid(); }
		$calendars = []; $seen = [];
		foreach ( PromiseShape::list( $data['calendar_refs'], 0, PromiseLimits::CALENDARS ) as $raw ) {
			$ref = PromiseCalendarReference::from_array( PromiseShape::object( $raw ) ); $facts = $ref->private_facts();
			if ( $site !== $facts['site_id'] || ( isset( $seen[$facts['calendar_id']] ) && PromiseJson::encode( $seen[$facts['calendar_id']] ) !== PromiseJson::encode( $facts ) ) ) { PromiseShape::invalid(); }
			if ( ! isset( $seen[$facts['calendar_id']] ) ) { $calendars[] = $facts; } $seen[$facts['calendar_id']] = $facts;
		}
		usort( $calendars, static fn( array $a, array $b ): int => strcmp( $a['calendar_id'], $b['calendar_id'] ) );
		$expected_calendars = array_map( static fn( PromiseCalendarReference $ref ): array => $ref->private_facts(), $policy->calendars() );
		usort( $expected_calendars, static fn( array $a, array $b ): int => strcmp( $a['calendar_id'], $b['calendar_id'] ) );
		if ( PromiseJson::encode( [ 'refs' => $calendars ] ) !== PromiseJson::encode( [ 'refs' => $expected_calendars ] ) ) { PromiseShape::invalid(); }
		$sources = []; $source_ids = [];
		foreach ( PromiseShape::list( $data['source_receipts'], 1, PromiseLimits::GRAPH_NODES + 1 ) as $raw ) {
			$source = self::source( $raw, $site );
			if ( isset( $source_ids[$source['source_id']] ) && PromiseJson::encode( $source_ids[$source['source_id']] ) !== PromiseJson::encode( $source ) ) { PromiseShape::invalid(); }
			if ( ! isset( $source_ids[$source['source_id']] ) ) { $sources[] = $source; } $source_ids[$source['source_id']] = $source;
		}
		$expected_sources = [];
		foreach ( $policy->graph()->private_facts()['components'] as $component ) { $expected_sources[$component['source']['source_id']] = $component['source']; }
		if ( null !== $policy->capacity_source() ) {
			$source = $policy->capacity_source();
			if ( isset( $expected_sources[$source['source_id']] ) && PromiseJson::encode( $expected_sources[$source['source_id']] ) !== PromiseJson::encode( $source ) ) { PromiseShape::invalid(); }
			$expected_sources[$source['source_id']] = $source;
		}
		$expected_sources = array_values( $expected_sources );
		$sort_sources = static fn( array $a, array $b ): int => strcmp( $a['source_id'], $b['source_id'] );
		usort( $sources, $sort_sources ); usort( $expected_sources, $sort_sources );
		if ( PromiseJson::encode( [ 'sources' => $sources ] ) !== PromiseJson::encode( [ 'sources' => $expected_sources ] ) ) { PromiseShape::invalid(); }
		$capacity = PromiseCapacityObservation::from_array( PromiseShape::object( $data['capacity'] ) ); $capacity_facts = $capacity->private_facts();
		if ( $capacity->mode() !== $policy->capacity_mode() ) { PromiseShape::invalid(); }
		if ( 'required' === $capacity->mode() ) {
			if ( $capacity_facts['site_id'] !== $site || ! hash_equals( $capacity_facts['service_digest'], $policy->service()->digest() ) || ! hash_equals( $capacity_facts['endpoint_digest'], self::endpoint_digest( $destination ) ) || PromiseJson::encode( $capacity_facts['source'] ) !== PromiseJson::encode( $policy->capacity_source() ) || $capacity_facts['window']['display_timezone'] !== $policy->promise_timezone() || PromiseShape::instant( $capacity_facts['observed_at'] )->compare( $evaluated ) > 0 ) { PromiseShape::invalid(); }
		}
		$runtime = PromiseShape::object( $data['runtime'] ); PromiseShape::fields( $runtime, [ 'timezone_data_version', 'runtime_id', 'digest' ] );
		$runtime = [ 'timezone_data_version' => PromiseShape::text( $runtime['timezone_data_version'], 128 ), 'runtime_id' => PromiseShape::id( $runtime['runtime_id'] ), 'digest' => PromiseShape::digest( $runtime['digest'] ) ];
		$day = self::service_day( $data['service_day'], $policy, $anchor );
		return new self( PromiseJson::detach( [ 'format_version' => 1, 'site_id' => $site, 'evaluated_at' => $evaluated->sql(), 'owner' => $owner, 'material' => $material, 'origin' => $origin, 'destination' => $destination, 'policy' => $policy->private_facts(), 'calendar_refs' => $calendars, 'source_receipts' => $sources, 'anchor' => $anchor->private_facts(), 'service_day' => $day, 'capacity' => $capacity_facts, 'runtime' => $runtime ] ) );
	}
	private static function endpoint( mixed $raw ): array {
		$data = PromiseShape::object( $raw ); PromiseShape::fields( $data, [ 'endpoint', 'endpoint_kind', 'identity_digest' ] );
		return [ 'endpoint' => PromiseShape::id( $data['endpoint'] ), 'endpoint_kind' => PromiseShape::choice( $data['endpoint_kind'], [ 'origin', 'dispatch', 'port', 'handover', 'pickup', 'doorstep' ] ), 'identity_digest' => PromiseShape::digest( $data['identity_digest'] ) ];
	}
	private static function source( mixed $raw, string $site ): array {
		$data = PromiseShape::object( $raw ); PromiseShape::fields( $data, [ 'format_version', 'site_id', 'source_id', 'version', 'digest' ] );
		PromiseShape::integer( $data['format_version'], 1, 1 );
		if ( PromiseShape::id( $data['site_id'] ) !== $site ) { PromiseShape::invalid(); }
		return [ 'format_version' => 1, 'site_id' => $site, 'source_id' => PromiseShape::id( $data['source_id'] ), 'version' => PromiseShape::integer( $data['version'], 1, PromiseLimits::VERSION_MAX ), 'digest' => PromiseShape::digest( $data['digest'] ) ];
	}
	private static function service_day( mixed $raw, ServicePromisePolicy $policy, PromiseAnchor $anchor ): ?array {
		if ( 'none' === $policy->private_facts()['day_constraint'] ) { if ( null !== $raw ) { PromiseShape::invalid(); } return null; }
		$day = PromiseShape::object( $raw ); PromiseShape::fields( $day, [ 'local_date', 'timezone', 'start_at', 'end_at' ] );
		$zone = PromiseShape::timezone( $day['timezone'] ); if ( $zone !== $policy->promise_timezone() || 'payment_confirmed' === $anchor->kind() ) { PromiseShape::invalid(); }
		$start = PromiseShape::instant( $day['start_at'] ); $end = PromiseShape::instant( $day['end_at'] );
		$tz = new \DateTimeZone( $zone );
		$local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $anchor->evaluated_at()->sql(), new \DateTimeZone( 'UTC' ) )->setTimezone( $tz );
		$local_start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $start->sql(), new \DateTimeZone( 'UTC' ) )->setTimezone( $tz );
		$local_end = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $end->sql(), new \DateTimeZone( 'UTC' ) )->setTimezone( $tz );
		if ( ! is_string( $day['local_date'] ) || $day['local_date'] !== $local->format( 'Y-m-d' ) || $local_start->format( 'Y-m-d H:i:s.u' ) !== $day['local_date'] . ' 00:00:00.000000' || $local_end->format( 'Y-m-d H:i:s.u' ) !== $local->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00.000000' || $start->compare( $anchor->evaluated_at() ) > 0 || $end->compare( $anchor->evaluated_at() ) <= 0 || ( null !== $anchor->accept_until() && $anchor->accept_until()->compare( $end ) > 0 ) ) { PromiseShape::invalid(); }
		return [ 'local_date' => $day['local_date'], 'timezone' => $zone, 'start_at' => $start->sql(), 'end_at' => $end->sql() ];
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json ) ); }
	public function policy(): ServicePromisePolicy { return ServicePromisePolicy::from_array( $this->data['policy'] ); }
	public function anchor(): PromiseAnchor { return PromiseAnchor::from_array( $this->data['anchor'] ); }
	public function capacity(): PromiseCapacityObservation { return PromiseCapacityObservation::from_array( $this->data['capacity'] ); }
	public static function endpoint_digest( array $endpoint ): string { return hash( 'sha256', 'cetech-service-promise-endpoint-v1:' . PromiseJson::encode( self::endpoint( $endpoint ) ) ); }
	public function site_id(): string { return $this->data['site_id']; }
	public function private_facts(): array { return $this->data; }
	public function to_private_json(): string { return PromiseJson::encode( $this->data ); }
	public function digest(): string { return hash( 'sha256', 'cetech-service-promise-input-v1:' . $this->to_private_json() ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise projection is required.' ); }
}
