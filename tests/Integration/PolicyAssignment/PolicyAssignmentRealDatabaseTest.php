<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\PolicyAssignment;

use CetechDeliveryEngine\Application\PolicyAssignment\PolicyAssignmentService;
use CetechDeliveryEngine\Application\RefundPolicy\RefundPolicyCatalogService;
use CetechDeliveryEngine\Application\ReturnPolicy\ReturnPolicyCatalogService;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Domain\PolicyAssignment\{PolicyAssignmentCommand, PolicyAssignmentState};
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\{PolicyAssignmentStore, ScopedConfigurationSchema};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use CetechDeliveryEngine\Tests\Support\PolicyAssignment\PolicyAssignmentProofEnvelope as Envelope;
use CetechDeliveryEngine\Tests\Support\RefundPolicy\RefundPolicyProofEnvelope as Refund;
use CetechDeliveryEngine\Tests\Support\ReturnPolicy\ReturnPolicyProofEnvelope as Returns;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\{RuleProofDatabase as DB, RuleProofFactory, RuleProofTransport};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Thirteen physical owner/option/current-source proofs; selected missing prerequisites fail, never skip. */
#[Group( 'rule-lifecycle-real-db' )]
final class PolicyAssignmentRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $directories = [];
	private array $processes = [];
	private array $legacy;

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix );
		foreach ( ScopedConfigurationSchema::create_table_statements( 'ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->prefix . 'delivery_engine_' ) as $sql ) { DB::execute( $this->database, $sql ); }
		$this->seed_legacy(); $this->legacy = $this->legacy_facts();
		$this->publish( Envelope::RETURN, 1, 101 ); $this->publish( Envelope::REFUND, 2, 201 );
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { try { $process->kill_and_wait(); } catch ( \Throwable ) {} }
		foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) {
			foreach ( ScopedConfigurationSchema::SUFFIXES as $suffix ) { DB::execute( $this->database, "DROP TABLE IF EXISTS `{$this->prefix}delivery_engine_{$suffix}`" ); }
			DB::cleanup( $this->database, $this->prefix ); $this->database->close();
		}
	}

	private function factory( ?callable $configure = null ): RuleProofFactory {
		$factory = new RuleProofFactory( $this->prefix, configure: $configure, clock: RuleTime::parse( Envelope::NOW ) ); $this->factories[] = $factory; return $factory;
	}
	private function stack( array $payload, string $token, ?callable $configure = null ): array {
		$factory = $this->factory( $configure ); $identity = Envelope::identity( $payload['coordinate'], $payload['offer_id'], $token );
		return [ Envelope::service( $factory, $this->prefix, $identity ), $factory, $identity ];
	}
	private function attempt( array $payload, string $token = 'original', ?callable $configure = null ): OperationAttemptResult {
		[ $service, $factory, $identity ] = $this->stack( $payload, $token, $configure ); $result = $service->attempt( $identity, $payload, RequestContext::create() ); $this->retired( $factory ); return $result;
	}
	private function state( ?array $coordinate = null ): PolicyAssignmentState {
		$coordinate ??= PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $identity = Envelope::identity( $coordinate, operation: 'policy.assignment.read' ); $factory = $this->factory();
		$state = Envelope::reader( $factory, $this->prefix, $identity )->read( $identity, $coordinate ); $this->retired( $factory ); return $state;
	}
	private function capture( array $coordinate, int $offer ): array {
		$identity = Envelope::identity( $coordinate, $offer, operation: 'policy.assignment.read' ); $factory = $this->factory();
		$result = Envelope::reader( $factory, $this->prefix, $identity )->capture( $identity, $coordinate, $offer ); $this->retired( $factory ); return $result;
	}
	private function payload( ?array $coordinate = null, int $offer = 0, ?array $slots = null ): array {
		$slots ??= Envelope::slots( 'assigned', 'assigned' ); $witnesses = [];
		foreach ( $slots as $family => $slot ) { $witnesses[$family] = 'assigned' === $slot['mode'] ? Envelope::witness( $this->database, $this->prefix, $family, $slot['policy_uuid'] ) : null; }
		return Envelope::payload( $this->state( $coordinate ), $offer, $slots, $witnesses );
	}
	private function publish( string $family, int $logical, int $version, array $content = [] ): void {
		[ $return, $refund ] = Envelope::families(); $factory = $this->factory(); $class = Envelope::RETURN === $family ? Returns::class : Refund::class;
		$service = Envelope::RETURN === $family ? new ReturnPolicyCatalogService( $return, $factory, static fn(): bool => true ) : new RefundPolicyCatalogService( $refund, $factory, static fn(): bool => true );
		$payload = $class::create( $logical, $version, $content );
		$old = DB::row( $this->database, "SELECT * FROM {$this->prefix}delivery_engine_logical_rules WHERE logical_uuid='" . Envelope::uuid( $logical ) . "'" );
		if ( null !== $old ) {
			$prior = DB::row( $this->database, "SELECT * FROM {$this->prefix}delivery_engine_rule_versions WHERE id=" . (int) $old['current_published_version_id'] );
			$guard = DB::row( $this->database, "SELECT * FROM {$this->prefix}delivery_engine_rule_family_guards WHERE id=" . (int) $old['family_guard_id'] );
			$payload['predecessor_uuid'] = $prior['version_uuid'];
			$payload['preconditions'] = [ 'family_revision' => (int) $guard['revision'], 'logical_id' => (int) $old['id'], 'logical_revision' => (int) $old['revision'], 'version_id' => 0, 'version_revision' => 0, 'predecessor_id' => (int) $prior['id'], 'predecessor_revision' => (int) $prior['row_revision'] ];
		}
		self::assertSame( 'accepted', $service->attempt( $class::identity( $payload, 'rule.draft.create', 'draft-' . $family . '-' . $version ), $payload, RequestContext::create() )->outcome->state );
		$opened = $class::opened( $this->database, $this->prefix, $logical, $version );
		self::assertSame( 'accepted', $service->attempt( $class::identity( $opened, 'rule.publish', 'publish-' . $family . '-' . $version ), $opened, RequestContext::create() )->outcome->state ); $this->retired( $factory );
	}
	private function retired( RuleProofFactory $factory ): void { self::assertNotEmpty( $factory->sessions ); foreach ( $factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); } }
	private function count_rows( string $suffix ): int { return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM {$this->prefix}delivery_engine_{$suffix}" ); }
	private function assignment_rows(): array {
		$result = $this->database->query( "SELECT * FROM {$this->prefix}options WHERE option_name LIKE 'cetech!_de!_policy!_assignment!_v1!_%' ESCAPE '!' ORDER BY option_id" );
		if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException(); } $rows = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); return $rows;
	}
	private function legacy_facts(): array {
		$rows = [];
		foreach ( ScopedConfigurationSchema::SUFFIXES as $suffix ) { $result = $this->database->query( "SELECT * FROM {$this->prefix}delivery_engine_{$suffix} ORDER BY id" ); if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException(); } $rows[$suffix] = $result->fetch_all( MYSQLI_ASSOC ); $result->free(); }
		return $rows;
	}
	private function seed_legacy(): void {
		DB::execute( $this->database, "INSERT INTO {$this->prefix}delivery_engine_configuration_scopes (id,scope_type,scope_id,slice_key,status,config_version,source,created_at,updated_at) VALUES (1,'global',0,'','active',7,'native','2026-10-24 10:00:00','2026-10-24 10:00:00')" );
		$fields = [ 'fulfilment_availability' => [ 'string', 'in_store' ], 'fulfilment_choice' => [ 'string', 'delivery' ], 'logistics_profile_id' => [ 'int', '4' ], 'supplier_id' => [ 'int', '5' ], 'origin_id' => [ 'int', '6' ], 'pickup_location_id' => [ 'int', '7' ], 'priority' => [ 'int', '8' ], 'estimated_delivery' => [ 'string', '3 to 6 business days' ] ];
		foreach ( $fields as $key => [ $type, $value ] ) { DB::execute( $this->database, "INSERT INTO {$this->prefix}delivery_engine_configuration_fields (scope_row_id,field_key,mode,value_type,value_text,created_at,updated_at) VALUES (1,'{$key}','override','{$type}','{$value}','2026-10-24 10:00:00','2026-10-24 10:00:00')" ); }
		DB::execute( $this->database, "INSERT INTO {$this->prefix}delivery_engine_configuration_collections (scope_row_id,field_key,mode,members_json,created_at,updated_at) VALUES (1,'delivery_offer_ids','replace','[20,21]','2026-10-24 10:00:00','2026-10-24 10:00:00')" );
	}
	private function worker( array $args ): OperationProofProcess { $p = new OperationProofProcess( [ __DIR__ . '/process-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $args, JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
	private function directory(): string { $path = OperationProofBarrier::directory(); $this->directories[] = $path; return $path; }
	private function marker( string $path ): array { OperationProofProcess::wait_for( $path, 20.0 ); return json_decode( (string) file_get_contents( $path ), true, 8, JSON_THROW_ON_ERROR ); }
	private function wait_on( string $table, string $index, int $waiter, int $blocker ): void {
		self::assertNotSame( $waiter, $blocker );
		$sql = "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id JOIN information_schema.INNODB_TRX r ON r.trx_id=w.requesting_trx_id JOIN information_schema.INNODB_TRX b ON b.trx_id=w.blocking_trx_id WHERE l.lock_table=CONCAT(CHAR(96),DATABASE(),CHAR(96),'.',CHAR(96),'{$table}',CHAR(96)) AND l.lock_index='{$index}' AND r.trx_mysql_thread_id={$waiter} AND b.trx_mysql_thread_id={$blocker} AND r.trx_state='LOCK WAIT'";
		$end = microtime( true ) + 1.5; $waiting = false;
		do { $waiting = (int) DB::scalar( $this->database, $sql ) > 0; if ( $waiting ) { break; } usleep( 120000 ); } while ( microtime( true ) < $end );
		self::assertTrue( $waiting, 'The exact physical owner and declared index must enter LOCK WAIT.' );
	}

	public function test_first_atomic_assignment_preserves_both_maps_source_and_all_nine_delivery_fields(): void {
		$original = $this->payload(); $original['reason'] = 'Owned café 東京 assignment proof'; $events = $this->count_rows( 'operation_changes' );
		$source = DB::row( $this->database, "SELECT SUM(revision) AS total FROM {$this->prefix}delivery_engine_rule_family_guards" );
		$result = $this->attempt( $original ); self::assertSame( 'accepted', $result->outcome->state );
		$state = $this->state(); self::assertSame( 1, $state->revision() ); self::assertSame( $original['generation'], $state->generation() ); self::assertGreaterThan( 0, $state->row_id() );
		self::assertSame( $original['reason'], json_decode( $state->original_bytes(), true, 16, JSON_THROW_ON_ERROR )['reason'] );
		self::assertSame( strlen( $state->original_bytes() ), (int) DB::scalar( $this->database, "SELECT OCTET_LENGTH(option_value) FROM {$this->prefix}options WHERE option_id=" . $state->row_id() ) );
		foreach ( [ Envelope::RETURN, Envelope::REFUND ] as $family ) { self::assertSame( $original['instructions'][$family], $state->map( $family )->facts()['default'] ); }
		self::assertSame( $events + 1, $this->count_rows( 'operation_changes' ) ); self::assertCount( 1, $this->assignment_rows() ); self::assertSame( 'off', $this->assignment_rows()[0]['autoload'] );
		self::assertSame( $this->legacy, $this->legacy_facts() ); self::assertSame( $source, DB::row( $this->database, "SELECT SUM(revision) AS total FROM {$this->prefix}delivery_engine_rule_family_guards WHERE family_code IN ('return_policy_v1','refund_policy_v1')" ) );
	}

	public function test_identical_maps_are_no_change_without_rewriting_revision_autoload_or_audit(): void {
		self::assertSame( 'accepted', $this->attempt( $this->payload(), 'first' )->outcome->state );
		$opened = $this->state(); $events = $this->count_rows( 'operation_changes' ); $before = $this->assignment_rows();
		$result = $this->attempt( $this->payload(), 'identical' ); self::assertSame( 'not_applicable', $result->outcome->state );
		self::assertSame( $before, $this->assignment_rows() ); self::assertSame( $opened->digest(), $this->state()->digest() ); self::assertSame( $events, $this->count_rows( 'operation_changes' ) );
	}

	public function test_stale_opened_bytes_and_revision_cannot_replace_a_newer_other_family_choice(): void {
		self::assertSame( 'accepted', $this->attempt( $this->payload(), 'first' )->outcome->state ); $stale = $this->payload();
		$new = $this->payload( slots: Envelope::slots( 'assigned', 'disabled' ) ); self::assertSame( 'accepted', $this->attempt( $new, 'newer' )->outcome->state ); $before = $this->assignment_rows();
		$stale['instructions'][Envelope::RETURN] = [ 'mode' => 'disabled' ]; $stale['reviewed'][Envelope::RETURN] = null;
		$result = $this->attempt( $stale, 'stale' ); self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( 'stale_revision', $result->outcome->error?->code );
		self::assertSame( $before, $this->assignment_rows() ); self::assertSame( $this->legacy, $this->legacy_facts() );
	}

	public function test_forged_actor_and_cross_family_review_refuse_without_assignment_effect(): void {
		$original = $this->payload(); $events = $this->count_rows( 'operation_changes' ); $forged = $original; $forged['actor_user_id'] = 10;
		[ $service, $factory, $identity ] = $this->stack( $forged, 'forged-author' );
		self::assertSame( 'rejected', $service->attempt( $identity, $forged, RequestContext::create() )->outcome->state ); self::assertSame( [], $factory->sessions );
		$foreign = $original; $foreign['instructions'][Envelope::RETURN]['policy_uuid'] = Envelope::uuid( 2 ); $foreign['reviewed'][Envelope::RETURN] = $original['reviewed'][Envelope::REFUND];
		self::assertSame( 'rejected', $this->attempt( $foreign, 'foreign-family' )->outcome->state ); self::assertSame( [], $this->assignment_rows() ); self::assertSame( $events, $this->count_rows( 'operation_changes' ) );
	}

	public function test_changed_reviewed_publication_is_rejected_before_any_option_effect(): void {
		$original = $this->payload(); $this->publish( Envelope::RETURN, 1, 102, [ 'summary' => 'A later published return policy.' ] );
		$events = $this->count_rows( 'operation_changes' ); $result = $this->attempt( $original );
		self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( [], $this->assignment_rows() ); self::assertSame( $events, $this->count_rows( 'operation_changes' ) );
		self::assertSame( 'accepted', $this->attempt( $this->payload(), 'fresh-review' )->outcome->state ); self::assertSame( $this->legacy, $this->legacy_facts() );
	}

	public function test_follow_current_preview_advances_after_publication_while_old_detached_capture_stays_exact(): void {
		self::assertSame( 'accepted', $this->attempt( $this->payload(), 'first' )->outcome->state ); $c = PolicyAssignmentState::make_coordinate( 1, 'global', 0 );
		$old = $this->capture( $c, 20 )['selections'][Envelope::RETURN]; $old_text = $old->version()->content()->facts(); $old_fingerprint = $old->fingerprint(); $row = $this->assignment_rows();
		$this->publish( Envelope::RETURN, 1, 102, [ 'full_text' => "Later supplied wording.\nStill exact text." ] );
		$new = $this->capture( $c, 20 )['selections'][Envelope::RETURN]; self::assertSame( 'assigned', $new->disposition() ); self::assertNotSame( $old_fingerprint, $new->fingerprint() );
		self::assertSame( "Later supplied wording.\nStill exact text.", $new->version()->content()->facts()['full_text'] ); self::assertSame( $old_text, $old->version()->content()->facts() ); self::assertSame( $row, $this->assignment_rows() );
	}

	private function rollback_fault( string $fault ): void {
		$original = $this->payload(); $events = $this->count_rows( 'operation_changes' );
		[ $service, $factory, $identity ] = $this->stack( $original, 'rollback-original', static function( RuleProofTransport $transport, int $open ) use ( $fault ): void {
			if ( 1 !== $open ) { return; } if ( 'audit' === $fault ) { $transport->reject_audit = true; } elseif ( 'completion' === $fault ) { $transport->reject_completion = true; } else { $transport->fault_commit = 2; $transport->commit_fault = 'unsent'; }
		} );
		$result = $service->attempt( $identity, $original, RequestContext::create() ); self::assertSame( 'rejected', $result->outcome->state ); $this->retired( $factory );
		self::assertSame( [], $this->assignment_rows() ); self::assertSame( $events, $this->count_rows( 'operation_changes' ) );
		if ( 'unsent' === $fault ) { self::assertSame( 2, $factory->transports[0]->commits ); self::assertSame( 1, $factory->transports[0]->sent_commits ); }
		self::assertSame( 'accepted', $service->attempt( $identity, $original, RequestContext::create() )->outcome->state ); self::assertSame( 1, $this->state()->revision() ); self::assertSame( $events + 1, $this->count_rows( 'operation_changes' ) );
		self::assertSame( $this->legacy, $this->legacy_facts() ); $this->retired( $factory );
	}
	public function test_audit_failure_rolls_back_both_maps_then_same_original_retry_commits_once(): void { $this->rollback_fault( 'audit' ); }
	public function test_completion_failure_rolls_back_both_maps_then_same_original_retry_commits_once(): void { $this->rollback_fault( 'completion' ); }
	public function test_proven_unsent_commit_rolls_back_and_preserves_original_retry_namespace(): void { $this->rollback_fault( 'unsent' ); }

	public function test_lost_commit_ack_fresh_process_reconciles_original_after_policy_head_changed(): void {
		$original = $this->payload(); [ $service, $factory, $identity ] = $this->stack( $original, 'lost-original', static function( RuleProofTransport $transport, int $open ): void { if ( 1 === $open ) { $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; } } );
		$events = $this->count_rows( 'operation_changes' ); self::assertSame( 'unconfirmed', $service->attempt( $identity, $original, RequestContext::create() )->outcome->state ); $this->retired( $factory );
		self::assertSame( 1, $this->state()->revision() ); self::assertSame( $events + 1, $this->count_rows( 'operation_changes' ) );
		$this->publish( Envelope::RETURN, 1, 102, [ 'full_text' => 'Policy published after the original assignment committed.' ] ); $before = $this->assignment_rows(); $events = $this->count_rows( 'operation_changes' );
		$packet = $this->worker( [ 'payload' => $original, 'token' => 'lost-original', 'action' => 'reconcile' ] )->finish();
		self::assertSame( 'accepted', $packet['state'] ); self::assertTrue( $packet['replayed'] ); self::assertTrue( $packet['all_retired'] ); self::assertSame( $before, $this->assignment_rows() ); self::assertSame( $events, $this->count_rows( 'operation_changes' ) );
		$again = $this->attempt( $original, 'lost-original' ); self::assertSame( 'accepted', $again->outcome->state ); self::assertTrue( $again->replayed ); self::assertSame( $packet['completion_json'], $again->completion?->to_json() );
	}

	public function test_two_tokens_wait_on_prepared_assignment_guard_and_only_one_opened_revision_commits(): void {
		self::assertSame( 'accepted', $this->attempt( $this->payload(), 'prepare' )->outcome->state ); $left_payload = $this->payload( slots: Envelope::slots( 'disabled', 'assigned' ) ); $right_payload = $this->payload( slots: Envelope::slots( 'assigned', 'disabled' ) );
		$events = $this->count_rows( 'operation_changes' ); $directory = $this->directory();
		$right = $this->worker( [ 'role' => 'right', 'directory' => $directory, 'payload' => $right_payload, 'token' => 'right' ] ); $left = null;
		try {
			$reserved = $this->marker( $directory . '/right-reserved' ); self::assertFalse( $reserved['in_transaction'] ); self::assertTrue( $reserved['autocommit'] );
			$left = $this->worker( [ 'role' => 'left', 'directory' => $directory, 'payload' => $left_payload, 'token' => 'left' ] ); $held = $this->marker( $directory . '/left-held' );
			self::assertTrue( $held['in_transaction'] ); self::assertTrue( $held['autocommit'] ); OperationProofBarrier::signal( $directory . '/release-right' ); $dispatched = $this->marker( $directory . '/right-dispatched' );
			self::assertSame( $reserved['connection_id'], $dispatched['connection_id'] );
			$this->wait_on( $this->prefix . 'delivery_engine_rule_family_guards', 'site_family', (int) $dispatched['connection_id'], (int) $held['connection_id'] );
			self::assertSame( 1, $this->state_without_locks()->revision() );
		} finally { OperationProofBarrier::signal( $directory . '/release-right' ); OperationProofBarrier::signal( $directory . '/release-left' ); }
		self::assertInstanceOf( OperationProofProcess::class, $left ); $winner = $left->finish(); $loser = $right->finish();
		self::assertSame( 'accepted', $winner['state'] ); self::assertSame( 'rejected', $loser['state'] ); self::assertSame( 'stale_revision', $loser['error_code'] ); self::assertTrue( $winner['all_retired'] ); self::assertTrue( $loser['all_retired'] );
		self::assertSame( 2, $this->state()->revision() ); self::assertSame( $events + 1, $this->count_rows( 'operation_changes' ) ); self::assertSame( $this->legacy, $this->legacy_facts() );
	}

	public function test_physical_scope_chain_resolves_independent_real_offer_slots_without_touching_delivery_rows(): void {
		$global = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $product = PolicyAssignmentState::make_coordinate( 1, 'product', 77 ); $variation = PolicyAssignmentState::make_coordinate( 1, 'variation', 78, 77 );
		self::assertSame( 'accepted', $this->attempt( $this->payload( $global ), 'global' )->outcome->state );
		self::assertSame( 'accepted', $this->attempt( $this->payload( $product, 20, Envelope::slots( 'disabled', 'inherit' ) ), 'product20' )->outcome->state );
		self::assertSame( 'accepted', $this->attempt( $this->payload( $variation, 20, Envelope::slots( 'assigned', 'disabled' ) ), 'variation20' )->outcome->state );
		$p20 = $this->capture( $product, 20 )['selections']; self::assertSame( 'disabled', $p20[Envelope::RETURN]->disposition() ); self::assertSame( 'assigned', $p20[Envelope::REFUND]->disposition() );
		$v20 = $this->capture( $variation, 20 )['selections']; self::assertSame( 'assigned', $v20[Envelope::RETURN]->disposition() ); self::assertSame( 'disabled', $v20[Envelope::REFUND]->disposition() );
		$v21 = $this->capture( $variation, 21 )['selections']; self::assertSame( 'assigned', $v21[Envelope::RETURN]->disposition() ); self::assertSame( 'assigned', $v21[Envelope::REFUND]->disposition() );
		self::assertNotSame( $v20[Envelope::RETURN]->fingerprint(), $v21[Envelope::RETURN]->fingerprint() ); self::assertCount( 3, $this->assignment_rows() ); self::assertSame( $this->legacy, $this->legacy_facts() );
	}

	private function state_without_locks(): PolicyAssignmentState {
		$c = PolicyAssignmentState::make_coordinate( 1, 'global', 0 ); $name = Envelope::store( $this->prefix )->name( $c );
		$row = DB::row( $this->database, "SELECT * FROM {$this->prefix}options WHERE option_name='" . $this->database->real_escape_string( $name ) . "'" );
		return PolicyAssignmentState::from_physical( 1, 'global', 0, 0, (int) $row['option_id'], $row['option_value'] );
	}

	public function test_external_options_row_owner_proves_exact_option_name_lock_before_any_cas(): void {
		self::assertSame( 'accepted', $this->attempt( $this->payload(), 'prepare' )->outcome->state ); $original = $this->payload( slots: Envelope::slots( 'disabled', 'assigned' ) ); $before = $this->assignment_rows();
		$blocker = DB::connect(); $directory = $this->directory(); $worker = null;
		try {
			DB::execute( $blocker, 'START TRANSACTION' ); $name = Envelope::store( $this->prefix )->name( $original['coordinate'] );
			DB::row( $blocker, "SELECT * FROM {$this->prefix}options FORCE INDEX (`option_name`) WHERE option_name='" . $blocker->real_escape_string( $name ) . "' FOR UPDATE" );
			$worker = $this->worker( [ 'role' => 'option_wait', 'directory' => $directory, 'payload' => $original, 'token' => 'option-wait' ] ); $dispatch = $this->marker( $directory . '/option-dispatched' );
			$this->wait_on( $this->prefix . 'options', 'option_name', (int) $dispatch['connection_id'], (int) $blocker->thread_id ); self::assertSame( $before, $this->assignment_rows() );
		} finally { $blocker->rollback(); $blocker->close(); }
		self::assertInstanceOf( OperationProofProcess::class, $worker ); $result = $worker->finish(); self::assertSame( 'accepted', $result['state'] ); self::assertTrue( $result['all_retired'] );
		self::assertSame( 2, $this->state()->revision() ); self::assertSame( [ 'mode' => 'disabled' ], $this->state()->map( Envelope::RETURN )->facts()['default'] ); self::assertSame( $this->legacy, $this->legacy_facts() );
	}
}
