<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\ServicePromise;

/** Closed captured result, not a calculator or native feasibility proof. Every graph sink stays explicit. */
final readonly class PromiseResult implements \JsonSerializable {
	public const REASON_CODES = [ 'estimate_unavailable', 'missing_source', 'unsupported_policy', 'capacity_unknown', 'capacity_unavailable', 'capacity_stale', 'outside_service_window', 'missing_destination', 'unsupported_anchor', 'budget_exceeded', 'unknown_timezone', 'source_changed', 'acceptance_expired' ];
	private function __construct( private array $data ) {}
	public static function from_array( array $data ): self {
		$data = PromiseJson::detach( $data );
		PromiseShape::fields( $data, [ 'format_version', 'state', 'input', 'input_digest', 'graph_digest', 'body', 'reason_codes' ] );
		PromiseShape::integer( $data['format_version'], 1, 1 );
		$state = PromiseShape::choice( $data['state'], [ 'absolute_window', 'relative_window', 'unavailable', 'ineligible' ] );
		$input = PromiseInput::from_array( PromiseShape::object( $data['input'] ) ); $policy = $input->policy();
		if ( ! hash_equals( PromiseShape::digest( $data['input_digest'] ), $input->digest() ) || ! hash_equals( PromiseShape::digest( $data['graph_digest'] ), $policy->graph()->digest() ) ) { PromiseShape::invalid(); }
		$reasons = self::reason_codes( $data['reason_codes'] );
		if ( in_array( $state, [ 'unavailable', 'ineligible' ], true ) ) {
			if ( null !== $data['body'] || [] === $reasons ) { PromiseShape::invalid(); }
			$body = null;
		} else {
			if ( [] !== $reasons ) { PromiseShape::invalid(); }
			$body = PromiseShape::object( $data['body'] );
			PromiseShape::fields( $body, 'absolute_window' === $state ? [ 'display_timezone', 'terminal_windows' ] : [ 'display_timezone', 'awaited_event', 'terminal_windows' ] );
			$zone = PromiseShape::timezone( $body['display_timezone'] );
			if ( $zone !== $policy->promise_timezone() || ( 'relative_window' === $state ) !== ( 'payment_confirmed' === $input->anchor()->kind() ) ) { PromiseShape::invalid(); }
			if ( 'relative_window' === $state && 'required' === $input->capacity()->mode() ) { PromiseShape::invalid(); }
			if ( 'relative_window' === $state ) { PromiseShape::choice( $body['awaited_event'], [ 'woocommerce_payment_confirmed' ] ); }
			$windows = []; $seen = []; $graph = $policy->graph()->private_facts();
			$terminals = $graph['terminal_component_ids'];
			foreach ( PromiseShape::list( $body['terminal_windows'], 1, PromiseLimits::GRAPH_NODES ) as $raw ) {
				$window = PromiseShape::object( $raw );
				PromiseShape::fields( $window, 'absolute_window' === $state ? [ 'component_id', 'from', 'until' ] : [ 'component_id', 'min', 'max', 'unit', 'calendar_refs', 'known_zero' ] );
				$id = PromiseShape::id( $window['component_id'] );
				if ( isset( $seen[$id] ) || ! in_array( $id, $terminals, true ) ) { PromiseShape::invalid(); } $seen[$id] = true;
				if ( 'absolute_window' === $state ) {
					$from = PromiseShape::instant( $window['from'] ); $until = PromiseShape::instant( $window['until'] );
					if ( $from->compare( $until ) > 0 || $from->compare( $input->anchor()->evaluated_at() ) < 0 || self::local_date_span( $input->anchor()->evaluated_at()->sql(), $until->sql(), $zone ) > PromiseLimits::LOOKAHEAD_DAYS ) { PromiseShape::invalid(); }
					$windows[] = [ 'component_id' => $id, 'from' => $from->sql(), 'until' => $until->sql() ];
				} else {
					$min = PromiseShape::integer( $window['min'], 0 ); $max = PromiseShape::integer( $window['max'], $min ); $zero = PromiseShape::boolean( $window['known_zero'] );
					$unit = PromiseShape::choice( $window['unit'], [ 'elapsed_minutes', 'calendar_days', 'business_minutes', 'business_days' ] );
					if ( $zero !== ( 0 === $min && 0 === $max ) ) { PromiseShape::invalid(); }
					$refs = self::calendar_refs( $window['calendar_refs'], $input->private_facts()['calendar_refs'] );
					if ( 'elapsed_minutes' !== $unit && [] === $refs ) { PromiseShape::invalid(); }
					$windows[] = [ 'component_id' => $id, 'min' => $min, 'max' => $max, 'unit' => $unit, 'calendar_refs' => $refs, 'known_zero' => $zero ];
				}
			}
			if ( count( $seen ) !== count( $terminals ) ) { PromiseShape::invalid(); }
			usort( $windows, static fn( array $a, array $b ): int => strcmp( $a['component_id'], $b['component_id'] ) );
			$body = [ 'display_timezone' => $zone, 'terminal_windows' => $windows ];
			if ( 'relative_window' === $state ) { $body['awaited_event'] = 'woocommerce_payment_confirmed'; }
			self::winning_capacity( $input, $body, $state );
			self::literal_day( $input, $body, $state );
		}
		return new self( PromiseJson::detach( [ 'format_version' => 1, 'state' => $state, 'input' => $input->private_facts(), 'input_digest' => $input->digest(), 'graph_digest' => $policy->graph()->digest(), 'body' => $body, 'reason_codes' => $reasons ] ) );
	}
	public static function reason_codes( mixed $raw ): array {
		$codes = []; foreach ( PromiseShape::list( $raw, 0, count( self::REASON_CODES ) ) as $code ) { $codes[] = PromiseShape::choice( $code, self::REASON_CODES ); }
		$codes = array_values( array_unique( $codes ) ); sort( $codes, SORT_STRING ); return $codes;
	}
	private static function calendar_refs( mixed $raw, array $expected ): array {
		$refs = [];
		foreach ( PromiseShape::list( $raw, 0, PromiseLimits::CALENDARS ) as $item ) {
			$ref = PromiseCalendarReference::from_array( PromiseShape::object( $item ) )->private_facts();
			$key = $ref['calendar_id'];
			if ( isset( $refs[$key] ) && PromiseJson::encode( $refs[$key] ) !== PromiseJson::encode( $ref ) ) { PromiseShape::invalid(); }
			$refs[$key] = $ref;
		}
		$refs = array_values( $refs ); usort( $refs, static fn( array $a, array $b ): int => strcmp( $a['calendar_id'], $b['calendar_id'] ) );
		foreach ( $refs as $ref ) { if ( ! in_array( $ref, $expected, true ) ) { PromiseShape::invalid(); } }
		return $refs;
	}
	private static function winning_capacity( PromiseInput $input, array $body, string $state ): void {
		$capacity = $input->capacity()->private_facts(); if ( 'none' === $capacity['mode'] ) { return; }
		if ( 'absolute_window' !== $state || 'available' !== $capacity['state'] || PromiseShape::instant( $capacity['valid_until'] )->compare( $input->anchor()->evaluated_at() ) <= 0 || ( null !== $input->anchor()->accept_until() && PromiseShape::instant( $capacity['valid_until'] )->compare( $input->anchor()->accept_until() ) < 0 ) ) { PromiseShape::invalid(); }
		foreach ( $body['terminal_windows'] as $window ) {
			if ( ! in_array( $window['component_id'], $input->policy()->endpoint_terminal_component_ids(), true ) ) { continue; }
			if ( PromiseShape::instant( $window['from'] )->compare( PromiseShape::instant( $capacity['window']['from'] ) ) < 0 || PromiseShape::instant( $window['until'] )->compare( PromiseShape::instant( $capacity['window']['until'] ) ) > 0 ) { PromiseShape::invalid(); }
		}
	}
	/** Civil-date bound, deliberately independent of DST's elapsed day length. */
	private static function local_date_span( string $from, string $until, string $zone ): int {
		$utc = new \DateTimeZone( 'UTC' ); $display = new \DateTimeZone( $zone );
		$start_date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $from, $utc )->setTimezone( $display )->format( 'Y-m-d' );
		$end_date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $until, $utc )->setTimezone( $display )->format( 'Y-m-d' );
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, $utc ); $end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, $utc );
		return (int) $start->diff( $end )->format( '%r%a' );
	}
	private static function literal_day( PromiseInput $input, array $body, string $state ): void {
		$day = $input->private_facts()['service_day']; if ( null === $day ) { return; }
		if ( 'absolute_window' !== $state ) { PromiseShape::invalid(); }
		$zone = new \DateTimeZone( $body['display_timezone'] );
		// A skipped local date stays the literal next date, never silently day two.
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day['local_date'], new \DateTimeZone( 'UTC' ) );
		$target = 'next_day' === $input->policy()->private_facts()['day_constraint'] ? $date->modify( '+1 day' )->format( 'Y-m-d' ) : $date->format( 'Y-m-d' );
		foreach ( $body['terminal_windows'] as $window ) {
			if ( ! in_array( $window['component_id'], $input->policy()->endpoint_terminal_component_ids(), true ) ) { continue; }
			foreach ( [ 'from', 'until' ] as $field ) {
				$local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $window[$field], new \DateTimeZone( 'UTC' ) )->setTimezone( $zone );
				if ( $local->format( 'Y-m-d' ) !== $target ) { PromiseShape::invalid(); }
			}
		}
	}
	public static function from_json( string $json ): self { return self::from_array( PromiseJson::decode( $json ) ); }
	public function state(): string { return $this->data['state']; }
	public function input(): PromiseInput { return PromiseInput::from_array( $this->data['input'] ); }
	public function private_facts(): array { return $this->data; }
	public function to_private_json(): string { return PromiseJson::encode( $this->data ); }
	public function digest(): string { return hash( 'sha256', 'cetech-service-promise-result-v1:' . $this->to_private_json() ); }
	public function __serialize(): never { throw new \LogicException( 'Private service promise serialization is forbidden.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Private service promise unserialization is forbidden.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized promise projection is required.' ); }
}
