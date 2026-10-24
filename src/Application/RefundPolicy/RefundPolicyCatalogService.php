<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\RefundPolicy;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleReadService;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyContent;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyFamily;
use CetechDeliveryEngine\Domain\RefundPolicy\RefundPolicyVersion;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;

/** Explicit administrative authoring; no runtime registration, activation or public projection. */
final class RefundPolicyCatalogService {

	private const OPERATIONS = [ 'rule.draft.create', 'rule.draft.edit', 'rule.schedule', 'rule.publish', 'rule.retire' ];

	/** @var \Closure(OperationIdentity):bool Separate family-wide disclosure grant. */
	private readonly \Closure $authorize_catalog;

	public function __construct(
		private readonly RefundPolicyFamily $family,
		private readonly OperationConnectionFactory $connections,
		callable $authorize_catalog,
		private readonly ?OperationReadiness $readiness = null,
		private readonly ?OperationPhaseObserver $observer = null
	) {
		$this->authorize_catalog = \Closure::fromCallable( $authorize_catalog );
	}

	public function attempt( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult {
		return $this->execute( false, $identity, $payload, $request );
	}

	public function reconcile( OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult {
		return $this->execute( true, $identity, $payload, $request );
	}

	/** Captures current heads and immediate predecessors, never complete audit/history. */
	public function capture( OperationIdentity $identity, array $authorization_scope, RequestContext $request ): RuleSnapshot|ContractError {
		try { $scope = $this->family->checked_scope( $authorization_scope ); }
		catch ( \Throwable ) { return self::invalid( $request ); }
		if ( ! $this->catalog_allowed( $identity ) ) { return self::denied( $request ); }

		try {
			$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $this->family ] ), $this->connections, $this->readiness );
			$snapshot = $reader->capture( $identity, RefundPolicyContent::FAMILY, $scope, $request );
			if ( $snapshot instanceof ContractError ) { return $snapshot; }
			if ( ! $snapshot->complete || $snapshot->history_complete || $snapshot->profile !== $this->family || ( null !== $snapshot->guard && $snapshot->guard->site_id !== $identity->site_id ) ) {
				throw new \InvalidArgumentException( 'Refund policy catalog capture is unavailable.' );
			}
			$logicals = [];
			foreach ( $snapshot->logicals as $logical ) {
				$policy_scope = $this->family->checked_scope( $logical->scope() );
				if ( $logical->site_id !== $identity->site_id || $policy_scope['policy_uuid'] !== $logical->logical_uuid ) {
					throw new \InvalidArgumentException( 'Refund policy catalog identity is unavailable.' );
				}
				$logicals[ $logical->id ] = $logical;
			}
			// Include retained predecessors too: candidates() intentionally filters retired evidence.
			foreach ( $snapshot->versions as $version ) {
				if ( 0 !== $version->priority || ! isset( $logicals[ $version->logical_rule_id ] ) ) {
					throw new \InvalidArgumentException( 'Refund policy catalog content is unavailable.' );
				}
				RefundPolicyVersion::from_candidate( new RuleCandidate( $logicals[ $version->logical_rule_id ], $version ), $this->family );
			}
		} catch ( \Throwable ) { return self::unavailable( $request ); }

		return $this->catalog_allowed( $identity ) ? $snapshot : self::denied( $request );
	}

	private function execute( bool $reconcile, OperationIdentity $identity, mixed $payload, RequestContext $request ): OperationAttemptResult {
		if ( is_array( $payload ) && is_string( $payload['family'] ?? null ) && RefundPolicyContent::FAMILY !== $payload['family'] ) {
			return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'unsupported_contract', $request, 'contact_support' ) ) );
		}
		try {
			$command = RuleLifecycleCommand::from_payload( $identity, $payload, new RuleFamilyRegistry( [ $this->family ] ) );
			if ( ! in_array( $identity->operation, self::OPERATIONS, true ) || 0 !== $command->priority || $command->scope['policy_uuid'] !== $command->logical_uuid ) {
				throw new \InvalidArgumentException( 'Invalid refund policy catalog command.' );
			}
			RefundPolicyContent::from_rule_payload( $command->payload );
			$families = new RuleFamilyRegistry( [ $this->family->for_author( $command->author_user_id ) ] );
			$lifecycle = new RuleLifecycleService( $families, $this->connections, $this->readiness, $this->observer );
		} catch ( \Throwable ) {
			return new OperationAttemptResult( OperationOutcome::rejected( self::invalid( $request ) ) );
		}

		// Preserve original IDs, author, CAS, dates and namespace, including after mutable rows advance.
		return $reconcile ? $lifecycle->reconcile( $identity, $payload, $request ) : $lifecycle->attempt( $identity, $payload, $request );
	}

	private function catalog_allowed( OperationIdentity $identity ): bool {
		try { return true === ( $this->authorize_catalog )( $identity ); }
		catch ( \Throwable ) { return false; }
	}

	private static function invalid( RequestContext $request ): ContractError {
		return new ContractError( 'invalid_input', $request, 'reload_and_submit', [], [ [ 'field' => 'semantic_payload', 'code' => 'unsupported_value' ] ] );
	}

	private static function denied( RequestContext $request ): ContractError {
		return new ContractError( 'not_authorized', $request, 'contact_support' );
	}

	private static function unavailable( RequestContext $request ): ContractError {
		return new ContractError( 'temporarily_unavailable', $request, 'contact_support' );
	}
}
