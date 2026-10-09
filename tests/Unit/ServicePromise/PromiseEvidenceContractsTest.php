<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise;

use CetechDeliveryEngine\Domain\ServicePromise\{AcceptedPromiseReceipt, PromiseAnchor, PromiseCapacityObservation, PromiseInput, PromiseResult, PublicPromiseView, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Port\{PromiseCalculator, PromiseCalendarVersionLoader, PromiseCapacityObserver, PromisePolicyVersionLoader};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromiseEvidenceContractsTest extends TestCase {
	public static function hash( string $value ): string { return hash( 'sha256', $value ); }
	public static function source( string $id = 'carrier' ): array { return [ 'format_version' => 1, 'site_id' => 'site-1', 'source_id' => $id, 'version' => 1, 'digest' => self::hash( $id ) ]; }
	public static function anchor( string $kind = 'order_accepted' ): array {
		return [ 'format_version' => 1, 'kind' => $kind, 'evaluated_at' => '2026-10-09 10:00:00.000000', 'quote_expires_at' => '2026-10-09 10:05:00.000000' ] + match ( $kind ) { 'order_accepted' => [ 'accept_until' => '2026-10-09 10:04:00.000000' ], 'checkout_capture' => [ 'capture_at' => '2026-10-09 10:00:00.000000' ], 'payment_confirmed' => [ 'awaited_event' => 'woocommerce_payment_confirmed' ] };
	}
	public static function policy( string $anchor = 'order_accepted', bool $required_capacity = false ): array {
		return [ 'format_version' => 1, 'site_id' => 'site-1', 'policy_id' => 'service-policy', 'version' => 1, 'effective_from' => '2026-10-01 00:00:00.000000', 'effective_until' => null,
			'service' => [ 'format_version' => 1, 'kind' => 'built_in', 'code' => 'standard', 'customer_label' => 'Standard delivery' ], 'anchor' => $anchor, 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep', 'endpoint_terminal_component_ids' => [ 'delivery' ], 'scope' => [ 'kind' => 'global', 'target_id' => null ], 'promise_timezone' => 'Africa/Accra',
			'graph' => [ 'format_version' => 1, 'components' => [ [ 'format_version' => 1, 'component_id' => 'delivery', 'role' => 'final_mile', 'duration' => [ 'format_version' => 1, 'min' => 10, 'max' => 20, 'unit' => 'elapsed_minutes', 'calendar' => null ], 'operating_calendar' => null, 'completion_window_rule' => 'none', 'predecessors' => [], 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep', 'source' => self::source() ] ], 'terminal_component_ids' => [ 'delivery' ] ],
			'calendars' => [], 'cutoff' => null, 'day_constraint' => 'none', 'promise_required' => true, 'late_payment_rule' => 'payment_confirmed' === $anchor ? 'relative_after_payment' : 'refuse_if_infeasible', 'capacity_mode' => $required_capacity ? 'required' : 'none', 'capacity_source' => $required_capacity ? self::source( 'capacity-provider' ) : null ];
	}
	public static function input( string $anchor = 'order_accepted', bool $capacity = false ): array {
		$policy = self::policy( $anchor, $capacity ); $destination = [ 'endpoint' => 'customer-door', 'endpoint_kind' => 'doorstep', 'identity_digest' => self::hash( 'private-destination' ) ];
		$observation = [ 'format_version' => 1, 'mode' => 'none' ];
		if ( $capacity ) {
			$observation = [ 'format_version' => 1, 'mode' => 'required', 'site_id' => 'site-1', 'service_digest' => ServicePromisePolicy::from_array( $policy )->service()->digest(), 'endpoint_digest' => PromiseInput::endpoint_digest( $destination ), 'window' => [ 'from' => '2026-10-09 10:00:00.000000', 'until' => '2026-10-09 11:00:00.000000', 'display_timezone' => 'Africa/Accra' ], 'source' => self::source( 'capacity-provider' ), 'revision' => 1, 'state' => 'available', 'observed_at' => '2026-10-09 09:59:00.000000', 'valid_until' => '2026-10-09 10:05:00.000000' ];
		}
		return [ 'format_version' => 1, 'site_id' => 'site-1', 'evaluated_at' => '2026-10-09 10:00:00.000000', 'owner' => [ 'site_id' => 'site-1', 'kind' => 'customer', 'principal_hash' => self::hash( 'private-principal' ), 'session_hash' => self::hash( 'private-session' ), 'key_epoch' => 'epoch-1' ], 'material' => [ 'group_id' => 'group-1', 'material_digest' => self::hash( 'private-material' ) ], 'origin' => [ 'endpoint' => 'private-origin', 'endpoint_kind' => 'origin', 'identity_digest' => self::hash( 'private-origin' ) ], 'destination' => $destination, 'policy' => $policy, 'calendar_refs' => [], 'source_receipts' => $capacity ? [ self::source(), self::source( 'capacity-provider' ) ] : [ self::source() ], 'anchor' => self::anchor( $anchor ), 'service_day' => null, 'capacity' => $observation, 'runtime' => [ 'timezone_data_version' => '2026a', 'runtime_id' => 'captured-runtime', 'digest' => self::hash( 'runtime-tzdata' ) ] ];
	}
	public static function result_facts( string $state = 'absolute_window', ?array $input = null ): array {
		$input ??= self::input( 'relative_window' === $state ? 'payment_confirmed' : 'order_accepted' ); $typed = PromiseInput::from_array( $input );
		$body = match ( $state ) {
			'absolute_window' => [ 'display_timezone' => $typed->policy()->promise_timezone(), 'terminal_windows' => [ [ 'component_id' => 'delivery', 'from' => '2026-10-09 10:10:00.000000', 'until' => '2026-10-09 10:20:00.000000' ] ] ],
			'relative_window' => [ 'display_timezone' => $typed->policy()->promise_timezone(), 'awaited_event' => 'woocommerce_payment_confirmed', 'terminal_windows' => [ [ 'component_id' => 'delivery', 'min' => 10, 'max' => 20, 'unit' => 'elapsed_minutes', 'calendar_refs' => [], 'known_zero' => false ] ] ],
			default => null,
		};
		return [ 'format_version' => 1, 'state' => $state, 'input' => $typed->private_facts(), 'input_digest' => $typed->digest(), 'graph_digest' => $typed->policy()->graph()->digest(), 'body' => $body, 'reason_codes' => null === $body ? [ 'estimate_unavailable' ] : [] ];
	}
	public static function receipt( ?array $result = null ): array {
		$typed = PromiseResult::from_array( $result ?? self::result_facts() );
		return [ 'format_version' => 1, 'result' => $typed->private_facts(), 'result_digest' => $typed->digest(), 'quote' => [ 'site_id' => 'site-1', 'quote_id' => '12345678-1234-4abc-8abc-123456789abc', 'issued_at' => '2026-10-09 10:00:00.000000', 'expires_at' => '2026-10-09 10:05:00.000000', 'body_digest' => self::hash( 'quote-body' ) ], 'snapshot' => [ 'site_id' => 'site-1', 'order_id' => 10, 'group_id' => 'group-1', 'snapshot_digest' => self::hash( 'saved-snapshot' ) ], 'final_event' => null ];
	}
	public function test_captured_input_is_detached_canonical_and_digest_binds_private_clock_material_and_owner(): void {
		$raw = self::input(); $source = $raw['source_receipts'][0]; $raw['source_receipts'][] = $source; $raw['material']['group_id'] =& $group; $group = 'group-1';
		$typed = PromiseInput::from_array( $raw ); $group = 'changed'; self::assertSame( 'group-1', $typed->private_facts()['material']['group_id'] );
		self::assertSame( PromiseInput::from_array( self::input() )->digest(), $typed->digest() ); self::assertCount( 1, $typed->private_facts()['source_receipts'] );
		$changed = self::input(); $changed['owner']['session_hash'] = self::hash( 'other-session' ); self::assertNotSame( $typed->digest(), PromiseInput::from_array( $changed )->digest() );
		$changed = self::input(); $changed['material']['material_digest'] = self::hash( 'other-material' ); self::assertNotSame( $typed->digest(), PromiseInput::from_array( $changed )->digest() );
		self::assertSame( $typed->to_private_json(), PromiseInput::from_json( $typed->to_private_json() )->to_private_json() );
	}
	#[DataProvider( 'bad_input_cases' )]
	public function test_invalid_or_disconnected_input_facts_refuse( string $case ): void {
		$raw = self::input( capacity: true );
		switch ( $case ) {
			case 'version': $raw['format_version'] = 2; break;
			case 'extra': $raw['browser_clock'] = 'tomorrow'; break;
			case 'site_type': $raw['site_id'] = 1; break;
			case 'owner': $raw['owner']['site_id'] = 'another-site'; break;
			case 'evaluated': $raw['evaluated_at'] = '2026-10-09 10:00:01.000000'; break;
			case 'policy_inactive': $raw['policy']['effective_from'] = '2026-10-09 10:00:01.000000'; break;
			case 'policy_expired': $raw['policy']['effective_until'] = $raw['evaluated_at']; break;
			case 'policy_acceptance': $raw['policy']['effective_until'] = '2026-10-09 10:03:59.000000'; break;
			case 'missing_source': $raw['source_receipts'] = [ self::source() ]; break;
			case 'wrong_source': $raw['source_receipts'][0]['digest'] = self::hash( 'changed' ); break;
			case 'conflicting_source': $copy = $raw['source_receipts'][0]; $copy['version'] = 2; $raw['source_receipts'][] = $copy; break;
			case 'missing_capacity': unset( $raw['capacity'] ); break;
			case 'capacity_mode': $raw['capacity'] = [ 'format_version' => 1, 'mode' => 'none' ]; break;
			case 'capacity_service': $raw['capacity']['service_digest'] = self::hash( 'other-service' ); break;
			case 'capacity_endpoint': $raw['capacity']['endpoint_digest'] = $raw['destination']['identity_digest']; break;
			case 'capacity_source': $raw['capacity']['source']['digest'] = self::hash( 'wrong-provider' ); break;
			case 'capacity_future_capture': $raw['capacity']['observed_at'] = '2026-10-09 10:00:01.000000'; break;
			case 'destination_kind': $raw['destination']['endpoint_kind'] = 'port'; break;
			case 'destination': $raw['destination']['endpoint'] = 'another-endpoint'; break;
			case 'unknown_tzdata': $raw['runtime']['timezone_data_version'] = ''; break;
			case 'unknown_calendar': $raw['calendar_refs'] = [ [ 'format_version' => 1, 'site_id' => 'site-1', 'calendar_id' => 'undeclared', 'version' => 1, 'digest' => self::hash( 'calendar' ) ] ]; break;
		}
		$this->expectException( \InvalidArgumentException::class ); PromiseInput::from_array( $raw );
	}
	public static function bad_input_cases(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'version', 'extra', 'site_type', 'owner', 'evaluated', 'policy_inactive', 'policy_expired', 'policy_acceptance', 'missing_source', 'wrong_source', 'conflicting_source', 'missing_capacity', 'capacity_mode', 'capacity_service', 'capacity_endpoint', 'capacity_source', 'capacity_future_capture', 'destination_kind', 'destination', 'unknown_tzdata', 'unknown_calendar' ] ); }
	#[DataProvider( 'bad_anchor_cases' )]
	public function test_anchor_does_not_coerce_retime_or_fabricate_acceptance_and_payment( string $case ): void {
		$raw = self::anchor();
		switch ( $case ) {
			case 'confirm': $raw['kind'] = 'quote_confirmed'; break;
			case 'seal_field': $raw['actual_seal_at'] = $raw['evaluated_at']; break;
			case 'ttl_extension': $raw['quote_expires_at'] = '2026-10-09 10:05:00.000001'; break;
			case 'ttl_zero': $raw['quote_expires_at'] = $raw['evaluated_at']; break;
			case 'ttl_shortened': $raw['quote_expires_at'] = '2026-10-09 10:04:59.999999'; break;
			case 'acceptance_zero': $raw['accept_until'] = $raw['evaluated_at']; break;
			case 'acceptance_extension': $raw['accept_until'] = '2026-10-09 10:05:00.000001'; break;
			case 'payment_instant': $raw = self::anchor( 'payment_confirmed' ); $raw['capture_at'] = $raw['evaluated_at']; break;
			case 'payment_event': $raw = self::anchor( 'payment_confirmed' ); $raw['awaited_event'] = 'client_payment_success'; break;
			case 'capture_changed': $raw = self::anchor( 'checkout_capture' ); $raw['capture_at'] = '2026-10-09 10:00:01.000000'; break;
			case 'noncanonical_clock': $raw['evaluated_at'] = '2026-10-09T10:00:00Z'; break;
		}
		$this->expectException( \InvalidArgumentException::class ); PromiseAnchor::from_array( $raw );
	}
	public static function bad_anchor_cases(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'confirm', 'seal_field', 'ttl_extension', 'ttl_zero', 'ttl_shortened', 'acceptance_zero', 'acceptance_extension', 'payment_instant', 'payment_event', 'capture_changed', 'noncanonical_clock' ] ); }
	public function test_absolute_equal_bounds_allow_explicit_zero_or_deterministic_nonzero_component_facts(): void {
		foreach ( [ 0, 5 ] as $minutes ) {
			$input = self::input( 'checkout_capture' ); $input['policy']['graph']['components'][0]['duration']['min'] = $minutes; $input['policy']['graph']['components'][0]['duration']['max'] = $minutes;
			$raw = self::result_facts( input: $input ); $instant = 0 === $minutes ? '2026-10-09 10:00:00.000000' : '2026-10-09 10:05:00.000000'; $raw['body']['terminal_windows'][0]['from'] = $instant; $raw['body']['terminal_windows'][0]['until'] = $instant;
			$result = PromiseResult::from_array( $raw ); $view = PublicPromiseView::from_result( $result, 'delivery' );
			self::assertSame( $instant, $view->fields()['from'] ); self::assertSame( $instant, $view->fields()['until'] ); self::assertArrayNotHasKey( 'known_zero', $view->fields() );
			self::assertSame( $minutes, $result->input()->policy()->graph()->private_facts()['components'][0]['duration']['min'] );
		}
	}
	public function test_payment_relative_original_never_invents_absolute_dates_and_zero_is_not_unknown(): void {
		$input = self::input( 'payment_confirmed' ); $input['policy']['graph']['components'][0]['duration']['min'] = 0; $input['policy']['graph']['components'][0]['duration']['max'] = 0;
		$raw = self::result_facts( 'relative_window', $input ); $raw['body']['terminal_windows'][0]['min'] = 0; $raw['body']['terminal_windows'][0]['max'] = 0; $raw['body']['terminal_windows'][0]['known_zero'] = true;
		$result = PromiseResult::from_array( $raw ); $public = PublicPromiseView::from_result( $result, 'delivery' )->fields();
		self::assertSame( 'after_payment_confirmation', $public['relative_explanation'] ); self::assertTrue( $public['known_zero'] ); self::assertArrayNotHasKey( 'from', $public ); self::assertArrayNotHasKey( 'until', $public );
		self::assertSame( $result->digest(), PromiseResult::from_json( $result->to_private_json() )->digest() );
	}
	#[DataProvider( 'bad_result_cases' )]
	public function test_result_state_and_evidence_cannot_be_crossed_or_partially_won( string $case ): void {
		$raw = self::result_facts();
		switch ( $case ) {
			case 'version': $raw['format_version'] = 2; break;
			case 'state': $raw['state'] = 'reserved'; break;
			case 'input_digest': $raw['input_digest'] = self::hash( 'wrong' ); break;
			case 'graph_digest': $raw['graph_digest'] = self::hash( 'wrong' ); break;
			case 'reversed': $raw['body']['terminal_windows'][0]['from'] = '2026-10-09 10:21:00.000000'; break;
			case 'fake_relative': $raw['state'] = 'relative_window'; break;
			case 'absolute_payment': $raw = self::result_facts( 'relative_window' ); $raw['state'] = 'absolute_window'; break;
			case 'missing_sink': $raw['body']['terminal_windows'] = []; break;
			case 'duplicate_sink': $raw['body']['terminal_windows'][] = $raw['body']['terminal_windows'][0]; break;
			case 'wrong_sink': $raw['body']['terminal_windows'][0]['component_id'] = 'port'; break;
			case 'wrong_zone': $raw['body']['display_timezone'] = 'Europe/London'; break;
			case 'private_extra': $raw['body']['private_cost'] = 100; break;
			case 'raw_reason': $raw['reason_codes'] = [ 'Supplier warehouse private address' ]; break;
			case 'refusal_date': $raw['state'] = 'unavailable'; $raw['reason_codes'] = [ 'estimate_unavailable' ]; break;
			case 'missing_reason': $raw = self::result_facts( 'ineligible' ); $raw['reason_codes'] = []; break;
			case 'relative_zero_lie': $raw = self::result_facts( 'relative_window' ); $raw['body']['terminal_windows'][0]['known_zero'] = true; break;
			case 'relative_unknown': $raw = self::result_facts( 'relative_window' ); $raw['body']['terminal_windows'][0]['min'] = null; break;
			case 'relative_required_capacity': $raw = self::result_facts( 'relative_window', self::input( 'payment_confirmed', true ) ); break;
		}
		$this->expectException( \InvalidArgumentException::class ); PromiseResult::from_array( $raw );
	}
	public static function bad_result_cases(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'version', 'state', 'input_digest', 'graph_digest', 'reversed', 'fake_relative', 'absolute_payment', 'missing_sink', 'duplicate_sink', 'wrong_sink', 'wrong_zone', 'private_extra', 'raw_reason', 'refusal_date', 'missing_reason', 'relative_zero_lie', 'relative_unknown', 'relative_required_capacity' ] ); }
	#[DataProvider( 'bad_capacity_winners' )]
	public function test_required_capacity_unknown_stale_or_wrong_window_can_only_support_refusal( string $case ): void {
		$input = self::input( capacity: true );
		if ( 'unknown' === $case || 'unavailable' === $case ) { $input['capacity']['state'] = $case; }
		elseif ( 'stale' === $case ) { $input['capacity']['valid_until'] = $input['evaluated_at']; }
		elseif ( 'acceptance_validity' === $case ) { $input['capacity']['valid_until'] = '2026-10-09 10:03:59.999999'; }
		else { $input['capacity']['window']['until'] = '2026-10-09 10:19:59.999999'; }
		$refusal = PromiseResult::from_array( self::result_facts( 'ineligible', $input ) ); self::assertSame( 'ineligible', $refusal->state() );
		$this->expectException( \InvalidArgumentException::class ); PromiseResult::from_array( self::result_facts( input: $input ) );
	}
	public static function bad_capacity_winners(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'unknown', 'unavailable', 'stale', 'acceptance_validity', 'window' ] ); }
	public function test_capacity_is_window_source_kind_revision_bound_without_a_reservation_claim(): void {
		$raw = self::input( capacity: true ); $first = PromiseResult::from_array( self::result_facts( input: $raw ) );
		$raw['capacity']['revision'] = 2; $second = PromiseResult::from_array( self::result_facts( input: $raw ) ); self::assertNotSame( $first->digest(), $second->digest() );
		self::assertNotSame( PromiseInput::endpoint_digest( $raw['destination'] ), PromiseInput::endpoint_digest( [ 'endpoint' => 'customer-door', 'endpoint_kind' => 'port', 'identity_digest' => $raw['destination']['identity_digest'] ] ) );
		self::assertArrayNotHasKey( 'reserved', $first->private_facts() );
	}
	public function test_independent_endpoint_sinks_remain_explicit_and_public_projection_selects_only_policy_endpoint(): void {
		$input = self::input(); $component = $input['policy']['graph']['components'][0]; $component['component_id'] = 'port-arrival'; $component['endpoint'] = 'port'; $component['endpoint_kind'] = 'port'; $component['role'] = 'transit'; $input['policy']['graph']['components'][] = $component; $input['policy']['graph']['terminal_component_ids'][] = 'port-arrival';
		$raw = self::result_facts( input: $input ); $raw['body']['terminal_windows'][] = [ 'component_id' => 'port-arrival', 'from' => '2026-10-09 10:05:00.000000', 'until' => '2026-10-09 10:08:00.000000' ];
		$result = PromiseResult::from_array( $raw ); self::assertCount( 2, $result->private_facts()['body']['terminal_windows'] ); self::assertSame( '2026-10-09 10:20:00.000000', PublicPromiseView::from_result( $result, 'delivery' )->fields()['until'] );
		$this->expectException( \InvalidArgumentException::class ); PublicPromiseView::from_result( $result, 'port-arrival' );
	}
	public function test_accepted_linkage_preserves_original_result_and_records_separate_q06_event_without_native_authority(): void {
		$raw = self::receipt(); $unresolved = AcceptedPromiseReceipt::from_array( $raw ); self::assertSame( 'unresolved', $unresolved->event_link_status() );
		$raw['final_event'] = [ 'kind' => 'q06_placement_sealed', 'occurred_at' => '2026-10-09 10:03:59.999999', 'source_receipt_digest' => self::hash( 'acknowledged-native-seal' ), 'order_id' => 10, 'snapshot_digest' => $raw['snapshot']['snapshot_digest'] ];
		$linked = AcceptedPromiseReceipt::from_array( $raw ); self::assertSame( 'captured_q06_seal', $linked->event_link_status() ); self::assertSame( $unresolved->result()->digest(), $linked->result()->digest() ); self::assertSame( 'unresolved', $unresolved->event_link_status() );
		self::assertSame( $linked->digest(), AcceptedPromiseReceipt::from_json( $linked->to_private_json() )->digest() );
	}
	#[DataProvider( 'bad_receipts' )]
	public function test_acceptance_receipt_does_not_retime_lose_linkage_or_use_q05_confirm( string $case ): void {
		$raw = self::receipt();
		switch ( $case ) {
			case 'result_digest': $raw['result_digest'] = self::hash( 'wrong' ); break;
			case 'uuid': $raw['quote']['quote_id'] = '10'; break;
			case 'group': $raw['snapshot']['group_id'] = 'different-group'; break;
			case 'snapshot_site': $raw['snapshot']['site_id'] = 'different-site'; break;
			case 'quote_expiry': $raw['quote']['expires_at'] = '2026-10-09 10:05:00.000001'; break;
			case 'quote_ttl': $raw['quote']['issued_at'] = '2026-10-09 09:59:59.999999'; break;
			case 'quote_issued_later': $raw['quote']['issued_at'] = '2026-10-09 10:00:00.000001'; break;
			case 'refusal': $raw = self::receipt(); $result = PromiseResult::from_array( self::result_facts( 'unavailable' ) ); $raw['result'] = $result->private_facts(); $raw['result_digest'] = $result->digest(); break;
			default:
				$raw['final_event'] = [ 'kind' => 'q06_placement_sealed', 'occurred_at' => '2026-10-09 10:03:00.000000', 'source_receipt_digest' => self::hash( 'seal' ), 'order_id' => 10, 'snapshot_digest' => $raw['snapshot']['snapshot_digest'] ];
				if ( 'q05_confirm' === $case ) { $raw['final_event']['kind'] = 'quote_confirmed'; }
				elseif ( 'event_boundary' === $case ) { $raw['final_event']['occurred_at'] = '2026-10-09 10:04:00.000000'; }
				elseif ( 'event_order' === $case ) { $raw['final_event']['order_id'] = 11; }
				else { $raw['final_event']['snapshot_digest'] = self::hash( 'changed-snapshot' ); }
		}
		$this->expectException( \InvalidArgumentException::class ); AcceptedPromiseReceipt::from_array( $raw );
	}
	public static function bad_receipts(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'result_digest', 'uuid', 'group', 'snapshot_site', 'quote_expiry', 'quote_ttl', 'quote_issued_later', 'refusal', 'q05_confirm', 'event_boundary', 'event_order', 'event_snapshot' ] ); }
	public function test_public_projection_has_no_private_payload_and_refuses_extra_or_raw_reason_fields(): void {
		$result = PromiseResult::from_array( self::result_facts( input: self::input( capacity: true ) ) ); $view = PublicPromiseView::from_result( $result, 'delivery' ); $json = json_encode( $view, JSON_THROW_ON_ERROR );
		foreach ( [ 'site_id', 'owner', 'origin', 'identity_digest', 'policy', 'calendar', 'source', 'graph', 'group', 'material', 'revision', 'capacity', 'cost', 'supplier', 'quote_id', 'component_id' ] as $forbidden ) { self::assertStringNotContainsString( '"' . $forbidden . '"', $json ); }
		foreach ( [ self::hash( 'private-origin' ), self::hash( 'private-principal' ), self::hash( 'private-destination' ), self::hash( 'private-material' ) ] as $private ) { self::assertStringNotContainsString( $private, $json ); }
		$raw = $view->fields(); $raw['origin'] = 'private';
		try { PublicPromiseView::from_array( $raw ); self::fail( 'Public extra survived.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		$raw = PublicPromiseView::from_result( PromiseResult::from_array( self::result_facts( 'unavailable' ) ), 'delivery' )->fields(); $raw['reason_codes'] = [ 'private supplier refused' ];
		$this->expectException( \InvalidArgumentException::class ); PublicPromiseView::from_array( $raw );
	}
	public function test_private_carriers_refuse_implicit_serialization_and_factory_bypass(): void {
		foreach ( [ PromiseAnchor::from_array( self::anchor() ), PromiseCapacityObservation::from_array( self::input( capacity: true )['capacity'] ), PromiseInput::from_array( self::input() ), PromiseResult::from_array( self::result_facts() ), AcceptedPromiseReceipt::from_array( self::receipt() ) ] as $carrier ) {
			foreach ( [ static fn(): string => json_encode( $carrier, JSON_THROW_ON_ERROR ), static fn(): string => serialize( $carrier ) ] as $serialize ) {
				try { $serialize(); self::fail( 'Private carrier escaped.' ); } catch ( \LogicException $error ) { self::assertStringNotContainsString( 'private-origin', $error->getMessage() ); }
			}
			$class = $carrier::class; $forged = 'O:' . strlen( $class ) . ':"' . $class . '":0:{}';
			try { unserialize( $forged ); self::fail( 'Factory bypass survived.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		}
	}
	public function test_ports_are_unmounted_interfaces_with_no_implementation_or_effect(): void {
		foreach ( [ PromisePolicyVersionLoader::class, PromiseCalendarVersionLoader::class, PromiseCalculator::class, PromiseCapacityObserver::class ] as $port ) { self::assertTrue( ( new \ReflectionClass( $port ) )->isInterface() ); }
	}
	public function test_public_factory_cannot_be_bypassed_by_native_unserialization(): void {
		$class = PublicPromiseView::class; $forged = 'O:' . strlen( $class ) . ':"' . $class . '":0:{}';
		$this->expectException( \LogicException::class ); unserialize( $forged );
	}
	public function test_literal_day_caps_order_acceptance_at_midnight_without_sliding_quote_expiry(): void {
		$input = self::input(); $input['policy']['service']['code'] = 'next_day'; $input['policy']['day_constraint'] = 'next_day'; $input['evaluated_at'] = '2026-10-09 23:58:00.000000'; $input['anchor']['evaluated_at'] = $input['evaluated_at']; $input['anchor']['quote_expires_at'] = '2026-10-10 00:03:00.000000'; $input['anchor']['accept_until'] = '2026-10-10 00:00:00.000000'; $input['service_day'] = [ 'local_date' => '2026-10-09', 'timezone' => 'Africa/Accra', 'start_at' => '2026-10-09 00:00:00.000000', 'end_at' => '2026-10-10 00:00:00.000000' ];
		$raw = self::result_facts( input: $input ); $raw['body']['terminal_windows'][0]['from'] = '2026-10-10 10:00:00.000000'; $raw['body']['terminal_windows'][0]['until'] = '2026-10-10 12:00:00.000000'; $result = PromiseResult::from_array( $raw );
		self::assertSame( '2026-10-10 00:03:00.000000', $result->input()->anchor()->quote_expires_at()->sql() );
		$too_late = $input; $too_late['anchor']['accept_until'] = '2026-10-10 00:00:00.000001';
		try { PromiseInput::from_array( $too_late ); self::fail( 'Acceptance crossed captured day.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		$receipt = self::receipt( $raw ); $receipt['quote']['issued_at'] = $input['evaluated_at']; $receipt['quote']['expires_at'] = $input['anchor']['quote_expires_at']; $receipt['final_event'] = [ 'kind' => 'q06_placement_sealed', 'occurred_at' => '2026-10-10 00:00:00.000000', 'source_receipt_digest' => self::hash( 'seal' ), 'order_id' => 10, 'snapshot_digest' => $receipt['snapshot']['snapshot_digest'] ];
		$this->expectException( \InvalidArgumentException::class ); AcceptedPromiseReceipt::from_array( $receipt );
	}
	public function test_literal_next_day_never_normalizes_a_skipped_calendar_date_to_day_two(): void {
		$input = self::input(); $input['policy']['promise_timezone'] = 'Pacific/Apia'; $input['policy']['effective_from'] = '2011-12-01 00:00:00.000000'; $input['policy']['service']['code'] = 'next_day'; $input['policy']['day_constraint'] = 'next_day'; $input['evaluated_at'] = '2011-12-29 22:00:00.000000'; $input['anchor']['evaluated_at'] = $input['evaluated_at']; $input['anchor']['quote_expires_at'] = '2011-12-29 22:05:00.000000'; $input['anchor']['accept_until'] = '2011-12-29 22:04:00.000000'; $input['service_day'] = [ 'local_date' => '2011-12-29', 'timezone' => 'Pacific/Apia', 'start_at' => '2011-12-29 10:00:00.000000', 'end_at' => '2011-12-30 10:00:00.000000' ];
		$raw = self::result_facts( input: $input ); $raw['body']['terminal_windows'][0]['from'] = '2011-12-30 11:00:00.000000'; $raw['body']['terminal_windows'][0]['until'] = '2011-12-30 12:00:00.000000';
		$this->expectException( \InvalidArgumentException::class ); PromiseResult::from_array( $raw );
	}
	public function test_absolute_horizon_counts_local_dates_across_dst_and_refuses_local_plus_one(): void {
		$input = self::input( 'checkout_capture' ); $input['policy']['promise_timezone'] = 'America/New_York'; $input['evaluated_at'] = '2026-03-09 04:00:00.000000'; $input['anchor']['evaluated_at'] = $input['evaluated_at']; $input['anchor']['capture_at'] = $input['evaluated_at']; $input['anchor']['quote_expires_at'] = '2026-03-09 04:05:00.000000'; $input['policy']['effective_from'] = '2026-03-01 00:00:00.000000';
		$raw = self::result_facts( input: $input ); $local = new \DateTimeImmutable( '2026-03-09 00:00:00', new \DateTimeZone( 'America/New_York' ) ); $last_local = $local->modify( '+730 days' ); self::assertGreaterThan( 730 * 86400, $last_local->getTimestamp() - $local->getTimestamp() ); $last = $last_local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ); $raw['body']['terminal_windows'][0]['from'] = $last; $raw['body']['terminal_windows'][0]['until'] = $last;
		self::assertSame( 'absolute_window', PromiseResult::from_array( $raw )->state() );
		$next = $local->modify( '+731 days' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s.u' ); $raw['body']['terminal_windows'][0]['from'] = $next; $raw['body']['terminal_windows'][0]['until'] = $next;
		$this->expectException( \InvalidArgumentException::class ); PromiseResult::from_array( $raw );
	}
}
