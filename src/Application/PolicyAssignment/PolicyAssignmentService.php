<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PolicyAssignment;

use CetechDeliveryEngine\Application\Operation\{OperationCoordinator, OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationIdentity, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationPhaseObserver, OperationProfileRegistry, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{PolicyAssignmentStore, RuleLifecycleReadiness};

/** Original C03 attempts only; current native grants are checked separately by the host. */
final class PolicyAssignmentService {

	private readonly \Closure $grant;
	private readonly OperationReadiness $readiness;
	public function __construct( private readonly OperationConnectionFactory $factory, private readonly PolicyAssignmentStore $store, private readonly PolicyCurrentCatalog $catalog, callable $pure_authorize, ?OperationReadiness $readiness = null, private readonly ?OperationPhaseObserver $observer = null ) {
		$this->grant = \Closure::fromCallable( $pure_authorize );
		$this->readiness = $readiness ?? new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void { ( new RuleLifecycleReadiness( $session ) )->assert_ready(); } };
	}
	public function attempt( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult { return $this->execute( false, $identity, $payload, $request ); }
	public function reconcile( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult { return $this->execute( true, $identity, $payload, $request ); }

	private function execute( bool $reconcile, OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult {
		$tracked = new class( $this->factory ) implements OperationConnectionFactory {
			public array $sessions = [];
			public function __construct( private readonly OperationConnectionFactory $source ) {}
			public function open(): OperationSession { $session = $this->source->open(); $this->sessions[] = $session; return $session; }
		};
		$profile = new PolicyAssignmentOperationProfile( $this->grant, $this->store, $this->catalog );
		$coordinator = new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $tracked, $this->readiness, $this->observer );
		try { $result = $reconcile ? $coordinator->reconcile( $identity, $payload, $request ) : $coordinator->attempt( $identity, $payload, $request ); }
		catch ( \Throwable ) { $result = self::unknown( $request ); }
		$all_retired = true;
		foreach ( $tracked->sessions as $session ) { $confirmed = $this->retired( $session ); $all_retired = $confirmed && $all_retired; }
		if ( ! $all_retired ) { return self::unknown( $request ); }
		if ( 'not_applicable' === $result->completion?->state && ! $profile->validate_no_change_facts( $result->completion ) ) { return self::unknown( $request ); }
		if ( in_array( $result->completion?->state, [ 'accepted', 'not_applicable' ], true ) && ! $profile->authorize( $identity ) ) { return self::unknown( $request ); }
		return $result;
	}
	private function retired( OperationSession $session ): bool { try { return $session->retire() && $session->is_retired() && ! $session->in_transaction(); } catch ( \Throwable ) { return false; } }
	private static function unknown( RequestContext $request ): OperationAttemptResult { return new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ); }
}
