<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Calculation;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput, PromiseLimits};

/** Pure complete graph transfer. No partial branch supplies a winning result. */
final readonly class PromiseGraphTransfer {
	public function __construct( private PromiseCalendarArithmetic $arithmetic = new PromiseCalendarArithmetic() ) {}

	/**
	 * @param array<string, BusinessCalendarVersion> $calendars Exact captured versions, keyed by calendar ID.
	 * Endpoint summaries are private maxima, not an added graph edge or public joint promise.
	 * @return array{components:array, terminal_windows:array, endpoint_windows:array}
	 */
	public function transfer( PromiseInput $input, array $calendars, RuleTime $lower_anchor, RuleTime $upper_anchor, PromiseCalculationBudget $budget ): array {
		$anchor = $input->anchor(); $origin = $anchor->evaluated_at(); $policy = $input->policy(); $display_zone = $policy->promise_timezone();
		if ( 'payment_confirmed' === $anchor->kind() ) { throw new PromiseCalculationException( 'unsupported_anchor' ); }
		if ( $lower_anchor->compare( $origin ) < 0 || $upper_anchor->compare( $lower_anchor ) < 0 ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		$this->exact_calendars( $input, $calendars, $budget );
		$graph = $policy->graph()->private_facts();
		$by_id = []; $remaining = []; $successors = []; $ready = [];
		foreach ( $graph['components'] as $component ) {
			$id = $component['component_id']; $key = ':' . $id;
			$by_id[$key] = $component; $remaining[$key] = count( $component['predecessors'] );
			if ( 0 === $remaining[$key] ) { $ready[] = $id; }
			foreach ( $component['predecessors'] as $predecessor ) { $budget->consume(); $successors[':' . $predecessor][] = $id; }
		}
		sort( $ready, SORT_STRING ); $completed = [];
		while ( [] !== $ready ) {
			$budget->consume(); $id = array_shift( $ready ); $key = ':' . $id; $component = $by_id[$key];
			$from = $lower_anchor; $until = $upper_anchor;
			foreach ( $component['predecessors'] as $predecessor ) {
				$budget->consume(); $previous = $completed[':' . $predecessor];
				$from = self::maximum( $from, $previous['from'] ); $until = self::maximum( $until, $previous['until'] );
			}
			$operating = null === $component['operating_calendar'] ? null : $calendars[$component['operating_calendar']['calendar_id']];
			$lower_interval = null; $upper_interval = null;
			if ( null !== $operating ) {
				$this->admission_range( $from, $until, $operating, $budget );
				$from = $this->arithmetic->next_start( $from, $operating, $budget );
				$until = $this->arithmetic->next_start( $until, $operating, $budget );
				if ( 'within_open_interval' === $component['completion_window_rule'] ) {
					$lower_interval = $this->arithmetic->containing_interval( $from, $operating, $budget );
					$upper_interval = $this->arithmetic->containing_interval( $until, $operating, $budget );
					// Different admitted intervals can hide an infeasible interior accepted instant.
					if ( null === $lower_interval || null === $upper_interval || ! $lower_interval['start']->equals( $upper_interval['start'] ) || ! $lower_interval['end']->equals( $upper_interval['end'] ) ) { throw new PromiseCalculationException( 'outside_service_window' ); }
				}
			}
			$this->horizon( $origin, $display_zone, $from, $component, $calendars, $budget );
			$this->horizon( $origin, $display_zone, $until, $component, $calendars, $budget );
			$duration = $component['duration']; $duration_calendar = null === $duration['calendar'] ? null : $calendars[$duration['calendar']['calendar_id']];
			// Each fixed-duration transfer must be defined and nondecreasing over the complete input range.
			$this->arithmetic->prove_monotonic( $from, $until, $duration['min'], $duration['unit'], $duration_calendar, $budget );
			if ( $duration['max'] !== $duration['min'] ) { $this->arithmetic->prove_monotonic( $from, $until, $duration['max'], $duration['unit'], $duration_calendar, $budget ); }
			$lower = $this->arithmetic->add( $from, $duration['min'], $duration['unit'], $duration_calendar, $budget );
			$upper = $this->arithmetic->add( $until, $duration['max'], $duration['unit'], $duration_calendar, $budget );
			if ( $lower->compare( $from ) < 0 || $upper->compare( $until ) < 0 || $lower->compare( $upper ) > 0 ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
			if ( null !== $lower_interval && ( $lower->compare( $lower_interval['end'] ) > 0 || $upper->compare( $upper_interval['end'] ) > 0 ) ) { throw new PromiseCalculationException( 'outside_service_window' ); }
			$this->horizon( $origin, $display_zone, $lower, $component, $calendars, $budget );
			$this->horizon( $origin, $display_zone, $upper, $component, $calendars, $budget );
			$completed[$key] = [ 'component_id' => $id, 'start_from' => $from, 'start_until' => $until, 'from' => $lower, 'until' => $upper ];
			foreach ( $successors[$key] ?? [] as $successor ) { if ( 0 === --$remaining[':' . $successor] ) { $ready[] = $successor; } }
			sort( $ready, SORT_STRING );
		}
		if ( count( $completed ) !== count( $by_id ) ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		ksort( $completed, SORT_STRING ); $terminals = []; $endpoints = [];
		foreach ( $graph['terminal_component_ids'] as $id ) {
			$budget->consume(); $key = ':' . $id; $window = $completed[$key]; $component = $by_id[$key];
			$terminals[] = [ 'component_id' => $id, 'from' => $window['from']->sql(), 'until' => $window['until']->sql() ];
			// Private aggregation retains every sink; only declared predecessor edges establish a graph join.
			// The delimiter cannot collide because endpoint_kind is a closed enum without colons.
			$endpoint_key = $component['endpoint_kind'] . ':' . $component['endpoint'];
			if ( ! isset( $endpoints[$endpoint_key] ) ) { $endpoints[$endpoint_key] = [ 'endpoint' => $component['endpoint'], 'endpoint_kind' => $component['endpoint_kind'], 'component_ids' => [], 'from' => $window['from'], 'until' => $window['until'] ]; }
			$endpoints[$endpoint_key]['component_ids'][] = $id;
			$endpoints[$endpoint_key]['from'] = self::maximum( $endpoints[$endpoint_key]['from'], $window['from'] );
			$endpoints[$endpoint_key]['until'] = self::maximum( $endpoints[$endpoint_key]['until'], $window['until'] );
		}
		ksort( $endpoints, SORT_STRING );
		foreach ( $endpoints as &$endpoint ) { sort( $endpoint['component_ids'], SORT_STRING ); $endpoint['from'] = $endpoint['from']->sql(); $endpoint['until'] = $endpoint['until']->sql(); } unset( $endpoint );
		return [ 'components' => array_values( $completed ), 'terminal_windows' => $terminals, 'endpoint_windows' => array_values( $endpoints ) ];
	}

	private function exact_calendars( PromiseInput $input, array $calendars, PromiseCalculationBudget $budget ): void {
		$refs = $input->private_facts()['calendar_refs'];
		if ( count( $calendars ) !== count( $refs ) ) { throw new PromiseCalculationException( count( $calendars ) < count( $refs ) ? 'missing_source' : 'source_changed' ); }
		foreach ( $refs as $ref ) {
			$budget->consume(); $calendar = $calendars[$ref['calendar_id']] ?? null;
			if ( ! $calendar instanceof BusinessCalendarVersion ) { throw new PromiseCalculationException( 'missing_source' ); }
			if ( $calendar->reference()->private_facts() !== $ref ) { throw new PromiseCalculationException( 'source_changed' ); }
		}
	}

	/** Resolve every admitted interval, so an interior gap/fold cannot hide behind valid endpoints. */
	private function admission_range( RuleTime $from, RuleTime $until, BusinessCalendarVersion $calendar, PromiseCalculationBudget $budget ): void {
		$cursor = $from;
		while ( $cursor->compare( $until ) < 0 ) {
			$budget->consume(); $start = $this->arithmetic->next_start( $cursor, $calendar, $budget );
			if ( $start->compare( $until ) >= 0 ) { return; }
			$interval = $this->arithmetic->containing_interval( $start, $calendar, $budget );
			if ( null === $interval || $interval['end']->compare( $cursor ) <= 0 ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
			$cursor = $interval['end'];
		}
	}

	/** The horizon is from the original capture, never reset at a succeeding leg. */
	private function horizon( RuleTime $origin, string $display_zone, RuleTime $instant, array $component, array $calendars, PromiseCalculationBudget $budget ): void {
		$zones = [ $display_zone ];
		foreach ( [ $component['operating_calendar'], $component['duration']['calendar'] ] as $ref ) { if ( null !== $ref ) { $zones[] = $calendars[$ref['calendar_id']]->timezone(); } }
		$utc = new \DateTimeZone( 'UTC' );
		foreach ( array_unique( $zones ) as $zone ) {
			$budget->consume(); $timezone = new \DateTimeZone( $zone );
			$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $origin->sql(), $utc )->setTimezone( $timezone );
			$end = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $instant->sql(), $utc )->setTimezone( $timezone );
			$start_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start->format( 'Y-m-d' ), $utc );
			$end_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end->format( 'Y-m-d' ), $utc );
			if ( (int) $start_date->diff( $end_date )->format( '%r%a' ) > PromiseLimits::LOOKAHEAD_DAYS ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
		}
	}

	private static function maximum( RuleTime $left, RuleTime $right ): RuleTime { return $left->compare( $right ) >= 0 ? $left : $right; }
}
