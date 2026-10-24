<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PolicyAssignment;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationCommand, OperationCompletion, OperationMaterialEvent, OperationMutation, OperationProfile, OperationRefusal, OperationSchema, OperationSession, OperationTarget};
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\{PolicyAssignmentStore, RuleLifecycleSchema, WpdbRuleLifecycleRepository};

/** One original atomic two-family slot change; replay never reads current policy heads. */
final class PolicyAssignmentOperationProfile implements OperationProfile {

	private readonly \Closure $grant;
	private ?PolicyAssignmentCommand $command = null;
	private ?PolicyAssignmentState $locked = null;
	private ?OperationSession $effect_owner = null;
	private ?OperationSession $participating_owner = null;

	public function __construct( callable $pure_authorize, private readonly PolicyAssignmentStore $store, private readonly PolicyCurrentCatalog $catalog ) { $this->grant = \Closure::fromCallable( $pure_authorize ); }
	public function operation(): string { return PolicyAssignmentCommand::OPERATION; }
	public function version(): int { return PolicyAssignmentCommand::VERSION; }
	public function authorize( OperationIdentity $identity ): bool { try { return true === ( $this->grant )( $identity, $this->command?->actor_user_id() ); } catch ( \Throwable ) { return false; } }

	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		$parsed = $command instanceof PolicyAssignmentCommand ? $command : PolicyAssignmentCommand::from_array( $identity, $command );
		if ( $parsed->identity !== $identity ) { throw new \InvalidArgumentException( 'Policy assignment identity differs.' ); }
		$this->command = $parsed;
		if ( ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		return $parsed;
	}

	public function transactional_tables( OperationSession $session ): array {
		$this->store->assert_ready( $session );
		$this->participating_owner = $session;
		return [ $this->store->options_table( $session ), ...RuleLifecycleSchema::tables( $session->table_prefix() ) ];
	}

	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $command !== $this->command || ! $command instanceof PolicyAssignmentCommand || ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$this->store->guard( $session, ! $command->no_change() );
		$this->catalog->lock_families( $session );
		$current = $this->store->current( $session, $command->coordinate() ); $opened = $command->opened();
		if ( $current->row_id() !== $opened->row_id() || $current->revision() !== $opened->revision() || $current->generation() !== $opened->generation() || $current->original_bytes() !== $opened->original_bytes() ) { $this->refuse( 'stale_revision' ); }
		$this->effect_owner = $session; $this->locked = $current;
		$at = ( new WpdbRuleLifecycleRepository( $session ) )->accepted_time();
		if ( ! $this->monotonic( $current, $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		$this->review( $session, $command, $at );
		return new OperationTarget( new OperationSchema( [ 'row_id' => 'nonnegative_int', 'revision' => 'nonnegative_int', 'state_hash' => 'sha256' ] ), [ 'row_id' => $current->row_id(), 'revision' => $current->revision(), 'state_hash' => $current->digest() ] );
	}

	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $context ): OperationMutation {
		if ( $session !== $this->effect_owner || null === $this->locked || $command !== $this->command || ! $command instanceof PolicyAssignmentCommand ) { throw new OperationStorageException(); }
		$before = $this->locked;
		if ( ! $this->authorize( $identity ) || $target->facts !== [ 'row_id' => $before->row_id(), 'revision' => $before->revision(), 'state_hash' => $before->digest() ] ) { $this->refuse( 'not_authorized' ); }
		if ( $command->no_change() ) { return OperationMutation::unchanged( OperationCompletion::not_applicable( $this, $this->result( $before ) ) ); }
		// C03 material revisions are positive; this profile explicitly offsets physical revisions by one.
		if ( $before->revision() > PHP_INT_MAX - 2 ) { $this->refuse( 'temporarily_unavailable' ); }
		$repository = new WpdbRuleLifecycleRepository( $session ); $early = $repository->accepted_time();
		$this->review( $session, $command, $early );
		$at = $repository->accepted_time();
		if ( $at->compare( $early ) < 0 || ! $this->monotonic( $before, $at ) ) { $this->refuse( 'temporarily_unavailable' ); }
		try { $this->catalog->assert_reviewed_at( $session, $command->reviewed(), $at ); }
		catch ( \UnexpectedValueException ) { $this->refuse( 'stale_revision' ); }
		if ( ! $this->authorize( $identity ) ) { $this->refuse( 'not_authorized' ); }
		$after = $this->store->write( $session, $before, $command->desired_bytes( $at->sql() ) );
		if ( $after->digest() !== $command->desired( $at->sql(), $after->row_id() )->digest() ) { throw new OperationStorageException(); }
		$event = OperationMaterialEvent::from_mutation( $this, $identity, $context, $this->actor(), $this->event_target( $after ), 'policy_assignment_saved', $before->revision() + 1, $after->revision() + 1, [ 'policy_assignment' ] );
		return OperationMutation::changed( OperationCompletion::accepted( $this, $this->result( $after ), $this->publication() ), $event );
	}

	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool {
		try {
			if ( null === $this->command || ! $this->authorize( $identity ) || 'accepted' !== $completion->state || null === $completion->result || $completion->publication !== $this->publication() || null === $this->participating_owner ) { return false; }
			$at = RuleTime::from_epoch_microseconds( $completion->result['changed_at'] );
			$after = $this->command->desired( $at->sql(), $completion->result['row_id'] );
			if ( $completion->result !== $this->result( $after ) || $this->participating_owner->in_transaction() || ! $this->retire( $this->participating_owner ) ) { return false; }
			return $this->authorize( $identity ) && $this->store->invalidate( $this->command->coordinate() );
		} catch ( \Throwable ) { return false; }
	}

