<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Calculation;

use CetechDeliveryEngine\Application\ServicePromise\Calculation\{PromiseCalculationBudget, PromiseCalculationException, PromiseGraphTransfer};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{BusinessCalendarVersion, PromiseInput};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Calculation\CalculationFixture as Fixture;
use PHPUnit\Framework\TestCase;

final class PromiseGraphTransferTest extends TestCase {
	private static function duration( int $min, int $max, string $unit = 'elapsed_minutes', ?BusinessCalendarVersion $calendar = null ): array {
		return [ 'format_version' => 1, 'min' => $min, 'max' => $max, 'unit' => $unit, 'calendar' => $calendar?->reference()->private_facts() ];
	}
	private static function input( array $components, array $terminals, array $calendars = [], string $evaluated = Fixture::EVALUATED, string $anchor = 'checkout_capture' ): PromiseInput {
		$chosen = null; foreach ( $components as $component ) { if ( in_array( $component['component_id'], $terminals, true ) ) { $chosen = $component; break; } }
		$selected = []; foreach ( $components as $component ) { if ( in_array( $component['component_id'], $terminals, true ) && $component['endpoint'] === $chosen['endpoint'] && $component['endpoint_kind'] === $chosen['endpoint_kind'] ) { $selected[] = $component['component_id']; } }
		$policy = Fixture::policy( [ 'anchor' => $anchor, 'graph' => [ 'format_version' => 1, 'components' => $components, 'terminal_component_ids' => $terminals ], 'endpoint' => $chosen['endpoint'], 'endpoint_kind' => $chosen['endpoint_kind'], 'endpoint_terminal_component_ids' => $selected ], $calendars );
		return Fixture::input( $policy, $evaluated );
	}
	private static function transfer( PromiseInput $input, array $calendars = [], ?string $upper = null, ?PromiseCalculationBudget $budget = null ): array {
		$map = []; foreach ( $calendars as $calendar ) { $map[$calendar->reference()->calendar_id()] = $calendar; }
		return ( new PromiseGraphTransfer() )->transfer( $input, $map, $input->anchor()->evaluated_at(), RuleTime::parse( $upper ?? $input->anchor()->evaluated_at()->sql() ), $budget ?? new PromiseCalculationBudget() );
	}
	private static function weekdays( string $open = '09:00', string $close = '17:00' ): array {
		$weekly = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $weekly[$day] = in_array( $day, [ 'sat', 'sun' ], true ) ? [] : [ [ 'open' => $open, 'close' => $close ] ]; } return $weekly;
	}
	private function refusal( string $reason, callable $action ): void {
		try { $action(); self::fail( 'Expected complete graph refusal.' ); } catch ( PromiseCalculationException $failure ) { self::assertSame( $reason, $failure->reason() ); }
	}
	public function test_zero_is_known_and_a_nonzero_elapsed_range_is_computed_exactly(): void {
		foreach ( [ [ 0, 0, '10:00:00', '10:00:00' ], [ 10, 20, '10:10:00', '10:20:00' ] ] as [ $min, $max, $from, $until ] ) {
			$input = self::input( [ Fixture::component( changes: [ 'duration' => self::duration( $min, $max ) ] ) ], [ 'delivery' ] ); $result = self::transfer( $input );
			self::assertSame( [ [ 'component_id' => 'delivery', 'from' => '2026-10-09 ' . $from . '.000000', 'until' => '2026-10-09 ' . $until . '.000000' ] ], $result['terminal_windows'] );
			self::assertSame( Fixture::EVALUATED, $result['components'][0]['start_from']->sql() );
		}
	}
	public function test_sequential_processing_wait_business_transit_buffer_and_final_mile_are_not_a_label_sum(): void {
		$calendar = Fixture::calendar( [ 'calendar_id' => 'carrier', 'weekly_openings' => self::weekdays() ] );
		$components = [
			Fixture::component( 'prepare', [ 'role' => 'preparation', 'duration' => self::duration( 120, 120 ) ] ),
			Fixture::component( 'transit', [ 'role' => 'transit', 'predecessors' => [ 'prepare' ], 'duration' => self::duration( 120, 120, 'business_minutes', $calendar ), 'operating_calendar' => $calendar->reference()->private_facts() ] ),
			Fixture::component( 'buffer', [ 'role' => 'buffer', 'predecessors' => [ 'transit' ], 'duration' => self::duration( 20, 20 ) ] ),
			Fixture::component( 'delivery', [ 'predecessors' => [ 'buffer' ], 'duration' => self::duration( 10, 10 ) ] ),
		];
		// Intermediate phases do not assert a doorstep completion.
		foreach ( [ 0, 1, 2 ] as $index ) { $components[$index]['endpoint'] = 'carrier-handover'; $components[$index]['endpoint_kind'] = 'handover'; }
		$input = self::input( $components, [ 'delivery' ], [ $calendar ], '2026-10-09 15:00:00.000000' ); $result = self::transfer( $input, [ $calendar ] );
		self::assertSame( '2026-10-12 11:30:00.000000', $result['terminal_windows'][0]['from'] );
		self::assertSame( $result['terminal_windows'][0]['from'], $result['terminal_windows'][0]['until'] );
		$by_id = array_column( $result['components'], null, 'component_id' ); self::assertSame( '2026-10-12 09:00:00.000000', $by_id['transit']['start_from']->sql() );
	}
	public function test_parallel_required_branches_join_by_max_lower_and_max_upper(): void {
		$components = [ Fixture::component( 'first', [ 'duration' => self::duration( 30, 60 ) ] ), Fixture::component( 'second', [ 'duration' => self::duration( 60, 90 ) ] ), Fixture::component( 'delivery', [ 'predecessors' => [ 'first', 'second' ], 'duration' => self::duration( 10, 20 ) ] ) ];
		$result = self::transfer( self::input( $components, [ 'delivery' ] ) );
		self::assertSame( [ [ 'component_id' => 'delivery', 'from' => '2026-10-09 11:10:00.000000', 'until' => '2026-10-09 11:50:00.000000' ] ], $result['terminal_windows'] );
	}
	public function test_independent_endpoints_stay_separate_and_same_endpoint_sinks_have_a_private_max_summary(): void {
		$components = [ Fixture::component( 'delivery', [ 'duration' => self::duration( 10, 40 ) ] ), Fixture::component( 'other-delivery', [ 'duration' => self::duration( 30, 35 ) ] ), Fixture::component( 'port', [ 'role' => 'transit', 'endpoint' => 'destination-port', 'endpoint_kind' => 'port', 'duration' => self::duration( 5, 10 ) ] ) ];
		$result = self::transfer( self::input( $components, [ 'port', 'other-delivery', 'delivery' ] ) );
		self::assertCount( 3, $result['terminal_windows'] ); self::assertCount( 2, $result['endpoint_windows'] );
		$by_endpoint = array_column( $result['endpoint_windows'], null, 'endpoint' );
		self::assertSame( [ 'delivery', 'other-delivery' ], $by_endpoint['customer-door']['component_ids'] ); self::assertSame( '2026-10-09 10:30:00.000000', $by_endpoint['customer-door']['from'] ); self::assertSame( '2026-10-09 10:40:00.000000', $by_endpoint['customer-door']['until'] );
		self::assertSame( '2026-10-09 10:10:00.000000', $by_endpoint['destination-port']['until'] );
	}
	public function test_elapsed_duration_still_waits_for_its_independent_destination_timezone(): void {
		$calendar = Fixture::calendar( [ 'calendar_id' => 'tokyo', 'timezone' => 'Asia/Tokyo' ] );
		$input = self::input( [ Fixture::component( changes: [ 'operating_calendar' => $calendar->reference()->private_facts(), 'duration' => self::duration( 60, 60 ) ] ) ], [ 'delivery' ], [ $calendar ], '2026-10-09 23:00:00.000000' );
		$result = self::transfer( $input, [ $calendar ] ); self::assertSame( '2026-10-10 00:00:00.000000', $result['components'][0]['start_from']->sql() ); self::assertSame( '2026-10-10 01:00:00.000000', $result['terminal_windows'][0]['until'] );
	}
	public function test_express_upper_completion_cannot_be_clipped_or_moved_to_a_later_opening(): void {
		$calendar = Fixture::calendar(); $input = self::input( [ Fixture::component( changes: [ 'operating_calendar' => $calendar->reference()->private_facts(), 'completion_window_rule' => 'within_open_interval', 'duration' => self::duration( 10, 360 ) ] ) ], [ 'delivery' ], [ $calendar ], '2026-10-09 15:59:00.000000' );
		$this->refusal( 'outside_service_window', static fn(): array => self::transfer( $input, [ $calendar ] ) );
	}
	public function test_completion_at_the_admitted_interval_close_is_exactly_allowed(): void {
		$calendar = Fixture::calendar(); $input = self::input( [ Fixture::component( changes: [ 'operating_calendar' => $calendar->reference()->private_facts(), 'completion_window_rule' => 'within_open_interval', 'duration' => self::duration( 1, 1 ) ] ) ], [ 'delivery' ], [ $calendar ], '2026-10-09 17:59:00.000000' );
		self::assertSame( '2026-10-09 18:00:00.000000', self::transfer( $input, [ $calendar ] )['terminal_windows'][0]['until'] );
	}
	public function test_valid_ranged_endpoints_cannot_hide_an_infeasible_interior_before_closing(): void {
		$calendar = Fixture::calendar(); $input = self::input( [ Fixture::component( changes: [ 'operating_calendar' => $calendar->reference()->private_facts(), 'completion_window_rule' => 'within_open_interval', 'duration' => self::duration( 1, 1 ) ] ) ], [ 'delivery' ], [ $calendar ], '2026-10-09 17:58:00.000000', 'order_accepted' );
		$this->refusal( 'outside_service_window', static fn(): array => self::transfer( $input, [ $calendar ], '2026-10-09 18:02:00.000000' ) );
	}
	public function test_each_bound_has_its_own_operating_admission_and_business_minute_completion(): void {
		$calendar = Fixture::calendar( [ 'weekly_openings' => self::weekdays() ] );
		$components = [ Fixture::component( 'prepare', [ 'duration' => self::duration( 10, 20 ) ] ), Fixture::component( 'delivery', [ 'predecessors' => [ 'prepare' ], 'operating_calendar' => $calendar->reference()->private_facts(), 'duration' => self::duration( 10, 20, 'business_minutes', $calendar ) ] ) ];
		$result = self::transfer( self::input( $components, [ 'delivery' ], [ $calendar ], '2026-10-09 16:40:00.000000' ), [ $calendar ] );
		self::assertSame( '2026-10-09 17:00:00.000000', $result['terminal_windows'][0]['from'] ); self::assertSame( '2026-10-12 09:20:00.000000', $result['terminal_windows'][0]['until'] );
	}
	public function test_missing_extra_and_changed_exact_calendar_sources_refuse_before_a_partial_branch_result(): void {
		$calendar = Fixture::calendar(); $components = [ Fixture::component( 'early', [ 'duration' => self::duration( 0, 0 ) ] ), Fixture::component( 'delivery', [ 'duration' => self::duration( 1, 1, 'business_days', $calendar ) ] ) ]; $input = self::input( $components, [ 'delivery', 'early' ], [ $calendar ] );
		$this->refusal( 'missing_source', static fn(): array => self::transfer( $input ) );
		$this->refusal( 'source_changed', static fn(): array => self::transfer( $input, [ $calendar, Fixture::calendar( [ 'calendar_id' => 'extra' ] ) ] ) );
		$this->refusal( 'source_changed', static fn(): array => self::transfer( $input, [ Fixture::calendar( [ 'version' => 2 ] ) ] ) );
	}
	public function test_shared_budget_exhaustion_refuses_the_entire_graph_without_a_fresh_allowance(): void {
		$budget = new PromiseCalculationBudget(); $budget->consume( 100000 ); $input = Fixture::input();
		$this->refusal( 'budget_exceeded', static fn(): array => self::transfer( $input, budget: $budget ) ); self::assertSame( 100000, $budget->used_steps() );
	}
	public function test_cumulative_horizon_is_measured_from_capture_rather_than_reset_for_each_leg(): void {
		foreach ( [ 365, 366 ] as $days ) {
			$components = [ Fixture::component( 'first', [ 'duration' => self::duration( 365 * 1440, 365 * 1440 ) ] ), Fixture::component( 'delivery', [ 'predecessors' => [ 'first' ], 'duration' => self::duration( $days * 1440, $days * 1440 ) ] ) ]; $input = self::input( $components, [ 'delivery' ] );
			if ( 366 === $days ) { $this->refusal( 'budget_exceeded', static fn(): array => self::transfer( $input ) ); }
			else { self::assertSame( '2028-10-08 10:00:00.000000', self::transfer( $input )['terminal_windows'][0]['until'] ); }
		}
	}
	public function test_order_acceptance_elapsed_transfer_covers_both_ends_without_retiming_input(): void {
		$input = self::input( [ Fixture::component() ], [ 'delivery' ], anchor: 'order_accepted' ); $digest = $input->digest(); $result = self::transfer( $input, upper: '2026-10-09 10:04:59.999999' );
		self::assertSame( '2026-10-09 10:10:00.000000', $result['terminal_windows'][0]['from'] ); self::assertSame( '2026-10-09 10:24:59.999999', $result['terminal_windows'][0]['until'] ); self::assertSame( $digest, $input->digest() );
	}
	public function test_day_transfer_across_a_source_fold_is_conservatively_refused_instead_of_underbounding(): void {
		$calendar = Fixture::calendar( [ 'timezone' => 'America/New_York' ] ); $input = self::input( [ Fixture::component( changes: [ 'duration' => self::duration( 1, 1, 'calendar_days', $calendar ) ] ) ], [ 'delivery' ], [ $calendar ], '2026-11-01 05:59:00.000000', 'order_accepted' );
		$this->refusal( 'unsupported_policy', static fn(): array => self::transfer( $input, [ $calendar ], '2026-11-01 06:01:00.000000' ) );
	}
	public function test_target_gap_inside_a_day_transfer_range_is_refused_even_when_its_endpoints_exist(): void {
		$calendar = Fixture::calendar( [ 'timezone' => 'America/New_York' ] );
		$components = [ Fixture::component( 'prepare', [ 'duration' => self::duration( 0, 62 ) ] ), Fixture::component( 'delivery', [ 'predecessors' => [ 'prepare' ], 'duration' => self::duration( 1, 1, 'calendar_days', $calendar ) ] ) ];
		$input = self::input( $components, [ 'delivery' ], [ $calendar ], '2026-03-07 06:59:00.000000' );
		// Tomorrow's 01:59 and 03:01 exist; the intervening 02:00..02:59 civil times do not.
		$this->refusal( 'unsupported_policy', static fn(): array => self::transfer( $input, [ $calendar ] ) );
	}
	public function test_an_unresolved_interior_business_opening_cannot_hide_behind_valid_short_endpoint_transfers(): void {
		$weekly = []; foreach ( BusinessCalendarVersion::WEEKDAYS as $day ) { $weekly[$day] = [ [ 'open' => '09:00', 'close' => '18:00' ] ]; }
		$weekly['sun'] = [ [ 'open' => '01:30', 'close' => '02:30' ] ]; $calendar = Fixture::calendar( [ 'timezone' => 'America/New_York', 'weekly_openings' => $weekly ] );
		$components = [ Fixture::component( 'prepare', [ 'duration' => self::duration( 0, 3 * 1440 ) ] ), Fixture::component( 'delivery', [ 'predecessors' => [ 'prepare' ], 'duration' => self::duration( 1, 1, 'business_minutes', $calendar ) ] ) ];
		$input = self::input( $components, [ 'delivery' ], [ $calendar ], '2026-10-30 10:00:00.000000' );
		$this->refusal( 'estimate_unavailable', static fn(): array => self::transfer( $input, [ $calendar ] ) );
	}
	public function test_monotonic_business_day_acceptance_range_preserves_each_selected_wall_time(): void {
		$calendar = Fixture::calendar( [ 'weekly_openings' => self::weekdays() ] ); $input = self::input( [ Fixture::component( changes: [ 'duration' => self::duration( 1, 1, 'business_days', $calendar ) ] ) ], [ 'delivery' ], [ $calendar ], anchor: 'order_accepted' );
		$result = self::transfer( $input, [ $calendar ], '2026-10-09 10:04:59.999999' );
		self::assertSame( '2026-10-12 10:00:00.000000', $result['terminal_windows'][0]['from'] ); self::assertSame( '2026-10-12 10:04:59.999999', $result['terminal_windows'][0]['until'] );
	}
	public function test_graph_order_changes_do_not_change_the_complete_transfer(): void {
		$components = [ Fixture::component( 'z-first', [ 'duration' => self::duration( 30, 60 ) ] ), Fixture::component( 'a-first', [ 'duration' => self::duration( 60, 90 ) ] ), Fixture::component( 'delivery', [ 'predecessors' => [ 'z-first', 'a-first' ], 'duration' => self::duration( 10, 20 ) ] ) ];
		$first = self::transfer( self::input( $components, [ 'delivery' ] ) ); $second = self::transfer( self::input( array_reverse( $components ), [ 'delivery' ] ) );
		self::assertEquals( $first, $second ); self::assertSame( [ 'a-first', 'delivery', 'z-first' ], array_column( $first['components'], 'component_id' ) );
	}
	public function test_the_complete_sixteen_node_thirty_two_edge_graph_keeps_every_required_dependency(): void {
		$components = []; $extra_edges = 17;
		for ( $i = 0; $i < 16; ++$i ) {
			$id = 15 === $i ? 'delivery' : 'node-' . $i; $predecessors = 0 === $i ? [] : [ 'node-' . ( $i - 1 ) ];
			for ( $j = 0; $j < $i - 1 && $extra_edges > 0; ++$j, --$extra_edges ) { $predecessors[] = 'node-' . $j; }
			$components[] = Fixture::component( $id, [ 'predecessors' => $predecessors, 'duration' => self::duration( 1, 1 ) ] );
		}
		self::assertSame( 32, array_sum( array_map( static fn( array $component ): int => count( $component['predecessors'] ), $components ) ) );
		$budget = new PromiseCalculationBudget(); $result = self::transfer( self::input( $components, [ 'delivery' ] ), budget: $budget );
		self::assertCount( 16, $result['components'] ); self::assertSame( '2026-10-09 10:16:00.000000', $result['terminal_windows'][0]['until'] ); self::assertLessThan( 100000, $budget->used_steps() );
	}
	public function test_unknown_payment_event_never_becomes_a_fabricated_absolute_graph_anchor(): void {
		$policy = Fixture::policy( [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] ); $input = Fixture::input( $policy );
		$this->refusal( 'unsupported_anchor', static fn(): array => self::transfer( $input ) );
	}
}
