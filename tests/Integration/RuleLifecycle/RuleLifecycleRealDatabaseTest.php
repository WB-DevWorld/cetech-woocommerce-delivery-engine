<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\RuleLifecycle;

use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleReadService;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleImpactPreviewService;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleActivationService;
use CetechDeliveryEngine\Domain\Contracts\ContractError;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleDecision;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleImpactPreview;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleEvaluator;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofClock;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofPopulation;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofObserver;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofDatabase;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFactory;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Real schema/session/process proofs. Explicit selection never silently skips. */
#[Group( 'rule-lifecycle-real-db' )]
final class RuleLifecycleRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $directories = [];
	protected function setUp(): void { $this->database = RuleProofDatabase::connect(); $this->prefix = RuleProofDatabase::prefix(); RuleProofDatabase::install( $this->database, $this->prefix ); }
	protected function tearDown(): void {
		foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { RuleProofDatabase::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function stack( ?RuleProofFamily $family = null, ?callable $configure = null, ?object $observer = null, string $clock = '2026-10-06 10:00:00.000000', int $site = 1 ): array {
		$family ??= new RuleProofFamily(); $factory = new RuleProofFactory( $this->prefix, $site, $configure, RuleTime::parse( $clock ) ); $this->factories[] = $factory;
		return [ new RuleLifecycleService( new RuleFamilyRegistry( [ $family ] ), $factory, observer: $observer ), $family, $factory ];
	}
	private function row_count( string $suffix ): int { return (int) RuleProofDatabase::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_{$suffix}`" ); }
	private function guard(): ?array { return RuleProofDatabase::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_rule_family_guards` ORDER BY id ASC LIMIT 1" ); }
	private function opened( int $logical = 1, int $version = 101, bool $activation = false ): array { return RuleProofEnvelope::opened( $this->database, $this->prefix, RuleProofEnvelope::uuid( $logical ), RuleProofEnvelope::uuid( $version ), $activation ); }
	private function version( int $identity = 101 ): array { return RuleProofEnvelope::version( $this->database, $this->prefix, RuleProofEnvelope::uuid( $identity ) ); }
	private function logical( int $identity = 1 ): array { return RuleProofEnvelope::logical( $this->database, $this->prefix, RuleProofEnvelope::uuid( $identity ) ); }
	private function attempt( object $service, string $operation, array $payload, string $token = 'original' ): object {
		$identity = 'rule.activate' === $operation ? RuleProofEnvelope::identity( $payload, $operation, $token, principal: 'rule-activation:' . $payload['version_uuid'], authority: 'rule.lifecycle.activation.v1' ) : RuleProofEnvelope::identity( $payload, $operation, $token );
		return $service->attempt( $identity, $payload, RequestContext::create() );
	}
	private function draft( object $service, int $logical = 1, int $version = 101, ?array $scope = null, string $value = 'allow' ): array {
		$payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( $logical ), RuleProofEnvelope::uuid( $version ), $scope ); $payload['payload']['availability'] = $value;
		$payload['preconditions']['family_revision'] = (int) ( $this->guard()['revision'] ?? 0 );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $payload, 'create-' . $version )->outcome->state );
		return $this->opened( $logical, $version );
	}
	private function successor( object $service, int $version = 102, string $value = 'deny' ): array {
		$logical = $this->logical(); $head = $this->version();
		$payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( $version ) ); $payload['payload']['availability'] = $value;
		$payload['predecessor_uuid'] = $head['version_uuid']; $payload['preconditions'] = [ 'family_revision' => (int) $this->guard()['revision'], 'logical_id' => (int) $logical['id'], 'logical_revision' => (int) $logical['revision'], 'version_id' => 0, 'version_revision' => 0, 'predecessor_id' => (int) $head['id'], 'predecessor_revision' => (int) $head['row_revision'] ];
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $payload, 'create-' . $version )->outcome->state );
		return $this->opened( 1, $version );
	}
	private function all_facts(): array {
		$facts = []; foreach ( [ ...RuleLifecycleSchema::SUFFIXES, 'operation_records', 'operation_changes' ] as $suffix ) { $result = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException( 'Disposable facts read failed.' ); } $facts[ $suffix ] = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); } return $facts;
	}
	private function directory(): string { $directory = OperationProofBarrier::directory(); $this->directories[] = $directory; return $directory; }
	private function worker( array $arguments ): OperationProofProcess { return new OperationProofProcess( [ __DIR__ . '/process-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $arguments, JSON_THROW_ON_ERROR ) ] ); }
	private function scheduled( object $service, int $version = 101, bool $successor = false, string $from = '2026-10-06 10:05:00.000000', ?string $until = null ): array {
		$draft = $successor ? $this->successor( $service, $version ) : $this->draft( $service, version: $version );
		$draft['start_mode'] = 'at'; $draft['effective_from'] = $from; $draft['effective_until'] = $until;
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $draft, 'dated-' . $version )->outcome->state );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.schedule', $this->opened( version: $version ), 'schedule-' . $version )->outcome->state );
		return $this->opened( version: $version, activation: true );
	}
	private function rule_facts(): array { $facts = $this->all_facts(); return array_intersect_key( $facts, array_fill_keys( RuleLifecycleSchema::SUFFIXES, true ) ); }

	public function test_real_schema_metadata_readiness_and_disposable_sql_clock(): void {
		[ , , $factory ] = $this->stack( clock: '2026-10-06 10:00:00.123456' ); $session = $factory->open();
		self::assertSame( [ 'ready' => true, 'code' => 'ready' ], ( new RuleLifecycleReadiness( $session ) )->get_status() );
		self::assertTrue( $session->begin() ); self::assertSame( '2026-10-06 10:00:00.123456', $session->get_row( 'SELECT UTC_TIMESTAMP(6) AS instant' )['instant'] ); self::assertTrue( $session->rollback() );
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) {
			$table = $this->prefix . 'delivery_engine_' . $suffix; self::assertSame( 'InnoDB', RuleProofDatabase::row( $this->database, "SHOW TABLE STATUS WHERE Name='{$table}'" )['Engine'] );
			$indexes = $this->database->query( "SHOW INDEX FROM `{$table}`" )->fetch_all( MYSQLI_ASSOC ); foreach ( $indexes as $index ) { self::assertNull( $index['Sub_part'] ); }
		}
		self::assertSame( 0, $this->row_count( 'rule_family_guards' ) ); self::assertSame( 0, $this->row_count( 'operation_records' ) );
	}

	public function test_create_edit_no_change_publish_retire_preserve_sealed_history_and_original_receipt(): void {
		[ $service, $family, $factory ] = $this->stack(); $draft = $this->draft( $service ); $before = $this->all_facts();
		$changed_intent = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) ); $changed_intent['payload']['availability'] = 'deny'; $conflict = $this->attempt( $service, 'rule.draft.create', $changed_intent, 'create-101' ); self::assertSame( 'intent_conflict', $conflict->outcome->error->code ); self::assertNull( $conflict->completion ); self::assertSame( $before, $this->all_facts() );
		$unchanged = $this->attempt( $service, 'rule.draft.edit', $draft, 'no-change' ); self::assertSame( 'not_applicable', $unchanged->outcome->state ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( $before['rule_versions'], $this->all_facts()['rule_versions'] );
		$edited = $this->opened(); $edited['payload']['availability'] = 'deny'; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $edited, 'edit' )->outcome->state );
		$publish_payload = $this->opened(); $published = $this->attempt( $service, 'rule.publish', $publish_payload, 'publish' ); self::assertSame( 'accepted', $published->outcome->state ); $sealed = $this->version();
		self::assertSame( 'published', $sealed['state'] ); self::assertSame( '2026-10-06 10:00:00.000000', $sealed['published_at'] ); self::assertSame( $sealed['id'], $this->logical()['current_published_version_id'] );
		$retire = $this->opened(); self::assertSame( 'accepted', $this->attempt( $service, 'rule.retire', $retire, 'retire' )->outcome->state ); $retired = $this->version();
		self::assertSame( 'retired', $retired['state'] ); self::assertNull( $this->logical()['current_published_version_id'] ); self::assertSame( $sealed['payload_json'], $retired['payload_json'] ); self::assertSame( $sealed['content_hash'], $retired['content_hash'] ); self::assertSame( $sealed['effective_from'], $retired['effective_from'] );
		$replay = $this->attempt( $service, 'rule.publish', $publish_payload, 'publish' ); self::assertTrue( $replay->replayed ); self::assertSame( $published->completion->result, $replay->completion->result ); self::assertSame( $retired, $this->version() ); self::assertSame( 4, $this->row_count( 'operation_changes' ) );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $retired_decision = $reader->decision( RuleProofEnvelope::identity( $publish_payload, 'rule.publish', 'read-retired' ), $family->family(), $publish_payload['scope'], $publish_payload['scope'], RuleTime::parse( '2026-10-06 10:00:00.000000' ), RequestContext::create() ); self::assertInstanceOf( RuleDecision::class, $retired_decision ); self::assertNull( $retired_decision->selected ); self::assertSame( 'rule_unavailable', $retired_decision->reason );
		self::assertStringNotContainsString( 'PRIVATE_SYNTHETIC_CHANGE_REASON', json_encode( $published->completion->result, JSON_THROW_ON_ERROR ) );
	}

	public function test_schedule_seals_and_early_original_activation_retry_accepts_when_due(): void {
		[ $service, , $factory ] = $this->stack(); $draft = $this->draft( $service );
		$edit = $draft; $edit['start_mode'] = 'at'; $edit['effective_from'] = '2026-10-06 10:05:00.000000'; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $edit, 'dated' )->outcome->state );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.schedule', $this->opened(), 'schedule' )->outcome->state ); $sealed = $this->version(); $original = $this->opened( activation: true );
		self::assertSame( 'scheduled', $sealed['state'] ); self::assertNotNull( $sealed['scheduled_revision'] ); self::assertNotNull( $sealed['scheduled_logical_revision'] );
		$early = $this->attempt( $service, 'rule.activate', $original, 'activation' ); self::assertSame( 'rejected', $early->outcome->state ); self::assertSame( 'temporarily_unavailable', $early->outcome->error->code ); self::assertSame( $sealed, $this->version() ); self::assertNull( $this->logical()['current_published_version_id'] );
		$factory->clock = RuleTime::parse( '2026-10-06 10:05:00.000000' ); $due = $this->attempt( $service, 'rule.activate', $original, 'activation' ); self::assertSame( 'accepted', $due->outcome->state ); self::assertSame( 'published', $this->version()['state'] ); self::assertSame( '2026-10-06 10:05:00.000000', $this->version()['published_at'] ); self::assertFalse( $due->completion->result['late'] );
	}

	#[DataProvider( 'rollback_faults' )]
	public function test_event_completion_and_proven_unsent_commit_roll_back_all_rule_effects( string $fault ): void {
		[ $service, , $factory ] = $this->stack( configure: static function ( RuleProofTransport $transport, int $open ) use ( $fault ): void {
			if ( 1 !== $open ) { return; } if ( 'audit' === $fault ) { $transport->reject_audit = true; } elseif ( 'completion' === $fault ) { $transport->reject_completion = true; } else { $transport->fault_commit = 2; $transport->commit_fault = 'unsent'; }
		} );
		$original = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) );
		$first = $this->attempt( $service, 'rule.draft.create', $original ); self::assertSame( 'rejected', $first->outcome->state );
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { self::assertSame( 0, $this->row_count( $suffix ) ); } self::assertSame( 0, $this->row_count( 'operation_changes' ) );
		$retry = $this->attempt( $service, 'rule.draft.create', $original ); self::assertSame( 'accepted', $retry->outcome->state ); self::assertSame( 1, $this->row_count( 'rule_versions' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertGreaterThan( 1, count( $factory->transports ) );
	}
	public static function rollback_faults(): array { return [ [ 'audit' ], [ 'completion' ], [ 'unsent' ] ]; }

	public function test_actual_rule_commit_then_injected_lost_ack_reconciles_without_second_transition(): void {
		[ $service, , $factory ] = $this->stack( configure: static function ( RuleProofTransport $transport, int $open ): void { if ( 1 === $open ) { $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } } );
		$original = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) ); $identity = RuleProofEnvelope::identity( $original, 'rule.draft.create' );
		$lost = $service->attempt( $identity, $original, RequestContext::create() ); self::assertSame( 'unconfirmed', $lost->outcome->state ); self::assertSame( 2, $factory->transports[0]->sent_commits ); $facts = $this->all_facts();
		self::assertSame( 'draft', $this->version()['state'] ); self::assertSame( 'accepted', $facts['operation_records'][0]['state'] );
		$resolved = $service->reconcile( $identity, $original, RequestContext::create() ); self::assertSame( 'accepted', $resolved->outcome->state ); self::assertTrue( $resolved->replayed ); self::assertSame( $facts, $this->all_facts() );
	}

	public function test_late_activation_keeps_authored_start_and_cuts_over_at_real_acceptance_only(): void {
		[ $service, , $factory ] = $this->stack(); $this->draft( $service ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened(), 'first-publish' )->outcome->state );
		$original = $this->scheduled( $service, 102, true ); $sealed = $this->version( 102 ); $before = $this->version();
		$factory->clock = RuleTime::parse( '2026-10-06 10:07:00.000000' ); $late = $this->attempt( $service, 'rule.activate', $original, 'activation' ); self::assertSame( 'accepted', $late->outcome->state ); self::assertTrue( $late->completion->result['late'] );
		$after = $this->version( 102 ); $retired = $this->version(); self::assertSame( '2026-10-06 10:05:00.000000', $after['effective_from'] ); self::assertSame( '2026-10-06 10:07:00.000000', $after['published_at'] ); self::assertSame( $after['published_at'], $retired['retired_at'] ); self::assertSame( 'retired', $retired['state'] ); self::assertSame( $after['id'], $this->logical()['current_published_version_id'] );
		foreach ( [ 'payload_json', 'content_hash', 'effective_from', 'effective_until', 'scheduled_revision', 'scheduled_logical_revision', 'scheduled_predecessor_row_revision' ] as $field ) { self::assertSame( $sealed[$field], $after[$field] ); }
		self::assertSame( $before['effective_from'], $retired['effective_from'] ); self::assertSame( $before['effective_until'], $retired['effective_until'] );
	}

	public function test_expired_or_revoked_activation_never_shortens_or_resurrects_predecessor(): void {
		[ $service, $family, $factory ] = $this->stack(); $draft = $this->draft( $service ); $draft['effective_until'] = '2026-10-06 10:03:00.000000'; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $draft, 'end-old' )->outcome->state ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened(), 'first-publish' )->outcome->state );
		$original = $this->scheduled( $service, 102, true, until: '2026-10-06 10:06:00.000000' ); $before = $this->rule_facts(); $events = $this->row_count( 'operation_changes' );
		$factory->clock = RuleTime::parse( '2026-10-06 10:05:00.000000' ); $family->author_allowed = false; $revoked = $this->attempt( $service, 'rule.activate', $original, 'activation' ); self::assertSame( 'rejected', $revoked->outcome->state ); self::assertSame( $before, $this->rule_facts() );
		$family->author_allowed = true; $factory->clock = RuleTime::parse( '2026-10-06 10:07:00.000000' ); $expired = $this->attempt( $service, 'rule.activate', $original, 'activation' ); self::assertSame( 'rejected', $expired->outcome->state ); self::assertSame( $before, $this->rule_facts() ); self::assertSame( $events, $this->row_count( 'operation_changes' ) ); self::assertSame( 'scheduled', $this->version( 102 )['state'] ); self::assertSame( '2026-10-06 10:03:00.000000', $this->version()['effective_until'] );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $actor = RuleProofEnvelope::identity( $original, 'rule.publish', 'gap-read' );
		foreach ( [ '2026-10-06 10:04:00.000000', '2026-10-06 10:07:00.000000' ] as $instant ) { $decision = $reader->decision( $actor, $family->family(), $original['scope'], $original['scope'], RuleTime::parse( $instant ), RequestContext::create() ); self::assertInstanceOf( RuleDecision::class, $decision ); self::assertNull( $decision->selected ); self::assertSame( 'rule_unavailable', $decision->reason ); }
		self::assertSame( $before, $this->rule_facts() ); self::assertSame( $events, $this->row_count( 'operation_changes' ) );
	}

	public function test_scheduled_edit_refuses_and_cancelled_replacement_has_new_sequence_and_uuid(): void {
		[ $service ] = $this->stack(); $this->scheduled( $service ); $sealed = $this->version(); $edit = $this->opened(); $edit['payload']['availability'] = 'deny';
		self::assertSame( 'rejected', $this->attempt( $service, 'rule.draft.edit', $edit, 'edit-sealed' )->outcome->state ); self::assertSame( $sealed, $this->version() );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.retire', $this->opened(), 'cancel' )->outcome->state );
		$logical = $this->logical(); $replacement = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 102 ) ); $replacement['preconditions']['family_revision'] = (int) $this->guard()['revision']; $replacement['preconditions']['logical_id'] = (int) $logical['id']; $replacement['preconditions']['logical_revision'] = (int) $logical['revision'];
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $replacement, 'replacement' )->outcome->state ); self::assertSame( 'retired', $this->version()['state'] ); self::assertSame( 'draft', $this->version( 102 )['state'] ); self::assertSame( '2', (string) $this->version( 102 )['version_sequence'] ); self::assertSame( $sealed['payload_json'], $this->version()['payload_json'] ); self::assertSame( $sealed['content_hash'], $this->version()['content_hash'] );
	}

	public function test_two_process_publish_tokens_one_original_revision_accept_once_then_loser_is_stale(): void {
		[ $service ] = $this->stack(); $payload = $this->draft( $service ); $directory = $this->directory();
		$workers = []; foreach ( [ 'left', 'right' ] as $side ) { $workers[] = $this->worker( [ 'operation' => 'rule.publish', 'payload' => $payload, 'token' => $side, 'start_ready' => $directory . '/' . $side, 'start_release' => $directory . '/go' ] ); }
		OperationProofProcess::wait_for( $directory . '/left' ); OperationProofProcess::wait_for( $directory . '/right' ); OperationProofBarrier::signal( $directory . '/go' ); $results = [ $workers[0]->finish(), $workers[1]->finish() ];
		self::assertSame( 1, count( array_filter( $results, static fn( array $result ): bool => 'accepted' === $result['state'] ) ) ); $loser = 'accepted' === $results[0]['state'] ? 1 : 0;
		self::assertSame( 'rejected', $results[$loser]['state'] ); self::assertContains( $results[$loser]['error_code'], [ 'stale_revision', 'temporarily_unavailable' ] );
		$retry = $this->attempt( $service, 'rule.publish', $payload, 0 === $loser ? 'left' : 'right' ); self::assertSame( 'stale_revision', $retry->outcome->error->code ); self::assertSame( 2, $this->row_count( 'operation_changes' ) ); self::assertSame( 1, $this->row_count( 'rule_versions' ) ); self::assertSame( 'published', $this->version()['state'] );
	}

	public function test_missing_first_family_guard_race_has_one_generation_and_no_duplicate_guard(): void {
		$directory = $this->directory(); $workers = [];
		foreach ( [ 'left' => [ 1, 101, RuleProofEnvelope::scope( 'product', 10 ) ], 'right' => [ 2, 102, RuleProofEnvelope::scope( 'variation', 11, 10 ) ] ] as $side => [$logical,$version,$scope] ) {
			$payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( $logical ), RuleProofEnvelope::uuid( $version ), $scope ); $workers[] = $this->worker( [ 'operation' => 'rule.draft.create', 'payload' => $payload, 'token' => $side, 'start_ready' => $directory . '/' . $side, 'start_release' => $directory . '/go' ] );
		}
		OperationProofProcess::wait_for( $directory . '/left' ); OperationProofProcess::wait_for( $directory . '/right' ); OperationProofBarrier::signal( $directory . '/go' ); $results = [ $workers[0]->finish(), $workers[1]->finish() ];
		self::assertSame( 1, count( array_filter( $results, static fn( array $result ): bool => 'accepted' === $result['state'] ) ) ); self::assertSame( 1, $this->row_count( 'rule_family_guards' ) ); self::assertSame( '2', (string) $this->guard()['revision'] ); self::assertSame( 1, $this->row_count( 'logical_rules' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_actual_repeatable_read_snapshot_cannot_override_newer_current_guard_and_version(): void {
		[ $creator ] = $this->stack(); $payload = $this->draft( $creator ); $observed = []; $winner = null;
		[ $older ] = $this->stack( configure: function ( RuleProofTransport $transport, int $open ) use ( &$observed, &$winner ): void {
			if ( 1 !== $open ) { return; }
			$transport->before_record_lock = function ( object $native ) use ( &$observed, &$winner ): void {
				$sql = "SELECT row_revision FROM `{$this->prefix}delivery_engine_rule_versions` WHERE id=1"; $observed[] = (int) $native->execute( $sql )->rows[0]['row_revision'];
				[ $newer ] = $this->stack(); $edit = $this->opened(); $edit['payload']['availability'] = 'deny'; $winner = $this->attempt( $newer, 'rule.draft.edit', $edit, 'newer-edit' );
				$observed[] = (int) $native->execute( $sql )->rows[0]['row_revision'];
			};
		} );
		$result = $this->attempt( $older, 'rule.publish', $payload, 'older-publish' ); self::assertSame( [ 1, 1 ], $observed ); self::assertSame( 'accepted', $winner->outcome->state ); self::assertSame( 'stale_revision', $result->outcome->error->code ); self::assertSame( 'draft', $this->version()['state'] ); self::assertSame( '{"availability":"deny"}', $this->version()['payload_json'] ); self::assertSame( '2', (string) $this->version()['row_revision'] ); self::assertSame( 2, $this->row_count( 'operation_changes' ) );
	}

	#[DataProvider( 'process_death_windows' )]
	public function test_real_process_death_before_or_after_commit_reconciles_truth_without_second_transition( string $window ): void {
		$payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) ); $directory = $this->directory();
		$arguments = [ 'operation' => 'rule.draft.create', 'payload' => $payload, 'token' => 'crashed' ];
		if ( 'after_commit' === $window ) { $arguments += [ 'commit_ready' => $directory . '/paused', 'commit_release' => $directory . '/unused' ]; } else { $arguments += [ 'phase' => $window, 'phase_ready' => $directory . '/paused', 'phase_release' => $directory . '/unused' ]; }
		$worker = $this->worker( $arguments ); OperationProofProcess::wait_for( $directory . '/paused' ); $committed = 'after_commit' === $window;
		self::assertSame( $committed ? 1 : 0, $this->row_count( 'rule_versions' ) ); self::assertSame( $committed ? 1 : 0, $this->row_count( 'operation_changes' ) ); self::assertSame( 9, $worker->kill_and_wait() );
		[ $service ] = $this->stack(); $identity = RuleProofEnvelope::identity( $payload, 'rule.draft.create', 'crashed' ); $resolved = $service->reconcile( $identity, $payload, RequestContext::create() ); self::assertSame( $committed ? 'accepted' : 'rejected', $resolved->outcome->state );
		self::assertSame( $committed ? 1 : 0, $this->row_count( 'rule_versions' ) ); self::assertSame( $committed ? 1 : 0, $this->row_count( 'operation_changes' ) );
		$retry = $service->attempt( $identity, $payload, RequestContext::create() ); self::assertSame( 'accepted', $retry->outcome->state ); self::assertSame( $committed, $retry->replayed ); self::assertSame( 1, $this->row_count( 'rule_versions' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public static function process_death_windows(): array { return [ [ 'reservation_committed' ], [ 'effect_mutated' ], [ 'before_effect_commit' ], [ 'after_commit' ] ]; }

	public function test_fresh_process_original_publish_replay_after_101_later_material_events(): void {
		[ $service ] = $this->stack(); $this->draft( $service ); $original = $this->opened(); $first = $this->attempt( $service, 'rule.publish', $original, 'old-publish' ); self::assertSame( 'accepted', $first->outcome->state );
		$original_version = $this->version();
		$this->successor( $service );
		for ( $number = 0; $number < 101; ++$number ) { $edit = $this->opened( version: 102 ); $edit['priority'] = $number + 1; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $edit, 'edit-' . $number )->outcome->state ); }
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened( version: 102 ), 'new-publish' )->outcome->state ); $before = $this->all_facts();
		$fresh = $this->worker( [ 'operation' => 'rule.publish', 'payload' => $original, 'token' => 'old-publish' ] )->finish(); self::assertSame( 'accepted', $fresh['state'] ); self::assertTrue( $fresh['replayed'] ); self::assertSame( $first->completion->result, $fresh['result'] ); self::assertSame( $before, $this->all_facts() ); self::assertSame( 105, $this->row_count( 'operation_changes' ) ); self::assertSame( $original_version['payload_json'], $this->version()['payload_json'] ); self::assertSame( $this->version( 102 )['id'], $this->logical()['current_published_version_id'] );
	}

	public function test_actual_second_connection_sees_whole_cutover_only_after_commit(): void {
		[ $service, $family, $factory ] = $this->stack(); $this->draft( $service ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened(), 'first-publish' )->outcome->state ); $successor = $this->successor( $service ); $directory = $this->directory();
		$worker = $this->worker( [ 'operation' => 'rule.publish', 'payload' => $successor, 'token' => 'cutover', 'phase' => 'before_effect_commit', 'phase_ready' => $directory . '/staged', 'phase_release' => $directory . '/go' ] );
		OperationProofProcess::wait_for( $directory . '/staged' );
		$sql = "SELECT l.current_published_version_id AS head,o.state AS old_state,o.retired_at,n.state AS new_state,n.published_at FROM `{$this->prefix}delivery_engine_logical_rules` l JOIN `{$this->prefix}delivery_engine_rule_versions` o ON o.id=1 JOIN `{$this->prefix}delivery_engine_rule_versions` n ON n.id=2 WHERE l.id=1";
		$before = RuleProofDatabase::row( $this->database, $sql ); self::assertSame( '1', (string) $before['head'] ); self::assertSame( 'published', $before['old_state'] ); self::assertNull( $before['retired_at'] ); self::assertSame( 'draft', $before['new_state'] ); self::assertNull( $before['published_at'] ); self::assertSame( 3, $this->row_count( 'operation_changes' ) );
		OperationProofBarrier::signal( $directory . '/go' ); self::assertSame( 'accepted', $worker->finish()['state'] ); $after = RuleProofDatabase::row( $this->database, $sql ); self::assertSame( '2', (string) $after['head'] ); self::assertSame( 'retired', $after['old_state'] ); self::assertSame( 'published', $after['new_state'] ); self::assertSame( $after['retired_at'], $after['published_at'] ); self::assertSame( 4, $this->row_count( 'operation_changes' ) );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $decision = $reader->decision( RuleProofEnvelope::identity( $successor, 'rule.publish', 'read-cutover' ), $family->family(), $successor['scope'], $successor['scope'], RuleTime::parse( '2026-10-06 10:00:00.000000' ), RequestContext::create() ); self::assertInstanceOf( RuleDecision::class, $decision ); self::assertSame( 2, $decision->selected->version_id );
	}

	public function test_real_family_guard_lock_timeout_preserves_draft_and_known_history(): void {
		[ $service ] = $this->stack(); $draft = $this->draft( $service ); $before = $this->rule_facts(); $directory = $this->directory();
		$worker = $this->worker( [ 'operation' => 'rule.publish', 'payload' => $draft, 'token' => 'waiting', 'phase' => 'reservation_committed', 'phase_ready' => $directory . '/reserved', 'phase_release' => $directory . '/go' ] ); OperationProofProcess::wait_for( $directory . '/reserved' );
		$locker = RuleProofDatabase::connect(); $locker->begin_transaction(); RuleProofDatabase::row( $locker, "SELECT * FROM `{$this->prefix}delivery_engine_rule_family_guards` WHERE id=1 FOR UPDATE" ); $started = microtime( true ); OperationProofBarrier::signal( $directory . '/go' ); $result = $worker->finish(); $elapsed = microtime( true ) - $started; $locker->rollback(); $locker->close();
		self::assertSame( 1, $result['lock_wait_timeouts'] ); self::assertGreaterThanOrEqual( 1.7, $elapsed ); self::assertLessThan( 5.0, $elapsed ); self::assertNotSame( 'accepted', $result['state'] ); self::assertSame( $before, $this->rule_facts() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
		$resolved = $service->reconcile( RuleProofEnvelope::identity( $draft, 'rule.publish', 'waiting' ), $draft, RequestContext::create() ); self::assertSame( 'rejected', $resolved->outcome->state ); self::assertSame( $before, $this->rule_facts() );
	}

	public function test_denied_and_revoked_replay_never_open_private_lookup_or_disclose_receipt(): void {
		[ $service, $family, $factory ] = $this->stack(); $payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) ); $family->allowed = false;
		$denied = $this->attempt( $service, 'rule.draft.create', $payload ); self::assertSame( 'not_authorized', $denied->outcome->error->code ); self::assertCount( 0, $factory->sessions );
		$family->allowed = true; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $payload )->outcome->state ); $facts = $this->all_facts(); $opened = count( $factory->sessions ); $family->allowed = false;
		$revoked = $this->attempt( $service, 'rule.draft.create', $payload ); self::assertSame( 'not_authorized', $revoked->outcome->error->code ); self::assertNull( $revoked->completion ); self::assertCount( $opened, $factory->sessions ); self::assertSame( $facts, $this->all_facts() );
	}

	public function test_current_policy_corruption_is_unknown_without_rewriting_history(): void {
		[ $service ] = $this->stack(); $draft = $this->draft( $service ); RuleProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_rule_family_guards` SET policy_hash='" . str_repeat( 'a', 64 ) . "' WHERE id=1" ); $before = $this->rule_facts();
		$result = $this->attempt( $service, 'rule.publish', $draft, 'unknown-policy' ); self::assertNotSame( 'accepted', $result->outcome->state ); self::assertSame( $before, $this->rule_facts() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}

	public function test_different_hierarchical_logicals_publish_through_same_family_guard_without_overlap_gap(): void {
		[ $service ] = $this->stack(); $this->draft( $service, scope: RuleProofEnvelope::scope( 'product', 10 ) ); $this->draft( $service, 2, 102, RuleProofEnvelope::scope( 'variation', 11, 10 ) ); $payloads = [ $this->opened(), $this->opened( 2, 102 ) ]; $directory = $this->directory();
		$workers = []; foreach ( [ 'left', 'right' ] as $index => $side ) { $workers[] = $this->worker( [ 'operation' => 'rule.publish', 'payload' => $payloads[$index], 'token' => $side, 'start_ready' => $directory . '/' . $side, 'start_release' => $directory . '/go' ] ); }
		OperationProofProcess::wait_for( $directory . '/left' ); OperationProofProcess::wait_for( $directory . '/right' ); OperationProofBarrier::signal( $directory . '/go' ); $results = [ $workers[0]->finish(), $workers[1]->finish() ]; $loser = 'accepted' === $results[0]['state'] ? 1 : 0;
		self::assertSame( 1, count( array_filter( $results, static fn( array $result ): bool => 'accepted' === $result['state'] ) ) ); self::assertNotSame( 'accepted', $results[$loser]['state'] ); self::assertSame( 3, $this->row_count( 'operation_changes' ) ); self::assertSame( '4', (string) $this->guard()['revision'] );
		$retry = $this->attempt( $service, 'rule.publish', $payloads[$loser], 0 === $loser ? 'left' : 'right' ); self::assertSame( 'stale_revision', $retry->outcome->error->code ); self::assertSame( 3, $this->row_count( 'operation_changes' ) );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened( $loser + 1, $loser + 101 ), 'new-opened-publish' )->outcome->state ); self::assertSame( '5', (string) $this->guard()['revision'] ); self::assertSame( 4, $this->row_count( 'operation_changes' ) ); self::assertSame( 'published', $this->version()['state'] ); self::assertSame( 'published', $this->version( 102 )['state'] );
	}

	public function test_retired_scheduling_predecessor_makes_original_activation_stale_and_fresh_process_preserves_original_envelope(): void {
		[ $service, $family, $factory ] = $this->stack(); $this->draft( $service ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened(), 'first-publish' )->outcome->state ); $this->scheduled( $service, 102, true );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $sealed = $this->opened( version: 102, activation: true );
		$actor = RuleProofEnvelope::identity( $sealed, 'rule.activate', 'activation-host', principal: 'rule-activation:' . $sealed['version_uuid'], authority: 'rule.lifecycle.activation.v1' );
		$original = $reader->activation_envelope( $actor, $family->family(), $sealed['version_uuid'], $sealed['scope'], RequestContext::create() ); self::assertIsArray( $original ); $intent = RuleLifecycleCommand::from_payload( $original['identity'], $original['payload'], new RuleFamilyRegistry( [ $family ] ) )->intent()->fingerprint();
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.retire', $this->opened(), 'retire-predecessor' )->outcome->state ); $before = $this->rule_facts(); $events = $this->row_count( 'operation_changes' );
		$fresh = $this->worker( [ 'action' => 'reconstruct', 'operation' => 'rule.activate', 'payload' => $sealed, 'token' => 'activation-host', 'principal' => $actor->principal, 'authority' => $actor->authority ] )->finish();
		self::assertSame( 'reconstructed', $fresh['state'] ); self::assertSame( $original['identity']->namespace_digest(), $fresh['namespace_digest'] ); self::assertSame( $intent, $fresh['intent_digest'] ); self::assertSame( $original['payload']['preconditions'], $fresh['preconditions'] ); self::assertNotSame( (int) $this->logical()['revision'], $fresh['preconditions']['logical_revision'] ); self::assertNotSame( (int) $this->version()['row_revision'], $fresh['preconditions']['predecessor_revision'] );
		$factory->clock = RuleTime::parse( '2026-10-06 10:05:00.000000' ); $stale = $service->attempt( $original['identity'], $original['payload'], RequestContext::create() ); self::assertSame( 'stale_revision', $stale->outcome->error->code ); self::assertSame( $before, $this->rule_facts() ); self::assertSame( $events, $this->row_count( 'operation_changes' ) ); self::assertNull( $this->logical()['current_published_version_id'] ); self::assertSame( 'scheduled', $this->version( 102 )['state'] );
	}

	public function test_actual_decision_start_inclusive_end_exclusive_and_no_expired_resurrection(): void {
		[ $service, $family, $factory ] = $this->stack(); $draft = $this->draft( $service ); $draft['effective_until'] = '2026-10-06 10:05:00.000000'; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $draft, 'bounded' )->outcome->state ); $publish = $this->opened();
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $actor = RuleProofEnvelope::identity( $publish, 'rule.publish', 'read' ); $scope = $publish['scope']; $request = RequestContext::create();
		$draft_decision = $reader->decision( $actor, $family->family(), $scope, $scope, RuleTime::parse( '2026-10-06 10:00:00.000000' ), $request ); self::assertInstanceOf( RuleDecision::class, $draft_decision ); self::assertNull( $draft_decision->selected );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $publish, 'publish' )->outcome->state ); $before = $this->all_facts();
		foreach ( [ '2026-10-06 09:59:59.999999' => false, '2026-10-06 10:00:00.000000' => true, '2026-10-06 10:04:59.999999' => true, '2026-10-06 10:05:00.000000' => false, '2026-10-06 10:07:00.000000' => false ] as $instant => $eligible ) {
			$decision = $reader->decision( $actor, $family->family(), $scope, $scope, RuleTime::parse( $instant ), $request ); self::assertInstanceOf( RuleDecision::class, $decision ); self::assertSame( $eligible, null !== $decision->selected ); if ( $eligible ) { self::assertSame( 1, $decision->selected->version_id ); }
		}
		self::assertSame( $before, $this->all_facts() ); self::assertSame( 'published', $this->version()['state'] ); self::assertSame( '1', (string) $this->logical()['current_published_version_id'] );
	}

	public function test_actual_preview_same_evaluator_overlay_parity_zero_writes_and_stale_mutation_refusal(): void {
		[ $service, $family, $factory ] = $this->stack(); $draft = $this->draft( $service ); $actor = RuleProofEnvelope::identity( $draft, 'rule.publish', 'preview' ); $request = RequestContext::create(); $at = RuleTime::parse( '2026-10-06 10:00:00.000000' );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $snapshot = $reader->capture( $actor, $family->family(), $draft['scope'], $request ); self::assertNotInstanceOf( ContractError::class, $snapshot ); $candidate = $snapshot->candidates()[0];
		$row = $candidate->version->row(); $row['state'] = 'published'; $row['effective_from'] = $at->sql(); $row['sealed_at'] = $at->sql(); $row['published_at'] = $at->sql(); $row['content_hash'] = RuleContent::hash( $family, $candidate->version->payload(), $candidate->version->start_mode, $at, null, 0, null );
		$proposed = new RuleCandidate( $candidate->logical, RuleVersion::hypothetical( $row, $candidate->logical, $family ) ); $previewer = new RuleImpactPreviewService( $reader, clock: new RuleProofClock( $at ) ); $before = $this->all_facts();
		$preview = $previewer->preview( $actor, $family->family(), $draft['scope'], [ $draft['scope'] ], $proposed, $at, $request ); self::assertInstanceOf( RuleImpactPreview::class, $preview ); self::assertFalse( $preview->hypothetical_due ); self::assertNull( $preview->comparisons[0]['baseline']->selected ); self::assertSame( 1, $preview->comparisons[0]['proposed']->selected->version_id ); self::assertTrue( $preview->comparisons[0]['proposed']->selected->hypothetical );
		$pure = ( new RuleLifecycleEvaluator() )->evaluate( $family, [ $proposed ], $draft['scope'], $at, $snapshot->guard->revision, true ); self::assertSame( $pure->selected->facts(), $preview->comparisons[0]['proposed']->selected->facts() ); self::assertSame( $before, $this->all_facts() );
		$future = $previewer->preview( $actor, $family->family(), $draft['scope'], [ $draft['scope'] ], $proposed, RuleTime::parse( '2026-10-06 10:07:00.000000' ), $request ); self::assertInstanceOf( RuleImpactPreview::class, $future ); self::assertTrue( $future->hypothetical_due ); self::assertSame( 'draft', $this->version()['state'] ); self::assertSame( $before, $this->all_facts() );
		$edit = $this->opened(); $edit['payload']['availability'] = 'deny'; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $edit, 'later-edit' )->outcome->state ); self::assertSame( 'stale_revision', $this->attempt( $service, 'rule.publish', $draft, 'stale-preview' )->outcome->error->code ); self::assertSame( '{"availability":"deny"}', $this->version()['payload_json'] );
	}

	public function test_preview_bounded_infinite_subject_input_unknown_nested_fields_and_revocation_leak_nothing(): void {
		[ $service, $family, $factory ] = $this->stack(); $draft = $this->draft( $service ); $reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $previewer = new RuleImpactPreviewService( $reader ); $actor = RuleProofEnvelope::identity( $draft, 'rule.publish', 'preview' ); $request = RequestContext::create(); $at = RuleTime::parse( '2026-10-06 10:00:00.000000' ); $before = $this->all_facts(); $opened = count( $factory->sessions ); $yielded = 0;
		$unbounded = ( static function () use ( &$yielded, $draft ): \Generator { while ( true ) { ++$yielded; yield $draft['scope']; } } )();
		$bounded = $previewer->preview( $actor, $family->family(), $draft['scope'], $unbounded, null, $at, $request ); self::assertInstanceOf( ContractError::class, $bounded ); self::assertSame( 'invalid_input', $bounded->code ); self::assertSame( 101, $yielded ); self::assertCount( $opened, $factory->sessions );
		$unknown = $draft['scope'] + [ 'renamed_private' => [ 'address' => 'PRIVATE_SENTINEL' ] ]; $invalid = $previewer->preview( $actor, $family->family(), $draft['scope'], [ $unknown ], null, $at, $request ); self::assertInstanceOf( ContractError::class, $invalid ); self::assertStringNotContainsString( 'PRIVATE_SENTINEL', json_encode( $invalid->to_array(), JSON_THROW_ON_ERROR ) ); self::assertCount( $opened, $factory->sessions );
		$family->allowed = false; $denied = $reader->capture( $actor, $family->family(), $draft['scope'], $request ); self::assertInstanceOf( ContractError::class, $denied ); self::assertSame( 'not_authorized', $denied->code ); self::assertCount( $opened, $factory->sessions ); self::assertSame( $before, $this->all_facts() );
	}

	public function test_safe_projection_and_direct_serializer_refusal_preserve_private_history(): void {
		[ $service, $family, $factory ] = $this->stack(); $draft = $this->draft( $service ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $draft, 'publish' )->outcome->state ); $reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $actor = RuleProofEnvelope::identity( $draft, 'rule.publish', 'read' ); $request = RequestContext::create();
		$decision = $reader->decision( $actor, $family->family(), $draft['scope'], $draft['scope'], RuleTime::parse( '2026-10-06 10:00:00.000000' ), $request ); self::assertInstanceOf( RuleDecision::class, $decision ); $safe = $decision->safe( $request ); self::assertSame( [ 'format', 'status', 'reason', 'complete', 'correlation_id' ], array_keys( $safe ) ); self::assertStringNotContainsString( RuleProofEnvelope::uuid( 101 ), json_encode( $safe, JSON_THROW_ON_ERROR ) ); self::assertStringNotContainsString( 'PRIVATE_SYNTHETIC_CHANGE_REASON', json_encode( $safe, JSON_THROW_ON_ERROR ) ); $safe['complete'] = false; self::assertTrue( $decision->safe( $request )['complete'] );
		$snapshot = $reader->capture( $actor, $family->family(), $draft['scope'], $request ); self::assertNotInstanceOf( ContractError::class, $snapshot );
		foreach ( [ $snapshot, $snapshot->candidates()[0], $snapshot->logicals[0], $snapshot->versions[0] ] as $carrier ) { $leaked = false; try { json_encode( $carrier, JSON_THROW_ON_ERROR ); $leaked = true; } catch ( \InvalidArgumentException ) {} self::assertFalse( $leaked, 'An actual stored private carrier must reject generic JSON serialization.' ); }
		$admin = $decision->admin( $family, $actor ); self::assertSame( 1, $admin['selected']['version_id'] ); $family->allowed = false; $disclosed = false; try { $decision->admin( $family, $actor ); $disclosed = true; } catch ( \InvalidArgumentException ) {} self::assertFalse( $disclosed );
		$serialized = false; try { json_encode( $decision, JSON_THROW_ON_ERROR ); $serialized = true; } catch ( \InvalidArgumentException ) {} self::assertFalse( $serialized ); self::assertSame( 2, $this->row_count( 'operation_changes' ) );
	}

	public function test_unknown_and_conflicting_activation_preserve_predecessor_and_current_heads(): void {
		[ $service, $family, $factory ] = $this->stack(); $this->draft( $service ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened(), 'first' )->outcome->state ); $original = $this->scheduled( $service, 102, true );
		$other = $this->draft( $service, 2, 103, RuleProofEnvelope::scope( 'product', 20 ) ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $other, 'other' )->outcome->state ); $before = $this->rule_facts(); $events = $this->row_count( 'operation_changes' );
		$family->unknown_overlap = true; $factory->clock = RuleTime::parse( '2026-10-06 10:05:00.000000' ); $refused = $this->attempt( $service, 'rule.activate', $original, 'activation' ); self::assertSame( 'rejected', $refused->outcome->state ); self::assertSame( 'temporarily_unavailable', $refused->outcome->error->code ); self::assertSame( $before, $this->rule_facts() ); self::assertSame( $events, $this->row_count( 'operation_changes' ) ); self::assertSame( 'published', $this->version()['state'] ); self::assertSame( 'scheduled', $this->version( 102 )['state'] ); self::assertSame( $this->version()['id'], $this->logical()['current_published_version_id'] );
		$family->unknown_overlap = false;
		// An externally restored, codec-valid conflicting row is a read fixture;
		// it is not claimed to have an accepted C03 mutation/event history.
		RuleProofPopulation::clone_pair( $this->database, $this->prefix, $this->logical( 2 ), $this->version( 103 ), 999, RuleProofEnvelope::scope() ); $with_conflict = $this->rule_facts();
		$conflicting = $this->attempt( $service, 'rule.activate', $original, 'activation' ); self::assertSame( 'rejected', $conflicting->outcome->state ); self::assertSame( 'invalid_input', $conflicting->outcome->error->code ); self::assertSame( $with_conflict, $this->rule_facts() ); self::assertSame( $events, $this->row_count( 'operation_changes' ) );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $decision = $reader->decision( RuleProofEnvelope::identity( $original, 'rule.publish', 'conflict-read' ), $family->family(), $original['scope'], $original['scope'], RuleTime::parse( '2026-10-06 10:05:00.000000' ), RequestContext::create() ); self::assertInstanceOf( RuleDecision::class, $decision ); self::assertNull( $decision->selected ); self::assertSame( 'rule_conflict', $decision->reason ); self::assertTrue( $decision->complete );
	}

	public function test_unrelated_logical_edit_does_not_stale_narrow_sealed_activation(): void {
		[ $service, , $factory ] = $this->stack(); $original = $this->scheduled( $service ); $sealed = $this->version(); $this->draft( $service, 2, 102, RuleProofEnvelope::scope( 'product', 20 ) ); $other = $this->opened( 2, 102 ); $other['priority'] = 1; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $other, 'unrelated-edit' )->outcome->state ); self::assertSame( $sealed, $this->version() ); self::assertSame( 0, $original['preconditions']['family_revision'] );
		$factory->clock = RuleTime::parse( '2026-10-06 10:05:00.000000' ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.activate', $original, 'activation' )->outcome->state ); self::assertSame( 'published', $this->version()['state'] ); self::assertSame( 'draft', $this->version( 102 )['state'] );
	}

	public function test_physical_candidate_1001_returns_incomplete_without_selected_winner_or_mutation(): void {
		[ $service, $family, $factory ] = $this->stack(); $draft = $this->draft( $service ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $draft, 'first' )->outcome->state ); $logical = $this->logical(); $version = $this->version();
		// Direct read-population fixtures are codec-validated; these clones are not
		// presented as 1000 accepted lifecycle changes or material audit events.
		for ( $identity = 2; $identity <= 1001; ++$identity ) { RuleProofPopulation::clone_pair( $this->database, $this->prefix, $logical, $version, $identity, RuleProofEnvelope::scope( 'product', $identity ) ); }
		$before = $this->all_facts(); $reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $actor = RuleProofEnvelope::identity( $draft, 'rule.publish', 'read' ); $decision = $reader->decision( $actor, $family->family(), $draft['scope'], RuleProofEnvelope::scope( 'product', 1001 ), RuleTime::parse( '2026-10-06 10:00:00.000000' ), RequestContext::create() ); self::assertInstanceOf( RuleDecision::class, $decision ); self::assertFalse( $decision->complete ); self::assertNull( $decision->selected ); self::assertSame( 'rule_incomplete', $decision->reason ); self::assertSame( $before, $this->all_facts() );
		$extra = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 9999 ), RuleProofEnvelope::uuid( 9998 ), RuleProofEnvelope::scope( 'product', 9999 ) ); $extra['preconditions']['family_revision'] = (int) $this->guard()['revision']; self::assertNotSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $extra, 'over-budget' )->outcome->state ); self::assertSame( $before['rule_versions'], $this->all_facts()['rule_versions'] ); self::assertSame( 2, $this->row_count( 'operation_changes' ) );
	}

	public function test_due_page_25_fixed_ceiling_keyset_and_unavailable_item_never_claim_activation(): void {
		[ $service, $family, $factory ] = $this->stack();
		for ( $number = 1; $number <= 26; ++$number ) {
			$draft = $this->draft( $service, $number, 100 + $number, 26 === $number ? RuleProofEnvelope::scope( 'product', 26 ) : null ); $draft['start_mode'] = 'at'; $draft['effective_from'] = '2026-10-06 10:05:00.000000'; $draft['priority'] = $number;
			self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $draft, 'dated-' . $number )->outcome->state ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.schedule', $this->opened( $number, 100 + $number ), 'schedule-' . $number )->outcome->state );
		}
		$scope = RuleProofEnvelope::scope(); $payload = $this->opened( activation: true ); $actor = RuleProofEnvelope::identity( $payload, 'rule.activate', 'scan', principal: 'rule-activation:scan', authority: 'rule.lifecycle.activation.v1' ); $reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $activation = new RuleActivationService( $reader, $service ); $at = RuleTime::parse( '2026-10-06 10:06:00.000000' ); $before = $this->all_facts();
		$page = $activation->scan_due_page( $actor, $family->family(), $scope, $at, RequestContext::create() ); self::assertNull( $page['error'] ); self::assertSame( 26, $page['observed_ceiling'] ); self::assertCount( 25, $page['items'] ); self::assertFalse( $page['complete'] ); self::assertSame( 25, $page['cursor_id'] ); foreach ( $page['items'] as $item ) { self::assertSame( 'candidate', $item['state'] ); } self::assertSame( $before, $this->all_facts() );
		$late = $this->draft( $service, 27, 127 ); $late['start_mode'] = 'at'; $late['effective_from'] = '2026-10-06 10:04:00.000000'; $late['priority'] = 27; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $late, 'late-dated' )->outcome->state ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.schedule', $this->opened( 27, 127 ), 'late-schedule' )->outcome->state );
		$valid_next = $activation->scan_due_page( $actor, $family->family(), $scope, $at, RequestContext::create(), $page['observed_ceiling'], RuleTime::parse( $page['cursor_at'] ), $page['cursor_id'] ); self::assertCount( 1, $valid_next['items'] ); self::assertSame( 'candidate', $valid_next['items'][0]['state'] ); self::assertSame( $family->scope_schema()->validate( RuleProofEnvelope::scope( 'product', 26 ) ), $valid_next['items'][0]['original']['payload']['scope'] ); self::assertSame( 26, $valid_next['observed_ceiling'] );
		RuleProofDatabase::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_rule_versions` SET content_hash='" . str_repeat( 'a', 64 ) . "' WHERE id=26" ); $after_seed = $this->all_facts();
		$next = $activation->scan_due_page( $actor, $family->family(), $scope, $at, RequestContext::create(), $page['observed_ceiling'], RuleTime::parse( $page['cursor_at'] ), $page['cursor_id'] ); self::assertSame( 26, $next['observed_ceiling'] ); self::assertCount( 1, $next['items'] ); self::assertSame( 26, $next['items'][0]['candidate_id'] ); self::assertSame( 'unavailable', $next['items'][0]['state'] ); self::assertNull( $next['items'][0]['original'] ); self::assertTrue( $next['complete'] ); self::assertSame( $after_seed, $this->all_facts() ); self::assertSame( 'scheduled', $this->version( 127 )['state'] );
	}

	#[DataProvider( 'publication_clock_refusals' )]
	public function test_actual_future_backdated_expired_or_regressed_clock_refuses_without_rule_change( string $fault ): void {
		[ $service, , $factory ] = $this->stack(); $draft = $this->draft( $service );
		if ( 'future' === $fault || 'backdated' === $fault ) { $draft['start_mode'] = 'at'; $draft['effective_from'] = 'future' === $fault ? '2026-10-06 10:01:00.000000' : '2026-10-06 09:59:00.000000'; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $draft, 'dated' )->outcome->state ); }
		elseif ( 'expired' === $fault ) { $draft['effective_until'] = '2026-10-06 09:59:00.000000'; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $draft, 'ended' )->outcome->state ); }
		else { $factory->clock = RuleTime::parse( '2026-10-06 09:59:00.000000' ); }
		$before = $this->rule_facts(); $events = $this->row_count( 'operation_changes' ); $refused = $this->attempt( $service, 'rule.publish', $this->opened(), 'publish' ); self::assertSame( 'rejected', $refused->outcome->state ); self::assertSame( $before, $this->rule_facts() ); self::assertSame( $events, $this->row_count( 'operation_changes' ) ); self::assertSame( 'draft', $this->version()['state'] ); self::assertNull( $this->logical()['current_published_version_id'] );
	}
	public static function publication_clock_refusals(): array { return [ [ 'future' ], [ 'backdated' ], [ 'expired' ], [ 'regressed' ] ]; }

	public function test_cross_principal_and_site_namespaces_never_replay_another_principals_receipt(): void {
		[ $service ] = $this->stack(); $original = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) ); $first = $this->attempt( $service, 'rule.draft.create', $original, 'shared' ); self::assertSame( 'accepted', $first->outcome->state );
		$other = $service->attempt( RuleProofEnvelope::identity( $original, 'rule.draft.create', 'shared', principal: 'staff:10' ), $original, RequestContext::create() ); self::assertSame( 'stale_revision', $other->outcome->error->code ); self::assertFalse( $other->replayed ); self::assertNull( $other->completion?->result );
		[ $other_site ] = $this->stack( site: 2 ); $site_result = $other_site->attempt( RuleProofEnvelope::identity( $original, 'rule.draft.create', 'shared', site: 2 ), $original, RequestContext::create() ); self::assertSame( 'accepted', $site_result->outcome->state ); self::assertFalse( $site_result->replayed ); self::assertNotSame( $first->completion->result['logical_id'], $site_result->completion->result['logical_id'] ); self::assertNotSame( $first->completion->result['version_id'], $site_result->completion->result['version_id'] ); self::assertSame( 2, $this->row_count( 'rule_versions' ) ); self::assertSame( 2, $this->row_count( 'operation_changes' ) );
	}

	public function test_real_equal_rank_collision_refuses_new_publication_without_uuid_order_winner(): void {
		[ $service, $family, $factory ] = $this->stack(); $this->draft( $service, scope: RuleProofEnvelope::scope( 'product', 10 ) ); self::assertSame( 'accepted', $this->attempt( $service, 'rule.publish', $this->opened(), 'first' )->outcome->state ); $second = $this->draft( $service, 2, 102, RuleProofEnvelope::scope( 'product', 10 ), 'deny' ); $before = $this->rule_facts();
		$collision = $this->attempt( $service, 'rule.publish', $second, 'collision' ); self::assertSame( 'rejected', $collision->outcome->state ); self::assertSame( 'invalid_input', $collision->outcome->error->code ); self::assertSame( $before, $this->rule_facts() ); self::assertSame( 'draft', $this->version( 102 )['state'] ); self::assertSame( 3, $this->row_count( 'operation_changes' ) );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $actor = RuleProofEnvelope::identity( $second, 'rule.publish', 'read' ); $decision = $reader->decision( $actor, $family->family(), $second['scope'], $second['scope'], RuleTime::parse( '2026-10-06 10:00:00.000000' ), RequestContext::create() ); self::assertInstanceOf( RuleDecision::class, $decision ); self::assertSame( 1, $decision->selected->version_id );
	}

	public function test_actual_activation_host_lost_ack_reconstructs_original_and_reconciles_without_second_cutover(): void {
		[ $setup, $family ] = $this->stack(); $sealed = $this->scheduled( $setup );
		[ $lifecycle, , $factory ] = $this->stack( $family, static function ( RuleProofTransport $transport, int $open ): void { if ( 2 === $open ) { $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } }, clock: '2026-10-06 10:05:00.000000' );
		$reader = new RuleLifecycleReadService( new RuleFamilyRegistry( [ $family ] ), $factory ); $activation = new RuleActivationService( $reader, $lifecycle ); $actor = RuleProofEnvelope::identity( $sealed, 'rule.activate', 'activation-host', principal: 'rule-activation:' . $sealed['version_uuid'], authority: 'rule.lifecycle.activation.v1' );
		$lost = $activation->activate_original( $actor, $family->family(), $sealed['version_uuid'], $sealed['scope'], RequestContext::create() ); self::assertNotInstanceOf( ContractError::class, $lost ); self::assertSame( 'unconfirmed', $lost->outcome->state ); self::assertSame( 2, $factory->transports[1]->sent_commits ); self::assertSame( 'published', $this->version()['state'] ); self::assertSame( 4, $this->row_count( 'operation_changes' ) ); $accepted_facts = $this->all_facts();
		$resolved = $activation->reconcile_original( $actor, $family->family(), $sealed['version_uuid'], $sealed['scope'], RequestContext::create() ); self::assertNotInstanceOf( ContractError::class, $resolved ); self::assertSame( 'accepted', $resolved->outcome->state ); self::assertTrue( $resolved->replayed ); self::assertSame( $accepted_facts, $this->all_facts() );
	}

	#[DataProvider( 'physical_faults' )]
	public function test_physical_partial_engine_collation_index_and_status_refuse_before_effect( string $fault ): void {
		$table = $this->prefix . 'delivery_engine_rule_versions';
		if ( 'missing' === $fault ) { RuleProofDatabase::execute( $this->database, "DROP TABLE `{$table}`" ); }
		elseif ( 'engine' === $fault ) { RuleProofDatabase::execute( $this->database, "ALTER TABLE `{$table}` ENGINE=MyISAM" ); }
		elseif ( 'collation' === $fault ) { RuleProofDatabase::execute( $this->database, "ALTER TABLE `{$table}` DEFAULT COLLATE=utf8mb4_general_ci" ); }
		elseif ( 'prefix_index' === $fault ) { RuleProofDatabase::execute( $this->database, "ALTER TABLE `{$table}` DROP INDEX site_version_uuid,ADD UNIQUE KEY site_version_uuid(site_id,version_uuid(8))" ); }
		else { \CetechDeliveryEngine\Tests\Support\Operation\OperationProofDatabase::option( $this->database, $this->prefix, \CetechDeliveryEngine\Core\Versioning\MigrationStatus::OPTION_NAME, serialize( [ 'status' => 'failed', 'to_version' => '8', 'migration_id' => RuleLifecycleReadiness::MIGRATION_ID ] ) ); }
		[ $service, , $factory ] = $this->stack(); self::assertFalse( ( new RuleLifecycleReadiness( $factory->open() ) )->get_status()['ready'] ); $payload = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 101 ) ); self::assertNotSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $payload )->outcome->state ); self::assertSame( 0, $this->row_count( 'operation_records' ) ); self::assertSame( 0, $this->row_count( 'rule_family_guards' ) ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}
	public static function physical_faults(): array { return [ [ 'missing' ], [ 'engine' ], [ 'collation' ], [ 'prefix_index' ], [ 'failed_status' ] ]; }
}
