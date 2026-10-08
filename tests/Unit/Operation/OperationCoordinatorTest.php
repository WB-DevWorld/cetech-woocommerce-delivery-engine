<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Operation;

use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\CanonicalIntent;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationCommand;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationCompletion;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationMaterialEvent;
use CetechDeliveryEngine\Domain\Operation\OperationMutation;
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\Operation\OperationProfile;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\Operation\OperationRefusal;
use CetechDeliveryEngine\Domain\Operation\OperationSchema;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\Operation\OperationTarget;
use PHPUnit\Framework\TestCase;

/** SQLite transaction fixture checks protocol failures; MariaDB proofs are separate. */
final class OperationCoordinatorTest extends TestCase {

	private string $path;
	private CoordinatorFixtureFactory $factory;
	private CoordinatorFixtureProfile $profile;
	private OperationCoordinator $coordinator;

	protected function setUp(): void {
		$this->path = tempnam( sys_get_temp_dir(), 'operation-unit-' );
		$this->factory = new CoordinatorFixtureFactory( $this->path );
		$this->profile = new CoordinatorFixtureProfile();
		$this->coordinator = $this->make_coordinator();
		$db = $this->factory->inspection();
		$db->exec( 'CREATE TABLE unit_delivery_engine_operation_records (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, namespace_hash TEXT NOT NULL, intent_hash TEXT NOT NULL, namespace_format INTEGER NOT NULL, intent_format INTEGER NOT NULL, record_format INTEGER NOT NULL, operation TEXT NOT NULL, operation_version INTEGER NOT NULL, target_hash TEXT NOT NULL, state TEXT NOT NULL, publication_state TEXT NOT NULL, completion_json TEXT NULL, audit_id INTEGER NULL, row_version INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, completed_at TEXT NULL, UNIQUE(site_id,namespace_hash))' );
		$db->exec( 'CREATE TABLE unit_delivery_engine_operation_changes (id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL, operation_id INTEGER NOT NULL, event_format INTEGER NOT NULL, event_json TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(site_id,operation_id))' );
		$db->exec( 'CREATE TABLE unit_counter (id INTEGER PRIMARY KEY, revision INTEGER NOT NULL, value INTEGER NOT NULL)' );
		$db->exec( 'INSERT INTO unit_counter VALUES (1,1,5)' );
	}

	protected function tearDown(): void {
		foreach ( $this->factory->sessions as $session ) { $session->retire(); }
		unlink( $this->path );
	}

	public function test_accepted_retry_and_changed_intent_have_one_effect_and_material_event(): void {
		$first_context = RequestContext::create();
		$first = $this->coordinator->attempt( $this->identity(), $this->command(), $first_context );
		$replay = $this->coordinator->attempt( $this->identity(), [ 'value' => 9, 'expected_revision' => 1 ], RequestContext::create() );
		$conflict = $this->coordinator->attempt( $this->identity(), $this->command( 10 ), RequestContext::create() );
		self::assertSame( 'accepted', $first->outcome->state );
		self::assertSame( 'accepted', $replay->outcome->state );
		self::assertTrue( $replay->replayed );
		self::assertSame( $first->completion->to_json(), $replay->completion->to_json() );
		self::assertSame( 'intent_conflict', $conflict->outcome->error->code );
		self::assertSame( 1, $this->profile->mutations );
		self::assertSame( [ 'id' => 1, 'revision' => 2, 'value' => 9 ], $this->resource() );
		self::assertSame( 1, $this->count_events() );
		$event = json_decode( $this->factory->inspection()->query( 'SELECT event_json FROM unit_delivery_engine_operation_changes' )->fetchColumn(), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $first_context->request_id, $event['request_id'] );
		self::assertStringNotContainsString( 'original-secret-token', json_encode( $event, JSON_THROW_ON_ERROR ) );
	}

	public function test_current_authority_denies_before_lookup_and_before_effect(): void {
		$this->profile->authorized = false;
		$denied = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_authorized', $denied->outcome->error->code );
		self::assertSame( 0, $this->factory->opens );
		$this->profile->authorized = true;
		$this->profile->revoke_on_lock = true;
		$late = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_authorized', $late->outcome->error->code );
		self::assertSame( 0, $this->profile->mutations );
		self::assertSame( 5, $this->resource()['value'] );
		self::assertSame( 0, $this->count_events() );
	}

