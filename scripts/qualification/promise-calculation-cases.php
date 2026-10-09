<?php
/** Source-derived P03 pure vectors. Each expected instant/range is manually specified. */
declare(strict_types=1);

use CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\{PromiseCalculationBudget, PromiseCalculationException, PromiseCalendarArithmetic};
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput, PromiseJson, PromiseResult, PublicPromiseView};
use CetechPromiseCalculationQualification\Vectors;

return static function ( callable $check, callable $fingerprints ): void {
	$calculator = new DeterministicPromiseCalculator( Vectors::runtime() );
	$calculate = static function ( PromiseInput $input, array $calendars = [] ) use ( $calculator, $fingerprints ): PromiseResult {
		$result = $calculator->calculate( $input, $calendars );
		$fingerprints( [ 'kind' => 'calculation', 'input_digest' => $input->digest(), 'result_digest' => $result->digest(), 'calendar_digests' => array_map( static fn( BusinessCalendarVersion $calendar ): string => $calendar->digest(), $calendars ), 'steps' => $calculator->last_steps() ] );
		return $result;
	};
	// Cart fingerprints bind the exact ordered digest vectors, without serializing private bodies.
	$cart_fingerprints = static function ( array $inputs, array $results, array $calendars = [] ) use ( $calculator, $fingerprints ): void {
		$fingerprints( [ 'kind' => 'cart', 'input_digest' => hash( 'sha256', 'p03-pure-cart-inputs:' . PromiseJson::encode( [ 'digests' => array_map( static fn( PromiseInput $input ): string => $input->digest(), $inputs ) ] ) ), 'result_digest' => hash( 'sha256', 'p03-pure-cart-results:' . PromiseJson::encode( [ 'digests' => array_map( static fn( PromiseResult $result ): string => $result->digest(), $results ) ] ) ), 'calendar_digests' => array_map( static fn( BusinessCalendarVersion $calendar ): string => $calendar->digest(), $calendars ), 'steps' => $calculator->last_steps() ] );
	};
	$window = static function ( PromiseResult $result, string $from, string $until, string $id = 'delivery' ): bool {
		return 'absolute_window' === $result->state() && [ [ 'component_id' => $id, 'from' => $from, 'until' => $until ] ] === $result->private_facts()['body']['terminal_windows'];
	};
	$refused = static fn( PromiseResult $result ): bool => in_array( $result->state(), [ 'unavailable', 'ineligible' ], true ) && null === $result->private_facts()['body'] && [] !== $result->private_facts()['reason_codes'];
	$duration = static fn( int $min, int $max, string $unit = 'elapsed_minutes', ?BusinessCalendarVersion $calendar = null ): array => [ 'format_version' => 1, 'min' => $min, 'max' => $max, 'unit' => $unit, 'calendar' => $calendar?->reference()->private_facts() ];
	$single = static function ( array $component, array $calendars = [], array $changes = [] ) { return Vectors::policy( array_replace( [ 'graph' => [ 'format_version' => 1, 'components' => [ $component ], 'terminal_component_ids' => [ $component['component_id'] ] ], 'endpoint_terminal_component_ids' => [ $component['component_id'] ] ], $changes ), $calendars ); };
	$office_week = array_fill_keys( BusinessCalendarVersion::WEEKDAYS, [] );
	foreach ( [ 'mon', 'tue', 'wed', 'thu', 'fri' ] as $day ) { $office_week[$day] = [ [ 'open' => '09:00', 'close' => '17:00' ] ]; }
	$office = Vectors::calendar( [ 'weekly_openings' => $office_week ] );

	$zero = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 0, 0 ) ] ) ) ) );
	$zero_view = PublicPromiseView::from_result( $zero, 'delivery' )->fields();
	$check( 'PURE-W2P03-EXPLICIT-KNOWN-ZERO', $window( $zero, '2026-10-09 10:00:00.000000', '2026-10-09 10:00:00.000000' ), [ 'equal_captured_bounds' => $window( $zero, '2026-10-09 10:00:00.000000', '2026-10-09 10:00:00.000000' ), 'no_unknown_or_fake_elapsed_time' => 'absolute_window' === $zero_view['state'] ] , 'calculation' );
	$input = Vectors::input(); $result = $calculate( $input );
	$check( 'PURE-W2P03-ELAPSED-RANGE-AND-DETERMINISTIC-REPLAY', $window( $result, '2026-10-09 10:10:00.000000', '2026-10-09 10:20:00.000000' ) && $result->digest() === $calculator->calculate( $input, [] )->digest(), [ 'manual_lower_upper' => $window( $result, '2026-10-09 10:10:00.000000', '2026-10-09 10:20:00.000000' ), 'same_captured_input_same_result' => $result->digest() === $calculator->calculate( $input, [] )->digest(), 'input_bytes_unchanged' => $input->digest() === $result->input()->digest() ] , 'calculation' );
	$result = $calculate( Vectors::input( Vectors::policy( [ 'anchor' => 'order_accepted' ] ), accept_until: '2026-10-09 10:04:00.000000' ) );
	$check( 'PURE-W2P03-FINAL-ACCEPTANCE-ENVELOPE', $window( $result, '2026-10-09 10:10:00.000000', '2026-10-09 10:23:59.999999' ), [ 'earliest_from_capture' => $result->private_facts()['body']['terminal_windows'][0]['from'] === '2026-10-09 10:10:00.000000', 'conservative_upper_from_last_acceptance' => $result->private_facts()['body']['terminal_windows'][0]['until'] === '2026-10-09 10:23:59.999999' ] , 'calculation' );

	$split_week = $office_week; $split_week['fri'] = [ [ 'open' => '09:00', 'close' => '12:00' ], [ 'open' => '13:00', 'close' => '17:00' ] ];
	$split = Vectors::calendar( [ 'weekly_openings' => $split_week ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 90, 90, 'business_minutes', $split ) ] ), [ $split ] ), '2026-10-09 11:30:00.000000' ), [ $split ] );
	$check( 'PURE-W2P03-SPLIT-WINDOW-BUSINESS-MINUTE-CARRY', $window( $result, '2026-10-09 14:00:00.000000', '2026-10-09 14:00:00.000000' ), [ 'break_not_counted' => $window( $result, '2026-10-09 14:00:00.000000', '2026-10-09 14:00:00.000000' ) ] , 'calculation' );
	$sunday_week = array_fill_keys( BusinessCalendarVersion::WEEKDAYS, [] ); foreach ( [ 'sun', 'mon', 'tue', 'wed', 'thu' ] as $day ) { $sunday_week[$day] = [ [ 'open' => '09:00', 'close' => '17:00' ] ]; }
	$sunday = Vectors::calendar( [ 'weekly_openings' => $sunday_week ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 120, 120, 'business_minutes', $sunday ) ] ), [ $sunday ] ), '2026-10-09 09:00:00.000000' ), [ $sunday ] );
	$check( 'PURE-W2P03-NON-MONDAY-FRIDAY-WORKWEEK', $window( $result, '2026-10-11 11:00:00.000000', '2026-10-11 11:00:00.000000' ), [ 'configured_sunday_opening_used' => $window( $result, '2026-10-11 11:00:00.000000', '2026-10-11 11:00:00.000000' ) ] , 'calculation' );
	$overnight_week = array_fill_keys( BusinessCalendarVersion::WEEKDAYS, [] ); $overnight_week['fri'] = [ [ 'open' => '22:00', 'close' => '24:00' ] ]; $overnight_week['sat'] = [ [ 'open' => '00:00', 'close' => '02:00' ] ];
	$overnight = Vectors::calendar( [ 'weekly_openings' => $overnight_week ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 120, 120, 'business_minutes', $overnight ) ] ), [ $overnight ] ), '2026-10-09 23:00:00.000000' ), [ $overnight ] );
	$check( 'PURE-W2P03-SPLIT-OVERNIGHT-CARRY', $window( $result, '2026-10-10 01:00:00.000000', '2026-10-10 01:00:00.000000' ), [ 'explicit_next_date_segment' => $window( $result, '2026-10-10 01:00:00.000000', '2026-10-10 01:00:00.000000' ) ] , 'calculation' );
	$closed = Vectors::calendar( [ 'weekly_openings' => $office_week, 'closed_dates' => [ '2026-10-09' ] ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 60, 60, 'business_minutes', $closed ) ] ), [ $closed ] ) ), [ $closed ] );
	$check( 'PURE-W2P03-CLOSURE-OVERRIDES-WEEKLY-OPENING', $window( $result, '2026-10-12 10:00:00.000000', '2026-10-12 10:00:00.000000' ), [ 'configured_closure_wait' => $window( $result, '2026-10-12 10:00:00.000000', '2026-10-12 10:00:00.000000' ) ] , 'calculation' );
	$exception = Vectors::calendar( [ 'weekly_openings' => $office_week, 'exception_openings' => [ [ 'date' => '2026-10-10', 'intervals' => [ [ 'open' => '10:00', 'close' => '12:00' ] ] ] ] ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 30, 30, 'business_minutes', $exception ) ] ), [ $exception ] ), '2026-10-10 09:00:00.000000' ), [ $exception ] );
	$check( 'PURE-W2P03-EXPLICIT-DATE-OPENING', $window( $result, '2026-10-10 10:30:00.000000', '2026-10-10 10:30:00.000000' ), [ 'exception_opening_used_without_holiday_inference' => $window( $result, '2026-10-10 10:30:00.000000', '2026-10-10 10:30:00.000000' ) ] , 'calculation' );
	$exception_closed = Vectors::calendar( [ 'weekly_openings' => $office_week, 'closed_dates' => [ '2026-10-10' ], 'exception_openings' => $exception->private_facts()['exception_openings'] ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 60, 60, 'business_minutes', $exception_closed ) ] ), [ $exception_closed ] ), '2026-10-10 09:00:00.000000' ), [ $exception_closed ] );
	$check( 'PURE-W2P03-CLOSURE-OVERRIDES-EXCEPTION', $window( $result, '2026-10-12 10:00:00.000000', '2026-10-12 10:00:00.000000' ), [ 'closed_date_has_priority' => $window( $result, '2026-10-12 10:00:00.000000', '2026-10-12 10:00:00.000000' ) ] , 'calculation' );
	$ny = Vectors::calendar( [ 'calendar_id' => 'new-york', 'timezone' => 'America/New_York', 'weekly_openings' => $office_week ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 60, 60, 'business_minutes', $ny ) ] ), [ $ny ], [ 'promise_timezone' => 'America/New_York' ] ), '2026-10-09 12:00:00.000000' ), [ $ny ] );
	$check( 'PURE-W2P03-IANA-LOCAL-OPENING-UTC-RESULT', $window( $result, '2026-10-09 14:00:00.000000', '2026-10-09 14:00:00.000000' ), [ 'local_nine_is_thirteen_utc' => $window( $result, '2026-10-09 14:00:00.000000', '2026-10-09 14:00:00.000000' ), 'display_zone_explicit' => 'America/New_York' === $result->private_facts()['body']['display_timezone'] ] , 'calculation' );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 1, 1, 'business_days', $office ) ] ), [ $office ] ), '2026-10-09 18:00:00.000000' ), [ $office ] );
	$check( 'PURE-W2P03-BUSINESS-DAY-EXCLUDES-START', $window( $result, '2026-10-13 09:00:00.000000', '2026-10-13 09:00:00.000000' ), [ 'friday_after_close_monday_start_tuesday_completion' => $window( $result, '2026-10-13 09:00:00.000000', '2026-10-13 09:00:00.000000' ) ] , 'calculation' );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 1, 1, 'calendar_days', $ny ) ] ), [ $ny ] ), '2026-03-07 17:00:00.000000' ), [ $ny ] );
	$check( 'PURE-W2P03-CALENDAR-DAY-PRESERVES-CIVIL-TIME-ACROSS-DST', $window( $result, '2026-03-08 16:00:00.000000', '2026-03-08 16:00:00.000000' ), [ 'calendar_day_is_not_fixed_twenty_four_hours' => $window( $result, '2026-03-08 16:00:00.000000', '2026-03-08 16:00:00.000000' ) ] , 'calculation' );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 1440, 1440 ) ] ) ), '2026-03-07 17:00:00.000000' ) );
	$check( 'PURE-W2P03-ELAPSED-DAY-RETAINS-TWENTY-FOUR-HOURS', $window( $result, '2026-03-08 17:00:00.000000', '2026-03-08 17:00:00.000000' ), [ 'elapsed_minutes_not_calendar_days' => $window( $result, '2026-03-08 17:00:00.000000', '2026-03-08 17:00:00.000000' ) ] , 'calculation' );
	$gap_week = array_fill_keys( BusinessCalendarVersion::WEEKDAYS, [] ); $gap_week['sun'] = [ [ 'open' => '02:15', 'close' => '03:30' ] ];
	$gap = Vectors::calendar( [ 'timezone' => 'America/New_York', 'weekly_openings' => $gap_week ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 10, 10, 'business_minutes', $gap ) ] ), [ $gap ] ), '2026-03-08 06:00:00.000000' ), [ $gap ] );
	$check( 'PURE-W2P03-DST-GAP-NOT-NORMALIZED', $refused( $result ), [ 'nonexistent_opening_refuses_whole_result' => $refused( $result ) ] , 'calculation' );
	$fold_week = array_fill_keys( BusinessCalendarVersion::WEEKDAYS, [] ); $fold_week['sun'] = [ [ 'open' => '01:30', 'close' => '02:30' ] ];
	$fold = Vectors::calendar( [ 'timezone' => 'America/New_York', 'weekly_openings' => $fold_week ] );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 10, 10, 'business_minutes', $fold ) ] ), [ $fold ] ), '2026-11-01 04:00:00.000000' ), [ $fold ] );
	$check( 'PURE-W2P03-DST-FOLD-REQUIRES-DISAMBIGUATION', $refused( $result ), [ 'ambiguous_opening_not_arbitrarily_chosen' => $refused( $result ) ] , 'calculation' );
	$math = new PromiseCalendarArithmetic(); $math_budget = new PromiseCalculationBudget();
	$first_fold = $math->resolve_local( '2026-10-25', '01:30:00.000000', 'Europe/London', 3600, $math_budget ); $second_fold = $math->resolve_local( '2026-10-25', '01:30:00.000000', 'Europe/London', 0, $math_budget );
	$wrong_fold = false; try { $math->resolve_local( '2026-10-25', '01:30:00.000000', 'Europe/London', 7200, $math_budget ); } catch ( PromiseCalculationException ) { $wrong_fold = true; }
	$fingerprints( [ 'kind' => 'calendar_math', 'input_digest' => hash( 'sha256', 'p03-pure-civil-input:' . PromiseJson::encode( [ 'date' => '2026-10-25', 'time' => '01:30:00.000000', 'zone' => 'Europe/London', 'offsets' => [ 3600, 0, 7200 ], 'runtime' => Vectors::runtime() ] ) ), 'result_digest' => hash( 'sha256', 'p03-pure-civil-output:' . PromiseJson::encode( [ 'answers' => [ $first_fold->sql(), $second_fold->sql() ], 'wrong_offset_refused' => $wrong_fold ] ) ), 'calendar_digests' => [], 'steps' => $math_budget->used_steps() ] );
	$check( 'PURE-W2P03-EXACT-OFFSET-ROUNDTRIPS-BOTH-FOLDS', $first_fold->sql() === '2026-10-25 00:30:00.000000' && $second_fold->sql() === '2026-10-25 01:30:00.000000' && $wrong_fold, [ 'first_fold_exact_offset' => $first_fold->sql() === '2026-10-25 00:30:00.000000', 'second_fold_exact_offset' => $second_fold->sql() === '2026-10-25 01:30:00.000000', 'wrong_offset_not_a_normalization_route' => $wrong_fold ] , 'calendar_math' );

	$cutoff = [ 'kind' => 'local_time', 'calendar' => $office->reference()->private_facts(), 'time' => '16:00', 'missed_window_rule' => 'reject' ];
	$cutoff_policy = $single( Vectors::component(), [ $office ], [ 'cutoff' => $cutoff ] );
	$result = $calculate( Vectors::input( $cutoff_policy, '2026-10-09 15:59:00.000000' ), [ $office ] );
	$check( 'PURE-W2P03-BEFORE-CUTOFF-ADMITTED', $window( $result, '2026-10-09 16:09:00.000000', '2026-10-09 16:19:00.000000' ), [ 'before_cutoff_uses_original_start' => $window( $result, '2026-10-09 16:09:00.000000', '2026-10-09 16:19:00.000000' ) ] , 'calculation' );
	$result = $calculate( Vectors::input( $cutoff_policy, '2026-10-09 16:00:00.000000' ), [ $office ] );
	$check( 'PURE-W2P03-CUTOFF-EQUALITY-MISSES', $refused( $result ), [ 'equal_cutoff_refuses' => $refused( $result ) ] , 'calculation' );
	$result = $calculate( Vectors::input( $cutoff_policy, '2026-10-09 16:00:00.000001' ), [ $office ] );
	$check( 'PURE-W2P03-AFTER-CUTOFF-MISSES', $refused( $result ), [ 'later_than_cutoff_refuses' => $refused( $result ) ] , 'calculation' );
	$cutoff['missed_window_rule'] = 'next_opening'; $next_policy = $single( Vectors::component( changes: [ 'duration' => $duration( 1, 1, 'business_days', $office ) ] ), [ $office ], [ 'cutoff' => $cutoff ] );
	$result = $calculate( Vectors::input( $next_policy, '2026-10-09 16:00:00.000000' ), [ $office ] );
	$check( 'PURE-W2P03-CUTOFF-NEXT-OPENING-EXCLUDE-START', $window( $result, '2026-10-13 09:00:00.000000', '2026-10-13 09:00:00.000000' ), [ 'missed_friday_moves_to_monday_then_one_business_day' => $window( $result, '2026-10-13 09:00:00.000000', '2026-10-13 09:00:00.000000' ) ] , 'calculation' );
	$express_calendar = Vectors::calendar();
	$express = $single( Vectors::component( changes: [ 'duration' => $duration( 10, 360 ), 'operating_calendar' => $express_calendar->reference()->private_facts(), 'completion_window_rule' => 'within_open_interval' ] ), [ $express_calendar ], [ 'cutoff' => [ 'kind' => 'local_time', 'calendar' => $express_calendar->reference()->private_facts(), 'time' => '16:00', 'missed_window_rule' => 'reject' ] ] );
	$result = $calculate( Vectors::input( $express, '2026-10-09 15:59:00.000000' ), [ $express_calendar ] );
	$check( 'PURE-W2P03-EXPRESS-UPPER-CANNOT-BE-CLIPPED', $refused( $result ), [ 'before_cutoff_insufficient_for_upper_feasibility' => $refused( $result ), 'no_partial_winning_window' => null === $result->private_facts()['body'] ] , 'calculation' );
	$before_close = $single( Vectors::component(), [ $office ], [ 'cutoff' => [ 'kind' => 'before_close', 'calendar' => $office->reference()->private_facts(), 'minutes_before_close' => 60, 'missed_window_rule' => 'reject' ] ] );
	$result = $calculate( Vectors::input( $before_close, '2026-10-09 16:00:00.000000' ), [ $office ] );
	$check( 'PURE-W2P03-BEFORE-CLOSE-CUTOFF-EQUALITY', $refused( $result ), [ 'configured_minutes_before_close_not_label_authority' => $refused( $result ) ] , 'calculation' );

	$prep = Vectors::component( 'prepare', [ 'role' => 'preparation', 'endpoint' => 'warehouse', 'endpoint_kind' => 'origin', 'duration' => $duration( 60, 60 ) ] );
	$delivery = Vectors::component( changes: [ 'predecessors' => [ 'prepare' ], 'duration' => $duration( 120, 120, 'business_minutes', $office ), 'operating_calendar' => $office->reference()->private_facts() ] );
	$sequential = Vectors::policy( [ 'graph' => [ 'format_version' => 1, 'components' => [ $prep, $delivery ], 'terminal_component_ids' => [ 'delivery' ] ] ], [ $office ] );
	$result = $calculate( Vectors::input( $sequential, '2026-10-09 16:00:00.000000' ), [ $office ] );
	$check( 'PURE-W2P03-SEQUENTIAL-HANDOVER-AND-NEXT-OPENING', $window( $result, '2026-10-12 11:00:00.000000', '2026-10-12 11:00:00.000000' ), [ 'processing_and_handover_wait_included' => $window( $result, '2026-10-12 11:00:00.000000', '2026-10-12 11:00:00.000000' ) ] , 'calculation' );
	$left = Vectors::component( 'prepare', [ 'role' => 'preparation', 'endpoint' => 'warehouse', 'endpoint_kind' => 'origin', 'duration' => $duration( 30, 60 ) ] );
	$right = Vectors::component( 'transfer', [ 'role' => 'transit', 'endpoint' => 'hub', 'endpoint_kind' => 'handover', 'duration' => $duration( 60, 90 ) ] );
	$join = Vectors::component( changes: [ 'predecessors' => [ 'prepare', 'transfer' ], 'duration' => $duration( 10, 20 ) ] );
	$parallel = Vectors::policy( [ 'graph' => [ 'format_version' => 1, 'components' => [ $left, $right, $join ], 'terminal_component_ids' => [ 'delivery' ] ] ] );
	$result = $calculate( Vectors::input( $parallel ) );
	$check( 'PURE-W2P03-PARALLEL-JOIN-MAX-LOWER-MAX-UPPER', $window( $result, '2026-10-09 11:10:00.000000', '2026-10-09 11:50:00.000000' ), [ 'parallel_branches_not_summed' => $window( $result, '2026-10-09 11:10:00.000000', '2026-10-09 11:50:00.000000' ) ] , 'calculation' );
	$phases = []; $predecessors = [];
	foreach ( [ 'preparation' => 10, 'dispatch' => 20, 'transit' => 30, 'buffer' => 40, 'final_mile' => 50 ] as $role => $minutes ) {
		$id = 'final_mile' === $role ? 'delivery' : $role;
		$phases[] = Vectors::component( $id, [ 'role' => $role, 'duration' => $duration( $minutes, $minutes ), 'predecessors' => $predecessors, 'endpoint' => 'final_mile' === $role ? 'customer-door' : 'handover-' . $role, 'endpoint_kind' => 'final_mile' === $role ? 'doorstep' : 'handover' ] ); $predecessors = [ $id ];
	}
	$five_phase = Vectors::policy( [ 'graph' => [ 'format_version' => 1, 'components' => $phases, 'terminal_component_ids' => [ 'delivery' ] ] ] );
	$result = $calculate( Vectors::input( $five_phase ) );
	$check( 'PURE-W2P03-ALL-FIVE-PHASES-IN-SEQUENTIAL-COMPLETION', $window( $result, '2026-10-09 12:30:00.000000', '2026-10-09 12:30:00.000000' ), [ 'preparation_dispatch_transit_buffer_final_mile_counted' => $window( $result, '2026-10-09 12:30:00.000000', '2026-10-09 12:30:00.000000' ), 'final_endpoint_is_real_final_mile' => $result->input()->policy()->endpoint_kind() === 'doorstep' ] , 'calculation' );
	$tokyo = Vectors::calendar( [ 'calendar_id' => 'tokyo-origin', 'timezone' => 'Asia/Tokyo' ] ); $new_york = Vectors::calendar( [ 'calendar_id' => 'ny-destination', 'timezone' => 'America/New_York' ] );
	$origin_phase = Vectors::component( 'prepare', [ 'role' => 'preparation', 'endpoint' => 'origin-hub', 'endpoint_kind' => 'origin', 'duration' => $duration( 60, 60, 'business_minutes', $tokyo ) ] );
	$destination_phase = Vectors::component( changes: [ 'predecessors' => [ 'prepare' ], 'duration' => $duration( 60, 60, 'business_minutes', $new_york ) ] );
	$cross_zone = Vectors::policy( [ 'promise_timezone' => 'America/New_York', 'graph' => [ 'format_version' => 1, 'components' => [ $origin_phase, $destination_phase ], 'terminal_component_ids' => [ 'delivery' ] ] ], [ $tokyo, $new_york ] );
	$result = $calculate( Vectors::input( $cross_zone, '2026-10-09 23:00:00.000000' ), [ $tokyo, $new_york ] );
	$check( 'PURE-W2P03-CROSS-ZONE-MIDNIGHT-HANDOVER', $window( $result, '2026-10-10 14:00:00.000000', '2026-10-10 14:00:00.000000' ), [ 'origin_and_destination_openings_use_own_iana_zones' => $window( $result, '2026-10-10 14:00:00.000000', '2026-10-10 14:00:00.000000' ), 'utc_and_destination_display_zone_remain_explicit' => 'America/New_York' === $result->private_facts()['body']['display_timezone'] ] , 'calculation' );
	$port = Vectors::component( 'port', [ 'role' => 'transit', 'endpoint' => 'arrival-port', 'endpoint_kind' => 'port', 'duration' => $duration( 10, 20 ) ] );
	$independent = Vectors::policy( [ 'graph' => [ 'format_version' => 1, 'components' => [ $port, Vectors::component( changes: [ 'duration' => $duration( 30, 60 ) ] ) ], 'terminal_component_ids' => [ 'port', 'delivery' ] ] ] );
	$result = $calculate( Vectors::input( $independent ) ); $windows = $result->private_facts()['body']['terminal_windows'];
	$check( 'PURE-W2P03-INDEPENDENT-ENDPOINTS-REMAIN-SEPARATE', [ [ 'component_id' => 'delivery', 'from' => '2026-10-09 10:30:00.000000', 'until' => '2026-10-09 11:00:00.000000' ], [ 'component_id' => 'port', 'from' => '2026-10-09 10:10:00.000000', 'until' => '2026-10-09 10:20:00.000000' ] ] === $windows, [ 'all_terminal_windows_preserved' => count( $windows ) === 2, 'port_not_substituted_for_doorstep' => PublicPromiseView::from_result( $result, 'delivery' )->fields()['until'] === '2026-10-09 11:00:00.000000' ] , 'calculation' );

	$payment_policy = Vectors::policy( [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] );
	$result = $calculate( Vectors::input( $payment_policy ) ); $view = PublicPromiseView::from_result( $result, 'delivery' )->fields();
	$check( 'PURE-W2P03-PAYMENT-RELATIVE-NO-ABSOLUTE-DATE', 'relative_window' === $result->state() && 10 === $view['min'] && 20 === $view['max'], [ 'unknown_payment_event_does_not_invent_instant' => ! isset( $view['from'], $view['until'] ), 'explicit_waited_payment_explanation' => 'after_payment_confirmation' === $view['relative_explanation'], 'range_remains_elapsed_minutes' => 'elapsed_minutes' === $view['unit'] ] , 'calculation' );
	$payment_zero = $single( Vectors::component( changes: [ 'duration' => $duration( 0, 0 ) ] ), [], [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] );
	$result = $calculate( Vectors::input( $payment_zero ) ); $view = PublicPromiseView::from_result( $result, 'delivery' )->fields();
	$check( 'PURE-W2P03-PAYMENT-RELATIVE-KNOWN-ZERO', 'relative_window' === $result->state() && true === $view['known_zero'], [ 'zero_is_distinct_from_missing_event' => true === $view['known_zero'] && 0 === $view['min'] && 0 === $view['max'], 'no_absolute_projection' => ! isset( $view['from'], $view['until'] ) ] , 'calculation' );
	$mixed = $single( Vectors::component( changes: [ 'duration' => $duration( 1, 1, 'business_days', $office ), 'operating_calendar' => $office->reference()->private_facts() ] ), [ $office ], [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] );
	$result = $calculate( Vectors::input( $mixed ), [ $office ] );
	$check( 'PURE-W2P03-RELATIVE-OPENING-WAIT-NOT-FABRICATED', $refused( $result ), [ 'event_unknown_calendar_wait_cannot_be_claimed_as_fixed_duration' => $refused( $result ) ] , 'calculation' );
	$capacity_policy = Vectors::policy( [ 'capacity_mode' => 'required', 'capacity_source' => Vectors::source( 'capacity' ) ] );
	$capacity_input = Vectors::input( $capacity_policy ); $result = $calculate( $capacity_input );
	$check( 'PURE-W2P03-CAPACITY-EXACT-AVAILABLE-OBSERVATION', $window( $result, '2026-10-09 10:10:00.000000', '2026-10-09 10:20:00.000000' ), [ 'captured_window_contains_complete_result' => $window( $result, '2026-10-09 10:10:00.000000', '2026-10-09 10:20:00.000000' ), 'no_capacity_hold_or_reservation_added' => $capacity_input->capacity()->private_facts() === $result->input()->capacity()->private_facts() ] , 'calculation' );
	$capacity = $capacity_input->capacity()->private_facts(); $capacity['state'] = 'unknown'; $result = $calculate( Vectors::input( $capacity_policy, capacity: $capacity ) );
	$check( 'PURE-W2P03-CAPACITY-UNKNOWN-REFUSES', $refused( $result ), [ 'required_unknown_not_unlimited' => $refused( $result ) ] , 'calculation' );
	$capacity = $capacity_input->capacity()->private_facts(); $capacity['state'] = 'unavailable'; $result = $calculate( Vectors::input( $capacity_policy, capacity: $capacity ) );
	$check( 'PURE-W2P03-CAPACITY-UNAVAILABLE-REFUSES', $refused( $result ), [ 'explicit_unavailable_not_omitted' => $refused( $result ) ] , 'calculation' );
	$capacity = $capacity_input->capacity()->private_facts(); $capacity['observed_at'] = '2026-10-09 09:59:00.000000'; $capacity['valid_until'] = '2026-10-09 10:00:00.000000'; $result = $calculate( Vectors::input( $capacity_policy, capacity: $capacity ) );
	$check( 'PURE-W2P03-CAPACITY-VALIDITY-EQUALITY-STALE', $refused( $result ), [ 'exclusive_validity_boundary' => $refused( $result ) ] , 'calculation' );
	$capacity = $capacity_input->capacity()->private_facts(); $capacity['window']['until'] = '2026-10-09 10:15:00.000000'; $result = $calculate( Vectors::input( $capacity_policy, capacity: $capacity ) );
	$check( 'PURE-W2P03-CAPACITY-COMPLETE-UPPER-WINDOW-REQUIRED', $refused( $result ), [ 'upper_cannot_be_clipped_to_capacity' => $refused( $result ), 'no_partial_winner' => null === $result->private_facts()['body'] ] , 'calculation' );
	$payment_capacity = Vectors::policy( [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment', 'capacity_mode' => 'required', 'capacity_source' => Vectors::source( 'capacity' ) ] );
	$result = $calculate( Vectors::input( $payment_capacity ) );
	$check( 'PURE-W2P03-FUTURE-PAYMENT-CAPACITY-NOT-CURRENT-AVAILABILITY', $refused( $result ), [ 'unknown_future_event_cannot_use_current_slot' => $refused( $result ) ] , 'calculation' );

	$same_day = $single( Vectors::component( changes: [ 'duration' => $duration( 360, 1440 ) ] ), [], [ 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'same_day', 'customer_label' => 'Same Day' ], 'day_constraint' => 'same_day' ] );
	$result = $calculate( Vectors::input( $same_day ) );
	$check( 'PURE-W2P03-SAME-DAY-LITERAL-COMPLETION', $refused( $result ), [ 'misleading_six_to_twenty_four_hours_refused' => $refused( $result ) ] , 'calculation' );
	$next_day = $single( Vectors::component( changes: [ 'duration' => $duration( 1440, 2880 ) ] ), [], [ 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'next_day', 'customer_label' => 'Next Day' ], 'day_constraint' => 'next_day' ] );
	$result = $calculate( Vectors::input( $next_day ) );
	$check( 'PURE-W2P03-NEXT-DAY-LITERAL-COMPLETION', $refused( $result ), [ 'misleading_twenty_four_to_forty_eight_hours_refused' => $refused( $result ) ] , 'calculation' );
	$midnight = Vectors::input( Vectors::policy( [ 'anchor' => 'order_accepted', 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'same_day', 'customer_label' => 'Same Day' ], 'day_constraint' => 'same_day' ] ), '2026-10-09 23:58:00.000000' );
	$result = $calculate( $midnight );
	$check( 'PURE-W2P03-ACCEPTANCE-MIDNIGHT-CAP-NO-ROLLOVER', $refused( $result ) && $midnight->anchor()->accept_until()?->sql() === '2026-10-10 00:00:00.000000', [ 'acceptance_cap_distinct_from_quote_ttl' => $midnight->anchor()->accept_until()?->sql() !== $midnight->anchor()->quote_expires_at()->sql(), 'upper_may_not_roll_into_new_service_day' => $refused( $result ) ] , 'calculation' );
	$result = $calculate( Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 1052640, 1052640 ) ] ) ) ) );
	$check( 'PURE-W2P03-LOOKAHEAD-PLUS-ONE-WHOLE-RESULT-REFUSAL', $refused( $result ), [ 'seven_hundred_thirty_one_days_not_partial_promise' => $refused( $result ) ] , 'calculation' );
	$missing_policy = $single( Vectors::component( changes: [ 'duration' => $duration( 10, 20, 'business_minutes', $office ) ] ), [ $office ] );
	$result = $calculate( Vectors::input( $missing_policy ), [] );
	$check( 'PURE-W2P03-MISSING-CALENDAR-ENTIRE-RESULT-REFUSES', $refused( $result ), [ 'no_elapsed_fallback_for_missing_calendar' => $refused( $result ) ] , 'calculation' );
	$changed_calendar = Vectors::calendar( [ 'weekly_openings' => $office_week, 'version' => 2 ] );
	$result = $calculate( Vectors::input( $missing_policy ), [ $changed_calendar ] );
	$check( 'PURE-W2P03-CHANGED-CALENDAR-EXACT-REFERENCE-REFUSES', $refused( $result ), [ 'current_calendar_does_not_replace_captured_version' => $refused( $result ) ] , 'calculation' );
	$raw = Vectors::input()->private_facts(); $raw['runtime']['timezone_data_version'] = 'wrong-runtime-version'; $input = PromiseInput::from_array( $raw ); $result = $calculate( $input );
	$check( 'PURE-W2P03-TIMEZONE-DATA-PROVENANCE-REFUSES', $refused( $result ), [ 'captured_zone_data_must_match_actual_runtime' => $refused( $result ), 'refusal_before_any_math_step' => 0 === $calculator->last_steps() ] , 'calculation' );
	$raw = Vectors::input()->private_facts(); $raw['runtime']['runtime_id'] = 'different-captured-host'; $result = $calculate( PromiseInput::from_array( $raw ) );
	$check( 'PURE-W2P03-INDEPENDENT-RUNTIME-ID-CONSISTENCY', $refused( $result ), [ 'matching_timezone_label_does_not_authorize_other_host_context' => $refused( $result ), 'refusal_before_any_math_step' => 0 === $calculator->last_steps() ] , 'calculation' );
	$raw = Vectors::input()->private_facts(); $raw['runtime']['digest'] = hash( 'sha256', 'different-captured-context' ); $result = $calculate( PromiseInput::from_array( $raw ) );
	$check( 'PURE-W2P03-INDEPENDENT-RUNTIME-DIGEST-CONSISTENCY', $refused( $result ), [ 'matching_timezone_label_does_not_authorize_other_context_digest' => $refused( $result ), 'refusal_before_any_math_step' => 0 === $calculator->last_steps() ] , 'calculation' );
	$budget = new PromiseCalculationBudget(); $budget->consume( 100000 ); $exact_budget = $budget->used_steps() === 100000 && ! $budget->exhausted();
	$plus_one = false; try { $budget->consume(); } catch ( PromiseCalculationException $error ) { $plus_one = 'budget_exceeded' === $error->reason() && $budget->exhausted() && 100000 === $budget->used_steps(); }
	$minute_week = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $minute_week[$day] = [ [ 'open' => '09:00', 'close' => '09:01' ] ]; }
	$minute_calendar = Vectors::calendar( [ 'weekly_openings' => $minute_week ] ); $expensive = Vectors::input( $single( Vectors::component( changes: [ 'duration' => $duration( 700, 700, 'business_minutes', $minute_calendar ) ] ), [ $minute_calendar ], [ 'promise_required' => false ] ) );
	$early = Vectors::input(); $early_winner = $calculator->calculate( $early, [] ); $expensive_inputs = [ $early ];
	for ( $index = 1; $index <= 20; ++$index ) { $raw = $expensive->private_facts(); $raw['material']['group_id'] = 'expensive-group-' . $index; $expensive_inputs[] = PromiseInput::from_array( $raw ); }
	$exhausted_results = $calculator->calculate_cart( $expensive_inputs, [ $minute_calendar ] ); $actual_shared_exhaustion = 100000 === $calculator->last_steps();
	$all_cleared = 21 === count( $exhausted_results ) && [] === array_filter( $exhausted_results, static fn( PromiseResult $item ): bool => [ 'budget_exceeded' ] !== $item->private_facts()['reason_codes'] || null !== $item->private_facts()['body'] );
	$cart_fingerprints( $expensive_inputs, $exhausted_results, [ $minute_calendar ] );
	$check( 'PURE-W2P03-SHARED-WORK-BUDGET-EXACT-AND-PLUS-ONE', $exact_budget && $plus_one && $actual_shared_exhaustion && $all_cleared, [ 'exact_hundred_thousand_steps_valid' => $exact_budget, 'next_charge_refuses_and_poison_is_retained' => $plus_one, 'actual_calculator_shared_budget_exhausted' => $actual_shared_exhaustion, 'optional_group_exhaustion_clears_all_prior_winners' => 'absolute_window' === $early_winner->state() && $all_cleared ] , 'cart' );
	$first = Vectors::input(); $second_raw = $first->private_facts(); $second_raw['material']['group_id'] = 'group-2'; $second_raw['policy']['graph']['components'][0]['duration']['max'] = 1052640; $second = PromiseInput::from_array( $second_raw ); $cart = $calculator->calculate_cart( [ $first, $second ], [] ); $cart_fingerprints( [ $first, $second ], $cart );
	$check( 'PURE-W2P03-REQUIRED-GROUP-REFUSAL-CLEARS-OTHER-WINNERS', count( $cart ) === 2 && $refused( $cart[0] ) && $refused( $cart[1] ), [ 'required_incomplete_branch_refuses_complete_cart' => count( $cart ) === 2 && $refused( $cart[0] ) && $refused( $cart[1] ), 'no_partial_winning_group' => null === $cart[0]->private_facts()['body'] && null === $cart[1]->private_facts()['body'] ] , 'cart' );
	$input = Vectors::input(); $groups = [];
	for ( $index = 1; $index <= 200; ++$index ) { $raw = $input->private_facts(); $raw['material']['group_id'] = 'group-' . $index; $groups[] = PromiseInput::from_array( $raw ); }
	$cart = $calculator->calculate_cart( $groups, [] ); $whole_packet = 200 === count( $cart ) && [] === array_filter( $cart, static fn( PromiseResult $item ): bool => ! $refused( $item ) ); $cart_fingerprints( $groups, $cart );
	$group_plus_one = false; $groups[] = $input; try { $calculator->calculate_cart( $groups, [] ); } catch ( InvalidArgumentException ) { $group_plus_one = true; }
	$check( 'PURE-W2P03-CART-BOUND-AND-PACKET-WHOLE-REFUSAL', $whole_packet && $group_plus_one, [ 'two_hundred_groups_fit_input_bound' => 200 === count( $cart ), 'oversized_result_packet_has_no_winners' => $whole_packet, 'two_hundred_one_inputs_refused' => $group_plus_one ] , 'cart' );
	$raw = Vectors::input()->private_facts(); $input = PromiseInput::from_array( $raw ); $result = $calculate( $input ); $public = PublicPromiseView::from_result( $result, 'delivery' )->fields();
	$check( 'PURE-W2P03-PUBLIC-PROJECTION-EXCLUDES-PRIVATE-FACTS', ! isset( $public['input'], $public['origin'], $public['capacity'], $public['source_receipts'], $public['policy'] ), [ 'public_closed_projection' => [ 'format_version', 'service_label', 'state', 'display_timezone', 'reason_codes', 'from', 'until' ] === array_keys( $public ), 'source_owner_capacity_policy_not_disclosed' => ! isset( $public['input'], $public['origin'], $public['capacity'], $public['source_receipts'], $public['policy'] ) ] , 'calculation' );
};
