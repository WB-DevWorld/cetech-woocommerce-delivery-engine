<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\ServicePromise\Calculation;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput, ServicePromisePolicy};

/** Detached vectors only; no clocks, WordPress, database or application callbacks. */
final class CalculationFixture {
	public const EVALUATED = '2026-10-09 10:00:00.000000';
	/** Explicit fixture-side host capture, supplied independently to calculator composition. */
	public static function runtime(): array { return [ 'timezone_data_version' => timezone_version_get(), 'runtime_id' => 'captured-runtime', 'digest' => hash( 'sha256', 'runtime:' . timezone_version_get() ) ]; }
	public static function source( string $id = 'merchant-processing' ): array { return [ 'format_version' => 1, 'site_id' => 'site-1', 'source_id' => $id, 'version' => 1, 'digest' => hash( 'sha256', $id ) ]; }
	public static function calendar( array $changes = [] ): BusinessCalendarVersion {
		$weekly = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $weekly[$day] = [ [ 'open' => '09:00', 'close' => '18:00' ] ]; }
		return BusinessCalendarVersion::from_array( array_replace( [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'working', 'version' => 1, 'timezone' => 'UTC', 'tzdata_version' => timezone_version_get(), 'weekly_openings' => $weekly, 'closed_dates' => [], 'exception_openings' => [], 'sources' => [] ], $changes ) );
	}
	public static function component( string $id = 'delivery', array $changes = [] ): array {
		return array_replace( [ 'format_version' => 1, 'component_id' => $id, 'role' => 'final_mile', 'duration' => [ 'format_version' => 1, 'min' => 10, 'max' => 20, 'unit' => 'elapsed_minutes', 'calendar' => null ], 'operating_calendar' => null, 'completion_window_rule' => 'none', 'predecessors' => [], 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep', 'source' => self::source() ], $changes );
	}
	/** @param list<BusinessCalendarVersion> $calendars */
	public static function policy( array $changes = [], array $calendars = [] ): ServicePromisePolicy {
		return ServicePromisePolicy::from_array( array_replace( [ 'format_version' => 1, 'site_id' => 'site-1', 'policy_id' => 'standard-policy', 'version' => 1, 'effective_from' => '2026-01-01 00:00:00.000000', 'effective_until' => null, 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard delivery' ], 'anchor' => 'checkout_capture', 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep', 'endpoint_terminal_component_ids' => [ 'delivery' ], 'scope' => [ 'kind' => 'global', 'target_id' => null ], 'promise_timezone' => 'UTC', 'graph' => [ 'format_version' => 1, 'components' => [ self::component() ], 'terminal_component_ids' => [ 'delivery' ] ], 'calendars' => array_map( static fn( BusinessCalendarVersion $calendar ): array => $calendar->reference()->private_facts(), $calendars ), 'cutoff' => null, 'day_constraint' => 'none', 'promise_required' => true, 'late_payment_rule' => 'refuse_if_infeasible', 'capacity_mode' => 'none', 'capacity_source' => null ], $changes ) );
	}
	public static function input( ?ServicePromisePolicy $policy = null, string $evaluated = self::EVALUATED, ?array $capacity = null, ?string $accept_until = null ): PromiseInput {
		$policy ??= self::policy(); $facts = $policy->private_facts(); $time = RuleTime::parse( $evaluated ); $expiry = RuleTime::from_epoch_microseconds( $time->epoch_microseconds() + 300000000 )->sql();
		$anchor = [ 'format_version' => 1, 'kind' => $facts['anchor'], 'evaluated_at' => $evaluated, 'quote_expires_at' => $expiry ] + match ( $facts['anchor'] ) { 'order_accepted' => [ 'accept_until' => $accept_until ?? $expiry ], 'checkout_capture' => [ 'capture_at' => $evaluated ], 'payment_confirmed' => [ 'awaited_event' => 'woocommerce_payment_confirmed' ] };
		$destination = [ 'endpoint' => $facts['endpoint'], 'endpoint_kind' => $facts['endpoint_kind'], 'identity_digest' => hash( 'sha256', 'destination' ) ]; $sources = [];
		foreach ( $facts['graph']['components'] as $component ) { $sources[$component['source']['source_id']] = $component['source']; }
		if ( 'required' === $facts['capacity_mode'] ) {
			$sources[$facts['capacity_source']['source_id']] = $facts['capacity_source'];
			$capacity ??= [ 'format_version' => 1, 'mode' => 'required', 'site_id' => $facts['site_id'], 'service_digest' => $policy->service()->digest(), 'endpoint_digest' => PromiseInput::endpoint_digest( $destination ), 'window' => [ 'from' => $evaluated, 'until' => RuleTime::from_epoch_microseconds( $time->epoch_microseconds() + 86400000000 )->sql(), 'display_timezone' => $facts['promise_timezone'] ], 'source' => $facts['capacity_source'], 'revision' => 1, 'state' => 'available', 'observed_at' => $evaluated, 'valid_until' => $expiry ];
		} else { $capacity ??= [ 'format_version' => 1, 'mode' => 'none' ]; }
		$day = null;
		if ( 'none' !== $facts['day_constraint'] ) {
			$local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $evaluated, new \DateTimeZone( 'UTC' ) )->setTimezone( new \DateTimeZone( $facts['promise_timezone'] ) ); $start = $local->setTime( 0, 0 ); $end = $start->modify( '+1 day' );
			$day = [ 'local_date' => $local->format( 'Y-m-d' ), 'timezone' => $facts['promise_timezone'], 'start_at' => $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ), 'end_at' => $end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ) ];
			if ( 'order_accepted' === $facts['anchor'] && strcmp( $anchor['accept_until'], $day['end_at'] ) > 0 ) { $anchor['accept_until'] = $day['end_at']; }
		}
		return PromiseInput::from_array( [ 'format_version' => 1, 'site_id' => $facts['site_id'], 'evaluated_at' => $evaluated, 'owner' => [ 'site_id' => $facts['site_id'], 'kind' => 'customer', 'principal_hash' => hash( 'sha256', 'principal' ), 'session_hash' => hash( 'sha256', 'session' ), 'key_epoch' => 'epoch-1' ], 'material' => [ 'group_id' => 'group-1', 'material_digest' => hash( 'sha256', 'material' ) ], 'origin' => [ 'endpoint' => 'merchant-origin', 'endpoint_kind' => 'origin', 'identity_digest' => hash( 'sha256', 'origin' ) ], 'destination' => $destination, 'policy' => $facts, 'calendar_refs' => $facts['calendars'], 'source_receipts' => array_values( $sources ), 'anchor' => $anchor, 'service_day' => $day, 'capacity' => $capacity, 'runtime' => self::runtime() ] );
	}
}
