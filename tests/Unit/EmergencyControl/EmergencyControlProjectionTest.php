<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyAdmissionResult;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlProjection;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnership;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlDiagnostics;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlReadResult;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EmergencyControlProjectionTest extends TestCase {
	private function physical( int $site = 7, string $state = 'checkout_suspended', int $revision = 29 ): EmergencyControlReadResult {
		return EmergencyControlReadResult::ready( EmergencyControlState::from_physical( $site, 912345,
			EmergencyControlState::record_json( $site, $state, $revision, 'enabled' === $state ? 'resume_verified' : 'incident_pause', 812345, 1780000000 ) ) );
	}

	private function facts( ?EmergencyControlReadResult $read = null ): array {
		return [ 'site_id' => 7, 'control' => $read ?? $this->physical(), 'module_readiness' => 'ready',
			'activation_policy' => 'current_site_all_or_none', 'publication_pending' => true,
			'observed_lines' => 200, 'observed_packages' => 9, 'observed_complete' => false ];
	}

	private function decision( string $code, ?int $revision = 29 ): EmergencyAdmissionResult {
		return new EmergencyAdmissionResult( in_array( $code, [ 'allowed', 'unmanaged', 'already_paid' ], true ), $code, EmergencyOwnership::Managed, $revision );
	}

	private function assert_private_absent( array $projection ): void {
		$json = json_encode( $projection, JSON_THROW_ON_ERROR );
		foreach ( [ 'actor', 'operator', 'incident_pause', 'reason_code', 'row_id', '912345', '812345', '1780000000',
			'original_bytes', 'idempotency', 'request_token', 'completion', 'product_id', 'variation_id', 'supplier', 'address', 'margin', 'rate_card', 'cost' ] as $private ) {
			self::assertStringNotContainsString( $private, $json );
		}
	}

	public function test_shopper_maps_each_finite_refusal_to_fixed_copy_without_private_state(): void {
		$request = RequestContext::create( 'private-address:{"supplier_id":17}' );
		foreach ( [ 'checkout_suspended' => 'paused', 'control_unavailable' => 'unavailable', 'stale_control_revision' => 'revalidation_required',
			'checkout_revalidation_required' => 'revalidation_required', 'unsupported_activation_policy' => 'unavailable' ] as $code => $category ) {
			$output = EmergencyControlProjection::for_shopper( $this->decision( $code ), $request );
			self::assertSame( [ 'contract_version', 'decision_kind', 'status', 'message_key', 'message', 'recovery_action', 'correlation_id' ], array_keys( $output ) );
			self::assertSame( 'site_control', $output['decision_kind'] );
			self::assertSame( 'refused', $output['status'] );
			self::assertSame( 'cetech.checkout.' . $category, $output['message_key'] );
			self::assertSame( $request->correlation_id, $output['correlation_id'] );
			self::assertTrue( RequestContext::is_valid_identifier( $output['correlation_id'] ) );
			self::assertArrayNotHasKey( 'revision', $output );
			$this->assert_private_absent( $output );
		}
	}

	public function test_allowed_unmanaged_and_paid_continuations_do_not_invent_pause_or_customer_actions(): void {
		foreach ( [ 'allowed', 'unmanaged', 'already_paid' ] as $code ) {
			$output = EmergencyControlProjection::for_shopper( $this->decision( $code, null ), RequestContext::create() );
			self::assertSame( 'accepted', $output['status'] );
			self::assertNull( $output['message'] );
			self::assertNull( $output['message_key'] );
			self::assertNull( $output['recovery_action'] );
		}
	}

	public function test_safe_logs_preserve_only_finite_decision_facts_not_control_serialization(): void {
		$log = EmergencyControlProjection::for_diagnostic_log( $this->decision( 'checkout_suspended' ) );
		self::assertSame( [ 'operation', 'reason_code', 'outcome', 'control_revision', 'complete' ], array_keys( $log ) );
		self::assertSame( [ 'operation' => 'checkout.admission', 'reason_code' => 'checkout_suspended', 'outcome' => 'refused', 'control_revision' => 29, 'complete' => true ], $log );
		foreach ( [ 'control_unavailable', 'unsupported_activation_policy' ] as $code ) {
			$log = EmergencyControlProjection::for_diagnostic_log( $this->decision( $code, null ) );
			self::assertSame( 'unavailable', $log['outcome'] );
			self::assertFalse( $log['complete'] );
			self::assertNull( $log['control_revision'] );
		}
	}

	public function test_settings_discloses_only_the_current_safe_view_without_hidden_envelope(): void {
		$capabilities = [];
		$read = $this->physical();
		$output = EmergencyControlProjection::for_settings( 7, RequestContext::create(),
			static function ( int $site, string $capability, string $purpose ) use ( &$capabilities ): bool { $capabilities[] = [ $site, $capability, $purpose ]; return true; },
			static fn( int $site ) => $read );
		self::assertSame( [ [ 7, 'manage_delivery_settings', 'settings' ], [ 7, 'manage_delivery_settings', 'settings' ] ], $capabilities );
		self::assertSame( [ 'contract_version', 'decision_kind', 'site_id', 'ready', 'state', 'revision', 'initialized', 'message_key', 'message', 'correlation_id' ], array_keys( $output ) );
		self::assertSame( 'checkout_suspended', $output['state'] );
		self::assertSame( 29, $output['revision'] );
		self::assertTrue( $output['initialized'] );
		$this->assert_private_absent( $output );
	}

	public function test_virtual_checked_absence_does_not_fabricate_a_physical_record_or_actor(): void {
		$read = EmergencyControlReadResult::ready( EmergencyControlState::absent( 7 ) );
		$settings = EmergencyControlProjection::for_settings( 7, RequestContext::create(), static fn() => true, static fn() => $read );
		self::assertSame( 'enabled', $settings['state'] );
		self::assertSame( 1, $settings['revision'] );
		self::assertFalse( $settings['initialized'] );
		$diagnostics = new EmergencyControlDiagnostics( 7, $read );
		$output = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, static fn() => $diagnostics );
		self::assertNull( $output['stored_state'] );
		self::assertSame( 'enabled', $output['current_control_state'] );
		self::assertSame( 'unavailable', $output['effective_state'] );
		self::assertSame( 'unknown', $output['module_readiness'] );
		$this->assert_private_absent( $output );
	}

	public function test_unavailable_read_has_unknown_state_revision_and_initialization(): void {
		$read = EmergencyControlReadResult::unavailable( 'outcome_unknown', RequestContext::create() );
		$output = EmergencyControlProjection::for_settings( 7, RequestContext::create(), static fn() => true, static fn() => $read );
		self::assertFalse( $output['ready'] );
		self::assertNull( $output['state'] );
		self::assertNull( $output['revision'] );
		self::assertNull( $output['initialized'] );
		self::assertSame( 'cetech.checkout.unavailable', $output['message_key'] );
	}

	public function test_settings_and_diagnostics_denial_precede_all_loading_including_truthy_nonboolean_grants(): void {
		$loads = 0;
		$load = static function () use ( &$loads ) { ++$loads; throw new \RuntimeException( 'address-private' ); };
		foreach ( [ false, 1, 'true', null ] as $grant ) {
			foreach ( [ 'for_settings', 'for_diagnostics' ] as $method ) {
				$error = EmergencyControlProjection::$method( 7, RequestContext::create(), static fn() => $grant, $load );
				self::assertInstanceOf( ContractError::class, $error );
				self::assertSame( 'not_authorized', $error->code );
			}
		}
		self::assertSame( 0, $loads );
	}

	public function test_revoked_authority_during_each_load_denies_even_valid_private_results(): void {
		foreach ( [ 'for_settings', 'for_diagnostics' ] as $method ) {
			$authorized = true;
			$read = 'for_settings' === $method ? $this->physical() : EmergencyControlDiagnostics::from_array( $this->facts() );
			$error = EmergencyControlProjection::$method( 7, RequestContext::create(), static function () use ( &$authorized ): bool { return $authorized; },
				static function () use ( &$authorized, $read ) { $authorized = false; return $read; } );
			self::assertInstanceOf( ContractError::class, $error );
			self::assertSame( 'not_authorized', $error->code );
		}
	}

	public function test_current_site_mismatch_and_service_denial_never_disclose_a_loaded_control(): void {
		foreach ( [ $this->physical( 8 ), EmergencyControlReadResult::unavailable( 'not_authorized', RequestContext::create() ) ] as $read ) {
			$error = EmergencyControlProjection::for_settings( 7, RequestContext::create(), static fn() => true, static fn() => $read );
			self::assertInstanceOf( ContractError::class, $error );
			self::assertSame( 'not_authorized', $error->code );
		}
		$facts = new EmergencyControlDiagnostics( 8, $this->physical( 8 ) );
		$error = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, static fn() => $facts );
		self::assertSame( 'not_authorized', $error->code );
	}

	public function test_throwing_authorizer_and_raw_loader_exception_have_only_fixed_safe_errors(): void {
		$request = RequestContext::create();
		$error = EmergencyControlProjection::for_settings( 7, $request, static function () { throw new \RuntimeException( 'SELECT private_actor FROM address' ); }, static fn() => null );
		self::assertSame( 'not_authorized', $error->code );
		$error = EmergencyControlProjection::for_settings( 7, $request, static fn() => true, static function () { throw new \RuntimeException( 'SELECT private_actor FROM address' ); } );
		self::assertSame( 'temporarily_unavailable', $error->code );
		self::assertStringNotContainsString( 'SELECT', json_encode( $error->to_array(), JSON_THROW_ON_ERROR ) );
		self::assertStringNotContainsString( 'address', json_encode( $error->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_arbitrary_nested_renamed_or_preencoded_loader_context_is_never_serialized(): void {
		foreach ( [ [ 'state' => 'enabled', 'details' => [ 'renamed_cost' => 99, 'address' => 'PRIVATE' ] ],
			(object) [ 'safe_name' => '{"supplier_id":99}' ], '{"state":"enabled","actor_user_id":99}' ] as $private ) {
			foreach ( [ 'for_settings', 'for_diagnostics' ] as $method ) {
				$error = EmergencyControlProjection::$method( 7, RequestContext::create(), static fn() => true, static fn() => $private );
				self::assertInstanceOf( ContractError::class, $error );
				self::assertSame( 'temporarily_unavailable', $error->code );
				self::assertStringNotContainsString( 'PRIVATE', json_encode( $error->to_array(), JSON_THROW_ON_ERROR ) );
			}
		}
	}

	public function test_diagnostics_distinguishes_control_module_publication_and_bounded_observations(): void {
		$calls = [];
		$facts = EmergencyControlDiagnostics::from_array( $this->facts() );
		$output = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static function ( int $site, string $cap, string $purpose ) use ( &$calls ): bool { $calls[] = [ $site, $cap, $purpose ]; return true; }, static fn() => $facts );
		self::assertSame( [ [ 7, 'view_delivery_diagnostics', 'diagnostics' ], [ 7, 'view_delivery_diagnostics', 'diagnostics' ] ], $calls );
		self::assertSame( 'checkout_suspended', $output['stored_state'] );
		self::assertSame( 'checkout_suspended', $output['effective_state'] );
		self::assertTrue( $output['publication_pending'] );
		self::assertSame( [ 'kind' => 'bounded_observed_estimate', 'observed_lines' => 200, 'observed_packages' => 9, 'complete' => false, 'limit' => 200 ], $output['observed_impact'] );
		self::assertSame( 'current_site_all_or_none', $output['activation_policy'] );
		$this->assert_private_absent( $output );
	}

	public function test_enabled_control_does_not_claim_module_readiness_or_unknown_counts(): void {
		foreach ( [ 'ready' => 'enabled', 'unready' => 'unavailable', 'unknown' => 'unavailable' ] as $readiness => $effective ) {
			$facts = new EmergencyControlDiagnostics( 7, $this->physical( state: 'enabled' ), $readiness );
			$output = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, static fn() => $facts );
			self::assertSame( 'enabled', $output['stored_state'] );
			self::assertSame( $effective, $output['effective_state'] );
			self::assertFalse( $output['observed_impact']['complete'] );
		}
	}

	public function test_unobserved_publication_is_unknown_and_only_explicit_observations_claim_a_state(): void {
		$read = $this->physical( state: 'enabled' );
		$unknown = new EmergencyControlDiagnostics( 7, $read, 'ready' );
		$output = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, static fn() => $unknown );
		self::assertSame( 'enabled', $output['effective_state'] );
		self::assertArrayHasKey( 'publication_pending', $output );
		self::assertNull( $output['publication_pending'] );
		foreach ( [ null, true, false ] as $observed ) {
			$facts = EmergencyControlDiagnostics::from_array( array_replace( $this->facts( $read ), [ 'publication_pending' => $observed ] ) );
			$output = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, static fn() => $facts );
			self::assertSame( $observed, $output['publication_pending'] );
		}
	}

	public function test_diagnostic_schema_refuses_nested_and_renamed_private_fields_and_nonfinite_labels(): void {
		$bad = [];
		$base = $this->facts();
		$bad[] = [ ...$base, 'details' => [ 'address' => 'PRIVATE' ] ];
		$bad[] = [ ...$base, 'safe_reference' => '{"operator":"PRIVATE"}' ];
		$renamed = $base; unset( $renamed['observed_lines'] ); $renamed['safe_line_count'] = 200; $bad[] = $renamed;
		foreach ( [ 'module_readiness' => [ 'actor' => 99 ], 'activation_policy' => '{"percent":50}', 'control' => [ 'state' => 'enabled' ],
			'publication_pending' => 1, 'observed_lines' => '200', 'observed_packages' => 201, 'observed_complete' => [ 'private' => true ] ] as $key => $value ) { $bad[] = array_replace( $base, [ $key => $value ] ); }
		foreach ( $bad as $facts ) {
			try { EmergencyControlDiagnostics::from_array( $facts ); self::fail( 'Private or malformed diagnostic input was accepted.' ); }
			catch ( InvalidArgumentException $error ) { self::assertSame( 'Invalid emergency-control diagnostic facts.', $error->getMessage() ); }
		}
	}

	public function test_diagnostic_bounds_and_site_coherence_are_enforced_before_projection(): void {
		foreach ( [ [ 'observed_lines' => -1 ], [ 'observed_lines' => 201 ], [ 'observed_packages' => -1 ],
			[ 'site_id' => 0 ], [ 'site_id' => 8 ], [ 'module_readiness' => 'cohort_ready' ], [ 'activation_policy' => 'percentage' ] ] as $changed ) {
			try { EmergencyControlDiagnostics::from_array( array_replace( $this->facts(), $changed ) ); self::fail( 'Invalid diagnostic bounds accepted.' ); }
			catch ( InvalidArgumentException $error ) { self::assertSame( 'Invalid emergency-control diagnostic facts.', $error->getMessage() ); }
		}
	}

	public function test_input_reference_changes_do_not_modify_captured_facts_or_output(): void {
		$lines = 5; $readiness = 'ready'; $input = $this->facts();
		$input['observed_lines'] = &$lines; $input['module_readiness'] = &$readiness;
		$facts = EmergencyControlDiagnostics::from_array( $input );
		$lines = 201; $readiness = 'PRIVATE'; $input['control'] = 'PRIVATE';
		$output = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, static fn() => $facts );
		self::assertSame( 5, $output['observed_impact']['observed_lines'] );
		self::assertSame( 'ready', $output['module_readiness'] );
		$output['observed_impact']['observed_lines'] = 999;
		$again = EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, static fn() => $facts );
		self::assertSame( 5, $again['observed_impact']['observed_lines'] );
	}

	public function test_generic_diagnostic_serialization_refuses_instead_of_recursing_into_private_control(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'Emergency-control diagnostics require an authorized projection.' );
		json_encode( EmergencyControlDiagnostics::from_array( $this->facts() ), JSON_THROW_ON_ERROR );
	}

	public function test_projection_does_not_change_any_existing_flag_or_saved_control_bytes(): void {
		$before = $GLOBALS['cetech_de_test_options'] ?? [];
		try {
			$flags = new FeatureFlags(); $defaults = $flags->defaults();
			self::assertCount( 23, $defaults );
			self::assertSame( [ 'enable_classic_checkout_adapter' => true ], array_filter( $defaults ) );
			foreach ( array_keys( $defaults ) as $i => $flag ) { $GLOBALS['cetech_de_test_options'][ $flags->option_name( $flag ) ] = $i % 2; }
			$GLOBALS['cetech_de_test_options'][DataLifecycleManifest::CHECKOUT_CONTROL_OPTION] = $this->physical()->state->original_bytes();
			$saved = $GLOBALS['cetech_de_test_options']; $memoized = $flags->all();
			EmergencyControlProjection::for_settings( 7, RequestContext::create(), static fn() => true, fn() => $this->physical() );
			EmergencyControlProjection::for_shopper( $this->decision( 'checkout_suspended' ), RequestContext::create() );
			EmergencyControlProjection::for_diagnostics( 7, RequestContext::create(), static fn() => true, fn() => EmergencyControlDiagnostics::from_array( $this->facts() ) );
			self::assertSame( $saved, $GLOBALS['cetech_de_test_options'] );
			self::assertSame( $memoized, $flags->all() );
			self::assertSame( $memoized, ( new FeatureFlags() )->all() );
		} finally { $GLOBALS['cetech_de_test_options'] = $before; }
	}
}
