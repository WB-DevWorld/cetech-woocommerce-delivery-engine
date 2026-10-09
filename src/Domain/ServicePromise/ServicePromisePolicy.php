<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Detached immutable policy proposal; no active resolver, publication or placement authority. */
final readonly class ServicePromisePolicy implements \JsonSerializable {
	public const FORMAT = 1;
	private function __construct( private string $json ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data, PromiseLimits::RECORD_BYTES );
		PromiseShape::fields( $data, [ 'format_version', 'site_id', 'policy_id', 'version', 'effective_from', 'effective_until', 'service', 'anchor', 'endpoint', 'endpoint_kind', 'endpoint_terminal_component_ids', 'scope', 'promise_timezone', 'graph', 'calendars', 'cutoff', 'day_constraint', 'promise_required', 'late_payment_rule', 'capacity_mode', 'capacity_source' ] );
		if ( self::FORMAT !== $data['format_version'] ) { PromiseShape::invalid(); }
		PromiseShape::id( $data['site_id'] ); PromiseShape::id( $data['policy_id'] ); PromiseShape::integer( $data['version'], 1, PromiseLimits::VERSION_MAX );
		$from = PromiseShape::instant( $data['effective_from'] ); $until = null === $data['effective_until'] ? null : PromiseShape::instant( $data['effective_until'] );
		if ( null !== $until && $from->compare( $until ) >= 0 ) { PromiseShape::invalid(); }
		$data['service'] = ServiceIdentity::from_array( PromiseShape::object( $data['service'] ) )->private_facts();
		PromiseShape::choice( $data['anchor'], [ 'order_accepted', 'checkout_capture', 'payment_confirmed' ] ); PromiseShape::id( $data['endpoint'] ); PromiseShape::choice( $data['endpoint_kind'], [ 'doorstep', 'pickup', 'port', 'handover' ] ); PromiseShape::timezone( $data['promise_timezone'] );
		$scope = PromiseShape::object( $data['scope'] ); PromiseShape::fields( $scope, [ 'kind', 'target_id' ] ); PromiseShape::choice( $scope['kind'], [ 'global', 'product', 'variation' ] );
		if ( 'global' === $scope['kind'] ) { if ( null !== $scope['target_id'] ) { PromiseShape::invalid(); } } else { PromiseShape::integer( $scope['target_id'], 1 ); } $data['scope'] = $scope;
		$graph = PromiseGraph::from_array( PromiseShape::object( $data['graph'] ) ); $data['graph'] = $graph->private_facts();
		$selected = PromiseShape::list( $data['endpoint_terminal_component_ids'], 1, PromiseLimits::GRAPH_NODES ); $seen = [];
		foreach ( $selected as $id ) { PromiseShape::id( $id ); if ( isset( $seen[':' . $id] ) ) { PromiseShape::invalid(); } $seen[':' . $id] = true; } sort( $selected, SORT_STRING );
		$expected = []; $sources = [];
		foreach ( $data['graph']['components'] as $component ) {
			if ( $data['site_id'] !== $component['source']['site_id'] ) { PromiseShape::invalid(); }
			$sources[':' . $component['source']['source_id']] = PromiseJson::encode( $component['source'] );
			if ( in_array( $component['component_id'], $data['graph']['terminal_component_ids'], true ) && $component['endpoint'] === $data['endpoint'] && $component['endpoint_kind'] === $data['endpoint_kind'] ) {
				if ( 'doorstep' === $data['endpoint_kind'] && 'final_mile' !== $component['role'] ) { PromiseShape::invalid(); } $expected[] = $component['component_id'];
			}
		} sort( $expected, SORT_STRING ); if ( $selected !== $expected ) { PromiseShape::invalid(); } $data['endpoint_terminal_component_ids'] = $selected;
		$calendars = PromiseShape::list( $data['calendars'], 0, PromiseLimits::CALENDARS ); $refs = [];
		foreach ( $calendars as $calendar ) {
			$ref = PromiseCalendarReference::from_array( PromiseShape::object( $calendar ) ); if ( $ref->site_id() !== $data['site_id'] ) { PromiseShape::invalid(); }
			$key = ':' . $ref->calendar_id(); $json = $ref->to_private_json(); if ( isset( $refs[$key] ) && $refs[$key] !== $json ) { PromiseShape::invalid(); } $refs[$key] = $json;
		} ksort( $refs, SORT_STRING ); $data['calendars'] = array_map( static fn( string $json ): array => PromiseCalendarReference::from_json( $json )->private_facts(), array_values( $refs ) );
		foreach ( $data['graph']['components'] as $component ) {
			if ( null !== $component['duration']['calendar'] ) { self::declared_calendar( $component['duration']['calendar'], $refs ); }
			if ( null !== $component['operating_calendar'] ) { self::declared_calendar( $component['operating_calendar'], $refs ); }
		}
		if ( null !== $data['cutoff'] ) {
			$cutoff = PromiseShape::object( $data['cutoff'] ); PromiseShape::choice( $cutoff['kind'] ?? null, [ 'local_time', 'before_close' ] );
			PromiseShape::fields( $cutoff, 'local_time' === $cutoff['kind'] ? [ 'kind', 'calendar', 'time', 'missed_window_rule' ] : [ 'kind', 'calendar', 'minutes_before_close', 'missed_window_rule' ] );
			$cutoff['calendar'] = self::declared_calendar( PromiseShape::object( $cutoff['calendar'] ), $refs );
			if ( 'local_time' === $cutoff['kind'] ) { if ( ! is_string( $cutoff['time'] ) || 1 !== preg_match( '/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/D', $cutoff['time'] ) ) { PromiseShape::invalid(); } }
			else { PromiseShape::integer( $cutoff['minutes_before_close'], 1, 1440 ); }
			PromiseShape::choice( $cutoff['missed_window_rule'], [ 'reject', 'next_opening' ] ); $data['cutoff'] = $cutoff;
		}
		PromiseShape::choice( $data['day_constraint'], [ 'none', 'same_day', 'next_day' ] ); PromiseShape::boolean( $data['promise_required'] );
		if ( 'built_in' === $data['service']['kind'] ) {
			$constraint = match ( $data['service']['code'] ) { 'same_day' => 'same_day', 'next_day' => 'next_day', default => 'none' };
			if ( $data['day_constraint'] !== $constraint ) { PromiseShape::invalid(); }
		}
		PromiseShape::choice( $data['late_payment_rule'], [ 'refuse_if_infeasible', 'require_new_review', 'relative_after_payment' ] );
		if ( 'payment_confirmed' === $data['anchor'] ) {
			if ( 'none' !== $data['day_constraint'] || 'relative_after_payment' !== $data['late_payment_rule'] ) { PromiseShape::invalid(); }
			if ( 'built_in' === $data['service']['kind'] && ! in_array( $data['service']['code'], [ 'standard', 'relaxed' ], true ) ) { PromiseShape::invalid(); }
		} elseif ( 'relative_after_payment' === $data['late_payment_rule'] ) { PromiseShape::invalid(); }
		PromiseShape::choice( $data['capacity_mode'], [ 'none', 'required' ] );
		if ( 'none' === $data['capacity_mode'] ) { if ( null !== $data['capacity_source'] ) { PromiseShape::invalid(); } }
		else {
			$source = PromiseShape::object( $data['capacity_source'] ); PromiseShape::fields( $source, [ 'format_version', 'site_id', 'source_id', 'version', 'digest' ] );
			if ( 1 !== $source['format_version'] || $data['site_id'] !== $source['site_id'] ) { PromiseShape::invalid(); }
			PromiseShape::id( $source['source_id'] ); PromiseShape::integer( $source['version'], 1, PromiseLimits::VERSION_MAX ); PromiseShape::digest( $source['digest'] );
			$key = ':' . $source['source_id']; if ( isset( $sources[$key] ) && $sources[$key] !== PromiseJson::encode( $source ) ) { PromiseShape::invalid(); } $data['capacity_source'] = $source;
		}
		return new self( PromiseJson::encode( $data, PromiseLimits::RECORD_BYTES ) );
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json, PromiseLimits::RECORD_BYTES ) ); }
	public function private_facts(): array { return PromiseJson::decode( $this->json, PromiseLimits::RECORD_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-service-promise-policy-v1:' . $this->json ); }
	public function reference(): PromisePolicyReference { $data = $this->private_facts(); return PromisePolicyReference::from_array( [ 'format_version' => 1, 'site_id' => $data['site_id'], 'policy_id' => $data['policy_id'], 'version' => $data['version'], 'digest' => $this->digest() ] ); }
	public function site_id(): string { return $this->private_facts()['site_id']; }
	public function service(): ServiceIdentity { return ServiceIdentity::from_array( $this->private_facts()['service'] ); }
	public function graph(): PromiseGraph { return PromiseGraph::from_array( $this->private_facts()['graph'] ); }
	/** @return list<PromiseCalendarReference> */
	public function calendars(): array { return array_map( PromiseCalendarReference::from_array( ... ), $this->private_facts()['calendars'] ); }
	public function anchor(): string { return $this->private_facts()['anchor']; }
	public function endpoint(): string { return $this->private_facts()['endpoint']; }
	public function endpoint_kind(): string { return $this->private_facts()['endpoint_kind']; }
	public function endpoint_terminal_component_ids(): array { return $this->private_facts()['endpoint_terminal_component_ids']; }
	public function promise_timezone(): string { return $this->private_facts()['promise_timezone']; }
	public function effective_from(): RuleTime { return RuleTime::parse( $this->private_facts()['effective_from'] ); }
	public function effective_until(): ?RuleTime { $value = $this->private_facts()['effective_until']; return null === $value ? null : RuleTime::parse( $value ); }
	public function capacity_mode(): string { return $this->private_facts()['capacity_mode']; }
	public function capacity_source(): ?array { return $this->private_facts()['capacity_source']; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicitly authorized service promise projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise values cannot be implicitly serialized.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise values require an explicit validated factory.' ); }
	private static function declared_calendar( array $data, array $refs ): array {
		$ref = PromiseCalendarReference::from_array( $data ); if ( ( $refs[':' . $ref->calendar_id()] ?? null ) !== $ref->to_private_json() ) { PromiseShape::invalid(); } return $ref->private_facts();
	}
}
