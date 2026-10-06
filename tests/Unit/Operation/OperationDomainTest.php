<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\Operation\OperationMaterialEvent;
use CetechDeliveryEngine\Domain\Operation\OperationMutation;
use CetechDeliveryEngine\Domain\Operation\OperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationRecord;
use CetechDeliveryEngine\Domain\Operation\OperationRefusal;
use CetechDeliveryEngine\Domain\Operation\OperationSchema;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\Operation\OperationTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationDomainTest extends TestCase {
	public function test_production_registry_is_empty_and_duplicate_or_changed_profile_refuses(): void {
		$empty = new OperationProfileRegistry();
		self::assertSame( [], $empty->profiles() );
		$this->assert_refuses( static fn() => $empty->get( 'fixture.counter', 1 ) );
		$profile = new DomainFixtureProfile();
		$this->assert_refuses( static fn() => new OperationProfileRegistry( [ $profile, $profile ] ) );
		$registry = new OperationProfileRegistry( [ $profile ] );
		self::assertSame( $profile, $registry->get( 'fixture.counter', 1 ) );
		$profile->name = 'changed.counter';
		$this->assert_refuses( static fn() => $registry->get( 'fixture.counter', 1 ) );
	}

	public function test_finite_schema_rejects_free_text_unknown_nested_or_renamed_private_facts(): void {
		$this->assert_refuses( static fn() => new OperationSchema( [ 'details' => 'string' ] ) );
		$this->assert_refuses( static fn() => new OperationSchema( [ 'label' => [ 'enum' => [ 'a private address' ] ] ] ) );
		$schema = new OperationSchema( [ 'row_id' => 'positive_int', 'status' => [ 'enum' => [ 'accepted' ] ], 'target' => [ 'object' => [ 'revision' => 'positive_int' ] ] ] );
		$valid = [ 'row_id' => 1, 'status' => 'accepted', 'target' => [ 'revision' => 2 ] ];
		self::assertSame( $valid, $schema->validate( $valid ) );
		$this->assert_refuses( static fn() => $schema->validate( $valid + [ 'renamed_private' => 'private-marker' ] ) );
		$this->assert_refuses( static fn() => $schema->validate( array_replace( $valid, [ 'row_id' => 'private-marker' ] ) ) );
		$this->assert_refuses( static fn() => $schema->validate( array_replace( $valid, [ 'target' => [ 'revision' => 2, 'renamed' => [ 'SQL' => 'private-marker' ] ] ] ) ) );
		$this->assert_refuses( static fn() => $schema->from_json_value( [ 'row_id' => 1 ] ) );
	}

	public function test_schema_and_completion_detach_php_references(): void {
		$type = 'positive_int';
		$fields = [ 'revision' => &$type ];
		$schema = new OperationSchema( $fields );
		$type = 'string';
		$revision = 2;
		$facts = [ 'revision' => &$revision ];
		$target = new OperationTarget( $schema, $facts );
		$revision = 3;
		self::assertSame( [ 'revision' => 2 ], $target->facts );
		$profile = new DomainFixtureProfile();
		$row_id = 1;
		$result = [ 'row_id' => &$row_id, 'revision' => 2 ];
		$completion = OperationCompletion::accepted( $profile, $result );
		$row_id = 9;
		self::assertSame( [ 'row_id' => 1, 'revision' => 2 ], $completion->result );
	}

	public function test_profile_and_material_event_cannot_omit_actor_identity_contract(): void {
		$profile = new DomainFixtureProfile();
		$profile->empty_actor = true;
		$this->assert_refuses( static fn() => new OperationProfileRegistry( [ $profile ] ) );
		$this->assert_refuses( static fn() => OperationMaterialEvent::from_mutation( $profile, self::identity(), RequestContext::create(), [], [ 'row_id' => 1 ], 'fixture_updated', 1, 2, [ 'value' ] ) );
	}

	public function test_rejected_completion_stores_only_safe_error_and_replays_current_attempt_ids(): void {
		$original = RequestContext::create();
		$retry = RequestContext::create( $original->correlation_id );
		$error = new ContractError( 'invalid_input', $original, 'reload_and_submit', [], [ [ 'field' => 'preconditions', 'code' => 'invalid_type' ] ] );
		$stored = OperationCompletion::rejected( $error );
		self::assertStringNotContainsString( $original->request_id, $stored->to_json() );
		self::assertStringNotContainsString( 'message_key', $stored->to_json() );
		self::assertStringNotContainsString( 'request_id', $stored->to_json() );
		$replayed = OperationCompletion::from_json( $stored->to_json(), new DomainFixtureProfile() );
		self::assertSame( $stored->to_json(), $replayed->to_json() );
		self::assertSame( $retry->request_id, $replayed->error( $retry )->context->request_id );
		self::assertSame( $original->correlation_id, $replayed->error( $retry )->context->correlation_id );
		self::assertSame( 'rejected', $replayed->outcome( $retry, 'none' )->state );
		$this->assert_refuses( static fn() => OperationCompletion::rejected( new ContractError( 'outcome_unknown', $retry, 'reconcile_original_request' ) ) );
	}

	public function test_accepted_publication_states_preserve_acceptance_without_second_material_event(): void {
		$profile = new DomainFixtureProfile( true );
		$context = RequestContext::create();
		$facts = [ 'row_id' => 1, 'revision' => 2 ];
		$completion = OperationCompletion::accepted( $profile, $facts, $facts );
		$decoded = OperationCompletion::from_json( $completion->to_json(), $profile );
		$pending = $decoded->outcome( $context, 'pending' );
		self::assertSame( 'unconfirmed', $pending->state );
		self::assertTrue( $pending->mutation_accepted );
		self::assertTrue( $pending->publication_pending );
		self::assertSame( 'accepted', $decoded->outcome( $context, 'published' )->state );
		$this->assert_refuses( static fn() => $decoded->outcome( $context, 'none' ) );
		$this->assert_refuses( static fn() => OperationCompletion::accepted( $profile, $facts ) );
		$this->assert_refuses( static fn() => OperationCompletion::accepted( new DomainFixtureProfile(), $facts, $facts ) );
		$unchanged = OperationCompletion::not_applicable( $profile, $facts );
		self::assertSame( 'not_applicable', $unchanged->outcome( $context, 'none' )->state );
		$this->assert_refuses( static fn() => $unchanged->outcome( $context, 'pending' ) );
	}

	#[DataProvider( 'corrupt_completion_cases' )]
	public function test_corrupt_or_unsupported_completions_fail_closed_without_raw_error( string $json ): void {
		$this->assert_refuses( static fn() => OperationCompletion::from_json( $json, new DomainFixtureProfile() ) );
	}

	public static function corrupt_completion_cases(): array {
		$valid = '{"format":1,"outcome":"accepted","result":{"row_id":1,"revision":2},"error":null,"publication":null}';
		return [
			[ str_replace( '"format":1', '"format":2', $valid ) ],
			[ str_replace( '"format":1', '"format":"1"', $valid ) ],
			[ str_replace( '"format":1', '"format":1,"form\\u0061t":1', $valid ) ],
			[ str_replace( '"revision":2', '"revision":2,"revision":3', $valid ) ],
			[ str_replace( '"revision":2', '"revision":"private-marker SQL"', $valid ) ],
			[ str_replace( '"revision":2', '"revision":2,"renamed_private":{"unknown":"private-marker"}', $valid ) ],
			[ str_replace( '"outcome":"accepted"', '"outcome":"unconfirmed"', $valid ) ],
			[ str_replace( '"publication":null', '"publication":{}', $valid ) ],
			[ str_replace( '"error":null', '"error":{"SQL":"private-marker"}', $valid ) ],
			[ '{"format":1,"outcome":"rejected","result":null,"error":{"code":"stale_revision","recovery_action":"reload_and_submit","parameters":{},"field_violations":[],"request_id":"private-marker"},"publication":null}' ],
			[ str_repeat( 'private-marker', 1500 ) ],
			[ '[1,2]' ], [ '{"format":' ],
		];
	}

	public function test_json_budget_rejects_depth_nodes_and_encoded_bytes(): void {
		$deep = (object) [ 'leaf' => true ];
		for ( $i = 0; $i < 9; ++$i ) {
			$deep = (object) [ 'nested' => $deep ];
		}
		$this->assert_refuses( static fn() => OperationJson::encode( $deep ) );
		$this->assert_refuses( static fn() => OperationJson::decode( json_encode( $deep, JSON_THROW_ON_ERROR ) ) );
		$this->assert_refuses( static fn() => OperationJson::encode( (object) [ 'list' => array_fill( 0, 256, true ) ] ) );
		$this->assert_refuses( static fn() => OperationJson::encode( (object) [ 'text' => str_repeat( 'x', 16384 ) ] ) );
		self::assertSame( '{"valid":true}', OperationJson::encode( (object) [ 'valid' => true ] ) );
	}

	public function test_material_event_preserves_first_effect_ids_and_forbids_private_values(): void {
		$profile = new DomainFixtureProfile();
		$context = RequestContext::create();
		$event = self::event( $profile, $context );
		self::assertSame( $context->request_id, $event->request_id );
		self::assertSame( $event->to_json(), OperationMaterialEvent::from_json( $event->to_json(), $profile )->to_json() );
		$this->assert_refuses( static fn() => OperationMaterialEvent::from_json( str_replace( '"user_id":1', '"user_id":1,"renamed_private":"private-marker"', $event->to_json() ), $profile ) );
		$this->assert_refuses( static fn() => OperationMaterialEvent::from_json( str_replace( '"after_revision":2', '"after_revision":1', $event->to_json() ), $profile ) );
		$this->assert_refuses( static fn() => OperationMaterialEvent::from_json( str_replace( '"changed_fields":["value"]', '"changed_fields":["value","value"]', $event->to_json() ), $profile ) );
		$this->assert_refuses( static fn() => OperationMaterialEvent::from_json( str_replace( 'fixture_updated', 'private-marker', $event->to_json() ), $profile ) );
	}

	public function test_accepted_record_requires_bidirectional_same_site_event_and_consistent_revisions(): void {
		$profile = new DomainFixtureProfile();
		$completion = OperationCompletion::accepted( $profile, [ 'row_id' => 1, 'revision' => 2 ] );
		$row = self::record_row();
		$row['state'] = 'accepted';
		$row['completion_json'] = $completion->to_json();
		$row['audit_id'] = '9';
		$row['completed_at'] = $row['updated_at'];
		$event_row = self::event_row( self::event( $profile, RequestContext::create() ) );
		$record = OperationRecord::from_row( $row, $profile, $event_row );
		self::assertTrue( $record->matches( self::identity(), self::intent() ) );
		self::assertTrue( $record->matches_identity( self::identity() ) );
		self::assertSame( 9, $record->audit_id );
		self::assertSame( 'accepted', $record->completion->state );
		foreach ( [ 'id', 'site_id', 'operation_id', 'event_format' ] as $key ) {
			$bad = $event_row;
			$bad[ $key ] = '999';
			$this->assert_refuses( static fn() => OperationRecord::from_row( $row, $profile, $bad ) );
		}
		$this->assert_refuses( static fn() => OperationRecord::from_row( $row, $profile ) );
		$bad = $event_row;
		$bad['event_json'] = str_replace( '"row_id":1', '"row_id":2', $bad['event_json'] );
		$this->assert_refuses( static fn() => OperationRecord::from_row( $row, $profile, $bad ) );
	}

	#[DataProvider( 'corrupt_record_cases' )]
	public function test_pending_record_format_state_and_number_corruption_refuses( string $field, mixed $value ): void {
		$row = self::record_row();
		$row[ $field ] = $value;
		$this->assert_refuses( static fn() => OperationRecord::from_row( $row, new DomainFixtureProfile() ) );
	}

	public static function corrupt_record_cases(): array {
		return [
			[ 'id', '01' ], [ 'site_id', '1e0' ], [ 'operation_version', '0' ], [ 'row_version', str_repeat( '9', 30 ) ],
			[ 'record_format', '2' ], [ 'namespace_format', '2' ], [ 'intent_format', '2' ],
			[ 'namespace_hash', str_repeat( 'A', 64 ) ], [ 'target_hash', 'private-marker' ],
			[ 'state', 'unconfirmed' ], [ 'publication_state', 'pending' ],
			[ 'completion_json', '{}' ], [ 'audit_id', '9' ], [ 'completed_at', '2026-10-06 20:00:00.000001' ],
			[ 'created_at', '2026-02-30 20:00:00.000001' ], [ 'updated_at', '0000-00-00 00:00:00.000000' ],
		];
	}

	public function test_namespace_digest_and_integer_boundaries_are_exact_and_never_authority(): void {
		$record = OperationRecord::from_row( self::record_row(), new DomainFixtureProfile() );
		self::assertTrue( $record->matches( self::identity(), self::intent() ) );
		$other = new OperationIdentity( 1, 'fixture-authority', 'another-principal', 'fixture.counter', 1, 'counter:1', 'private-token' );
		self::assertFalse( $record->matches( $other, CanonicalIntent::from_command( $other, 1, [ 'revision' => 1 ], [ 'value' => 5 ] ) ) );
		self::assertFalse( $record->matches_identity( $other ) );
		$changed_intent = CanonicalIntent::from_command( self::identity(), 1, [ 'revision' => 1 ], [ 'value' => 99 ] );
		self::assertTrue( $record->matches_identity( self::identity() ) );
		self::assertFalse( $record->matches( self::identity(), $changed_intent ) );
		self::assertSame( PHP_INT_MAX, OperationRecord::positive_integer( (string) PHP_INT_MAX ) );
		$this->assert_refuses( static fn() => OperationRecord::positive_integer( -1 ) );
		$this->assert_refuses( static fn() => OperationRecord::positive_integer( 1.0 ) );
	}

	public function test_known_refusal_and_mutation_facts_cannot_claim_unknown_or_no_change_as_accepted(): void {
		$context = RequestContext::create();
		$profile = new DomainFixtureProfile();
		$refusal = new OperationRefusal( 'stale_revision', 'reload_and_submit' );
		self::assertSame( 'stale_revision', $refusal->error( $context )->code );
		self::assertSame( $context->request_id, $refusal->error( $context )->context->request_id );
		$this->assert_refuses( static fn() => new OperationRefusal( 'outcome_unknown', 'reconcile_original_request' ) );
		$accepted = OperationCompletion::accepted( $profile, [ 'row_id' => 1, 'revision' => 2 ] );
		$rejected = OperationCompletion::rejected( $refusal->error( $context ) );
		self::assertTrue( OperationMutation::changed( $accepted, self::event( $profile, $context ) )->changed );
		self::assertFalse( OperationMutation::unchanged( $rejected )->changed );
		$this->assert_refuses( static fn() => OperationMutation::unchanged( $accepted ) );
		$this->assert_refuses( static fn() => OperationMutation::changed( $rejected, self::event( $profile, $context ) ) );
	}

	public function test_attempt_result_cannot_present_staged_or_contradictory_facts_as_acceptance(): void {
		$context = RequestContext::create();
		$completion = OperationCompletion::accepted( new DomainFixtureProfile(), [ 'row_id' => 1, 'revision' => 2 ] );
		self::assertSame( $completion, ( new OperationAttemptResult( OperationOutcome::accepted(), $completion ) )->completion );
		$this->assert_refuses( static fn() => new OperationAttemptResult( OperationOutcome::accepted() ) );
		$this->assert_refuses( static fn() => new OperationAttemptResult( OperationOutcome::pending(), $completion ) );
		$this->assert_refuses( static fn() => new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $context, 'reconcile_original_request' ) ), $completion ) );
	}

	private function assert_refuses( callable $action ): void {
		try {
			$action();
			self::fail( 'Corrupt or unauthorized facts were accepted.' );
		} catch ( \InvalidArgumentException $error ) {
			self::assertStringNotContainsString( 'private-marker', $error->getMessage() );
			self::assertStringNotContainsString( 'SQL', $error->getMessage() );
			self::assertNull( $error->getPrevious() );
		}
	}

	private static function identity(): OperationIdentity {
		return new OperationIdentity( 1, 'fixture-authority', 'fixture-principal', 'fixture.counter', 1, 'counter:1', 'private-token' );
	}

	private static function intent(): CanonicalIntent {
		return CanonicalIntent::from_command( self::identity(), 1, [ 'revision' => 1 ], [ 'value' => 5 ] );
	}

	private static function event( DomainFixtureProfile $profile, RequestContext $context ): OperationMaterialEvent {
		return OperationMaterialEvent::from_mutation( $profile, self::identity(), $context, [ 'user_id' => 1, 'kind' => 'fixture_admin' ], [ 'row_id' => 1 ], 'fixture_updated', 1, 2, [ 'value' ] );
	}

	private static function record_row(): array {
		return [
			'id' => '1', 'site_id' => '1', 'namespace_hash' => self::identity()->namespace_digest(), 'intent_hash' => self::intent()->fingerprint(),
			'namespace_format' => '1', 'intent_format' => '1', 'record_format' => '1', 'operation' => 'fixture.counter', 'operation_version' => '1',
			'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:counter:1' ), 'state' => 'pending', 'publication_state' => 'none',
			'completion_json' => null, 'audit_id' => null, 'row_version' => '1', 'created_at' => '2026-10-06 20:00:00.000001',
			'updated_at' => '2026-10-06 20:00:00.000001', 'completed_at' => null,
		];
	}

	private static function event_row( OperationMaterialEvent $event ): array {
		return [ 'id' => '9', 'site_id' => '1', 'operation_id' => '1', 'event_format' => '1', 'event_json' => $event->to_json(), 'created_at' => '2026-10-06 20:00:00.000001' ];
	}
}

