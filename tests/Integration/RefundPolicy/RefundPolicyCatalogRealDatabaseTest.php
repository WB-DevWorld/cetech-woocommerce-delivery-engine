<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\RefundPolicy;

use CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyCatalogService;
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\Operation\OperationPhaseObserver;
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyContent, RefundPolicyFamily};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleSnapshot, RuleTime};
use CetechDeliveryEngine\Infrastructure\Persistence\RuleLifecycleSchema;
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use CetechDeliveryEngine\Tests\Support\RefundPolicy\RefundPolicyProofEnvelope as Envelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\{RuleProofDatabase as DB, RuleProofFactory, RuleProofTransport};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Eleven non-skipped Refund catalog proofs in the existing explicitly disposable SQL lane. */
#[Group( 'rule-lifecycle-real-db' )]
final class RefundPolicyCatalogRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $directories = [];
	private array $processes = [];

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix );
	}

	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { try { $process->kill_and_wait(); } catch ( \Throwable ) {} }
		foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}

	private function stack( ?RefundPolicyFamily $family = null, ?callable $configure = null, ?OperationPhaseObserver $observer = null, ?callable $catalog = null ): array {
		$factory = new RuleProofFactory( $this->prefix, configure: $configure, clock: RuleTime::parse( Envelope::NOW ) );
		$this->factories[] = $factory;
		$family ??= Envelope::family();
		$catalog ??= static fn( OperationIdentity $identity ): bool => 1 === $identity->site_id && 'staff:9' === $identity->principal;
		return [ new RefundPolicyCatalogService( $family, $factory, $catalog, observer: $observer ), $factory ];
	}

	private function attempt( RefundPolicyCatalogService $service, string $operation, array $payload, string $token ): object {
		return $service->attempt( Envelope::identity( $payload, $operation, $token ), $payload, RequestContext::create() );
	}

	private function count_rows( string $suffix ): int {
		return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM {$this->prefix}delivery_engine_{$suffix}" );
	}

	private function guard(): ?array {
		return DB::row( $this->database, "SELECT * FROM {$this->prefix}delivery_engine_rule_family_guards ORDER BY id LIMIT 1" );
	}

	private function opened( int $logical = 1, int $version = 101 ): array { return Envelope::opened( $this->database, $this->prefix, $logical, $version ); }
	private function version( int $version = 101 ): array { return Envelope::version( $this->database, $this->prefix, $version ); }
	private function logical( int $logical = 1 ): array { return Envelope::logical( $this->database, $this->prefix, $logical ); }

	private function draft( RefundPolicyCatalogService $service ): array {
		$payload = Envelope::create();
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $payload, 'create-original' )->outcome->state );
		return $this->opened();
	}

	private function all_facts(): array {
		$facts = [];
		foreach ( [ ...RuleLifecycleSchema::SUFFIXES, 'operation_records', 'operation_changes' ] as $suffix ) {
			$result = $this->database->query( "SELECT * FROM {$this->prefix}delivery_engine_{$suffix} ORDER BY id" );
			if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException( 'Disposable Refund facts unavailable.' ); }
			$facts[$suffix] = $result->fetch_all( MYSQLI_ASSOC ); $result->free();
		}
		return $facts;
	}

	private function worker( array $args ): OperationProofProcess {
		$process = new OperationProofProcess( [ __DIR__ . '/process-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $args, JSON_THROW_ON_ERROR ) ] );
		$this->processes[] = $process; return $process;
	}

	private function capture( RefundPolicyCatalogService $service ): RuleSnapshot|ContractError {
		return $service->capture( Envelope::read_identity(), [ 'policy_uuid' => Envelope::uuid( 1 ) ], RequestContext::create() );
	}

	private function assert_retired( RuleProofFactory $factory ): void {
		self::assertNotEmpty( $factory->sessions );
		foreach ( $factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}

	public function test_create_edit_no_change_preserves_canonical_multiline_content_and_single_audit_per_effect(): void {
		[ $service, $factory ] = $this->stack(); $original = Envelope::create();
		$created = $this->attempt( $service, 'rule.draft.create', $original, 'create-original' );
		self::assertSame( 'accepted', $created->outcome->state );
		self::assertSame( Envelope::content()->to_rule_payload(), json_decode( $this->version()['payload_json'], true, 8, JSON_THROW_ON_ERROR ) );
		$edit = $this->opened(); $content = Envelope::content( [ 'full_text' => "Changed source.\n\tUnicode: 東京 and café." ] );
		$edit['payload'] = $content->to_rule_payload();
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $edit, 'edit-original' )->outcome->state );
		self::assertSame( $content->json(), json_decode( $this->version()['payload_json'], true, 8, JSON_THROW_ON_ERROR )['content_json'] );
		$before = $this->all_facts(); $unchanged = $this->attempt( $service, 'rule.draft.edit', $this->opened(), 'no-change' );
		self::assertSame( 'not_applicable', $unchanged->outcome->state );
		self::assertSame( $before['rule_versions'], $this->all_facts()['rule_versions'] );
		self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
		self::assertSame( 1, $this->count_rows( 'rule_versions' ) );
		$snapshot = $this->capture( $service ); self::assertInstanceOf( RuleSnapshot::class, $snapshot );
		self::assertTrue( $snapshot->complete ); self::assertFalse( $snapshot->history_complete );
		self::assertCount( 1, $snapshot->candidates() ); $this->assert_retired( $factory );
	}

	public function test_publish_retire_and_fresh_original_replay_preserve_sealed_bytes_and_original_completion(): void {
		[ $service ] = $this->stack(); $original = $this->draft( $service );
		$published = $this->attempt( $service, 'rule.publish', $original, 'publish-original' );
		self::assertSame( 'accepted', $published->outcome->state ); $sealed = $this->version();
		self::assertSame( 'published', $sealed['state'] ); self::assertNotNull( $sealed['sealed_at'] );
		self::assertSame( $sealed['id'], $this->logical()['current_published_version_id'] );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.retire', $this->opened(), 'retire-original' )->outcome->state );
		$before = $this->all_facts();
		$fresh = $this->worker( [ 'operation' => 'rule.publish', 'payload' => $original, 'token' => 'publish-original' ] )->finish();
		self::assertNotSame( getmypid(), $fresh['process_id'] ); self::assertSame( 'accepted', $fresh['state'] );
		self::assertTrue( $fresh['replayed'] ); self::assertTrue( $fresh['all_retired'] );
		self::assertSame( $published->completion->to_json(), $fresh['completion_json'] );
		self::assertSame( $before, $this->all_facts() ); self::assertSame( 3, $this->count_rows( 'operation_changes' ) );
		foreach ( [ 'payload_json', 'content_hash', 'sealed_at', 'published_at', 'effective_from' ] as $field ) { self::assertSame( $sealed[$field], $this->version()[$field] ); }
		self::assertSame( 'retired', $this->version()['state'] ); self::assertNull( $this->logical()['current_published_version_id'] );
	}

	public function test_scheduled_capture_after_due_is_read_only_and_explicit_retire_cancels_without_activation(): void {
		[ $service, $factory ] = $this->stack(); $draft = $this->draft( $service );
		$draft['start_mode'] = 'at'; $draft['effective_from'] = '2026-10-24 10:05:00.000000';
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.edit', $draft, 'date-draft' )->outcome->state );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.schedule', $this->opened(), 'schedule-original' )->outcome->state );
		$sealed = $this->version(); self::assertSame( 'scheduled', $sealed['state'] );
		$factory->clock = RuleTime::parse( '2026-10-24 10:06:00.000000' ); $before = $this->all_facts();
		$snapshot = $this->capture( $service ); self::assertInstanceOf( RuleSnapshot::class, $snapshot );
		self::assertSame( 'scheduled', $snapshot->versions[0]->state->value ); self::assertSame( $before, $this->all_facts() );
		self::assertNull( $this->logical()['current_published_version_id'] );
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.retire', $this->opened(), 'cancel-original' )->outcome->state );
		self::assertSame( 'retired', $this->version()['state'] ); self::assertNull( $this->logical()['scheduled_version_id'] );
		self::assertNull( $this->version()['published_at'] ); self::assertSame( $sealed['payload_json'], $this->version()['payload_json'] );
		$this->assert_retired( $factory );
	}

	public function test_actor_author_scope_and_revoked_binding_refuse_before_effect_or_catalog_disclosure(): void {
		$grant = (object) [ 'allowed' => true ];
		$family = Envelope::family(
			static fn( OperationIdentity $identity, int $author, array $scope ): bool => $grant->allowed && 'staff:' . $author === $identity->principal,
			static fn( OperationIdentity $identity, array $scope ): bool => 1 === $identity->site_id && in_array( $identity->principal, [ 'staff:9', 'staff:10' ], true ) && $scope['policy_uuid'] === Envelope::uuid( 1 )
		);
		[ $service, $factory ] = $this->stack( $family ); $payload = Envelope::create();
		foreach ( [
			Envelope::identity( $payload, 'rule.draft.create', 'forged-author', principal: 'staff:10' ),
			Envelope::identity( $payload, 'rule.draft.create', 'foreign-site', site: 2 ),
		] as $identity ) {
			$result = $service->attempt( $identity, $payload, RequestContext::create() );
			self::assertSame( 'not_authorized', $result->outcome->error?->code ); self::assertNull( $result->completion );
		}
		$other = Envelope::create( 2, 102 );
		self::assertSame( 'not_authorized', $this->attempt( $service, 'rule.draft.create', $other, 'other-scope' )->outcome->error?->code );
		self::assertCount( 0, $factory->sessions ); self::assertSame( 0, $this->count_rows( 'operation_records' ) );
		$observer = new class( $grant ) implements OperationPhaseObserver {
			public function __construct( private object $grant ) {}
			public function observe( string $phase, OperationIdentity $identity ): void { if ( 'reservation_committed' === $phase ) { $this->grant->allowed = false; } }
		};
		[ $revoked, $revoked_factory ] = $this->stack( $family, observer: $observer );
		self::assertSame( 'not_authorized', $this->attempt( $revoked, 'rule.draft.create', $payload, 'revoked-after-reservation' )->outcome->error?->code );
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { self::assertSame( 0, $this->count_rows( $suffix ) ); }
		self::assertSame( 0, $this->count_rows( 'operation_changes' ) ); $this->assert_retired( $revoked_factory );
		$grant->allowed = true; self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $payload, 'valid-author' )->outcome->state );
		[ $scoped_only, $read_factory ] = $this->stack( $family, catalog: static fn( OperationIdentity $identity ): bool => false );
		$before = $this->all_facts(); $denied = $this->capture( $scoped_only );
		self::assertInstanceOf( ContractError::class, $denied ); self::assertSame( 'not_authorized', $denied->code );
		self::assertCount( 0, $read_factory->sessions ); self::assertSame( $before, $this->all_facts() );
	}

	public function test_foreign_family_target_and_noncanonical_content_refuse_without_sql_or_writes(): void {
		[ $service, $factory ] = $this->stack(); $original = Envelope::create();
		$foreign = $original; $foreign['family'] = 'return_policy_v1';
		self::assertSame( 'unsupported_contract', $this->attempt( $service, 'rule.draft.create', $foreign, 'foreign-family' )->outcome->error?->code );
		$bad_target = new OperationIdentity( 1, 'wordpress', 'staff:9', 'rule.draft.create', 1, 'foreign-refund-target', 'foreign-target' );
		self::assertSame( 'invalid_input', $service->attempt( $bad_target, $original, RequestContext::create() )->outcome->error?->code );
		$bad_scope = $original; $bad_scope['scope']['policy_uuid'] = Envelope::uuid( 2 );
		self::assertSame( 'invalid_input', $this->attempt( $service, 'rule.draft.create', $bad_scope, 'mismatched-policy' )->outcome->error?->code );
		$bad_content = $original; $bad_content['payload']['content_json'] = ' ' . $bad_content['payload']['content_json'];
		self::assertSame( 'invalid_input', $this->attempt( $service, 'rule.draft.create', $bad_content, 'noncanonical-content' )->outcome->error?->code );
		$bad_priority = $original; $bad_priority['priority'] = 1;
		self::assertSame( 'invalid_input', $this->attempt( $service, 'rule.draft.create', $bad_priority, 'unsupported-priority' )->outcome->error?->code );
		self::assertCount( 0, $factory->sessions );
		foreach ( [ ...RuleLifecycleSchema::SUFFIXES, 'operation_records', 'operation_changes' ] as $suffix ) { self::assertSame( 0, $this->count_rows( $suffix ) ); }
	}

	public function test_return_and_refund_catalogs_coexist_with_identical_text_and_refuse_cross_family_originals(): void {
		[ $refund_service, $factory ] = $this->stack();
		$refund = Envelope::create();
		self::assertSame( 'accepted', $this->attempt( $refund_service, 'rule.draft.create', $refund, 'refund-own-original' )->outcome->state );
		$return = \CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyProofEnvelope::create( 2, 102, Envelope::content()->facts() );
		$return_family = \CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyProofEnvelope::family();
		$return_service = new \CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService( $return_family, $factory, static fn(): bool => true );
		$return_identity = \CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyProofEnvelope::identity( $return, 'rule.draft.create', 'return-own-original' );
		self::assertSame( $refund['payload'], $return['payload'] );
		self::assertSame( 'accepted', $return_service->attempt( $return_identity, $return, RequestContext::create() )->outcome->state );
		self::assertSame( 2, $this->count_rows( 'rule_family_guards' ) ); self::assertSame( 2, $this->count_rows( 'logical_rules' ) );
		self::assertSame( 2, $this->count_rows( 'rule_versions' ) ); self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
		$before = $this->all_facts(); $opened = count( $factory->sessions );
		$wrong_refund = $refund_service->attempt( $return_identity, $return, RequestContext::create() );
		$wrong_return = $return_service->attempt( Envelope::identity( $refund, 'rule.draft.create', 'refund-own-original' ), $refund, RequestContext::create() );
		foreach ( [ $wrong_refund, $wrong_return ] as $wrong ) {
			self::assertSame( 'rejected', $wrong->outcome->state ); self::assertSame( 'unsupported_contract', $wrong->outcome->error?->code ); self::assertNull( $wrong->completion );
		}
		self::assertCount( $opened, $factory->sessions ); self::assertSame( $before, $this->all_facts() );
		$refund_snapshot = $this->capture( $refund_service );
		$return_snapshot = $return_service->capture( \CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyProofEnvelope::read_identity(), $return['scope'], RequestContext::create() );
		self::assertInstanceOf( RuleSnapshot::class, $refund_snapshot ); self::assertInstanceOf( RuleSnapshot::class, $return_snapshot );
		self::assertCount( 1, $refund_snapshot->versions ); self::assertCount( 1, $return_snapshot->versions );
		self::assertSame( RefundPolicyContent::FAMILY, $refund_snapshot->logicals[0]->family_code );
		self::assertSame( \CetechDeliveryEngine\Domain\ReturnPolicy\ReturnPolicyContent::FAMILY, $return_snapshot->logicals[0]->family_code );
		self::assertSame( $before, $this->all_facts() ); $this->assert_retired( $factory );
	}

	private function rollback_fault( string $fault ): void {
		[ $service, $factory ] = $this->stack( configure: static function( RuleProofTransport $transport, int $open ) use ( $fault ): void {
			if ( 1 !== $open ) { return; }
			if ( 'audit' === $fault ) { $transport->reject_audit = true; }
			elseif ( 'completion' === $fault ) { $transport->reject_completion = true; }
			else { $transport->fault_commit = 2; $transport->commit_fault = 'unsent'; }
		} );
		$original = Envelope::create(); $rejected = $this->attempt( $service, 'rule.draft.create', $original, 'rollback-original' );
		self::assertSame( 'rejected', $rejected->outcome->state );
		foreach ( RuleLifecycleSchema::SUFFIXES as $suffix ) { self::assertSame( 0, $this->count_rows( $suffix ) ); }
		self::assertSame( 0, $this->count_rows( 'operation_changes' ) ); $this->assert_retired( $factory );
		if ( 'unsent' === $fault ) { self::assertSame( 2, $factory->transports[0]->commits ); self::assertSame( 1, $factory->transports[0]->sent_commits ); }
		self::assertSame( 'accepted', $this->attempt( $service, 'rule.draft.create', $original, 'rollback-original' )->outcome->state );
		self::assertSame( 1, $this->count_rows( 'rule_versions' ) ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
		self::assertGreaterThan( 1, count( $factory->transports ) ); $this->assert_retired( $factory );
	}

	public function test_audit_failure_rolls_back_rule_effect_and_retry_uses_original_intent(): void { $this->rollback_fault( 'audit' ); }
	public function test_completion_failure_rolls_back_rule_effect_and_retry_uses_original_intent(): void { $this->rollback_fault( 'completion' ); }
	public function test_proven_unsent_commit_rolls_back_rule_effect_and_preserves_retry_namespace(): void { $this->rollback_fault( 'unsent' ); }

	public function test_lost_commit_ack_fresh_process_reconciles_exact_original_once_without_second_effect(): void {
		[ $service, $factory ] = $this->stack( configure: static function( RuleProofTransport $transport, int $open ): void {
			if ( 1 === $open ) { $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; }
		} );
		$original = Envelope::create(); $identity = Envelope::identity( $original, 'rule.draft.create', 'lost-original' );
		$lost = $service->attempt( $identity, $original, RequestContext::create() );
		self::assertSame( 'unconfirmed', $lost->outcome->state ); self::assertSame( 2, $factory->transports[0]->sent_commits );
		$before = $this->all_facts(); self::assertSame( 'accepted', $before['operation_records'][0]['state'] );
		self::assertSame( $identity->namespace_digest(), $before['operation_records'][0]['namespace_hash'] );
		$fresh = $this->worker( [ 'action' => 'reconcile', 'operation' => 'rule.draft.create', 'payload' => $original, 'token' => 'lost-original' ] )->finish();
		self::assertNotSame( getmypid(), $fresh['process_id'] ); self::assertSame( 'accepted', $fresh['state'] );
		self::assertTrue( $fresh['replayed'] ); self::assertTrue( $fresh['all_retired'] );
		self::assertSame( $before['operation_records'][0]['completion_json'], $fresh['completion_json'] );
		self::assertSame( $before, $this->all_facts() ); self::assertSame( 1, $this->count_rows( 'rule_versions' ) );
		self::assertSame( 1, $this->count_rows( 'operation_changes' ) ); self::assertSame( 'draft', $this->version()['state'] );
	}

	public function test_two_token_publication_proves_exact_family_lock_wait_and_accepts_one_original_revision(): void {
		[ $service ] = $this->stack(); $original = $this->draft( $service );
		$directory = OperationProofBarrier::directory(); $this->directories[] = $directory;
		$right = $this->worker( [ 'role' => 'right', 'directory' => $directory, 'operation' => 'rule.publish', 'payload' => $original, 'token' => 'publish-right' ] );
		$left = null;
		try {
			OperationProofProcess::wait_for( $directory . '/right-reserved', 20.0 );
			$reserved = json_decode( (string) file_get_contents( $directory . '/right-reserved' ), true, 8, JSON_THROW_ON_ERROR );
			self::assertFalse( $reserved['in_transaction'] ); self::assertTrue( $reserved['autocommit'] );
			$left = $this->worker( [ 'role' => 'left', 'directory' => $directory, 'operation' => 'rule.publish', 'payload' => $original, 'token' => 'publish-left' ] );
			OperationProofProcess::wait_for( $directory . '/left-held', 20.0 );
			$held = json_decode( (string) file_get_contents( $directory . '/left-held' ), true, 8, JSON_THROW_ON_ERROR );
			self::assertTrue( $held['in_transaction'] ); self::assertTrue( $held['autocommit'] );
			self::assertNotSame( $reserved['connection_id'], $held['connection_id'] );
			OperationProofBarrier::signal( $directory . '/release-right' );
			OperationProofProcess::wait_for( $directory . '/right-dispatched', 20.0 );
			$dispatched = json_decode( (string) file_get_contents( $directory . '/right-dispatched' ), true, 8, JSON_THROW_ON_ERROR );
			self::assertSame( $reserved['connection_id'], $dispatched['connection_id'] );
			$table = $this->prefix . 'delivery_engine_rule_family_guards';
			$waiter = (int) $dispatched['connection_id']; $blocker = (int) $held['connection_id'];
			$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT(CHAR(96),DATABASE(),CHAR(96),'.',CHAR(96),'{$table}',CHAR(96)) AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
			$deadline = microtime( true ) + 1.5; $waiting = false;
			do { $waiting = (int) DB::scalar( $this->database, $sql ) > 0; if ( $waiting ) { break; } usleep( 10000 ); } while ( microtime( true ) < $deadline );
			self::assertTrue( $waiting, 'The actual Refund publication must wait on the exact family owner.' );
			self::assertSame( 'draft', $this->version()['state'] ); self::assertSame( 1, $this->count_rows( 'operation_changes' ) );
		} finally {
			OperationProofBarrier::signal( $directory . '/release-right' );
			OperationProofBarrier::signal( $directory . '/release-left' );
		}
		self::assertInstanceOf( OperationProofProcess::class, $left );
		$winner = $left->finish(); $loser = $right->finish();
		self::assertSame( 'accepted', $winner['state'] ); self::assertSame( 'rejected', $loser['state'] );
		self::assertSame( 'stale_revision', $loser['error_code'] ); self::assertTrue( $winner['all_retired'] ); self::assertTrue( $loser['all_retired'] );
		self::assertSame( 'published', $this->version()['state'] ); self::assertSame( 1, $this->count_rows( 'rule_versions' ) );
		self::assertSame( 2, $this->count_rows( 'operation_changes' ) );
		$before = $this->all_facts(); $retry = $this->attempt( $service, 'rule.publish', $original, 'publish-right' );
		self::assertSame( 'stale_revision', $retry->outcome->error?->code );
		self::assertSame( $before['rule_versions'], $this->all_facts()['rule_versions'] ); self::assertSame( $before['operation_changes'], $this->all_facts()['operation_changes'] );
	}
}