	public function result_schema(): OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'coordinate_hash' => 'sha256', 'offer_id' => 'nonnegative_int', 'row_id' => 'nonnegative_int', 'revision' => 'nonnegative_int', 'generation_hash' => 'sha256', 'state_hash' => 'sha256', 'bytes_hash' => 'sha256', 'return_map_hash' => 'sha256', 'refund_map_hash' => 'sha256', 'actor_user_id' => 'nonnegative_int', 'reason_hash' => 'sha256', 'changed_at' => 'integer', 'reviewed_hash' => 'sha256', 'intent_hash' => 'sha256' ] ); }
	public function publication_schema(): ?OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'coordinate_hash' => 'sha256', 'option_hash' => 'sha256' ] ); }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'actor_user_id' => 'positive_int', 'authority_hash' => 'sha256', 'principal_hash' => 'sha256' ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'site_id' => 'positive_int', 'coordinate_hash' => 'sha256', 'offer_id' => 'nonnegative_int', 'before_row_id' => 'nonnegative_int', 'after_row_id' => 'positive_int', 'before_revision' => 'nonnegative_int', 'after_revision' => 'positive_int', 'before_state_hash' => 'sha256', 'after_state_hash' => 'sha256', 'before_bytes_hash' => 'sha256', 'after_bytes_hash' => 'sha256', 'generation_hash' => 'sha256', 'reason_hash' => 'sha256', 'reviewed_hash' => 'sha256', 'intent_hash' => 'sha256', 'changed_at' => 'integer' ] ); }
	public function reason_codes(): array { return [ 'policy_assignment_saved' ]; }
	public function changed_fields(): array { return [ 'policy_assignment' ]; }

	public function validate_no_change_facts( OperationCompletion $completion ): bool {
		try { return null !== $this->command && $this->command->no_change() && 'not_applicable' === $completion->state && null === $completion->publication && $completion->result === $this->result( $this->command->opened() ); }
		catch ( \Throwable ) { return false; }
	}

	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		try {
			if ( null === $this->command || $this->command->no_change() || 'accepted' !== $completion->state || null === $completion->result || $completion->publication !== $this->publication() || $event->reason_code !== 'policy_assignment_saved' || $event->changed_fields !== [ 'policy_assignment' ] || $event->operation !== $this->operation() || $event->operation_version !== 1 ) { return false; }
			$at = RuleTime::from_epoch_microseconds( $completion->result['changed_at'] ); $opened = $this->command->opened();
			if ( ! $this->monotonic( $opened, $at ) || $opened->revision() > PHP_INT_MAX - 2 ) { return false; }
			$after = $this->command->desired( $at->sql(), $completion->result['row_id'] );
			return $completion->result === $this->result( $after ) && $event->actor === $this->actor() && $event->target === $this->event_target( $after ) && $event->before_revision === $opened->revision() + 1 && $event->after_revision === $after->revision() + 1;
		} catch ( \Throwable ) { return false; }
	}

	private function result( PolicyAssignmentState $state ): array {
		if ( null === $this->command ) { throw new OperationStorageException(); }
		$metadata = '' === $state->original_bytes() ? null : json_decode( $state->original_bytes(), true, 12, JSON_THROW_ON_ERROR );
		return [ 'site_id' => $this->command->identity->site_id, 'coordinate_hash' => self::digest( $this->command->coordinate() ), 'offer_id' => $this->command->offer_id(), 'row_id' => $state->row_id(), 'revision' => $state->revision(), 'generation_hash' => hash( 'sha256', $state->generation() ?? '' ), 'state_hash' => $state->digest(), 'bytes_hash' => hash( 'sha256', $state->original_bytes() ), 'return_map_hash' => $state->map( 'return_policy_v1' )->digest(), 'refund_map_hash' => $state->map( 'refund_policy_v1' )->digest(), 'actor_user_id' => $metadata['actor_user_id'] ?? 0, 'reason_hash' => hash( 'sha256', $metadata['reason'] ?? '' ), 'changed_at' => null === $metadata ? 0 : RuleTime::parse( $metadata['changed_at'] )->epoch_microseconds(), 'reviewed_hash' => self::digest( $this->command->reviewed() ), 'intent_hash' => $this->command->intent()->fingerprint() ];
	}
	private function actor(): array { $identity = $this->command->identity; return [ 'actor_user_id' => $this->command->actor_user_id(), 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ]; }
	private function event_target( PolicyAssignmentState $after ): array {
		$before = $this->command->opened(); $r = $this->result( $after );
		return [ 'site_id' => $r['site_id'], 'coordinate_hash' => $r['coordinate_hash'], 'offer_id' => $r['offer_id'], 'before_row_id' => $before->row_id(), 'after_row_id' => $after->row_id(), 'before_revision' => $before->revision(), 'after_revision' => $after->revision(), 'before_state_hash' => $before->digest(), 'after_state_hash' => $after->digest(), 'before_bytes_hash' => hash( 'sha256', $before->original_bytes() ), 'after_bytes_hash' => $r['bytes_hash'], 'generation_hash' => $r['generation_hash'], 'reason_hash' => $r['reason_hash'], 'reviewed_hash' => $r['reviewed_hash'], 'intent_hash' => $r['intent_hash'], 'changed_at' => $r['changed_at'] ];
	}
	private function publication(): array { return [ 'site_id' => $this->command->identity->site_id, 'coordinate_hash' => self::digest( $this->command->coordinate() ), 'option_hash' => hash( 'sha256', $this->store->name( $this->command->coordinate() ) ) ]; }
	private function review( OperationSession $session, PolicyAssignmentCommand $command, RuleTime $at ): void { try { $this->catalog->assert_reviewed( $session, $command->reviewed(), $at ); } catch ( \UnexpectedValueException ) { $this->refuse( 'stale_revision' ); } }
	private function monotonic( PolicyAssignmentState $before, RuleTime $at ): bool { if ( '' === $before->original_bytes() ) { return true; } $d = json_decode( $before->original_bytes(), true, 12, JSON_THROW_ON_ERROR ); return $at->compare( RuleTime::parse( $d['changed_at'] ) ) >= 0; }
	private function retire( OperationSession $session ): bool { try { return $session->retire() && $session->is_retired() && ! $session->in_transaction(); } catch ( \Throwable ) { return false; } }
	private static function digest( array $facts ): string { return hash( 'sha256', 'cetech-policy-assignment-facts-v1:' . json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); }
	private function refuse( string $code ): never { throw new OperationRefusal( $code, 'not_authorized' === $code ? 'contact_support' : ( 'temporarily_unavailable' === $code ? 'retry_original_request' : 'reload_and_submit' ) ); }
}
