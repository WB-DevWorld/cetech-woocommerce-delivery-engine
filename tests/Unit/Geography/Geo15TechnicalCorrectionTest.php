<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackPreflight;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeKickoff;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Infrastructure\WordPress\ActionSchedulerReadiness;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Issue #23 geo.15 — deferred schema-6 kickoff, Action Scheduler safety, pack lease no-op renew.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013, DE-PERF-002.
 */
final class Geo15TechnicalCorrectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']            = [];
		$GLOBALS['cetech_de_test_as_enqueue_attempts'] = 0;
		$GLOBALS['cetech_de_test_as_enqueue_blocked']  = 0;
		$GLOBALS['cetech_de_test_as_enqueue_invoked']  = 0;
		$GLOBALS['wp_actions']                         = [];
		$GLOBALS['cetech_de_test_actions']             = [];
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['wpdb'],
			$GLOBALS['cetech_de_test_wc'],
			$GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ],
			$GLOBALS['cetech_de_test_as_enqueue_attempts'],
			$GLOBALS['cetech_de_test_as_enqueue_blocked'],
			$GLOBALS['cetech_de_test_as_enqueue_invoked'],
			$GLOBALS['wp_actions']
		);
		parent::tearDown();
	}

	public function test_plugins_loaded_boot_does_not_call_maybe_run(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Bootstrap/Plugin.php' );
		$boot   = $this->boot_method_source( $plugin );
		self::assertStringContainsString( '$migration_runner->run();', $boot );
		self::assertStringContainsString( 'Schema6CoverageUpgradeKickoff::class )->register()', $boot );
		self::assertStringNotContainsString( 'maybe_run()', $boot );
		self::assertSame( Schema6CoverageUpgradeKickoff::HOOK, 'init' );
		self::assertSame( 20, Schema6CoverageUpgradeKickoff::PRIORITY );
		self::assertGreaterThan( 1, Schema6CoverageUpgradeKickoff::PRIORITY );
	}

	public function test_worker_hooks_remain_registered_during_boot(): void {
		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Bootstrap/Plugin.php' );
		$boot   = $this->boot_method_source( $plugin );
		self::assertStringContainsString( 'Schema6CoverageUpgradeService::HOOK', $boot );
		self::assertStringContainsString( 'GeographyPackService::HOOK', $boot );
		self::assertStringContainsString( 'GeographyPackService::DOWNLOAD_HOOK', $boot );
		self::assertStringContainsString( 'register_liveness()', $boot );
	}

	public function test_action_scheduler_enqueue_is_blocked_before_initialization(): void {
		$this->install_coverage_table();
		$upgrade = $this->schema6_upgrade_service();
		unset( $GLOBALS['cetech_de_test_wc'] );
		$GLOBALS['wp_actions']['action_scheduler_init'] = 0;

		self::assertFalse( ActionSchedulerReadiness::is_initialized() );
		$result = $upgrade->maybe_run();

		self::assertSame( 'woocommerce_unavailable', (string) ( $result['migration']['reason'] ?? '' ) );
		self::assertSame( Schema6CoverageUpgradeService::STATUS_DEFERRED_WOO, (string) ( $upgrade->current_state()['status'] ?? '' ) );
		self::assertGreaterThan( 0, (int) $GLOBALS['cetech_de_test_as_enqueue_attempts'] );
		self::assertSame( (int) $GLOBALS['cetech_de_test_as_enqueue_attempts'], (int) $GLOBALS['cetech_de_test_as_enqueue_blocked'] );
		self::assertSame( 0, (int) $GLOBALS['cetech_de_test_as_enqueue_invoked'] );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $upgrade->current_state()['status'] ?? '' ) );
	}

	public function test_kickoff_skips_before_woo_and_action_scheduler_are_ready(): void {
		$this->install_coverage_table();
		$upgrade = $this->schema6_upgrade_service();
		unset( $GLOBALS['cetech_de_test_wc'] );
		$kickoff = new Schema6CoverageUpgradeKickoff( $upgrade );

		self::assertFalse( $kickoff->ready() );
		$kickoff->run();
		$status = (string) ( $upgrade->current_state()['status'] ?? Schema6CoverageUpgradeService::STATUS_PENDING );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, $status );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_DEFERRED_WOO, $status );
		self::assertSame( 0, (int) $GLOBALS['cetech_de_test_as_enqueue_attempts'] );
	}

	public function test_kickoff_runs_maybe_run_after_woo_and_action_scheduler_ready(): void {
		$this->install_coverage_table();
		$upgrade = $this->schema6_upgrade_service();
		$GLOBALS['cetech_de_test_wc']                   = $this->woo_stub();
		$GLOBALS['wp_actions']['woocommerce_init']      = 1;
		$GLOBALS['wp_actions']['action_scheduler_init'] = 1;
		$kickoff = new Schema6CoverageUpgradeKickoff( $upgrade );
		$kickoff->register();

		$registered = $GLOBALS['cetech_de_test_actions']['init'] ?? [];
		self::assertNotSame( [], $registered );
		self::assertSame( 20, (int) ( $registered[ array_key_last( $registered ) ]['priority'] ?? 0 ) );
		self::assertTrue( ActionSchedulerReadiness::is_initialized() );
		self::assertTrue( $kickoff->ready() );

		$kickoff->run();
		$status = (string) ( $upgrade->current_state()['status'] ?? '' );
		self::assertContains(
			$status,
			[
				Schema6CoverageUpgradeService::STATUS_RUNNING,
				Schema6CoverageUpgradeService::STATUS_COMPLETED,
			]
		);
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_DEFERRED_WOO, $status );
	}

	public function test_interrupted_kickoff_resumes_on_later_ready_request(): void {
		$this->install_coverage_table();
		$upgrade = $this->schema6_upgrade_service();
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$kickoff                      = new Schema6CoverageUpgradeKickoff( $upgrade );

		self::assertFalse( $kickoff->ready() );
		$kickoff->run();
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $upgrade->current_state()['status'] ?? '' ) );

		$GLOBALS['wp_actions']['action_scheduler_init'] = 1;
		self::assertTrue( $kickoff->ready() );
		$kickoff->run();
		self::assertContains(
			(string) ( $upgrade->current_state()['status'] ?? '' ),
			[
				Schema6CoverageUpgradeService::STATUS_RUNNING,
				Schema6CoverageUpgradeService::STATUS_COMPLETED,
			]
		);
	}

	public function test_woo_unavailable_does_not_fabricate_success(): void {
		$this->install_coverage_table();
		$upgrade = $this->schema6_upgrade_service();
		unset( $GLOBALS['cetech_de_test_wc'] );
		$GLOBALS['wp_actions']['action_scheduler_init'] = 1;
		$result = $upgrade->maybe_run();

		self::assertSame( 'woocommerce_unavailable', (string) ( $result['migration']['reason'] ?? '' ) );
		self::assertSame( Schema6CoverageUpgradeService::STATUS_DEFERRED_WOO, (string) ( $upgrade->current_state()['status'] ?? '' ) );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $upgrade->current_state()['status'] ?? '' ) );
	}

	public function test_same_owner_same_expiry_renew_is_verified_noop(): void {
		$wpdb            = new Geo15VerifyingLeaseWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new WpdbGeographyPackRepository();
		$now             = 1_700_000_000;
		$ttl             = GeographyPackService::LOCK_TTL_SECONDS;
		$owner           = 'tick:owner-a';
		$wpdb->row       = [
			'id'               => 7,
			'lease_owner'      => $owner,
			'lease_role'       => 'tick',
			'lease_acquired_at'=> $now,
			'lease_expires_at' => $now + $ttl,
		];
		$wpdb->affected  = 0;

		self::assertTrue( $repo->renew_lease( 7, $owner, $now, $ttl ) );
		self::assertSame( 1, $wpdb->update_calls );
		self::assertSame( 1, $wpdb->select_calls );
		self::assertSame( 1, $wpdb->update_count_in_log() );
	}

	public function test_stale_owner_renew_fails_without_fallback_write(): void {
		$wpdb            = new Geo15VerifyingLeaseWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new WpdbGeographyPackRepository();
		$now             = 1_700_000_000;
		$wpdb->row       = [
			'id'               => 7,
			'lease_owner'      => 'tick:owner-b',
			'lease_role'       => 'tick',
			'lease_acquired_at'=> $now,
			'lease_expires_at' => $now + GeographyPackService::LOCK_TTL_SECONDS,
		];
		$wpdb->affected  = 0;

		self::assertFalse( $repo->renew_lease( 7, 'tick:owner-a', $now, GeographyPackService::LOCK_TTL_SECONDS ) );
		self::assertSame( 1, $wpdb->update_calls );
		self::assertSame( 1, $wpdb->select_calls );
		self::assertSame( 1, $wpdb->update_count_in_log() );
	}

	public function test_zero_row_renew_fails_when_persisted_expiry_does_not_match(): void {
		$wpdb            = new Geo15VerifyingLeaseWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new WpdbGeographyPackRepository();
		$now             = 1_700_000_000;
		$owner           = 'tick:owner-a';
		$wpdb->row       = [
			'id'               => 7,
			'lease_owner'      => $owner,
			'lease_role'       => 'tick',
			'lease_acquired_at'=> $now,
			'lease_expires_at' => $now + 5,
		];
		$wpdb->affected  = 0;

		self::assertFalse( $repo->renew_lease( 7, $owner, $now, GeographyPackService::LOCK_TTL_SECONDS ) );
		self::assertSame( 1, $wpdb->update_count_in_log() );
	}

	public function test_tick_acquire_and_immediate_renew_is_not_lock_lost(): void {
		$geo     = new GhanaGeographyFixture();
		$packs   = new InMemoryGeographyPackRepository();
		$service = $this->pack_service( $geo, $packs );
		$file    = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '' ),
				$this->row( '2', 'Greater Accra', 'Greater Accra', 'A', 'ADM1', '01' ),
				$this->row( '3', 'Accra', 'Accra', 'P', 'PPLC', '01' ),
			]
		);
		$pack = $this->importer( $geo, $packs )->begin_dataset(
			$packs->save(
				[
					'country_code' => 'GH',
					'provider'     => GeographyProvider::GeoNames->value,
					'dataset_name' => 'gazetteer',
					'status'       => GeographyPackStatus::Pending->value,
				]
			),
			$file,
			hash_file( 'sha256', $file ) ?: 'sum',
			'2026.geo15'
		);

		$result = $service->tick( $pack->id, $file, 20, $pack->target_token() );
		self::assertNotSame( 'lock_lost', (string) ( $result['reason'] ?? '' ) );
		unlink( $file );
	}

	public function test_small_valid_pack_reaches_ready_and_retry_is_idempotent(): void {
		$geo     = new GhanaGeographyFixture();
		$packs   = new InMemoryGeographyPackRepository();
		$service = $this->pack_service( $geo, $packs );
		$importer = $this->importer( $geo, $packs );
		$file     = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '' ),
				$this->row( '2', 'Greater Accra', 'Greater Accra', 'A', 'ADM1', '01' ),
				$this->row( '3', 'Accra', 'Accra', 'P', 'PPLC', '01' ),
			]
		);
		$pack = $importer->begin_dataset(
			$importer->ensure_pack( 'GH', $file ),
			$file,
			hash_file( 'sha256', $file ) ?: 'sum',
			'2026.geo15'
		);
		$result = $this->tick_until_terminal( $service, $pack->id, $file, $pack->target_token() );
		self::assertSame( GeographyPackStatus::Ready->value, (string) ( $result['status'] ?? '' ) );
		$ready = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Ready, $ready?->status );
		$accra = $geo->locations->find_location_id( GeographyProvider::GeoNames, '3' );
		self::assertNotNull( $accra );
		$before = $accra;

		$retried = $service->retry( $pack->id, $file );
		$again   = $this->tick_until_terminal( $service, $retried->id, $file, $retried->target_token() );
		self::assertNotSame( 'lock_lost', (string) ( $again['reason'] ?? '' ) );
		self::assertSame( $before, $geo->locations->find_location_id( GeographyProvider::GeoNames, '3' ) );
		unlink( $file );
	}

	public function test_empty_corrupt_and_wrong_country_packs_still_fail(): void {
		$preflight = new GeoNamesPackPreflight();
		$empty     = tempnam( sys_get_temp_dir(), 'geo15empty' );
		self::assertIsString( $empty );
		file_put_contents( $empty, '' );
		self::assertSame( 'empty', $preflight->validate( $empty, 'GH' )['error'] );

		$corrupt = tempnam( sys_get_temp_dir(), 'geo15corr' );
		self::assertIsString( $corrupt );
		file_put_contents( $corrupt, "not\ttabular\n" );
		self::assertSame( 'corrupt', $preflight->validate( $corrupt, 'GH' )['error'] );

		$wrong = $this->gazetteer_file( [ $this->row( '99', 'Lagos', 'Lagos', 'P', 'PPL', '05', '', 'NG' ) ] );
		self::assertSame( 'wrong_country', $preflight->validate( $wrong, 'GH' )['error'] );

		$geo     = new GhanaGeographyFixture();
		$packs   = new InMemoryGeographyPackRepository();
		$service = $this->pack_service( $geo, $packs );
		$failed  = $service->update( 'GH', $empty );
		self::assertSame( GeographyPackStatus::Failed, $failed->status );
		unlink( $empty );
		unlink( $corrupt );
		unlink( $wrong );
	}

	public function test_action_scheduler_readiness_source_never_trusts_function_exists_alone(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Infrastructure/WordPress/ActionSchedulerReadiness.php' );
		self::assertStringContainsString( 'is_initialized()', $source );
		self::assertStringContainsString( 'action_scheduler_init', $source );
		self::assertStringContainsString( 'can_enqueue_async()', $source );
		$enqueue = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Geography/Schema6CoverageUpgradeService.php' );
		self::assertStringContainsString( 'ActionSchedulerReadiness::enqueue_unique_async', $enqueue );
		$packs = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Geography/GeographyPackService.php' );
		self::assertStringContainsString( 'ActionSchedulerReadiness::enqueue_unique_async', $packs );
	}

	private function boot_method_source( string $plugin ): string {
		$start = strpos( $plugin, 'public function boot(' );
		self::assertNotFalse( $start );
		$chunk = substr( $plugin, (int) $start, 8000 );

		return $chunk;
	}

	private function schema6_upgrade_service(): Schema6CoverageUpgradeService {
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );

		return new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
	}

	private function install_coverage_table(): void {
		$wpdb            = new FakeWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->create_table( TableNames::for( CoverageSchema::GROUPS_SUFFIX ) );
	}

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					unset( $country );

					return [ 'AA' => 'Greater Accra' ];
				}
			},
		];
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
	 * @return array<string, mixed>
	 */
	private function tick_until_terminal( GeographyPackService $service, int $pack_id, string $file, string $token ): array {
		$result = [ 'status' => GeographyPackStatus::Importing->value ];
		for ( $i = 0; $i < 40; ++$i ) {
			$result = $service->tick( $pack_id, $file, 20, $token );
			self::assertNotSame( 'lock_lost', (string) ( $result['reason'] ?? '' ) );
			$status = (string) ( $result['status'] ?? '' );
			if ( GeographyPackStatus::Ready->value === $status || GeographyPackStatus::Failed->value === $status ) {
				return $result;
			}
		}

		return $result;
	}

	/**
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geo15' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		return $file;
	}

	private function row( string $id, string $name, string $ascii, string $class, string $code, string $admin1, string $admin2 = '', string $country = 'GH' ): string {
		$parts         = array_fill( 0, 19, '' );
		$parts[0]      = $id;
		$parts[1]      = $name;
		$parts[2]      = $ascii;
		$parts[6]      = $class;
		$parts[7]      = $code;
		$parts[8]      = $country;
		$parts[10]     = $admin1;
		$parts[11]     = $admin2;

		return implode( "\t", $parts );
	}
}

/**
 * wpdb stand-in: UPDATE can report 0 rows while SELECT proves or rejects the lease.
 */
