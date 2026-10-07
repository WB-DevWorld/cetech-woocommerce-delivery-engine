<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\EmergencyControl\EmergencyControlProofFactory;
use CetechDeliveryEngine\Tests\Support\EmergencyControl\EmergencyControlProofTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Native options/C03/two-process proofs. Selected missing prerequisites fail, never skip. */
#[Group( 'emergency-control-real-db' )]
final class EmergencyControlRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $directories = [];
	private array $processes = [];
	private EmergencyControlService $service;
	private EmergencyControlProofFactory $factory;
	private const NOW = 2000000000;

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix );
		[ $this->service, $this->factory ] = $this->stack();
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); } $this->processes = [];
		foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function stack( ?callable $configure = null, ?EmergencyControlStore $store = null, int $site = 1, bool $trusted_route = true ): array {
		$factory = new EmergencyControlProofFactory( $this->prefix, $site ); $factory->configure = null === $configure ? null : \Closure::fromCallable( $configure ); $this->factories[] = $factory;
		$service = new EmergencyControlService( $factory, static fn ( int $s, int $actor ): bool => 1 === $s && 9 === $actor, $store ?? new EmergencyControlStore( static fn (): bool => false ), trusted_equivalent_route: $trusted_route );
		return [ $service, $factory ];
	}
	private function current( ?EmergencyControlService $service = null ): EmergencyControlState {
		$r = ( $service ?? $this->service )->read( 1 ); self::assertSame( 'ready', $r->status ); self::assertTrue( $r->available ); return $r->state;
	}
	private function payload( string $desired = 'checkout_suspended', ?EmergencyControlState $opened = null ): array {
		return EmergencyControlCommand::payload( $opened ?? $this->current(), $desired, 'enabled' === $desired ? 'resume_verified' : 'operator_pause' );
	}
	private function transition( string $token = 'original', ?array $payload = null, ?EmergencyControlService $service = null ): OperationAttemptResult {
		return ( $service ?? $this->service )->transition( EmergencyControlCommand::identity( 1, 9, $token ), $payload ?? $this->payload(), RequestContext::create() );
	}
	private function accept( OperationAttemptResult $r ): void { self::assertSame( 'accepted', $r->outcome->state ); self::assertTrue( $r->outcome->mutation_accepted ); self::assertNull( $r->outcome->error ); }
	private function row_count( string $suffix ): int { return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_{$suffix}`" ); }
	private function control(): ?array { return DB::row( $this->database, "SELECT * FROM `{$this->prefix}options` WHERE option_name='cetech_de_checkout_control_v1'" ); }
	private function records(): array { $r = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_operation_records` ORDER BY id" ); if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException(); } return $r->fetch_all( MYSQLI_ASSOC ); }
	private function definition( string $suffix ): array { $row = DB::row( $this->database, "SHOW CREATE TABLE `{$this->prefix}delivery_engine_{$suffix}`" ); return array_map( static fn ( string $value ): string => preg_replace( '/ AUTO_INCREMENT=[0-9]+\b/', '', $value ), $row ); }
	private function directory(): string { $d = OperationProofBarrier::directory(); $this->directories[] = $d; return $d; }
	private function worker( array $args ): OperationProofProcess { $p = new OperationProofProcess( [ __DIR__ . '/process-worker.php', json_encode( [ 'prefix' => $this->prefix ] + $args, JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
	private function wait_for_lock_wait(): void {
		$end = microtime( true ) + 1.5;
		do { if ( (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS w JOIN information_schema.INNODB_LOCKS l ON l.lock_id=w.requested_lock_id WHERE l.lock_table=CONCAT('`', DATABASE(), '`.`{$this->prefix}options`')" ) > 0 ) { self::assertTrue( true ); return; } usleep( 10000 ); } while ( microtime( true ) < $end );
		self::fail( 'The real second native connection did not enter a lock wait.' );
	}

	public function test_native_options_identity_rr_snapshot_zero_and_two_second_wait_are_actual(): void {
		$s = $this->factory->open(); self::assertTrue( $s->begin() ); ( new EmergencyControlStore() )->assert_ready( $s, 1 );
		$runtime = $s->get_row( 'SELECT @@SESSION.tx_isolation AS isolation_level, @@SESSION.innodb_snapshot_isolation AS snapshot_isolation, @@SESSION.innodb_lock_wait_timeout AS lock_wait_seconds' );
		self::assertSame( 'REPEATABLE-READ', $runtime['isolation_level'] ); self::assertSame( '0', (string) $runtime['snapshot_isolation'] ); self::assertSame( '2', (string) $runtime['lock_wait_seconds'] );
		self::assertSame( 'InnoDB', DB::row( $this->database, "SHOW TABLE STATUS WHERE Name='{$this->prefix}options'" )['Engine'] ); self::assertTrue( $s->rollback() ); self::assertTrue( $s->retire() );
	}
	public function test_ready_absence_is_virtual_enabled_revision_one_without_initialization_write(): void {
		$before = DB::options( $this->database, $this->prefix ); $s = $this->current();
		self::assertSame( [ 'enabled', 1, 0, false, null, null, '' ], [ $s->state, $s->revision, $s->row_id, $s->initialized, $s->actor_user_id, $s->changed_at_epoch, $s->original_bytes() ] );
		self::assertSame( $before, DB::options( $this->database, $this->prefix ) ); self::assertSame( 0, $this->row_count( 'operation_records' ) ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}
	public function test_first_changed_pause_is_revision_one_to_two_atomic_option_event_and_completion(): void {
		$this->accept( $this->transition() ); $row = $this->control(); $s = $this->current();
		self::assertSame( [ 'checkout_suspended', 2, 9, self::NOW, 'off' ], [ $s->state, $s->revision, $s->actor_user_id, $s->changed_at_epoch, $row['autoload'] ] );
		self::assertSame( 1, $this->row_count( 'operation_records' ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( 'accepted', $this->records()[0]['state'] );
		$event = json_decode( DB::scalar( $this->database, "SELECT event_json FROM `{$this->prefix}delivery_engine_operation_changes`" ), true, 8, JSON_THROW_ON_ERROR );
		self::assertSame( [ 1, 2 ], [ $event['before_revision'], $event['after_revision'] ] ); self::assertStringNotContainsString( 'original', json_encode( $event, JSON_THROW_ON_ERROR ) );
	}
	public function test_unchanged_legacy_enable_records_completion_without_inserting_state_or_audit(): void {
		$p = $this->payload( 'enabled' ); $r = $this->transition( 'noop', $p ); self::assertSame( 'not_applicable', $r->outcome->state ); self::assertNull( $this->control() ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
		$this->accept( $this->transition() ); $before = $this->control(); $replay = $this->transition( 'noop', $p ); self::assertSame( 'not_applicable', $replay->outcome->state ); self::assertTrue( $replay->replayed ); self::assertSame( $before, $this->control() );
	}
	public function test_same_physical_state_does_not_bump_revision_or_append_another_material_event(): void {
		$this->accept( $this->transition() ); $before = $this->control(); $r = $this->transition( 'same-state', $this->payload() );
		self::assertSame( 'not_applicable', $r->outcome->state ); self::assertSame( $before, $this->control() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( 2, $this->row_count( 'operation_records' ) );
	}
	public function test_refused_material_append_rolls_back_the_first_option_insertion(): void {
		[ $service ] = $this->stack( static function ( EmergencyControlProofTransport $t ): void { $t->reject_audit = true; } );
		$r = $this->transition( service: $service ); self::assertSame( 'rejected', $r->outcome->state ); self::assertFalse( $r->outcome->mutation_accepted ); self::assertNull( $this->control() ); self::assertSame( 0, $this->row_count( 'operation_changes' ) ); self::assertNotSame( 'accepted', $this->records()[0]['state'] );
	}
	public function test_refused_target_write_rolls_back_material_and_completion_acceptance(): void {
		$this->accept( $this->transition() ); $before = $this->control(); $p = $this->payload( 'enabled' );
		[ $service ] = $this->stack( static function ( EmergencyControlProofTransport $t ): void { $t->reject_state = true; } );
		$r = $this->transition( 'refused-cas', $p, $service ); self::assertSame( 'rejected', $r->outcome->state ); self::assertSame( $before, $this->control() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( 1, count( array_filter( $this->records(), static fn ( array $r ): bool => 'accepted' === $r['state'] ) ) );
	}
	public function test_second_opened_form_cannot_overwrite_a_newer_accepted_state(): void {
		$old = $this->payload(); $this->accept( $this->transition( 'winner', $old ) ); $before = $this->control();
		$r = $this->transition( 'stale', $old ); self::assertSame( 'stale_revision', $r->outcome->error?->code ); self::assertSame( $before, $this->control() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_recreated_physical_identity_with_identical_bytes_refuses_the_old_form(): void {
		$this->accept( $this->transition() ); $p = $this->payload( 'enabled' ); $old = $this->control();
		DB::execute( $this->database, "DELETE FROM `{$this->prefix}options` WHERE option_id=" . (int) $old['option_id'] ); DB::insert_option( $this->database, $this->prefix, EmergencyControlStore::OPTION_NAME, $old['option_value'] ); $recreated = $this->control();
		self::assertGreaterThan( (int) $old['option_id'], (int) $recreated['option_id'] ); $r = $this->transition( 'old-row', $p ); self::assertSame( 'stale_revision', $r->outcome->error?->code ); self::assertSame( $recreated, $this->control() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_current_locked_read_escapes_a_preopened_consistent_read_view(): void {
		$this->accept( $this->transition() ); $resume = $this->payload( 'enabled' ); $old = $this->control(); $snapshot_bytes = null; $once = false;
		[ $reader ] = $this->stack( function ( EmergencyControlProofTransport $t ) use ( $resume, $old, &$snapshot_bytes, &$once ): void {
			$t->before = function ( string $sql, EmergencyControlProofTransport $transport ) use ( $resume, $old, &$snapshot_bytes, &$once ): void {
				if ( $once || ! str_contains( $sql, EmergencyControlStore::OPTION_NAME ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $once = true;
				$r = $transport->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_id=" . (int) $old['option_id'] ); self::assertTrue( $r->acknowledged ); $snapshot_bytes = $r->rows[0]['option_value']; $this->accept( $this->transition( 'newer', $resume ) );
			};
		} );
		$state = $this->current( $reader ); self::assertSame( $old['option_value'], $snapshot_bytes ); self::assertSame( [ 'enabled', 3 ], [ $state->state, $state->revision ] ); self::assertNotSame( $snapshot_bytes, $state->original_bytes() );
	}
	public function test_absent_unique_key_lock_admits_first_and_the_second_process_pause_waits(): void {
		$p = $this->payload(); $d = $this->directory();
		$admission = $this->worker( [ 'action' => 'confirm', 'revision' => 1, 'lock_ready' => $d . '/admission', 'lock_release' => $d . '/release' ] ); OperationProofProcess::wait_for( $d . '/admission' );
		$pause = $this->worker( [ 'action' => 'transition', 'token' => 'later-pause', 'payload' => $p, 'state_dispatch' => $d . '/pause' ] ); OperationProofProcess::wait_for( $d . '/pause' ); $lock_failure = null; try { $this->wait_for_lock_wait(); } catch ( \Throwable $error ) { $lock_failure = $error->getMessage(); }
		OperationProofBarrier::signal( $d . '/release' ); $a = $admission->finish(); $b = $pause->finish(); self::assertNull( $lock_failure, json_encode( [ 'admission' => $a, 'pause' => $b ], JSON_THROW_ON_ERROR ) );
		self::assertSame( [ 'ready', true, 1 ], [ $a['status'], $a['available'], $a['revision'] ] ); self::assertSame( 'accepted', $b['state'] ); self::assertNotSame( $a['process_id'], $b['process_id'] ); self::assertNotSame( getmypid(), $a['process_id'] ); self::assertSame( 'checkout_suspended', $this->current()->state );
	}
	public function test_committed_pause_before_admission_refuses_without_control_mutation(): void {
		$this->accept( $this->transition() ); $before = $this->control(); $r = $this->worker( [ 'action' => 'confirm', 'revision' => 1 ] )->finish();
		self::assertSame( [ 'unavailable', false, 'checkout_suspended' ], [ $r['status'], $r['available'], $r['code'] ] ); self::assertSame( 0, $r['state_writes'] ); self::assertSame( 0, $r['audit_appends'] ); self::assertSame( $before, $this->control() );
	}
	public function test_two_os_processes_with_the_same_opened_control_accept_only_one_change(): void {
		$p = $this->payload(); $d = $this->directory(); $left = $this->worker( [ 'action' => 'transition', 'token' => 'left', 'payload' => $p, 'start_ready' => $d . '/left', 'start_release' => $d . '/go' ] ); $right = $this->worker( [ 'action' => 'transition', 'token' => 'right', 'payload' => $p, 'start_ready' => $d . '/right', 'start_release' => $d . '/go' ] );
		OperationProofProcess::wait_for( $d . '/left' ); OperationProofProcess::wait_for( $d . '/right' ); OperationProofBarrier::signal( $d . '/go' ); $results = [ $left->finish(), $right->finish() ];
		self::assertSame( 1, count( array_filter( $results, static fn ( array $r ): bool => 'accepted' === $r['state'] ) ) ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( 2, $this->current()->revision ); self::assertNotSame( $results[0]['process_id'], $results[1]['process_id'] );
		$loser = 'accepted' === $results[0]['state'] ? 'right' : 'left'; $before = $this->control(); $r = $this->transition( $loser, $p ); self::assertSame( 'stale_revision', $r->outcome->error?->code ); self::assertSame( $before, $this->control() );
	}
	public function test_initialized_admission_lock_precedes_pause_and_releases_before_the_pause_commit(): void {
		$this->accept( $this->transition() ); $this->accept( $this->transition( 'resume', $this->payload( 'enabled' ) ) ); $p = $this->payload(); $d = $this->directory();
		$admission = $this->worker( [ 'action' => 'confirm', 'revision' => 3, 'lock_ready' => $d . '/admission', 'lock_release' => $d . '/release' ] ); OperationProofProcess::wait_for( $d . '/admission' );
		$pause = $this->worker( [ 'action' => 'transition', 'token' => 'second-pause', 'payload' => $p, 'lock_dispatch' => $d . '/pause' ] ); OperationProofProcess::wait_for( $d . '/pause' ); $this->wait_for_lock_wait(); OperationProofBarrier::signal( $d . '/release' );
		$a = $admission->finish(); $b = $pause->finish(); self::assertTrue( $a['available'] ); self::assertSame( 3, $a['revision'] ); self::assertSame( 'accepted', $b['state'] ); self::assertSame( [ 'checkout_suspended', 4 ], [ $this->current()->state, $this->current()->revision ] );
	}
	public function test_actual_row_lock_timeout_is_two_seconds_and_never_grants_admission(): void {
		$this->accept( $this->transition() ); $this->accept( $this->transition( 'resume', $this->payload( 'enabled' ) ) ); $s = $this->factory->open(); self::assertTrue( $s->begin() ); $store = new EmergencyControlStore(); $store->assert_ready( $s, 1 ); $store->current( $s );
		$r = $this->worker( [ 'action' => 'confirm', 'revision' => 3 ] )->finish( 8.0 ); self::assertFalse( $r['available'] ); self::assertGreaterThanOrEqual( 1800, $r['elapsed_ms'] ); self::assertLessThan( 5500, $r['elapsed_ms'] ); self::assertSame( 1, $r['lock_timeouts'] ); self::assertSame( 0, $r['state_writes'] ); self::assertTrue( $s->rollback() ); $s->retire();
	}
	public function test_non_innodb_options_refuse_without_ddl_repair_or_control_mutation(): void {
		$this->accept( $this->transition() ); $before = $this->control(); DB::execute( $this->database, "ALTER TABLE `{$this->prefix}options` ENGINE=MyISAM" );
		$r = $this->service->read( 1 ); self::assertFalse( $r->available ); self::assertSame( $before, $this->control() ); self::assertSame( 'MyISAM', DB::row( $this->database, "SHOW TABLE STATUS WHERE Name='{$this->prefix}options'" )['Engine'] ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_read_committed_does_not_claim_missing_key_admission_protection(): void {
		$this->factory->isolation = 'READ COMMITTED'; $r = $this->service->confirm_enabled( 1, 1, static fn (): bool => true ); self::assertFalse( $r->available ); self::assertSame( 'temporarily_unavailable', $r->code ); self::assertNull( $this->control() ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}
	public function test_wrong_native_site_and_unsupported_wordpress_routing_refuse(): void {
		[ $wrong ] = $this->stack( site: 2 ); self::assertFalse( $wrong->read( 1 )->available );
		[ $unrouted ] = $this->stack( trusted_route: false ); $saved = $GLOBALS['wpdb'] ?? null; unset( $GLOBALS['wpdb'] ); try { self::assertFalse( $unrouted->read( 1 )->available ); } finally { if ( null !== $saved ) { $GLOBALS['wpdb'] = $saved; } }
		self::assertNull( $this->control() ); self::assertSame( 0, $this->row_count( 'operation_records' ) );
	}
	public function test_real_ambient_native_transaction_is_retired_and_never_admitted(): void {
		[ $service, $factory ] = $this->stack( static function ( EmergencyControlProofTransport $t ): void { self::assertTrue( $t->native_execute( 'START TRANSACTION' )->acknowledged ); } );
		$r = $service->confirm_enabled( 1, 1, static fn (): bool => true ); self::assertFalse( $r->available ); self::assertTrue( $factory->sessions[0]->is_retired() ); self::assertSame( 0, $factory->transports[0]->state_writes ); self::assertNull( $this->control() );
	}
	public function test_positive_unsent_effect_commit_rolls_back_and_original_request_can_apply_once(): void {
		$p = $this->payload(); [ $service, $factory ] = $this->stack( static function ( EmergencyControlProofTransport $t ): void { $t->fault_commit = 2; $t->commit_fault = 'unsent'; } );
		$r = $this->transition( 'unsent', $p, $service ); self::assertSame( 'rejected', $r->outcome->state ); self::assertNull( $this->control() ); self::assertSame( 0, $this->row_count( 'operation_changes' ) ); self::assertGreaterThanOrEqual( 1, $factory->transports[0]->sent_commits );
		$this->accept( $this->transition( 'unsent', $p ) ); self::assertSame( 2, $this->current()->revision ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_actual_committed_lost_acknowledgement_reconciles_in_a_fresh_os_process_once(): void {
		$p = $this->payload(); [ $service, $factory ] = $this->stack( static function ( EmergencyControlProofTransport $t ): void { $t->fault_commit = 2; $t->commit_fault = 'lost_ack'; } );
		$r = $this->transition( 'lost-ack', $p, $service ); self::assertSame( 'unconfirmed', $r->outcome->state ); self::assertSame( 'outcome_unknown', $r->outcome->error?->code ); $before = $this->control(); self::assertNotNull( $before ); self::assertSame( 1, $this->row_count( 'operation_changes' ) ); self::assertSame( 2, $factory->transports[0]->sent_commits );
		$fresh = $this->worker( [ 'action' => 'reconcile', 'token' => 'lost-ack', 'payload' => $p ] )->finish(); self::assertSame( 'accepted', $fresh['state'] ); self::assertTrue( $fresh['replayed'] ); self::assertSame( 0, $fresh['state_writes'] ); self::assertSame( 0, $fresh['audit_appends'] ); self::assertSame( $before, $this->control() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_fresh_process_original_token_buried_under101_later_events_does_not_restore_old_state(): void {
		$p = $this->payload(); $this->accept( $this->transition( 'buried', $p ) ); $record = $this->records()[0]; $event = DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_changes` ORDER BY id LIMIT 1" );
		for ( $i = 0; $i < 101; ++$i ) { $desired = 0 === $i % 2 ? 'enabled' : 'checkout_suspended'; $this->accept( $this->transition( 'later-' . $i, $this->payload( $desired ) ) ); }
		$current = $this->control(); self::assertSame( [ 'enabled', 103 ], [ $this->current()->state, $this->current()->revision ] );
		$fresh = $this->worker( [ 'action' => 'transition', 'token' => 'buried', 'payload' => $p ] )->finish(); self::assertSame( 'accepted', $fresh['state'] ); self::assertTrue( $fresh['replayed'] ); self::assertSame( 0, $fresh['state_writes'] ); self::assertSame( 0, $fresh['audit_appends'] );
		self::assertSame( $record, $this->records()[0] ); self::assertSame( $event, DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_changes` ORDER BY id LIMIT 1" ) ); self::assertSame( $current, $this->control() ); self::assertSame( 102, $this->row_count( 'operation_changes' ) );
	}
	public function test_same_token_changed_intent_conflicts_without_overwriting_original_completion(): void {
		$p = $this->payload(); $this->accept( $this->transition( 'conflict', $p ) ); $before = $this->control(); $records = $this->records(); $p['reason_code'] = 'incident_pause';
		$r = $this->transition( 'conflict', $p ); self::assertSame( 'intent_conflict', $r->outcome->error?->code ); self::assertSame( $before, $this->control() ); self::assertSame( $records, $this->records() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_publication_provider_exception_preserves_committed_pause_and_original_retry(): void {
		$p = $this->payload(); [ $service ] = $this->stack( store: new EmergencyControlStore( static function (): never { throw new \RuntimeException( 'synthetic provider unavailable' ); } ) );
		$r = $this->transition( 'publish-fail', $p, $service ); self::assertTrue( $r->outcome->mutation_accepted ); self::assertTrue( $r->outcome->publication_pending ); $before = $this->control(); self::assertSame( 'checkout_suspended', $this->current()->state );
		$replay = $this->transition( 'publish-fail', $p ); self::assertSame( 'accepted', $replay->outcome->state ); self::assertTrue( $replay->replayed ); self::assertSame( $before, $this->control() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_delayed_old_publication_only_invalidates_named_cache_entries_never_restores_old_value(): void {
		$deleted = []; $p = $this->payload(); [ $delayed ] = $this->stack( store: new EmergencyControlStore( static function ( string $name, string $group ) use ( &$deleted ): bool { $deleted[] = [ $name, $group ]; throw new \RuntimeException( 'synthetic initial publication failure' ); } ) );
		$this->transition( 'old', $p, $delayed ); $this->accept( $this->transition( 'opposite', $this->payload( 'enabled' ) ) ); $before = $this->control(); $deleted = [];
		[ $retry, $factory ] = $this->stack( store: new EmergencyControlStore( static function ( string $name, string $group ) use ( &$deleted ): bool { $deleted[] = [ $name, $group ]; return false; } ) );
		$r = $this->transition( 'old', $p, $retry ); self::assertSame( 'accepted', $r->outcome->state ); self::assertTrue( $r->replayed ); self::assertSame( [ [ EmergencyControlStore::OPTION_NAME, 'options' ], [ 'alloptions', 'options' ], [ 'notoptions', 'options' ] ], $deleted ); self::assertSame( 0, array_sum( array_column( $factory->transports, 'state_writes' ) ) ); self::assertSame( $before, $this->control() ); self::assertSame( 'enabled', $this->current()->state );
	}
	public function test_unacknowledged_read_release_and_retirement_never_grant_admission(): void {
		[ $service ] = $this->stack( static function ( EmergencyControlProofTransport $t ): void { $t->reject_rollback = true; $t->reject_close = true; } );
		$r = $service->confirm_enabled( 1, 1, static fn (): bool => true ); self::assertFalse( $r->available ); self::assertSame( 'outcome_unknown', $r->code ); self::assertNull( $this->control() ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}
	public function test_backward_native_utc_time_refuses_the_transition_without_history_repair(): void {
		$this->accept( $this->transition() ); $before = $this->control(); $p = $this->payload( 'enabled' ); $this->factory->clock = self::NOW - 1;
		$r = $this->transition( 'backwards', $p ); self::assertSame( 'rejected', $r->outcome->state ); self::assertSame( $before, $this->control() ); self::assertSame( 1, $this->row_count( 'operation_changes' ) );
	}
	public function test_revision_overflow_and_corrupt_duplicate_private_bytes_are_preserved_and_refused(): void {
		$bytes = EmergencyControlState::record_json( 1, 'enabled', PHP_INT_MAX, 'resume_verified', 9, self::NOW ); DB::insert_option( $this->database, $this->prefix, EmergencyControlStore::OPTION_NAME, $bytes ); $before = $this->control();
		$r = $this->transition( 'overflow', $this->payload() ); self::assertSame( 'rejected', $r->outcome->state ); self::assertSame( $before, $this->control() ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
		$bad = '{"format_version":1,"format_version":1,"private":"DO_NOT_ECHO"}'; $statement = $this->database->prepare( "UPDATE `{$this->prefix}options` SET option_value=? WHERE option_name='cetech_de_checkout_control_v1'" ); $statement->bind_param( 's', $bad ); self::assertTrue( $statement->execute() ); $statement->close(); $r = $this->service->read( 1 ); self::assertFalse( $r->available ); self::assertSame( $bad, $this->control()['option_value'] ); self::assertStringNotContainsString( 'DO_NOT_ECHO', json_encode( $r->error?->to_array(), JSON_THROW_ON_ERROR ) );
	}
	public function test_shortened_unique_option_identity_index_refuses_without_automatic_schema_change(): void {
		DB::execute( $this->database, "ALTER TABLE `{$this->prefix}options` DROP INDEX option_name, ADD UNIQUE INDEX option_name (option_name(32))" );
		self::assertFalse( $this->service->read( 1 )->available ); $index = DB::row( $this->database, "SHOW INDEX FROM `{$this->prefix}options` WHERE Key_name='option_name'" ); self::assertSame( '32', (string) $index['Sub_part'] ); self::assertNull( $this->control() ); self::assertSame( 0, $this->row_count( 'operation_changes' ) );
	}
	public function test_existing_authored_flags_domain_tables_and_previous_completion_bytes_remain_preserved(): void {
		$flags = new FeatureFlags(); $names = [];
		foreach ( $flags->defaults() as $name => $value ) { $key = $flags->option_name( $name ); DB::insert_option( $this->database, $this->prefix, $key, $value ? '1' : '0', 'on' ); $names[] = $key; }
		$before = DB::options( $this->database, $this->prefix ); $definitions = [];
		foreach ( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix ) { $definitions[$suffix] = $this->definition( $suffix ); }
		self::assertCount( 23, $names ); self::assertCount( 32, $definitions ); $this->accept( $this->transition() ); $accepted = $this->records()[0]; $this->accept( $this->transition( 'resume', $this->payload( 'enabled' ) ) );
		foreach ( $definitions as $suffix => $definition ) { self::assertSame( $definition, $this->definition( $suffix ) ); }
		$after = array_values( array_filter( DB::options( $this->database, $this->prefix ), static fn ( array $r ): bool => EmergencyControlStore::OPTION_NAME !== $r['option_name'] ) ); self::assertSame( $before, $after ); self::assertSame( $accepted, $this->records()[0] );
	}
}
