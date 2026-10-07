<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlDiagnostics;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlReadResult;
use Throwable;

/** Explicit site-control serializers. No product identity or arbitrary context. */
final class EmergencyControlProjection {
	public const CONTRACT_VERSION = 1;

	public static function for_shopper( EmergencyAdmissionResult $decision, RequestContext $request ): array {
		$category = self::category( $decision );
		return [
			'contract_version' => self::CONTRACT_VERSION, 'decision_kind' => 'site_control',
			'status' => $decision->allowed ? 'accepted' : 'refused',
			'message_key' => null === $category ? null : 'cetech.checkout.' . $category,
			'message' => match ( $category ) {
				'paused' => 'Delivery and pickup checkouts are temporarily paused. Please try again later.',
				'revalidation_required' => 'Your delivery choices need to be checked again. Return to checkout and review them.',
				'unavailable' => 'Checkout could not be confirmed. Please try again later.',
				default => null,
			},
			'recovery_action' => match ( $category ) { 'revalidation_required' => 'reload_and_submit', 'paused', 'unavailable' => 'retry_later', default => null },
			'correlation_id' => $request->correlation_id,
		];
	}

	/** The application resolves current site/capability/WCFM authority, never a saved grant. */
	public static function for_settings( int $site, RequestContext $request, callable $authorize, callable $load ): array|ContractError {
		$read = self::load_authorized( $site, $request, $authorize, $load, 'manage_delivery_settings', 'settings', EmergencyControlReadResult::class );
		if ( $read instanceof ContractError ) { return $read; }
		return self::settings_fields( $site, $request, $read );
	}

	/** All technical observations are loaded only after diagnostics authority. */
	public static function for_diagnostics( int $site, RequestContext $request, callable $authorize, callable $load ): array|ContractError {
		$facts = self::load_authorized( $site, $request, $authorize, $load, 'view_delivery_diagnostics', 'diagnostics', EmergencyControlDiagnostics::class );
		if ( $facts instanceof ContractError ) { return $facts; }
		$state = $facts->control->state?->state;
		return [
			'contract_version' => self::CONTRACT_VERSION, 'decision_kind' => 'site_control', 'site_id' => $site,
			'ready' => $facts->control->available,
			'stored_state' => $facts->control->state?->initialized ? $state : null,
			'current_control_state' => $state,
			'effective_state' => 'checkout_suspended' === $state ? 'checkout_suspended' : ( 'enabled' === $state && 'ready' === $facts->module_readiness ? 'enabled' : 'unavailable' ),
			'revision' => $facts->control->state?->revision, 'initialized' => $facts->control->state?->initialized,
			'module_readiness' => $facts->module_readiness, 'activation_policy' => $facts->activation_policy,
			'publication_pending' => $facts->publication_pending,
			'observed_impact' => [ 'kind' => 'bounded_observed_estimate', 'observed_lines' => $facts->observed_lines,
				'observed_packages' => $facts->observed_packages, 'complete' => $facts->observed_complete, 'limit' => EmergencyControlDiagnostics::OBSERVATION_LIMIT ],
			'correlation_id' => $request->correlation_id,
		];
	}

	/** Producing these facts does not assert a payment, mutation or exhaustive count. */
	public static function for_diagnostic_log( EmergencyAdmissionResult $decision ): array {
		return [ 'operation' => 'checkout.admission', 'reason_code' => $decision->code,
			'outcome' => $decision->allowed ? 'allowed' : ( 'unavailable' === self::category( $decision ) ? 'unavailable' : 'refused' ),
			'control_revision' => $decision->revision,
			'complete' => ! in_array( $decision->code, [ 'control_unavailable', 'unsupported_activation_policy' ], true ) ];
	}

	private static function category( EmergencyAdmissionResult $decision ): ?string {
		return match ( $decision->code ) {
			'allowed', 'unmanaged', 'already_paid' => null,
			'checkout_suspended' => 'paused',
			'stale_control_revision', 'checkout_revalidation_required' => 'revalidation_required',
			'control_unavailable', 'unsupported_activation_policy' => 'unavailable',
		};
	}

	private static function settings_fields( int $site, RequestContext $request, EmergencyControlReadResult $read ): array {
		$state = $read->state?->state;
		return [ 'contract_version' => self::CONTRACT_VERSION, 'decision_kind' => 'site_control', 'site_id' => $site,
			'ready' => $read->available, 'state' => $state, 'revision' => $read->state?->revision, 'initialized' => $read->state?->initialized,
			'message_key' => match ( $state ) { 'enabled' => 'cetech.checkout.enabled', 'checkout_suspended' => 'cetech.checkout.paused', default => 'cetech.checkout.unavailable' },
			'message' => match ( $state ) {
				'enabled' => 'Checkouts are enabled. Delivery choices will be checked again.',
				'checkout_suspended' => 'New delivery and pickup checkouts are paused. Payments on unpaid delivery orders are also paused. Existing paid orders and shipments continue.',
				default => 'The current checkout control is unavailable. Check the current state before submitting a change.',
			}, 'correlation_id' => $request->correlation_id ];
	}

	private static function load_authorized( int $site, RequestContext $request, callable $authorize, callable $load, string $capability, string $purpose, string $expected ): EmergencyControlReadResult|EmergencyControlDiagnostics|ContractError {
		if ( $site < 1 || ! self::authorized( $authorize, $site, $capability, $purpose ) ) { return new ContractError( 'not_authorized', $request, 'contact_support' ); }
		try { $loaded = $load( $site ); } catch ( Throwable ) {
			return self::authorized( $authorize, $site, $capability, $purpose )
				? new ContractError( 'temporarily_unavailable', $request, 'contact_support' ) : new ContractError( 'not_authorized', $request, 'contact_support' );
		}
		if ( ! self::authorized( $authorize, $site, $capability, $purpose ) ) { return new ContractError( 'not_authorized', $request, 'contact_support' ); }
		if ( ! $loaded instanceof $expected ) { return new ContractError( 'temporarily_unavailable', $request, 'contact_support' ); }
		$loaded_site = $loaded instanceof EmergencyControlDiagnostics ? $loaded->site_id : $loaded->state?->site_id;
		if ( null !== $loaded_site && $site !== $loaded_site ) { return new ContractError( 'not_authorized', $request, 'contact_support' ); }
		// A service's current denial is not converted to an administrative state view.
		$read = $loaded instanceof EmergencyControlDiagnostics ? $loaded->control : $loaded;
		if ( 'not_authorized' === $read->code ) { return new ContractError( 'not_authorized', $request, 'contact_support' ); }
		return $loaded;
	}

	private static function authorized( callable $authorize, int $site, string $capability, string $purpose ): bool {
		try { return true === $authorize( $site, $capability, $purpose ); } catch ( Throwable ) { return false; }
	}
}
