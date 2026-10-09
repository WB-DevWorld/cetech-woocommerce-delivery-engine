<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Calculation;

use CetechDeliveryEngine\Application\ServicePromise\Calculation\PromiseCalendarArithmetic;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\PromiseCalculationBudget;
use CetechDeliveryEngine\Application\ServicePromise\Calculation\PromiseCalculationException;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\BusinessCalendarVersion;
use PHPUnit\Framework\TestCase;

final class PromiseCalendarArithmeticTest extends TestCase {
	public function test_local_round_trip_preserves_microseconds_and_does_not_use_default_timezone(): void {
		$old = date_default_timezone_get(); date_default_timezone_set( 'Pacific/Honolulu' );
		try {
			$math = new PromiseCalendarArithmetic(); $budget = new PromiseCalculationBudget();
			self::assertSame( '2026-10-09 10:01:02.123456', $math->resolve_local( '2026-10-09', '10:01:02.123456', 'Africa/Accra', 0, $budget )->sql() );
			self::assertSame( '2026-10-08 23:30:00.000000', $math->resolve_local( '2026-10-09', '08:30', 'Asia/Tokyo', 32400, $budget )->sql() );
			self::assertGreaterThan( 0, $budget->used_steps() );
		} finally { date_default_timezone_set( $old ); }
	}
	public function test_repeated_wall_time_requires_a_real_explicit_occurrence(): void {
		$math = new PromiseCalendarArithmetic();
		self::assertSame( '2026-10-25 00:30:00.000000', $math->resolve_local( '2026-10-25', '01:30', 'Europe/London', 3600 )->sql() );
		self::assertSame( '2026-10-25 01:30:00.000000', $math->resolve_local( '2026-10-25', '01:30', 'Europe/London', 0 )->sql() );
		$this->refuses( 'estimate_unavailable', fn() => $math->resolve_local( '2026-10-25', '01:30', 'Europe/London' ) );
		$this->refuses( 'estimate_unavailable', fn() => $math->resolve_local( '2026-10-25', '01:30', 'Europe/London', 7200 ) );
	}
	/** @dataProvider nonexistent_wall_times */
	public function test_gap_and_invalid_civil_facts_are_not_normalized( string $date, string $time, string $zone, ?int $offset ): void {
		$this->refuses( 'estimate_unavailable', fn() => ( new PromiseCalendarArithmetic() )->resolve_local( $date, $time, $zone, $offset ) );
	}
	public static function nonexistent_wall_times(): array {
		return [ [ '2026-03-29', '01:30', 'Europe/London', null ], [ '2026-03-29', '01:30', 'Europe/London', 0 ], [ '2026-03-29', '01:30', 'Europe/London', 3600 ], [ '2011-12-30', '12:00', 'Pacific/Apia', -36000 ], [ '2026-02-29', '12:00', 'UTC', 0 ], [ '2026-10-09', '24:00', 'UTC', 0 ], [ '2026-10-09', '12:00', 'UTC', 3600 ] ];
	}
	public function test_unknown_zone_is_a_distinct_safe_refusal(): void { $this->refuses( 'unknown_timezone', fn() => ( new PromiseCalendarArithmetic() )->resolve_local( '2026-10-09', '12:00', '+00:00' ) ); }
	public function test_elapsed_minutes_and_calendar_days_are_distinct_across_dst(): void {
		$math = new PromiseCalendarArithmetic(); $calendar = self::calendar( 'Europe/London' ); $from = self::time( '2026-03-28 12:00:00.123456' ); $budget = new PromiseCalculationBudget();
		self::assertSame( '2026-03-29 12:00:00.123456', $math->add( $from, 1440, 'elapsed_minutes', null, $budget )->sql() );
		self::assertSame( '2026-03-29 11:00:00.123456', $math->add( $from, 1, 'calendar_days', $calendar, $budget )->sql() );
		self::assertSame( '2026-10-25 12:00:00.123456', $math->add( self::time( '2026-10-24 11:00:00.123456' ), 1, 'calendar_days', $calendar, $budget )->sql() );
	}
	public function test_wall_day_target_gap_or_fold_refuses_even_when_php_would_normalize_or_pick(): void {
		$math = new PromiseCalendarArithmetic(); $calendar = self::calendar( 'Europe/London' );
		$this->refuses( 'estimate_unavailable', fn() => $math->add( self::time( '2026-03-28 01:30:00.000000' ), 1, 'calendar_days', $calendar, new PromiseCalculationBudget() ) );
		$this->refuses( 'estimate_unavailable', fn() => $math->add( self::time( '2026-10-24 00:30:00.000000' ), 1, 'calendar_days', $calendar, new PromiseCalculationBudget() ) );
	}
	public function test_business_minutes_carry_exactly_across_breaks_and_completion_at_close(): void {
		$calendar = self::calendar( 'UTC', [ [ 'open' => '09:00', 'close' => '12:00' ], [ 'open' => '13:00', 'close' => '17:00' ] ] ); $math = new PromiseCalendarArithmetic(); $budget = new PromiseCalculationBudget();
		self::assertSame( '2026-10-09 14:15:00.123456', $math->add( self::time( '2026-10-09 11:45:00.123456' ), 90, 'business_minutes', $calendar, $budget )->sql() );
		$close = $math->add( self::time( '2026-10-09 11:45:00.000000' ), 15, 'business_minutes', $calendar, $budget );
		self::assertSame( '2026-10-09 12:00:00.000000', $close->sql() ); self::assertNull( $math->containing_interval( $close, $calendar, $budget ) );
		self::assertSame( '2026-10-09 12:00:00.000000', $math->containing_interval( $close, $calendar, $budget, true )['end']->sql() );
		self::assertSame( '2026-10-09 13:00:00.000000', $math->next_start( $close, $calendar, $budget )->sql() );
	}
	public function test_split_overnight_work_and_recorded_24_hour_close_are_explicit(): void {
		$facts = self::facts( 'UTC', [] ); $facts['weekly_openings']['fri'] = [ [ 'open' => '20:00', 'close' => '24:00' ] ]; $facts['weekly_openings']['sat'] = [ [ 'open' => '00:00', 'close' => '04:00' ] ];
		$calendar = BusinessCalendarVersion::from_array( $facts ); $math = new PromiseCalendarArithmetic(); $budget = new PromiseCalculationBudget();
		self::assertSame( '2026-10-10 03:00:00.000000', $math->add( self::time( '2026-10-09 22:00:00.000000' ), 300, 'business_minutes', $calendar, $budget )->sql() );
		$facts['weekly_openings']['sat'] = []; $calendar = BusinessCalendarVersion::from_array( $facts );
		self::assertNotNull( $math->containing_interval( self::time( '2026-10-10 00:00:00.000000' ), $calendar, $budget, true ) );
		self::assertNull( $math->containing_interval( self::time( '2026-10-10 00:00:00.000000' ), $calendar, $budget ) );
	}
	public function test_closure_wins_over_exception_and_no_monday_friday_week_is_assumed(): void {
		$facts = self::facts( 'UTC', [] ); $facts['weekly_openings']['fri'] = [ [ 'open' => '08:00', 'close' => '16:00' ] ]; $facts['weekly_openings']['sun'] = [ [ 'open' => '12:00', 'close' => '20:00' ] ];
		$facts['closed_dates'] = [ '2026-10-11' ]; $facts['exception_openings'] = [ [ 'date' => '2026-10-11', 'intervals' => [ [ 'open' => '06:00', 'close' => '24:00' ] ] ], [ 'date' => '2026-10-13', 'intervals' => [ [ 'open' => '08:00', 'close' => '10:00' ] ] ] ];
		$math = new PromiseCalendarArithmetic(); $budget = new PromiseCalculationBudget(); $calendar = BusinessCalendarVersion::from_array( $facts );
		self::assertSame( '2026-10-13 09:00:00.000000', $math->add( self::time( '2026-10-09 15:00:00.000000' ), 120, 'business_minutes', $calendar, $budget )->sql() );
		$facts['closed_dates'] = []; array_shift( $facts['exception_openings'] ); self::assertSame( '2026-10-11 13:00:00.000000', $math->add( self::time( '2026-10-09 15:00:00.000000' ), 120, 'business_minutes', BusinessCalendarVersion::from_array( $facts ), $budget )->sql() );
	}
	public function test_business_days_exclude_resolved_eligible_start_and_zero_does_not_advance(): void {
		$math = new PromiseCalendarArithmetic(); $calendar = self::calendar( 'UTC' ); $budget = new PromiseCalculationBudget(); $after_close = self::time( '2026-10-09 17:00:00.123456' );
		self::assertSame( '2026-10-12 08:00:00.000000', $math->next_start( $after_close, $calendar, $budget )->sql() );
		self::assertSame( '2026-10-13 08:00:00.000000', $math->add( $after_close, 1, 'business_days', $calendar, $budget )->sql() );
		self::assertSame( '2026-10-12 10:01:02.123456', $math->add( self::time( '2026-10-09 10:01:02.123456' ), 1, 'business_days', $calendar, $budget )->sql() );
		self::assertSame( '2026-10-12 08:00:00.000000', $math->add( $after_close, 0, 'business_days', $calendar, $budget )->sql() );
		self::assertSame( $after_close, $math->add( $after_close, 0, 'business_minutes', $calendar, $budget ) );
	}
	public function test_business_day_preserves_time_then_moves_to_next_permitted_opening(): void {
		$facts = self::facts( 'UTC', [] ); $facts['weekly_openings']['fri'] = [ [ 'open' => '08:00', 'close' => '16:00' ] ]; $facts['weekly_openings']['mon'] = [ [ 'open' => '12:00', 'close' => '16:00' ] ];
		$math = new PromiseCalendarArithmetic(); self::assertSame( '2026-10-12 12:00:00.000000', $math->add( self::time( '2026-10-09 10:00:00.000000' ), 1, 'business_days', BusinessCalendarVersion::from_array( $facts ), new PromiseCalculationBudget() )->sql() );
	}
	/** @dataProvider dst_working_minutes */
	public function test_business_minutes_count_actual_elapsed_open_work_during_dst( string $date, int $minutes, string $expected ): void {
		$calendar = self::calendar( 'Europe/London', [ [ 'open' => '00:00', 'close' => '04:00' ] ], true ); $math = new PromiseCalendarArithmetic(); $budget = new PromiseCalculationBudget();
		$from = $math->resolve_local( $date, '00:00', 'Europe/London', null, $budget );
		self::assertSame( $expected, $math->add( $from, $minutes, 'business_minutes', $calendar, $budget )->sql() );
	}
	public static function dst_working_minutes(): array { return [ [ '2026-03-29', 180, '2026-03-29 03:00:00.000000' ], [ '2026-10-25', 300, '2026-10-25 04:00:00.000000' ] ]; }
	/** @dataProvider ambiguous_calendar_boundary */
	public function test_calendar_format_without_fold_selector_refuses_ambiguous_or_gap_opening( string $date ): void {
		$math = new PromiseCalendarArithmetic(); $calendar = self::calendar( 'Europe/London', [ [ 'open' => '01:00', 'close' => '04:00' ] ], true );
		$this->refuses( 'estimate_unavailable', fn() => $math->next_start( self::time( $date . ' 00:00:00.000000' ), $calendar, new PromiseCalculationBudget() ) );
	}
	public static function ambiguous_calendar_boundary(): array { return [ [ '2026-03-29' ], [ '2026-10-25' ] ]; }
	public function test_exact_730_civil_date_horizon_and_plus_one(): void {
		$math = new PromiseCalendarArithmetic(); $from = self::time( '2028-01-01 08:00:00.000000' ); $calendar = self::calendar( 'UTC', [ [ 'open' => '00:00', 'close' => '24:00' ] ], true ); $budget = new PromiseCalculationBudget();
		self::assertSame( '2029-12-31 08:00:00.000000', $math->add( $from, 730, 'calendar_days', $calendar, $budget )->sql() );
		self::assertSame( '2029-12-31 08:00:00.000000', $math->add( $from, 730, 'business_days', $calendar, $budget )->sql() );
		$this->refuses( 'budget_exceeded', fn() => $math->add( $from, 731, 'calendar_days', $calendar, $budget ) );
		$this->refuses( 'budget_exceeded', fn() => $math->add( $from, 731 * 1440, 'elapsed_minutes', null, $budget ) );
	}
	public function test_sparse_opening_at_horizon_fits_but_beyond_horizon_or_unopened_refuses(): void {
		$math = new PromiseCalendarArithmetic(); $facts = self::facts( 'UTC', [] ); $facts['exception_openings'] = [ [ 'date' => '2029-12-31', 'intervals' => [ [ 'open' => '08:00', 'close' => '09:00' ] ] ] ]; $from = self::time( '2028-01-01 08:00:00.000000' );
		self::assertSame( '2029-12-31 08:00:00.000000', $math->next_start( $from, BusinessCalendarVersion::from_array( $facts ), new PromiseCalculationBudget() )->sql() );
		$facts['exception_openings'][0]['date'] = '2030-01-01';
		$this->refuses( 'budget_exceeded', fn() => $math->next_start( $from, BusinessCalendarVersion::from_array( $facts ), new PromiseCalculationBudget() ) );
		$this->refuses( 'budget_exceeded', fn() => $math->next_start( $from, self::calendar( 'UTC', [] ), new PromiseCalculationBudget() ) );
	}
	public function test_shared_complete_cart_budget_charges_every_transfer_and_conversion(): void {
		$budget = new PromiseCalculationBudget(); $budget->consume( 99999 ); $math = new PromiseCalendarArithmetic();
		$this->refuses( 'budget_exceeded', fn() => $math->resolve_local( '2026-10-09', '10:00', 'UTC', null, $budget ) ); self::assertSame( 100000, $budget->used_steps() );
	}
	public function test_invalid_units_missing_calendars_and_overflow_refuse_without_coercion(): void {
		$math = new PromiseCalendarArithmetic(); $at = self::time( '2026-10-09 10:00:00.000000' ); $budget = new PromiseCalculationBudget();
		$this->refuses( 'unsupported_policy', fn() => $math->add( $at, -1, 'elapsed_minutes', null, $budget ) );
		$this->refuses( 'unsupported_policy', fn() => $math->add( $at, 1, 'days', null, $budget ) );
		$this->refuses( 'missing_source', fn() => $math->add( $at, 0, 'business_days', null, $budget ) );
		$this->refuses( 'budget_exceeded', fn() => $math->add( $at, PHP_INT_MAX, 'business_minutes', self::calendar(), $budget ) );
		$this->refuses( 'estimate_unavailable', fn() => $math->add( self::time( '9999-12-31 23:59:00.000000' ), 1, 'elapsed_minutes', null, $budget ) );
		self::assertSame( '1000-01-01 00:01:00.000000', $math->add( self::time( '1000-01-01 00:00:00.000000' ), 1, 'elapsed_minutes', null, $budget )->sql() );
	}
	public function test_monotonic_day_envelope_proof_accepts_regular_minutes_but_refuses_hidden_fold(): void {
		$math = new PromiseCalendarArithmetic(); $budget = new PromiseCalculationBudget(); $calendar = self::calendar( 'Europe/London' );
		$math->prove_monotonic( self::time( '2026-10-09 10:00:00.000000' ), self::time( '2026-10-09 10:01:00.000000' ), 1, 'calendar_days', $calendar, $budget ); self::assertGreaterThan( 0, $budget->used_steps() );
		$this->refuses( 'unsupported_policy', fn() => $math->prove_monotonic( self::time( '2026-10-24 23:30:00.000000' ), self::time( '2026-10-25 01:30:00.000000' ), 1, 'calendar_days', $calendar, $budget ) );
		$this->refuses( 'unsupported_policy', fn() => $math->prove_monotonic( self::time( '2026-10-24 23:30:00.000000' ), self::time( '2026-10-25 02:30:00.000000' ), 0, 'unsupported', $calendar, $budget ) );
	}
	public function test_business_minute_envelope_cannot_hide_an_undefined_interior_calendar_date(): void {
		$facts = self::facts( 'America/New_York', [ [ 'open' => '09:00', 'close' => '18:00' ] ] );
		$facts['weekly_openings']['sun'] = [ [ 'open' => '02:30', 'close' => '04:00' ] ]; $calendar = BusinessCalendarVersion::from_array( $facts ); $math = new PromiseCalendarArithmetic(); $budget = new PromiseCalculationBudget();
		$from = self::time( '2026-03-06 14:00:00.000000' ); $until = self::time( '2026-03-09 13:00:00.000000' );
		self::assertSame( '2026-03-06 14:10:00.000000', $math->add( $from, 10, 'business_minutes', $calendar, $budget )->sql() );
		self::assertSame( '2026-03-09 13:10:00.000000', $math->add( $until, 10, 'business_minutes', $calendar, $budget )->sql() );
		$this->refuses( 'estimate_unavailable', fn() => $math->prove_monotonic( $from, $until, 10, 'business_minutes', $calendar, $budget ) );
	}
	private function refuses( string $reason, callable $work ): void {
		try { $work(); self::fail( 'Expected a finite calculation refusal.' ); }
		catch ( PromiseCalculationException $error ) { self::assertSame( $reason, $error->reason() ); }
	}
	private static function time( string $utc ): RuleTime { return RuleTime::parse( $utc ); }
	private static function calendar( string $zone = 'UTC', ?array $openings = null, bool $all_days = false ): BusinessCalendarVersion { return BusinessCalendarVersion::from_array( self::facts( $zone, $openings, $all_days ) ); }
	private static function facts( string $zone, ?array $openings = null, bool $all_days = false ): array {
		$openings ??= [ [ 'open' => '08:00', 'close' => '16:00' ] ]; $week = [];
		foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $week[$day] = $all_days || ! in_array( $day, [ 'sat', 'sun' ], true ) ? $openings : []; }
		return [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'calendar-1', 'version' => 1, 'timezone' => $zone, 'tzdata_version' => '2026b', 'weekly_openings' => $week, 'closed_dates' => [], 'exception_openings' => [], 'sources' => [] ];
	}
}