final class Geo15VerifyingLeaseWpdb {

	public string $prefix = 'wp_';

	/** @var array<string, mixed> */
	public array $row = [];

	public int $affected = 0;

	public int $update_calls = 0;

	public int $select_calls = 0;

	/** @var list<string> */
	public array $sql_log = [];

	public function query( mixed $sql ): int|false {
		$sql = (string) $sql;
		$this->sql_log[] = $sql;
		if ( str_starts_with( strtoupper( trim( $sql ) ), 'UPDATE' ) ) {
			++$this->update_calls;

			return $this->affected;
		}

		return 0;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_row( mixed $sql, mixed $output = 'ARRAY_A' ): ?array {
		unset( $output );
		$this->sql_log[] = (string) $sql;
		++$this->select_calls;

		return $this->row;
	}

	public function prepare( string $query, mixed ...$args ): string {
		$i = 0;

		return (string) preg_replace_callback(
			'/%[sdfF]/',
			static function () use ( &$i, $args ): string {
				$value = $args[ $i++ ] ?? '';

				return "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function update_count_in_log(): int {
		$count = 0;
		foreach ( $this->sql_log as $sql ) {
			if ( str_starts_with( strtoupper( trim( $sql ) ), 'UPDATE' ) ) {
				++$count;
			}
		}

		return $count;
	}
}
