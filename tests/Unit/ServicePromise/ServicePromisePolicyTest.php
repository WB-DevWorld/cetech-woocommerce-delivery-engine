<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\PromiseLimits;
use CetechDeliveryEngine\Domain\ServicePromise\ServicePromisePolicy;
use PHPUnit\Framework\TestCase;

final class ServicePromisePolicyTest extends TestCase {
	public function test_immutable_policy_roundtrips_exact_references_and_known_zero(): void {
		$data = self::facts(); $policy = ServicePromisePolicy::from_array( $data ); $data['graph']['components'][0]['duration']['max'] = 10;
		self::assertSame( 0, $policy->graph()->private_facts()['components'][0]['duration']['max'] ); self::assertSame( $policy->digest(), $policy->reference()->content_digest() );
		self::assertSame( $policy->to_private_json(), ServicePromisePolicy::from_json( $policy->to_private_json() )->to_private_json() ); self::assertSame( 'site-1', $policy->site_id() ); self::assertSame( 'order_accepted', $policy->anchor() );
		self::assertSame( 'doorstep', $policy->endpoint() ); self::assertSame( 'doorstep', $policy->endpoint_kind() ); self::assertSame( [ 'delivery' ], $policy->endpoint_terminal_component_ids() ); self::assertSame( 'Africa/Accra', $policy->promise_timezone() );
		self::assertSame( '2026-10-09 08:00:00.000000', $policy->effective_from()->sql() ); self::assertSame( '2026-10-10 08:00:00.000000', $policy->effective_until()->sql() );
		self::assertSame( 'standard', $policy->service()->private_facts()['code'] ); self::assertSame( 'none', $policy->capacity_mode() ); self::assertNull( $policy->capacity_source() ); self::assertSame( [], $policy->calendars() );
		$copy = $policy->private_facts(); $copy['service']['customer_label'] = 'Changed'; self::assertSame( 'Standard', $policy->private_facts()['service']['customer_label'] );
		self::assertNotSame( $policy->digest(), ServicePromisePolicy::from_array( $data )->digest() );
	}
	public function test_exact_declared_calendar_union_supports_duration_opening_and_both_cutoffs(): void {
		$data = self::facts(); $calendar = self::calendar(); $data['calendars'] = [ $calendar, $calendar ];
		$data['graph']['components'][0]['duration'] = [ 'format_version' => 1, 'min' => 0, 'max' => 1, 'unit' => 'business_days', 'calendar' => $calendar ];
		$data['graph']['components'][0]['operating_calendar'] = $calendar; $data['graph']['components'][0]['completion_window_rule'] = 'within_open_interval';
		$data['cutoff'] = [ 'kind' => 'local_time', 'calendar' => $calendar, 'time' => '16:00', 'missed_window_rule' => 'next_opening' ]; $policy = ServicePromisePolicy::from_array( $data );
		self::assertCount( 1, $policy->calendars() ); self::assertSame( $calendar['digest'], $policy->calendars()[0]->content_digest() );
		$data['calendars'] = [ $calendar ]; self::assertSame( $policy->digest(), ServicePromisePolicy::from_array( $data )->digest() );
		$data['cutoff'] = [ 'kind' => 'before_close', 'calendar' => $calendar, 'minutes_before_close' => 120, 'missed_window_rule' => 'reject' ];
		$relative = ServicePromisePolicy::from_array( $data ); self::assertSame( 120, $relative->private_facts()['cutoff']['minutes_before_close'] ); self::assertNotSame( $policy->digest(), $relative->digest() );
		self::assertSame( $relative->digest(), ServicePromisePolicy::from_json( $relative->to_private_json() )->digest() );
	}
	public function test_independent_port_and_doorstep_sinks_remain_distinct(): void {
		$data = self::facts(); $port = $data['graph']['components'][0]; $port['component_id'] = 'port-arrival'; $port['endpoint'] = 'accra-port'; $port['endpoint_kind'] = 'port'; $port['role'] = 'transit';
		$data['graph']['components'][] = $port; $data['graph']['terminal_component_ids'][] = 'port-arrival'; $policy = ServicePromisePolicy::from_array( $data );
		self::assertSame( [ 'accra-port', 'doorstep' ], $policy->graph()->terminal_endpoints() ); self::assertSame( [ 'delivery' ], $policy->endpoint_terminal_component_ids() );
		$data['graph']['components'] = array_reverse( $data['graph']['components'] ); $data['graph']['terminal_component_ids'] = array_reverse( $data['graph']['terminal_component_ids'] ); self::assertSame( $policy->digest(), ServicePromisePolicy::from_array( $data )->digest() );
		$data['endpoint'] = 'accra-port'; $data['endpoint_kind'] = 'port'; $data['endpoint_terminal_component_ids'] = [ 'port-arrival' ]; $port_policy = ServicePromisePolicy::from_array( $data ); self::assertSame( 'port', $port_policy->endpoint_kind() ); self::assertNotSame( $policy->digest(), $port_policy->digest() );
	}
	public function test_explicit_capacity_source_and_open_effective_end_are_retained_not_authorized(): void {
		$data = self::facts(); $data['effective_until'] = null; $data['capacity_mode'] = 'required'; $data['capacity_source'] = self::source( 'capacity' ); $policy = ServicePromisePolicy::from_array( $data );
		self::assertNull( $policy->effective_until() ); self::assertSame( 'required', $policy->capacity_mode() ); self::assertSame( 'capacity', $policy->capacity_source()['source_id'] );
		$data['scope'] = [ 'kind' => 'variation', 'target_id' => 11 ]; self::assertNotSame( $policy->digest(), ServicePromisePolicy::from_array( $data )->digest() );
	}
	public function test_sixteen_calendar_references_fit_and_seventeenth_is_not_truncated(): void {
		$data = self::facts();
		for ( $i = 0; $i < PromiseLimits::CALENDARS; ++$i ) { $calendar = self::calendar(); $calendar['calendar_id'] = 'calendar-' . $i; $data['calendars'][] = $calendar; }
		$policy = ServicePromisePolicy::from_array( $data ); self::assertCount( 16, $policy->calendars() ); self::assertLessThanOrEqual( PromiseLimits::RECORD_BYTES, strlen( $policy->to_private_json() ) );
		$calendar['calendar_id'] = 'calendar-plus-one'; $data['calendars'][] = $calendar;
		$this->expectException( \InvalidArgumentException::class ); ServicePromisePolicy::from_array( $data );
	}
	/** @dataProvider payment_services */
	public function test_payment_relative_standard_relaxed_and_custom_have_explicit_rule( string $kind, string $code ): void {
		$data = self::facts(); $data['service']['kind'] = $kind; $data['service']['code'] = $code; $data['anchor'] = 'payment_confirmed'; $data['late_payment_rule'] = 'relative_after_payment';
		self::assertSame( 'payment_confirmed', ServicePromisePolicy::from_array( $data )->anchor() );
	}
	public static function payment_services(): array { return [ [ 'built_in', 'standard' ], [ 'built_in', 'relaxed' ], [ 'merchant', 'custom-shipping' ] ]; }
	public function test_record_wire_budget_is_distinct_from_retained_quote_budget(): void {
		$policy = ServicePromisePolicy::from_array( self::facts() ); $wire = str_pad( $policy->to_private_json(), PromiseLimits::RECORD_BYTES, ' ' ); self::assertSame( $policy->digest(), ServicePromisePolicy::from_json( $wire )->digest() );
		$this->expectException( \InvalidArgumentException::class ); ServicePromisePolicy::from_json( $wire . ' ' );
	}
	/** @dataProvider invalid_policy */
	public function test_unsafe_policy_link_or_semantics_refuses( string $case ): void {
		$data = self::facts(); $calendar = self::calendar();
		switch ( $case ) {
			case 'unknown_format': $data['format_version'] = 2; break;
			case 'extra_field': $data['price'] = 10; break;
			case 'numeric_site': $data['site_id'] = 1; break;
			case 'string_version': $data['version'] = '1'; break;
			case 'equal_effective': $data['effective_until'] = $data['effective_from']; break;
			case 'invalid_instant': $data['effective_from'] = '2026-10-09T08:00:00Z'; break;
			case 'wrong_scope': $data['scope']['target_id'] = 1; break;
			case 'product_scope_missing_id': $data['scope']['kind'] = 'product'; break;
			case 'foreign_graph': $data['graph']['components'][0]['source']['site_id'] = 'other-site'; break;
			case 'unknown_endpoint': $data['endpoint'] = 'other-endpoint'; break;
			case 'false_doorstep': $data['graph']['components'][0]['endpoint_kind'] = 'port'; $data['graph']['components'][0]['role'] = 'transit'; break;
			case 'missing_final_mile': $data['graph']['components'][0]['role'] = 'transit'; break;
			case 'missing_terminal_selection': $data['endpoint_terminal_component_ids'] = []; break;
			case 'wrong_terminal_selection': $data['endpoint_terminal_component_ids'] = [ 'not-delivery' ]; break;
			case 'missing_known_zero': unset( $data['graph']['components'][0]['duration']['min'] ); break;
			case 'foreign_calendar': $calendar['site_id'] = 'other-site'; $data['calendars'] = [ $calendar ]; break;
			case 'conflicting_calendar': $data['calendars'] = [ $calendar, $calendar ]; $data['calendars'][1]['digest'] = str_repeat( 'b', 64 ); break;
			case 'missing_duration_calendar': $data['graph']['components'][0]['duration']['unit'] = 'calendar_days'; $data['graph']['components'][0]['duration']['calendar'] = $calendar; break;
			case 'missing_operating_calendar': $data['graph']['components'][0]['operating_calendar'] = $calendar; break;
			case 'wrong_cutoff_digest': $data['calendars'] = [ $calendar ]; $calendar['digest'] = str_repeat( 'b', 64 ); $data['cutoff'] = [ 'kind' => 'local_time', 'calendar' => $calendar, 'time' => '16:00', 'missed_window_rule' => 'reject' ]; break;
			case 'cutoff_24': $data['calendars'] = [ $calendar ]; $data['cutoff'] = [ 'kind' => 'local_time', 'calendar' => $calendar, 'time' => '24:00', 'missed_window_rule' => 'reject' ]; break;
			case 'cutoff_mixed': $data['calendars'] = [ $calendar ]; $data['cutoff'] = [ 'kind' => 'before_close', 'calendar' => $calendar, 'time' => '16:00', 'minutes_before_close' => 120, 'missed_window_rule' => 'reject' ]; break;
			case 'cutoff_zero': $data['calendars'] = [ $calendar ]; $data['cutoff'] = [ 'kind' => 'before_close', 'calendar' => $calendar, 'minutes_before_close' => 0, 'missed_window_rule' => 'reject' ]; break;
			case 'cutoff_overflow': $data['calendars'] = [ $calendar ]; $data['cutoff'] = [ 'kind' => 'before_close', 'calendar' => $calendar, 'minutes_before_close' => 1441, 'missed_window_rule' => 'reject' ]; break;
			case 'builtin_day_mismatch': $data['service']['code'] = 'same_day'; break;
			case 'builtin_standard_day': $data['day_constraint'] = 'same_day'; break;
			case 'payment_same_day': $data['service']['code'] = 'same_day'; $data['day_constraint'] = 'same_day'; $data['anchor'] = 'payment_confirmed'; $data['late_payment_rule'] = 'relative_after_payment'; break;
			case 'payment_express': $data['service']['code'] = 'express'; $data['anchor'] = 'payment_confirmed'; $data['late_payment_rule'] = 'relative_after_payment'; break;
			case 'absolute_relative_rule': $data['late_payment_rule'] = 'relative_after_payment'; break;
			case 'payment_absolute_rule': $data['anchor'] = 'payment_confirmed'; break;
			case 'capacity_none_source': $data['capacity_source'] = self::source( 'capacity' ); break;
			case 'capacity_required_missing': $data['capacity_mode'] = 'required'; break;
			case 'capacity_wrong_site': $data['capacity_mode'] = 'required'; $data['capacity_source'] = self::source( 'capacity' ); $data['capacity_source']['site_id'] = 'other-site'; break;
			case 'capacity_conflicting_source': $data['capacity_mode'] = 'required'; $data['capacity_source'] = self::source( 'configured-phase' ); $data['capacity_source']['digest'] = str_repeat( 'b', 64 ); break;
			case 'required_coercion': $data['promise_required'] = 1; break;
			case 'offset_zone': $data['promise_timezone'] = '+00:00'; break;
		}
		$this->expectException( \InvalidArgumentException::class ); ServicePromisePolicy::from_array( $data );
	}
	public static function invalid_policy(): array { $cases = [ 'unknown_format', 'extra_field', 'numeric_site', 'string_version', 'equal_effective', 'invalid_instant', 'wrong_scope', 'product_scope_missing_id', 'foreign_graph', 'unknown_endpoint', 'false_doorstep', 'missing_final_mile', 'missing_terminal_selection', 'wrong_terminal_selection', 'missing_known_zero', 'foreign_calendar', 'conflicting_calendar', 'missing_duration_calendar', 'missing_operating_calendar', 'wrong_cutoff_digest', 'cutoff_24', 'cutoff_mixed', 'cutoff_zero', 'cutoff_overflow', 'builtin_day_mismatch', 'builtin_standard_day', 'payment_same_day', 'payment_express', 'absolute_relative_rule', 'payment_absolute_rule', 'capacity_none_source', 'capacity_required_missing', 'capacity_wrong_site', 'capacity_conflicting_source', 'required_coercion', 'offset_zone' ]; return array_combine( $cases, array_map( static fn( string $case ): array => [ $case ], $cases ) ); }
	public function test_policy_cannot_implicitly_serialize_private_facts(): void { $this->expectException( \LogicException::class ); json_encode( ServicePromisePolicy::from_array( self::facts() ), JSON_THROW_ON_ERROR ); }
	public function test_policy_cannot_serialize_private_payload(): void { $this->expectException( \LogicException::class ); serialize( ServicePromisePolicy::from_array( self::facts() ) ); }
	public function test_unserialize_cannot_bypass_policy_factory(): void { $class = ServicePromisePolicy::class; $this->expectException( \LogicException::class ); unserialize( 'O:' . strlen( $class ) . ':"' . $class . '":0:{}' ); }
	private static function facts(): array {
		return [ 'format_version' => 1, 'site_id' => 'site-1', 'policy_id' => 'local-standard', 'version' => 1, 'effective_from' => '2026-10-09 08:00:00.000000', 'effective_until' => '2026-10-10 08:00:00.000000', 'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard' ], 'anchor' => 'order_accepted', 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep', 'endpoint_terminal_component_ids' => [ 'delivery' ], 'scope' => [ 'kind' => 'global', 'target_id' => null ], 'promise_timezone' => 'Africa/Accra', 'graph' => [ 'format_version' => 1, 'components' => [ [ 'format_version' => 1, 'component_id' => 'delivery', 'role' => 'final_mile', 'duration' => [ 'format_version' => 1, 'min' => 0, 'max' => 0, 'unit' => 'elapsed_minutes', 'calendar' => null ], 'operating_calendar' => null, 'completion_window_rule' => 'none', 'predecessors' => [], 'endpoint' => 'doorstep', 'endpoint_kind' => 'doorstep', 'source' => self::source( 'configured-phase' ) ] ], 'terminal_component_ids' => [ 'delivery' ] ], 'calendars' => [], 'cutoff' => null, 'day_constraint' => 'none', 'promise_required' => true, 'late_payment_rule' => 'refuse_if_infeasible', 'capacity_mode' => 'none', 'capacity_source' => null ];
	}
	private static function calendar(): array { return [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'picking', 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ]; }
	private static function source( string $id ): array { return [ 'format_version' => 1, 'site_id' => 'site-1', 'source_id' => $id, 'version' => 1, 'digest' => str_repeat( 'a', 64 ) ]; }
}
