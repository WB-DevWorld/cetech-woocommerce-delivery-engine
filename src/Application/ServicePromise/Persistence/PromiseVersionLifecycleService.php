<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Persistence;
use CetechDeliveryEngine\Application\Operation\{OperationCoordinator, OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationIdentity, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationPhaseObserver, OperationProfileRegistry, OperationRefusal};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePermissionGrant, PromisePersistenceAuthorizer, PromiseSiteBinding, PromiseVersionCommand};

/** Inactive explicit application seam; no WordPress hook or publication adapter is mounted. */
final class PromiseVersionLifecycleService {
	public function __construct( private PromiseSiteBinding $binding, private OperationConnectionFactory $connections, private PromisePersistenceAuthorizer $authority, private ?OperationReadiness $readiness = null, private ?OperationPhaseObserver $observer = null ) {}
	public function attempt( OperationIdentity $identity, mixed $payload, RequestContext $context ): OperationAttemptResult { return $this->execute( false, $identity, $payload, $context ); }
	public function reconcile( OperationIdentity $identity, mixed $payload, RequestContext $context ): OperationAttemptResult { return $this->execute( true, $identity, $payload, $context ); }
	private function execute( bool $reconcile, OperationIdentity $identity, mixed $payload, RequestContext $context ): OperationAttemptResult {
		$result = null;
		try {
			$command = $payload instanceof PromiseVersionCommand ? $payload : PromiseVersionCommand::from_array( $identity, $this->binding, $payload );
			if ( $command->identity !== $identity || $command->binding->private_facts() !== $this->binding->private_facts() ) { throw new OperationRefusal( 'not_authorized', 'contact_support' ); }
			$profile = PromiseLifecycleOperationProfile::for_command( $command, PromisePermissionGrant::capture( $command, $this->authority ) );
			$coordinator = new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $this->connections, $this->readiness ?? new PromiseOperationReadiness(), $this->observer );
			$result = $reconcile ? $coordinator->reconcile( $identity, $command, $context ) : $coordinator->attempt( $identity, $command, $context );
			// The coordinator has retired its SQL owner before this current disclosure check.
			PromisePermissionGrant::capture( $command, $this->authority ); return $result;
		} catch ( OperationRefusal $e ) { if ( null !== $result && 'accepted' === $result->outcome->state ) { return self::unknown( $context ); } return new OperationAttemptResult( OperationOutcome::rejected( $e->error( $context ) ) ); }
		catch ( \Throwable ) { if ( null !== $result && 'accepted' === $result->outcome->state ) { return self::unknown( $context ); } return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'invalid_input', $context, 'reload_and_submit' ) ) ); }
	}
	private static function unknown( RequestContext $context ): OperationAttemptResult { return new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $context, 'reconcile_original_request' ) ) ); }
}
