<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationMaterialEvent;
use CetechDeliveryEngine\Domain\Operation\OperationMutation;
use CetechDeliveryEngine\Domain\Operation\OperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationRefusal;
use CetechDeliveryEngine\Domain\Operation\OperationSchema;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\Operation\OperationTarget;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;

/** A single explicit C03 adopter; the fixed native option is the only new writer. */
final class EmergencyControlOperationProfile implements OperationProfile {
	private \Closure $authorize;
	private ?EmergencyControlCommand $command = null;
	private ?EmergencyControlState $locked = null;
	private ?OperationSession $owner = null;
	public function __construct( callable $admin_authorizer, private EmergencyControlStore $store, private bool $trusted_equivalent_route = false ) { $this->authorize = \Closure::fromCallable( $admin_authorizer ); }
	public function operation(): string { return EmergencyControlCommand::OPERATION; }
	public function version(): int { return 1; }
	public function authorize( OperationIdentity $identity ): bool { try { return true === ( $this->authorize )( $identity->site_id, EmergencyControlCommand::actor( $identity ) ); } catch ( \Throwable ) { return false; } }
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		$parsed = $command instanceof EmergencyControlCommand ? $command : EmergencyControlCommand::from_payload( $identity, $command );
		if ( ! hash_equals( $identity->namespace_digest(), $parsed->identity->namespace_digest() ) ) { throw new \InvalidArgumentException( 'Emergency-control command identity mismatch.' ); }
		$this->command = $parsed; return $parsed;
	}
	public function transactional_tables( OperationSession $session ): array {
		if ( ! $this->trusted_equivalent_route ) { $this->store->assert_standard_wordpress_route( $session ); }
		$this->store->assert_ready( $session, $session->site_id() );
		return [ $this->store->options_table( $session ) ];
	}
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->command || ! $command instanceof EmergencyControlCommand || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$current = $this->store->current( $session ); $opened = $command->opened;
		if ( $current->row_id !== $opened->row_id || $current->revision !== $opened->revision || ! hash_equals( $current->original_bytes(), $opened->original_bytes() ) ) { $this->refuse( 'stale_revision' ); }
		$this->owner = $session; $this->locked = $current;
		return new OperationTarget( new OperationSchema( [ 'row_id' => 'nonnegative_int', 'revision' => 'positive_int', 'state' => [ 'enum' => EmergencyControlState::STATES ] ] ), [ 'row_id' => $current->row_id, 'revision' => $current->revision, 'state' => $current->state ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $request ): OperationMutation {
		if ( $this->owner !== $session || null === $this->locked || $command !== $this->command || ! $command instanceof EmergencyControlCommand ) { throw new OperationStorageException(); }
		$before = $this->locked;
		if ( ! $this->authorize( $identity ) || $target->facts !== [ 'row_id' => $before->row_id, 'revision' => $before->revision, 'state' => $before->state ] ) { $this->refuse( 'not_authorized' ); }
		if ( $command->desired_state === $before->state ) { return OperationMutation::unchanged( OperationCompletion::not_applicable( $this, $this->result( $before ) ) ); }
		if ( PHP_INT_MAX === $before->revision ) { $this->refuse( 'temporarily_unavailable' ); }
		$at = $this->store->accepted_time( $session );
		if ( $at < ( $before->changed_at_epoch ?? 0 ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$bytes = EmergencyControlState::record_json( $identity->site_id, $command->desired_state, $before->revision + 1, $command->reason_code, $command->actor_user_id, $at );
		$after = $this->store->write( $session, $before, $bytes );
		$event = OperationMaterialEvent::from_mutation( $this, $identity, $request,
			[ 'actor_user_id' => $command->actor_user_id, 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ],
			[ 'site_id' => $identity->site_id, 'before_row_id' => $before->row_id, 'after_row_id' => $after->row_id, 'before_state' => $before->state, 'after_state' => $after->state, 'before_bytes_hash' => hash( 'sha256', $before->original_bytes() ), 'after_bytes_hash' => hash( 'sha256', $after->original_bytes() ), 'reason_code' => $after->reason_code, 'changed_at_epoch' => $at ],
			$command->reason_code, $before->revision, $after->revision, [ 'checkout_control' ] );
		return OperationMutation::changed( OperationCompletion::accepted( $this, $this->result( $after ), [ 'site_id' => $identity->site_id, 'option' => EmergencyControlStore::OPTION_NAME ] ), $event );
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool { return $this->authorize( $identity ) && 'accepted' === $completion->state && null !== $completion->publication && $completion->publication['site_id'] === $identity->site_id && EmergencyControlStore::OPTION_NAME === $completion->publication['option'] && $this->store->invalidate(); }
	public function result_schema(): OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'row_id' => 'nonnegative_int', 'state' => [ 'enum' => EmergencyControlState::STATES ], 'revision' => 'positive_int', 'initialized' => 'bool', 'reason_code' => [ 'enum' => [ 'legacy_default', ...EmergencyControlState::REASONS ] ], 'actor_user_id' => 'nonnegative_int', 'changed_at_epoch' => 'nonnegative_int', 'bytes_hash' => 'sha256' ] ); }
	public function publication_schema(): ?OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'option' => [ 'enum' => [ EmergencyControlStore::OPTION_NAME ] ] ] ); }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'actor_user_id' => 'positive_int', 'authority_hash' => 'sha256', 'principal_hash' => 'sha256' ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'before_row_id' => 'nonnegative_int', 'after_row_id' => 'positive_int', 'before_state' => [ 'enum' => EmergencyControlState::STATES ], 'after_state' => [ 'enum' => EmergencyControlState::STATES ], 'before_bytes_hash' => 'sha256', 'after_bytes_hash' => 'sha256', 'reason_code' => [ 'enum' => EmergencyControlState::REASONS ], 'changed_at_epoch' => 'positive_int' ] ); }
	public function reason_codes(): array { return EmergencyControlState::REASONS; }
	public function changed_fields(): array { return [ 'checkout_control' ]; }
	public function validate_no_change_facts( OperationCompletion $completion ): bool { return null !== $this->command && 'not_applicable' === $completion->state && null === $completion->publication && $this->command->desired_state === $this->command->opened->state && $completion->result === $this->result( $this->command->opened ); }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		if ( null === $this->command || 'accepted' !== $completion->state || null === $completion->result || null === $completion->publication || $event->after_revision !== $event->before_revision + 1 || $event->changed_fields !== [ 'checkout_control' ] ) { return false; }
		$r = $completion->result; $t = $event->target; $identity = $this->command->identity;
		// Validate recorded source facts, not the semantic payload of a later attempt.
		return $r['site_id'] === $identity->site_id && $t['site_id'] === $r['site_id'] && $completion->publication === [ 'site_id' => $r['site_id'], 'option' => EmergencyControlStore::OPTION_NAME ]
			&& $r['initialized'] && $r['row_id'] === $t['after_row_id'] && ( 0 === $t['before_row_id'] ? 1 === $event->before_revision && 'enabled' === $t['before_state'] && hash( 'sha256', '' ) === $t['before_bytes_hash'] : $t['before_row_id'] === $t['after_row_id'] && $event->before_revision >= 2 )
			&& $r['state'] === $t['after_state'] && $t['before_state'] !== $t['after_state'] && EmergencyControlState::valid_reason( $r['state'], $r['reason_code'] )
			&& $r['revision'] === $event->after_revision && $r['reason_code'] === $t['reason_code'] && $event->reason_code === $r['reason_code'] && $r['changed_at_epoch'] === $t['changed_at_epoch'] && $r['bytes_hash'] === $t['after_bytes_hash']
			&& $r['actor_user_id'] === $event->actor['actor_user_id'] && $r['actor_user_id'] === EmergencyControlCommand::actor( $identity ) && $event->actor['authority_hash'] === hash( 'sha256', $identity->authority ) && $event->actor['principal_hash'] === hash( 'sha256', $identity->principal )
			&& $r['bytes_hash'] === hash( 'sha256', EmergencyControlState::record_json( $r['site_id'], $r['state'], $r['revision'], $r['reason_code'], $r['actor_user_id'], $r['changed_at_epoch'] ) );
	}
	private function result( EmergencyControlState $state ): array { return [ 'site_id' => $state->site_id, 'row_id' => $state->row_id, 'state' => $state->state, 'revision' => $state->revision, 'initialized' => $state->initialized, 'reason_code' => $state->reason_code, 'actor_user_id' => $state->actor_user_id ?? 0, 'changed_at_epoch' => $state->changed_at_epoch ?? 0, 'bytes_hash' => hash( 'sha256', $state->original_bytes() ) ]; }
	private function refuse( string $code ): never { throw new OperationRefusal( $code, match ( $code ) { 'not_authorized' => 'contact_support', 'temporarily_unavailable' => 'retry_original_request', default => 'reload_and_submit' } ); }
}
