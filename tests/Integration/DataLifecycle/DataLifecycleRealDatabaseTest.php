<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DataLifecycle;

use CetechDeliveryEngine\Application\DataLifecycle\DataLifecycleCleanupService;
use CetechDeliveryEngine\Application\DataLifecycle\ManagedGeographyCache;
use CetechDeliveryEngine\Application\Operation\OperationCoordinator;
use CetechDeliveryEngine\Application\RuleLifecycle\RuleLifecycleService;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleContinuation;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheEnvelope;
use CetechDeliveryEngine\Domain\DataLifecycle\ManagedGeographyCacheIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationProfileRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;
use CetechDeliveryEngine\Infrastructure\Persistence\AbstractWpdbRepository;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofConfiguration;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofFactory;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofTransport;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofBarrier;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofFactory;
use CetechDeliveryEngine\Tests\Support\Operation\OperationProofProcess;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofOperationProfile;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofEnvelope;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFactory;
use CetechDeliveryEngine\Tests\Support\RuleLifecycle\RuleProofFamily;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual disposable SQL/process proofs. Missing runtime/database is a failure, never a skip.
 * @group data-lifecycle-real-db
 */
final class DataLifecycleRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private DataLifecycleProofFactory $factory;
	private DataLifecycleCleanupService $service;
	private array $barriers = [];
	private array $processes = [];
	private array $extra_factories = [];
	private const NOW = 2000000000;
	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix ); DB::insert_option( $this->database, $this->prefix, 'cetech_de_geography_revision', 'opaque:revision-v1' );
		$this->factory = new DataLifecycleProofFactory( $this->prefix, 1, self::NOW ); $this->service = $this->service();
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); }
		$this->processes = []; $this->factory->close_all(); foreach ( $this->extra_factories as $factory ) { $factory->close_all(); }
		foreach ( $this->barriers as $barrier ) { OperationProofBarrier::cleanup( $barrier ); }
		AbstractWpdbRepository::reset_transaction_state(); unset( $GLOBALS['wpdb'] ); DB::cleanup( $this->database, $this->prefix ); $this->database->close();
	}
	public function test_normal_cleanup_preserves_all32_physical_domain_tables_and_authored_options(): void {
		$tables = $this->domain_tables(); self::assertCount( 35, $tables );
		$original32 = array_map( fn( string $suffix ): string => $this->prefix . 'delivery_engine_' . $suffix, DataLifecycleManifest::ORIGINAL_DOMAIN_TABLE_SUFFIXES );
		self::assertCount( 32, $original32 ); self::assertCount( 32, array_intersect( $original32, $tables ) );
		foreach ( $tables as $table ) { $this->seed_sentinel( $table ); }
		$before = $this->domain_bytes();
		$preserved = [ 'cetech_de_sitewide_defaults', 'cetech_de_global_configuration_version', 'cetech_de_country_identity_repair_lock', 'cetech_de_delete_data_on_uninstall', '_transient_cetech_de_notice_private', '_transient_timeout_cetech_de_notice_private', '_transient_cetech_de_geo_old', '_transient_timeout_cetech_de_geo_old' ];
		foreach ( $preserved as $i => $name ) { DB::insert_option( $this->database, $this->prefix, $name, "PRIVATE_SENTINEL_{$i}", 0 === $i % 2 ? 'on' : 'off' ); }
		$option_bytes = $this->without_controls(); $this->seed( 1 ); $result = $this->complete(); self::assertSame( 1, $result->progress->deleted ); self::assertSame( $before, $this->domain_bytes() );
		self::assertSame( $option_bytes, $this->without_controls() );
	}
	public function test_cor007_original_token_survives101_later_material_audits_and_fresh_process_replay(): void {
		$wpdb = DataLifecycleProofConfiguration::open( $this->prefix ); $service = DataLifecycleProofConfiguration::service();
		$first = $service->save( DataLifecycleProofConfiguration::command( '5', 'buried-c06-original', 0 ) ); self::assertTrue( $first->success );
		for ( $i = 0; $i < 101; ++$i ) {
			$other = new \CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationWriteCommand( \CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product, 200 + $i, '', null, DataLifecycleProofConfiguration::command( (string) ( 6 + $i ), 'pressure-' . $i )->raw_fields, true, 0, 'pressure-' . $i );
			self::assertTrue( $service->save( $other )->success );
		}
		$before = $this->domain_bytes(); $audit_count = $this->count_table( 'audit_log' ); self::assertSame( 102, $audit_count ); $this->seed( 1 ); $this->complete();
		$process = new OperationProofProcess( [ __DIR__ . '/configuration-worker.php', json_encode( [ 'prefix' => $this->prefix, 'priority' => '5', 'token' => 'buried-c06-original', 'revision' => 0 ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $process; $replay = $process->finish();
		self::assertTrue( $replay['success'] ); self::assertTrue( $replay['replayed'] ); self::assertSame( $audit_count, $this->count_table( 'audit_log' ) ); self::assertSame( $before, $this->domain_bytes() );
	}
	public function test_actual_c03_acceptance_and_c04_scheduled_original_evidence_survive_cleanup(): void {
		$op_factory = new DataLifecycleProofFactory( $this->prefix ); $this->extra_factories[] = $op_factory; $profile = new DataLifecycleProofOperationProfile( $this->prefix );
		$op = new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $op_factory ); $identity = new OperationIdentity( 1, 'wordpress', 'staff:9', $profile->operation(), 1, 'counter:1', 'preserved-original' );
		$accepted = $op->attempt( $identity, [ 'row_id' => 1, 'expected_revision' => 1, 'value' => 7 ], RequestContext::create() ); self::assertSame( 'accepted', $accepted->outcome->state );
		$family = new RuleProofFamily(); $rule_factory = new DataLifecycleProofFactory( $this->prefix, clock: self::NOW ); $this->extra_factories[] = $rule_factory; $rules = new RuleLifecycleService( new RuleFamilyRegistry( [ $family ] ), $rule_factory );
		$p = RuleProofEnvelope::create( RuleProofEnvelope::uuid( 1 ), RuleProofEnvelope::uuid( 2 ) );
		self::assertSame( 'accepted', $rules->attempt( RuleProofEnvelope::identity( $p, 'rule.draft.create' ), $p, RequestContext::create() )->outcome->state );
		$head = RuleProofEnvelope::opened( $this->database, $this->prefix, $p['logical_uuid'], $p['version_uuid'] ); self::assertSame( 'accepted', $rules->attempt( RuleProofEnvelope::identity( $head, 'rule.publish', 'published-head' ), $head, RequestContext::create() )->outcome->state );
		$head = RuleProofEnvelope::opened( $this->database, $this->prefix, $p['logical_uuid'], $p['version_uuid'] );
		$next = RuleProofEnvelope::create( $p['logical_uuid'], RuleProofEnvelope::uuid( 3 ) ); $next['start_mode'] = 'at'; $next['effective_from'] = gmdate( 'Y-m-d H:i:s', self::NOW + 60 ) . '.000000'; $next['predecessor_uuid'] = $p['version_uuid']; $next['preconditions'] = $head['preconditions']; $next['preconditions']['predecessor_id'] = $head['preconditions']['version_id']; $next['preconditions']['predecessor_revision'] = $head['preconditions']['version_revision']; $next['preconditions']['version_id'] = 0; $next['preconditions']['version_revision'] = 0;
		self::assertSame( 'accepted', $rules->attempt( RuleProofEnvelope::identity( $next, 'rule.draft.create', 'successor' ), $next, RequestContext::create() )->outcome->state );
		$opened = RuleProofEnvelope::opened( $this->database, $this->prefix, $next['logical_uuid'], $next['version_uuid'] ); self::assertSame( 'accepted', $rules->attempt( RuleProofEnvelope::identity( $opened, 'rule.schedule', 'schedule' ), $opened, RequestContext::create() )->outcome->state );
		$scheduled = RuleProofEnvelope::version( $this->database, $this->prefix, $next['version_uuid'] ); self::assertSame( 2, (int) $scheduled['scheduled_revision'] ); self::assertNotNull( $scheduled['scheduled_predecessor_row_revision'] );

		$before = $this->domain_bytes(); $this->seed( 1 ); $this->complete(); self::assertSame( $before, $this->domain_bytes() );
		$fresh = new OperationProofProcess( [ __DIR__ . '/operation-worker.php', json_encode( [ 'prefix' => $this->prefix, 'token' => 'preserved-original', 'revision' => 1, 'value' => 7 ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $fresh; $replay = $fresh->finish(); self::assertSame( 'accepted', $replay['state'] ); self::assertTrue( $replay['replayed'] ); self::assertSame( $accepted->completion->result, $replay['result'] ); self::assertSame( $before, $this->domain_bytes() );
		$guard_row = DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_rule_family_guards`" ); $logical_row = RuleProofEnvelope::logical( $this->database, $this->prefix, $next['logical_uuid'] );
		$guard = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyGuard::from_row( $guard_row, $family ); $logical = \CetechDeliveryEngine\Domain\RuleLifecycle\LogicalRule::from_row( $logical_row, $guard, $family ); $version = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion::from_row( $scheduled, $logical, $family ); $prior = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion::from_row( RuleProofEnvelope::version( $this->database, $this->prefix, $p['version_uuid'] ), $logical, $family );
		$activation_identity = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand::activation_identity( 1, $family, $logical, $version ); $original = \CetechDeliveryEngine\Domain\RuleLifecycle\RuleLifecycleCommand::activation_payload( $family, $logical, $version, $prior ); $rule_factory->clock = self::NOW + 70;
		$activation = ( new RuleLifecycleService( new RuleFamilyRegistry( [ $family ] ), $rule_factory ) )->attempt( $activation_identity, $original, RequestContext::create() ); self::assertSame( 'accepted', $activation->outcome->state ); self::assertTrue( $activation->completion->result['late'] ); self::assertSame( $scheduled['effective_from'], RuleProofEnvelope::version( $this->database, $this->prefix, $next['version_uuid'] )['effective_from'] ); self::assertSame( 'retired', RuleProofEnvelope::version( $this->database, $this->prefix, $p['version_uuid'] )['state'] );

	}
	public function test_preview_is_read_only_and_bounded_with_an_explicit_incomplete_continuation(): void {
		for ( $i = 0; $i < 205; ++$i ) { $this->seed( $i ); } $before = DB::options( $this->database, $this->prefix ); $domains = $this->domain_bytes();
		$preview = $this->service->preview( 1 ); self::assertSame( 'preview', $preview->status ); self::assertSame( 200, $preview->eligible_candidates ); self::assertSame( 'running', $preview->progress->status ); self::assertSame( $before, DB::options( $this->database, $this->prefix ) ); self::assertSame( $domains, $this->domain_bytes() );
		self::assertNull( $this->control() ); self::assertNotNull( $preview->continuation );
	}
	public function test_original_cutoff_and_ceiling_exclude_a_later_expired_matching_identity(): void {
		$this->seed( 1 ); $start = $this->start(); $later = $this->seed( 2 ); self::assertGreaterThan( $start->progress->ceiling_id, $later ); $this->factory->clock = self::NOW + 300;
		$batch = $this->service->batch( 1, $start->continuation ); self::assertSame( 'accepted', $batch->status ); self::assertSame( self::NOW, $batch->progress->cutoff_utc ); self::assertSame( $start->progress->ceiling_id, $batch->progress->ceiling_id ); self::assertSame( 1, $batch->progress->deleted ); self::assertNotNull( $this->option_id( $later ) );
	}
	public function test_refused_start_publishes_no_run_and_retry_retains_original_cutoff_and_ceiling(): void {
		$this->seed( 1 ); $this->factory->configure = static function ( DataLifecycleProofTransport $t ): void { $t->reject_checkpoint = true; }; $refused = $this->service->start( 1 );
		self::assertSame( 'refused', $refused->status ); self::assertNull( $this->control() ); self::assertNotNull( $refused->continuation ); $later = $this->seed( 2 ); $this->factory->clock = self::NOW + 500; $this->factory->configure = null;
		$resumed = $this->service->resume( 1, $refused->continuation ); self::assertSame( 'accepted', $resumed->status ); self::assertSame( self::NOW, $resumed->progress->cutoff_utc ); self::assertLessThan( $later, $resumed->progress->ceiling_id );
	}
	public function test_empty_eligible_candidates_do_not_complete_before_sparse_ceiling_is_exhausted(): void {
		$this->seed( 1, self::NOW ); DB::insert_option( $this->database, $this->prefix, 'foreign-highwater', 'keep' ); DB::execute( $this->database, "UPDATE `{$this->prefix}options` SET option_id=5000 WHERE option_name='foreign-highwater'" );
		$start = $this->start(); $first = $this->service->batch( 1, $start->continuation ); self::assertSame( 'accepted', $first->status ); self::assertSame( 1000, $first->progress->cursor_id ); self::assertSame( 'running', $first->progress->status ); self::assertSame( 0, $first->progress->deleted );
		$done = $this->complete( $first ); self::assertSame( 5000, $done->progress->cursor_id ); self::assertSame( 'completed', $done->progress->status ); self::assertSame( 1, $done->progress->renewed );
	}
	public function test_50_delete_limit_stops_at_last_inspected_candidate_and_resume_visits_the_rest(): void {
		for ( $i = 0; $i < 101; ++$i ) { $this->seed( $i ); } $start = $this->start(); $first = $this->service->batch( 1, $start->continuation );
		self::assertSame( 50, $first->progress->deleted ); self::assertSame( 50, $first->progress->inspected ); self::assertSame( 'running', $first->progress->status ); self::assertSame( 51, $this->cache_count() );
		$done = $this->complete( $first ); self::assertSame( 101, $done->progress->deleted ); self::assertSame( 101, $done->progress->inspected ); self::assertSame( 0, $this->cache_count() );
	}
	public function test_200_inspection_limit_and_malformed_rows_advance_without_skipping_overflow(): void {
		for ( $i = 0; $i < 205; ++$i ) { DB::insert_option( $this->database, $this->prefix, DataLifecycleManifest::CACHE_PREFIX . hash( 'sha256', 'invalid-' . $i ), '{"unknown":"PRIVATE_MALFORMED"}' ); } $start = $this->start();
		$first = $this->service->batch( 1, $start->continuation ); self::assertSame( 200, $first->progress->inspected ); self::assertSame( 200, $first->progress->invalid ); self::assertSame( 0, $first->progress->deleted ); self::assertSame( 'running', $first->progress->status );
		$done = $this->complete( $first ); self::assertSame( 205, $done->progress->inspected ); self::assertSame( 205, $this->cache_count() );
	}
	public function test_a_second_connection_renews_after_enumeration_before_effect_lock(): void {
		$id = $this->seed( 1 ); $start = $this->start(); $new = $this->envelope( 1, self::NOW )->to_json(); $other = DB::connect(); $observed = false;
		$this->factory->configure = function ( DataLifecycleProofTransport $t ) use ( $id, $new, $other, &$observed ): void { $t->before = function ( string $sql ) use ( $id, $new, $other, &$observed ): void { if ( ! $observed && str_contains( $sql, 'FOR UPDATE' ) && preg_match( '/option_id\s*=\s*' . $id . '\b/', $sql ) ) { $observed = true; $s = $other->prepare( "UPDATE `{$this->prefix}options` SET option_value=? WHERE option_id=?" ); $s->bind_param( 'si', $new, $id ); self::assertTrue( $s->execute() ); } }; };
		$batch = $this->service->batch( 1, $start->continuation ); $other->close(); self::assertTrue( $observed ); self::assertSame( 'accepted', $batch->status ); self::assertSame( 0, $batch->progress->deleted ); self::assertSame( 1, $batch->progress->renewed ); self::assertSame( $new, $this->option_id( $id )['option_value'] );
	}
	public function test_disappeared_candidate_after_enumeration_advances_only_with_checkpoint(): void {
		$id = $this->seed( 1 ); $start = $this->start(); $other = DB::connect(); $disappeared = false;
		$this->factory->configure = function ( DataLifecycleProofTransport $t ) use ( $id, $other, &$disappeared ): void { $t->before = function ( string $sql ) use ( $id, $other, &$disappeared ): void { if ( ! $disappeared && str_contains( $sql, 'FOR UPDATE' ) && preg_match( '/option_id\s*=\s*' . $id . '\b/', $sql ) ) { DB::execute( $other, "DELETE FROM `{$this->prefix}options` WHERE option_id={$id}" ); $disappeared = true; } }; };
		$batch = $this->service->batch( 1, $start->continuation ); $other->close(); self::assertTrue( $disappeared ); self::assertSame( 'accepted', $batch->status ); self::assertSame( 1, $batch->progress->disappeared ); self::assertSame( 0, $batch->progress->deleted ); self::assertSame( $start->progress->ceiling_id, $batch->progress->cursor_id );
	}
	public function test_second_connection_recreates_same_key_above_original_ceiling_before_batch(): void {
		$id = $this->seed( 1 ); $start = $this->start(); $other = DB::connect(); DB::execute( $other, "DELETE FROM `{$this->prefix}options` WHERE option_id={$id}" ); $new_id = DB::insert_option( $other, $this->prefix, $this->identity( 1 )->option_name(), $this->envelope( 1 )->to_json() ); $other->close();
		$before = $this->option_id( $new_id ); $batch = $this->service->batch( 1, $start->continuation ); self::assertGreaterThan( $start->progress->ceiling_id, $new_id ); self::assertSame( 'accepted', $batch->status ); self::assertSame( 0, $batch->progress->deleted ); self::assertSame( $before, $this->option_id( $new_id ) ); self::assertSame( $start->progress->ceiling_id, $batch->progress->cursor_id );
	}

	public function test_two_actual_worker_processes_serialize_and_stale_original_action_cannot_repeat_deletion(): void {
		$this->seed( 1 ); $start = $this->start(); $b = $this->barrier(); $first = $this->worker( 'batch', $start->continuation, [ 'phase' => 'before_delete', 'ready' => $b . '/held', 'release' => $b . '/release' ] ); OperationProofProcess::wait_for( $b . '/held' );
		$second = $this->worker( 'batch', $start->continuation, [ 'start_ready' => $b . '/second-ready', 'start_release' => $b . '/second-release', 'coordinator_dispatch_ready' => $b . '/dispatch', 'coordinator_response_ready' => $b . '/response' ] ); OperationProofProcess::wait_for( $b . '/second-ready' ); OperationProofBarrier::signal( $b . '/second-release' );
		OperationProofProcess::wait_for( $b . '/dispatch' ); $waiting = false; $deadline = microtime( true ) + 1.0;
		do { $rows = $this->database->query( 'SHOW FULL PROCESSLIST' )->fetch_all( MYSQLI_ASSOC ); foreach ( $rows as $row ) { $sql = $row['Info'] ?? ''; if ( is_string( $sql ) && str_contains( $sql, $this->prefix . 'options' ) && str_contains( $sql, 'FOR UPDATE' ) && str_contains( $sql, DataLifecycleManifest::COORDINATOR_OPTION ) ) { $waiting = true; } } if ( ! $waiting ) { usleep( 10000 ); } } while ( ! $waiting && microtime( true ) < $deadline );
		self::assertTrue( $waiting, 'The second native worker must reach the actual locked coordinator SELECT.' ); clearstatcache( true, $b . '/response' ); self::assertFileDoesNotExist( $b . '/response', 'The SQL call cannot return while the first worker holds its coordinator lock.' ); OperationProofBarrier::signal( $b . '/release' ); $a = $first->finish(); $c = $second->finish();
		self::assertSame( 'accepted', $a['status'] ); self::assertSame( 'refused', $c['status'] ); self::assertSame( 1, $a['progress']['deleted'] ); self::assertSame( 0, $c['deletes'] ); self::assertSame( 2, json_decode( $this->control()['option_value'], true )['revision'] ); self::assertSame( 0, $this->cache_count() );
	}
	#[DataProvider( 'write_refusals' )]
	public function test_refused_delete_or_checkpoint_rolls_back_effects_and_progress_together( string $fault ): void {
		$this->seed( 1 ); $start = $this->start(); $before = $this->control()['option_value']; $this->factory->configure = static function ( DataLifecycleProofTransport $t ) use ( $fault ): void { $t->{$fault} = true; };
		$result = $this->service->batch( 1, $start->continuation ); self::assertSame( 'refused', $result->status ); self::assertSame( 1, $this->cache_count() ); self::assertSame( $before, $this->control()['option_value'] );
		$this->factory->configure = null; $retry = $this->service()->batch( 1, $start->continuation ); self::assertSame( 'accepted', $retry->status ); self::assertSame( 1, $retry->progress->deleted ); self::assertSame( $start->progress->run_manifest_hash, $retry->progress->run_manifest_hash );
	}
	public static function write_refusals(): array { return [ [ 'reject_delete' ], [ 'reject_checkpoint' ] ]; }
	public function test_proved_unsent_commit_rolls_back_and_original_checkpoint_can_retry(): void {
		$this->seed( 1 ); $start = $this->start(); $before = $this->control()['option_value']; $this->factory->configure = static function ( DataLifecycleProofTransport $t ): void { $t->commit_fault = 'unsent'; };
		$result = $this->service->batch( 1, $start->continuation ); self::assertSame( 'refused', $result->status ); self::assertSame( 1, $this->cache_count() ); self::assertSame( $before, $this->control()['option_value'] ); self::assertSame( 0, end( $this->factory->transports )->sent_commits );
		$this->factory->configure = null; self::assertSame( 1, $this->service()->batch( 1, $start->continuation )->progress->deleted );
	}
	public function test_actual_committed_acknowledgement_loss_is_confirmed_in_a_fresh_process_without_second_deletion(): void {
		$this->seed( 1 ); $start = $this->start(); $this->factory->configure = static function ( DataLifecycleProofTransport $t ): void { $t->commit_fault = 'lost_ack'; };
		$result = $this->service->batch( 1, $start->continuation ); self::assertSame( 'outcome_unknown', $result->status ); self::assertNull( $result->progress ); self::assertSame( 0, $this->cache_count() ); self::assertSame( 1, end( $this->factory->transports )->sent_commits );
		$bytes = $this->control()['option_value']; $reconciled = $this->worker( 'reconcile', $result->continuation )->finish(); self::assertSame( 'accepted', $reconciled['status'] ); self::assertSame( 1, $reconciled['progress']['deleted'] ); self::assertSame( 0, $reconciled['deletes'] ); self::assertSame( $bytes, $this->control()['option_value'] );
	}
	#[DataProvider( 'kill_windows' )]
	public function test_actual_sigkill_windows_keep_delete_and_checkpoint_in_one_durable_unit( string $phase, bool $committed ): void {
		$this->seed( 1 ); $start = $this->start(); $before = $this->control()['option_value']; $b = $this->barrier(); $process = $this->worker( 'batch', $start->continuation, [ 'phase' => $phase, 'ready' => $b . '/ready', 'release' => $b . '/release' ] ); OperationProofProcess::wait_for( $b . '/ready' ); self::assertSame( 9, $process->kill_and_wait() );
		self::assertSame( $committed ? 0 : 1, $this->cache_count() ); $stored = json_decode( $this->control()['option_value'], true ); self::assertSame( $committed ? 2 : 1, $stored['revision'] ); self::assertSame( $committed ? 1 : 0, $stored['deleted'] );
		if ( ! $committed ) { self::assertSame( $before, $this->control()['option_value'] ); }
		$fresh = $this->worker( 'reconcile', $start->continuation )->finish(); self::assertSame( 'accepted', $fresh['status'] ); self::assertSame( 0, $fresh['deletes'] );
		if ( ! $committed ) { $next = DataLifecycleContinuation::from_private_array( $fresh['continuation'] ); $done = $this->worker( 'batch', $next )->finish(); self::assertSame( 'accepted', $done['status'] ); self::assertSame( 1, $done['progress']['deleted'] ); }
	}
	public static function kill_windows(): array { return [ [ 'before_delete', false ], [ 'after_delete', false ], [ 'before_checkpoint', false ], [ 'after_checkpoint', false ], [ 'before_commit', false ], [ 'after_commit', true ] ]; }
	public function test_invalid_coordinator_and_unsupported_engine_preserve_candidates(): void {
		$this->seed( 1 ); DB::insert_option( $this->database, $this->prefix, DataLifecycleManifest::COORDINATOR_OPTION, '{"unknown":"KEEP_PRIVATE"}' ); $bytes = $this->control()['option_value'];
		self::assertSame( 'refused', $this->service->start( 1 )->status ); self::assertSame( $bytes, $this->control()['option_value'] ); self::assertSame( 1, $this->cache_count() );
		DB::execute( $this->database, "ALTER TABLE `{$this->prefix}options` ENGINE=MyISAM" ); self::assertSame( 'refused', $this->service()->start( 1 )->status ); self::assertSame( 1, $this->cache_count() );
	}
	public function test_nonunique_option_identity_and_wrong_site_refuse_without_deletion(): void {
		$this->seed( 1 ); DB::execute( $this->database, "ALTER TABLE `{$this->prefix}options` DROP INDEX option_name" ); self::assertSame( 'refused', $this->service->start( 1 )->status ); self::assertSame( 1, $this->cache_count() ); self::assertSame( 'refused', $this->service->start( 2 )->status );
	}
	public function test_managed_cache_late_old_ticket_cannot_replace_current_newer_generation(): void {
		$cache = new ManagedGeographyCache( $this->factory, salt: static fn(): string => 'synthetic-proof-salt', cache_get: static fn(): bool => false, cache_set: static fn(): bool => true, cache_delete: static fn(): bool => true ); $id = $this->identity( 1 ); $old = $cache->lookup( $id ); $newer = $cache->lookup( $id ); self::assertTrue( $cache->publish( $newer, [ 'required' => true, 'visible' => true ] ) );
		$physical = $this->option_name( $id->option_name() ); self::assertFalse( $cache->publish( $old, [ 'required' => false, 'visible' => false ] ) ); self::assertSame( $physical, $this->option_name( $id->option_name() ) ); self::assertSame( [ 'required' => true, 'visible' => true ], $cache->lookup( $id )->payload() );
	}
	public function test_controlled_persistent_cache_cannot_serve_a_delayed_old_generation_or_extend_expiry(): void {
		$advisory = []; $publications = []; $cache = new ManagedGeographyCache( $this->factory, salt: static fn(): string => 'synthetic-proof-salt', cache_get: static function ( string $key ) use ( &$advisory ): mixed { return $advisory[$key] ?? false; }, cache_set: static function ( string $key, array $value, string $group, int $ttl ) use ( &$advisory, &$publications ): bool { $publications[] = [ $key, $value, $ttl ]; $advisory[$key] = $value; return true; }, cache_delete: static fn(): bool => true );
		$id = $this->identity( 1 ); $first = $cache->lookup( $id ); self::assertTrue( $cache->publish( $first, [ 'required' => false, 'visible' => false ] ) ); $old = $publications[0]; $fresh = $cache->lookup( $id ); $this->factory->clock = self::NOW + 10;
		self::assertTrue( $cache->publish( $fresh, [ 'required' => true, 'visible' => true ] ) ); self::assertSame( 110, $publications[1][2] ); $advisory[$old[0]] = $old[1]; self::assertSame( [ 'required' => true, 'visible' => true ], $cache->lookup( $id )->payload() );
		$this->factory->clock = self::NOW + 120; self::assertNull( $cache->lookup( $id )->payload() ); self::assertFalse( $cache->publish( $fresh, [ 'required' => false, 'visible' => false ] ) ); self::assertSame( self::NOW + 120, ManagedGeographyCacheEnvelope::from_json( $this->option_name( $id->option_name() )['option_value'] )->expires_at() );
	}
	public function test_post_commit_advisory_failure_keeps_sql_publication_and_avoids_second_write(): void {
		$cache = new ManagedGeographyCache( $this->factory, salt: static fn(): string => 'synthetic-proof-salt', cache_get: static fn(): bool => false, cache_set: static function (): never { throw new \RuntimeException( 'synthetic cache refusal' ); }, cache_delete: static fn(): bool => true ); $id = $this->identity( 1 ); $ticket = $cache->lookup( $id );
		self::assertTrue( $cache->publish( $ticket, [ 'required' => true, 'visible' => true ] ) ); self::assertSame( [ 'status' => 'publication_pending' ], $cache->diagnostics() ); $bytes = $this->option_name( $id->option_name() ); self::assertFalse( $cache->publish( $ticket, [ 'required' => false, 'visible' => false ] ) ); self::assertSame( $bytes, $this->option_name( $id->option_name() ) );
	}
	public function test_unverified_retirement_does_not_grant_uncertain_owner_an_effect_retry(): void {
		$this->seed( 1 ); $start = $this->start(); $this->factory->configure = static function ( DataLifecycleProofTransport $t ): void { $t->commit_fault = 'lost_ack'; $t->reject_close = true; };
		$unknown = $this->service->batch( 1, $start->continuation ); self::assertSame( 'outcome_unknown', $unknown->status ); self::assertFalse( $unknown->continuation->retirement_confirmed ); self::assertSame( 0, $this->cache_count() ); $stored = $this->control()['option_value']; $calls = count( $this->factory->transports );
		self::assertSame( 'outcome_unknown', $this->service->batch( 1, $unknown->continuation )->status ); self::assertSame( $calls, count( $this->factory->transports ) ); self::assertSame( $stored, $this->control()['option_value'] ); $this->factory->close_all();
		$reconciled = $this->worker( 'reconcile', $unknown->continuation )->finish(); self::assertSame( 'accepted', $reconciled['status'] ); self::assertSame( 0, $reconciled['deletes'] ); self::assertSame( $stored, $this->control()['option_value'] );
	}

	public function test_unknown_oversized_foreign_site_and_similar_names_are_preserved_exactly(): void {
		$valid = $this->seed( 1 ); $other = ManagedGeographyCacheIdentity::create( 2, 'postcode', 'GB', 'other-site', '', '', 1, 'opaque:revision-v1', 'en_GB', 'synthetic-proof-salt' );
		$other_bytes = ManagedGeographyCacheEnvelope::create( $other, [ 'required' => true, 'visible' => true ], self::NOW - 1000 )->to_json(); DB::insert_option( $this->database, $this->prefix, $other->option_name(), $other_bytes );
		$unknown = json_decode( $this->envelope( 2 )->to_json(), true ); $unknown['format'] = 2; DB::insert_option( $this->database, $this->prefix, $this->identity( 2 )->option_name(), json_encode( $unknown, JSON_THROW_ON_ERROR ) ); DB::insert_option( $this->database, $this->prefix, $this->identity( 3 )->option_name(), str_repeat( 'PRIVATE_OVERSIZED_', 5000 ) );
		DB::insert_option( $this->database, $this->prefix, 'cetechXdeXgcXgeoXv1X' . hash( 'sha256', 'similar' ), 'FOREIGN_KEEP', 'on' ); DB::insert_option( $this->database, $this->prefix, strtoupper( $this->identity( 4 )->option_name() ), $this->envelope( 4 )->to_json() );
		$before = array_values( array_filter( DB::options( $this->database, $this->prefix ), static fn( array $r ): bool => (int) $r['option_id'] !== $valid ) ); $done = $this->complete(); self::assertSame( 1, $done->progress->deleted ); self::assertGreaterThanOrEqual( 3, $done->progress->invalid );
		$after = array_values( array_filter( DB::options( $this->database, $this->prefix ), static fn( array $r ): bool => DataLifecycleManifest::COORDINATOR_OPTION !== $r['option_name'] ) ); self::assertSame( $before, $after );
	}

	private function service(): DataLifecycleCleanupService { return new DataLifecycleCleanupService( DataLifecycleRegistry::standard(), $this->factory, static fn( int $site ): bool => 1 === $site, trusted_equivalent_route: true ); }
	private function identity( int $id, int $site = 1 ): ManagedGeographyCacheIdentity { return ManagedGeographyCacheIdentity::create( $site, 'postcode', 'GB', 'synthetic-parent-' . $id, '', '', 1, 'opaque:revision-v1', 'en_GB', 'synthetic-proof-salt' ); }
	private function envelope( int $id, ?int $observed = null ): ManagedGeographyCacheEnvelope { return ManagedGeographyCacheEnvelope::create( $this->identity( $id ), [ 'required' => true, 'visible' => true ], $observed ?? self::NOW - 1000 ); }
	private function seed( int $id, ?int $observed = null ): int { return DB::insert_option( $this->database, $this->prefix, $this->identity( $id )->option_name(), $this->envelope( $id, $observed )->to_json() ); }
	private function start(): \CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleResult { $r = $this->service->start( 1 ); self::assertSame( 'accepted', $r->status, $r->error?->code ?? '' ); return $r; }
	private function complete( ?\CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleResult $current = null ): \CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleResult {
		$r = $current ?? $this->start(); for ( $i = 0; $i < 25 && 'completed' !== $r->progress->status; ++$i ) { $r = $this->service->batch( 1, $r->continuation ); self::assertSame( 'accepted', $r->status, $r->error?->code ?? '' ); } self::assertSame( 'completed', $r->progress->status ); return $r;
	}
	private function control(): ?array { return $this->option_name( DataLifecycleManifest::COORDINATOR_OPTION ); }
	private function option_name( string $name ): ?array { $name = $this->database->real_escape_string( $name ); return DB::row( $this->database, "SELECT * FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); }
	private function option_id( int|string $id ): ?array { return DB::row( $this->database, "SELECT * FROM `{$this->prefix}options` WHERE option_id=" . (int) $id ); }
	private function cache_count(): int { return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}options` WHERE LEFT(option_name,20)='cetech_de_gc_geo_v1_'" ); }
	private function count_table( string $suffix ): int { return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_{$suffix}`" ); }
	private function domain_tables(): array { $p = $this->database->real_escape_string( $this->prefix . 'delivery_engine_' ); $r = $this->database->query( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME," . strlen( $this->prefix . 'delivery_engine_' ) . ")='{$p}' ORDER BY TABLE_NAME" ); return array_column( $r->fetch_all( MYSQLI_ASSOC ), 'TABLE_NAME' ); }
	private function domain_bytes(): array { $rows = []; foreach ( $this->domain_tables() as $table ) { $result = $this->database->query( "SELECT * FROM `{$table}` ORDER BY id" ); $rows[$table] = $result->fetch_all( MYSQLI_ASSOC ); } return $rows; }
	private function without_controls(): array { return array_values( array_filter( DB::options( $this->database, $this->prefix ), static fn( array $r ): bool => DataLifecycleManifest::COORDINATOR_OPTION !== $r['option_name'] && ! str_starts_with( $r['option_name'], DataLifecycleManifest::CACHE_PREFIX ) ) ); }
	private function seed_sentinel( string $table ): void {
		$fields = $this->database->query( "SHOW COLUMNS FROM `{$table}`" )->fetch_all( MYSQLI_ASSOC ); $columns = []; $values = [];
		foreach ( $fields as $f ) { if ( str_contains( $f['Extra'], 'auto_increment' ) || 'id' === $f['Field'] ) { continue; } $columns[] = '`' . $f['Field'] . '`'; $type = strtolower( $f['Type'] ); if ( 'YES' === $f['Null'] ) { $values[] = 'NULL'; } elseif ( preg_match( '/int|decimal|double|float/', $type ) ) { $values[] = '1'; } elseif ( str_starts_with( $type, 'datetime' ) || str_starts_with( $type, 'timestamp' ) ) { $values[] = "'2026-10-06 00:00:00'"; } else { $values[] = preg_match( '/(?:var)?char\(([0-9]+)\)/', $type, $m ) && (int) $m[1] < 32 ? "'P'" : "'PRIVATE_PRESERVATION_SENTINEL'"; } }
		DB::execute( $this->database, "INSERT INTO `{$table}` (" . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')' );
	}
	private function barrier(): string { $b = OperationProofBarrier::directory(); $this->barriers[] = $b; return $b; }
	private function worker( string $action, DataLifecycleContinuation $continuation, array $extra = [] ): OperationProofProcess { $p = new OperationProofProcess( [ __DIR__ . '/process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'clock' => $this->factory->clock, 'action' => $action, 'continuation' => $continuation->to_private_array() ] + $extra, JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $p; return $p; }
}
