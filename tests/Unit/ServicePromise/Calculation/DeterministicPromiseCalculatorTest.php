<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Calculation;

use CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput, PromiseLimits, ServicePromisePolicy};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Calculation\CalculationFixture as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeterministicPromiseCalculatorTest extends TestCase {
	private function calculator(): DeterministicPromiseCalculator { return new DeterministicPromiseCalculator( F::runtime() ); }
	private function fixture_groups( int $count, PromiseInput $input ): array { $out = []; for ( $index = 0; $index < $count; ++$index ) { $facts = $input->private_facts(); $facts['material']['group_id'] = 'group-' . $index; $out[] = PromiseInput::from_array( $facts ); } return $out; }
	private function single( array $duration, array $policy = [], array $calendars = [], string $time = F::EVALUATED, ?array $component = null ): PromiseInput {
		$node = F::component( changes: array_replace( [ 'duration' => [ 'format_version' => 1, 'min' => $duration[0], 'max' => $duration[1], 'unit' => $duration[2] ?? 'elapsed_minutes', 'calendar' => $duration[3] ?? null ] ], $component ?? [] ) );
		return F::input( F::policy( array_replace( [ 'graph' => [ 'format_version' => 1, 'components' => [ $node ], 'terminal_component_ids' => [ 'delivery' ] ] ], $policy ), $calendars ), $time );
	}
	private function reason( PromiseInput $input, string $reason, array $calendars = [] ): void { $result = $this->calculator()->calculate( $input, $calendars ); self::assertNull( $result->private_facts()['body'] ); self::assertSame( [ $reason ], $result->private_facts()['reason_codes'] ); }
	public function test_capture_uses_complete_lower_upper_range_without_clock_or_money_changes(): void {
		$input = F::input(); $bytes = $input->to_private_json(); $calc = $this->calculator(); $result = $calc->calculate( $input, [] );
		self::assertSame( 'absolute_window', $result->state() ); self::assertSame( [ [ 'component_id' => 'delivery', 'from' => '2026-10-09 10:10:00.000000', 'until' => '2026-10-09 10:20:00.000000' ] ], $result->private_facts()['body']['terminal_windows'] ); self::assertSame( $bytes, $input->to_private_json() ); self::assertGreaterThan( 0, $calc->last_steps() );
		$old = date_default_timezone_get(); try { date_default_timezone_set( 'Pacific/Auckland' ); self::assertSame( $result->digest(), $calc->calculate( $input, [] )->digest() ); } finally { date_default_timezone_set( $old ); }
	}
	public function test_order_acceptance_envelope_includes_last_permitted_microsecond_and_preserves_price_expiry(): void {
		$input = $this->single( [ 10, 20 ], [ 'anchor' => 'order_accepted' ] ); $result = $this->calculator()->calculate( $input, [] );
		self::assertSame( '2026-10-09 10:24:59.999999', $result->private_facts()['body']['terminal_windows'][0]['until'] ); self::assertSame( '2026-10-09 10:05:00.000000', $result->input()->anchor()->accept_until()->sql() ); self::assertSame( $input->anchor()->quote_expires_at()->sql(), $result->input()->anchor()->quote_expires_at()->sql() );
	}
	#[DataProvider( 'runtime_changes' )]
	public function test_independent_runtime_context_must_match_all_three_captured_fields( string $field ): void {
		$raw = F::input()->private_facts(); $raw['runtime'][$field] = 'digest' === $field ? str_repeat( 'e', 64 ) : 'changed-runtime'; $calc = $this->calculator(); $result = $calc->calculate( PromiseInput::from_array( $raw ), [] );
		self::assertSame( [ 'source_changed' ], $result->private_facts()['reason_codes'] ); self::assertSame( 0, $calc->last_steps() );
	}
	public static function runtime_changes(): array { return [ [ 'digest' ], [ 'runtime_id' ], [ 'timezone_data_version' ] ]; }
	public function test_runtime_composition_does_not_accept_extra_fields_or_construct_default_authority(): void {
		$this->expectException( \InvalidArgumentException::class ); new DeterministicPromiseCalculator( F::runtime() + [ 'browser_timezone' => 'UTC' ] );
	}
	public function test_missing_changed_foreign_or_undeclared_calendar_refuses_before_any_winning_body(): void {
		$calendar = F::calendar(); $input = $this->single( [ 1, 1, 'calendar_days', $calendar->reference()->private_facts() ], calendars: [ $calendar ] );
		$this->reason( $input, 'missing_source' );
		foreach ( [ [ 'version' => 2 ], [ 'site_id' => 'foreign-site' ], [ 'timezone' => 'Africa/Accra' ], [ 'tzdata_version' => 'unverified' ] ] as $change ) { $this->reason( $input, 'source_changed', [ BusinessCalendarVersion::from_array( array_replace( $calendar->private_facts(), $change ) ) ] ); }
		$this->reason( F::input(), 'source_changed', [ $calendar ] );
	}
	public function test_matching_reference_with_unverified_calendar_runtime_label_still_refuses(): void {
		$calendar = F::calendar( [ 'tzdata_version' => 'merchant-label' ] ); $input = $this->single( [ 1, 1, 'calendar_days', $calendar->reference()->private_facts() ], calendars: [ $calendar ] ); $this->reason( $input, 'source_changed', [ $calendar ] );
	}
	public function test_equal_duplicate_calendar_records_are_deduplicated_and_input_permutation_is_stable(): void {
		$a = F::calendar(); $b = F::calendar( [ 'calendar_id' => 'second' ] ); $input = $this->single( [ 1, 2, 'calendar_days', $a->reference()->private_facts() ], calendars: [ $a, $b ] ); $calc = $this->calculator();
		self::assertSame( $calc->calculate( $input, [ $a, $b ] )->digest(), $calc->calculate( $input, [ $b, $a, $a ] )->digest() );
	}
	#[DataProvider( 'cutoff_equality_cases' )]
	public function test_cutoff_equality_and_after_refuse_and_before_admits( string $kind, string $time, bool $wins ): void {
		$calendar = F::calendar(); $cutoff = [ 'kind' => $kind, 'calendar' => $calendar->reference()->private_facts(), 'missed_window_rule' => 'reject' ] + ( 'local_time' === $kind ? [ 'time' => '16:00' ] : [ 'minutes_before_close' => 120 ] );
		$input = $this->single( [ 0, 0 ], [ 'cutoff' => $cutoff ], [ $calendar ], '2026-10-09 ' . $time . '.000000' ); $result = $this->calculator()->calculate( $input, [ $calendar ] );
		self::assertSame( $wins ? 'absolute_window' : 'ineligible', $result->state() );
	}
	public static function cutoff_equality_cases(): array { $cases = []; foreach ( [ 'local_time', 'before_close' ] as $kind ) { foreach ( [ [ '15:59:59', true ], [ '16:00:00', false ], [ '16:00:01', false ] ] as $case ) { $cases[] = [ $kind, ...$case ]; } } return $cases; }
	public function test_before_close_uses_selected_split_interval_not_last_daily_close(): void {
		$weekly = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $weekly[$day] = [ [ 'open' => '09:00', 'close' => '12:00' ], [ 'open' => '14:00', 'close' => '18:00' ] ]; } $calendar = F::calendar( [ 'weekly_openings' => $weekly ] );
		$cutoff = [ 'kind' => 'before_close', 'calendar' => $calendar->reference()->private_facts(), 'minutes_before_close' => 60, 'missed_window_rule' => 'reject' ]; $this->reason( $this->single( [ 0, 0 ], [ 'cutoff' => $cutoff ], [ $calendar ], '2026-10-09 11:00:00.000000' ), 'outside_service_window', [ $calendar ] );
	}
	public function test_explicit_next_opening_after_friday_cutoff_plus_business_day_completes_tuesday(): void {
		$weekly = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $weekly[$day] = in_array( $day, [ 'sat', 'sun' ], true ) ? [] : [ [ 'open' => '09:00', 'close' => '18:00' ] ]; } $calendar = F::calendar( [ 'weekly_openings' => $weekly ] ); $ref = $calendar->reference()->private_facts();
		$cutoff = [ 'kind' => 'local_time', 'calendar' => $ref, 'time' => '16:00', 'missed_window_rule' => 'next_opening' ]; $result = $this->calculator()->calculate( $this->single( [ 1, 1, 'business_days', $ref ], [ 'cutoff' => $cutoff ], [ $calendar ], '2026-10-09 16:00:00.000000' ), [ $calendar ] );
		self::assertSame( 'absolute_window', $result->state() ); self::assertSame( '2026-10-13 09:00:00.000000', $result->private_facts()['body']['terminal_windows'][0]['until'] );
	}
	public function test_cutoff_acceptance_refinement_creates_new_input_without_retiming_original_or_expiry(): void {
		$calendar = F::calendar(); $cutoff = [ 'kind' => 'before_close', 'calendar' => $calendar->reference()->private_facts(), 'minutes_before_close' => 120, 'missed_window_rule' => 'reject' ]; $input = $this->single( [ 1, 2 ], [ 'anchor' => 'order_accepted', 'cutoff' => $cutoff ], [ $calendar ], '2026-10-09 15:59:00.000000' ); $bytes = $input->to_private_json();
		$result = $this->calculator()->calculate( $input, [ $calendar ] ); self::assertSame( 'absolute_window', $result->state() ); self::assertSame( '2026-10-09 16:00:00.000000', $result->input()->anchor()->accept_until()->sql() ); self::assertSame( '2026-10-09 16:04:00.000000', $result->input()->anchor()->quote_expires_at()->sql() ); self::assertSame( $bytes, $input->to_private_json() ); self::assertNotSame( $input->digest(), $result->input()->digest() );
	}
	public function test_express_upper_six_hours_cannot_be_clipped_to_closing_even_before_cutoff(): void {
		$calendar = F::calendar(); $ref = $calendar->reference()->private_facts(); $cutoff = [ 'kind' => 'before_close', 'calendar' => $ref, 'minutes_before_close' => 120, 'missed_window_rule' => 'reject' ]; $input = $this->single( [ 60, 360 ], [ 'cutoff' => $cutoff ], [ $calendar ], '2026-10-09 15:59:00.000000', [ 'operating_calendar' => $ref, 'completion_window_rule' => 'within_open_interval' ] ); $this->reason( $input, 'outside_service_window', [ $calendar ] );
	}
	public function test_hard_completion_feasibility_refines_full_acceptance_prefix_before_closing(): void {
		$calendar = F::calendar(); $ref = $calendar->reference()->private_facts(); $input = $this->single( [ 2, 2 ], [ 'anchor' => 'order_accepted' ], [ $calendar ], '2026-10-09 17:57:00.000000', [ 'operating_calendar' => $ref, 'completion_window_rule' => 'within_open_interval' ] ); $result = $this->calculator()->calculate( $input, [ $calendar ] );
		self::assertSame( 'absolute_window', $result->state() ); self::assertSame( '2026-10-09 17:58:00.000001', $result->input()->anchor()->accept_until()->sql() ); self::assertSame( '2026-10-09 18:00:00.000000', $result->private_facts()['body']['terminal_windows'][0]['until'] ); self::assertSame( '2026-10-09 18:02:00.000000', $input->anchor()->quote_expires_at()->sql() );
	}
	public function test_same_day_literal_range_and_midnight_cap_never_extend_to_day_two(): void {
		$policy = [ 'anchor' => 'order_accepted', 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'same_day', 'customer_label' => 'Same day' ], 'day_constraint' => 'same_day' ]; $input = $this->single( [ 0, 0 ], $policy, [], '2026-10-09 23:58:00.000000' ); $result = $this->calculator()->calculate( $input, [] );
		self::assertSame( 'absolute_window', $result->state() ); self::assertSame( '2026-10-10 00:00:00.000000', $result->input()->anchor()->accept_until()->sql() ); self::assertSame( '2026-10-09 23:59:59.999999', $result->private_facts()['body']['terminal_windows'][0]['until'] ); self::assertSame( '2026-10-10 00:03:00.000000', $result->input()->anchor()->quote_expires_at()->sql() );
		$this->reason( $this->single( [ 360, 1440 ], array_replace( $policy, [ 'anchor' => 'checkout_capture' ] ) ), 'outside_service_window' );
	}
	public function test_next_day_means_next_civil_date_and_not_generic_twenty_four_to_forty_eight_hours(): void {
		$policy = [ 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'next_day', 'customer_label' => 'Next day' ], 'day_constraint' => 'next_day' ];
		self::assertSame( 'absolute_window', $this->calculator()->calculate( $this->single( [ 1440, 1440 ], $policy ), [] )->state() ); $this->reason( $this->single( [ 1440, 2880 ], $policy ), 'outside_service_window' );
	}
	public function test_order_accepted_without_literal_day_constraint_still_caps_its_service_date_at_midnight(): void {
		$input = $this->single( [ 20, 20 ], [ 'anchor' => 'order_accepted' ], [], '2026-10-09 23:58:00.000000' ); $result = $this->calculator()->calculate( $input, [] ); self::assertSame( 'absolute_window', $result->state() ); self::assertSame( '2026-10-10 00:00:00.000000', $result->input()->anchor()->accept_until()->sql() ); self::assertSame( '2026-10-10 00:19:59.999999', $result->private_facts()['body']['terminal_windows'][0]['until'] ); self::assertSame( '2026-10-10 00:03:00.000000', $input->anchor()->quote_expires_at()->sql() );
	}
	#[DataProvider( 'capacity_refusals' )]
	public function test_required_capacity_unknown_unavailable_stale_refuse( string $state, string $reason ): void {
		$policy = F::policy( [ 'capacity_mode' => 'required', 'capacity_source' => F::source( 'capacity' ) ] ); $raw = F::input( $policy )->private_facts(); $raw['capacity']['state'] = $state;
		if ( 'capacity_stale' === $reason ) { $raw['capacity']['observed_at'] = '2026-10-09 09:59:00.000000'; $raw['capacity']['valid_until'] = F::EVALUATED; }
		$this->reason( PromiseInput::from_array( $raw ), $reason );
	}
	public static function capacity_refusals(): array { return [ [ 'unknown', 'capacity_unknown' ], [ 'unavailable', 'capacity_unavailable' ], [ 'available', 'capacity_stale' ] ]; }
	public function test_available_capacity_refines_acceptance_validity_but_does_not_reserve_or_change_window(): void {
		$policy = F::policy( [ 'anchor' => 'order_accepted', 'capacity_mode' => 'required', 'capacity_source' => F::source( 'capacity' ) ] ); $raw = F::input( $policy )->private_facts(); $raw['capacity']['valid_until'] = '2026-10-09 10:01:00.000000'; $input = PromiseInput::from_array( $raw ); $result = $this->calculator()->calculate( $input, [] );
		self::assertSame( 'absolute_window', $result->state() ); self::assertSame( '2026-10-09 10:01:00.000000', $result->input()->anchor()->accept_until()->sql() ); self::assertSame( $input->capacity()->digest(), $result->input()->capacity()->digest() ); self::assertArrayNotHasKey( 'reservation_id', $result->private_facts() );
	}
	public function test_observed_capacity_window_must_cover_full_endpoint_bounds(): void {
		$policy = F::policy( [ 'capacity_mode' => 'required', 'capacity_source' => F::source( 'capacity' ) ] ); $raw = F::input( $policy )->private_facts(); $raw['capacity']['window']['until'] = '2026-10-09 10:19:59.999999'; $this->reason( PromiseInput::from_array( $raw ), 'capacity_unavailable' );
	}
	public function test_payment_relative_known_zero_is_honest_and_no_absolute_event_is_fabricated(): void {
		$input = $this->single( [ 0, 0 ], [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] ); $result = $this->calculator()->calculate( $input, [] ); $body = $result->private_facts()['body']; self::assertSame( 'relative_window', $result->state() ); self::assertSame( 'woocommerce_payment_confirmed', $body['awaited_event'] ); self::assertTrue( $body['terminal_windows'][0]['known_zero'] ); self::assertArrayNotHasKey( 'from', $body['terminal_windows'][0] );
	}
	public function test_payment_relative_required_capacity_cannot_use_generic_current_availability(): void {
		$policy = F::policy( [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment', 'capacity_mode' => 'required', 'capacity_source' => F::source( 'capacity' ) ] ); $this->reason( F::input( $policy ), 'capacity_unknown' );
	}
	public function test_single_relative_calendar_rule_retains_exact_frozen_calendar_and_unit(): void {
		$calendar = F::calendar(); $input = $this->single( [ 1, 2, 'business_days', $calendar->reference()->private_facts() ], [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ], [ $calendar ] ); $result = $this->calculator()->calculate( $input, [ $calendar ] ); self::assertSame( 'relative_window', $result->state() ); self::assertSame( 'business_days', $result->private_facts()['body']['terminal_windows'][0]['unit'] ); self::assertSame( [ $calendar->reference()->private_facts() ], $result->private_facts()['body']['terminal_windows'][0]['calendar_refs'] );
	}
	public function test_relative_operating_waits_are_not_relabelled_as_elapsed_known_zero(): void {
		$calendar = F::calendar(); $input = $this->single( [ 0, 0 ], [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ], [ $calendar ], component: [ 'operating_calendar' => $calendar->reference()->private_facts() ] ); $this->reason( $input, 'unsupported_policy', [ $calendar ] );
	}
	public function test_mixed_relative_graph_cannot_be_flattened_to_one_terminal_scalar(): void {
		$calendar = F::calendar(); $prep = F::component( 'prep', [ 'role' => 'preparation', 'endpoint' => 'merchant-origin', 'endpoint_kind' => 'origin', 'duration' => [ 'format_version' => 1, 'min' => 1, 'max' => 1, 'unit' => 'business_days', 'calendar' => $calendar->reference()->private_facts() ] ] ); $delivery = F::component( changes: [ 'predecessors' => [ 'prep' ] ] ); $policy = F::policy( [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment', 'graph' => [ 'format_version' => 1, 'components' => [ $prep, $delivery ], 'terminal_component_ids' => [ 'delivery' ] ] ], [ $calendar ] ); $this->reason( F::input( $policy ), 'unsupported_policy', [ $calendar ] );
	}
	public function test_relative_elapsed_parallel_join_is_maximum_and_not_a_sum(): void {
		$a = F::component( 'a', [ 'role' => 'preparation', 'endpoint' => 'origin-a', 'endpoint_kind' => 'origin', 'duration' => [ 'format_version' => 1, 'min' => 3, 'max' => 4, 'unit' => 'elapsed_minutes', 'calendar' => null ] ] ); $b = F::component( 'b', [ 'role' => 'preparation', 'endpoint' => 'origin-b', 'endpoint_kind' => 'origin', 'duration' => [ 'format_version' => 1, 'min' => 5, 'max' => 7, 'unit' => 'elapsed_minutes', 'calendar' => null ] ] ); $policy = F::policy( [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment', 'graph' => [ 'format_version' => 1, 'components' => [ $a, $b, F::component( changes: [ 'predecessors' => [ 'a', 'b' ] ] ) ], 'terminal_component_ids' => [ 'delivery' ] ] ] ); $window = $this->calculator()->calculate( F::input( $policy ), [] )->private_facts()['body']['terminal_windows'][0]; self::assertSame( 15, $window['min'] ); self::assertSame( 27, $window['max'] );
	}
	public function test_exact_730_day_horizon_succeeds_and_plus_one_refuses_without_partial_dates(): void {
		self::assertSame( 'absolute_window', $this->calculator()->calculate( $this->single( [ 730 * 1440, 730 * 1440 ] ), [] )->state() ); $this->reason( $this->single( [ 731 * 1440, 731 * 1440 ] ), 'budget_exceeded' );
	}
	public function test_required_cart_refusal_removes_other_winning_groups_but_optional_refusal_does_not(): void {
		$bad = $this->single( [ 731 * 1440, 731 * 1440 ] ); $bad = $this->fixture_groups( 3, $bad )[2]; $good = F::input(); $results = $this->calculator()->calculate_cart( [ $good, $bad ], [] ); self::assertSame( [ 'unavailable', 'unavailable' ], array_map( static fn( $result ): string => $result->state(), $results ) );
		$optional = $bad->private_facts(); $optional['policy']['promise_required'] = false; $results = $this->calculator()->calculate_cart( [ $good, PromiseInput::from_array( $optional ) ], [] ); self::assertSame( 'absolute_window', $results[0]->state() ); self::assertSame( 'unavailable', $results[1]->state() );
	}
	public function test_cart_packet_overflow_returns_no_hidden_partial_winner(): void {
		$results = $this->calculator()->calculate_cart( $this->fixture_groups( 30, F::input() ), [] ); self::assertCount( 30, $results ); foreach ( $results as $result ) { self::assertSame( [ 'budget_exceeded' ], $result->private_facts()['reason_codes'] ); self::assertNull( $result->private_facts()['body'] ); }
	}
	public function test_cart_group_limit_is_bounded_before_work(): void { $this->expectException( \InvalidArgumentException::class ); $this->calculator()->calculate_cart( array_fill( 0, 201, F::input() ), [] ); }
	public function test_cart_calendar_work_consumes_one_shared_fixed_budget(): void {
		$weekly = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $weekly[$day] = [ [ 'open' => '09:00', 'close' => '09:01' ] ]; } $calendar = F::calendar( [ 'weekly_openings' => $weekly ] ); $input = $this->single( [ 700, 700, 'business_minutes', $calendar->reference()->private_facts() ], calendars: [ $calendar ] ); $calc = $this->calculator(); $results = $calc->calculate_cart( $this->fixture_groups( 200, $input ), [ $calendar ] );
		self::assertLessThanOrEqual( PromiseLimits::CART_STEPS, $calc->last_steps() ); self::assertGreaterThan( 90000, $calc->last_steps() ); foreach ( $results as $result ) { self::assertNotSame( 'absolute_window', $result->state() ); }
	}
	#[DataProvider( 'cart_inconsistencies' )]
	public function test_one_cart_requires_one_capture_owner_and_unique_group_identity( string $change ): void {
		$inputs = $this->fixture_groups( 2, F::input() ); $facts = $inputs[1]->private_facts();
		switch ( $change ) { case 'group': $facts['material']['group_id'] = 'group-0'; break; case 'owner': $facts['owner']['session_hash'] = str_repeat( 'd', 64 ); break; case 'runtime': $facts['runtime']['runtime_id'] = 'another-runtime'; break; case 'capture': $inputs[1] = F::input( evaluated: '2026-10-09 10:00:01.000000' ); $facts = $inputs[1]->private_facts(); $facts['material']['group_id'] = 'group-1'; break; }
		$inputs[1] = PromiseInput::from_array( $facts ); $calc = $this->calculator(); $results = $calc->calculate_cart( $inputs, [] ); foreach ( $results as $result ) { self::assertSame( [ 'source_changed' ], $result->private_facts()['reason_codes'] ); } self::assertSame( 0, $calc->last_steps() );
	}
	public static function cart_inconsistencies(): array { return [ [ 'group' ], [ 'owner' ], [ 'runtime' ], [ 'capture' ] ]; }
	public function test_never_eligible_cutoff_search_cannot_restart_the_730_day_horizon(): void {
		$calendar = F::calendar(); $cutoff = [ 'kind' => 'local_time', 'calendar' => $calendar->reference()->private_facts(), 'time' => '08:00', 'missed_window_rule' => 'next_opening' ]; $calc = $this->calculator(); $result = $calc->calculate( $this->single( [ 0, 0 ], [ 'cutoff' => $cutoff ], [ $calendar ] ), [ $calendar ] ); self::assertSame( [ 'budget_exceeded' ], $result->private_facts()['reason_codes'] ); self::assertLessThan( PromiseLimits::CART_STEPS, $calc->last_steps() );
	}
	public function test_relative_business_rule_cannot_infer_unbounded_future_openings_from_finite_dated_exceptions(): void {
		$weekly = array_fill_keys( BusinessCalendarVersion::WEEKDAYS, [] ); $calendar = F::calendar( [ 'weekly_openings' => $weekly, 'exception_openings' => [ [ 'date' => '2026-10-09', 'intervals' => [ [ 'open' => '09:00', 'close' => '18:00' ] ] ] ] ] ); $input = $this->single( [ 1, 1, 'business_days', $calendar->reference()->private_facts() ], [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ], [ $calendar ] ); $this->reason( $input, 'unsupported_policy', [ $calendar ] );
	}
	public function test_zero_business_day_relative_rule_does_not_claim_zero_wait_until_opening(): void {
		$calendar = F::calendar(); $input = $this->single( [ 0, 0, 'business_days', $calendar->reference()->private_facts() ], [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ], [ $calendar ] ); $this->reason( $input, 'unsupported_policy', [ $calendar ] );
	}
	public function test_optional_group_cannot_leave_partial_winners_after_shared_work_exhaustion(): void {
		$weekly = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $weekly[$day] = [ [ 'open' => '09:00', 'close' => '09:01' ] ]; } $calendar = F::calendar( [ 'weekly_openings' => $weekly ] ); $expensive = $this->single( [ 700, 700, 'business_minutes', $calendar->reference()->private_facts() ], [ 'promise_required' => false ], [ $calendar ] ); $inputs = $this->fixture_groups( 20, $expensive ); $simple = F::input()->private_facts(); $simple['material']['group_id'] = 'simple-group'; array_unshift( $inputs, PromiseInput::from_array( $simple ) ); $calc = $this->calculator(); $results = $calc->calculate_cart( $inputs, [ $calendar ] ); self::assertGreaterThan( 90000, $calc->last_steps() ); foreach ( $results as $result ) { self::assertSame( [ 'budget_exceeded' ], $result->private_facts()['reason_codes'] ); }
	}
}
