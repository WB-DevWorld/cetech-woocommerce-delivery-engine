<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PolicyAssignment;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationJson, OperationSession};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentMap, PolicyAssignmentState, PolicyEffectiveSelection};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleSnapshot;
use CetechDeliveryEngine\Infrastructure\Persistence\{PolicyAssignmentStore, RuleLifecycleReadiness, RuleLifecycleSchema, WpdbRuleLifecycleRepository};

/** Saved-state, as-of observations on one read owner; no current-source memo. */
final class PolicyAssignmentCurrentService {

	private readonly \Closure $authorize;

	public function __construct(
		private readonly OperationConnectionFactory $factory,
		private readonly PolicyAssignmentStore $store,
		private readonly PolicyCurrentCatalog $catalog,
		private readonly EffectiveConfigurationResolver $resolver,
		callable $pure_authorize,
		private readonly ?OperationReadiness $readiness = null
	) { $this->authorize = \Closure::fromCallable( $pure_authorize ); }

	public function read( OperationIdentity $identity, array $coordinate, int $offer = 0 ): PolicyAssignmentState {
		$result = $this->observe( false, $identity, $coordinate, $offer );
		if ( ! $result instanceof PolicyAssignmentState ) { self::refuse(); }
		return $result;
	}

	/** @return array{states:array,selections:array} */
	public function capture( OperationIdentity $identity, array $coordinate, int $positive_real_offer ): array {
		if ( $positive_real_offer < 1 ) { self::refuse(); }
		$result = $this->observe( true, $identity, $coordinate, $positive_real_offer );
		if ( ! is_array( $result ) ) { self::refuse(); }
		return $result;
	}

	private function observe( bool $capture, OperationIdentity $identity, array $coordinate, int $offer ): PolicyAssignmentState|array {
		$session = null;
		$owned = false;
		$closed = false;
		try {
			OperationJson::exact_fields( (object) $coordinate, [ 'site_id', 'scope_type', 'scope_id', 'parent_id' ] );
			$coordinate = PolicyAssignmentState::make_coordinate( $coordinate['site_id'], $coordinate['scope_type'], $coordinate['scope_id'], $coordinate['parent_id'] );
			if ( $offer < 0 || $identity->site_id !== $coordinate['site_id'] || PolicyAssignmentCommand::AUTHORITY !== $identity->authority || 'policy.assignment.read' !== $identity->operation || 1 !== $identity->operation_version || PolicyAssignmentCommand::make_target( $coordinate['scope_type'], $coordinate['scope_id'], $offer ) !== $identity->target_key || ! $this->allowed( $identity, $coordinate, $offer ) ) { self::refuse(); }
			$session = $this->factory->open();
			$binding = [ $session->site_id(), $session->table_prefix() ];
			if ( $binding[0] !== $identity->site_id || $session->is_retired() || $session->in_transaction() ) { self::refuse(); }
			if ( null !== $this->readiness ) { $this->readiness->assert_ready( $session ); }
			else { ( new RuleLifecycleReadiness( $session ) )->assert_ready(); }
			$this->assert_binding( $session, $binding, false );
			if ( ! $this->allowed( $identity, $coordinate, $offer ) ) { self::refuse(); }
			$owned = true; // Beginning was attempted only after the no-foreign-unit fence.
			if ( ! $session->begin() ) { self::refuse(); }
			$this->assert_binding( $session, $binding, true );
			$this->store->assert_ready( $session );
			if ( ! $session->validate_tables( [ $this->store->options_table( $session ), ...RuleLifecycleSchema::tables( $binding[1] ) ] ) ) { self::refuse(); }
			$this->store->guard( $session, false );
			$this->catalog->lock_families( $session );
			if ( $capture ) {
				$states = [];
				foreach ( $this->chain( $coordinate ) as $scope ) { $states[] = $this->store->current( $session, $scope ); }
				$result = $this->capture_saved( $session, $identity, $coordinate, $offer, $states );
			} else {
				$result = $this->store->current( $session, $coordinate );
				$at = ( new WpdbRuleLifecycleRepository( $session ) )->accepted_time();
				if ( null !== $result->changed_at() && $result->changed_at()->compare( $at ) > 0 ) { self::refuse(); }
			}
			$this->assert_binding( $session, $binding, true );
			if ( ! $this->allowed( $identity, $coordinate, $offer ) || ! $session->rollback() ) { self::refuse(); }
			$owned = false;
			if ( ! $session->retire() ) { self::refuse(); }
			$this->assert_retired( $session, $binding );
			if ( ! $this->allowed( $identity, $coordinate, $offer ) ) { self::refuse(); }
			$this->assert_retired( $session, $binding );
			$closed = true;
			return $result;
		} catch ( \Throwable ) { self::refuse(); }
		finally {
			if ( $session instanceof OperationSession && ! $closed ) {
				// Metadata faults must not prevent either independent cleanup attempt.
				if ( $owned ) { try { $session->rollback(); } catch ( \Throwable ) {} }
				try { $session->retire(); } catch ( \Throwable ) {}
			}
		}
	}

