<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Infrastructure\WordPress\ActionSchedulerReadiness;
use CetechDeliveryEngine\Tests\Support\ActionSchedulerUniqueStore;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #33 — geography pack liveness: successor scheduling, orphan recovery,
 * lease/token fences, and bounded deduplication.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013, DE-PERF-002.
 */
final class GeographyPackLivenessTest extends TestCase {

	private ActionSchedulerUniqueStore $store;

	private string $functions;

	protected function setUp(): void {
		parent::setUp();
		$this->functions = dirname( __DIR__, 2 ) . '/Support/action-scheduler-test-functions.php';
		require_once $this->functions;
		$GLOBALS['cetech_de_test_options']             = [];
		$GLOBALS['cetech_de_test_as_enqueue_attempts'] = 0;
		$GLOBALS['cetech_de_test_as_enqueue_invoked']  = 0;
		$GLOBALS['cetech_de_test_as_enqueue_blocked']  = 0;
		$this->store = ActionSchedulerUniqueStore::install();
	}

	protected function tearDown(): void {
		ActionSchedulerUniqueStore::uninstall();
		$GLOBALS['wp_actions']['action_scheduler_init'] = 0;
		unset(
			$GLOBALS['cetech_de_test_as_enqueue_attempts'],
			$GLOBALS['cetech_de_test_as_enqueue_invoked'],
			$GLOBALS['cetech_de_test_as_enqueue_blocked']
		);
		parent::tearDown();
	}

	public function test_readiness_source_does_not_treat_running_action_as_unique_successor(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/../src/Infrastructure/WordPress/ActionSchedulerReadiness.php' );
		self::assertStringContainsString( 'as_enqueue_async_action( $hook, $args, $group, false )', $source );
		self::assertStringNotContainsString( 'as_enqueue_async_action( $hook, $args, $group, true )', $source );
		$enqueue = $this->method_source( $source, 'enqueue_unique_async' );
		self::assertStringNotContainsString( 'as_unschedule_all_actions', $enqueue );
		self::assertStringContainsString( 'pending_count', $enqueue );
		$delayed = $this->method_source( $source, 'schedule_unique_delayed' );
		self::assertStringContainsString( 'as_schedule_single_action( $timestamp, $hook, $args, $group, false )', $delayed );
		self::assertStringNotContainsString( 'as_enqueue_async_action', $delayed );
		$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/../src/Bootstrap/Plugin.php' );
		self::assertStringContainsString( 'register_liveness()', $plugin );
		$packs = (string) file_get_contents( dirname( __DIR__, 2 ) . '/../src/Application/Geography/GeographyPackService.php' );
		self::assertStringContainsString( 'ensure_import_liveness', $packs );
		self::assertStringContainsString( 'schedule_unique_delayed', $packs );
		self::assertSame( 'cetech_de_geography_pack_liveness', GeographyPackService::LIVENESS_HOOK );
		self::assertSame( 60, GeographyPackService::LIVENESS_INTERVAL_SECONDS );
		self::assertMatchesRegularExpression( '/function schedule_liveness_check\(\): void \{[^}]*schedule_unique_delayed/s', $packs );
		self::assertDoesNotMatchRegularExpression( '/function schedule_liveness_check\(\): void \{[^}]*enqueue_unique_async/s', $packs );
	}

