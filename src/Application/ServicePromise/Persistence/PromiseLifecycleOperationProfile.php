<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Persistence;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationCommand, OperationCompletion, OperationMaterialEvent, OperationMutation, OperationProfile, OperationRefusal, OperationSession, OperationTarget};
use CetechDeliveryEngine\Domain\Operation\OperationSchema;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseCalendarReference, ServicePromisePolicy};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\{PromiseAssignmentCommand, PromisePermissionGrant, PromiseSiteBinding, PromiseSourceReceipt, PromiseStoredAssignment, PromiseStoredObject, PromiseStoredVersion, PromiseVersionCommand};
use CetechDeliveryEngine\Infrastructure\Persistence\{PromiseStorageSchema, WpdbPromiseRepository};

/** Explicit unmounted C03 adopter; parse-only receipt profiles never read current source facts. */
final class PromiseLifecycleOperationProfile implements OperationProfile {
	private ?WpdbPromiseRepository $repository = null;
	private ?OperationSession $owner = null;
	private ?array $object = null;
	private ?array $stored_version = null;
	private ?array $assignment = null;
	private ?array $predecessor = null;
	private function __construct( private string $action, private PromiseVersionCommand|PromiseAssignmentCommand|null $command, private ?PromisePermissionGrant $grant ) {}
	public static function for_command( PromiseVersionCommand|PromiseAssignmentCommand $command, PromisePermissionGrant $grant ): self { return new self( $command->identity->operation, $command, $grant ); }
	public static function receipt_profile( string $operation ): self {
		if ( ! in_array( $operation, [ ...PromiseVersionCommand::OPERATIONS, PromiseAssignmentCommand::OPERATION ], true ) ) { throw new \InvalidArgumentException( 'Unknown promise receipt operation.' ); }
		return new self( $operation, null, null );
	}
	public function operation(): string { return $this->action; }
	public function version(): int { return 1; }
	public function authorize( OperationIdentity $identity ): bool { return null !== $this->command && null !== $this->grant && $this->grant->permits( $identity, $this->command ); }
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand { if ( $command !== $this->command || ! $this->authorize( $identity ) ) { self::refuse( 'not_authorized' ); } return $command; }
	public function transactional_tables( OperationSession $session ): array { return array_values( PromiseStorageSchema::tables( $session->table_prefix() ) ); }
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->command || ! $this->authorize( $identity ) ) { self::refuse( 'not_authorized' ); }
		$binding = $this->command->binding; $binding->assert_session( $session ); $data = $this->command->private_facts(); $repo = new WpdbPromiseRepository( $session, $binding ); $this->repository = $repo; $this->owner = $session;
		if ( $command instanceof PromiseAssignmentCommand ) {
			$row = $repo->lock_assignment( self::assignment_key( $binding, $data['key'] ), 0 === $data['expected_revision'] );
			if ( null === $row ) { self::refuse( 'stale_revision' ); }
			$new = 0 === $data['expected_revision'];
			if ( ( $new && ( 1 !== $row['revision'] || 0 !== $row['generation'] ) ) || ( ! $new && ( $row['revision'] !== $data['expected_revision'] || $row['generation'] !== $data['expected_generation'] ) ) ) { self::refuse( 'stale_revision' ); }
			$this->assignment = $row;
			return new OperationTarget( new OperationSchema( [ 'id' => 'positive_int', 'revision' => 'positive_int' ] ), [ 'id' => $row['id'], 'revision' => $row['revision'] ] );
		}
		$object = $repo->lock_object( $data['kind'], $data['logical_id'] );
		if ( null === $object && 'promise.version.create' === $this->action && 0 === $data['preconditions']['object_revision'] ) {
			$at = $repo->accepted_time(); $id = $repo->insert_object( [ 'kind' => $data['kind'], 'logical_id' => $data['logical_id'], 'revision' => 1, 'last_sequence' => 0, 'draft_version_id' => null, 'scheduled_version_id' => null, 'published_version_id' => null, 'latest_version_id' => null, 'latest_source_receipt_digest' => null, 'created_at' => $at->sql(), 'updated_at' => $at->sql() ] );
			$object = $repo->lock_object( $data['kind'], $data['logical_id'] ); if ( null === $object || $object['id'] !== $id || 1 !== $object['revision'] || 0 !== $object['last_sequence'] ) { throw new OperationStorageException(); }
		} elseif ( null === $object || $object['revision'] !== $data['preconditions']['object_revision'] ) { self::refuse( 'stale_revision' ); }
		$repo->assert_object_ack( $object );
		if ( ( $object['published_version_id'] ?? 0 ) !== $data['preconditions']['published_version_id'] ) { self::refuse( 'stale_revision' ); }
		$versions = $repo->lock_versions_for_object( $object['id'] ); if ( count( $versions ) > 1000 ) { self::refuse( 'temporarily_unavailable' ); }
		foreach ( $versions as $row ) { PromiseStoredVersion::from_row( $row, $binding )->assert_parent( PromiseStoredObject::from_row( $object, $binding ) ); }
		$version = $repo->lock_version( $data['kind'], $data['logical_id'], $data['domain_version'] );
		if ( 'promise.version.create' === $this->action ) {
			if ( null !== $version || null !== $object['draft_version_id'] || null !== $object['scheduled_version_id'] || $data['domain_version'] !== $object['last_sequence'] + 1 ) { self::refuse( 'stale_revision' ); }
		} else {
			if ( null === $version || $version['row_revision'] !== $data['preconditions']['version_revision'] || $version['version_uuid'] !== $data['version_uuid'] || $version['body_digest'] !== $data['body_digest'] || $version['declared_from'] !== $data['declared_from'] || $version['declared_until'] !== $data['declared_until']  ) { self::refuse( 'stale_revision' ); }
			$body = PromiseStoredVersion::from_row( $version, $binding )->body(); if ( $body instanceof ServicePromisePolicy && PromiseVersionCommand::policy_scope( $body ) !== $data['scope'] ) { self::refuse( 'stale_revision' ); }
		}
		$this->object = $object; $this->stored_version = $version;
		if ( null !== $object['published_version_id'] ) { foreach ( $versions as $row ) { if ( $row['id'] === $object['published_version_id'] ) { $this->predecessor = $row; } } if ( null === $this->predecessor || 'published' !== $this->predecessor['state'] ) { throw new OperationStorageException(); } }
		return new OperationTarget( new OperationSchema( [ 'id' => 'positive_int', 'revision' => 'positive_int' ] ), [ 'id' => $object['id'], 'revision' => $object['revision'] ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $context ): OperationMutation {
		if ( $command !== $this->command || ! $this->authorize( $identity ) || null === $this->repository || $session !== $this->owner || $session->is_retired() || ! $session->in_transaction() ) { self::refuse( 'not_authorized' ); }
		return $command instanceof PromiseAssignmentCommand ? $this->mutate_assignment( $identity, $target, $context ) : $this->mutate_version( $identity, $target, $context );
	}
	private function mutate_version( OperationIdentity $identity, OperationTarget $target, RequestContext $context ): OperationMutation {
		$data = $this->command->private_facts(); $repo = $this->repository; $object = $this->object; $version = $this->stored_version; $at = $repo->accepted_time();
		if ( $target->facts['id'] !== $object['id'] || $target->facts['revision'] !== $object['revision'] ) { throw new OperationStorageException(); }
		self::assert_monotonic( $at, $object['updated_at'], $version['source_receipt_json'] ?? null );
		$before_version = $version['row_revision'] ?? 0; $after_version = $before_version + 1; $state = self::state_for( $this->action ); $calendar_publications = [];
		if ( 'promise.version.create' !== $this->action ) {
			if ( 'promise.version.retire' === $this->action ) { $calendar_publications = PromiseStoredVersion::from_row( $version, $this->command->binding )->source_receipt()->private_facts()['calendar_publications']; }
			$repo->accepted_receipt( PromiseStoredVersion::from_row( $version, $this->command->binding )->source_receipt(), $version );
			$expected = match ( $this->action ) { 'promise.version.seal' => 'draft', 'promise.version.publish', 'promise.version.schedule' => 'sealed', 'promise.version.activate' => 'scheduled', default => $version['state'] };
			if ( $expected !== $version['state'] || 'retired' === $version['state'] ) { self::refuse( 'stale_revision' ); }
			if ( in_array( $this->action, [ 'promise.version.seal', 'promise.version.publish', 'promise.version.schedule', 'promise.version.activate' ], true ) ) {
				$calendar_publications = $this->calendar_publications( PromiseStoredVersion::from_row( $version, $this->command->binding ), $at );
			}
			if ( 'promise.version.schedule' === $this->action && RuleTime::parse( $version['declared_from'] )->compare( $at ) <= 0 ) { self::refuse( 'invalid_input' ); }
			if ( in_array( $this->action, [ 'promise.version.publish', 'promise.version.activate' ], true ) && ( RuleTime::parse( $version['declared_from'] )->compare( $at ) > 0 || ( null !== $version['declared_until'] && $at->compare( RuleTime::parse( $version['declared_until'] ) ) >= 0 ) ) ) { self::refuse( 'invalid_input' ); }
			if ( 'promise.version.activate' === $this->action && ( $version['schedule_expected_object_revision'] !== $object['revision'] || ( $version['schedule_expected_published_version_id'] ?? 0 ) !== ( $object['published_version_id'] ?? 0 ) || ! $this->grant->original_author_allowed() || PromiseStoredVersion::from_row( $version, $this->command->binding )->source_receipt()->private_facts()['author_user_id'] !== $data['scheduled_author_user_id'] ) ) { self::refuse( 'stale_revision' ); }
		}
		$receipt = $this->receipt( $identity, $at, $object['revision'], $object['revision'] + 1, [ 'kind' => 'version', 'role' => 'primary', 'object_id' => $object['id'], 'version_uuid' => $data['version_uuid'], 'domain_version' => $data['domain_version'], 'content_digest' => $data['body_digest'], 'logical_digest' => PromiseStoredObject::identity_digest( $this->command->binding->site_id(), $this->command->binding->site_key(), $data['kind'], $data['logical_id'] ), 'version_before_revision' => $before_version, 'version_after_revision' => $after_version, 'state' => $state, 'declared_from' => $data['declared_from'], 'declared_until' => $data['declared_until'], 'calendar_publications' => $calendar_publications ] );
		$predecessor_receipt = null;
		$logical_values = [ 'revision' => $object['revision'] + 1, 'updated_at' => $at->sql() ];
		if ( 'promise.version.create' === $this->action ) {
			$id = $repo->insert_version( $object, [ 'object_id' => $object['id'], 'kind' => $data['kind'], 'logical_id' => $data['logical_id'], 'version_uuid' => $data['version_uuid'], 'format_version' => 1, 'domain_version' => $data['domain_version'], 'row_revision' => 1, 'state' => 'draft', 'body_json' => $data['body_json'], 'body_digest' => $data['body_digest'], 'declared_from' => $data['declared_from'], 'declared_until' => $data['declared_until'], 'created_at' => $at->sql(), 'sealed_at' => null, 'scheduled_at' => null, 'published_at' => null, 'retired_at' => null, 'author_user_id' => $data['author_user_id'], 'reason' => $data['reason'], 'predecessor_version_id' => $object['published_version_id'], 'schedule_expected_object_revision' => null, 'schedule_expected_published_version_id' => null, 'create_receipt_json' => $receipt->to_private_json(), 'create_receipt_digest' => $receipt->digest(), 'publication_receipt_json' => null, 'publication_receipt_digest' => null, 'source_receipt_json' => $receipt->to_private_json(), 'source_receipt_digest' => $receipt->digest() ] );
			$logical_values += [ 'last_sequence' => $data['domain_version'], 'draft_version_id' => $id ];
		} else {
			$id = $version['id']; $values = [ 'row_revision' => $after_version, 'state' => $state, 'source_receipt_json' => $receipt->to_private_json(), 'source_receipt_digest' => $receipt->digest() ];
			if ( 'promise.version.seal' === $this->action ) { $values['sealed_at'] = $at->sql(); }
			elseif ( 'promise.version.schedule' === $this->action ) { $values += [ 'scheduled_at' => $at->sql(), 'schedule_expected_object_revision' => $object['revision'] + 1, 'schedule_expected_published_version_id' => $object['published_version_id'] ]; $logical_values += [ 'draft_version_id' => null, 'scheduled_version_id' => $id ]; }
			elseif ( in_array( $this->action, [ 'promise.version.publish', 'promise.version.activate' ], true ) ) {
				$values += [ 'published_at' => $at->sql(), 'publication_receipt_json' => $receipt->to_private_json(), 'publication_receipt_digest' => $receipt->digest() ]; $logical_values += [ 'draft_version_id' => null, 'scheduled_version_id' => null, 'published_version_id' => $id ];
				if ( null !== $this->predecessor ) {
					$old = $this->predecessor;
					if ( $old['id'] === $id || $version['predecessor_version_id'] !== $old['id'] ) { self::refuse( 'stale_revision' ); }
					self::assert_monotonic( $at, $old['published_at'], $old['source_receipt_json'] );
					$old_stored = PromiseStoredVersion::from_row( $old, $this->command->binding ); $repo->accepted_receipt( $old_stored->source_receipt(), $old );
					$predecessor_receipt = $this->receipt( $identity, $at, $object['revision'], $object['revision'] + 1, [ 'kind' => 'version', 'role' => 'superseded', 'object_id' => $object['id'], 'version_uuid' => $old['version_uuid'], 'domain_version' => $old['domain_version'], 'content_digest' => $old['body_digest'], 'logical_digest' => PromiseStoredObject::identity_digest( $this->command->binding->site_id(), $this->command->binding->site_key(), $old['kind'], $old['logical_id'] ), 'version_before_revision' => $old['row_revision'], 'version_after_revision' => $old['row_revision'] + 1, 'state' => 'retired', 'declared_from' => $old['declared_from'], 'declared_until' => $old['declared_until'], 'calendar_publications' => $old_stored->source_receipt()->private_facts()['calendar_publications'] ] );
					$repo->update_version( $old, [ 'row_revision' => $old['row_revision'] + 1, 'state' => 'retired', 'retired_at' => $at->sql(), 'source_receipt_json' => $predecessor_receipt->to_private_json(), 'source_receipt_digest' => $predecessor_receipt->digest() ] );
				} elseif ( null !== $version['predecessor_version_id'] ) { self::refuse( 'stale_revision' ); }
			} else { $values['retired_at'] = $at->sql(); foreach ( [ 'draft_version_id', 'scheduled_version_id', 'published_version_id' ] as $pointer ) { if ( $object[$pointer] === $id ) { $logical_values[$pointer] = null; } } }
			$repo->update_version( $version, $values );
		}
		$logical_values += [ 'latest_version_id' => $id, 'latest_source_receipt_digest' => $receipt->digest() ];
		$repo->update_object( $object, $logical_values );
		$has_published = 'published' === $state || null !== ( $version['published_at'] ?? null ); $published_at = 'published' === $state ? $at : ( $has_published ? RuleTime::parse( $version['published_at'] ) : null );
		$result = [ 'kind' => $data['kind'], 'site_digest' => hash( 'sha256', $this->command->binding->site_key() ), 'logical_digest' => PromiseStoredObject::identity_digest( $this->command->binding->site_id(), $this->command->binding->site_key(), $data['kind'], $data['logical_id'] ), 'object_id' => $object['id'], 'object_before_revision' => $object['revision'], 'object_revision' => $object['revision'] + 1, 'version_id' => $id, 'version_uuid' => $data['version_uuid'], 'domain_version' => $data['domain_version'], 'version_before_revision' => $before_version, 'version_revision' => $after_version, 'state' => $state, 'body_digest' => $data['body_digest'], 'source_receipt_hash' => $receipt->digest(), 'accepted_at' => $at->epoch_microseconds(), 'declared_from' => RuleTime::parse( $data['declared_from'] )->epoch_microseconds(), 'has_until' => null !== $data['declared_until'], 'declared_until' => null === $data['declared_until'] ? 0 : RuleTime::parse( $data['declared_until'] )->epoch_microseconds(), 'has_published' => $has_published, 'published_at' => $published_at?->epoch_microseconds() ?? 0, 'author_user_id' => $data['author_user_id'] ];
		$new_head = array_replace( $object, $logical_values ); $result['object_head'] = [ 'revision' => $new_head['revision'], 'last_sequence' => $new_head['last_sequence'], 'draft_version_id' => $new_head['draft_version_id'] ?? 0, 'scheduled_version_id' => $new_head['scheduled_version_id'] ?? 0, 'published_version_id' => $new_head['published_version_id'] ?? 0, 'latest_version_id' => $id ];
		$result['version_guards'] = [ 'predecessor_version_id' => 'promise.version.create' === $this->action ? ( $object['published_version_id'] ?? 0 ) : ( $version['predecessor_version_id'] ?? 0 ), 'schedule_expected_object_revision' => 'promise.version.schedule' === $this->action ? $object['revision'] + 1 : ( $version['schedule_expected_object_revision'] ?? 0 ), 'schedule_expected_published_version_id' => 'promise.version.schedule' === $this->action ? ( $object['published_version_id'] ?? 0 ) : ( $version['schedule_expected_published_version_id'] ?? 0 ) ];
		$result += [ 'has_predecessor' => null !== $predecessor_receipt, 'predecessor_version_id' => $predecessor_receipt ? $this->predecessor['id'] : 0, 'predecessor_version_uuid' => $predecessor_receipt ? $this->predecessor['version_uuid'] : '00000000-0000-4000-8000-000000000000', 'predecessor_domain_version' => $predecessor_receipt ? $this->predecessor['domain_version'] : 0, 'predecessor_version_before_revision' => $predecessor_receipt ? $this->predecessor['row_revision'] : 0, 'predecessor_version_revision' => $predecessor_receipt ? $this->predecessor['row_revision'] + 1 : 0, 'predecessor_body_digest' => $predecessor_receipt ? $this->predecessor['body_digest'] : hash( 'sha256', '' ), 'predecessor_source_receipt_hash' => $predecessor_receipt?->digest() ?? hash( 'sha256', '' ) ];
		return $this->material( $identity, $context, $result, $object['revision'], $object['revision'] + 1 );
	}
	private function mutate_assignment( OperationIdentity $identity, OperationTarget $target, RequestContext $context ): OperationMutation {
		$data = $this->command->private_facts(); $repo = $this->repository; $row = $this->assignment; $at = $repo->accepted_time(); $publication = null; $policy = null; $object = null; self::assert_monotonic( $at, $row['updated_at'] );
		if ( $target->facts['id'] !== $row['id'] || $target->facts['revision'] !== $row['revision'] ) { throw new OperationStorageException(); }
		if ( null !== $row['source_receipt_json'] ) { $repo->accepted_receipt( PromiseStoredAssignment::from_row( $row, $this->command->binding )->source_receipt(), $row ); }
		if ( 'assigned' === $data['mode'] ) {
			$ref = $data['policy_reference']; $object = $repo->lock_object( 'policy', $ref['policy_id'] ); $policy = $repo->lock_version( 'policy', $ref['policy_id'], $ref['version'] );
			if ( null === $object || null === $policy ) { self::refuse( 'stale_revision' ); } $stored = PromiseStoredVersion::from_row( $policy, $this->command->binding ); $stored->assert_parent( PromiseStoredObject::from_row( $object, $this->command->binding ) );
			$repo->assert_object_ack( $object ); $body = $stored->body(); $key = $data['key']; if ( $stored->reference()->private_facts() !== $ref || ! $this->eligible( $policy, $object, $at ) || ! $body instanceof ServicePromisePolicy || PromiseVersionCommand::policy_scope( $body ) !== [ 'kind' => $key['scope_kind'], 'target_id' => $key['scope_id'] ] || $body->service()->private_facts()['kind'] !== $key['service_kind'] || $body->service()->private_facts()['code'] !== $key['service_code'] || $body->endpoint() !== $key['endpoint'] || $body->endpoint_kind() !== $key['endpoint_kind'] ) { self::refuse( 'stale_revision' ); }
			$pub = $stored->publication_receipt(); $repo->accepted_receipt( $pub, $policy ); $publication = [ 'reference' => $ref, 'publication_digest' => $pub->digest(), 'published_at' => $policy['published_at'] ]; $this->calendar_publications( $stored, $at );
		}
		$receipt = $this->receipt( $identity, $at, $row['revision'], $row['revision'] + 1, [ 'kind' => 'assignment', 'assignment_key_hash' => PromiseStoredAssignment::key_digest( self::assignment_key( $this->command->binding, $data['key'] ) ), 'mode' => $data['mode'], 'generation_before' => $row['generation'], 'generation_after' => $row['generation'] + 1, 'policy_publication' => $publication ] );
		$repo->update_assignment( $row, [ 'revision' => $row['revision'] + 1, 'generation' => $row['generation'] + 1, 'state' => $data['mode'], 'policy_object_id' => $object['id'] ?? null, 'policy_version_id' => $policy['id'] ?? null, 'policy_reference_json' => null === $data['policy_reference'] ? null : \CetechDeliveryEngine\Domain\ServicePromise\PromisePolicyReference::from_array( $data['policy_reference'] )->to_private_json(), 'updated_at' => $at->sql(), 'source_receipt_json' => $receipt->to_private_json(), 'source_receipt_digest' => $receipt->digest() ] );
		$result = [ 'site_digest' => hash( 'sha256', $this->command->binding->site_key() ), 'assignment_key_hash' => PromiseStoredAssignment::key_digest( self::assignment_key( $this->command->binding, $data['key'] ) ), 'assignment_id' => $row['id'], 'before_revision' => $row['revision'], 'revision' => $row['revision'] + 1, 'generation_before' => $row['generation'], 'generation' => $row['generation'] + 1, 'state' => $data['mode'], 'policy_object_id' => $object['id'] ?? 0, 'policy_version_id' => $policy['id'] ?? 0, 'policy_content_digest' => $policy['body_digest'] ?? hash( 'sha256', '' ), 'source_receipt_hash' => $receipt->digest(), 'accepted_at' => $at->epoch_microseconds(), 'author_user_id' => $data['author_user_id'] ];
		return $this->material( $identity, $context, $result, $row['revision'], $row['revision'] + 1 );
	}
	private function calendar_publications( PromiseStoredVersion $stored, RuleTime $at ): array {
		$body = $stored->body(); if ( ! $body instanceof ServicePromisePolicy ) { return []; } $out = []; $refs = $body->calendars(); usort( $refs, static fn( PromiseCalendarReference $a, PromiseCalendarReference $b ): int => strcmp( $a->calendar_id(), $b->calendar_id() ) );
		foreach ( $refs as $ref ) {
			$object = $this->repository->lock_object( 'calendar', $ref->calendar_id() ); $row = $this->repository->lock_version( 'calendar', $ref->calendar_id(), $ref->version() );
			if ( null === $object || null === $row || ! $this->eligible( $row, $object, $at ) ) { self::refuse( 'stale_revision' ); }
			$this->repository->assert_object_ack( $object ); $version = PromiseStoredVersion::from_row( $row, $this->command->binding ); $version->assert_parent( PromiseStoredObject::from_row( $object, $this->command->binding ) );
			if ( $version->reference()->to_private_json() !== $ref->to_private_json() ) { self::refuse( 'stale_revision' ); } $publication = $version->publication_receipt(); $this->repository->accepted_receipt( $publication, $row );
			$out[] = [ 'reference' => $ref->private_facts(), 'publication_digest' => $publication->digest(), 'published_at' => $row['published_at'] ];
		} return $out;
	}
	private function eligible( array $version, array $object, RuleTime $at ): bool { return 'published' === $version['state'] && $object['published_version_id'] === $version['id'] && null !== $version['published_at'] && $at->compare( RuleTime::parse( $version['published_at'] ) ) >= 0 && $at->compare( RuleTime::parse( $version['declared_from'] ) ) >= 0 && ( null === $version['declared_until'] || $at->compare( RuleTime::parse( $version['declared_until'] ) ) < 0 ); }
	private function receipt( OperationIdentity $identity, RuleTime $at, int $before, int $after, array $specific ): PromiseSourceReceipt {
		$data = $this->command->private_facts(); return PromiseSourceReceipt::from_array( [ 'format_version' => 1, 'site_id' => $identity->site_id, 'site_key' => $this->command->binding->site_key(), 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $this->command->intent()->fingerprint(), 'operation' => $this->action, 'target_digest' => hash( 'sha256', 'cetech-operation-target-v1:' . $identity->target_key ), 'accepted_at' => $at->sql(), 'author_user_id' => $data['author_user_id'], 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ), 'reason' => $data['reason'], 'before_revision' => $before, 'after_revision' => $after ] + $specific );
	}
	private function material( OperationIdentity $identity, RequestContext $context, array $result, int $before, int $after ): OperationMutation {
		$data = $this->command->private_facts(); $event = OperationMaterialEvent::from_mutation( $this, $identity, $context, [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ), 'author_user_id' => $data['author_user_id'] ], $result, $this->reason(), $before, $after, $this->fields( $result['has_predecessor'] ?? false ) ); return OperationMutation::changed( OperationCompletion::accepted( $this, $result ), $event );
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool { return false; }
	public function publication_schema(): ?OperationSchema { return null; }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'authority_hash' => 'sha256', 'principal_hash' => 'sha256', 'author_user_id' => 'positive_int' ] ); }
	public function result_schema(): OperationSchema {
		if ( PromiseAssignmentCommand::OPERATION === $this->action ) { return new OperationSchema( [ 'site_digest' => 'sha256', 'assignment_key_hash' => 'sha256', 'assignment_id' => 'positive_int', 'before_revision' => 'positive_int', 'revision' => 'positive_int', 'generation_before' => 'nonnegative_int', 'generation' => 'positive_int', 'state' => [ 'enum' => [ 'assigned', 'inherit', 'disabled' ] ], 'policy_object_id' => 'nonnegative_int', 'policy_version_id' => 'nonnegative_int', 'policy_content_digest' => 'sha256', 'source_receipt_hash' => 'sha256', 'accepted_at' => 'integer', 'author_user_id' => 'positive_int' ] ); }
		return new OperationSchema( [ 'kind' => [ 'enum' => [ 'calendar', 'policy' ] ], 'site_digest' => 'sha256', 'logical_digest' => 'sha256', 'object_id' => 'positive_int', 'object_before_revision' => 'positive_int', 'object_revision' => 'positive_int', 'version_id' => 'positive_int', 'version_uuid' => 'uuid', 'domain_version' => 'positive_int', 'version_before_revision' => 'nonnegative_int', 'version_revision' => 'positive_int', 'state' => [ 'enum' => [ 'draft', 'sealed', 'scheduled', 'published', 'retired' ] ], 'body_digest' => 'sha256', 'source_receipt_hash' => 'sha256', 'accepted_at' => 'integer', 'declared_from' => 'integer', 'has_until' => 'bool', 'declared_until' => 'integer', 'has_published' => 'bool', 'published_at' => 'integer', 'author_user_id' => 'positive_int', 'has_predecessor' => 'bool', 'predecessor_version_id' => 'nonnegative_int', 'predecessor_version_uuid' => 'uuid', 'predecessor_domain_version' => 'nonnegative_int', 'predecessor_version_before_revision' => 'nonnegative_int', 'predecessor_version_revision' => 'nonnegative_int', 'predecessor_body_digest' => 'sha256', 'predecessor_source_receipt_hash' => 'sha256', 'object_head' => [ 'object' => [ 'revision' => 'positive_int', 'last_sequence' => 'positive_int', 'draft_version_id' => 'nonnegative_int', 'scheduled_version_id' => 'nonnegative_int', 'published_version_id' => 'nonnegative_int', 'latest_version_id' => 'positive_int' ] ], 'version_guards' => [ 'object' => [ 'predecessor_version_id' => 'nonnegative_int', 'schedule_expected_object_revision' => 'nonnegative_int', 'schedule_expected_published_version_id' => 'nonnegative_int' ] ] ] );
	}
	public function target_schema(): OperationSchema { return $this->result_schema(); }
	public function reason_codes(): array { return [ 'promise_created', 'promise_sealed', 'promise_published', 'promise_scheduled', 'promise_activated', 'promise_retired', 'promise_assigned' ]; }
	public function changed_fields(): array { return [ 'immutable_version', 'seal', 'publication', 'schedule', 'retirement', 'head', 'assignment' ]; }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		if ( 'accepted' !== $completion->state || null === $completion->result || $completion->result !== $event->target || $event->reason_code !== $this->reason() || $event->changed_fields !== $this->fields( $completion->result['has_predecessor'] ?? false ) || $event->after_revision !== $event->before_revision + 1 || $event->actor['author_user_id'] !== $completion->result['author_user_id'] ) { return false; }
		$r = $completion->result;
		if ( PromiseAssignmentCommand::OPERATION === $this->action ) { if ( $r['before_revision'] !== $event->before_revision || $r['revision'] !== $event->after_revision || $r['generation'] !== $r['generation_before'] + 1 || ( 'assigned' === $r['state'] ) !== ( $r['policy_object_id'] > 0 && $r['policy_version_id'] > 0 ) ) { return false; } }
		else {
			if ( 'promise.version.schedule' === $this->action && $r['version_guards']['schedule_expected_object_revision'] !== $r['object_revision'] || 'promise.version.activate' === $this->action && $r['version_guards']['schedule_expected_object_revision'] !== $r['object_before_revision'] || $r['has_predecessor'] && $r['version_guards']['predecessor_version_id'] !== $r['predecessor_version_id'] ) { return false; }
			if ( $r['object_head']['revision'] !== $r['object_revision'] || $r['object_head']['latest_version_id'] !== $r['version_id'] || $r['object_head']['last_sequence'] < $r['domain_version'] ) { return false; }
			if ( $r['has_predecessor'] && ( ! in_array( $this->action, [ 'promise.version.publish', 'promise.version.activate' ], true ) || $r['predecessor_version_id'] <= 0 || $r['predecessor_version_id'] === $r['version_id'] || $r['predecessor_domain_version'] <= 0 || $r['predecessor_version_before_revision'] <= 0 || $r['predecessor_version_revision'] !== $r['predecessor_version_before_revision'] + 1 ) || ! $r['has_predecessor'] && ( $r['predecessor_version_id'] !== 0 || $r['predecessor_domain_version'] !== 0 || $r['predecessor_version_before_revision'] !== 0 || $r['predecessor_version_revision'] !== 0 || $r['predecessor_body_digest'] !== hash( 'sha256', '' ) || $r['predecessor_source_receipt_hash'] !== hash( 'sha256', '' ) ) ) { return false; }
			if ( $r['state'] !== self::state_for( $this->action ) || $r['object_before_revision'] !== $event->before_revision || $r['object_revision'] !== $event->after_revision || $r['version_revision'] !== $r['version_before_revision'] + 1 || ( 'promise.version.create' === $this->action ) !== ( 0 === $r['version_before_revision'] ) || ( ! $r['has_until'] && 0 !== $r['declared_until'] ) || ( $r['has_until'] && $r['declared_until'] <= $r['declared_from'] ) || ( ! $r['has_published'] && 0 !== $r['published_at'] ) || ( in_array( $this->action, [ 'promise.version.publish', 'promise.version.activate' ], true ) && ( ! $r['has_published'] || $r['published_at'] !== $r['accepted_at'] || $r['accepted_at'] < $r['declared_from'] || $r['has_until'] && $r['accepted_at'] >= $r['declared_until'] ) ) || ( 'promise.version.schedule' === $this->action && $r['accepted_at'] >= $r['declared_from'] ) ) { return false; }
		}
		if ( null !== $this->command ) { $data = $this->command->private_facts(); if ( $r['site_digest'] !== hash( 'sha256', $this->command->binding->site_key() ) || $event->actor['authority_hash'] !== hash( 'sha256', $this->command->identity->authority ) || $event->actor['principal_hash'] !== hash( 'sha256', $this->command->identity->principal ) ) { return false; } }
		return true;
	}
	private static function state_for( string $operation ): string { return match ( $operation ) { 'promise.version.create' => 'draft', 'promise.version.seal' => 'sealed', 'promise.version.schedule' => 'scheduled', 'promise.version.publish', 'promise.version.activate' => 'published', default => 'retired' }; }
	private function reason(): string { return match ( $this->action ) { 'promise.version.create' => 'promise_created', 'promise.version.seal' => 'promise_sealed', 'promise.version.publish' => 'promise_published', 'promise.version.schedule' => 'promise_scheduled', 'promise.version.activate' => 'promise_activated', 'promise.version.retire' => 'promise_retired', default => 'promise_assigned' }; }
	private function fields( bool $predecessor = false ): array { return match ( $this->action ) { 'promise.version.create' => [ 'immutable_version', 'head' ], 'promise.version.seal' => [ 'seal' ], 'promise.version.schedule' => [ 'schedule', 'head' ], 'promise.version.publish', 'promise.version.activate' => $predecessor ? [ 'publication', 'retirement', 'head' ] : [ 'publication', 'head' ], 'promise.version.retire' => [ 'retirement', 'head' ], default => [ 'assignment' ] }; }
	public static function assignment_key( PromiseSiteBinding $binding, array $key ): array { return [ 'site_id' => $binding->site_id(), 'site_key' => $binding->site_key(), ...PromiseAssignmentCommand::key( $key ) ]; }
	private static function assert_monotonic( RuleTime $at, string $updated, ?string $receipt_json = null ): void { if ( $at->compare( RuleTime::parse( $updated ) ) < 0 || ( null !== $receipt_json && $at->compare( PromiseSourceReceipt::from_json( $receipt_json )->accepted_at() ) < 0 ) ) { self::refuse( 'temporarily_unavailable' ); } }
	private static function refuse( string $code ): never { throw new OperationRefusal( $code, 'not_authorized' === $code ? 'contact_support' : 'reload_and_submit' ); }
}
