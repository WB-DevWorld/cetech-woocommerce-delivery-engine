<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\CanonicalResolutionContext;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyPackIncompletePreparationException;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Issue #23 geo.13 bounded technical correction.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013, DE-PERF-002.
 */
final class Geo13TechnicalCorrectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'], $GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] );
		parent::tearDown();
	}

	public function test_stale_worker_is_fenced_after_successor_takeover(): void {
		$upgrade = $this->schema6_upgrade_service();
		self::assertTrue( $upgrade->try_claim_owner( 'worker-A' ) );
		$state = $upgrade->current_state();
		$pass  = (string) ( $state['pass_id'] ?? '' );
		self::assertSame( 'worker-A', (string) ( $state['worker'] ?? '' ) );
		self::assertNotSame( '', $pass );

		$state['worker_expires_at'] = time() - 1;
		$state['lease_expires_at']  = time() + 180;
		update_option( Schema6CoverageUpgradeService::OPTION_KEY, $state, false );

		self::assertTrue( $this->invoke( $upgrade, 'renew_owner_lease', [ 'worker-A', $pass ] ) );

		$state                      = $upgrade->current_state();
		$state['worker_expires_at'] = time() - 1;
		$state['lease_expires_at']  = time() + 180;
		update_option( Schema6CoverageUpgradeService::OPTION_KEY, $state, false );

		$claimed = $this->invoke( $upgrade, 'acquire_worker', [] );
		self::assertIsArray( $claimed );
		$worker_b = (string) ( $claimed['owner'] ?? '' );
		self::assertNotSame( '', $worker_b );
		self::assertNotSame( 'worker-A', $worker_b );
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );
		self::assertSame( 'worker-A', (string) ( $upgrade->current_state()['owner'] ?? '' ) );

		self::assertFalse( $this->invoke( $upgrade, 'renew_owner_lease', [ 'worker-A', $pass ] ) );
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );

		$stolen = array_merge(
			$upgrade->current_state(),
			[
				'last_zone_id' => 1,
				'status'       => Schema6CoverageUpgradeService::STATUS_COMPLETED,
				'worker'       => 'worker-A',
			]
		);
		self::assertFalse( $this->invoke( $upgrade, 'owner_store', [ 'worker-A', $pass, $stolen ] ) );
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $upgrade->current_state()['status'] ?? '' ) );

		self::assertTrue( $this->invoke( $upgrade, 'renew_owner_lease', [ $worker_b, $pass ] ) );
		$advanced = array_merge( $upgrade->current_state(), [ 'last_zone_id' => 50 ] );
		self::assertTrue( $this->invoke( $upgrade, 'owner_store', [ $worker_b, $pass, $advanced ] ) );
		self::assertSame( 50, (int) ( $upgrade->current_state()['last_zone_id'] ?? 0 ) );

		$overwrite = array_merge(
			$upgrade->current_state(),
			[
				'last_zone_id' => 1,
				'status'       => Schema6CoverageUpgradeService::STATUS_COMPLETED,
				'worker'       => 'worker-A',
			]
		);
		self::assertFalse( $this->invoke( $upgrade, 'owner_store', [ 'worker-A', $pass, $overwrite ] ) );
		self::assertSame( 50, (int) ( $upgrade->current_state()['last_zone_id'] ?? 0 ) );
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $upgrade->current_state()['status'] ?? '' ) );
	}

	public function test_draft_budget_does_not_scan_hierarchy_roots_or_enumerate_all_metadata_drafts(): void {
		$repo     = new InMemoryCanonicalLocationRepository();
		$country  = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		for ( $i = 1; $i <= 300; ++$i ) {
			$this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-bound' );
		}
		$root = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'Late Town', $region_a, $region_b, 'token-bound' );
		$leaf = $repo->save( $this->blank_location( $this->key( 'leaf', 1 ), 'Late Leaf', $root->id, GeographyLocationType::Locality ) );

		$first = $repo->prepare_generation( 'token-bound', 50, 0 );
		self::assertFalse( $first['done'] );
		self::assertSame( 50, $repo->generation_drafts_examined );
		self::assertSame( 0, $repo->prepared_moving_root_pages );

		$state = $this->prepare_until_done( $repo, 'token-bound', 50, 40 );
		self::assertTrue( $state['done'] );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaf->id )?->prepared_ancestry_path, $region_b->id ) );
		$repo->finalize_generation( 'token-bound' );
		self::assertSame( $region_b->id, $repo->find_by_id( $root->id )?->parent_location_id );
	}

	public function test_hierarchy_root_cursor_advances_and_resume_skips_completed_roots(): void {
		$repo     = new InMemoryCanonicalLocationRepository();
		$country  = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$roots    = [];
		$leaves   = [];
		for ( $i = 1; $i <= 6; ++$i ) {
			$roots[ $i ]  = $this->save_reparent( $repo, $this->key( 'town', $i ), 'Town ' . $i, $region_a, $region_b, 'token-cursor' );
			$leaves[ $i ] = $repo->save( $this->blank_location( $this->key( 'leaf', $i ), 'Leaf ' . $i, $roots[ $i ]->id, GeographyLocationType::Locality ) );
		}

		$after      = 0;
		$hierarchy  = [];
		$cursors    = [];
		$batches    = 0;
		$prepared   = [];
		do {
			$prepared   = $repo->prepare_generation( 'token-cursor', 2, $after, $hierarchy );
			$after      = (int) $prepared['last_id'];
			$hierarchy  = [
				'hierarchy_root_cursor'       => (int) $prepared['hierarchy_root_cursor'],
				'hierarchy_descendant_cursor' => (int) $prepared['hierarchy_descendant_cursor'],
				'hierarchy_root_id'           => (int) $prepared['hierarchy_root_id'],
			];
			$cursors[] = (int) $prepared['hierarchy_root_cursor'];
			++$batches;
		} while ( ! $prepared['done'] && $batches < 40 );

		self::assertTrue( $prepared['done'] );
		self::assertGreaterThan( 0, max( $cursors ) );
		$positive_after = array_values( array_filter( $repo->prepared_moving_root_after_ids, static fn ( int $id ): bool => $id > 0 ) );
		self::assertNotSame( [], $positive_after, 'Durable cursor must be passed into later root discovery.' );
		self::assertGreaterThanOrEqual( $roots[1]->id, max( $positive_after ) );

		$resume     = new InMemoryCanonicalLocationRepository();
		$country_r  = $resume->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_ar  = $resume->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country_r->id, GeographyLocationType::Administrative, 1 ) );
		$region_br  = $resume->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country_r->id, GeographyLocationType::Administrative, 1 ) );
		$resume_roots = [];
		for ( $i = 1; $i <= 4; ++$i ) {
			$resume_roots[ $i ] = $this->save_reparent( $resume, $this->key( 'town', $i ), 'Town ' . $i, $region_ar, $region_br, 'token-resume' );
			$resume->save( $this->blank_location( $this->key( 'leaf', $i ), 'Leaf ' . $i, $resume_roots[ $i ]->id, GeographyLocationType::Locality ) );
		}
		$partial = $this->prepare_until( $resume, 'token-resume', 2, 3 );
		self::assertFalse( $partial['done'] );
		self::assertGreaterThan( 0, (int) $partial['hierarchy_root_cursor'] );
		try {
			$resume->finalize_generation( 'token-resume' );
			self::fail( 'Finalize must fail closed before hierarchy work is complete.' );
		} catch ( GeographyPackIncompletePreparationException $e ) {
			self::assertStringContainsString( 'incomplete', strtolower( $e->getMessage() ) );
		}
		$finished = $this->prepare_until_done(
			$resume,
			'token-resume',
			2,
			40,
			(int) $partial['last_id'],
			[
				'hierarchy_root_cursor'       => (int) $partial['hierarchy_root_cursor'],
				'hierarchy_descendant_cursor' => (int) $partial['hierarchy_descendant_cursor'],
				'hierarchy_root_id'           => (int) $partial['hierarchy_root_id'],
			]
		);
		self::assertTrue( $finished['done'] );
		self::assertGreaterThanOrEqual( (int) $partial['hierarchy_root_cursor'], (int) $finished['hierarchy_root_cursor'] );
		$resume->finalize_generation( 'token-resume' );
		self::assertSame( $region_br->id, $resume->find_by_id( $resume_roots[4]->id )?->parent_location_id );
	}

	public function test_ambiguous_or_typo_woo_city_cannot_match_city_specific_coverage(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$country   = $locations->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$adm1      = $locations->save( $this->blank_location( $this->key( 'adm', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$dist_a    = $locations->save( $this->blank_location( $this->key( 'adm', 2 ), 'District A', $adm1->id, GeographyLocationType::Administrative, 2 ) );
		$dist_b    = $locations->save( $this->blank_location( $this->key( 'adm', 3 ), 'District B', $adm1->id, GeographyLocationType::Administrative, 2 ) );
		$accra_a   = $locations->save( $this->blank_location( $this->key( 'loc', 1 ), 'Accra', $dist_a->id, GeographyLocationType::Locality ) );
		$accra_b   = $locations->save( $this->blank_location( $this->key( 'loc', 2 ), 'Accra', $dist_b->id, GeographyLocationType::Locality ) );
		$packs     = new InMemoryGeographyPackRepository();
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
		$groups   = new InMemoryCoverageGroupRepository();
		$groups->save_group(
			[
				'zone_id'          => 22,
				'root_location_id' => $accra_a->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $accra_a->id, 'membership' => CoverageMembership::Include->value ],
				],
			]
		);
		$zones = new InMemoryDestinationZoneRepository();
		$zones->save(
			[
				'id'            => 22,
				'internal_name' => 'Accra A only',
				'status'        => RecordStatus::Active->value,
				'priority'      => 200,
			]
		);
		$zones->save(
			[
				'id'            => 23,
				'internal_name' => 'Ghana country',
				'status'        => RecordStatus::Active->value,
				'priority'      => 10,
			]
		);
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			23,
			[ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ]
		);
		$coverage = new CoverageGroupMatcher( $groups, $locations );
		$matcher  = new DestinationZoneMatcher( $zones, $rules, null, $coverage, $resolver );

		$ambiguous = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Accra', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertNotSame( $accra_a->id, $ambiguous->location_id() );
		self::assertNotSame( $accra_b->id, $ambiguous->location_id() );
		$city_specific = $coverage->match_zone( 22, $ambiguous );
		self::assertFalse( $city_specific['matched'] );

		$typo = $resolver->resolve( 'GH', 'GA', 'Greater Accra', 'Acccra', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertFalse( $coverage->match_zone( 22, $typo )['matched'] );

		$ambiguous_zones = $this->matched_zone_ids( $matcher->match_all( 'GH', 'GA', 'Accra', '' ) );
		self::assertNotContains( 22, $ambiguous_zones );
		self::assertContains( 23, $ambiguous_zones );

		$typo_zones = $this->matched_zone_ids( $matcher->match_all( 'GH', 'GA', 'Acccra', '' ) );
		self::assertNotContains( 22, $typo_zones );
		self::assertContains( 23, $typo_zones );

		$keyed = $this->matched_zone_ids(
			$matcher->match_all(
				'GH',
				'GA',
				'Accra',
				'',
				[
					'canonical_location_key' => $accra_a->location_key,
					'state_label'            => 'Greater Accra',
				]
			)
		);
		self::assertContains( 22, $keyed );
	}

	/**
	 * @param list<array<string, mixed>> $zones
	 * @return list<int>
	 */
	private function matched_zone_ids( array $zones ): array {
		$ids = [];
		foreach ( $zones as $zone ) {
			$ids[] = (int) ( $zone['id'] ?? 0 );
		}

		return $ids;
	}

	/**
	 * @param list<mixed> $args
	 */
	private function invoke( object $object, string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( $object, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $object, $args );
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

	/**
	 * @param array{hierarchy_root_cursor?:int,hierarchy_descendant_cursor?:int,hierarchy_root_id?:int} $hierarchy
	 * @return array{processed:int,last_id:int,done:bool,hierarchy_root_cursor:int,hierarchy_descendant_cursor:int,hierarchy_root_id:int}
	 */
	private function prepare_until_done(
		InMemoryCanonicalLocationRepository $repo,
		string $token,
		int $limit = 20,
		int $max_batches = 200,
		int $after = 0,
		array $hierarchy = []
	): array {
		$batches  = 0;
		$prepared = [];
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

	/**
	 * @param array{hierarchy_root_cursor?:int,hierarchy_descendant_cursor?:int,hierarchy_root_id?:int} $hierarchy
	 * @return array{processed:int,last_id:int,done:bool,hierarchy_root_cursor:int,hierarchy_descendant_cursor:int,hierarchy_root_id:int}
	 */
	private function prepare_until(
		InMemoryCanonicalLocationRepository $repo,
		string $token,
		int $limit,
		int $max_batches,
		int $after = 0,
		array $hierarchy = []
	): array {
		$batches  = 0;
		$prepared = [];
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

		return $prepared;
	}

	private function save_reparent(
		InMemoryCanonicalLocationRepository $repo,
		string $key,
		string $name,
		CanonicalLocation $from,
		CanonicalLocation $to,
		string $token
	): CanonicalLocation {
		return $repo->save(
			new CanonicalLocation(
				0,
				$key,
				'GH',
				$from->id,
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

	private function save_name_draft(
		InMemoryCanonicalLocationRepository $repo,
		string $key,
		string $name,
		int $parent_id,
		string $token
	): CanonicalLocation {
		return $repo->save(
			new CanonicalLocation(
				0,
				$key,
				'GH',
				$parent_id,
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
						'canonical_name'  => $name . ' Renamed',
						'normalized_name' => strtolower( $name . ' Renamed' ),
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
		return sprintf( 'd0000000-0000-4000-8%03s-%012d', substr( $kind, 0, 3 ), $n );
	}
}