	private function capture_saved( OperationSession $session, OperationIdentity $identity, array $coordinate, int $offer, array $states ): array {
		$resolved = $this->resolver->resolve_policy_instructions( $coordinate, $offer, $states );
		OperationJson::exact_fields( (object) $resolved, [ 'instructions', 'provenance', 'fingerprint' ] );
		if ( ! is_array( $resolved['instructions'] ) || ! is_array( $resolved['provenance'] ) || ! is_string( $resolved['fingerprint'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $resolved['fingerprint'] ) ) { self::refuse(); }
		foreach ( [ 'instructions', 'provenance' ] as $field ) {
			if ( count( $resolved[$field] ) !== 2 || [] !== array_diff( PolicyAssignmentMap::FAMILIES, array_keys( $resolved[$field] ) ) ) { self::refuse(); }
		}
		$snapshots = [];
		foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
			$instruction = $resolved['instructions'][$family];
			if ( ! is_array( $instruction ) || ! is_array( $resolved['provenance'][$family] ) ) { self::refuse(); }
			// Validate the whole instruction before locking anything selected from it.
			$checked = PolicyAssignmentMap::empty( $identity->site_id, $family )->with_instruction( 0, $instruction )->instruction( $offer )['instruction'];
			$resolved['instructions'][$family] = $checked;
			if ( 'assigned' !== $checked['mode'] ) { continue; }
			try { $snapshots[$family] = $this->catalog->snapshot( $session, $family, $checked['policy_uuid'] ); }
			catch ( \UnexpectedValueException ) { $snapshots[$family] = null; }
		}
		// All selected source locks precede the one evaluation instant. No failed read is retried.
		$at = ( new WpdbRuleLifecycleRepository( $session ) )->accepted_time();
		foreach ( $states as $state ) { if ( null !== $state->changed_at() && $state->changed_at()->compare( $at ) > 0 ) { self::refuse(); } }
		$selections = [];
		foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
			$instruction = $resolved['instructions'][$family];
			$provenance = $resolved['provenance'][$family];
			if ( 'assigned' === $instruction['mode'] ) {
				$snapshot = $snapshots[$family] ?? null;
				if ( ! $snapshot instanceof RuleSnapshot ) { $selections[$family] = PolicyEffectiveSelection::unresolved( $family, $at, $provenance, $resolved['fingerprint'], 'policy_unavailable' ); continue; }
				try {
					if ( true !== $snapshot->profile->authorize( $identity, [ 'policy_uuid' => $instruction['policy_uuid'] ] ) ) { self::refuse(); }
					$selections[$family] = PolicyEffectiveSelection::assigned( $family, $snapshot, $instruction['policy_uuid'], $at, $provenance, $resolved['fingerprint'] );
				} catch ( \InvalidArgumentException ) { $selections[$family] = PolicyEffectiveSelection::unresolved( $family, $at, $provenance, $resolved['fingerprint'], 'policy_unavailable' ); }
			} elseif ( 'disabled' === $instruction['mode'] ) { $selections[$family] = PolicyEffectiveSelection::disabled( $family, $at, $provenance, $resolved['fingerprint'] ); }
			else { $selections[$family] = PolicyEffectiveSelection::not_assigned( $family, $at, $provenance, $resolved['fingerprint'] ); }
		}
		foreach ( $snapshots as $family => $snapshot ) {
			if ( $snapshot instanceof RuleSnapshot && true !== $snapshot->profile->authorize( $identity, [ 'policy_uuid' => $resolved['instructions'][$family]['policy_uuid'] ] ) ) { self::refuse(); }
		}
		return [ 'states' => $states, 'selections' => $selections ];
	}

	private function chain( array $coordinate ): array {
		$site = $coordinate['site_id'];
		$out = [ PolicyAssignmentState::make_coordinate( $site, 'global', 0 ) ];
		if ( 'product' === $coordinate['scope_type'] ) { $out[] = $coordinate; }
		elseif ( 'variation' === $coordinate['scope_type'] ) { $out[] = PolicyAssignmentState::make_coordinate( $site, 'product', $coordinate['parent_id'] ); $out[] = $coordinate; }
		return $out;
	}

	private function allowed( OperationIdentity $identity, array $coordinate, int $offer ): bool { try { return true === ( $this->authorize )( $identity, null, $coordinate, $offer ); } catch ( \Throwable ) { return false; } }
	private function assert_binding( OperationSession $session, array $binding, bool $transaction ): void {
		if ( $session->is_retired() || $session->in_transaction() !== $transaction || [ $session->site_id(), $session->table_prefix() ] !== $binding ) { self::refuse(); }
	}
	private function assert_retired( OperationSession $session, array $binding ): void {
		if ( ! $session->is_retired() || $session->in_transaction() || [ $session->site_id(), $session->table_prefix() ] !== $binding ) { self::refuse(); }
	}
	private static function refuse(): never { throw new \RuntimeException( 'Policy assignments are unavailable.' ); }
}