	public function test_revoked_authority_cannot_load_an_existing_completion(): void {
		$this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		$opens = $this->factory->opens;
		$this->profile->authorized = false;
		$result = $this->coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_authorized', $result->outcome->error->code );
		self::assertNull( $result->completion );
		self::assertSame( $opens, $this->factory->opens );
	}

	public function test_empty_production_registry_refuses_before_opening_a_connection(): void {
		$coordinator = new OperationCoordinator( new OperationProfileRegistry(), $this->factory, new CoordinatorFixtureReadiness() );
		$result = $coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unsupported_contract', $result->outcome->error->code );
		self::assertSame( 0, $this->factory->opens );
	}

	public function test_audit_rejection_rolls_back_resource_and_retries_original_intent_once(): void {
		$this->factory->reject_audit = true;
		$rejected = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $rejected->outcome->state );
		self::assertSame( 5, $this->resource()['value'] );
		self::assertSame( 1, $this->resource()['revision'] );
		self::assertSame( 0, $this->count_events() );
		self::assertSame( 'rejected', $this->stored_state() );
		$retry = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $retry->outcome->state );
		self::assertSame( 9, $this->resource()['value'] );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_a_typed_rejected_mutation_cannot_commit_staged_resource_writes(): void {
		$this->profile->reject_after_write = true;
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'invalid_input', $result->outcome->error->code );
		self::assertSame( 5, $this->resource()['value'] );
		self::assertSame( 1, $this->resource()['revision'] );
		self::assertSame( 0, $this->count_events() );
		self::assertSame( 'rejected', $this->stored_state() );
	}

	public function test_proved_unsent_commit_and_lost_acknowledgement_have_different_truth(): void {
		$this->factory->not_sent_at = 2;
		$unsent = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $unsent->outcome->state );
		self::assertFalse( $unsent->outcome->mutation_accepted );
		self::assertSame( 5, $this->resource()['value'] );
		self::assertSame( 0, $this->count_events() );
		$this->factory->lost_ack_at = $this->factory->commits + 2;
		$lost = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $lost->outcome->state );
		self::assertNull( $lost->outcome->mutation_accepted );
		self::assertSame( 'reconcile_original_request', $lost->outcome->error->recovery_action );
		self::assertSame( 9, $this->resource()['value'] );
		self::assertSame( 'accepted', $this->stored_state() );
		self::assertSame( 1, $this->count_events() );
		$mutations = $this->profile->mutations;
		$resolved = $this->make_coordinator()->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $resolved->outcome->state );
		self::assertTrue( $resolved->replayed );
		self::assertSame( $mutations, $this->profile->mutations );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_pending_reconciliation_proves_no_effect_without_invoking_mutator(): void {
		$this->factory->lost_ack_at = 1;
		$unknown = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $unknown->outcome->state );
		self::assertSame( 'pending', $this->stored_state() );
		self::assertSame( 0, $this->profile->mutations );
		$resolved = $this->coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $resolved->outcome->state );
		self::assertSame( 'rejected', $this->stored_state() );
		self::assertSame( 0, $this->profile->mutations );
		$retry = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $retry->outcome->state );
		self::assertSame( 1, $this->profile->mutations );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_missing_or_corrupt_completion_remains_unknown_without_mutation(): void {
		$missing = $this->coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $missing->outcome->state );
		self::assertSame( 0, $this->profile->mutations );
		$this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		$this->factory->inspection()->exec( "UPDATE unit_delivery_engine_operation_records SET completion_json = '{\"private_alias\":\"SELECT secret\"}'" );
		$corrupt = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $corrupt->outcome->state );
		self::assertSame( 'outcome_unknown', $corrupt->outcome->error->code );
		self::assertSame( 1, $this->profile->mutations );
		self::assertSame( 1, $this->count_events() );
		self::assertStringNotContainsString( 'SELECT', json_encode( $corrupt->outcome->error->to_array(), JSON_THROW_ON_ERROR ) );
	}

	public function test_failed_rollback_does_not_record_a_false_rejection(): void {
		$this->factory->reject_audit = true;
		$this->factory->fail_rollback = true;
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state );
		self::assertSame( 'pending', $this->stored_state() );
		self::assertSame( 5, $this->resource()['value'] );
		self::assertSame( 0, $this->count_events() );
	}
	public function test_refusal_after_unknown_owner_loss_remains_pending_without_a_replacement_connection(): void {
		$this->profile->lose_owner_on_lock = true; $result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertSame( 'reconcile_original_request', $result->outcome->error->recovery_action ); self::assertNull( $result->completion ); self::assertSame( 1, $this->factory->opens ); self::assertTrue( $this->factory->sessions[0]->is_retired() ); self::assertSame( 'pending', $this->stored_state() ); self::assertSame( 0, $this->profile->mutations ); self::assertSame( 5, $this->resource()['value'] ); self::assertSame( 0, $this->count_events() );
	}

	public function test_rejection_bookkeeping_preserves_a_concurrent_terminal_no_change_record(): void {
		$this->factory->reject_audit = true;
		$this->factory->terminal_no_change_on_second_open = OperationCompletion::not_applicable( $this->profile, [ 'id' => 1, 'revision' => 1, 'value' => 5 ] )->to_json();
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_applicable', $result->outcome->state );
		self::assertTrue( $result->replayed );
		self::assertSame( 'not_applicable', $this->stored_state() );
		self::assertSame( 5, $this->resource()['value'] );
		self::assertSame( 0, $this->count_events() );
		$mutations = $this->profile->mutations;
		$retry = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_applicable', $retry->outcome->state );
		self::assertSame( $mutations, $this->profile->mutations );
	}

	public function test_failed_current_record_read_cannot_become_a_known_rejection_or_new_effect(): void {
		$this->factory->fail_record_read = true;
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state );
		self::assertSame( 'pending', $this->stored_state() );
		self::assertSame( 0, $this->profile->mutations );
		self::assertSame( 0, $this->count_events() );
	}

	public function test_orphan_material_event_on_pending_record_refuses_reconcile_and_attempt_without_rewrite(): void {
		$this->factory->lost_ack_at = 1;
		$this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		$event = OperationMaterialEvent::from_mutation( $this->profile, $this->identity(), RequestContext::create(), [ 'principal_id' => 7 ], [ 'row_id' => 1 ], 'counter_changed', 1, 2, [ 'value' ] );
		$statement = $this->factory->inspection()->prepare( "INSERT INTO unit_delivery_engine_operation_changes (site_id,operation_id,event_format,event_json,created_at) VALUES(1,1,1,?,'2026-10-06 20:00:00.000001')" );
		$statement->execute( [ $event->to_json() ] );
		$before = $this->factory->inspection()->query( 'SELECT * FROM unit_delivery_engine_operation_records' )->fetch( \PDO::FETCH_ASSOC );
		foreach ( [ 'reconcile', 'attempt' ] as $method ) {
			$result = $this->coordinator->$method( $this->identity(), $this->command(), RequestContext::create() );
			self::assertSame( 'unconfirmed', $result->outcome->state );
			self::assertNull( $result->completion );
			self::assertSame( $before, $this->factory->inspection()->query( 'SELECT * FROM unit_delivery_engine_operation_records' )->fetch( \PDO::FETCH_ASSOC ) );
			self::assertSame( 0, $this->profile->mutations );
			self::assertSame( 5, $this->resource()['value'] );
			self::assertSame( 1, $this->count_events() );
		}
	}

	public function test_corrupt_immutable_target_hash_is_unknown_rather_than_an_intent_conflict(): void {
		$this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		$this->factory->inspection()->exec( "UPDATE unit_delivery_engine_operation_records SET target_hash='" . str_repeat( '0', 64 ) . "'" );
		$before = $this->factory->inspection()->query( 'SELECT * FROM unit_delivery_engine_operation_records' )->fetch( \PDO::FETCH_ASSOC );
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state );
		self::assertSame( 'outcome_unknown', $result->outcome->error->code );
		self::assertSame( $before, $this->factory->inspection()->query( 'SELECT * FROM unit_delivery_engine_operation_records' )->fetch( \PDO::FETCH_ASSOC ) );
		self::assertSame( 1, $this->profile->mutations );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_revocation_after_acknowledged_effect_commit_hides_private_facts_without_claiming_rejection(): void {
		$this->factory->revoke_profile_after_second_commit = $this->profile;
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state );
		self::assertNull( $result->outcome->mutation_accepted );
		self::assertNull( $result->completion );
		self::assertSame( 'accepted', $this->stored_state() );
		self::assertSame( 9, $this->resource()['value'] );
		self::assertSame( 1, $this->count_events() );
		$this->profile->authorized = true;
		$resolved = $this->coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $resolved->outcome->state );
		self::assertSame( 1, $this->profile->mutations );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_no_change_is_terminal_after_later_resource_changes(): void {
		$initial = $this->coordinator->attempt( $this->identity(), $this->command( 5 ), RequestContext::create() );
		self::assertSame( 'not_applicable', $initial->outcome->state );
		$this->factory->inspection()->exec( 'UPDATE unit_counter SET value=11,revision=2 WHERE id=1' );
		$replay = $this->coordinator->attempt( $this->identity(), $this->command( 5 ), RequestContext::create() );
		self::assertSame( 'not_applicable', $replay->outcome->state );
		self::assertTrue( $replay->replayed );
		self::assertSame( 11, $this->resource()['value'] );
		self::assertSame( 0, $this->count_events() );
		self::assertSame( 1, $this->profile->mutations );
	}

	public function test_publication_retry_changes_only_marker_and_preserves_accepted_receipt(): void {
		$this->profile->publication_enabled = true;
		$this->profile->publication_fails = true;
		$pending = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $pending->outcome->state );
		self::assertTrue( $pending->outcome->mutation_accepted );
		self::assertTrue( $pending->outcome->publication_pending );
		$receipt = $pending->completion->to_json();
		$this->profile->publication_fails = false;
		$resolved = $this->coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $resolved->outcome->state );
		self::assertSame( $receipt, $resolved->completion->to_json() );
		self::assertSame( 1, $this->profile->mutations );
		self::assertSame( 1, $this->count_events() );
		self::assertSame( 'published', $this->factory->inspection()->query( 'SELECT publication_state FROM unit_delivery_engine_operation_records' )->fetchColumn() );
	}

	public function test_newer_publication_is_not_regressed_or_assumed_to_confirm_an_older_receipt(): void {
		$this->profile->publication_enabled = true;
		$this->profile->published_revision = 99;
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertTrue( $result->outcome->publication_pending );
		self::assertTrue( $result->outcome->mutation_accepted );
		self::assertSame( 99, $this->profile->published_revision );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_publication_marker_lost_acknowledgement_never_repeats_business_effect(): void {
		$this->profile->publication_enabled = true;
		$this->factory->lost_ack_at = 3;
		$unknown = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertTrue( $unknown->outcome->publication_pending );
		self::assertTrue( $unknown->outcome->mutation_accepted );
		self::assertSame( 'published', $this->factory->inspection()->query( 'SELECT publication_state FROM unit_delivery_engine_operation_records' )->fetchColumn() );
		$resolved = $this->coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $resolved->outcome->state );
		self::assertSame( 1, $this->profile->mutations );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_revocation_after_publication_marker_commit_does_not_disclose_or_erase_acceptance(): void {
		$this->profile->publication_enabled = true;
		$this->factory->revoke_profile_after_second_commit = $this->profile;
		$this->factory->revoke_on_commit_number = 3;
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state );
		self::assertNull( $result->completion );
		self::assertSame( 'accepted', $this->stored_state() );
		self::assertSame( 'published', $this->factory->inspection()->query( 'SELECT publication_state FROM unit_delivery_engine_operation_records' )->fetchColumn() );
		self::assertSame( 1, $this->count_events() );
		$this->profile->authorized = true;
		$resolved = $this->coordinator->reconcile( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'accepted', $resolved->outcome->state );
		self::assertSame( 1, $this->profile->mutations );
	}

	public function test_failed_publisher_that_revokes_authority_cannot_return_private_accepted_facts(): void {
		$this->profile->publication_enabled = true;
		$this->profile->revoke_on_publication = true;
		$result = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state );
		self::assertNull( $result->completion );
		self::assertSame( 'accepted', $this->stored_state() );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_second_token_is_not_a_revision_bypass(): void {
		$this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		$stale = $this->coordinator->attempt( $this->identity( 'other-token' ), $this->command( 10 ), RequestContext::create() );
		self::assertSame( 'stale_revision', $stale->outcome->error->code );
		self::assertSame( 9, $this->resource()['value'] );
		self::assertSame( 1, $this->count_events() );
	}

	public function test_wrong_site_and_ambient_session_refuse_before_any_statement(): void {
		$this->factory->site = 2;
		$wrong = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'not_authorized', $wrong->outcome->error->code );
		self::assertSame( 0, $this->factory->queries );
		$this->factory->site = 1;
		$this->factory->ambient = true;
		$ambient = $this->coordinator->attempt( $this->identity(), $this->command(), RequestContext::create() );
		self::assertSame( 'rejected', $ambient->outcome->state );
		self::assertSame( 0, $this->factory->queries );
		self::assertSame( 0, $this->profile->mutations );
	}

	private function make_coordinator(): OperationCoordinator {
		return new OperationCoordinator( new OperationProfileRegistry( [ $this->profile ] ), $this->factory, new CoordinatorFixtureReadiness() );
	}

	private function identity( string $token = 'original-secret-token' ): OperationIdentity {
		return new OperationIdentity( 1, 'wordpress', 'user:7', 'fixture.counter', 1, 'counter:1', $token );
	}

	private function command( int $value = 9 ): array { return [ 'expected_revision' => 1, 'value' => $value ]; }
	private function resource(): array { return $this->factory->inspection()->query( 'SELECT * FROM unit_counter WHERE id=1' )->fetch( \PDO::FETCH_ASSOC ); }
	private function count_events(): int { return (int) $this->factory->inspection()->query( 'SELECT COUNT(*) FROM unit_delivery_engine_operation_changes' )->fetchColumn(); }
	private function stored_state(): string { return $this->factory->inspection()->query( 'SELECT state FROM unit_delivery_engine_operation_records LIMIT 1' )->fetchColumn(); }
}

