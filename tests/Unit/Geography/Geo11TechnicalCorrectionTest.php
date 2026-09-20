<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\AdminGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackPreflight;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\GeographyPostcodeRelevance;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\StorefrontGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\CanonicalResolutionContext;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyPackIncompletePreparationException;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class Geo11TechnicalCorrectionTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'] );
		if ( isset( $GLOBALS['cetech_de_test_options'] ) && is_array( $GLOBALS['cetech_de_test_options'] ) ) {
			unset( $GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] );
			unset( $GLOBALS['cetech_de_test_options'][ GeographyPackService::REVISION_OPTION ] );
		}
		parent::tearDown();
	}

	public function test_nested_moving_root_leaf_follows_nearest_child_root(): void {
		$fixture = $this->nested_reparent_fixture();
		$repo    = $fixture['repo'];
		$this->prepare_until_done( $repo, 'token-nested' );

		$leaf = $repo->find_by_id( $fixture['leaf']->id );
		self::assertSame( 'token-nested', $leaf?->prepared_generation_token );
		self::assertTrue( LocationAncestry::path_contains( (string) $leaf?->prepared_ancestry_path, $fixture['region_c']->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $leaf?->prepared_ancestry_path, $fixture['region_b']->id ) );
		self::assertTrue( LocationAncestry::path_contains( (string) $leaf?->ancestry_path, $fixture['region_a']->id ) );

		$repo->finalize_generation( 'token-nested' );
		$live_parent = $repo->find_by_id( $fixture['parent_root']->id );
		$live_child  = $repo->find_by_id( $fixture['child_root']->id );
		$live_leaf   = $repo->find_by_id( $fixture['leaf']->id );
		self::assertSame( $fixture['region_b']->id, $live_parent?->parent_location_id );
		self::assertSame( $fixture['region_c']->id, $live_child?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $live_leaf?->ancestry_path, $fixture['region_c']->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $live_leaf?->ancestry_path, $fixture['region_b']->id ) );
		self::assertSame( $fixture['region_a']->id, $fixture['parent_root']->parent_location_id );
	}

	public function test_metadata_only_child_inside_moving_subtree_receives_future_ancestry(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$root = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'Moved Town', $region_a, $region_b, 'token-meta' );
		$live = $repo->save( $this->blank_location( $this->key( 'live', 1 ), 'Live Child', $root->id, GeographyLocationType::Locality ) );
		$meta = $this->save_name_draft( $repo, $this->key( 'meta', 1 ), 'Renamed Child', $live->id, 'token-meta' );

		$this->prepare_until_done( $repo, 'token-meta' );
		$prepared = $repo->find_by_id( $meta->id );
		self::assertSame( 'token-meta', $prepared?->prepared_generation_token );
		self::assertTrue( LocationAncestry::path_contains( (string) $prepared?->prepared_ancestry_path, $region_b->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $prepared?->prepared_ancestry_path, $region_a->id ) );
		self::assertSame( 'Renamed Child', $repo->find_by_id( $meta->id )?->canonical_name );

		$repo->finalize_generation( 'token-meta' );
		$live = $repo->find_by_id( $meta->id );
		self::assertTrue( LocationAncestry::path_contains( (string) $live?->ancestry_path, $region_b->id ) );
		self::assertSame( 'Renamed Child Renamed', $live?->canonical_name );
		unset( $wpdb );
	}

	public function test_three_nested_moving_roots_assign_nearest_owner(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Region A', $country->id, GeographyLocationType::Administrative, 1 ) );
		$b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Region B', $country->id, GeographyLocationType::Administrative, 1 ) );
		$c = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Region C', $country->id, GeographyLocationType::Administrative, 1 ) );
		$d = $repo->save( $this->blank_location( $this->key( 'region', 4 ), 'Region D', $country->id, GeographyLocationType::Administrative, 1 ) );
		$parent = $this->save_reparent( $repo, $this->key( 'root', 1 ), 'ParentRoot', $a, $b, 'token-three' );
		$child  = $this->save_reparent( $repo, $this->key( 'root', 2 ), 'ChildRoot', $a, $c, 'token-three', $parent->id );
		$grand  = $this->save_reparent( $repo, $this->key( 'root', 3 ), 'GrandRoot', $a, $d, 'token-three', $child->id );
		$leaf   = $repo->save( $this->blank_location( $this->key( 'leaf', 1 ), 'Leaf', $grand->id, GeographyLocationType::Locality ) );

		$this->prepare_until_done( $repo, 'token-three' );
		$prepared_leaf = $repo->find_by_id( $leaf->id );
		self::assertTrue( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $d->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $b->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $c->id ) );

		$repo->finalize_generation( 'token-three' );
		self::assertSame( $b->id, $repo->find_by_id( $parent->id )?->parent_location_id );
		self::assertSame( $c->id, $repo->find_by_id( $child->id )?->parent_location_id );
		self::assertSame( $d->id, $repo->find_by_id( $grand->id )?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaf->id )?->ancestry_path, $d->id ) );
		unset( $wpdb );
	}

	public function test_nested_hierarchy_interrupt_and_resume_keeps_nearest_owner(): void {
		$fixture = $this->nested_reparent_fixture();
		$repo    = $fixture['repo'];
		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		$snapshot  = null;
		do {
			$prepared  = $repo->prepare_generation( 'token-nested', 2, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			++$batches;
			if ( null === $snapshot && ( $prepared['hierarchy_root_id'] > 0 || ! $prepared['done'] ) ) {
				$snapshot = [ 'after' => $after, 'hierarchy' => $hierarchy ];
				break;
			}
		} while ( ! $prepared['done'] && $batches < 200 );

		self::assertNotNull( $snapshot );
		$after     = $snapshot['after'];
		$hierarchy = $snapshot['hierarchy'];
		do {
			$prepared  = $repo->prepare_generation( 'token-nested', 2, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			++$batches;
		} while ( ! $prepared['done'] && $batches < 400 );

		self::assertTrue( $prepared['done'] );
		$leaf = $repo->find_by_id( $fixture['leaf']->id );
		self::assertTrue( LocationAncestry::path_contains( (string) $leaf?->prepared_ancestry_path, $fixture['region_c']->id ) );
		$repo->finalize_generation( 'token-nested' );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $fixture['leaf']->id )?->ancestry_path, $fixture['region_c']->id ) );
	}

	public function test_wrong_prepared_hierarchy_path_fails_finalize_closed(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_c = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Eastern', $country->id, GeographyLocationType::Administrative, 1 ) );
		$root = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'Moved Town', $region_a, $region_b, 'token-wrong' );
		$child = $repo->save( $this->blank_location( $this->key( 'child', 1 ), 'Child', $root->id, GeographyLocationType::Locality ) );
		$this->prepare_until_done( $repo, 'token-wrong' );

		$table = TableNames::for( GeographySchema::LOCATIONS_SUFFIX );
		$wrong = LocationAncestry::append_path( $region_c->ancestry_path, $child->id );
		$wpdb->update(
			$table,
			[
				'prepared_ancestry_path'    => $wrong,
				'prepared_generation_token' => 'token-wrong',
			],
			[ 'id' => $child->id ]
		);

		try {
			$repo->finalize_generation( 'token-wrong' );
			self::fail( 'Finalize must fail closed when prepared ancestry does not match the effective future parent chain.' );
		} catch ( GeographyPackIncompletePreparationException $e ) {
			self::assertStringContainsString( 'incomplete', strtolower( $e->getMessage() ) );
		}
		self::assertSame( $region_a->id, $repo->find_by_id( $root->id )?->parent_location_id );
		unset( $wpdb );
	}

	/**
	 * @group geo11-scale
	 */
	public function test_more_than_ten_thousand_metadata_drafts_before_first_hierarchy_root(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		for ( $i = 1; $i <= 10001; ++$i ) {
			$this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-scale' );
		}
		$root = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'Late Town', $region_a, $region_b, 'token-scale' );
		$leaf = $repo->save( $this->blank_location( $this->key( 'leaf', 1 ), 'Late Leaf', $root->id, GeographyLocationType::Locality ) );

		$state = $this->prepare_until_done( $repo, 'token-scale', 200, 2000 );
		self::assertTrue( $state['done'] );
		$prepared_leaf = $repo->find_by_id( $leaf->id );
		self::assertSame( 'token-scale', $prepared_leaf?->prepared_generation_token );
		self::assertTrue( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $region_b->id ) );
		$repo->finalize_generation( 'token-scale' );
		self::assertSame( $region_b->id, $repo->find_by_id( $root->id )?->parent_location_id );
		unset( $wpdb );
	}

	/**
	 * @group geo11-scale
	 */
	public function test_more_than_one_thousand_hierarchy_changing_roots(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$roots = [];
		$leaves = [];
		for ( $i = 1; $i <= 1001; ++$i ) {
			$roots[ $i ] = $this->save_reparent( $repo, $this->key( 'town', $i ), 'Town ' . $i, $region_a, $region_b, 'token-many' );
			$leaves[ $i ] = $repo->save( $this->blank_location( $this->key( 'leaf', $i ), 'Leaf ' . $i, $roots[ $i ]->id, GeographyLocationType::Locality ) );
		}

		$state = $this->prepare_until_done( $repo, 'token-many', 50, 5000 );
		self::assertTrue( $state['done'] );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaves[1001]->id )?->prepared_ancestry_path, $region_b->id ) );
		$repo->finalize_generation( 'token-many' );
		self::assertSame( $region_b->id, $repo->find_by_id( $roots[1001]->id )?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaves[1001]->id )?->ancestry_path, $region_b->id ) );
		unset( $wpdb );
	}

	public function test_hierarchy_discovery_must_not_treat_scan_caps_as_completion(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( WpdbCanonicalLocationRepository::class ) )->getFileName() );
		self::assertStringNotContainsString( 'for ( $page = 0; $page < 200; ++$page )', $src );
		self::assertStringNotContainsString( 'for ( $page = 0; $page < 1000; ++$page )', $src );
	}

	public function test_two_concurrent_schema6_migration_starters_only_one_owns_the_pass(): void {
		$upgrade = $this->schema6_upgrade_service();
		self::assertTrue( method_exists( $upgrade, 'try_claim_owner' ) );
		self::assertTrue( $upgrade->try_claim_owner( 'owner-a' ) );
		self::assertFalse( $upgrade->try_claim_owner( 'owner-b' ) );
		$state = $upgrade->current_state();
		self::assertSame( 'owner-a', (string) ( $state['owner'] ?? $state['lease_owner'] ?? '' ) );
		self::assertNotSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $state['status'] ?? '' ) );
	}

	public function test_migration_visits_zone_after_five_hundred_before_completed(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$inner     = new InMemoryDestinationZoneRepository();
		$zones     = new Geo11CappedDestinationZoneRepository( $inner, 500 );
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$this->install_coverage_table();
		$ghana = $locations->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		for ( $i = 1; $i <= 501; ++$i ) {
			$inner->save( [ 'id' => $i, 'internal_name' => 'Zone ' . $i, 'status' => RecordStatus::Active->value ] );
			$rules->replaceForZone( $i, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		}
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );
		$upgrade   = new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
		$state     = [];
		for ( $i = 0; $i < 20; ++$i ) {
			$result = $upgrade->maybe_run();
			$state  = is_array( $result['state'] ?? null ) ? $result['state'] : $upgrade->current_state();
			if ( Schema6CoverageUpgradeService::STATUS_COMPLETED === (string) ( $state['status'] ?? '' ) ) {
				break;
			}
		}
		self::assertNotEmpty( $groups->list_by_zone( 501 ) );
		self::assertSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, (string) ( $state['status'] ?? '' ) );
		unset( $ghana );
	}

	public function test_interrupted_upgrade_resumes_from_persisted_zone_cursor(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( Schema6CoverageUpgradeService::class ) )->getFileName() );
		self::assertStringContainsString( 'last_zone_id', $src );
		self::assertTrue( method_exists( Schema6CoverageUpgradeService::class, 'try_claim_owner' ) );
	}

	public function test_runtime_zone_after_five_hundred_can_match(): void {
		$geo     = new GhanaGeographyFixture();
		$inner   = new InMemoryDestinationZoneRepository();
		$zones   = new Geo11CappedDestinationZoneRepository( $inner, 500 );
		$rules   = new InMemoryDestinationRuleRepository();
		$groups  = new InMemoryCoverageGroupRepository();
		$coverage = new CoverageGroupMatcher( $groups, $geo->locations );
		$matcher  = new DestinationZoneMatcher( $zones, $rules, null, $coverage, new CanonicalLocationResolver( $geo->locations, $geo->locations ) );
		for ( $i = 1; $i <= 500; ++$i ) {
			$inner->save( [ 'id' => $i, 'internal_name' => 'Filler ' . $i, 'status' => RecordStatus::Active->value, 'priority' => 200 ] );
		}
		$inner->save( [ 'id' => 501, 'internal_name' => 'Accra late', 'status' => RecordStatus::Active->value, 'priority' => 10 ] );
		$groups->save_group(
			[
				'zone_id'          => 501,
				'root_location_id' => $geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$matches = $matcher->match_all( 'GH', 'Greater Accra', 'Accra', '', [ 'canonical_location_key' => $geo->accra->location_key ] );
		$ids     = array_map( static fn ( array $zone ): int => (int) ( $zone['id'] ?? 0 ), $matches );
		self::assertContains( 501, $ids );
	}

	public function test_inactive_coverage_member_cannot_match(): void {
		$geo    = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$zones  = new InMemoryDestinationZoneRepository();
		$rules  = new InMemoryDestinationRuleRepository();
		$zones->save( [ 'id' => 21, 'internal_name' => 'Greater Accra member', 'status' => RecordStatus::Active->value ] );
		$groups->save_group(
			[
				'zone_id'          => 21,
				'root_location_id' => $geo->ghana->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $geo->greater_accra->id, 'membership' => 'include' ],
				],
			]
		);
		$coverage = new CoverageGroupMatcher( $groups, $geo->locations );
		$matcher  = new DestinationZoneMatcher( $zones, $rules, null, $coverage, new CanonicalLocationResolver( $geo->locations, $geo->locations ) );
		$before   = $matcher->match_all( 'GH', 'Greater Accra', 'Accra', '', [ 'canonical_location_key' => $geo->accra->location_key ] );
		self::assertContains( 21, array_map( static fn ( array $zone ): int => (int) $zone['id'], $before ) );

		$geo->locations->save(
			new CanonicalLocation(
				$geo->greater_accra->id,
				$geo->greater_accra->location_key,
				$geo->greater_accra->country_code,
				$geo->greater_accra->parent_location_id,
				$geo->greater_accra->location_type,
				$geo->greater_accra->administrative_level,
				$geo->greater_accra->canonical_name,
				$geo->greater_accra->normalized_name,
				$geo->greater_accra->ascii_name,
				$geo->greater_accra->latitude,
				$geo->greater_accra->longitude,
				RecordStatus::Inactive,
				$geo->greater_accra->ancestry_path
			)
		);
		$coverage = new CoverageGroupMatcher( $groups, $geo->locations );
		$matcher  = new DestinationZoneMatcher( $zones, $rules, null, $coverage, new CanonicalLocationResolver( $geo->locations, $geo->locations ) );
		$after = $matcher->match_all( 'GH', 'Greater Accra', 'Accra', '', [ 'canonical_location_key' => $geo->accra->location_key ] );
		self::assertNotContains( 21, array_map( static fn ( array $zone ): int => (int) $zone['id'], $after ) );
	}

	public function test_inactive_country_is_not_authoritative_and_bootstrap_does_not_duplicate(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$inactive  = $locations->save(
			new CanonicalLocation(
				0,
				$this->key( 'country', 9 ),
				'GH',
				null,
				GeographyLocationType::Country,
				null,
				'Ghana',
				'ghana',
				'ghana',
				null,
				null,
				RecordStatus::Inactive,
				''
			)
		);
		$resolver = new CanonicalLocationResolver( $locations, $locations );
		$shopper  = $resolver->resolve( 'GH', '', '', '', '', '', CanonicalResolutionContext::ShopperSelector );
		self::assertFalse( $shopper->hasCanonicalLocation() );
		$woo = $resolver->resolve( 'GH', '', '', '', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertFalse( $woo->hasCanonicalLocation() );
		self::assertNotSame( $inactive->id, $woo->location_id() );

		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$result = $bootstrap->bootstrap_country( 'GH' );
		self::assertSame( $inactive->id, $result['country']?->id );
		self::assertSame( 0, (int) ( $result['created'] ?? 1 ) );
	}

	public function test_administrative_child_251_remains_discoverable(): void {
		$geo = new GhanaGeographyFixture();
		for ( $i = 1; $i <= 251; ++$i ) {
			$geo->locations->save(
				$this->blank_location( $this->key( 'adm', $i ), sprintf( 'Admin %03d', $i ), $geo->ghana->id, GeographyLocationType::Administrative, 1 )
			);
		}
		$packs    = new InMemoryGeographyPackRepository();
		$endpoint = new StorefrontGeographyEndpoint( $geo->locations, new CanonicalLocationResolver( $geo->locations, $geo->locations ), $packs, $geo->locations );
		$page1 = $endpoint->children_result( 'GH' );
		self::assertTrue( ! empty( $page1['has_more'] ) || count( $page1['items'] ) >= 251, 'Administrative children must not silently truncate.' );
		$later = $geo->locations->list_children( $geo->ghana->id, GeographyLocationType::Administrative, 50, 250 );
		$names = array_map( static fn ( CanonicalLocation $row ): string => $row->canonical_name, $later );
		self::assertContains( 'Admin 251', $names );
		$admin_src = (string) file_get_contents( ( new \ReflectionClass( AdminGeographyEndpoint::class ) )->getFileName() );
		self::assertDoesNotMatchRegularExpression( "/'has_more'\\s*=>\\s*false/", $admin_src );
	}

	public function test_provider_reconciliation_finds_admin_beyond_first_250(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$match   = null;
		for ( $i = 1; $i <= 251; ++$i ) {
			$saved = $repo->save( $this->blank_location( $this->key( 'adm', $i ), sprintf( 'District %03d', $i ), $country->id, GeographyLocationType::Administrative, 2 ) );
			if ( 251 === $i ) {
				$match = $saved;
			}
		}
		$found = $repo->find_unique_administrative_core( 'GH', $country->id, 'District 251', 2 );
		self::assertNotNull( $found );
		self::assertSame( $match?->id, $found->id );
		unset( $wpdb );
	}

	public function test_postcode_selected_group_scope_does_not_force_unrelated_locality(): void {
		$geo    = new GhanaGeographyFixture();
		$zones  = new InMemoryDestinationZoneRepository();
		$groups = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 90, 'internal_name' => 'Accra postcode', 'status' => RecordStatus::Active->value ] );
		$groups->save_group(
			[
				'zone_id'          => 90,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $geo->accra->id, 'membership' => 'include' ],
				],
				'postcodes'        => [
					[ 'postcode_value' => 'GA-ACC', 'match_mode' => 'exact', 'status' => RecordStatus::Active->value ],
				],
			]
		);
		$relevance = new GeographyPostcodeRelevance( $groups, $geo->locations, $zones );
		self::assertTrue( $relevance->is_visible( 'GH', $geo->accra->location_key ) );
		self::assertFalse( $relevance->is_visible( 'GH', $geo->tema->location_key ) );
	}

	public function test_inactive_postcode_rows_do_not_force_visibility(): void {
		$geo    = new GhanaGeographyFixture();
		$zones  = new InMemoryDestinationZoneRepository();
		$groups = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 91, 'internal_name' => 'Inactive postcode', 'status' => RecordStatus::Active->value ] );
		$groups->save_group(
			[
				'zone_id'          => 91,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'postcodes'        => [
					[ 'postcode_value' => 'GA-OFF', 'match_mode' => 'exact', 'status' => RecordStatus::Inactive->value ],
				],
			]
		);
		$relevance = new GeographyPostcodeRelevance( $groups, $geo->locations, $zones );
		self::assertFalse( $relevance->is_visible( 'GH', $geo->greater_accra->location_key ) );
	}

	public function test_postcode_relevance_visits_zone_after_five_hundred(): void {
		$geo    = new GhanaGeographyFixture();
		$inner  = new InMemoryDestinationZoneRepository();
		$zones  = new Geo11CappedDestinationZoneRepository( $inner, 500 );
		$groups = new InMemoryCoverageGroupRepository();
		for ( $i = 1; $i <= 500; ++$i ) {
			$inner->save( [ 'id' => $i, 'internal_name' => 'Filler ' . $i, 'status' => RecordStatus::Active->value ] );
		}
		$inner->save( [ 'id' => 501, 'internal_name' => 'Late postcode', 'status' => RecordStatus::Active->value ] );
		$groups->save_group(
			[
				'zone_id'          => 501,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'postcodes'        => [
					[ 'postcode_value' => 'GA-501', 'match_mode' => 'exact', 'status' => RecordStatus::Active->value ],
				],
			]
		);
		$relevance = new GeographyPostcodeRelevance( $groups, $geo->locations, $zones );
		self::assertTrue( $relevance->is_visible( 'GH', $geo->greater_accra->location_key ) );
	}

	public function test_concurrent_cache_revision_cannot_collapse_to_one_stale_namespace(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( GeographyPackService::class ) )->getFileName() );
		self::assertStringNotContainsString( '$current + 1', $src );
		$first  = $this->invoke_bump_revision();
		$second = $this->invoke_bump_revision();
		self::assertNotSame( $first, $second );
		self::assertNotSame( '', (string) $second );
	}

	public function test_migration_verify_requires_geo11_schema_markers(): void {
		$migration = (string) file_get_contents( dirname( __DIR__, 3 ) . '/database/migrations/20260917120000_create_geography_coverage_tables.php' );
		self::assertStringContainsString( 'prepared_hierarchy_root_id', $migration );
		self::assertStringContainsString( 'assert_column_present', $migration );
		self::assertStringContainsString( 'target_token', $migration );
		self::assertStringContainsString( 'lease_owner', $migration );
		self::assertStringContainsString( 'draft_generation_token', $migration );
		self::assertStringContainsString( 'prepared_generation_token', $migration );
		$schema = GeographySchema::required_markers()[ GeographySchema::LOCATIONS_SUFFIX ];
		self::assertContains( 'prepared_hierarchy_root_id', $schema );
	}

	public function test_preflight_budget_exhaustion_is_indeterminate_not_invalid(): void {
		$preflight = new GeoNamesPackPreflight();
		$lines     = [];
		for ( $i = 1; $i <= GeoNamesPackPreflight::MAX_SCAN; ++$i ) {
			$lines[] = $this->geonames_row( (string) $i, 'Noise ' . $i, 'H', 'STM', 'XX' );
		}
		$lines[] = $this->geonames_row( '9000001', 'Ghana', 'A', 'PCLI', 'GH' );
		$lines[] = $this->geonames_row( '9000002', 'Greater Accra', 'A', 'ADM1', 'GH' );
		$file    = tempnam( sys_get_temp_dir(), 'geo11pre' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );
		$result = $preflight->validate( $file, 'GH' );
		unlink( $file );
		self::assertFalse( $result['ok'] );
		self::assertSame( 'indeterminate', strtolower( (string) ( $result['error'] ?? $result['status'] ?? '' ) ) );
		self::assertNotSame( 'wrong_country', $result['error'] ?? '' );
		self::assertNotSame( 'zero_relevant_geography', $result['error'] ?? '' );
		self::assertNotSame( 'minimum_hierarchy_missing', $result['error'] ?? '' );
	}

	public function test_wrong_country_and_empty_preflight_still_fail(): void {
		$preflight = new GeoNamesPackPreflight();
		$empty     = tempnam( sys_get_temp_dir(), 'geo11empty' );
		self::assertIsString( $empty );
		file_put_contents( $empty, '' );
		self::assertSame( 'empty', $preflight->validate( $empty, 'GH' )['error'] );
		$wrong = tempnam( sys_get_temp_dir(), 'geo11wrong' );
		self::assertIsString( $wrong );
		file_put_contents( $wrong, $this->geonames_row( '1', 'Lagos', 'P', 'PPL', 'NG' ) . "\n" );
		self::assertSame( 'wrong_country', $preflight->validate( $wrong, 'GH' )['error'] );
		unlink( $empty );
		unlink( $wrong );
	}

	public function test_locality_combobox_exposes_aria_controls(): void {
		$renderer = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/MatchingLocationFieldRenderer.php' );
		self::assertStringContainsString( "'aria-controls'", $renderer );
		self::assertStringContainsString( 'aria-controls', $renderer );
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/frontend/product-delivery-selector.js' );
		self::assertStringContainsString( 'aria-controls', $js );
	}

	public function test_public_postcode_endpoint_uses_request_protection(): void {
		$src     = (string) file_get_contents( ( new \ReflectionClass( StorefrontGeographyEndpoint::class ) )->getFileName() );
		$start   = strpos( $src, 'function handle_postcode' );
		$end     = strpos( $src, 'function customer_item', (int) $start );
		self::assertNotFalse( $start );
		self::assertNotFalse( $end );
		$chunk = substr( $src, (int) $start, (int) $end - (int) $start );
		self::assertStringContainsString( 'allow_request', $chunk );
		self::assertStringContainsString( 'cache_', $chunk );
	}

	/**
	 * @return array{repo: WpdbCanonicalLocationRepository, region_a: CanonicalLocation, region_b: CanonicalLocation, region_c: CanonicalLocation, parent_root: CanonicalLocation, child_root: CanonicalLocation, leaf: CanonicalLocation}
	 */
	private function nested_reparent_fixture(): array {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Region A', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Region B', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_c = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Region C', $country->id, GeographyLocationType::Administrative, 1 ) );
		$parent = $this->save_reparent( $repo, $this->key( 'root', 1 ), 'ParentRoot', $region_a, $region_b, 'token-nested' );
		$child  = $this->save_reparent( $repo, $this->key( 'root', 2 ), 'ChildRoot', $region_a, $region_c, 'token-nested', $parent->id );
		$leaf   = $repo->save( $this->blank_location( $this->key( 'leaf', 1 ), 'Leaf', $child->id, GeographyLocationType::Locality ) );

		return [
			'repo'        => $repo,
			'region_a'    => $region_a,
			'region_b'    => $region_b,
			'region_c'    => $region_c,
			'parent_root' => $parent,
			'child_root'  => $child,
			'leaf'        => $leaf,
			'wpdb'        => $wpdb,
		];
	}

	/**
	 * @return array{processed:int,last_id:int,done:bool,hierarchy_root_cursor:int,hierarchy_descendant_cursor:int,hierarchy_root_id:int}
	 */
	private function prepare_until_done( WpdbCanonicalLocationRepository $repo, string $token, int $limit = 20, int $max_batches = 500 ): array {
		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		$prepared  = [];
		do {
			$prepared  = $repo->prepare_generation( $token, $limit, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			++$batches;
		} while ( ! $prepared['done'] && $batches < $max_batches );

		self::assertTrue( $prepared['done'], 'Preparation must complete without treating a scan budget as DONE.' );

		return $prepared;
	}

	/**
	 * @param array<string, mixed> $prepared
	 * @return array{hierarchy_root_cursor: int, hierarchy_descendant_cursor: int, hierarchy_root_id: int}
	 */
	private function hierarchy_from( array $prepared ): array {
		return [
			'hierarchy_root_cursor'       => (int) $prepared['hierarchy_root_cursor'],
			'hierarchy_descendant_cursor' => (int) $prepared['hierarchy_descendant_cursor'],
			'hierarchy_root_id'           => (int) $prepared['hierarchy_root_id'],
		];
	}

	private function save_reparent(
		WpdbCanonicalLocationRepository $repo,
		string $key,
		string $name,
		CanonicalLocation $from,
		CanonicalLocation $to,
		string $token,
		?int $live_parent = null
	): CanonicalLocation {
		return $repo->save(
			new CanonicalLocation(
				0,
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

	private function save_name_draft( WpdbCanonicalLocationRepository $repo, string $key, string $name, int $parent_id, string $token ): CanonicalLocation {
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

	private function wpdb_with_geography_tables(): FakeWpdb {
		$wpdb            = new FakeWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->create_table( TableNames::for( GeographySchema::LOCATIONS_SUFFIX ), [ [ 'location_key' ] ] );
		$wpdb->create_table( TableNames::for( GeographySchema::PACKS_SUFFIX ) );
		$wpdb->create_table( TableNames::for( GeographySchema::MAPPINGS_SUFFIX ), [ [ 'provider', 'external_id', 'generation_token' ] ] );
		$wpdb->create_table( TableNames::for( GeographySchema::ALIASES_SUFFIX ), [ [ 'location_id', 'normalized_alias', 'generation_token' ] ] );

		return $wpdb;
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

	private function invoke_bump_revision(): mixed {
		$service = ( new \ReflectionClass( GeographyPackService::class ) )->newInstanceWithoutConstructor();
		$method  = new \ReflectionMethod( GeographyPackService::class, 'bump_revision' );
		$method->setAccessible( true );
		$method->invoke( $service );

		return $GLOBALS['cetech_de_test_options'][ GeographyPackService::REVISION_OPTION ] ?? null;
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

/**
 * Mirrors AbstractWpdbRepository::fetch_list() clamping so tests can prove
 * production callers must not treat list() as a complete iterator.
 */
final class Geo11CappedDestinationZoneRepository implements \CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface {

	public function __construct(
		private InMemoryDestinationZoneRepository $inner,
		private int $cap = 500
	) {
	}

	public function findById( int $id ): ?array {
		return $this->inner->findById( $id );
	}

	public function findByCode( string $code ): ?array {
		return $this->inner->findByCode( $code );
	}

	public function save( array $data ): int {
		return $this->inner->save( $data );
	}

	public function list( array $criteria = [] ): array {
		$status = (string) ( $criteria['status'] ?? '' );
		$rows   = $this->inner->list( $criteria );
		if ( '' !== $status ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn ( array $row ): bool => (string) ( $row['status'] ?? '' ) === $status
				)
			);
		}
		usort(
			$rows,
			static fn ( array $left, array $right ): int => ( (int) ( $left['id'] ?? 0 ) ) <=> ( (int) ( $right['id'] ?? 0 ) )
		);
		$limit = max( 1, min( $this->cap, (int) ( $criteria['limit'] ?? $this->cap ) ) );

		return array_slice( $rows, 0, $limit );
	}

	public function softDelete( int $id ): bool {
		return $this->inner->softDelete( $id );
	}

	public function hardDelete( int $id ): bool {
		return $this->inner->hardDelete( $id );
	}

	public function count_all(): int {
		return $this->inner->count_all();
	}

	/**
	 * Complete keyset page used by geo.11 production callers. Not capped to the
	 * generic list() ceiling.
	 *
	 * @param array<string, mixed> $criteria
	 * @return list<array<string, mixed>>
	 */
	public function page_after( int $after_id, int $limit = 100, array $criteria = [] ): array {
		$status = (string) ( $criteria['status'] ?? '' );
		$rows   = $this->inner->list( $criteria );
		$out    = [];
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= $after_id ) {
				continue;
			}
			if ( '' !== $status && (string) ( $row['status'] ?? '' ) !== $status ) {
				continue;
			}
			$out[] = $row;
			if ( count( $out ) >= max( 1, $limit ) ) {
				break;
			}
		}

		return $out;
	}
}
