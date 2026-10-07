<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Operation\DatabaseOperationReadiness;
use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlReadResult;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** Physical state is authoritative. Neither reads nor replay republish old values. */
final class EmergencyControlService implements EmergencyAdmissionControlInterface {
	private \Closure $authorize;
	private EmergencyControlStore $store;
	private OperationReadiness $readiness;
	public function __construct( private OperationConnectionFactory $factory, callable $admin_authorizer, ?EmergencyControlStore $store = null, ?OperationReadiness $readiness = null, private ?OperationPhaseObserver $observer = null, private bool $trusted_equivalent_route = false ) {
		$this->authorize = \Closure::fromCallable( $admin_authorizer ); $this->store = $store ?? new EmergencyControlStore(); $this->readiness = $readiness ?? new DatabaseOperationReadiness();
	}
	public function read( int $site, ?RequestContext $request = null ): EmergencyControlReadResult { return $this->observe( $site, null, null, $request ?? RequestContext::create() ); }
	/** The callback is only a cheap request-local facts comparison, never Woo work. */
	public function confirm_enabled( int $site, int $expected_revision, ?callable $unchanged_facts = null, ?RequestContext $request = null ): EmergencyControlReadResult { return $this->observe( $site, $expected_revision, $unchanged_facts, $request ?? RequestContext::create() ); }
	public function transition( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult { return $this->execute( false, $identity, $payload, $request ); }
	public function reconcile( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult { return $this->execute( true, $identity, $payload, $request ); }
	private function execute( bool $reconcile, OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult {
		$profile = new EmergencyControlOperationProfile( $this->authorize, $this->store, $this->trusted_equivalent_route );
		$tracked = new class( $this->factory ) implements OperationConnectionFactory {
			public array $sessions = [];
			public function __construct( private OperationConnectionFactory $factory ) {}
			public function open(): OperationSession { $session = $this->factory->open(); $this->sessions[] = $session; return $session; }
		};
		$coordinator = new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $tracked, $this->readiness, $this->observer );
		$result = $reconcile ? $coordinator->reconcile( $identity, $payload, $request ) : $coordinator->attempt( $identity, $payload, $request );
		foreach ( $tracked->sessions as $session ) { if ( ! $this->retire_confirmed( $session ) ) { return new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ); } }
		if ( 'not_applicable' === $result->completion?->state && ! $profile->validate_no_change_facts( $result->completion ) ) { return new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ); }
		return $result;
	}
	private function observe( int $site, ?int $expected, ?callable $unchanged_facts, RequestContext $request ): EmergencyControlReadResult {
		$session = null; $owned = false; $code = 'temporarily_unavailable';
		try {
			if ( $site < 1 || null !== $expected && $expected < 1 ) { return EmergencyControlReadResult::unavailable( 'not_authorized', $request ); }
			$session = $this->factory->open();
			if ( $session->site_id() !== $site ) { $code = 'not_authorized'; throw new \RuntimeException(); }
			if ( $session->is_retired() || $session->in_transaction() ) { throw new \RuntimeException(); }
			if ( ! $this->trusted_equivalent_route ) { $this->store->assert_standard_wordpress_route( $session ); }
			if ( ! $session->begin() ) { throw new \RuntimeException(); } $owned = true;
			$this->store->assert_ready( $session, $site ); $state = $this->store->current( $session );
			if ( null !== $expected && ( ! $state->enabled() || $state->revision !== $expected ) ) { $code = ! $state->enabled() ? 'checkout_suspended' : 'stale_revision'; throw new \RuntimeException(); }
			if ( null !== $unchanged_facts && true !== $unchanged_facts() ) { $code = 'stale_revision'; throw new \RuntimeException(); }
			if ( ! $session->rollback() ) { $owned = false; $code = 'outcome_unknown'; throw new \RuntimeException(); } $owned = false;
			if ( ! $this->retire_confirmed( $session ) ) { return EmergencyControlReadResult::unavailable( 'outcome_unknown', $request ); }
			return EmergencyControlReadResult::ready( $state );
		} catch ( \Throwable ) {
			if ( $owned ) { try { if ( ! $session->rollback() ) { $code = 'outcome_unknown'; } } catch ( \Throwable ) { $code = 'outcome_unknown'; } }
			if ( null !== $session && ! $this->retire_confirmed( $session ) ) { $code = 'outcome_unknown'; }
			return EmergencyControlReadResult::unavailable( $code, $request );
		}
	}
	private function retire_confirmed( OperationSession $session ): bool { try { return $session->retire() && $session->is_retired(); } catch ( \Throwable ) { return false; } }
}