	public function test_running_action_does_not_suppress_its_own_successor(): void {
		$hook  = GeographyPackService::HOOK;
		$group = GeographyPackService::GROUP . '-1';
		$args  = [ 'pack_id' => 1, 'source_path' => '/tmp/gh.txt', 'generation_token' => 'tok' ];
		$running = $this->store->enqueue_async( $hook, $args, $group, false );
		$this->store->mark_running( $running );

		$ok = ActionSchedulerReadiness::enqueue_unique_async( $hook, $args, $group );
		self::assertTrue( $ok );
		$returned = $this->store->last_id();
		self::assertNotSame( $running, $returned );
		self::assertSame( 1, $this->store->count_by_status( $hook, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );

		$this->store->complete( $running );
		self::assertSame( 1, $this->store->count_by_status( $hook, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( 0, $this->store->count_by_status( $hook, $group, ActionSchedulerUniqueStore::STATUS_RUNNING ) );
	}

	public function test_legacy_unique_true_from_running_action_leaves_no_successor(): void {
		$hook  = GeographyPackService::HOOK;
		$group = GeographyPackService::GROUP . '-1';
		$args  = [ 'pack_id' => 1 ];
		$running = $this->store->enqueue_async( $hook, $args, $group, false );
		$this->store->mark_running( $running );

		as_unschedule_all_actions( $hook, null, $group );
		$returned = as_enqueue_async_action( $hook, $args, $group, true );
		self::assertSame( $running, $returned, 'Action Scheduler unique=true must return the in-progress ID.' );
		$this->store->complete( $running );
		self::assertSame( 0, $this->store->count_by_status( $hook, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
	}

	public function test_unique_true_ignores_argument_differences(): void {
		$hook  = GeographyPackService::HOOK;
		$group = GeographyPackService::GROUP . '-1';
		$running = $this->store->enqueue_async( $hook, [ 'pack_id' => 1, 'cursor' => '1' ], $group, false );
		$this->store->mark_running( $running );
		$returned = as_enqueue_async_action( $hook, [ 'pack_id' => 1, 'cursor' => '2' ], $group, true );
		self::assertSame( $running, $returned );
	}

	public function test_duplicate_scheduling_attempts_remain_bounded_to_one_pending(): void {
		$hook  = GeographyPackService::HOOK;
		$group = GeographyPackService::GROUP . '-9';
		self::assertTrue( ActionSchedulerReadiness::enqueue_unique_async( $hook, [ 'pack_id' => 9 ], $group ) );
		self::assertTrue( ActionSchedulerReadiness::enqueue_unique_async( $hook, [ 'pack_id' => 9 ], $group ) );
		self::assertTrue( ActionSchedulerReadiness::enqueue_unique_async( $hook, [ 'pack_id' => 9 ], $group ) );
		self::assertSame( 1, $this->store->count_by_status( $hook, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
	}

	public function test_importing_worker_creates_a_successor_that_survives_completion(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 8 );
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		$service->retry( $pack->id, $file );
		$id = $this->store->claim_next_pending( GeographyPackService::HOOK, $group );
		self::assertGreaterThan( 0, $id );
		$result = $service->tick( $pack->id, $file, 3, $pack->target_token() );
		self::assertSame( GeographyPackStatus::Importing->value, (string) ( $result['status'] ?? '' ) );
		$this->store->complete( $id );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_exactly_one_effective_continuation_exists_while_importing(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 8 );
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		$service->retry( $pack->id, $file );
		$id = $this->store->claim_next_pending( GeographyPackService::HOOK, $group );
		$service->tick( $pack->id, $file, 3, $pack->target_token() );
		$service->retry( $pack->id, $file );
		$service->ensure_import_liveness();
		$this->store->complete( $id );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_lease_contention_prevents_concurrent_import_mutation(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 4 );
		$owner = $packs->acquire_lease( $pack->id, 'tick', time(), GeographyPackService::LOCK_TTL_SECONDS );
		self::assertNotSame( '', $owner );
		$blocked = $service->tick( $pack->id, $file, 3, $pack->target_token() );
		self::assertSame( 'locked', (string) ( $blocked['reason'] ?? '' ) );
		$cursor = $packs->find_by_id( $pack->id )?->import_cursor;
		$again  = $service->tick( $pack->id, $file, 3, $pack->target_token() );
		self::assertSame( 'locked', (string) ( $again['reason'] ?? '' ) );
		self::assertSame( $cursor, $packs->find_by_id( $pack->id )?->import_cursor );
		unlink( $file );
	}

	public function test_expired_dead_lease_permits_safe_recovery(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 8 );
		$packs->update_progress( $pack->id, GeographyPackStatus::Importing, '12', $pack->progress, '', null, $pack->target_token() );
		$owner = $packs->acquire_lease( $pack->id, 'tick', time(), GeographyPackService::LOCK_TTL_SECONDS );
		self::assertNotSame( '', $owner );
		$packs->expire_lease( $pack->id, time() - 10 );
		$before = $packs->find_by_id( $pack->id );
		$service->ensure_import_liveness();
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		$after = $packs->find_by_id( $pack->id );
		self::assertSame( $before?->import_cursor, $after?->import_cursor );
		self::assertSame( $before?->checksum, $after?->checksum );
		self::assertSame( $before?->target_token(), $after?->target_token() );
		self::assertSame( $before?->source_reference, $after?->source_reference );
		unlink( $file );
	}

	public function test_stale_generation_worker_cannot_advance_new_generation(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 8 );
		$cursor = $pack->import_cursor;
		$result = $service->tick( $pack->id, $file, 3, 'stale-token-not-current' );
		self::assertSame( 'stale_generation', (string) ( $result['reason'] ?? '' ) );
		self::assertSame( $cursor, $packs->find_by_id( $pack->id )?->import_cursor );
		unlink( $file );
	}

	public function test_orphaned_importing_pack_is_rearmed_without_resetting_identity(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 8 );
		$service->tick( $pack->id, $file, 3, $pack->target_token() );
		$after_tick = $packs->find_by_id( $pack->id );
		self::assertInstanceOf( \CetechDeliveryEngine\Domain\Geography\GeographyPack::class, $after_tick );
		$this->store->reset();
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::HOOK, GeographyPackService::GROUP . '-' . $pack->id, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		$service->ensure_import_liveness();
		$rearmed = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Importing, $rearmed?->status );
		self::assertSame( $after_tick->import_cursor, $rearmed?->import_cursor );
		self::assertSame( $after_tick->checksum, $rearmed?->checksum );
		self::assertSame( $after_tick->target_token(), $rearmed?->target_token() );
		self::assertSame( $file, $rearmed?->source_reference );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, GeographyPackService::GROUP . '-' . $pack->id, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_ready_pack_schedules_no_tick_successor(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 1 );
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		$service->retry( $pack->id, $file );
		$result = $this->run_unattended( $service, $pack, $file, 20, 40 );
		self::assertSame( GeographyPackStatus::Ready->value, (string) ( $result['status'] ?? '' ) );
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		$service->ensure_import_liveness();
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_failed_pack_does_not_auto_resume(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 1 );
		$packs->update_progress( $pack->id, GeographyPackStatus::Failed, $pack->import_cursor, $pack->progress, 'preflight', null, $pack->target_token() );
		$this->store->reset();
		$cursor = $packs->find_by_id( $pack->id )?->import_cursor;
		$ticked = $service->tick( $pack->id, $file, 3, $pack->target_token() );
		self::assertSame( GeographyPackStatus::Failed->value, (string) ( $ticked['status'] ?? '' ) );
		self::assertSame( 'terminal', (string) ( $ticked['reason'] ?? '' ) );
		self::assertSame( $cursor, $packs->find_by_id( $pack->id )?->import_cursor );
		$service->ensure_import_liveness();
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( GeographyPackStatus::Failed, $packs->find_by_id( $pack->id )?->status );
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_official_download_chain_unschedules_ticks_then_enqueues_download(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 1 );
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		ActionSchedulerReadiness::enqueue_unique_async( GeographyPackService::HOOK, [ 'pack_id' => $pack->id ], $group );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		$method = new \ReflectionMethod( GeographyPackService::class, 'enqueue_download' );
		if ( PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}
		$method->invoke( $service, $pack->id, 'GH', $pack->target_token() );
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::DOWNLOAD_HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_manual_retry_is_idempotent_and_does_not_duplicate_work(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 4 );
		$first  = $service->retry( $pack->id, $file );
		$second = $service->retry( $pack->id, $file );
		$group  = GeographyPackService::GROUP . '-' . $pack->id;
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( $first->target_token(), $second->target_token() );
		self::assertSame( $first->import_cursor, $second->import_cursor );
		unlink( $file );
	}

	public function test_held_lease_blocks_orphan_rearm(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 4 );
		$packs->update_progress( $pack->id, GeographyPackStatus::Importing, '4', $pack->progress, '', null, $pack->target_token() );
		$owner = $packs->acquire_lease( $pack->id, 'tick', time(), GeographyPackService::LOCK_TTL_SECONDS );
		self::assertNotSame( '', $owner );
		$this->store->reset();
		$service->ensure_import_liveness();
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		self::assertSame( 0, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_watchdog_is_rate_bounded_and_does_not_hot_loop(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 4 );
		$packs->update_progress( $pack->id, GeographyPackStatus::Importing, '8', $pack->progress, '', null, $pack->target_token() );
		$this->store->reset();
		$this->store->set_now( 1_000 );
		$service->kick_liveness_if_needed();
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( 1_000 + GeographyPackService::LIVENESS_INTERVAL_SECONDS, $this->store->next_scheduled_at( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP ) );

		$executions = 0;
		for ( $i = 0; $i < 100; ++$i ) {
			$id = $this->store->claim_next_pending( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP );
			self::assertSame( 0, $id, 'Watchdog must not run before the delayed interval.' );
		}

		$this->store->set_now( 1_060 );
		$id = $this->store->claim_next_pending( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP );
		self::assertGreaterThan( 0, $id );
		$service->ensure_import_liveness();
		$this->store->complete( $id );
		++$executions;
		self::assertSame( 1, $executions );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( 1_120, $this->store->next_scheduled_at( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP ) );

		for ( $i = 0; $i < 100; ++$i ) {
			$again = $this->store->claim_next_pending( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP );
			self::assertSame( 0, $again, 'Watchdog must not execute 100 times at the same clock time.' );
		}
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, GeographyPackService::GROUP . '-' . $pack->id, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	public function test_watchdog_does_not_rearm_when_a_healthy_tick_is_pending(): void {
		[ $service, $packs, $file, $pack ] = $this->importing_fixture( 4 );
		$group = GeographyPackService::GROUP . '-' . $pack->id;
		$service->retry( $pack->id, $file );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		$service->ensure_import_liveness();
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		self::assertSame( 1, $this->store->count_by_status( GeographyPackService::LIVENESS_HOOK, GeographyPackService::LIVENESS_GROUP, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		unlink( $file );
	}

	/**
	 * @return array{0: GeographyPackService, 1: InMemoryGeographyPackRepository, 2: string, 3: \CetechDeliveryEngine\Domain\Geography\GeographyPack}
	 */
	private function importing_fixture( int $admin_count ): array {
		$geo     = new GhanaGeographyFixture();
		$packs   = new InMemoryGeographyPackRepository();
		$service = $this->pack_service( $geo, $packs );
		$lines   = [ $this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '' ) ];
		for ( $i = 1; $i <= $admin_count; ++$i ) {
			$code    = str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
			$lines[] = $this->row( (string) ( 100 + $i ), 'Region ' . $code, 'Region ' . $code, 'A', 'ADM1', $code );
			$lines[] = $this->row( (string) ( 200 + $i ), 'Town ' . $code, 'Town ' . $code, 'P', 'PPL', $code );
		}
		$file = $this->gazetteer_file( $lines );
		$pack = $this->importer( $geo, $packs )->begin_dataset(
			$packs->save(
				[
					'country_code'     => 'GH',
					'provider'         => GeographyProvider::GeoNames->value,
					'dataset_name'     => 'gazetteer',
					'source_reference' => $file,
					'checksum'         => hash_file( 'sha256', $file ) ?: 'sum',
					'status'           => GeographyPackStatus::Pending->value,
				]
			),
			$file,
			hash_file( 'sha256', $file ) ?: 'sum',
			'2026.geo-live'
		);

		return [ $service, $packs, $file, $pack ];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_unattended( GeographyPackService $service, \CetechDeliveryEngine\Domain\Geography\GeographyPack $pack, string $file, int $batch, int $max ): array {
		$group  = GeographyPackService::GROUP . '-' . $pack->id;
		$result = [ 'status' => GeographyPackStatus::Importing->value ];
		for ( $i = 0; $i < $max; ++$i ) {
			$id = $this->store->claim_next_pending( GeographyPackService::HOOK, $group );
			if ( $id <= 0 ) {
				break;
			}
			$fresh  = $service->find( $pack->id );
			$token  = $fresh instanceof \CetechDeliveryEngine\Domain\Geography\GeographyPack ? $fresh->target_token() : '';
			$result = $service->tick( $pack->id, $file, $batch, $token );
			$this->store->complete( $id );
			$service->on_scheduler_after_execute( $id, $this->store->fetch_action( $id ) );
			$status = (string) ( $result['status'] ?? '' );
			if ( GeographyPackStatus::Ready->value === $status || GeographyPackStatus::Failed->value === $status ) {
				$result['ticks'] = $i + 1;

				return $result;
			}
		}
		$result['ticks'] = $max;

		return $result;
	}

	private function pack_service( GhanaGeographyFixture $geo, InMemoryGeographyPackRepository $packs ): GeographyPackService {
		return new GeographyPackService(
			$packs,
			$this->importer( $geo, $packs ),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
	}

	private function importer( GhanaGeographyFixture $geo, InMemoryGeographyPackRepository $packs ): GeoNamesPackImporter {
		return new GeoNamesPackImporter(
			$geo->locations,
			$geo->locations,
			$geo->locations,
			$packs,
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ),
			new GeoNamesGazetteerParser()
		);
	}

	/**
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geolive' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		return $file;
	}

	private function row( string $id, string $name, string $ascii, string $class, string $code, string $admin1, string $admin2 = '', string $country = 'GH' ): string {
		$parts     = array_fill( 0, 19, '' );
		$parts[0]  = $id;
		$parts[1]  = $name;
		$parts[2]  = $ascii;
		$parts[6]  = $class;
		$parts[7]  = $code;
		$parts[8]  = $country;
		$parts[10] = $admin1;
		$parts[11] = $admin2;

		return implode( "\t", $parts );
	}

	private function method_source( string $source, string $method ): string {
		$start = strpos( $source, 'public static function ' . $method . '(' );
		self::assertNotFalse( $start );

		return substr( $source, (int) $start, 1800 );
	}
}
