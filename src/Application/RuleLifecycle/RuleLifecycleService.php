<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\RuleLifecycle;

use CetechDeliveryEngine\Application\Operation\DatabaseOperationReadiness;
use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;

/** Internal, explicitly scoped C03 adopter. Empty production family registry refuses. */
final class RuleLifecycleService {
	private readonly OperationReadiness $readiness;
	public function __construct( private readonly RuleFamilyRegistry $families, private readonly OperationConnectionFactory $factory, ?OperationReadiness $readiness = null, private readonly ?OperationPhaseObserver $observer = null ) {
		$this->readiness = $readiness ?? new class( $families ) implements OperationReadiness {
			public function __construct( private readonly RuleFamilyRegistry $families ) {}
			public function assert_ready( OperationSession $session ): void {
				( new DatabaseOperationReadiness() )->assert_ready( $session );
				( new RuleLifecycleReadiness( $session, $this->families ) )->assert_ready();
			}
		};
	}
	public function attempt( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult { return $this->execute( false, $identity, $payload, $request ); }
	public function reconcile( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult { return $this->execute( true, $identity, $payload, $request ); }
	private function execute( bool $reconcile, OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult {
		try {
			if ( ! is_array( $payload ) || ! is_string( $payload['family'] ?? null ) ) { throw new \InvalidArgumentException(); }
			try { $this->families->get( $payload['family'] ); }
			catch ( \Throwable ) { return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'unsupported_contract', $request, 'contact_support' ) ) ); }
			$command = RuleLifecycleCommand::from_payload( $identity, $payload, $this->families );
		} catch ( \Throwable ) { return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'invalid_input', $request, 'reload_and_submit' ) ) ); }
		$profile = new RuleLifecycleOperationProfile( $command );
		$coordinator = new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $this->factory, $this->readiness, $this->observer );
		return $reconcile ? $coordinator->reconcile( $identity, $command, $request ) : $coordinator->attempt( $identity, $command, $request );
	}
}
