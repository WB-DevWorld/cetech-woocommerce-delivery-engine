<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\RuleLifecycle;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Application\Operation\OperationUnconfirmedException;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationMaterialEvent;
use CetechDeliveryEngine\Domain\Operation\OperationMutation;
use CetechDeliveryEngine\Domain\Operation\OperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationRefusal;
use CetechDeliveryEngine\Domain\Operation\OperationSchema;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\Operation\OperationTarget;
use CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleEvaluator;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleStartMode;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleState;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbRuleLifecycleRepository;

/** One explicitly validated scope-bound command, never a global callback/adopter. */
final class RuleLifecycleOperationProfile implements OperationProfile {
	private ?OperationSession $owner = null;
	private ?array $locked = null;
	public function __construct( private readonly RuleLifecycleCommand $command ) {}
	public function operation(): string { return $this->command->identity->operation; }
	public function version(): int { return 1; }
	public function authorize( OperationIdentity $identity ): bool {
		if ( $identity->namespace_digest() !== $this->command->identity->namespace_digest() ) { return false; }
		if ( 'rule.activate' === $identity->operation && ( 'rule.lifecycle.activation.v1' !== $identity->authority || 'rule-activation:' . $this->command->version_uuid !== $identity->principal ) ) { return false; }
		return $this->command->family->authorize( $identity, $this->command->scope );
	}
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		if ( ! $command instanceof RuleLifecycleCommand || $identity->namespace_digest() !== $command->identity->namespace_digest() || ! $command->intent()->equals( $this->command->intent() ) ) { throw new \InvalidArgumentException( 'Invalid bound rule lifecycle command.' ); }
		return $command;
	}
	public function transactional_tables( OperationSession $session ): array { return RuleLifecycleSchema::tables( $session->table_prefix() ); }
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->command || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$repo = new WpdbRuleLifecycleRepository( $session ); $c = $this->command;
		try {
			$guard_row = $repo->lock_family( $c->family->family(), $c->family->policy_hash(), 'rule.draft.create' === $identity->operation );
			if ( null === $guard_row ) { $this->refuse( 'stale_revision' ); }
			$guard = RuleFamilyGuard::from_row( $guard_row, $c->family );
			$logical_rows = $repo->lock_logicals_for_family( $guard->id );
			if ( count( $logical_rows ) > 1000 ) { $this->refuse( 'temporarily_unavailable' ); }
			$logicals = []; $logical = null;
			foreach ( $logical_rows as $row ) { $item = LogicalRule::from_row( $row, $guard, $c->family ); $logicals[$item->id] = $item; if ( $item->logical_uuid === $c->logical_uuid ) { $logical = $item; } }
			if ( null !== $logical && ( $logical->scope_hash !== $c->scope_hash || $logical->site_id !== $identity->site_id ) ) { $this->refuse( 'stale_revision' ); }
			if ( ! $c->family->authorize( $identity, $logical?->scope ?? $c->scope ) ) { $this->refuse( 'not_authorized' ); }
			$version_rows = $repo->lock_versions_for_family( $guard->id, 1003, $c->version_uuid, $c->predecessor_uuid );
			if ( count( $version_rows ) >= 1003 ) { $this->refuse( 'temporarily_unavailable' ); }
			$versions = []; $version = null; $predecessor = null;
			foreach ( $version_rows as $row ) {
				if ( ! isset( $logicals[(int) $row['logical_rule_id']] ) ) { throw new OperationStorageException(); }
				$item = RuleVersion::from_row( $row, $logicals[(int) $row['logical_rule_id']], $c->family );
				if ( in_array( $item->state, [ RuleState::Published, RuleState::Scheduled ], true ) ) { $versions[$item->id] = $item; }
				if ( ( RuleState::Published === $item->state && $logicals[$item->logical_rule_id]->current_published_version_id !== $item->id ) || ( RuleState::Scheduled === $item->state && $logicals[$item->logical_rule_id]->scheduled_version_id !== $item->id ) ) { throw new OperationUnconfirmedException(); }
				if ( $item->version_uuid === $c->version_uuid ) { $version = $item; }
				if ( $item->version_uuid === $c->predecessor_uuid ) { $predecessor = $item; }
			}
			if ( count( $versions ) > 1000 || ( null !== $version && ( null === $logical || $version->logical_rule_id !== $logical->id ) ) || ( null !== $c->predecessor_uuid && ( null === $predecessor || null === $logical || $predecessor->logical_rule_id !== $logical->id ) ) ) { $this->refuse( count( $versions ) > 1000 ? 'temporarily_unavailable' : 'stale_revision' ); }
			foreach ( $logicals as $item ) { foreach ( [ 'current_published_version_id' => RuleState::Published, 'scheduled_version_id' => RuleState::Scheduled ] as $pointer => $state ) { $id = $item->$pointer; if ( null !== $id && ( ! isset( $versions[$id] ) || $versions[$id]->state !== $state || $versions[$id]->logical_rule_id !== $item->id ) ) { throw new OperationUnconfirmedException(); } } }
		} catch ( OperationRefusal $e ) { throw $e; }
		catch ( \InvalidArgumentException ) { throw new OperationUnconfirmedException(); }
		$p = $c->preconditions;
		if ( 'rule.activate' !== $identity->operation && $guard->revision !== $p['family_revision'] && ! ( 'rule.draft.create' === $identity->operation && 0 === $p['family_revision'] && 1 === $guard->revision && [] === $logical_rows ) ) { $this->refuse( 'stale_revision' ); }
		if ( ( $logical?->id ?? 0 ) !== $p['logical_id'] || ( $logical?->revision ?? 0 ) !== $p['logical_revision'] || ( $version?->id ?? 0 ) !== $p['version_id'] || ( $version?->row_revision ?? 0 ) !== $p['version_revision'] || ( $predecessor?->id ?? 0 ) !== $p['predecessor_id'] || ( $predecessor?->row_revision ?? 0 ) !== $p['predecessor_revision'] ) { $this->refuse( 'stale_revision' ); }
		if ( in_array( $identity->operation, [ 'rule.schedule', 'rule.publish', 'rule.activate' ], true ) && ( $logical?->current_published_version_id ?? 0 ) !== $p['predecessor_id'] ) { $this->refuse( 'stale_revision' ); }
		if ( 'rule.activate' === $identity->operation && ( null === $version || RuleState::Scheduled !== $version->state || $version->scheduled_revision !== $p['version_revision'] || $version->scheduled_logical_revision !== $p['logical_revision'] || ( $version->scheduled_predecessor_row_revision ?? 0 ) !== $p['predecessor_revision'] || ! $c->family->authorize_author( $identity->site_id, $version->author_user_id, $logical->scope ) ) ) { $this->refuse( null !== $version && ! $c->family->authorize_author( $identity->site_id, $version->author_user_id, $logical->scope ) ? 'not_authorized' : 'stale_revision' ); }
		$this->owner = $session;
		$this->locked = [ $repo, $guard, $logical, $version, $predecessor, $logicals, $versions ];
		return new OperationTarget( new OperationSchema( [ 'guard_id' => 'positive_int', 'guard_revision' => 'positive_int', 'logical_id' => 'nonnegative_int', 'version_id' => 'nonnegative_int' ] ), [ 'guard_id' => $guard->id, 'guard_revision' => $guard->revision, 'logical_id' => $logical?->id ?? 0, 'version_id' => $version?->id ?? 0 ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $request ): OperationMutation {
		if ( $this->owner !== $session || null === $this->locked || $command !== $this->command ) { throw new OperationStorageException(); }
		[ $repo, $guard, $logical, $version, $predecessor, $logicals, $versions ] = $this->locked;
		$c = $this->command; $operation = $identity->operation;
		if ( $target->facts['guard_id'] !== $guard->id || $target->facts['guard_revision'] !== $guard->revision || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$at = $repo->accepted_time();
		foreach ( [ $guard->updated_at, $logical?->updated_at, $version?->updated_at, $predecessor?->updated_at ] as $previous ) { if ( null !== $previous && $at->compare( $previous ) < 0 ) { $this->refuse( 'temporarily_unavailable' ); } }
		$create = 'rule.draft.create' === $operation;
		if ( $create && ( null !== $version || ( null !== $logical && ( null !== $logical->draft_version_id || null !== $logical->scheduled_version_id ) ) ) ) { $this->refuse( 'stale_revision' ); }
		if ( ! $create && null === $version ) { $this->refuse( 'stale_revision' ); }
		if ( in_array( $operation, [ 'rule.draft.edit', 'rule.schedule', 'rule.publish' ], true ) && ( RuleState::Draft !== $version->state || $logical->draft_version_id !== $version->id ) ) { $this->refuse( 'stale_revision' ); }
		if ( in_array( $operation, [ 'rule.schedule', 'rule.publish', 'rule.activate', 'rule.retire' ], true ) && ( $version->content_hash !== $c->content_hash || $version->author_user_id !== $c->author_user_id || $version->change_reason !== $c->change_reason ) ) { $this->refuse( 'stale_revision' ); }
		if ( 'rule.draft.edit' === $operation && $version->content_hash === $c->content_hash && $version->author_user_id === $c->author_user_id && $version->change_reason === $c->change_reason ) { return OperationMutation::unchanged( OperationCompletion::not_applicable( $this, $this->result( $guard, $logical, $version, $predecessor, false, $version->updated_at ) ) ); }
		if ( 'rule.retire' === $operation && RuleState::Retired === $version->state ) { return OperationMutation::unchanged( OperationCompletion::not_applicable( $this, $this->result( $guard, $logical, $version, $predecessor, false, $version->updated_at ) ) ); }
		if ( 'rule.schedule' === $operation && ( RuleStartMode::At !== $version->start_mode || null === $version->effective_from || $version->effective_from->compare( $at ) <= 0 ) ) { $this->refuse( 'invalid_input' ); }
		if ( 'rule.publish' === $operation && RuleStartMode::At === $version->start_mode && ! $version->effective_from->equals( $at ) ) { $this->refuse( 'invalid_input' ); }
		if ( 'rule.activate' === $operation && $version->effective_from->compare( $at ) > 0 ) { $this->refuse( 'temporarily_unavailable' ); }
		if ( in_array( $operation, [ 'rule.publish', 'rule.activate' ], true ) && null !== $version->effective_until && $at->compare( $version->effective_until ) >= 0 ) { $this->refuse( 'invalid_input' ); }
		$before_logical = $logical?->revision ?? 0; $before_version = $version?->row_revision ?? 0;
		$repo->advance_family( $guard->row(), $at );
		$new_guard = RuleFamilyGuard::from_row( array_replace( $guard->row(), [ 'revision' => $guard->revision + 1, 'updated_at' => $at->sql() ] ), $c->family );
		if ( null === $logical ) {
			$values = [ 'family_guard_id' => $guard->id, 'logical_uuid' => $c->logical_uuid, 'scope_format' => $c->family->version(), 'scope_json' => $c->family->scope_schema()->encode( $c->scope, 4096 ), 'scope_hash' => $c->scope_hash, 'revision' => 1, 'last_version_sequence' => 0, 'current_published_version_id' => null, 'draft_version_id' => null, 'scheduled_version_id' => null, 'created_at' => $at->sql(), 'updated_at' => $at->sql() ];
			$id = $repo->insert_logical( $values ); $logical = LogicalRule::from_row( [ 'id' => $id, 'site_id' => $identity->site_id ] + $values, $new_guard, $c->family );
		}
		$logical_values = [ 'revision' => $logical->revision + 1, 'updated_at' => $at->sql() ];
		if ( $create ) {
			if ( PHP_INT_MAX === $logical->last_version_sequence ) { throw new OperationStorageException(); }
			$values = [ 'logical_rule_id' => $logical->id, 'version_uuid' => $c->version_uuid, 'version_sequence' => $logical->last_version_sequence + 1, 'row_revision' => 1, 'state' => 'draft', 'payload_format' => $c->family->version(), 'payload_json' => $c->family->payload_schema()->encode( $c->payload, 16384 ), 'content_hash' => $c->content_hash, 'priority' => $c->priority, 'start_mode' => $c->start_mode->value, 'effective_from' => $c->effective_from?->sql(), 'effective_until' => $c->effective_until?->sql(), 'author_user_id' => $c->author_user_id, 'change_reason' => $c->change_reason, 'supersedes_version_id' => $predecessor?->id, 'scheduled_revision' => null, 'scheduled_logical_revision' => null, 'scheduled_predecessor_row_revision' => null, 'sealed_at' => null, 'scheduled_at' => null, 'published_at' => null, 'retired_at' => null, 'created_at' => $at->sql(), 'updated_at' => $at->sql() ];
			$id = $repo->insert_version( $values ); $row = [ 'id' => $id, 'site_id' => $identity->site_id ] + $values;
			$logical_values += [ 'last_version_sequence' => $logical->last_version_sequence + 1, 'draft_version_id' => $id ];
		} else {
			$values = [ 'row_revision' => $version->row_revision + 1, 'updated_at' => $at->sql() ];
			if ( 'rule.draft.edit' === $operation ) {
				$values += [ 'payload_json' => $c->family->payload_schema()->encode( $c->payload, 16384 ), 'content_hash' => $c->content_hash, 'priority' => $c->priority, 'start_mode' => $c->start_mode->value, 'effective_from' => $c->effective_from?->sql(), 'effective_until' => $c->effective_until?->sql(), 'author_user_id' => $c->author_user_id, 'change_reason' => $c->change_reason, 'supersedes_version_id' => $predecessor?->id ];
			} elseif ( 'rule.schedule' === $operation ) {
				$values += [ 'state' => 'scheduled', 'sealed_at' => $at->sql(), 'scheduled_at' => $at->sql(), 'scheduled_revision' => $version->row_revision + 1, 'scheduled_logical_revision' => $logical->revision + 1, 'scheduled_predecessor_row_revision' => $predecessor?->row_revision ];
				$logical_values += [ 'draft_version_id' => null, 'scheduled_version_id' => $version->id ];
			} elseif ( in_array( $operation, [ 'rule.publish', 'rule.activate' ], true ) ) {
				$from = RuleStartMode::Immediate === $version->start_mode ? $at : $version->effective_from;
				$values += [ 'state' => 'published', 'sealed_at' => $version->sealed_at?->sql() ?? $at->sql(), 'published_at' => $at->sql(), 'effective_from' => $from->sql(), 'content_hash' => RuleContent::hash( $c->family, $version->payload, $version->start_mode, $from, $version->effective_until, $version->priority, $version->supersedes_version_id ) ];
				$logical_values += [ 'draft_version_id' => null, 'scheduled_version_id' => null, 'current_published_version_id' => $version->id ];
				if ( null !== $predecessor ) {
					if ( RuleState::Published !== $predecessor->state ) { $this->refuse( 'stale_revision' ); }
					$old_values = [ 'state' => 'retired', 'row_revision' => $predecessor->row_revision + 1, 'retired_at' => $at->sql(), 'updated_at' => $at->sql() ];
					$repo->update_version( $predecessor->row(), $old_values );
					$predecessor_row = array_replace( $predecessor->row(), $old_values );
				}
			} elseif ( 'rule.retire' === $operation ) {
				$values += [ 'state' => 'retired', 'retired_at' => $at->sql() ];
				foreach ( [ 'draft_version_id', 'scheduled_version_id', 'current_published_version_id' ] as $pointer ) { if ( $logical->$pointer === $version->id ) { $logical_values[$pointer] = null; } }
			}
			$repo->update_version( $version->row(), $values ); $row = array_replace( $version->row(), $values );
		}
		$repo->update_logical( $logical->row(), $logical_values );
		$new_logical = LogicalRule::from_row( array_replace( $logical->row(), $logical_values ), $new_guard, $c->family );
		$new_version = RuleVersion::from_row( $row, $new_logical, $c->family );
		if ( isset( $predecessor_row ) ) { $predecessor = RuleVersion::from_row( $predecessor_row, $new_logical, $c->family ); }
		if ( in_array( $operation, [ 'rule.schedule', 'rule.publish', 'rule.activate' ], true ) ) {
			$existing = [];
			foreach ( $versions as $candidate ) { if ( $candidate->id !== $new_version->id ) { $existing[] = new RuleCandidate( $logicals[$candidate->logical_rule_id], $candidate ); } }
			$conflicts = ( new RuleLifecycleEvaluator() )->conflicts( $c->family, new RuleCandidate( $new_logical, $new_version ), $existing, $new_version->supersedes_version_id, $at, true );
			if ( ! $conflicts->complete || $conflicts->conflict ) { $this->refuse( $conflicts->complete ? 'invalid_input' : 'temporarily_unavailable' ); }
		}
		$late = 'rule.activate' === $operation && $at->compare( $new_version->effective_from ) > 0;
		$result = $this->result( $new_guard, $new_logical, $new_version, $predecessor, $late, $at );
		$event = OperationMaterialEvent::from_mutation( $this, $identity, $request,
			[ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ), 'author_user_id' => $c->author_user_id ],
			[ 'guard_id' => $guard->id, 'logical_id' => $new_logical->id, 'version_id' => $new_version->id, 'logical_uuid' => $new_logical->logical_uuid, 'version_uuid' => $new_version->version_uuid, 'before_logical_revision' => $before_logical, 'after_logical_revision' => $new_logical->revision, 'before_version_revision' => $before_version, 'after_version_revision' => $new_version->row_revision, 'content_hash' => $new_version->content_hash, 'scope_hash' => $new_logical->scope_hash,
				'predecessor_id' => $predecessor?->id ?? 0, 'before_predecessor_revision' => $c->preconditions['predecessor_revision'], 'after_predecessor_revision' => $predecessor?->row_revision ?? 0, 'state' => $new_version->state->value, 'late' => $late, 'accepted_at' => $at->epoch_microseconds(), 'source_content_hash' => $c->content_hash, 'start_mode' => $c->start_mode->value, 'has_from' => null !== $c->effective_from, 'requested_from' => $c->effective_from?->epoch_microseconds() ?? 0, 'has_until' => null !== $c->effective_until, 'requested_until' => $c->effective_until?->epoch_microseconds() ?? 0 ],
			$this->reason(), $guard->revision, $new_guard->revision, $this->fields() );
		return OperationMutation::changed( OperationCompletion::accepted( $this, $result ), $event );
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool { return false; }
	public function result_schema(): OperationSchema { return new OperationSchema( [ 'family' => [ 'enum' => [ $this->command->family->family() ] ], 'action' => [ 'enum' => RuleLifecycleCommand::OPERATIONS ], 'state' => [ 'enum' => [ 'draft', 'scheduled', 'published', 'retired' ] ], 'guard_id' => 'positive_int', 'logical_id' => 'positive_int', 'version_id' => 'positive_int', 'logical_uuid' => 'uuid', 'version_uuid' => 'uuid', 'family_revision' => 'positive_int', 'logical_revision' => 'positive_int', 'version_revision' => 'positive_int', 'predecessor_id' => 'nonnegative_int', 'predecessor_revision' => 'nonnegative_int', 'content_hash' => 'sha256', 'scope_hash' => 'sha256', 'author_user_id' => 'positive_int', 'late' => 'bool', 'accepted_at' => 'nonnegative_int' ] ); }
	public function publication_schema(): ?OperationSchema { return null; }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'authority_hash' => 'sha256', 'principal_hash' => 'sha256', 'author_user_id' => 'positive_int' ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'guard_id' => 'positive_int', 'logical_id' => 'positive_int', 'version_id' => 'positive_int', 'logical_uuid' => 'uuid', 'version_uuid' => 'uuid', 'before_logical_revision' => 'nonnegative_int', 'after_logical_revision' => 'positive_int', 'before_version_revision' => 'nonnegative_int', 'after_version_revision' => 'positive_int', 'content_hash' => 'sha256', 'scope_hash' => 'sha256', 'predecessor_id' => 'nonnegative_int', 'before_predecessor_revision' => 'nonnegative_int', 'after_predecessor_revision' => 'nonnegative_int', 'state' => [ 'enum' => [ 'draft', 'scheduled', 'published', 'retired' ] ], 'late' => 'bool', 'accepted_at' => 'nonnegative_int', 'source_content_hash' => 'sha256', 'start_mode' => [ 'enum' => [ 'immediate', 'at' ] ], 'has_from' => 'bool', 'requested_from' => 'integer', 'has_until' => 'bool', 'requested_until' => 'integer' ] ); }
	public function reason_codes(): array { return [ 'draft_created', 'draft_edited', 'rule_scheduled', 'rule_published', 'rule_retired', 'rule_activated' ]; }
	public function changed_fields(): array { return [ 'draft', 'sealed_content', 'schedule', 'publication', 'retirement', 'head' ]; }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		if ( 'accepted' !== $completion->state || null === $completion->result || $event->reason_code !== $this->reason() || $event->changed_fields !== $this->fields() || $event->after_revision !== $event->before_revision + 1 ) { return false; }
		$r = $completion->result; $t = $event->target;
		foreach ( [ 'guard_id', 'logical_id', 'version_id', 'logical_uuid', 'version_uuid', 'content_hash', 'scope_hash', 'predecessor_id', 'state', 'late', 'accepted_at' ] as $key ) { if ( $r[$key] !== $t[$key] ) { return false; } }
		// Stored acceptance is validated before the coordinator compares this attempt's
		// intent. These links therefore use recorded source facts, never a new payload.
		$action = $this->operation();
		$expected_state = match ( $action ) { 'rule.draft.create', 'rule.draft.edit' => 'draft', 'rule.schedule' => 'scheduled', 'rule.publish', 'rule.activate' => 'published', default => 'retired' };
		$cutover = in_array( $action, [ 'rule.publish', 'rule.activate' ], true ) && 0 !== $t['predecessor_id'];
		if ( $r['state'] !== $expected_state || $r['predecessor_revision'] !== $t['after_predecessor_revision']
			|| ( 0 === $t['predecessor_id'] ) !== ( 0 === $t['before_predecessor_revision'] )
			|| $t['after_predecessor_revision'] !== $t['before_predecessor_revision'] + ( $cutover ? 1 : 0 )
			|| $t['after_logical_revision'] !== ( 0 === $t['before_logical_revision'] ? 2 : $t['before_logical_revision'] + 1 )
			|| $t['after_version_revision'] !== $t['before_version_revision'] + 1
			|| ( 'rule.draft.create' === $action ) !== ( 0 === $t['before_version_revision'] )
			|| ( ! $t['has_from'] && 0 !== $t['requested_from'] ) || ( ! $t['has_until'] && 0 !== $t['requested_until'] )
			|| ( 'at' === $t['start_mode'] && ! $t['has_from'] ) ) { return false; }
		try {
			$at = RuleTime::from_epoch_microseconds( $r['accepted_at'] );
			$from = $t['has_from'] ? RuleTime::from_epoch_microseconds( $t['requested_from'] ) : null;
			$until = $t['has_until'] ? RuleTime::from_epoch_microseconds( $t['requested_until'] ) : null;
		} catch ( \Throwable ) { return false; }
		$late = 'rule.activate' === $action && null !== $from && $at->compare( $from ) > 0;
		if ( $r['late'] !== $late || ( null !== $from && null !== $until && $until->compare( $from ) <= 0 )
			|| ( 'rule.schedule' === $action && ( 'at' !== $t['start_mode'] || null === $from || $at->compare( $from ) >= 0 ) )
			|| ( 'rule.activate' === $action && ( 'at' !== $t['start_mode'] || null === $from || $at->compare( $from ) < 0 ) )
			|| ( 'rule.publish' === $action && 'at' === $t['start_mode'] && ! $from->equals( $at ) )
			|| ( 'rule.publish' === $action && 'immediate' === $t['start_mode'] && null !== $from )
			|| ( in_array( $action, [ 'rule.publish', 'rule.activate' ], true ) && null !== $until && $at->compare( $until ) >= 0 )
			|| ( ! ( 'rule.publish' === $action && 'immediate' === $t['start_mode'] ) && $r['content_hash'] !== $t['source_content_hash'] ) ) { return false; }
		return $r['family'] === $this->command->family->family() && $r['action'] === $action && $r['family_revision'] === $event->after_revision && $r['logical_revision'] === $t['after_logical_revision'] && $r['version_revision'] === $t['after_version_revision']
			&& $r['logical_uuid'] === $this->command->logical_uuid && $r['scope_hash'] === $this->command->scope_hash
			&& $event->actor['authority_hash'] === hash( 'sha256', $this->command->identity->authority ) && $event->actor['principal_hash'] === hash( 'sha256', $this->command->identity->principal ) && $event->actor['author_user_id'] === $r['author_user_id'];
	}
	private function result( RuleFamilyGuard $guard, LogicalRule $logical, RuleVersion $version, ?RuleVersion $predecessor, bool $late, RuleTime $at ): array {
		return [ 'family' => $this->command->family->family(), 'action' => $this->operation(), 'state' => $version->state->value, 'guard_id' => $guard->id, 'logical_id' => $logical->id, 'version_id' => $version->id, 'logical_uuid' => $logical->logical_uuid, 'version_uuid' => $version->version_uuid, 'family_revision' => $guard->revision, 'logical_revision' => $logical->revision, 'version_revision' => $version->row_revision, 'predecessor_id' => $predecessor?->id ?? 0, 'predecessor_revision' => $predecessor?->row_revision ?? 0, 'content_hash' => $version->content_hash, 'scope_hash' => $logical->scope_hash, 'author_user_id' => $version->author_user_id, 'late' => $late, 'accepted_at' => $at->epoch_microseconds() ];
	}
	private function reason(): string { return match ( $this->operation() ) { 'rule.draft.create' => 'draft_created', 'rule.draft.edit' => 'draft_edited', 'rule.schedule' => 'rule_scheduled', 'rule.publish' => 'rule_published', 'rule.activate' => 'rule_activated', default => 'rule_retired' }; }
	private function fields(): array { return match ( $this->operation() ) { 'rule.draft.create', 'rule.draft.edit' => [ 'draft' ], 'rule.schedule' => [ 'sealed_content', 'schedule' ], 'rule.publish' => [ 'sealed_content', 'publication', 'head' ], 'rule.activate' => [ 'publication', 'head' ], default => [ 'retirement', 'head' ] }; }
	private function refuse( string $code ): never { throw new OperationRefusal( $code, match ( $code ) { 'not_authorized' => 'contact_support', 'temporarily_unavailable' => 'retry_original_request', default => 'reload_and_submit' } ); }
}