final class CoordinatorFixtureReadiness implements OperationReadiness {
	public function assert_ready( OperationSession $session ): void {}
}

final readonly class CoordinatorFixtureCommand implements OperationCommand {
	public function __construct( public int $value, public int $revision, private CanonicalIntent $canonical ) {}
	public function intent(): CanonicalIntent { return $this->canonical; }
}

final class CoordinatorFixtureProfile implements OperationProfile {
	public bool $authorized = true;
	public bool $lose_owner_on_lock = false;
	public bool $revoke_on_lock = false;
	public bool $reject_after_write = false;
	public bool $publication_enabled = false;
	public bool $publication_fails = false;
	public bool $revoke_on_publication = false;
	public int $published_revision = 0;
	public int $mutations = 0;
	public function operation(): string { return 'fixture.counter'; }
	public function version(): int { return 1; }
	public function authorize( OperationIdentity $identity ): bool { return $this->authorized; }
	public function validate_command( OperationIdentity $identity, mixed $command ): OperationCommand {
		if ( ! is_array( $command ) || count( $command ) !== 2 || ! isset( $command['expected_revision'], $command['value'] ) || ! is_int( $command['expected_revision'] ) || $command['expected_revision'] < 1 || ! is_int( $command['value'] ) ) { throw new \InvalidArgumentException( 'Invalid fixture command.' ); }
		return new CoordinatorFixtureCommand( $command['value'], $command['expected_revision'], CanonicalIntent::from_command( $identity, [ 'row_id' => 1 ], [ 'revision' => $command['expected_revision'] ], [ 'value' => $command['value'] ] ) );
	}
	public function transactional_tables( OperationSession $session ): array { return [ $session->table_prefix() . 'counter' ]; }
	public function lock_target( OperationSession $session, OperationIdentity $identity, OperationCommand $command ): OperationTarget {
		if ( $this->lose_owner_on_lock ) { $session->retire(); throw new OperationRefusal( 'temporarily_unavailable', 'retry_original_request' ); }
		$row = $session->get_row( 'SELECT * FROM unit_counter WHERE id=1 FOR UPDATE' );
		if ( ! is_array( $row ) || $row['revision'] !== $command->revision ) { throw new OperationRefusal( 'stale_revision', 'reload_and_submit' ); }
		if ( $this->revoke_on_lock ) { $this->authorized = false; }
		return new OperationTarget( $this->result_schema(), $row );
	}
	public function mutate( OperationSession $session, OperationIdentity $identity, OperationCommand $command, OperationTarget $target, RequestContext $context ): OperationMutation {
		++$this->mutations;
		$facts = $target->facts;
		$result = [ 'id' => $facts['id'], 'revision' => $facts['revision'], 'value' => $command->value ];
		if ( $facts['value'] === $command->value ) { return OperationMutation::unchanged( OperationCompletion::not_applicable( $this, $result ) ); }
		$result['revision']++;
		if ( 1 !== $session->query( $session->prepare( 'UPDATE unit_counter SET value=%d,revision=%d WHERE id=1', $command->value, $result['revision'] ) ) ) { throw new \RuntimeException( 'Fixture write failed.' ); }
		if ( $this->reject_after_write ) { return OperationMutation::unchanged( OperationCompletion::rejected( new ContractError( 'invalid_input', $context, 'reload_and_submit' ) ) ); }
		$completion = OperationCompletion::accepted( $this, $result, $this->publication_enabled ? [ 'revision' => $result['revision'] ] : null );
		$event = OperationMaterialEvent::from_mutation( $this, $identity, $context, [ 'principal_id' => 7 ], [ 'row_id' => 1 ], 'counter_changed', $facts['revision'], $result['revision'], [ 'value' ] );
		return OperationMutation::changed( $completion, $event );
	}
	public function publish( OperationIdentity $identity, OperationCompletion $completion ): bool {
		if ( $this->revoke_on_publication ) { $this->authorized = false; return false; }
		if ( $this->publication_fails ) { return false; }
		$this->published_revision = max( $this->published_revision, $completion->publication['revision'] );
		return $this->published_revision === $completion->publication['revision'];
	}
	public function result_schema(): OperationSchema { return new OperationSchema( [ 'id' => 'positive_int', 'revision' => 'positive_int', 'value' => 'integer' ] ); }
	public function publication_schema(): ?OperationSchema { return $this->publication_enabled ? new OperationSchema( [ 'revision' => 'positive_int' ] ) : null; }
	public function actor_schema(): OperationSchema { return new OperationSchema( [ 'principal_id' => 'positive_int' ] ); }
	public function target_schema(): OperationSchema { return new OperationSchema( [ 'row_id' => 'positive_int' ] ); }
	public function reason_codes(): array { return [ 'counter_changed' ]; }
	public function changed_fields(): array { return [ 'value' ]; }
	public function validate_accepted_facts( OperationCompletion $completion, OperationMaterialEvent $event ): bool { return $completion->result['id'] === $event->target['row_id'] && $completion->result['revision'] === $event->after_revision; }
}

