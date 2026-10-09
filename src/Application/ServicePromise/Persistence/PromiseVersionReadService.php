<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\ServicePromise\Persistence;
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationRefusal, OperationSession};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseCalendarReference, PromisePolicyReference};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromiseEffectiveAssignment, PromisePersistenceAuthorizer, PromiseSiteBinding, PromiseStoredAssignment, PromiseStoredObject, PromiseStoredVersion, PromiseVersionCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPromiseRepository;

/** Explicit private read authority, owning and retiring one bounded SQL read session. */
final class PromiseVersionReadService {
	public function __construct( private PromiseSiteBinding $binding, private OperationConnectionFactory $connections, private PromisePersistenceAuthorizer $authority, private ?OperationReadiness $readiness = null ) {}
	public function load_policy_versions( OperationIdentity $identity, array $references ): array { return $this->load( $identity, $references, false ); }
	public function load_calendar_versions( OperationIdentity $identity, array $references ): array { return $this->load( $identity, $references, true ); }
	private function load( OperationIdentity $identity, array $references, bool $calendars ): array {
		if ( 'promise.versions.read' !== $identity->operation || 1 !== $identity->operation_version || $identity->target_key !== 'promise-versions:' . $this->binding->site_key() || ! array_is_list( $references ) || count( $references ) > 200 ) { self::refuse(); }
		foreach ( $references as $reference ) { if ( ! $reference instanceof ( $calendars ? PromiseCalendarReference::class : PromisePolicyReference::class ) || $reference->site_id() !== $this->binding->site_key() ) { self::refuse(); } }
		$scope = [ 'kind' => 'global', 'target_id' => 0 ]; $this->authorize( $identity, $scope );
		$result = $this->owned( $identity, static fn( WpdbPromiseRepository $repo ): array => $calendars ? $repo->load_calendar_versions( $references ) : $repo->load_policy_versions( $references ) );
		$this->authorize( $identity, $scope );
		if ( ! $calendars ) { foreach ( $result as $policy ) { $this->authorize( $identity, PromiseVersionCommand::policy_scope( $policy ) ); } }
		return $result;
	}
	public function capture_assignment( OperationIdentity $identity, array $key, RuleTime $at ): PromiseEffectiveAssignment {
		$key = PromiseAssignmentCommand::key( $key );
		if ( 'promise.assignment.read' !== $identity->operation || 1 !== $identity->operation_version || $identity->target_key !== PromiseAssignmentCommand::target_key( $this->binding, $key ) ) { self::refuse(); }
		$scope = [ 'kind' => $key['scope_kind'], 'target_id' => $key['scope_id'] ]; $this->authorize( $identity, $scope );
		$result = $this->owned( $identity, function ( WpdbPromiseRepository $repo ) use ( $key, $at ): PromiseEffectiveAssignment {
			$row = $repo->lock_assignment( PromiseLifecycleOperationProfile::assignment_key( $this->binding, $key ) );
			if ( null === $row ) { return new PromiseEffectiveAssignment( 'absent', null, null, [], $at ); }
			$assignment = PromiseStoredAssignment::from_row( $row, $this->binding ); $repo->accepted_receipt( $assignment->source_receipt(), $row );
			if ( 'assigned' !== $assignment->state() ) { return new PromiseEffectiveAssignment( $assignment->state(), $assignment, null, [], $at ); }
			$ref = $assignment->reference(); $object = $repo->lock_object( 'policy', $ref->policy_id() ); $version = $repo->lock_version( 'policy', $ref->policy_id(), $ref->version() );
			if ( null === $object || null === $version ) { throw new OperationStorageException(); }
			$repo->assert_object_ack( $object ); $stored = PromiseStoredVersion::from_row( $version, $this->binding ); $stored->assert_parent( PromiseStoredObject::from_row( $object, $this->binding ) ); $assignment->assert_policy( $stored );
			$body = $repo->load_policy_versions( [ $ref ] )[0];
			if ( $object['published_version_id'] !== $stored->id() || ! $stored->eligible_at( $at ) ) { return new PromiseEffectiveAssignment( 'unavailable', $assignment, null, [], $at ); }
			$calendars = $repo->load_calendar_versions( $body->calendars() );
			foreach ( $body->calendars() as $calendar_ref ) { $calendar_object = $repo->lock_object( 'calendar', $calendar_ref->calendar_id() ); $calendar_row = $repo->lock_version( 'calendar', $calendar_ref->calendar_id(), $calendar_ref->version() ); if ( null !== $calendar_object ) { $repo->assert_object_ack( $calendar_object ); } if ( null === $calendar_object || null === $calendar_row || $calendar_object['published_version_id'] !== $calendar_row['id'] || ! PromiseStoredVersion::from_row( $calendar_row, $this->binding )->eligible_at( $at ) ) { return new PromiseEffectiveAssignment( 'unavailable', $assignment, null, [], $at ); } }
			return new PromiseEffectiveAssignment( 'assigned', $assignment, $body, $calendars, $at );
		} );
		$this->authorize( $identity, $scope ); return $result;
	}
	private function authorize( OperationIdentity $identity, array $scope ): void { if ( $identity->site_id !== $this->binding->site_id() || true !== $this->authority->authorize( $identity, $this->binding, $scope, 0 ) ) { self::refuse(); } }
	private function owned( OperationIdentity $identity, callable $read ): mixed {
		$session = null;
		try {
			$session = $this->connections->open(); $this->binding->assert_session( $session );
			if ( $session->in_transaction() || $session->is_retired() ) { throw new OperationStorageException(); }
			( $this->readiness ?? new PromiseOperationReadiness() )->assert_ready( $session );
			if ( ! $session->begin() ) { throw new OperationStorageException(); }
			$result = $read( new WpdbPromiseRepository( $session, $this->binding ) );
			if ( ! $session->rollback() || ! $session->retire() ) { throw new OperationStorageException(); } return $result;
		} catch ( OperationRefusal $error ) { throw $error; }
		catch ( \Throwable $error ) { throw new OperationStorageException(); }
		finally { if ( null !== $session && ! $session->is_retired() ) { if ( $session->in_transaction() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} } }
	}
	private static function refuse(): never { throw new OperationRefusal( 'not_authorized', 'contact_support' ); }
}
