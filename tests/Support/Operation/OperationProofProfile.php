<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support\Operation;

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

/** Disposable counter adopter. This class is never registered by production. */
final class OperationProofProfile implements OperationProfile {
	public bool $allowed = true;
	public int $authorization_calls = 0;
	public ?int $revoke_at_call = null;
	public bool $publish_allowed = true;
	public int $mutation_calls = 0;
	public int $publish_calls = 0;
	public ?\Closure $before_current_lock = null;
	public ?array $participant_override = null;
	private ?\Closure $authorizer;

	public function __construct( private string $prefix, private bool $publication = false, private string $name = 'fixture.counter_update', private int $contract_version = 1, ?callable $authorizer = null, private int $actor_id = 9 ) {
		OperationProofDatabase::validate_prefix( $prefix );
		$this->authorizer = null === $authorizer ? null : \Closure::fromCallable( $authorizer );
	}
	public function operation(): string { return $this->name; }
	public function version(): int { return $this->contract_version; }
	public function authorize( OperationIdentity $identity ): bool {
		++$this->authorization_calls;
		return $this->allowed && ( null === $this->revoke_at_call || $this->authorization_calls < $this->revoke_at_call )
			&& 'wordpress' === $identity->authority && str_starts_with( $identity->principal, 'staff:' )
			&& ( null === $this->authorizer || true === ( $this->authorizer )( $identity ) );
	}
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		if ( ! is_array( $command ) || count( $command ) !== 3 || array_diff( [ 'row_id', 'expected_revision', 'value' ], array_keys( $command ) )
			|| 1 !== ( $command['row_id'] ?? null ) || ! is_int( $command['expected_revision'] ?? null ) || $command['expected_revision'] < 1
			|| ! is_int( $command['value'] ?? null ) || 'counter:1' !== $identity->target_key ) {
			throw new OperationRefusal( 'invalid_input', 'reload_and_submit' );
		}
		return new OperationProofCommand( $identity, $command['row_id'], $command['expected_revision'], $command['value'] );
	}
	public function transactional_tables( OperationSession $session ): array { return $this->participant_override ?? [ $this->prefix . 'operation_fixture_counter' ]; }
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( ! $command instanceof OperationProofCommand ) { throw new OperationRefusal( 'invalid_input', 'reload_and_submit' ); }
		if ( null !== $this->before_current_lock ) { ( $this->before_current_lock )( $session ); }
		$row = $session->get_row( "SELECT id,revision,value FROM `{$this->prefix}operation_fixture_counter` WHERE id=1 FOR UPDATE" );
		if ( false === $row ) { throw new \RuntimeException( 'Disposable counter locking read failed.' ); }
		if ( null === $row || (int) $row['id'] !== $command->row_id || (int) $row['revision'] !== $command->expected_revision ) {
			throw new OperationRefusal( 'stale_revision', 'reload_and_submit' );
		}
		return new OperationTarget( $this->target_schema(), [ 'row_id' => (int) $row['id'], 'revision' => (int) $row['revision'] ] );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $context ): OperationMutation {
		++$this->mutation_calls;
		if ( ! $command instanceof OperationProofCommand ) { throw new \RuntimeException( 'Invalid disposable counter command.' ); }
		$row = $session->get_row( "SELECT value FROM `{$this->prefix}operation_fixture_counter` WHERE id=1 FOR UPDATE" );
		if ( false === $row || null === $row ) { throw new \RuntimeException( 'Disposable counter current read failed.' ); }
		if ( (int) $row['value'] === $command->value ) {
			return OperationMutation::unchanged( OperationCompletion::not_applicable( $this, [ 'row_id' => 1, 'revision' => $target->facts['revision'], 'value' => $command->value ] ) );
		}
		$after = $target->facts['revision'] + 1;
		$sql = $session->prepare( "UPDATE `{$this->prefix}operation_fixture_counter` SET value=%d,revision=%d WHERE id=1 AND revision=%d", $command->value, $after, $target->facts['revision'] );
		if ( 1 !== $session->query( $sql ) ) { throw new \RuntimeException( 'Disposable counter update failed.' ); }
		$completion = OperationCompletion::accepted( $this, [ 'row_id' => 1, 'revision' => $after, 'value' => $command->value ], $this->publication ? [ 'row_id' => 1, 'revision' => $after ] : null );
		$event = OperationMaterialEvent::from_mutation( $this, $identity, $context, [ 'user_id' => $this->actor_id, 'kind' => 'fixture_admin' ], $target->facts, 'fixture.updated', $target->facts['revision'], $after, [ 'value' ] );
		return OperationMutation::changed( $completion, $event );
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool {
		++$this->publish_calls;
		if ( ! $this->publish_allowed || null === $completion->publication ) { return false; }
		$connection = OperationProofDatabase::connect();
		$revision = (int) $completion->publication['revision'];
		// This disposable profile explicitly treats a newer publication as satisfying
		// an older receipt. Production profiles must define their own policy.
		OperationProofDatabase::execute( $connection, "UPDATE `{$this->prefix}operation_fixture_counter` SET published_revision=GREATEST(published_revision,{$revision}) WHERE id=1" );
		$published = (int) OperationProofDatabase::scalar( $connection, "SELECT published_revision FROM `{$this->prefix}operation_fixture_counter` WHERE id=1" );
		$connection->close();
		return $published >= $revision;
	}
	public function result_schema(): OperationSchema { return new OperationSchema( [ 'row_id' => 'positive_int', 'revision' => 'positive_int', 'value' => 'integer' ] ); }
	public function publication_schema(): ?OperationSchema { return $this->publication ? new OperationSchema( [ 'row_id' => 'positive_int', 'revision' => 'positive_int' ] ) : null; }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'user_id' => 'positive_int', 'kind' => [ 'enum' => [ 'fixture_admin' ] ] ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'row_id' => 'positive_int', 'revision' => 'positive_int' ] ); }
	public function reason_codes(): array { return [ 'fixture.updated' ]; }
	public function changed_fields(): array { return [ 'value' ]; }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		return 'accepted' === $completion->state && 1 === ( $completion->result['row_id'] ?? null )
			&& 1 === ( $event->target['row_id'] ?? null )
			&& $event->before_revision === ( $event->target['revision'] ?? null )
			&& $event->after_revision === ( $completion->result['revision'] ?? null )
			&& $event->after_revision === $event->before_revision + 1
			&& $this->actor_id === ( $event->actor['user_id'] ?? null )
			&& 'fixture_admin' === ( $event->actor['kind'] ?? null )
			&& 'fixture.updated' === $event->reason_code && [ 'value' ] === $event->changed_fields
			&& ( ! $this->publication || ( 1 === ( $completion->publication['row_id'] ?? null ) && $event->after_revision === ( $completion->publication['revision'] ?? null ) ) );
	}
}
