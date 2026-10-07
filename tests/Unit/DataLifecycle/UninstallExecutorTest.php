<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DataLifecycle;

use CetechDeliveryEngine\Bootstrap\DataLifecycleBootstrap;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;
use CetechDeliveryEngine\Bootstrap\DataLifecycleUninstallExecutor;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleProgress;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleRegistry;
use CetechDeliveryEngine\Domain\DataLifecycle\DataLifecycleContinuation;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\UninstallExecutorFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DataLifecycle/UninstallExecutorFixture.php';

final class UninstallExecutorTest extends TestCase {
	private array $globals = [];
	private const GLOBAL_KEYS = [ 'blog_id', 'wpdb', 'cetech_de_test_options', 'cetech_de_test_transients', 'cetech_de_test_cache_deletes', 'cetech_de_test_wp_roles', 'cetech_de_test_roles', 'cetech_de_test_filters' ];
	protected function setUp(): void {
		foreach ( self::GLOBAL_KEYS as $key ) { $this->globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
	}
	protected function tearDown(): void {
		foreach ( $this->globals as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
	}

	#[DataProvider( 'unsupported_intent' )]
	public function test_loose_or_absent_intent_cannot_open_storage_or_remove_anything( mixed $intent ): void {
		$f = new UninstallExecutorFixture(); $GLOBALS['cetech_de_test_options'][DataLifecycleManifest::UNINSTALL_INTENT] = $intent;
		$before = $f->rows; $result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( 'not_requested', $result['code'] ); self::assertSame( $before, $f->rows );
		self::assertSame( 0, $f->opens ); self::assertSame( 0, $f->capability_calls ); self::assertFalse( $result['intent_cleared'] );
	}
	public static function unsupported_intent(): array { return [ [ null ], [ true ], [ false ], [ 1.0 ], [ 0 ], [ '01' ], [ ' 1' ], [ '1 ' ], [ '1delete' ], [ [ 1 ] ], [ new \stdClass() ] ]; }

	public function test_cached_intent_does_not_override_locked_physical_intent(): void {
		$f = new UninstallExecutorFixture(); $f->put( DataLifecycleManifest::UNINSTALL_INTENT, '0' ); $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( 'not_requested', $result['code'] ); self::assertSame( $before, $f->rows ); self::assertSame( 0, $f->writes );
		self::assertSame( 0, $f->capability_calls ); self::assertSame( [], $GLOBALS['cetech_de_test_cache_deletes'] );
	}

	public function test_missing_native_owner_reports_unknown_and_preserves_every_existing_row(): void {
		$f = new UninstallExecutorFixture(); $f->available = false; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'outcome_unknown', 'status_unconfirmed', false, false ], [ $result['status'], $result['code'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( $before, $f->rows ); self::assertSame( 0, $f->writes );
		self::assertStringNotContainsString( 'PRIVATE', json_encode( $result, JSON_THROW_ON_ERROR ) );
	}

	public function test_unsupported_options_participant_preserves_data_and_intent(): void {
		$f = new UninstallExecutorFixture(); $f->ready = false; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( 'outcome_unknown', $result['status'] ); self::assertSame( $before, $f->rows );
		self::assertSame( 0, $f->writes ); self::assertSame( 0, $f->capability_calls );
	}

	public function test_mismatched_wordpress_route_cannot_be_used_as_an_uninstall_owner(): void {
		$f = new UninstallExecutorFixture(); $GLOBALS['wpdb']->options = 'foreign_options'; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'outcome_unknown', 'status_unconfirmed' ], [ $result['status'], $result['code'] ] );
		self::assertSame( $before, $f->rows ); self::assertSame( 0, $f->writes ); self::assertSame( 0, $f->capability_calls );
		self::assertSame( [], $f->statements );
	}

	public function test_active_ordinary_cleanup_is_not_replaced_by_an_uninstall_pass(): void {
		$f = new UninstallExecutorFixture(); $p = $f->checkpoint( 'expired' ); $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'incomplete', 'active_cleanup', false ], [ $result['status'], $result['code'], $result['intent_cleared'] ] );
		self::assertSame( $before[DataLifecycleManifest::COORDINATOR_OPTION], $f->rows[DataLifecycleManifest::COORDINATOR_OPTION] );
		self::assertSame( $p->counts(), $result['counts'] ); self::assertSame( 0, $f->batches ); self::assertSame( 0, $f->capability_calls );
	}

	public function test_checkpoint_from_another_inventory_is_preserved_without_policy_takeover(): void {
		$f = new UninstallExecutorFixture();
		$other_policy = hash( 'sha256', 'fixture-original32-inventory' );
		self::assertNotSame( $other_policy, DataLifecycleRegistry::standard()->policy_digest() );
		$checkpoint = DataLifecycleProgress::initial( 1, $other_policy, 'uninstall_cache', UninstallExecutorFixture::NOW, 5000 );
		$f->put( DataLifecycleManifest::COORDINATOR_OPTION, $checkpoint->to_json() );
		$before = $f->rows;
		$refused_batch = $f->cleanup()->batch( 1, DataLifecycleContinuation::checkpoint( $checkpoint ) );
		self::assertSame( [ 'refused', 'policy_changed' ], [ $refused_batch->status, $refused_batch->reason ] );
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		// The existing read path retires before checking its policy and reports
		// an unknown outcome. Q02 retains that disposition and all saved bytes.
		self::assertSame( [ 'outcome_unknown', 'outcome_unknown', false, false ], [ $result['status'], $result['code'], $result['permissions_removed'], $result['intent_cleared'] ] );
		foreach ( [ DataLifecycleManifest::COORDINATOR_OPTION, DataLifecycleManifest::UNINSTALL_INTENT, DataLifecycleManifest::CAPABILITIES_MARKER, 'proof_user_roles', 'cetech_de_sitewide_defaults' ] as $name ) {
			self::assertSame( $before[$name], $f->rows[$name] );
		}
		self::assertSame( 0, $f->batches ); self::assertSame( 0, $f->role_writes ); self::assertSame( 0, $f->capability_calls );
	}

	public function test_uninstall_runs_only_one_bounded_batch_before_returning_incomplete(): void {
		$f = new UninstallExecutorFixture(); $f->put( 'foreign_high_id', 'KEEP', 'on', 5000 ); $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'incomplete', 'batch_incomplete', false, false ], [ $result['status'], $result['code'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( 1, $f->batches ); self::assertSame( 0, $f->capability_calls );
		self::assertSame( $before['foreign_high_id'], $f->rows['foreign_high_id'] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}

	public function test_unknown_cleanup_read_does_not_remove_permissions_marker_or_intent(): void {
		$f = new UninstallExecutorFixture(); $f->checkpoint( 'uninstall_cache' ); $f->unknown_worker_read = true; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'outcome_unknown', 'outcome_unknown' ], [ $result['status'], $result['code'] ] );
		self::assertSame( 0, $f->capability_calls ); self::assertSame( 0, $f->batches );
		self::assertSame( $before[DataLifecycleManifest::COORDINATOR_OPTION], $f->rows[DataLifecycleManifest::COORDINATOR_OPTION] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}

	public function test_invalid_cache_retained_after_completed_scan_keeps_uninstall_incomplete(): void {
		$f = new UninstallExecutorFixture(); $name = $f->cache( 20, true ); $before = $f->rows[$name];
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'incomplete', 'retained_unknown_cache' ], [ $result['status'], $result['code'] ] );
		self::assertSame( $before, $f->rows[$name] ); self::assertSame( 1, $result['counts']['invalid'] );
		self::assertSame( 0, $f->capability_calls ); self::assertArrayHasKey( DataLifecycleManifest::CAPABILITIES_MARKER, $f->rows );
		self::assertArrayHasKey( DataLifecycleManifest::UNINSTALL_INTENT, $f->rows );
	}

	public function test_cache_publication_refusal_keeps_confirmed_sql_counts_and_saved_intent(): void {
		$f = new UninstallExecutorFixture(); $name = $f->cache( 20 ); $f->advisory_publication = false;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'incomplete', 'cache_publication_pending' ], [ $result['status'], $result['code'] ] );
		self::assertSame( 1, $result['counts']['deleted'] ); self::assertArrayNotHasKey( $name, $f->rows );
		self::assertSame( 0, $f->capability_calls ); self::assertArrayHasKey( DataLifecycleManifest::UNINSTALL_INTENT, $f->rows );
	}

	public function test_rejected_guarded_role_publication_cannot_consume_marker_or_intent(): void {
		$f = new UninstallExecutorFixture(); $f->permission_publication = false; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'refused', false, false ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( 1, $f->role_writes ); self::assertSame( 0, $f->capability_calls );
		self::assertSame( $before['proof_user_roles'], $f->rows['proof_user_roles'] );
		self::assertSame( $before[DataLifecycleManifest::CAPABILITIES_MARKER], $f->rows[DataLifecycleManifest::CAPABILITIES_MARKER] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}

	public function test_stale_role_cache_refuses_before_removing_any_capability(): void {
		$f = new UninstallExecutorFixture(); $f->roles->roles['custom_staff']['capabilities']['new_foreign_cap'] = true; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'refused', false, false ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( 0, $f->capability_calls ); self::assertSame( $before['proof_user_roles'], $f->rows['proof_user_roles'] );
		self::assertSame( $before[DataLifecycleManifest::CAPABILITIES_MARKER], $f->rows[DataLifecycleManifest::CAPABILITIES_MARKER] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}

	public function test_new_foreign_role_grant_between_snapshot_and_publication_is_preserved(): void {
		$f = new UninstallExecutorFixture(); $before = $f->rows;
		$f->after_role_snapshot = static function () use ( $f ): void {
			$current = unserialize( $f->rows['proof_user_roles']['option_value'], [ 'allowed_classes' => false ] );
			$current['custom_staff']['capabilities']['newer_foreign_grant'] = true;
			$f->put( 'proof_user_roles', serialize( $current ) );
		};
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		$current = unserialize( $f->rows['proof_user_roles']['option_value'], [ 'allowed_classes' => false ] );
		self::assertArrayHasKey( 'newer_foreign_grant', $current['custom_staff']['capabilities'] );
		self::assertSame( [ 'refused', false, false ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( $before[DataLifecycleManifest::CAPABILITIES_MARKER], $f->rows[DataLifecycleManifest::CAPABILITIES_MARKER] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
		self::assertSame( 0, $f->role_writes ); self::assertSame( 0, $f->capability_calls );
	}

	#[DataProvider( 'native_role_filters' )]
	public function test_native_role_publication_filter_can_refuse_without_broadening_the_disposition( string $hook ): void {
		$f = new UninstallExecutorFixture(); $before = $f->rows;
		$GLOBALS['cetech_de_test_filters'] = [ $hook => [ static fn( mixed $value ): array => $f->before_roles ] ];
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'refused', false, false ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( 0, $f->role_writes ); self::assertSame( 0, $f->capability_calls );
		self::assertSame( $before['proof_user_roles'], $f->rows['proof_user_roles'] );
		self::assertSame( $before[DataLifecycleManifest::CAPABILITIES_MARKER], $f->rows[DataLifecycleManifest::CAPABILITIES_MARKER] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}
	public static function native_role_filters(): array { return [ [ 'pre_update_option_proof_user_roles' ], [ 'pre_update_option' ] ]; }

	#[DataProvider( 'unaccepted_role_commit' )]
	public function test_unaccepted_role_commit_keeps_marker_and_uninstall_intent( OperationCommitResult $commit, string $status ): void {
		$f = new UninstallExecutorFixture(); $f->role_commit = $commit; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ $status, false, false ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( $before[DataLifecycleManifest::CAPABILITIES_MARKER], $f->rows[DataLifecycleManifest::CAPABILITIES_MARKER] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
		$current = unserialize( $f->rows['proof_user_roles']['option_value'], [ 'allowed_classes' => false ] );
		self::assertTrue( $current['custom_staff']['capabilities']['foreign_capability'] );
		self::assertSame( 1, $f->role_writes ); self::assertSame( 0, $f->role_refreshes ); self::assertSame( 0, $f->capability_calls );
		if ( OperationCommitResult::NotSent === $commit ) { self::assertSame( $before['proof_user_roles'], $f->rows['proof_user_roles'] ); }
	}
	public static function unaccepted_role_commit(): array { return [ [ OperationCommitResult::NotSent, 'refused' ], [ OperationCommitResult::Unconfirmed, 'outcome_unknown' ] ]; }

	public function test_missing_current_role_object_preserves_physical_permissions_and_saved_intent(): void {
		$f = new UninstallExecutorFixture(); unset( $GLOBALS['cetech_de_test_roles']['custom_staff'] ); $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'refused', false, false ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( 0, $f->capability_calls ); self::assertSame( $before['proof_user_roles'], $f->rows['proof_user_roles'] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}

	#[DataProvider( 'retained_derived_store' )]
	public function test_refused_notice_or_marker_removal_does_not_clear_saved_intent( string $retained ): void {
		$f = new UninstallExecutorFixture(); $f->retain = [ $retained ]; $before = $f->rows;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'refused', true, false ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( $before[$retained], $f->rows[$retained] );
		self::assertSame( $before[DataLifecycleManifest::UNINSTALL_INTENT], $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}
	public static function retained_derived_store(): array { return [ [ '_transient_' . DataLifecycleManifest::ACTIVATION_NOTICE ], [ DataLifecycleManifest::CAPABILITIES_MARKER ] ]; }

	#[DataProvider( 'unaccepted_status_commit' )]
	public function test_unconfirmed_pending_status_prevents_consuming_the_uninstall_intent( OperationCommitResult $commit ): void {
		$f = new UninstallExecutorFixture(); $f->status_commit = $commit;
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'outcome_unknown', 'status_unconfirmed', true, false ], [ $result['status'], $result['code'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertArrayHasKey( DataLifecycleManifest::UNINSTALL_INTENT, $f->rows );
		self::assertSame( 'intent_publication_pending', $f->status_values[0]['code'] );
		self::assertSame( [ 'format', 'site_id', 'status', 'code', 'counts', 'permissions_removed', 'intent_cleared' ], array_keys( $result ) );
	}
	public static function unaccepted_status_commit(): array { return [ 'not dispatched' => [ OperationCommitResult::NotSent ], 'acknowledgement lost' => [ OperationCommitResult::Unconfirmed ] ]; }

	public function test_saved_intent_is_only_reported_cleared_after_physical_removal(): void {
		$f = new UninstallExecutorFixture(); $f->retain = [ DataLifecycleManifest::UNINSTALL_INTENT ]; $before = $f->rows[DataLifecycleManifest::UNINSTALL_INTENT];
		$result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'refused', 'intent_removal_refused', false ], [ $result['status'], $result['code'], $result['intent_cleared'] ] );
		self::assertSame( $before, $f->rows[DataLifecycleManifest::UNINSTALL_INTENT] );
	}

	public function test_complete_unit_seam_preserves_authored_and_foreign_rows_and_role_caps(): void {
		$f = new UninstallExecutorFixture(); $f->rows['proof_user_roles']['autoload'] = 'on'; $before = $f->rows; $result = DataLifecycleUninstallExecutor::run( $f->cleanup(), $f );
		self::assertSame( [ 'completed', true, true ], [ $result['status'], $result['permissions_removed'], $result['intent_cleared'] ] );
		self::assertSame( $before['foreign_option'], $f->rows['foreign_option'] ); self::assertSame( $before['cetech_de_sitewide_defaults'], $f->rows['cetech_de_sitewide_defaults'] );
		self::assertSame( [ 'read' => true, 'foreign_capability' => true ], unserialize( $f->rows['proof_user_roles']['option_value'], [ 'allowed_classes' => false ] )['custom_staff']['capabilities'] );
		self::assertArrayNotHasKey( DataLifecycleManifest::CAPABILITIES_MARKER, $f->rows ); self::assertArrayNotHasKey( DataLifecycleManifest::UNINSTALL_INTENT, $f->rows );
		self::assertSame( [ 'intent_publication_pending', 'completed' ], array_column( $f->status_values, 'code' ) );
		self::assertSame( 'on', $f->rows['proof_user_roles']['autoload'] ); self::assertSame( 1, $f->role_writes ); self::assertSame( 1, $f->role_refreshes ); self::assertSame( 0, $f->capability_calls );
		foreach ( $f->statements as [ $sql ] ) {
			self::assertStringNotContainsString( 'DROP ', $sql ); self::assertStringNotContainsString( 'TRUNCATE ', $sql );
			// Unit transport allowlist only: physical quote preservation is a separate native/SQL proof.
			foreach ( DataLifecycleManifest::QUOTE_TABLE_SUFFIXES as $suffix ) { self::assertStringNotContainsString( $suffix, $sql ); }
		}
	}

	public function test_fixed_standalone_closure_loads_without_composer_woo_or_wordpress_runtime(): void {
		$root = dirname( __DIR__, 3 );
		$code = 'require ' . var_export( $root . '/src/Bootstrap/DataLifecycleBootstrap.php', true ) . '; '
			. '$b=\\CetechDeliveryEngine\\Bootstrap\\DataLifecycleBootstrap::class; echo json_encode([$b::load(),class_exists("WC_Order",false),function_exists("get_option"),class_exists("Composer\\\\Autoload\\\\ClassLoader",false),class_exists("CetechDeliveryEngine\\\\Bootstrap\\\\DataLifecycleUninstallExecutor",false),count(\\CetechDeliveryEngine\\Bootstrap\\DataLifecycleManifest::QUOTE_TABLE_SUFFIXES),class_exists("CetechDeliveryEngine\\\\Infrastructure\\\\Persistence\\\\DeliveryQuoteSchema",false)],JSON_THROW_ON_ERROR);';
		self::assertSame( [ true, false, false, false, true, 3, false ], $this->fresh_process( $code ) );
		self::assertSame( 0, ( new \ReflectionMethod( DataLifecycleBootstrap::class, 'load' ) )->getNumberOfParameters() );
		self::assertCount( count( array_unique( DataLifecycleBootstrap::FILES ) ), DataLifecycleBootstrap::FILES );
	}

	public function test_missing_standalone_helper_refuses_before_loading_any_dependency(): void {
		$root = sys_get_temp_dir() . '/cetech-c06-closure-' . bin2hex( random_bytes( 8 ) );
		mkdir( $root . '/src/Bootstrap', 0777, true );
		$source_root = dirname( __DIR__, 3 ) . '/src';
		copy( $source_root . '/Bootstrap/DataLifecycleBootstrap.php', $root . '/src/Bootstrap/DataLifecycleBootstrap.php' );
		copy( $source_root . '/Bootstrap/DataLifecycleManifest.php', $root . '/src/Bootstrap/DataLifecycleManifest.php' );
		try {
			$code = 'require ' . var_export( $root . '/src/Bootstrap/DataLifecycleBootstrap.php', true ) . '; echo json_encode([\\CetechDeliveryEngine\\Bootstrap\\DataLifecycleBootstrap::load(),class_exists("CetechDeliveryEngine\\\\Bootstrap\\\\DataLifecycleManifest",false)],JSON_THROW_ON_ERROR);';
			self::assertSame( [ false, false ], $this->fresh_process( $code ) );
		} finally {
			unlink( $root . '/src/Bootstrap/DataLifecycleBootstrap.php' ); unlink( $root . '/src/Bootstrap/DataLifecycleManifest.php' );
			rmdir( $root . '/src/Bootstrap' ); rmdir( $root . '/src' ); rmdir( $root );
		}
	}

	private function fresh_process( string $code ): array {
		$p = proc_open( [ PHP_BINARY, '-n', '-r', $code ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $p ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $p ), $err ); return json_decode( $out, true, 16, JSON_THROW_ON_ERROR );
	}
}
