<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\RuleLifecycle;

use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\OperationStoreSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/RuleLifecycle/RuleProofFamily.php';
require_once dirname( __DIR__, 2 ) . '/Support/RuleLifecycle/RuleProofEnvelope.php';

/** Owned SQLite protocol model; real locking and commit proofs are separate. */
final class CoordinatorTest extends TestCase {
	private RuleUnitFactory $factory;
	private RuleProofFamily $family;
	private RuleLifecycleService $service;
	protected function setUp(): void {
		$this->family = new RuleProofFamily();
		$this->factory = new RuleUnitFactory( tempnam( sys_get_temp_dir(), 'rule-unit-' ), $this->family );
		$this->factory->install(); $this->service = $this->service();
	}
	protected function tearDown(): void { foreach ( $this->factory->sessions as $s ) { $s->retire(); } unlink( $this->factory->path ); }
	public function test_first_guard_and_original_replay_have_one_rule_version_and_event(): void {
		$p = $this->create_payload(); $id = RuleProofEnvelope::identity( $p, 'rule.draft.create' );
		$first = $this->service->attempt( $id, $p, RequestContext::create() );
		$replay = $this->service()->attempt( $id, $p, RequestContext::create() );
		$p['payload']['availability'] = 'deny';
		$conflict = $this->service->attempt( $id, $p, RequestContext::create() );
		self::assertSame( 'accepted', $first->outcome->state ); self::assertSame( 'accepted', $replay->outcome->state ); self::assertTrue( $replay->replayed );
		self::assertSame( $first->completion->to_json(), $replay->completion->to_json() ); self::assertSame( 'intent_conflict', $conflict->outcome->error->code );
		self::assertSame( 2, $this->guard()['revision'] ); self::assertSame( 1, $this->count_rows( 'logical_rules' ) ); self::assertSame( 1, $this->count_rows( 'rule_versions' ) ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
		$event = json_decode( $this->factory->inspection()->query( 'SELECT event_json FROM unit_delivery_engine_operation_changes' )->fetchColumn(), true, 64, JSON_THROW_ON_ERROR );
		self::assertSame( [ 1, 2 ], [ $event['before_revision'], $event['after_revision'] ] ); self::assertStringNotContainsString( 'PRIVATE_SYNTHETIC_CHANGE_REASON', json_encode( $event, JSON_THROW_ON_ERROR ) );
	}
	public function test_empty_registry_and_denied_scope_do_not_open_storage(): void {
		$p = $this->create_payload(); $id = RuleProofEnvelope::identity( $p, 'rule.draft.create' );
		$empty = new RuleLifecycleService( new RuleFamilyRegistry(), $this->factory, new RuleUnitReadiness() );
		self::assertSame( 'unsupported_contract', $empty->attempt( $id, $p, RequestContext::create() )->outcome->error->code );
		$this->family->allowed = false;
		self::assertSame( 'not_authorized', $this->service->attempt( $id, $p, RequestContext::create() )->outcome->error->code );
		self::assertSame( 0, $this->factory->opens ); self::assertSame( 0, $this->count_rows( 'operation_records' ) );
	}
	public function test_scope_hash_cannot_be_changed_to_read_a_private_completion(): void {
		$p = $this->create_payload(); $id = RuleProofEnvelope::identity( $p, 'rule.draft.create' ); $this->service->attempt( $id, $p, RequestContext::create() );
		$opens = $this->factory->opens; $p['scope'] = RuleProofEnvelope::scope( 'product', 5 );
		self::assertSame( 'invalid_input', $this->service->attempt( $id, $p, RequestContext::create() )->outcome->error->code );
		self::assertSame( $opens, $this->factory->opens ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
	}
	public function test_draft_edit_is_revision_guarded_and_no_change_has_no_event(): void {
		$this->create(); $p = $this->opened(); $unchanged = $this->attempt( 'rule.draft.edit', $p, 'unchanged' );
		self::assertSame( 'not_applicable', $unchanged->outcome->state ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) ); self::assertSame( 2, $this->guard()['revision'] );
		$p['payload']['availability'] = 'deny'; self::assertSame( 'accepted', $this->attempt( 'rule.draft.edit', $p, 'edit' )->outcome->state );
		self::assertSame( 'stale_revision', $this->attempt( 'rule.draft.edit', $p, 'old-editor' )->outcome->error->code );
		self::assertSame( [ 'availability' => 'deny' ], json_decode( $this->version()['payload_json'], true ) ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}
	public function test_immediate_sealing_changes_result_hash_without_changing_original_intent(): void {
		$this->create(); $p = $this->opened(); $id = RuleProofEnvelope::identity( $p, 'rule.publish', 'publish' );
		$command = RuleLifecycleCommand::from_payload( $id, $p, new RuleFamilyRegistry( [ $this->family ] ) );
		$published = $this->service->attempt( $id, $p, RequestContext::create() );
		self::assertSame( 'accepted', $published->outcome->state ); self::assertNotSame( $command->content_hash, $published->completion->result['content_hash'] ); self::assertNull( $p['effective_from'] );
		self::assertSame( $this->factory->clock, $this->version()['effective_from'] ); self::assertSame( $this->factory->clock, $this->version()['published_at'] );
		self::assertSame( $published->completion->to_json(), $this->service()->attempt( $id, $p, RequestContext::create() )->completion->to_json() ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}
	#[DataProvider( 'altered_metadata' )]
	public function test_sealing_rejects_altered_author_or_private_reason_without_event( string $key, mixed $value ): void {
		$this->create(); $before = $this->version(); $p = $this->opened(); $p[$key] = $value;
		self::assertSame( 'stale_revision', $this->attempt( 'rule.publish', $p, 'changed-' . $key )->outcome->error->code );
		self::assertSame( $before, $this->version() ); self::assertSame( 2, $this->guard()['revision'] ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
	}
	public static function altered_metadata(): array { return [ [ 'author_user_id', 10 ], [ 'change_reason', 'another private reason' ] ]; }
	public function test_early_original_activation_can_retry_and_late_acceptance_keeps_authored_start(): void {
		$p = $this->create_payload(); $p['start_mode'] = 'at'; $p['effective_from'] = '2026-10-06 21:00:00.000000'; $this->create( $p );
		self::assertSame( 'accepted', $this->attempt( 'rule.schedule', $this->opened(), 'schedule' )->outcome->state );
		[ $id, $payload ] = $this->activation(); $before = $this->version();
		self::assertSame( 'temporarily_unavailable', $this->service->attempt( $id, $payload, RequestContext::create() )->outcome->error->code ); self::assertSame( $before, $this->version() );
		$this->factory->clock = '2026-10-06 21:07:00.000000';
		$late = $this->service()->attempt( $id, $payload, RequestContext::create() );
		self::assertSame( 'accepted', $late->outcome->state ); self::assertTrue( $late->completion->result['late'] );
		self::assertSame( '2026-10-06 21:00:00.000000', $this->version()['effective_from'] ); self::assertSame( $this->factory->clock, $this->version()['published_at'] );
		[ $again_id, $again_payload ] = $this->activation(); self::assertSame( $id->namespace_digest(), $again_id->namespace_digest() ); self::assertSame( $payload, $again_payload );
		self::assertSame( $late->completion->to_json(), $this->service()->reconcile( $again_id, $again_payload, RequestContext::create() )->completion->to_json() ); self::assertSame( 3, $this->count_rows( 'operation_changes' ) );
	}
	public function test_expired_activation_and_revoked_original_author_leave_scheduled_bytes(): void {
		$p = $this->create_payload(); $p['start_mode'] = 'at'; $p['effective_from'] = '2026-10-06 21:00:00.000000'; $p['effective_until'] = '2026-10-06 21:05:00.000000'; $this->create( $p );
		$this->attempt( 'rule.schedule', $this->opened(), 'schedule' ); [ $id, $payload ] = $this->activation(); $before = $this->version();
		$this->factory->clock = '2026-10-06 21:05:00.000000'; self::assertSame( 'invalid_input', $this->service->attempt( $id, $payload, RequestContext::create() )->outcome->error->code ); self::assertSame( $before, $this->version() );
		$this->family->author_allowed = false; self::assertSame( 'not_authorized', $this->service->attempt( $id, $payload, RequestContext::create() )->outcome->error->code ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}
	public function test_audit_refusal_rolls_back_sealing_and_guard_then_original_request_can_apply(): void {
		$this->create(); $p = $this->opened(); $before = $this->version(); $this->factory->reject_audit = true;
		self::assertSame( 'temporarily_unavailable', $this->attempt( 'rule.publish', $p, 'publication' )->outcome->error->code );
		self::assertSame( $before, $this->version() ); self::assertSame( 2, $this->guard()['revision'] ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
		$this->factory->reject_audit = false; self::assertSame( 'accepted', $this->attempt( 'rule.publish', $p, 'publication' )->outcome->state ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}
	public function test_lost_ack_reconciliation_reads_accepted_rule_without_second_transition(): void {
		$this->create(); $p = $this->opened(); $id = RuleProofEnvelope::identity( $p, 'rule.publish', 'lost' ); $this->factory->lost_ack_at = $this->factory->commits + 2;
		$unknown = $this->service->attempt( $id, $p, RequestContext::create() ); self::assertSame( 'unconfirmed', $unknown->outcome->state ); self::assertNull( $unknown->completion );
		$before = $this->version(); $accepted = $this->service()->reconcile( $id, $p, RequestContext::create() );
		self::assertSame( 'accepted', $accepted->outcome->state ); self::assertTrue( $accepted->replayed ); self::assertSame( $before, $this->version() ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}
	public function test_unsent_commit_and_confirmed_rollback_have_no_accepted_sealing(): void {
		$this->create(); $p = $this->opened(); $before = $this->version(); $this->factory->not_sent_at = $this->factory->commits + 2;
		self::assertSame( 'rejected', $this->attempt( 'rule.publish', $p, 'unsent' )->outcome->state ); self::assertSame( $before, $this->version() ); self::assertSame( 2, $this->guard()['revision'] ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
	}
	public function test_authority_lost_after_commit_hides_completion_without_claiming_rejection(): void {
		$this->factory->revoke_at_commit = 2; $p = $this->create_payload(); $result = $this->attempt( 'rule.draft.create', $p );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertNull( $result->completion ); self::assertSame( 1, $this->count_rows( 'rule_versions' ) ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
	}
	#[DataProvider( 'corrupt_facts' )]
	public function test_corrupt_accepted_completion_preserves_row_and_refuses_replay( string $field, mixed $value ): void {
		$p = $this->create_payload(); $id = RuleProofEnvelope::identity( $p, 'rule.draft.create' ); $this->service->attempt( $id, $p, RequestContext::create() );
		$db = $this->factory->inspection(); $row = $db->query( 'SELECT * FROM unit_delivery_engine_operation_records' )->fetch( \PDO::FETCH_ASSOC ); $json = json_decode( $row['completion_json'], true, 64, JSON_THROW_ON_ERROR ); $json['result'][$field] = $value;
		$bytes = json_encode( $json, JSON_THROW_ON_ERROR ); $db->prepare( 'UPDATE unit_delivery_engine_operation_records SET completion_json=?' )->execute( [ $bytes ] );
		$result = $this->service()->reconcile( $id, $p, RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->outcome->state ); self::assertNull( $result->completion ); self::assertSame( $bytes, $db->query( 'SELECT completion_json FROM unit_delivery_engine_operation_records' )->fetchColumn() ); self::assertSame( (int) $row['row_version'], (int) $db->query( 'SELECT row_version FROM unit_delivery_engine_operation_records' )->fetchColumn() ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
	}
	public static function corrupt_facts(): array { return [ [ 'state', 'retired' ], [ 'predecessor_id', 1 ], [ 'predecessor_revision', 1 ], [ 'late', true ], [ 'accepted_at', 0 ], [ 'logical_revision', 3 ], [ 'content_hash', str_repeat( 'a', 64 ) ] ]; }
	public function test_retire_keeps_sealed_bytes_and_new_token_no_change_adds_no_event(): void {
		$this->create(); $this->attempt( 'rule.publish', $this->opened(), 'publish' ); $before = $this->version();
		self::assertSame( 'accepted', $this->attempt( 'rule.retire', $this->opened(), 'retire' )->outcome->state );
		foreach ( [ 'payload_json', 'content_hash', 'author_user_id', 'change_reason', 'effective_from', 'effective_until', 'published_at', 'sealed_at' ] as $field ) { self::assertSame( $before[$field], $this->version()[$field] ); }
		self::assertSame( 'not_applicable', $this->attempt( 'rule.retire', $this->opened(), 'already-retired' )->outcome->state ); self::assertSame( 3, $this->count_rows( 'operation_changes' ) ); self::assertNull( $this->logical()['current_published_version_id'] );
	}
	#[DataProvider( 'invalid_publication_times' )]
	public function test_future_backdated_and_nonfuture_schedule_refuse_without_transition( string $operation, string $from ): void {
		$p = $this->create_payload(); $p['start_mode'] = 'at'; $p['effective_from'] = $from; $this->create( $p );
		$before = $this->version(); $result = $this->attempt( $operation, $this->opened(), 'invalid-time' );
		self::assertSame( 'invalid_input', $result->outcome->error->code ); self::assertSame( $before, $this->version() ); self::assertSame( 2, $this->guard()['revision'] ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
	}
	public static function invalid_publication_times(): array { return [ [ 'rule.publish', '2026-10-06 21:00:00.000000' ], [ 'rule.publish', '2026-10-06 19:00:00.000000' ], [ 'rule.schedule', '2026-10-06 20:00:00.000001' ], [ 'rule.schedule', '2026-10-06 19:00:00.000000' ] ]; }
	public function test_database_clock_regression_preserves_draft_and_original_retry(): void {
		$this->create(); $p = $this->opened(); $before = $this->version(); $this->factory->clock = '2026-10-06 19:59:59.999999';
		self::assertSame( 'temporarily_unavailable', $this->attempt( 'rule.publish', $p, 'clock-regression' )->outcome->error->code ); self::assertSame( $before, $this->version() ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
		$this->factory->clock = '2026-10-06 20:00:01.000000'; self::assertSame( 'accepted', $this->attempt( 'rule.publish', $p, 'clock-regression' )->outcome->state ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}
	public function test_other_site_or_principal_cannot_replay_original_private_acceptance(): void {
		$p = $this->create_payload(); $id = RuleProofEnvelope::identity( $p, 'rule.draft.create' ); $accepted = $this->service->attempt( $id, $p, RequestContext::create() );
		$other_site = RuleProofEnvelope::identity( $p, 'rule.draft.create', 'original', 2 ); $site = $this->service()->attempt( $other_site, $p, RequestContext::create() );
		self::assertNull( $site->completion ); self::assertNotSame( 'accepted', $site->outcome->state );
		$other_actor = RuleProofEnvelope::identity( $p, 'rule.draft.create', 'original', 1, 'staff:10' ); $actor = $this->service()->attempt( $other_actor, $p, RequestContext::create() );
		self::assertSame( 'stale_revision', $actor->outcome->error->code ); self::assertFalse( $actor->replayed ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
		self::assertSame( $accepted->completion->to_json(), $this->service()->reconcile( $id, $p, RequestContext::create() )->completion->to_json() );
	}
	public function test_recreated_rule_uuid_cannot_satisfy_original_opened_row_preconditions(): void {
		$this->create(); $old = $this->opened(); $old['payload']['availability'] = 'deny'; $db = $this->factory->inspection();
		$db->exec( 'DELETE FROM unit_delivery_engine_rule_versions' ); $db->exec( 'DELETE FROM unit_delivery_engine_logical_rules' );
		$new = $this->create_payload(); $new['preconditions']['family_revision'] = 2; self::assertSame( 'accepted', $this->attempt( 'rule.draft.create', $new, 'recreated' )->outcome->state );
		$before = $this->version(); self::assertNotSame( $old['preconditions']['version_id'], (int) $before['id'] );
		self::assertSame( 'stale_revision', $this->attempt( 'rule.draft.edit', $old, 'old-opened-row' )->outcome->error->code ); self::assertSame( $before, $this->version() ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}

	public function test_pre_epoch_authored_draft_can_edit_and_replay_but_cannot_schedule_in_the_past(): void {
		$p = $this->create_payload(); $p['start_mode'] = 'at'; $p['effective_from'] = '1000-01-01 00:00:00.000001'; $p['effective_until'] = '1969-12-31 23:59:59.999999'; $this->create( $p );
		$edit = $this->opened(); $edit['effective_from'] = '1900-01-01 00:00:00.000001'; $result = $this->attempt( 'rule.draft.edit', $edit, 'pre-epoch-edit' );
		self::assertSame( 'accepted', $result->outcome->state ); self::assertSame( '1900-01-01 00:00:00.000001', $this->version()['effective_from'] );
		$id = RuleProofEnvelope::identity( $edit, 'rule.draft.edit', 'pre-epoch-edit' ); self::assertSame( $result->completion->to_json(), $this->service()->reconcile( $id, $edit, RequestContext::create() )->completion->to_json() );
		$before = $this->version(); self::assertSame( 'invalid_input', $this->attempt( 'rule.schedule', $this->opened(), 'past-schedule' )->outcome->error->code ); self::assertSame( $before, $this->version() ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
	}

	private function service(): RuleLifecycleService { return new RuleLifecycleService( new RuleFamilyRegistry( [ $this->family ] ), $this->factory, new RuleUnitReadiness() ); }
	private function create_payload(): array { return RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 2 ) ); }
	private function create( ?array $p = null ): void { $result = $this->attempt( 'rule.draft.create', $p ?? $this->create_payload() ); self::assertSame( 'accepted', $result->outcome->state ); }
	private function attempt( string $action, array $p, string $token = 'original' ): \CetechDeliveryEngine\Domain\Operation\OperationAttemptResult { return $this->service->attempt( RuleProofEnvelope::identity( $p, $action, $token ), $p, RequestContext::create() ); }
	private function row( string $suffix ): array { return $this->factory->inspection()->query( 'SELECT * FROM unit_delivery_engine_' . $suffix . ' ORDER BY id ASC LIMIT 1' )->fetch( \PDO::FETCH_ASSOC ); }
	private function version(): array { return $this->row( 'rule_versions' ); }
	private function logical(): array { return $this->row( 'logical_rules' ); }
	private function guard(): array { return $this->row( 'rule_family_guards' ); }
	private function count_rows( string $suffix ): int { return (int) $this->factory->inspection()->query( 'SELECT COUNT(*) FROM unit_delivery_engine_' . $suffix )->fetchColumn(); }
	private function opened(): array {
		$l = $this->logical(); $v = $this->version(); $g = $this->guard();
		return [ 'family' => $g['family_code'], 'logical_uuid' => $l['logical_uuid'], 'version_uuid' => $v['version_uuid'], 'scope' => json_decode( $l['scope_json'], true ), 'payload' => json_decode( $v['payload_json'], true ), 'start_mode' => $v['start_mode'], 'effective_from' => $v['effective_from'], 'effective_until' => $v['effective_until'], 'priority' => (int) $v['priority'], 'author_user_id' => (int) $v['author_user_id'], 'change_reason' => $v['change_reason'], 'predecessor_uuid' => null,
			'preconditions' => [ 'family_revision' => (int) $g['revision'], 'logical_id' => (int) $l['id'], 'logical_revision' => (int) $l['revision'], 'version_id' => (int) $v['id'], 'version_revision' => (int) $v['row_revision'], 'predecessor_id' => 0, 'predecessor_revision' => 0 ] ];
	}
	private function activation(): array {
		$guard = RuleFamilyGuard::from_row( $this->guard(), $this->family ); $logical = LogicalRule::from_row( $this->logical(), $guard, $this->family ); $version = RuleVersion::from_row( $this->version(), $logical, $this->family );
		return [ RuleLifecycleCommand::activation_identity( 1, $this->family, $logical, $version ), RuleLifecycleCommand::activation_payload( $this->family, $logical, $version, null ) ];
	}
}

final class RuleUnitReadiness implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} }
final class RuleUnitFactory implements OperationConnectionFactory {
	public string $clock = '2026-10-06 20:00:00.000001'; public int $opens = 0; public int $commits = 0; public bool $reject_audit = false; public ?int $lost_ack_at = null; public ?int $not_sent_at = null; public ?int $revoke_at_commit = null; public array $sessions = []; public array $sql = [];
	public function __construct( public readonly string $path, public readonly RuleProofFamily $family ) {}
	public function inspection(): \PDO { $dsn = 'sqlite:' . $this->path; $options = [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ]; return method_exists( \PDO::class, 'connect' ) ? \PDO::connect( $dsn, null, null, $options ) : new \PDO( $dsn, null, null, $options ); }
	public function open(): OperationSession { ++$this->opens; $s = new RuleUnitSession( $this ); $this->sessions[] = $s; return $s; }
	public function install(): void {
		$db = $this->inspection();
		foreach ( [ OperationStoreSchema::class, RuleLifecycleSchema::class ] as $schema ) {
			foreach ( $schema::SUFFIXES as $suffix ) {
				$columns = [];
				foreach ( $schema::columns( $suffix ) as $name => $facts ) {
					if ( 'id' === $name ) { $columns[] = 'id INTEGER PRIMARY KEY AUTOINCREMENT'; continue; }
					$column = $name . ( str_contains( $facts[0], 'int' ) ? ' INTEGER' : ' TEXT' ) . ( $facts[1] ? '' : ' NOT NULL' );
					if ( null !== $facts[2] ) { $column .= ' DEFAULT ' . $db->quote( $facts[2] ); } $columns[] = $column;
				}
				$table = 'unit_delivery_engine_' . $suffix; $db->exec( 'CREATE TABLE ' . $table . ' (' . implode( ',', $columns ) . ')' );
				foreach ( $schema::indexes( $suffix ) as $name => $facts ) { if ( 'PRIMARY' !== $name ) { $db->exec( 'CREATE ' . ( $facts['unique'] ? 'UNIQUE ' : '' ) . 'INDEX ' . $table . '_' . $name . ' ON ' . $table . '(' . implode( ',', $facts['columns'] ) . ')' ); } }
			}
		}
	}
}
final class RuleUnitSession implements OperationSession {
	private ?\PDO $db; private int $error = 0; private bool $retired = false; private array $tables = [];
	public function __construct( private readonly RuleUnitFactory $factory ) { $this->db = $factory->inspection(); $clock = fn(): string => $factory->clock; if ( method_exists( $this->db, 'createFunction' ) ) { $this->db->createFunction( 'UTC_NOW', $clock ); } else { $this->db->sqliteCreateFunction( 'UTC_NOW', $clock ); } }
	public function site_id(): int { return 1; } public function table_prefix(): string { return 'unit_'; } public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { $this->tables = []; return ! $this->retired && ! $this->in_transaction() && $this->db->beginTransaction(); }
	public function commit(): OperationCommitResult { ++$this->factory->commits; if ( $this->factory->not_sent_at === $this->factory->commits ) { $this->factory->not_sent_at = null; return OperationCommitResult::NotSent; } $this->db->commit(); if ( $this->factory->revoke_at_commit === $this->factory->commits ) { $this->factory->family->allowed = false; } if ( $this->factory->lost_ack_at === $this->factory->commits ) { $this->factory->lost_ack_at = null; $this->retire(); return OperationCommitResult::Unconfirmed; } return OperationCommitResult::Acknowledged; }
	public function rollback(): bool { return ! $this->retired && $this->db->inTransaction() && $this->db->rollBack(); }
	public function retire(): bool { if ( null !== $this->db && $this->db->inTransaction() ) { $this->db->rollBack(); } $this->db = null; $this->retired = true; return true; } public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return ! $this->retired && null !== $this->db && $this->db->inTransaction(); }
	public function validate_tables( array $tables ): bool { if ( ! $this->in_transaction() ) { return false; } foreach ( $tables as $table ) { if ( ! str_starts_with( $table, 'unit_' ) || false === $this->db->query( 'SELECT 1 FROM ' . $table . ' LIMIT 0' ) ) { return false; } $this->tables[$table] = true; } return true; }
	public function query( string $sql ): int|false {
		if ( ! $this->in_transaction() ) { return false; } $this->error = 0; $this->factory->sql[] = $sql;
		if ( $this->factory->reject_audit && str_starts_with( $sql, 'INSERT INTO `unit_delivery_engine_operation_changes`' ) ) { return false; }
		if ( 1 === preg_match( '/^(?:INSERT INTO|UPDATE) `([^`]+)`/', $sql, $match ) && ! isset( $this->tables[$match[1]] ) ) { return false; }
		try { return $this->db->exec( $this->sql( $sql ) ); } catch ( \PDOException $e ) { $this->error = str_contains( $e->getMessage(), 'UNIQUE constraint' ) ? 1062 : 1; return false; }
	}
	public function get_row( string $sql ): array|null|false { $rows = $this->get_results( $sql ); return false === $rows ? false : ( $rows[0] ?? null ); }
	public function get_results( string $sql ): array|false { if ( ! $this->in_transaction() ) { return false; } $this->factory->sql[] = $sql; try { return $this->db->query( $this->sql( $sql ) )->fetchAll( \PDO::FETCH_ASSOC ); } catch ( \PDOException ) { $this->error = 1; return false; } }
	public function prepare( string $sql, mixed ...$args ): string { $index = 0; return preg_replace_callback( '/%([ds])/', function ( array $m ) use ( $args, &$index ): string { $v = $args[$index++]; return 'd' === $m[1] ? (string) $v : $this->db->quote( $v ); }, $sql ); }
	public function errno(): int { return $this->error; } public function insert_id(): int { return (int) $this->db->lastInsertId(); }
	private function sql( string $sql ): string { return str_replace( [ ' FOR UPDATE', 'UTC_TIMESTAMP(6)' ], [ '', 'UTC_NOW()' ], $sql ); }
}