/** Domain-only fixture: it deliberately offers no database mutation implementation. */
final class DomainFixtureProfile implements OperationProfile {
	public string $name = 'fixture.counter';
	public bool $empty_actor = false;
	public function __construct( private bool $publication = false ) {}
	public function operation(): string { return $this->name; }
	public function version(): int { return 1; }
	public function authorize( OperationIdentity $identity ): bool { return true; }
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand { throw new \LogicException( 'Domain fixture has no command adapter.' ); }
	public function transactional_tables( OperationSession $session ): array { return []; }
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget { throw new \LogicException( 'Domain fixture has no database.' ); }
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $context ): OperationMutation { throw new \LogicException( 'Domain fixture has no database.' ); }
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool { return true; }
	public function result_schema(): OperationSchema { return new OperationSchema( [ 'row_id' => 'positive_int', 'revision' => 'positive_int' ] ); }
	public function publication_schema(): ?OperationSchema { return $this->publication ? $this->result_schema() : null; }
	public function actor_schema(): OperationSchema { return new OperationSchema( $this->empty_actor ? [] : [ 'user_id' => 'positive_int', 'kind' => [ 'enum' => [ 'fixture_admin' ] ] ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'row_id' => 'positive_int' ] ); }
	public function reason_codes(): array { return [ 'fixture_updated' ]; }
	public function changed_fields(): array { return [ 'value' ]; }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool {
		return $completion->result['row_id'] === $event->target['row_id'] && $completion->result['revision'] === $event->after_revision
			&& 1 === $event->actor['user_id'] && ( null === $completion->publication || $completion->publication === $completion->result );
	}
}
