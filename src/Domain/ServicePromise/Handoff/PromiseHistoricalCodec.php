<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Domain\ServicePromise\Handoff;

use CetechDeliveryEngine\Domain\ServicePromise\{PromiseAnchor, PromiseCalendarReference, PromiseCapacityObservation, PromiseJson, PromiseLimits, PromiseResult, PromiseShape, ServicePromisePolicy};

/** Frozen format-1 structure and captured UTC links. Never reinterprets civil dates using current tzdata. */
final class PromiseHistoricalCodec {
	public static function zone( mixed $zone ): string { $zone = PromiseShape::text( $zone, 128 ); if ( 1 !== preg_match( '/\A[A-Za-z][A-Za-z0-9_+\/-]*\z/D', $zone ) ) { PromiseShape::invalid(); } return $zone; }
	public static function input( string $json ): array {
		$data = PromiseJson::decode( $json ); if ( PromiseJson::encode( $data ) !== $json ) { PromiseShape::invalid(); }
		PromiseShape::fields( $data, [ 'format_version', 'site_id', 'evaluated_at', 'owner', 'material', 'origin', 'destination', 'policy', 'calendar_refs', 'source_receipts', 'anchor', 'service_day', 'capacity', 'runtime' ] ); PromiseShape::integer( $data['format_version'], 1, 1 ); $site = PromiseShape::id( $data['site_id'] ); $at = PromiseShape::instant( $data['evaluated_at'] );
		$owner = PromiseShape::object( $data['owner'] ); PromiseShape::fields( $owner, [ 'site_id', 'kind', 'principal_hash', 'session_hash', 'key_epoch' ] ); if ( PromiseShape::id( $owner['site_id'] ) !== $site ) { PromiseShape::invalid(); } PromiseShape::choice( $owner['kind'], [ 'customer', 'guest' ] ); foreach ( [ 'principal_hash', 'session_hash' ] as $key ) { PromiseShape::digest( $owner[$key] ); } PromiseShape::id( $owner['key_epoch'] );
		$material = PromiseShape::object( $data['material'] ); PromiseShape::fields( $material, [ 'group_id', 'material_digest' ] ); PromiseShape::id( $material['group_id'] ); PromiseShape::digest( $material['material_digest'] );
		foreach ( [ 'origin', 'destination' ] as $key ) { self::endpoint( $data[$key] ); }
		$policy = PromiseShape::object( $data['policy'] ); $zone = self::zone( $policy['promise_timezone'] ?? null ); $copy = $policy; $copy['promise_timezone'] = 'UTC'; $validated = ServicePromisePolicy::from_array( $copy )->private_facts(); $validated['promise_timezone'] = $zone;
		if ( PromiseJson::encode( $validated ) !== PromiseJson::encode( $policy ) || $policy['site_id'] !== $site || $data['destination']['endpoint'] !== $policy['endpoint'] || $data['destination']['endpoint_kind'] !== $policy['endpoint_kind'] ) { PromiseShape::invalid(); }
		$anchor = PromiseAnchor::from_array( PromiseShape::object( $data['anchor'] ) ); if ( $anchor->kind() !== $policy['anchor'] || ! $anchor->evaluated_at()->equals( $at ) || $at->compare( PromiseShape::instant( $policy['effective_from'] ) ) < 0 || ( null !== $policy['effective_until'] && ( $at->compare( PromiseShape::instant( $policy['effective_until'] ) ) >= 0 || ( null !== $anchor->accept_until() && $anchor->accept_until()->compare( PromiseShape::instant( $policy['effective_until'] ) ) > 0 ) ) ) ) { PromiseShape::invalid(); }
		$refs = []; foreach ( PromiseShape::list( $data['calendar_refs'], 0, PromiseLimits::CALENDARS ) as $ref ) { $typed = PromiseCalendarReference::from_array( PromiseShape::object( $ref ) ); if ( $typed->site_id() !== $site || isset( $refs[$typed->calendar_id()] ) ) { PromiseShape::invalid(); } $refs[$typed->calendar_id()] = $typed->private_facts(); } ksort( $refs, SORT_STRING );
		if ( array_values( $refs ) !== $data['calendar_refs'] || PromiseJson::encode( [ 'refs' => array_values( $refs ) ] ) !== PromiseJson::encode( [ 'refs' => $policy['calendars'] ] ) ) { PromiseShape::invalid(); }
		$expected = []; foreach ( $policy['graph']['components'] as $component ) { $expected[$component['source']['source_id']] = $component['source']; } if ( null !== $policy['capacity_source'] ) { $expected[$policy['capacity_source']['source_id']] = $policy['capacity_source']; } ksort( $expected, SORT_STRING ); $sources = [];
		foreach ( PromiseShape::list( $data['source_receipts'], 1, PromiseLimits::GRAPH_NODES + 1 ) as $source ) { $source = self::source( $source, $site ); if ( isset( $sources[$source['source_id']] ) ) { PromiseShape::invalid(); } $sources[$source['source_id']] = $source; } ksort( $sources, SORT_STRING ); if ( array_values( $sources ) !== $data['source_receipts'] || $sources !== $expected ) { PromiseShape::invalid(); }
		$capacity = PromiseShape::object( $data['capacity'] ); $cap_copy = $capacity;
		if ( 'required' === ( $capacity['mode'] ?? null ) ) { $cap_zone = self::zone( $capacity['window']['display_timezone'] ?? null ); $cap_copy['window']['display_timezone'] = 'UTC'; }
		$cap_typed = PromiseCapacityObservation::from_array( $cap_copy )->private_facts(); if ( 'required' === $cap_typed['mode'] ) { $cap_typed['window']['display_timezone'] = $cap_zone; }
		if ( PromiseJson::encode( $cap_typed ) !== PromiseJson::encode( $capacity ) || $capacity['mode'] !== $policy['capacity_mode'] ) { PromiseShape::invalid(); }
		if ( 'required' === $capacity['mode'] ) {
			$service_digest = hash( 'sha256', 'cetech-service-identity-v1:' . PromiseJson::encode( $policy['service'] ) );
			$endpoint_digest = hash( 'sha256', 'cetech-service-promise-endpoint-v1:' . PromiseJson::encode( $data['destination'] ) );
			if ( $capacity['site_id'] !== $site || ! hash_equals( $capacity['service_digest'], $service_digest ) || ! hash_equals( $capacity['endpoint_digest'], $endpoint_digest ) || $capacity['source'] !== $policy['capacity_source'] || $capacity['window']['display_timezone'] !== $zone || PromiseShape::instant( $capacity['observed_at'] )->compare( $at ) > 0 ) { PromiseShape::invalid(); }
		}
		$runtime = PromiseShape::object( $data['runtime'] ); PromiseShape::fields( $runtime, [ 'timezone_data_version', 'runtime_id', 'digest' ] ); PromiseShape::text( $runtime['timezone_data_version'], 128 ); PromiseShape::id( $runtime['runtime_id'] ); PromiseShape::digest( $runtime['digest'] );
		$day = $data['service_day'];
		if ( 'none' === $policy['day_constraint'] ) { if ( null !== $day ) { PromiseShape::invalid(); } }
		else {
			$day = PromiseShape::object( $day ); PromiseShape::fields( $day, [ 'local_date', 'timezone', 'start_at', 'end_at' ] ); if ( self::zone( $day['timezone'] ) !== $zone || 'payment_confirmed' === $anchor->kind() || ! is_string( $day['local_date'] ) || 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/D', $day['local_date'] ) ) { PromiseShape::invalid(); }
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $day['local_date'], new \DateTimeZone( 'UTC' ) ); if ( false === $date || $date->format( 'Y-m-d' ) !== $day['local_date'] ) { PromiseShape::invalid(); }
			$start = PromiseShape::instant( $day['start_at'] ); $end = PromiseShape::instant( $day['end_at'] ); if ( $start->compare( $at ) > 0 || $end->compare( $at ) <= 0 || ( null !== $anchor->accept_until() && $anchor->accept_until()->compare( $end ) > 0 ) ) { PromiseShape::invalid(); }
		}
		return $data;
	}
	public static function result( string $json, array $input, string $input_digest ): array {
		$data = PromiseJson::decode( $json ); if ( PromiseJson::encode( $data ) !== $json ) { PromiseShape::invalid(); } PromiseShape::fields( $data, [ 'format_version', 'state', 'input', 'input_digest', 'graph_digest', 'body', 'reason_codes' ] ); PromiseShape::integer( $data['format_version'], 1, 1 ); $state = PromiseShape::choice( $data['state'], [ 'absolute_window', 'relative_window', 'unavailable', 'ineligible' ] );
		if ( $data['input'] !== $input || ! hash_equals( PromiseShape::digest( $data['input_digest'] ), $input_digest ) || ! hash_equals( PromiseShape::digest( $data['graph_digest'] ), hash( 'sha256', 'cetech-promise-graph-v1:' . PromiseJson::encode( $input['policy']['graph'], PromiseLimits::RECORD_BYTES ) ) ) ) { PromiseShape::invalid(); }
		$reasons = PromiseResult::reason_codes( $data['reason_codes'] ); if ( in_array( $state, [ 'unavailable', 'ineligible' ], true ) ) { if ( $input['policy']['promise_required'] || null !== $data['body'] || [] === $reasons || $reasons !== $data['reason_codes'] ) { PromiseShape::invalid(); } return $data; } if ( [] !== $reasons ) { PromiseShape::invalid(); }
		$body = PromiseShape::object( $data['body'] ); PromiseShape::fields( $body, 'absolute_window' === $state ? [ 'display_timezone', 'terminal_windows' ] : [ 'display_timezone', 'awaited_event', 'terminal_windows' ] ); if ( self::zone( $body['display_timezone'] ) !== $input['policy']['promise_timezone'] || ( 'relative_window' === $state ) !== ( 'payment_confirmed' === $input['anchor']['kind'] ) ) { PromiseShape::invalid(); }
		if ( 'relative_window' === $state && ( 'woocommerce_payment_confirmed' !== $body['awaited_event'] || 'required' === $input['capacity']['mode'] ) ) { PromiseShape::invalid(); }
		$seen = []; foreach ( PromiseShape::list( $body['terminal_windows'], 1, PromiseLimits::GRAPH_NODES ) as $window ) {
			$window = PromiseShape::object( $window ); PromiseShape::fields( $window, 'absolute_window' === $state ? [ 'component_id', 'from', 'until' ] : [ 'component_id', 'min', 'max', 'unit', 'calendar_refs', 'known_zero' ] ); $id = PromiseShape::id( $window['component_id'] ); if ( isset( $seen[$id] ) || ! in_array( $id, $input['policy']['graph']['terminal_component_ids'], true ) ) { PromiseShape::invalid(); } $seen[$id] = true;
			if ( 'absolute_window' === $state ) { $from = PromiseShape::instant( $window['from'] ); $until = PromiseShape::instant( $window['until'] ); if ( $from->compare( $until ) > 0 || $from->compare( PromiseShape::instant( $input['evaluated_at'] ) ) < 0 ) { PromiseShape::invalid(); } }
			else { $min = PromiseShape::integer( $window['min'], 0 ); $max = PromiseShape::integer( $window['max'], $min ); if ( PromiseShape::boolean( $window['known_zero'] ) !== ( 0 === $max ) ) { PromiseShape::invalid(); } PromiseShape::choice( $window['unit'], [ 'elapsed_minutes', 'calendar_days', 'business_minutes', 'business_days' ] ); $refs = []; foreach ( PromiseShape::list( $window['calendar_refs'], 0, PromiseLimits::CALENDARS ) as $raw ) { $ref = PromiseCalendarReference::from_array( PromiseShape::object( $raw ) )->private_facts(); if ( ! in_array( $ref, $input['calendar_refs'], true ) || isset( $refs[$ref['calendar_id']] ) ) { PromiseShape::invalid(); } $refs[$ref['calendar_id']] = true; } $sorted = array_keys( $refs ); sort( $sorted, SORT_STRING ); if ( array_keys( $refs ) !== $sorted || ( 'elapsed_minutes' !== $window['unit'] && [] === $refs ) ) { PromiseShape::invalid(); } }
		}
		if ( array_keys( $seen ) !== $input['policy']['graph']['terminal_component_ids'] ) { PromiseShape::invalid(); }
		if ( 'required' === $input['capacity']['mode'] ) {
			$capacity = $input['capacity']; if ( 'available' !== $capacity['state'] || PromiseShape::instant( $capacity['valid_until'] )->compare( PromiseShape::instant( $input['evaluated_at'] ) ) <= 0 || ( isset( $input['anchor']['accept_until'] ) && PromiseShape::instant( $capacity['valid_until'] )->compare( PromiseShape::instant( $input['anchor']['accept_until'] ) ) < 0 ) ) { PromiseShape::invalid(); }
			foreach ( $body['terminal_windows'] as $window ) { if ( in_array( $window['component_id'], $input['policy']['endpoint_terminal_component_ids'], true ) && ( PromiseShape::instant( $window['from'] )->compare( PromiseShape::instant( $capacity['window']['from'] ) ) < 0 || PromiseShape::instant( $window['until'] )->compare( PromiseShape::instant( $capacity['window']['until'] ) ) > 0 ) ) { PromiseShape::invalid(); } }
		}
		return $data;
	}
	private static function endpoint( mixed $value ): void { $data = PromiseShape::object( $value ); PromiseShape::fields( $data, [ 'endpoint', 'endpoint_kind', 'identity_digest' ] ); PromiseShape::id( $data['endpoint'] ); PromiseShape::choice( $data['endpoint_kind'], [ 'origin', 'dispatch', 'port', 'handover', 'pickup', 'doorstep' ] ); PromiseShape::digest( $data['identity_digest'] ); }
	private static function source( mixed $value, string $site ): array { $data = PromiseShape::object( $value ); PromiseShape::fields( $data, [ 'format_version', 'site_id', 'source_id', 'version', 'digest' ] ); PromiseShape::integer( $data['format_version'], 1, 1 ); if ( PromiseShape::id( $data['site_id'] ) !== $site ) { PromiseShape::invalid(); } PromiseShape::id( $data['source_id'] ); PromiseShape::integer( $data['version'], 1, PromiseLimits::VERSION_MAX ); PromiseShape::digest( $data['digest'] ); return $data; }
}
