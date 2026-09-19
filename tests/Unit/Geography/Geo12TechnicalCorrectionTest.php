<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackPreflight;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\CanonicalResolutionContext;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Presentation\Admin\LocationPacksPage;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Issue #23 / PR #24 comment 5732836980 — geo.12 closure regressions.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013.
 */
final class Geo12TechnicalCorrectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'], $GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] );
		parent::tearDown();
	}

	public function test_schema6_cas_failure_does_not_call_run(): void {
		$this->install_coverage_table();
		$upgrade = $this->schema6_upgrade_service();
		update_option(
			Schema6CoverageUpgradeService::OPTION_KEY,
			[
				'status'           => Schema6CoverageUpgradeService::STATUS_COMPLETED,
				'owner'            => 'done-owner',
				'lease_owner'      => 'done-owner',
				'pass_id'          => 'pass-done',
				'pass_kind'        => Schema6CoverageUpgradeService::PASS_INITIAL,
				'last_zone_id'     => 12,
				'lease_expires_at' => time() + 60,
			],
			false
		);
		$GLOBALS['wpdb'] = new Geo12AlwaysFailCasWpdb();
		$result          = $upgrade->reconcile( true );
		self::assertSame( 'cas_failed', (string) ( $result['migration']['reason'] ?? '' ) );
		self::assertTrue( ! empty( $result['migration']['skipped'] ) );
		self::assertSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $upgrade->current_state()['status'] ?? '' ) );
	}

	public function test_force_reconciliation_does_not_steal_live_pass(): void {
		$upgrade = $this->schema6_upgrade_service();
		self::assertTrue( $upgrade->try_claim_owner( 'owner-live' ) );
		$result = $upgrade->reconcile( true );
		self::assertSame( 'running', (string) ( $result['migration']['reason'] ?? '' ) );
		self::assertSame( 'owner-live', (string) ( $upgrade->current_state()['owner'] ?? '' ) );
	}

	public function test_reconciliation_starts_a_new_pass_from_zone_zero(): void {
		$upgrade = $this->schema6_upgrade_service();
		update_option(
			Schema6CoverageUpgradeService::OPTION_KEY,
			[
				'status'           => Schema6CoverageUpgradeService::STATUS_COMPLETED,
				'owner'            => 'old',
				'lease_owner'      => 'old',
				'pass_id'          => 'pass-initial',
				'pass_kind'        => Schema6CoverageUpgradeService::PASS_INITIAL,
				'last_zone_id'     => 501,
				'lease_expires_at' => time() - 10,
			],
			false
		);
		$result = $upgrade->reconcile( true );
		$state  = is_array( $result['state'] ?? null ) ? $result['state'] : $upgrade->current_state();
		self::assertSame( Schema6CoverageUpgradeService::PASS_RECONCILE, (string) ( $state['pass_kind'] ?? '' ) );
		self::assertNotSame( 'pass-initial', (string) ( $state['pass_id'] ?? '' ) );
		self::assertNotSame( 501, (int) ( $state['last_zone_id'] ?? 501 ) );
	}

	public function test_persistence_failure_does_not_advance_or_complete(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$groups->fail_replace_zone_id = 2;
		$this->install_coverage_table();
		$locations->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$zones->save( [ 'id' => 1, 'internal_name' => 'One', 'status' => RecordStatus::Active->value ] );
		$zones->save( [ 'id' => 2, 'internal_name' => 'Two', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$rules->replaceForZone( 2, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );
		$upgrade   = new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
		$state     = [];
		for ( $i = 0; $i < 8; ++$i ) {
			$result = $upgrade->maybe_run();
			$state  = is_array( $result['state'] ?? null ) ? $result['state'] : $upgrade->current_state();
			if ( (int) ( $state['failed_zone_id'] ?? 0 ) > 0 ) {
				break;
			}
		}
		self::assertSame( 2, (int) ( $state['failed_zone_id'] ?? 0 ) );
		self::assertSame( 1, (int) ( $state['last_zone_id'] ?? 0 ) );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $state['status'] ?? '' ) );
		self::assertEmpty( $groups->list_by_zone( 2 ) );
	}

	public function test_conversion_defers_when_woocommerce_is_unavailable(): void {
		$upgrade = $this->schema6_upgrade_service();
		unset( $GLOBALS['cetech_de_test_wc'] );
		$result = $upgrade->maybe_run();
		self::assertSame( 'woocommerce_unavailable', (string) ( $result['migration']['reason'] ?? '' ) );
		self::assertSame( Schema6CoverageUpgradeService::STATUS_DEFERRED_WOO, (string) ( $upgrade->current_state()['status'] ?? '' ) );
	}

	public function test_migrate_is_never_called_with_unlimited_pages(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( Schema6CoverageUpgradeService::class ) )->getFileName() );
		self::assertStringContainsString( '$this->migrator->migrate( $force, $after, self::PAGES_PER_TICK )', $src );
		self::assertStringNotContainsString( '$this->migrator->migrate( $force, $after )', $src );
		self::assertSame( 1, Schema6CoverageUpgradeService::PAGES_PER_TICK );
	}

	public function test_future_parent_graph_is_independent_of_prepare_order(): void {
		$repo      = new InMemoryCanonicalLocationRepository();
		$country   = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a  = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Region A', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_x  = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Region X', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b  = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Region B', $country->id, GeographyLocationType::Administrative, 1 ) );
		$q_live    = $repo->save( $this->blank_location( $this->key( 'town', 1 ), 'Q', $region_a->id, GeographyLocationType::Locality ) );
		$p         = $this->save_reparent( $repo, $this->key( 'town', 2 ), 'P', $region_x, $region_b, 'token-order' );
		$q         = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'Q', $region_a, $p, 'token-order', $region_a->id, $q_live->id );
		self::assertLessThan( $p->id, $q->id );

		$prepared_q = $repo->prepare_generation( 'token-order', 1, 0 );
		self::assertFalse( $prepared_q['done'] );
		$staged_q = $repo->find_by_id( $q->id );
		self::assertTrue( LocationAncestry::path_contains( (string) $staged_q?->prepared_ancestry_path, $region_b->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $staged_q?->prepared_ancestry_path, $region_x->id ) );
		self::assertSame( $region_a->id, $repo->find_by_id( $q->id )?->parent_location_id );
		self::assertSame( $region_x->id, $repo->find_by_id( $p->id )?->parent_location_id );

		$this->prepare_until_done( $repo, 'token-order' );
		self::assertSame( $region_a->id, $repo->find_by_id( $q->id )?->parent_location_id );
		$repo->finalize_generation( 'token-order' );
		self::assertSame( $p->id, $repo->find_by_id( $q->id )?->parent_location_id );
		self::assertSame( $region_b->id, $repo->find_by_id( $p->id )?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $q->id )?->ancestry_path, $region_b->id ) );
	}

	public function test_future_parent_chain_and_cycle_and_abandon(): void {
		$repo     = new InMemoryCanonicalLocationRepository();
		$country  = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Region A', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Region B', $country->id, GeographyLocationType::Administrative, 1 ) );
		$m_live   = $repo->save( $this->blank_location( $this->key( 'town', 1 ), 'M', $region_a->id, GeographyLocationType::Locality ) );
		$p_live   = $repo->save( $this->blank_location( $this->key( 'town', 2 ), 'P', $region_a->id, GeographyLocationType::Locality ) );
		$q_live   = $repo->save( $this->blank_location( $this->key( 'town', 3 ), 'Q', $region_a->id, GeographyLocationType::Locality ) );
		$m        = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'M', $region_a, $region_b, 'token-chain', $region_a->id, $m_live->id );
		$p        = $this->save_reparent( $repo, $this->key( 'town', 2 ), 'P', $region_a, $m, 'token-chain', $region_a->id, $p_live->id );
		$q        = $this->save_reparent( $repo, $this->key( 'town', 3 ), 'Q', $region_a, $p, 'token-chain', $region_a->id, $q_live->id );
		$this->prepare_until_done( $repo, 'token-chain' );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $q->id )?->prepared_ancestry_path, $region_b->id ) );
		$repo->abandon_generation( 'token-chain' );
		self::assertSame( $region_a->id, $repo->find_by_id( $q->id )?->parent_location_id );
		self::assertSame( $region_a->id, $repo->find_by_id( $p->id )?->parent_location_id );
		self::assertSame( '', (string) $repo->find_by_id( $q->id )?->prepared_ancestry_path );

		$cycle_a = $repo->save( $this->blank_location( $this->key( 'cyc', 1 ), 'CycleA', $region_a->id, GeographyLocationType::Locality ) );
		$cycle_b = $repo->save( $this->blank_location( $this->key( 'cyc', 2 ), 'CycleB', $region_a->id, GeographyLocationType::Locality ) );
		$this->save_reparent( $repo, $this->key( 'cyc', 1 ), 'CycleA', $region_a, $cycle_b, 'token-cycle', $region_a->id, $cycle_a->id );
		$this->save_reparent( $repo, $this->key( 'cyc', 2 ), 'CycleB', $region_a, $cycle_a, 'token-cycle', $region_a->id, $cycle_b->id );
		try {
			$repo->prepare_generation( 'token-cycle', 20, 0 );
			self::fail( 'Cyclic future-parent graph must fail closed.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'Cyclic', $e->getMessage() );
		}
		self::assertSame( $region_a->id, $repo->find_by_id( $cycle_a->id )?->parent_location_id );
	}

	public function test_woo_destination_unique_descendant_and_shopper_selector_pack_guard(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$country   = $locations->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$adm1      = $locations->save( $this->blank_location( $this->key( 'adm', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$dist_a    = $locations->save( $this->blank_location( $this->key( 'adm', 2 ), 'District A', $adm1->id, GeographyLocationType::Administrative, 2 ) );
		$dist_b    = $locations->save( $this->blank_location( $this->key( 'adm', 3 ), 'District B', $adm1->id, GeographyLocationType::Administrative, 2 ) );
		$sub_a     = $locations->save( $this->blank_location( $this->key( 'adm', 4 ), 'Sub A', $dist_a->id, GeographyLocationType::Administrative, 3 ) );
		$sub_b     = $locations->save( $this->blank_location( $this->key( 'adm', 5 ), 'Sub B', $dist_b->id, GeographyLocationType::Administrative, 3 ) );
		$accra_a   = $locations->save( $this->blank_location( $this->key( 'loc', 1 ), 'Accra', $sub_a->id, GeographyLocationType::Locality ) );
		$accra_b   = $locations->save( $this->blank_location( $this->key( 'loc', 2 ), 'Accra', $sub_b->id, GeographyLocationType::Locality ) );
		$tema      = $locations->save( $this->blank_location( $this->key( 'loc', 3 ), 'Tema Port', $sub_a->id, GeographyLocationType::Locality ) );
		$locations->add_alias( $tema->id, 'Harbour Town', 'harbour town' );
		$packs = new InMemoryGeographyPackRepository();
		$packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Ready->value,
				'checksum'     => 'pack-ready',
			]
		);
		$resolver = new CanonicalLocationResolver( $locations, $locations, $packs );

		$deep = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Tema Port', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertTrue( $deep->canonical );
		self::assertSame( $tema->id, $deep->location_id() );

		$alias = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Harbour Town', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertTrue( $alias->canonical );
		self::assertSame( $tema->id, $alias->location_id() );

		$ambiguous = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Accra', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertNotSame( 'exact_locality', $ambiguous->resolution_source );
		self::assertNotSame( $accra_a->id, $ambiguous->location_id() );
		self::assertNotSame( $accra_b->id, $ambiguous->location_id() );

		$typo = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Acccra', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertNotSame( 'exact_locality', $typo->resolution_source );

		$key_wins = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Acccra', '', $accra_a->location_key, CanonicalResolutionContext::WooCommerceDestination );
		self::assertTrue( $key_wins->canonical );
		self::assertSame( $accra_a->id, $key_wins->location_id() );

		$shopper = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Tema Port', '', '', CanonicalResolutionContext::ShopperSelector );
		self::assertNotSame( 'exact_locality', $shopper->resolution_source );
		self::assertNotSame( $tema->id, $shopper->location_id() );
	}

	public function test_geography_pack_service_continues_indeterminate_preflight(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$service = new GeographyPackService(
			$packs,
			new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ), new GeoNamesGazetteerParser() ),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$lines = [];
		for ( $i = 1; $i <= GeoNamesPackPreflight::MAX_SCAN; ++$i ) {
			$lines[] = $this->geonames_row( (string) $i, 'Noise ' . $i, 'H', 'STM', 'XX' );
		}
		$lines[] = $this->geonames_row( '9000001', 'Ghana', 'A', 'PCLI', 'GH' );
		$lines[] = $this->geonames_row( '9000002', 'Greater Accra', 'A', 'ADM1', 'GH' );
		$file    = tempnam( sys_get_temp_dir(), 'geo12pre' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );
		$pack = $service->update( 'GH', $file );
		self::assertSame( GeographyPackStatus::Importing, $pack->status );
		self::assertSame( 'preflight_validation', (string) ( $pack->progress['phase'] ?? '' ) );
		self::assertSame( '', $pack->last_error );
		$last = $pack;
		for ( $i = 0; $i < 6; ++$i ) {
			$service->tick( $pack->id, $file, 50 );
			$last = $service->find( $pack->id ) ?? $last;
			if ( GeographyPackStatus::Failed === $last->status ) {
				break;
			}
			if ( (string) ( $last->progress['phase'] ?? '' ) !== 'preflight_validation' ) {
				break;
			}
		}
		self::assertNotSame( GeographyPackStatus::Failed, $last->status );
		self::assertNotSame( 'preflight_validation', (string) ( $last->progress['phase'] ?? '' ) );
		unlink( $file );
	}

	public function test_preflight_terminal_failures_still_fail(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$service = new GeographyPackService(
			$packs,
			new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ), new GeoNamesGazetteerParser() ),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$empty = tempnam( sys_get_temp_dir(), 'geo12empty' );
		self::assertIsString( $empty );
		file_put_contents( $empty, '' );
		$failed_empty = $service->update( 'GH', $empty );
		self::assertSame( GeographyPackStatus::Failed, $failed_empty->status );
		$wrong = tempnam( sys_get_temp_dir(), 'geo12wrong' );
		self::assertIsString( $wrong );
		file_put_contents( $wrong, $this->geonames_row( '1', 'Lagos', 'P', 'PPL', 'NG' ) . "\n" );
		$failed_wrong = $service->update( 'GH', $wrong );
		self::assertSame( GeographyPackStatus::Failed, $failed_wrong->status );
		unlink( $empty );
		unlink( $wrong );
	}

	public function test_matcher_cache_preserves_diagnostics(): void {
		$geo     = new GhanaGeographyFixture();
		$zones   = new InMemoryDestinationZoneRepository();
		$rules   = new InMemoryDestinationRuleRepository();
		$groups  = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra Metro', 'status' => RecordStatus::Active->value, 'priority' => 10 ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$matcher = new DestinationZoneMatcher( $zones, $rules, null, new CoverageGroupMatcher( $groups, $geo->locations ), new CanonicalLocationResolver( $geo->locations, $geo->locations ) );
		$first   = $matcher->match_all( 'GH', '', 'Accra', '' );
		$diag    = $matcher->last_diagnostics();
		self::assertNotEmpty( $diag );
		$second = $matcher->match_all( 'GH', '', 'Accra', '' );
		self::assertSame( $first, $second );
		self::assertEquals( $diag, $matcher->last_diagnostics() );
	}

	public function test_country_neutral_runtime_and_failed_pack_notice(): void {
		$packs = (string) file_get_contents( ( new \ReflectionClass( LocationPacksPage::class ) )->getFileName() );
		self::assertStringNotContainsString( "'GH'", $packs );
		self::assertStringContainsString( 'GeographyPackStatus::Failed === $pack->status', $packs );
		$failed_pos = strpos( $packs, 'GeographyPackStatus::Failed === $pack->status' );
		$tick_pos   = strpos( $packs, '$this->packs->tick(' );
		self::assertNotFalse( $failed_pos );
		self::assertNotFalse( $tick_pos );
		self::assertLessThan( $tick_pos, $failed_pos );
		$zones = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/DestinationZonesPage.php' );
		self::assertStringContainsString( 'unknown country', $zones );
		self::assertStringNotContainsString( "'GH'", $zones );
		$contextual = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Configuration/ContextualEntityService.php' );
		self::assertStringContainsString( "return '';", $contextual );
		$admin_js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/admin/delivery-engine-admin.js' );
		self::assertStringContainsString( 'flag.checked = true', $admin_js );
	}

	public function test_wpdb_q_before_p_uses_future_region_b_path(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country  = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Region A', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_x = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Region X', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Region B', $country->id, GeographyLocationType::Administrative, 1 ) );
		$q_live   = $repo->save( $this->blank_location( $this->key( 'town', 1 ), 'Q', $region_a->id, GeographyLocationType::Locality ) );
		$p        = $this->save_reparent( $repo, $this->key( 'town', 2 ), 'P', $region_x, $region_b, 'token-sql' );
		$q        = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'Q', $region_a, $p, 'token-sql', $region_a->id, $q_live->id );
		$first    = $repo->prepare_generation( 'token-sql', 1, 0 );
		self::assertFalse( $first['done'] );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $q->id )?->prepared_ancestry_path, $region_b->id ) );
		$this->prepare_until_done( $repo, 'token-sql' );
		$repo->finalize_generation( 'token-sql' );
		self::assertSame( $p->id, $repo->find_by_id( $q->id )?->parent_location_id );
		unset( $wpdb );
	}

	private function schema6_upgrade_service(): Schema6CoverageUpgradeService {
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$this->install_coverage_table();
		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );

		return new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
	}

	private function install_coverage_table(): void {
		if ( ! isset( $GLOBALS['wpdb'] ) || ! ( $GLOBALS['wpdb'] instanceof FakeWpdb ) ) {
			$GLOBALS['wpdb'] = new FakeWpdb();
		}
		$GLOBALS['wpdb']->create_table( 'wp_delivery_engine_destination_coverage_groups' );
	}

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					return [];
				}
			},
		];
	}

	private function wpdb_with_geography_tables(): FakeWpdb {
		$wpdb            = new FakeWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->create_table( TableNames::for( GeographySchema::LOCATIONS_SUFFIX ), [ [ 'location_key' ] ] );
		$wpdb->create_table( TableNames::for( GeographySchema::PACKS_SUFFIX ) );
		$wpdb->create_table( TableNames::for( GeographySchema::MAPPINGS_SUFFIX ), [ [ 'provider', 'external_id', 'generation_token' ] ] );
		$wpdb->create_table( TableNames::for( GeographySchema::ALIASES_SUFFIX ), [ [ 'location_id', 'normalized_alias', 'generation_token' ] ] );

		return $wpdb;
	}

	/**
	 * @return array{processed:int,last_id:int,done:bool,hierarchy_root_cursor:int,hierarchy_descendant_cursor:int,hierarchy_root_id:int}
	 */
	private function prepare_until_done( InMemoryCanonicalLocationRepository|WpdbCanonicalLocationRepository $repo, string $token, int $limit = 20, int $max_batches = 200 ): array {
		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		$prepared  = [];
		do {
			$prepared  = $repo->prepare_generation( $token, $limit, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = [
				'hierarchy_root_cursor'       => (int) $prepared['hierarchy_root_cursor'],
				'hierarchy_descendant_cursor' => (int) $prepared['hierarchy_descendant_cursor'],
				'hierarchy_root_id'           => (int) $prepared['hierarchy_root_id'],
			];
			++$batches;
		} while ( ! $prepared['done'] && $batches < $max_batches );
		self::assertTrue( $prepared['done'], 'Preparation must complete without a false done.' );

		return $prepared;
	}

	private function save_reparent(
		InMemoryCanonicalLocationRepository|WpdbCanonicalLocationRepository $repo,
		string $key,
		string $name,
		CanonicalLocation $from,
		CanonicalLocation $to,
		string $token,
		?int $live_parent = null,
		int $existing_id = 0
	): CanonicalLocation {
		return $repo->save(
			new CanonicalLocation(
				$existing_id,
				$key,
				'GH',
				$live_parent ?? $from->id,
				GeographyLocationType::Locality,
				null,
				$name,
				strtolower( $name ),
				strtolower( $name ),
				null,
				null,
				RecordStatus::Active,
				'',
				1,
				(string) json_encode(
					[
						'generation_token'   => $token,
						'canonical_name'     => $name,
						'parent_location_id' => $to->id,
					]
				),
				'',
				$token
			)
		);
	}

	private function blank_location(
		string $key,
		string $name,
		?int $parent = null,
		GeographyLocationType $type = GeographyLocationType::Country,
		?int $level = null
	): CanonicalLocation {
		return new CanonicalLocation(
			0,
			$key,
			'GH',
			$parent,
			$type,
			$level,
			$name,
			strtolower( $name ),
			strtolower( $name ),
			null,
			null,
			RecordStatus::Active,
			''
		);
	}

	private function key( string $kind, int $n ): string {
		return sprintf( 'a0000000-0000-4000-8%03s-%012d', substr( $kind, 0, 3 ), $n );
	}

	private function geonames_row( string $id, string $name, string $class, string $code, string $country ): string {
		$parts        = array_fill( 0, 19, '' );
		$parts[0]     = $id;
		$parts[1]     = $name;
		$parts[2]     = $name;
		$parts[6]     = $class;
		$parts[7]     = $code;
		$parts[8]     = $country;
		$parts[18]    = '2024-01-01';

		return implode( "\t", $parts );
	}
}

final class Geo12AlwaysFailCasWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public function update( mixed ...$unused ): int {
		unset( $unused );

		return 0;
	}

	public function get_var( mixed ...$unused ): string {
		unset( $unused );

		return 'wp_delivery_engine_destination_coverage_groups';
	}

	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );

		return $query;
	}

	public function create_table( string $name, array $unique = [] ): void {
		unset( $name, $unique );
	}
}
