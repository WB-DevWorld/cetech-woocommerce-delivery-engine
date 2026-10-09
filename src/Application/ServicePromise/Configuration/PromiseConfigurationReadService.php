<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Application\ServicePromise\Persistence\{PromiseLifecycleOperationProfile, PromiseOperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationRefusal};
use CetechDeliveryEngine\Domain\ServicePromise\{ServicePromisePolicy, PromiseShape};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePersistenceAuthorizer, PromiseSiteBinding, PromiseStoredAssignment, PromiseStoredObject, PromiseStoredVersion, PromiseVersionCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPromiseRepository;

/** Private exact-target editor reads; all disclosure grants run outside its SQL owner. */
final class PromiseConfigurationReadService {
	public function __construct( private OperationConnectionFactory $connections, private PromisePersistenceAuthorizer $authority, private ?OperationReadiness $readiness = null ) {}

	public function version( OperationIdentity $identity, PromiseSiteBinding $binding, string $kind, string $logical_id, int $version, array $scope ): array {
		PromiseShape::integer( $version, 1 ); PromiseShape::choice( $kind, [ 'policy', 'calendar' ] );
		$scope = PromiseVersionCommand::scope( $scope ); if ( 'calendar' === $kind && 'global' !== $scope['kind'] ) { self::deny(); }
		$this->authorize( $identity, $binding, $scope, PromiseVersionCommand::target_key( $binding, $kind, $logical_id ) );
		$result = $this->owned( $binding, static function ( WpdbPromiseRepository $repo ) use ( $binding, $kind, $logical_id, $version, $scope ): array {
			$object = $repo->find_object( $kind, $logical_id );
			if ( null === $object ) { return [ 'object_revision' => 0, 'published_version_id' => 0, 'next_version' => 1, 'version' => null ]; }
			$repo->assert_object_ack( $object ); $head = PromiseStoredObject::from_row( $object, $binding );
			$versions = $repo->lock_versions_for_object( $head->id() );
			if ( count( $versions ) > 1000 ) { throw new OperationStorageException(); }
			foreach ( $versions as $existing ) {
				$previous = PromiseStoredVersion::from_row( $existing, $binding ); $previous->assert_parent( $head );
				if ( $previous->body() instanceof ServicePromisePolicy && PromiseVersionCommand::policy_scope( $previous->body() ) !== $scope ) { self::deny(); }
			}
			$row = $repo->find_version( $kind, $logical_id, $version );
			if ( null === $row ) {
				// The scope of an existing logical object comes from its latest accepted body.
				$latest = $repo->find_version( $kind, $logical_id, $head->row()['last_sequence'] );
				if ( null === $latest ) { throw new OperationStorageException(); }
				$body = PromiseStoredVersion::from_row( $latest, $binding )->body();
				if ( $body instanceof ServicePromisePolicy && PromiseVersionCommand::policy_scope( $body ) !== $scope ) { self::deny(); }
				return [ 'object_revision' => $head->revision(), 'published_version_id' => $object['published_version_id'] ?? 0, 'next_version' => $object['last_sequence'] + 1, 'version' => null ];
			}
			$stored = PromiseStoredVersion::from_row( $row, $binding ); $stored->assert_parent( $head );
			$repo->accepted_receipt( $stored->create_receipt(), $row ); $repo->accepted_receipt( $stored->source_receipt(), $row );
			$body = $stored->body(); if ( $body instanceof ServicePromisePolicy && PromiseVersionCommand::policy_scope( $body ) !== $scope ) { self::deny(); }
			return [ 'object_revision' => $head->revision(), 'published_version_id' => $object['published_version_id'] ?? 0, 'next_version' => $object['last_sequence'] + 1,
				'version' => [ 'domain_version' => $row['domain_version'], 'version_uuid' => $row['version_uuid'], 'body_digest' => $row['body_digest'], 'body_json' => $body->to_private_json(), 'state' => $stored->state(),
					'row_revision' => $stored->revision(), 'declared_from' => $row['declared_from'], 'declared_until' => $row['declared_until'], 'author_user_id' => $row['author_user_id'], 'reason' => $row['reason'],
					'scheduled_author_user_id' => 'scheduled' === $stored->state() ? $stored->source_receipt()->private_facts()['author_user_id'] : null ] ];
		} );
		$this->authorize( $identity, $binding, $scope, PromiseVersionCommand::target_key( $binding, $kind, $logical_id ) ); return $result;
	}

	public function assignment( OperationIdentity $identity, PromiseSiteBinding $binding, array $key ): array {
		$key = PromiseAssignmentCommand::key( $key ); $scope = [ 'kind' => $key['scope_kind'], 'target_id' => $key['scope_id'] ]; $target = PromiseAssignmentCommand::target_key( $binding, $key );
		$this->authorize( $identity, $binding, $scope, $target );
		$result = $this->owned( $binding, static function ( WpdbPromiseRepository $repo ) use ( $binding, $key ): array {
			$row = $repo->find_assignment( PromiseLifecycleOperationProfile::assignment_key( $binding, $key ) );
			if ( null === $row ) { return [ 'revision' => 0, 'generation' => 0, 'mode' => 'inherit', 'policy_reference' => null ]; }
			$stored = PromiseStoredAssignment::from_row( $row, $binding ); $repo->accepted_receipt( $stored->source_receipt(), $row );
			return [ 'revision' => $row['revision'], 'generation' => $row['generation'], 'mode' => $stored->state(), 'policy_reference' => $stored->reference()?->private_facts() ];
		} );
		$this->authorize( $identity, $binding, $scope, $target ); return $result;
	}

	private function authorize( OperationIdentity $identity, PromiseSiteBinding $binding, array $scope, string $target ): void {
		if ( 'promise.configuration.read' !== $identity->operation || 1 !== $identity->operation_version || $target !== $identity->target_key
			|| $identity->site_id !== $binding->site_id() || true !== $this->authority->authorize( $identity, $binding, $scope, 0 ) ) { self::deny(); }
	}
	private function owned( PromiseSiteBinding $binding, callable $read ): mixed {
		$session = null;
		try {
			$session = $this->connections->open(); $binding->assert_session( $session );
			if ( $session->in_transaction() || $session->is_retired() ) { throw new OperationStorageException(); }
			( $this->readiness ?? new PromiseOperationReadiness() )->assert_ready( $session );
			if ( ! $session->begin() ) { throw new OperationStorageException(); }
			$result = $read( new WpdbPromiseRepository( $session, $binding ) );
			if ( ! $session->rollback() || ! $session->retire() ) { throw new OperationStorageException(); } return $result;
		} finally {
			if ( null !== $session && ! $session->is_retired() ) { if ( $session->in_transaction() ) { try { $session->rollback(); } catch ( \Throwable ) {} } try { $session->retire(); } catch ( \Throwable ) {} }
		}
	}
	private static function deny(): never { throw new OperationRefusal( 'not_authorized', 'contact_support' ); }
}