final class CoordinatorFixtureFactory implements OperationConnectionFactory {
	public int $opens = 0;
	public int $queries = 0;
	public int $commits = 0;
	public int $site = 1;
	public bool $ambient = false;
	public bool $reject_audit = false;
	public bool $fail_rollback = false;
	public bool $fail_record_read = false;
	public ?string $terminal_no_change_on_second_open = null;
	public ?CoordinatorFixtureProfile $revoke_profile_after_second_commit = null;
	public int $revoke_on_commit_number = 2;
	public ?int $not_sent_at = null;
	public ?int $lost_ack_at = null;
	/** @var list<CoordinatorFixtureSession> */ public array $sessions = [];
	public function __construct( public readonly string $path ) {}
	public function open(): OperationSession {
		++$this->opens;
		if ( 2 === $this->opens && null !== $this->terminal_no_change_on_second_open ) {
			$statement = $this->inspection()->prepare( "UPDATE unit_delivery_engine_operation_records SET state='not_applicable',completion_json=?,completed_at='2026-10-06 20:00:00.000001',updated_at='2026-10-06 20:00:00.000001',row_version=row_version+1 WHERE state='pending'" );
			$statement->execute( [ $this->terminal_no_change_on_second_open ] );
		}
		$session = new CoordinatorFixtureSession( $this ); $this->sessions[] = $session; return $session;
	}
	public function inspection(): \PDO {
		$dsn = 'sqlite:' . $this->path;
		$options = [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ];
		return method_exists( \PDO::class, 'connect' )
			? \PDO::connect( $dsn, null, null, $options )
			: new \PDO( $dsn, null, null, $options );
	}
}

