<?php
/** P06 source-defined measured pure boundaries; never native cart, SQL or network proof. */
declare(strict_types=1);

use CetechDeliveryEngine\Application\ServicePromise\Calculation\{DeterministicPromiseCalculator, PromiseCalculationBudget, PromiseCalculationException, PromiseGraphTransfer};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseGraph, PromiseInput, PromiseJson, PromiseLimits};
use CetechPromiseCalculationQualification\Vectors;

return static function ( callable $check ): void {
	$refuses = static function ( callable $action ): bool { try { $action(); return false; } catch ( InvalidArgumentException ) { return true; } };
	$duration = static fn( int $minutes ): array => [ 'format_version' => 1, 'min' => $minutes, 'max' => $minutes, 'unit' => 'elapsed_minutes', 'calendar' => null ];
	$components = []; $extra = 17;
	for ( $i = 0; $i < PromiseLimits::GRAPH_NODES; ++$i ) {
		$id = 15 === $i ? 'delivery' : 'node-' . $i; $parents = 0 === $i ? [] : [ 'node-' . ( $i - 1 ) ];
		for ( $j = 0; $j < $i - 1 && $extra > 0; ++$j, --$extra ) { $parents[] = 'node-' . $j; }
		$components[] = Vectors::component( $id, [ 'predecessors' => $parents, 'duration' => $duration( 1 ) ] );
	}
	$graph = [ 'format_version' => 1, 'components' => $components, 'terminal_component_ids' => [ 'delivery' ] ];
	$policy = Vectors::policy( [ 'graph' => $graph ] ); $calculator = new DeterministicPromiseCalculator( Vectors::runtime() ); $input = Vectors::input( $policy ); $result = $calculator->calculate( $input, [] );
	$check( 'PURE-W2P06-GRAPH-EXACT-NODES-EDGES', 'absolute_window' === $result->state() && '2026-10-09 10:16:00.000000' === $result->private_facts()['body']['terminal_windows'][0]['until'], [ 'complete_manual_endpoint' => '2026-10-09 10:16:00.000000' === $result->private_facts()['body']['terminal_windows'][0]['until'], 'shared_budget_bounded' => $calculator->last_steps() <= PromiseLimits::CART_STEPS ], [ 'nodes' => count( $components ), 'edges' => array_sum( array_map( static fn( array $part ): int => count( $part['predecessors'] ), $components ) ), 'steps' => $calculator->last_steps(), 'input_bytes' => strlen( $input->to_private_json() ) ] );
	$nodes_plus = $graph; $nodes_plus['components'][] = Vectors::component( 'extra', [ 'predecessors' => [ 'delivery' ] ] ); $nodes_plus['terminal_component_ids'] = [ 'extra' ];
	$denied = $refuses( static fn() => PromiseGraph::from_array( $nodes_plus ) );
	$check( 'PURE-W2P06-GRAPH-NODES-PLUS-ONE', $denied, [ 'whole_graph_refused' => $denied ], [ 'nodes' => count( $nodes_plus['components'] ), 'limit' => PromiseLimits::GRAPH_NODES ] );
	$edges_plus = $graph; $added = false;
	foreach ( $edges_plus['components'] as $i => &$part ) { for ( $j = 0; $j < $i - 1; ++$j ) { $parent = 'node-' . $j; if ( ! in_array( $parent, $part['predecessors'], true ) ) { $part['predecessors'][] = $parent; $added = true; break 2; } } } unset( $part );
	$denied = $added && $refuses( static fn() => PromiseGraph::from_array( $edges_plus ) );
	$check( 'PURE-W2P06-GRAPH-EDGES-PLUS-ONE', $denied, [ 'whole_graph_refused' => $denied ], [ 'edges' => array_sum( array_map( static fn( array $part ): int => count( $part['predecessors'] ), $edges_plus['components'] ) ), 'limit' => PromiseLimits::GRAPH_EDGES ] );
	foreach ( [ 'RECORD' => PromiseLimits::RECORD_BYTES, 'PACKET' => PromiseLimits::PACKET_BYTES ] as $kind => $limit ) {
		$overhead = strlen( PromiseJson::encode( [ 'payload' => '' ], $limit ) ); $body = [ 'payload' => str_repeat( 'x', $limit - $overhead ) ]; $json = PromiseJson::encode( $body, $limit );
		$exact = strlen( $json ) === $limit && $body === PromiseJson::decode( $json, $limit );
		if ( 'RECORD' === $kind ) { $check( 'PURE-W2P06-RECORD-BYTES-EXACT', $exact, [ 'exact_codec_roundtrip' => $exact ], [ 'encoded_bytes' => strlen( $json ), 'limit' => $limit ] ); }
		else { $check( 'PURE-W2P06-PACKET-BYTES-EXACT', $exact, [ 'exact_codec_roundtrip' => $exact ], [ 'encoded_bytes' => strlen( $json ), 'limit' => $limit ] ); }
		$body['payload'] .= 'x'; $denied = $refuses( static fn() => PromiseJson::encode( $body, $limit ) ) && $refuses( static fn() => PromiseJson::decode( substr( $json, 0, -2 ) . 'x"}', $limit ) );
		if ( 'RECORD' === $kind ) { $check( 'PURE-W2P06-RECORD-BYTES-PLUS-ONE', $denied, [ 'encode_and_decode_refused' => $denied ], [ 'attempted_bytes' => $limit + 1, 'limit' => $limit ] ); }
		else { $check( 'PURE-W2P06-PACKET-BYTES-PLUS-ONE', $denied, [ 'encode_and_decode_refused' => $denied ], [ 'attempted_bytes' => $limit + 1, 'limit' => $limit ] ); }
	}
	$budget = new PromiseCalculationBudget(); $budget->consume( PromiseLimits::CART_STEPS );
	$check( 'PURE-W2P06-CART-STEPS-EXACT', PromiseLimits::CART_STEPS === $budget->used_steps() && ! $budget->exhausted(), [ 'exact_allowance_accepted' => ! $budget->exhausted() ], [ 'used_steps' => $budget->used_steps(), 'limit' => PromiseLimits::CART_STEPS ] );
	$denied = false; try { $budget->consume(); } catch ( PromiseCalculationException $error ) { $denied = 'budget_exceeded' === $error->reason() && $budget->exhausted(); }
	$check( 'PURE-W2P06-CART-STEPS-PLUS-ONE', $denied && PromiseLimits::CART_STEPS === $budget->used_steps(), [ 'no_partial_charge_or_reset' => PromiseLimits::CART_STEPS === $budget->used_steps(), 'whole_budget_exhausted' => $denied ], [ 'attempted_steps' => PromiseLimits::CART_STEPS + 1, 'used_steps' => $budget->used_steps() ] );
	foreach ( [ 730, 731 ] as $days ) {
		$legs = [ Vectors::component( 'first', [ 'duration' => $duration( 365 * 1440 ) ] ), Vectors::component( 'delivery', [ 'predecessors' => [ 'first' ], 'duration' => $duration( ( $days - 365 ) * 1440 ) ] ) ];
		$input = Vectors::input( Vectors::policy( [ 'graph' => [ 'format_version' => 1, 'components' => $legs, 'terminal_component_ids' => [ 'delivery' ] ] ] ) ); $result = $calculator->calculate( $input, [] );
		$exact = 730 === $days; $valid = $exact ? 'absolute_window' === $result->state() && '2028-10-08 10:00:00.000000' === $result->private_facts()['body']['terminal_windows'][0]['until'] : null === $result->private_facts()['body'] && [ 'budget_exceeded' ] === $result->private_facts()['reason_codes'];
		if ( $exact ) { $check( 'PURE-W2P06-HORIZON-EXACT', $valid, [ 'cumulative_not_per_leg' => $valid, 'no_partial_winning_range' => $exact || null === $result->private_facts()['body'] ], [ 'days' => $days, 'limit' => PromiseLimits::LOOKAHEAD_DAYS, 'steps' => $calculator->last_steps() ] ); }
		else { $check( 'PURE-W2P06-HORIZON-PLUS-ONE', $valid, [ 'cumulative_not_per_leg' => $valid, 'no_partial_winning_range' => $exact || null === $result->private_facts()['body'] ], [ 'days' => $days, 'limit' => PromiseLimits::LOOKAHEAD_DAYS, 'steps' => $calculator->last_steps() ] ); }
	}
	$calendar = Vectors::calendar(); $facts = $calendar->private_facts(); $intervals = [];
	for ( $i = 0; $i < 9; ++$i ) { $intervals[] = [ 'open' => sprintf( '%02d:00', $i * 2 ), 'close' => sprintf( '%02d:30', $i * 2 ) ]; }
	$facts['weekly_openings']['mon'] = array_slice( $intervals, 0, 8 ); $eight = BusinessCalendarVersion::from_array( $facts ); $facts['weekly_openings']['mon'] = $intervals; $denied = $refuses( static fn() => BusinessCalendarVersion::from_array( $facts ) );
	$check( 'PURE-W2P06-CALENDAR-INTERVALS-EXACT-PLUS-ONE', 8 === count( $eight->private_facts()['weekly_openings']['mon'] ) && $denied, [ 'eight_accepted' => 8 === count( $eight->private_facts()['weekly_openings']['mon'] ), 'nine_refused' => $denied ], [ 'accepted_intervals' => 8, 'attempted_intervals' => 9 ] );
	$facts = $calendar->private_facts(); $dates = []; $start = new DateTimeImmutable( '2026-01-01', new DateTimeZone( 'UTC' ) );
	for ( $i = 0; $i < 367; ++$i ) { $dates[] = $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' ); }
	$facts['closed_dates'] = array_slice( $dates, 0, 366 ); $exact_calendar = BusinessCalendarVersion::from_array( $facts ); $facts['closed_dates'] = $dates; $denied = $refuses( static fn() => BusinessCalendarVersion::from_array( $facts ) );
	$check( 'PURE-W2P06-CALENDAR-EXCEPTIONS-EXACT-PLUS-ONE', 366 === count( $exact_calendar->private_facts()['closed_dates'] ) && $denied, [ 'exact_accepted' => 366 === count( $exact_calendar->private_facts()['closed_dates'] ), 'plus_one_refused' => $denied ], [ 'accepted_dates' => 366, 'attempted_dates' => 367 ] );
	$calendars = []; $parts = []; $terminals = [];
	for ( $i = 0; $i < 16; ++$i ) { $calendars[] = Vectors::calendar( [ 'calendar_id' => 'calendar-' . $i ] ); $id = 'terminal-' . $i; $terminals[] = $id; $parts[] = Vectors::component( $id, [ 'duration' => [ 'format_version' => 1, 'min' => 0, 'max' => 0, 'unit' => 'business_minutes', 'calendar' => $calendars[$i]->reference()->private_facts() ] ] ); }
	$policy = Vectors::policy( [ 'graph' => [ 'format_version' => 1, 'components' => $parts, 'terminal_component_ids' => $terminals ], 'endpoint_terminal_component_ids' => $terminals ], $calendars ); $result = $calculator->calculate( Vectors::input( $policy ), $calendars );
	$check( 'PURE-W2P06-CALENDAR-REFERENCES-EXACT', 'absolute_window' === $result->state() && 16 === count( $result->private_facts()['body']['terminal_windows'] ), [ 'complete_sixteen_source_result' => 'absolute_window' === $result->state() && 16 === count( $result->private_facts()['body']['terminal_windows'] ), 'bounded_work' => $calculator->last_steps() <= PromiseLimits::CART_STEPS ], [ 'unique_calendars' => count( $calendars ), 'steps' => $calculator->last_steps() ] );
	$calendars[] = Vectors::calendar( [ 'calendar_id' => 'calendar-16' ] ); $facts = $policy->private_facts(); $facts['calendars'] = array_map( static fn( BusinessCalendarVersion $value ): array => $value->reference()->private_facts(), $calendars ); $denied = $refuses( static fn() => \CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy::from_array( $facts ) );
	$check( 'PURE-W2P06-CALENDAR-REFERENCES-PLUS-ONE', $denied, [ 'whole_policy_refused' => $denied ], [ 'attempted_calendars' => count( $calendars ), 'limit' => PromiseLimits::CALENDARS ] );
	$inputs = []; for ( $i = 0; $i < 200; ++$i ) { $facts = Vectors::input()->private_facts(); $facts['material']['group_id'] = 'group-' . $i; $inputs[] = PromiseInput::from_array( $facts ); }
	$results = $calculator->calculate_cart( $inputs, [] ); $steps = $calculator->last_steps(); $whole = 200 === count( $results ); foreach ( $results as $result ) { $whole = $whole && 'unavailable' === $result->state() && null === $result->private_facts()['body'] && [ 'budget_exceeded' ] === $result->private_facts()['reason_codes']; }
	$check( 'PURE-W2P06-CART-GROUPS-EXACT-PACKET-REFUSAL', $whole, [ 'input_count_accepted' => 200 === count( $results ), 'packet_overflow_has_no_winners' => $whole, 'shared_budget_within_limit' => $steps <= PromiseLimits::CART_STEPS ], [ 'inputs' => count( $inputs ), 'outputs' => count( $results ), 'steps' => $steps ] );
	$more = Vectors::input()->private_facts(); $more['material']['group_id'] = 'group-200'; $inputs[] = PromiseInput::from_array( $more ); $denied = $refuses( static fn() => $calculator->calculate_cart( $inputs, [] ) );
	$check( 'PURE-W2P06-CART-GROUPS-PLUS-ONE', $denied, [ 'before_calculation_refused' => $denied && $steps === $calculator->last_steps() ], [ 'attempted_inputs' => count( $inputs ), 'last_completed_steps' => $calculator->last_steps() ] );
	// Reuse the P03 hostile business-minute vector to exhaust one actual cart budget.
	$minute_calendar = Vectors::calendar( [ 'weekly_openings' => array_fill_keys( BusinessCalendarVersion::WEEKDAYS, [ [ 'open' => '09:00', 'close' => '09:01' ] ] ) ] );
	$minute = [ 'format_version' => 1, 'min' => 700, 'max' => 700, 'unit' => 'business_minutes', 'calendar' => $minute_calendar->reference()->private_facts() ];
	$policy = Vectors::policy( [ 'graph' => [ 'format_version' => 1, 'components' => [ Vectors::component( changes: [ 'duration' => $minute ] ) ], 'terminal_component_ids' => [ 'delivery' ] ] ], [ $minute_calendar ] );
	$expensive = []; for ( $i = 0; $i < 30; ++$i ) { $facts = Vectors::input( $policy )->private_facts(); $facts['material']['group_id'] = 'costly-' . $i; $expensive[] = PromiseInput::from_array( $facts ); }
	$results = $calculator->calculate_cart( $expensive, [ $minute_calendar ] ); $whole = 30 === count( $results ); foreach ( $results as $result ) { $whole = $whole && 'unavailable' === $result->state() && null === $result->private_facts()['body']; }
	$check( 'PURE-W2P06-ACTUAL-CART-SHARED-STEP-EXHAUSTION', $whole && PromiseLimits::CART_STEPS === $calculator->last_steps(), [ 'one_budget_never_reset_per_group' => PromiseLimits::CART_STEPS === $calculator->last_steps(), 'complete_cart_refused' => $whole ], [ 'inputs' => count( $expensive ), 'used_steps' => $calculator->last_steps(), 'limit' => PromiseLimits::CART_STEPS ] );
};
