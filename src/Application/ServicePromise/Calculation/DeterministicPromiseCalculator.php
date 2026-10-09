<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Calculation;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput, PromiseJson, PromiseLimits, PromiseResult};
use CetechDeliveryEngine\Domain\ServicePromise\Port\PromiseCalculator;

/** Pure detached calculation. A winning estimate grants no placement, payment or reservation authority. */
final class DeterministicPromiseCalculator implements PromiseCalculator {
	private int $steps = 0;
	private readonly PromiseCalculationRuntime $runtime;
	public function __construct( array $captured_runtime ) { $this->runtime = new PromiseCalculationRuntime( $captured_runtime ); }
	public function last_steps(): int { return $this->steps; }
	public function calculate( PromiseInput $input, array $calendars ): PromiseResult {
		$budget = new PromiseCalculationBudget();
		try { return $this->calculate_with_budget( $input, $calendars, $budget ); }
		finally { $this->steps = $budget->used_steps(); }
	}
	/**
	 * @param list<PromiseInput> $inputs Already captured groups, at most the retained 200-group limit.
	 * @param list<BusinessCalendarVersion> $calendars Exact union of immutable references.
	 * @return list<PromiseResult> No winning group survives a required refusal or a whole-packet overflow.
	 * Oversized refusal lists are internal diagnostics, never an admissible serialized quote packet.
	 */
	public function calculate_cart( array $inputs, array $calendars ): array {
		if ( ! array_is_list( $inputs ) || [] === $inputs || count( $inputs ) > 200 ) { throw new \InvalidArgumentException( 'Invalid promise cart.' ); }
		foreach ( $inputs as $input ) { if ( ! $input instanceof PromiseInput ) { throw new \InvalidArgumentException( 'Invalid promise cart.' ); } }
		$budget = new PromiseCalculationBudget(); $results = [];
		try {
			$first = $inputs[0]->private_facts(); $groups = [];
			foreach ( $inputs as $input ) {
				$facts = $input->private_facts(); $group = $facts['material']['group_id'];
				if ( ! $this->runtime->matches( $facts['runtime'] ) ) { throw new PromiseCalculationException( 'source_changed' ); }
				if ( isset( $groups[$group] ) || $facts['site_id'] !== $first['site_id'] || $facts['owner'] !== $first['owner'] || $facts['evaluated_at'] !== $first['evaluated_at'] || $facts['anchor']['quote_expires_at'] !== $first['anchor']['quote_expires_at'] || $facts['runtime'] !== $first['runtime'] ) { throw new PromiseCalculationException( 'source_changed' ); } $groups[$group] = true;
			}
			$expected = []; foreach ( $inputs as $input ) { foreach ( $input->private_facts()['calendar_refs'] as $ref ) { $key = $ref['calendar_id']; if ( isset( $expected[$key] ) && $expected[$key] !== $ref ) { throw new PromiseCalculationException( 'source_changed' ); } $expected[$key] = $ref; } }
			$map = $this->calendar_map( $calendars, $expected, $budget );
			foreach ( $inputs as $input ) { $subset = []; foreach ( $input->private_facts()['calendar_refs'] as $ref ) { $subset[] = $map[$ref['calendar_id']]; } $results[] = $this->calculate_with_budget( $input, $subset, $budget ); if ( $budget->exhausted() ) { throw new PromiseCalculationException( 'budget_exceeded' ); } }
			try { PromiseJson::encode( [ 'results' => array_map( static fn( PromiseResult $result ): array => $result->private_facts(), $results ) ], PromiseLimits::PACKET_BYTES ); }
			catch ( \InvalidArgumentException ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
			$required_refusal = false;
			foreach ( $results as $result ) { if ( $result->input()->policy()->private_facts()['promise_required'] && ! $this->winning( $result ) ) { $required_refusal = true; } }
			if ( $required_refusal ) { foreach ( $results as $index => $result ) { if ( $this->winning( $result ) ) { $results[$index] = $this->refusal( $result->input(), 'estimate_unavailable' ); } } }
			return $results;
		} catch ( PromiseCalculationException $error ) { return array_map( fn( PromiseInput $input ): PromiseResult => $this->refusal( $input, $error->reason() ), $inputs ); }
		finally { $this->steps = $budget->used_steps(); }
	}
	private function calculate_with_budget( PromiseInput $input, array $calendars, PromiseCalculationBudget $budget ): PromiseResult {
		try {
			$facts = $input->private_facts(); if ( ! $this->runtime->matches( $facts['runtime'] ) ) { throw new PromiseCalculationException( 'source_changed' ); }
			$budget->consume(); $expected = []; foreach ( $facts['calendar_refs'] as $ref ) { $expected[$ref['calendar_id']] = $ref; }
			$map = $this->calendar_map( $calendars, $expected, $budget );
			foreach ( $map as $calendar ) { if ( $calendar->tzdata_version() !== $facts['runtime']['timezone_data_version'] ) { throw new PromiseCalculationException( 'source_changed' ); } }
			if ( 'payment_confirmed' === $input->anchor()->kind() ) {
				if ( 'required' === $input->capacity()->mode() ) { throw new PromiseCalculationException( 'capacity_unknown' ); }
				return $this->result( $input, 'relative_window', $this->relative_body( $input, $map, $budget ) );
			}
			$this->capacity_state( $input );
			$math = new PromiseCalendarArithmetic(); $lower = $input->anchor()->evaluated_at(); $deadline = $input->anchor()->accept_until();
			$cutoff = $facts['policy']['cutoff'];
			if ( null !== $cutoff ) { $admitted = $this->admission( $lower, $cutoff, $map, $math, $budget ); if ( null !== $deadline && $admitted['deadline']->compare( $deadline ) < 0 && $admitted['deadline']->compare( $lower ) > 0 ) { $deadline = $admitted['deadline']; } }
			if ( null !== $deadline && 'required' === $input->capacity()->mode() ) { $valid = RuleTime::parse( $facts['capacity']['valid_until'] ); if ( $valid->compare( $deadline ) < 0 ) { $deadline = $valid; } }
			if ( null !== $deadline ) {
				$local_date = $this->local( $lower, $input->policy()->promise_timezone(), $budget )->format( 'Y-m-d' );
				$next_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $local_date, new \DateTimeZone( 'UTC' ) )->modify( '+1 day' )->format( 'Y-m-d' );
				$midnight = $math->resolve_local( $next_date, '00:00', $input->policy()->promise_timezone(), null, $budget ); if ( $midnight->compare( $deadline ) < 0 ) { $deadline = $midnight; }
			}
			if ( null !== $deadline && null !== $facts['service_day'] ) { $end = RuleTime::parse( $facts['service_day']['end_at'] ); if ( $end->compare( $deadline ) < 0 ) { $deadline = $end; } }
			if ( null !== $deadline ) { $input = $this->with_deadline( $input, $deadline ); }
			try { return $this->absolute_result( $input, $map, $math, $budget ); }
			catch ( PromiseCalculationException $error ) {
				if ( null === $deadline || ! in_array( $error->reason(), [ 'outside_service_window', 'capacity_unavailable' ], true ) ) { throw $error; }
				// Every candidate is verified as a whole prefix; this does not assume endpoint monotonicity.
				$first = $this->with_deadline( $input, RuleTime::from_epoch_microseconds( $lower->epoch_microseconds() + 1 ) );
				$best = $this->absolute_result( $first, $map, $math, $budget ); $safe = 1; $failed = $deadline->epoch_microseconds() - $lower->epoch_microseconds();
				while ( $failed - $safe > 1 ) {
					$budget->consume(); $middle = $safe + intdiv( $failed - $safe, 2 ); $candidate = $this->with_deadline( $input, RuleTime::from_epoch_microseconds( $lower->epoch_microseconds() + $middle ) );
					try { $best = $this->absolute_result( $candidate, $map, $math, $budget ); $safe = $middle; }
					catch ( PromiseCalculationException $failure ) { if ( ! in_array( $failure->reason(), [ 'outside_service_window', 'capacity_unavailable' ], true ) ) { throw $failure; } $failed = $middle; }
				}
				return $best;
			}
		} catch ( PromiseCalculationException $error ) { return $this->refusal( $input, $error->reason() ); }
		catch ( \InvalidArgumentException ) { return $this->refusal( $input, 'budget_exceeded' ); }
	}
	/** @return array<string,BusinessCalendarVersion> */
	private function calendar_map( array $calendars, array $expected, PromiseCalculationBudget $budget ): array {
		if ( ! array_is_list( $calendars ) || count( $calendars ) > PromiseLimits::CALENDARS * 200 ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
		$map = [];
		foreach ( $calendars as $calendar ) {
			$budget->consume(); if ( ! $calendar instanceof BusinessCalendarVersion ) { throw new PromiseCalculationException( 'missing_source' ); }
			$ref = $calendar->reference()->private_facts(); $id = $ref['calendar_id'];
			if ( ! isset( $expected[$id] ) || $expected[$id] !== $ref || ( isset( $map[$id] ) && $map[$id]->to_private_json() !== $calendar->to_private_json() ) ) { throw new PromiseCalculationException( 'source_changed' ); } $map[$id] = $calendar;
		}
		if ( count( $map ) !== count( $expected ) ) { throw new PromiseCalculationException( 'missing_source' ); } ksort( $map, SORT_STRING ); return $map;
	}
	private function absolute_result( PromiseInput $input, array $map, PromiseCalendarArithmetic $math, PromiseCalculationBudget $budget ): PromiseResult {
		$lower = $input->anchor()->evaluated_at(); $deadline = $input->anchor()->accept_until(); $upper = null === $deadline ? $lower : RuleTime::from_epoch_microseconds( $deadline->epoch_microseconds() - 1 );
		$cutoff = $input->policy()->private_facts()['cutoff'];
		if ( null !== $cutoff ) {
			$first = $this->admission( $lower, $cutoff, $map, $math, $budget ); $last = $this->admission( $upper, $cutoff, $map, $math, $budget );
			if ( ! $first['interval']['start']->equals( $last['interval']['start'] ) || ! $first['interval']['end']->equals( $last['interval']['end'] ) ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
			$lower = $first['start']; $upper = $last['start'];
		}
		$transfer = ( new PromiseGraphTransfer() )->transfer( $input, $map, $lower, $upper, $budget ); $body = [ 'display_timezone' => $input->policy()->promise_timezone(), 'terminal_windows' => $transfer['terminal_windows'] ];
		$this->completion_constraints( $input, $body, $budget ); return $this->result( $input, 'absolute_window', $body );
	}
	/** The cutoff belongs to the selected opening, including each separate break interval. */
	private function admission( RuleTime $requested, array $cutoff, array $map, PromiseCalendarArithmetic $math, PromiseCalculationBudget $budget ): array {
		$calendar = $map[$cutoff['calendar']['calendar_id']]; $cursor = $requested;
		for ( $count = 0; $count <= PromiseLimits::LOOKAHEAD_DAYS * PromiseLimits::INTERVALS_PER_DATE; ++$count ) {
			$budget->consume(); $start = $math->next_start( $cursor, $calendar, $budget ); $interval = $math->containing_interval( $start, $calendar, $budget );
			$origin_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $this->local( $requested, $calendar->timezone(), $budget )->format( 'Y-m-d' ), new \DateTimeZone( 'UTC' ) );
			$start_date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $this->local( $start, $calendar->timezone(), $budget )->format( 'Y-m-d' ), new \DateTimeZone( 'UTC' ) );
			if ( (int) $origin_date->diff( $start_date )->format( '%r%a' ) > PromiseLimits::LOOKAHEAD_DAYS ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
			if ( null === $interval ) { throw new PromiseCalculationException( 'outside_service_window' ); }
			if ( 'before_close' === $cutoff['kind'] ) { $deadline = RuleTime::from_epoch_microseconds( $interval['end']->epoch_microseconds() - $cutoff['minutes_before_close'] * 60000000 ); }
			else { $date = $this->local( $start, $calendar->timezone(), $budget )->format( 'Y-m-d' ); $deadline = $math->resolve_local( $date, $cutoff['time'], $calendar->timezone(), null, $budget ); }
			if ( $start->compare( $deadline ) < 0 ) { return [ 'start' => $start, 'deadline' => $deadline, 'interval' => $interval ]; }
			if ( 'reject' === $cutoff['missed_window_rule'] ) { throw new PromiseCalculationException( 'outside_service_window' ); }
			$cursor = $interval['end'];
		}
		throw new PromiseCalculationException( 'budget_exceeded' );
	}
	private function capacity_state( PromiseInput $input ): void {
		$capacity = $input->capacity()->private_facts(); if ( 'none' === $capacity['mode'] ) { return; }
		if ( 'available' !== $capacity['state'] ) { throw new PromiseCalculationException( 'unknown' === $capacity['state'] ? 'capacity_unknown' : 'capacity_unavailable' ); }
		if ( RuleTime::parse( $capacity['valid_until'] )->compare( $input->anchor()->evaluated_at() ) <= 0 ) { throw new PromiseCalculationException( 'capacity_stale' ); }
	}
	private function completion_constraints( PromiseInput $input, array $body, PromiseCalculationBudget $budget ): void {
		$facts = $input->private_facts(); $target = null; $zone = $body['display_timezone'];
		if ( null !== $facts['service_day'] ) { $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $facts['service_day']['local_date'], new \DateTimeZone( 'UTC' ) ); $target = 'next_day' === $facts['policy']['day_constraint'] ? $date->modify( '+1 day' )->format( 'Y-m-d' ) : $date->format( 'Y-m-d' ); }
		foreach ( $body['terminal_windows'] as $window ) {
			$budget->consume(); $from = RuleTime::parse( $window['from'] ); $until = RuleTime::parse( $window['until'] );
			$start_date = $this->local( $input->anchor()->evaluated_at(), $zone, $budget )->format( 'Y-m-d' ); $end_date = $this->local( $until, $zone, $budget )->format( 'Y-m-d' );
			$start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, new \DateTimeZone( 'UTC' ) ); $end = \DateTimeImmutable::createFromFormat( '!Y-m-d', $end_date, new \DateTimeZone( 'UTC' ) );
			if ( (int) $start->diff( $end )->format( '%r%a' ) > PromiseLimits::LOOKAHEAD_DAYS ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
			if ( ! in_array( $window['component_id'], $facts['policy']['endpoint_terminal_component_ids'], true ) ) { continue; }
			if ( null !== $target && ( $this->local( $from, $zone, $budget )->format( 'Y-m-d' ) !== $target || $this->local( $until, $zone, $budget )->format( 'Y-m-d' ) !== $target ) ) { throw new PromiseCalculationException( 'outside_service_window' ); }
			if ( 'required' === $facts['capacity']['mode'] && ( $from->compare( RuleTime::parse( $facts['capacity']['window']['from'] ) ) < 0 || $until->compare( RuleTime::parse( $facts['capacity']['window']['until'] ) ) > 0 ) ) { throw new PromiseCalculationException( 'capacity_unavailable' ); }
		}
	}
	private function relative_body( PromiseInput $input, array $map, PromiseCalculationBudget $budget ): array {
		$facts = $input->policy()->private_facts(); $graph = $facts['graph']; if ( null !== $facts['cutoff'] ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
		foreach ( $graph['components'] as $component ) { if ( null !== $component['operating_calendar'] || 'none' !== $component['completion_window_rule'] ) { throw new PromiseCalculationException( 'unsupported_policy' ); } }
		if ( 1 === count( $graph['components'] ) && 'elapsed_minutes' !== $graph['components'][0]['duration']['unit'] ) {
			$duration = $graph['components'][0]['duration']; $limit = in_array( $duration['unit'], [ 'calendar_days', 'business_days' ], true ) ? PromiseLimits::LOOKAHEAD_DAYS : PromiseLimits::LOOKAHEAD_DAYS * 1440;
			if ( $duration['max'] > $limit ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
			// Zero eligible dates can still wait for admission; the scalar zero carrier cannot express that wait.
			if ( 'business_days' === $duration['unit'] && 0 === $duration['max'] ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
			if ( in_array( $duration['unit'], [ 'business_days', 'business_minutes' ], true ) && ( $duration['max'] > 0 || 'business_days' === $duration['unit'] ) ) {
				$calendar = $map[$duration['calendar']['calendar_id']]->private_facts(); $recurring = false; foreach ( $calendar['weekly_openings'] as $intervals ) { $budget->consume(); if ( [] !== $intervals ) { $recurring = true; } }
				if ( ! $recurring ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
			}
			$windows = [ [ 'component_id' => $graph['components'][0]['component_id'], 'min' => $duration['min'], 'max' => $duration['max'], 'unit' => $duration['unit'], 'calendar_refs' => [ $duration['calendar'] ], 'known_zero' => 0 === $duration['max'] ] ];
		} else {
			$remaining = []; foreach ( $graph['components'] as $component ) { if ( 'elapsed_minutes' !== $component['duration']['unit'] ) { throw new PromiseCalculationException( 'unsupported_policy' ); } $remaining[$component['component_id']] = $component; } $completed = [];
			while ( [] !== $remaining ) {
				$progress = false;
				foreach ( $remaining as $id => $component ) {
					$budget->consume(); $min = 0; $max = 0; $ready = true;
					foreach ( $component['predecessors'] as $parent ) { if ( ! isset( $completed[$parent] ) ) { $ready = false; break; } $min = max( $min, $completed[$parent]['min'] ); $max = max( $max, $completed[$parent]['max'] ); }
					if ( ! $ready ) { continue; } $limit = PromiseLimits::LOOKAHEAD_DAYS * 1440;
					if ( $component['duration']['max'] > $limit - $max ) { throw new PromiseCalculationException( 'budget_exceeded' ); }
					$completed[$id] = [ 'min' => $min + $component['duration']['min'], 'max' => $max + $component['duration']['max'] ]; unset( $remaining[$id] ); $progress = true;
				}
				if ( ! $progress ) { throw new PromiseCalculationException( 'unsupported_policy' ); }
			}
			$windows = []; foreach ( $graph['terminal_component_ids'] as $id ) { $bounds = $completed[$id]; $windows[] = [ 'component_id' => $id, 'min' => $bounds['min'], 'max' => $bounds['max'], 'unit' => 'elapsed_minutes', 'calendar_refs' => [], 'known_zero' => 0 === $bounds['max'] ]; }
		}
		return [ 'display_timezone' => $input->policy()->promise_timezone(), 'awaited_event' => 'woocommerce_payment_confirmed', 'terminal_windows' => $windows ];
	}
	private function with_deadline( PromiseInput $input, RuleTime $deadline ): PromiseInput {
		if ( $deadline->compare( $input->anchor()->evaluated_at() ) <= 0 ) { throw new PromiseCalculationException( 'acceptance_expired' ); }
		$facts = $input->private_facts(); $facts['anchor']['accept_until'] = $deadline->sql(); return PromiseInput::from_array( $facts );
	}
	private function local( RuleTime $time, string $zone, PromiseCalculationBudget $budget ): \DateTimeImmutable { $budget->consume(); return \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $time->sql(), new \DateTimeZone( 'UTC' ) )->setTimezone( new \DateTimeZone( $zone ) ); }
	private function winning( PromiseResult $result ): bool { return in_array( $result->state(), [ 'absolute_window', 'relative_window' ], true ); }
	private function result( PromiseInput $input, string $state, ?array $body, array $reasons = [] ): PromiseResult { return PromiseResult::from_array( [ 'format_version' => 1, 'state' => $state, 'input' => $input->private_facts(), 'input_digest' => $input->digest(), 'graph_digest' => $input->policy()->graph()->digest(), 'body' => $body, 'reason_codes' => $reasons ] ); }
	private function refusal( PromiseInput $input, string $reason ): PromiseResult { return $this->result( $input, in_array( $reason, [ 'capacity_unknown', 'capacity_unavailable', 'capacity_stale', 'outside_service_window', 'acceptance_expired' ], true ) ? 'ineligible' : 'unavailable', null, [ $reason ] ); }
}