final class CoordinatorFixtureSession implements OperationSession {
	private ?\PDO $pdo;
	private int $error = 0;
	private bool $retired = false;
	private bool $ambient;
	public function __construct( private readonly CoordinatorFixtureFactory $factory ) {
		$this->pdo = $factory->inspection();
		$clock = static fn(): string => '2026-10-06 20:00:00.000001';
		if ( method_exists( $this->pdo, 'createFunction' ) ) {
			$this->pdo->createFunction( 'UTC_NOW', $clock );
		} else {
			$this->pdo->sqliteCreateFunction( 'UTC_NOW', $clock );
		}
		$this->ambient = $factory->ambient;
	}
	public function site_id(): int { return $this->factory->site; }
	public function table_prefix(): string { return 'unit_'; }
	public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { return ! $this->retired && ! $this->in_transaction() && $this->pdo->beginTransaction(); }
	public function commit(): OperationCommitResult {
		++$this->factory->commits;
		if ( $this->factory->not_sent_at === $this->factory->commits ) { $this->factory->not_sent_at = null; return OperationCommitResult::NotSent; }
		$this->pdo->commit();
		if ( $this->factory->revoke_on_commit_number === $this->factory->commits && null !== $this->factory->revoke_profile_after_second_commit ) { $this->factory->revoke_profile_after_second_commit->authorized = false; }
		if ( $this->factory->lost_ack_at === $this->factory->commits ) { $this->factory->lost_ack_at = null; $this->retire(); return OperationCommitResult::Unconfirmed; }
		return OperationCommitResult::Acknowledged;
	}
	public function rollback(): bool {
		if ( $this->factory->fail_rollback ) { $this->factory->fail_rollback = false; return false; }
		return ! $this->retired && $this->pdo->inTransaction() && $this->pdo->rollBack();
	}
	public function retire(): bool { if ( null !== $this->pdo && $this->pdo->inTransaction() ) { $this->pdo->rollBack(); } $this->pdo = null; $this->retired = true; return true; }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->ambient || ( null !== $this->pdo && $this->pdo->inTransaction() ); }
	public function validate_tables( array $table_names ): bool { return $this->in_transaction() && ! $this->retired; }
	public function query( string $sql ): int|false {
		++$this->factory->queries;
		$this->error = 0;
		if ( $this->factory->reject_audit && str_starts_with( $sql, 'INSERT INTO `unit_delivery_engine_operation_changes`' ) ) { $this->factory->reject_audit = false; return false; }
		try { return $this->pdo->exec( $this->sql( $sql ) ); } catch ( \PDOException $exception ) { $this->error = str_contains( $exception->getMessage(), 'UNIQUE constraint failed' ) ? 1062 : 1; return false; }
	}
	public function get_row( string $sql ): array|null|false {
		++$this->factory->queries;
		if ( $this->factory->fail_record_read && str_contains( $sql, 'unit_delivery_engine_operation_records' ) ) { $this->factory->fail_record_read = false; return false; }
		$row = $this->pdo->query( $this->sql( $sql ) )->fetch( \PDO::FETCH_ASSOC ); return false === $row ? null : $row;
	}
	public function get_results( string $sql ): array|false { ++$this->factory->queries; return $this->pdo->query( $this->sql( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ); }
	public function prepare( string $sql, mixed ...$args ): string {
		$offset = 0;
		return preg_replace_callback( '/%[ds]/', function ( array $match ) use ( &$offset, $args ): string { $value = $args[ $offset++ ]; return '%d' === $match[0] ? (string) $value : $this->pdo->quote( (string) $value ); }, $sql );
	}
	public function errno(): int { return $this->error; }
	public function insert_id(): int { return (int) $this->pdo->lastInsertId(); }
	private function sql( string $sql ): string { return str_replace( [ ' FOR UPDATE', 'UTC_TIMESTAMP(6)' ], [ '', 'UTC_NOW()' ], $sql ); }
}
