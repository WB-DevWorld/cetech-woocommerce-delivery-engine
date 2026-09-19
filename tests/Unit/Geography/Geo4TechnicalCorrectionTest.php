<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Coverage\CoverageConfigurationValidator;
use CetechDeliveryEngine\Application\Coverage\CoverageGroupSummarizer;
use CetechDeliveryEngine\Application\Coverage\DeliveryAreaCoverageCleanup;
use CetechDeliveryEngine\Application\Destination\OverlappingDeliveryAreaCoverage;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackPreflight;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\StorefrontGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryQuoteRateCardRepository;
use PHPUnit\Framework\TestCase;

final class Geo4TechnicalCorrectionTest extends TestCase {

	public function test_woo_greater_accra_and_geonames_region_share_one_canonical_adm1(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$file     = $this->gazetteer_file(
			[
				$this->row( '2306104', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2306106', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
				$this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01', '' ),
				$this->row( '2306110', 'Tema', 'Tema', 'P', 'PPL', '01', '' ),
			]
		);

		$pack   = $importer->ensure_pack( 'GH', $file );
		$pack   = $importer->begin_dataset( $pack, $file, hash_file( 'sha256', $file ) ?: 'abc', '2026.geo4' );
		$result = $this->run_import( $importer, $packs, $pack, $file );
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] );

		$adm1 = [];
		foreach ( $geo->locations->list_children( $geo->ghana->id, GeographyLocationType::Administrative, 50, 0 ) as $child ) {
			if ( 1 === $child->administrative_level && str_contains( GeographyNameNormalizer::administrative_core( $child->canonical_name ), 'greater accra' ) ) {
				$adm1[] = $child;
			}
		}
		self::assertCount( 1, $adm1 );
		$canonical = $adm1[0];
		self::assertSame( $geo->greater_accra->id, $canonical->id );
		self::assertSame( $canonical->id, $geo->locations->find_location_id( GeographyProvider::WooCommerce, 'GH:AA' ) );
		self::assertSame( $canonical->id, $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306106' ) );

		$accra_id = $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306108' );
		$tema_id  = $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306110' );
		self::assertNotNull( $accra_id );
		self::assertNotNull( $tema_id );
		$accra = $geo->locations->find_by_id( $accra_id );
		$tema  = $geo->locations->find_by_id( $tema_id );
		self::assertSame( $canonical->id, $accra?->parent_location_id );
		self::assertSame( $canonical->id, $tema?->parent_location_id );
		self::assertTrue( $accra?->isActive() );
		unlink( $file );
	}

	public function test_failed_staged_update_leaves_active_search_unchanged(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$first    = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
				$this->row( '3', 'Accra', 'Accra', 'P', 'PPLC', '01', '' ),
			]
		);
		$pack = $importer->ensure_pack( 'GH', $first );
		$pack = $importer->begin_dataset( $pack, $first, hash_file( 'sha256', $first ) ?: 'one', '2026.one' );
		$this->run_import( $importer, $packs, $pack, $first );
		$endpoint = $this->endpoint( $geo, $packs );
		$ready    = $endpoint->search_result( 'GH', $geo->greater_accra->location_key, 'Acc', 1, 't1' );
		self::assertNotSame( [], $ready['items'] );

		$second = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
				$this->row( '3', 'Accra', 'Accra', 'P', 'PPLC', '01', '' ),
				$this->row( '9', 'Kasoa', 'Kasoa', 'P', 'PPL', '01', '' ),
			]
		);
		$pack = $packs->find_by_id( $pack->id );
		self::assertNotNull( $pack );
		$pack   = $importer->begin_dataset( $pack, $second, hash_file( 'sha256', $second ) ?: 'two', '2026.two' );
		$result = $importer->import_batch( $pack, $second, 1 );
		self::assertSame( GeographyPackStatus::Importing->value, $result['status'] );
		$kasoa = $geo->locations->find_location_id( GeographyProvider::GeoNames, '9' );
		if ( null !== $kasoa ) {
			$node = $geo->locations->find_by_id( $kasoa );
			self::assertFalse( $node?->isActive() );
		}
		$during = $endpoint->search_result( 'GH', $geo->greater_accra->location_key, 'Kas', 1, 't2' );
		self::assertSame( [], $during['items'] );
		$still = $endpoint->search_result( 'GH', $geo->greater_accra->location_key, 'Acc', 1, 't3' );
		self::assertNotSame( [], $still['items'] );
		unlink( $first );
		unlink( $second );
	}

	public function test_successful_staged_update_appears_atomically(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$file     = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
				$this->row( '3', 'Accra', 'Accra', 'P', 'PPLC', '01', '' ),
				$this->row( '9', 'Kasoa', 'Kasoa', 'P', 'PPL', '01', '' ),
			]
		);
		$pack = $importer->ensure_pack( 'GH', $file );
		$pack = $importer->begin_dataset( $pack, $file, hash_file( 'sha256', $file ) ?: 'all', '2026.all' );
		$this->run_import( $importer, $packs, $pack, $file );
		$endpoint = $this->endpoint( $geo, $packs );
		$found    = $endpoint->search_result( 'GH', $geo->greater_accra->location_key, 'Kas', 1, 't4' );
		self::assertNotSame( [], $found['items'] );
		self::assertSame( 'Kasoa', $found['items'][0]['name'] );
		unlink( $file );
	}

	public function test_stale_generation_token_is_noop(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$service  = new GeographyPackService( $packs, $importer, new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ) );
		$file     = $this->gazetteer_file( [ $this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ) ] );
		$pack     = $importer->ensure_pack( 'GH', $file );
		$pack     = $importer->begin_dataset( $pack, $file, 'aaa', '2026.a' );
		$stale    = $pack->target_token();
		$pack     = $importer->begin_dataset( $pack, $file, 'bbb', '2026.b' );
		$result   = $service->tick( $pack->id, $file, 10, $stale );
		self::assertSame( 'noop', $result['status'] );
		self::assertSame( 'stale_generation', $result['reason'] );
		unlink( $file );
	}

	public function test_unresolved_legacy_city_requires_explicit_scope_replacement(): void {
		$geo    = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$saved  = $groups->save_group(
			[
				'zone_id'          => 8,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Inactive->value,
				'review_required'  => true,
				'legacy_migration' => [
					'reason'          => 'unmapped_city',
					'origin'          => 'legacy_migration',
					'countries'       => [ 'GH' ],
					'regions'         => [ 'Greater Accra' ],
					'unmapped_cities' => [ 'Old Town' ],
				],
			]
		);
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$confirmed = $validator->validate(
			[
				[
					'id'                        => $saved->id,
					'country'                   => 'GH',
					'root_location_id'          => $geo->greater_accra->id,
					'mode'                      => CoverageMode::EntireArea->value,
					'resolve_review'            => '1',
					'confirm_scope_replacement' => '1',
				],
			],
			'',
			8,
			[ $saved ]
		);
		self::assertTrue( $confirmed['ok'] );
		self::assertFalse( $confirmed['groups'][0]['review_required'] );
		self::assertArrayHasKey( 'scope_replacement', $confirmed['groups'][0]['legacy_migration'] );
	}

	public function test_manual_canonical_coverage_survives_reconcile(): void {
		$geo    = new GhanaGeographyFixture();
		$zones  = new InMemoryDestinationZoneRepository();
		$rules  = new InMemoryDestinationRuleRepository();
		$groups = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 4, 'internal_name' => 'Manual Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone(
			4,
			[
				[ 'rule_type' => 'country', 'rule_value' => 'GH' ],
				[ 'rule_type' => 'region', 'rule_value' => 'Greater Accra' ],
			]
		);
		$groups->save_group(
			[
				'zone_id'          => 4,
				'root_location_id' => $geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'legacy_migration' => [ 'origin' => 'manual' ],
			]
		);
		$migrator = new LegacyDestinationCoverageMigrator(
			$zones,
			$rules,
			$groups,
			$geo->locations,
			new CanonicalLocationResolver( $geo->locations, $geo->locations )
		);
		$report = $migrator->migrate( true );
		self::assertGreaterThanOrEqual( 1, $report['skipped_manual'] );
		$kept = $groups->list_by_zone( 4 );
		self::assertCount( 1, $kept );
		self::assertSame( $geo->accra->id, $kept[0]->root_location_id );
		self::assertSame( 'manual', $kept[0]->legacy_migration['origin'] );
	}

	public function test_more_than_5000_descendants_reparent_completely(): void {
		$geo  = new GhanaGeographyFixture();
		$adm2 = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Old District', $geo->greater_accra->id, 2 );
		$ids  = [];
		for ( $i = 0; $i < 5100; $i++ ) {
			$ids[] = $geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Town ' . $i, $adm2->id )->id;
		}
		$old_path = $adm2->ancestry_path;
		$new_path = '/' . $geo->ghana->id . '/' . $geo->ashanti->id . '/' . $adm2->id . '/';
		$geo->locations->update_ancestry_path( $adm2->id, $new_path );
		$updated = $geo->locations->rebuild_descendant_ancestry( $adm2->id, $old_path, $new_path, 200 );
		self::assertSame( 5100, $updated );
		$sample = $geo->locations->find_by_id( $ids[5099] );
		self::assertSame( $ids[5099], $sample?->id );
		self::assertStringContainsString( '/' . $geo->ashanti->id . '/', (string) $sample?->ancestry_path );
		self::assertStringNotContainsString( '/' . $geo->greater_accra->id . '/', (string) $sample?->ancestry_path );
	}

	public function test_canonical_only_delivery_area_has_summary_and_readiness(): void {
		$geo    = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$localities = $geo->twenty_greater_accra_localities();
		$members    = [];
		foreach ( $localities as $location ) {
			$members[] = [ 'location_id' => $location->id, 'membership' => CoverageMembership::Include->value ];
		}
		$groups->save_group(
			[
				'zone_id'          => 22,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => $members,
			]
		);
		$summary = ( new CoverageGroupSummarizer( $geo->locations ) )->summarize( $groups->list_by_zone( 22 ) );
		self::assertStringContainsString( 'Greater Accra', $summary );
		self::assertTrue( str_contains( $summary, 'selected location' ) || str_contains( $summary, '+' ) || str_contains( $summary, 'Accra' ) );

		$zones = new InMemoryDestinationZoneRepository();
		$zones->save(
			[
				'id'            => 22,
				'internal_name' => 'Canonical Accra',
				'public_label'  => 'Canonical Accra',
				'status'        => RecordStatus::Active->value,
				'priority'      => 100,
			]
		);
		$zones->save(
			[
				'id'            => 23,
				'internal_name' => 'Legacy Ashanti',
				'public_label'  => 'Legacy Ashanti',
				'status'        => RecordStatus::Active->value,
				'priority'      => 100,
			]
		);
		$rules = new InMemoryDestinationRuleRepository();
		$rules->replaceForZone(
			23,
			[
				[ 'rule_type' => 'country', 'rule_value' => 'GH' ],
				[ 'rule_type' => 'region', 'rule_value' => 'Ashanti' ],
			]
		);
		$coverage = new OverlappingDeliveryAreaCoverage(
			$zones,
			$rules,
			new InMemoryQuoteRateCardRepository( [] ),
			null,
			$groups
		);
		self::assertNotContains( 22, $coverage->uncovered_zone_ids() );
		self::assertContains( 22, $coverage->unproven_zone_ids() );
		$warnings = $coverage->warnings();
		self::assertNotSame( [], $warnings );
		self::assertSame( 'canonical_coverage_overlap_unproven', $warnings[0]['code'] );
	}

	public function test_same_name_localities_are_disambiguated(): void {
		$geo        = new GhanaGeographyFixture();
		$eastern    = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Eastern Region', $geo->ghana->id, 1 );
		$denkyembour = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Denkyembour District', $eastern->id, 2 );
		$akwatia    = $geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Akwatia', $denkyembour->id );
		$other      = $geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Akwatia', $geo->ashanti->id );
		$endpoint   = $this->endpoint( $geo, new InMemoryGeographyPackRepository() );
		$results    = $endpoint->search_result( 'GH', $geo->ghana->location_key, 'Akw', 1, 'd1' );
		$labels     = array_map( static fn ( array $item ): string => (string) $item['label'], $results['items'] );
		self::assertNotSame( [], $labels );
		$matched = false;
		foreach ( $results['items'] as $item ) {
			if ( $item['key'] === $akwatia->location_key ) {
				$matched = true;
				self::assertSame( 'Akwatia', $item['name'] );
				self::assertStringContainsString( 'Denkyembour District', $item['label'] );
				self::assertStringContainsString( 'Eastern Region', $item['label'] );
				self::assertStringNotContainsString( 'geoname', strtolower( $item['label'] ) );
			}
		}
		self::assertTrue( $matched );
		self::assertNotSame( $akwatia->id, $other->id );
	}

	public function test_wrong_country_and_empty_packs_fail_preflight(): void {
		$preflight = new GeoNamesPackPreflight();
		$empty     = tempnam( sys_get_temp_dir(), 'emptygeo' );
		self::assertIsString( $empty );
		file_put_contents( $empty, '' );
		self::assertFalse( $preflight->validate( $empty, 'GH' )['ok'] );
		self::assertSame( 'empty', $preflight->validate( $empty, 'GH' )['error'] );

		$corrupt = tempnam( sys_get_temp_dir(), 'corrgeo' );
		self::assertIsString( $corrupt );
		file_put_contents( $corrupt, "not\ttabular\n" );
		self::assertFalse( $preflight->validate( $corrupt, 'GH' )['ok'] );
		self::assertSame( 'corrupt', $preflight->validate( $corrupt, 'GH' )['error'] );

		$wrong = $this->gazetteer_file( [ $this->row( '99', 'Lagos', 'Lagos', 'P', 'PPL', '05', '', 'NG' ) ] );
		$fail  = $preflight->validate( $wrong, 'GH' );
		self::assertFalse( $fail['ok'] );
		self::assertSame( 'wrong_country', $fail['error'] );

		unlink( $empty );
		unlink( $corrupt );
		unlink( $wrong );
	}

	public function test_hard_delete_zone_removes_coverage_rows(): void {
		$groups = new InMemoryCoverageGroupRepository();
		$zones  = new InMemoryDestinationZoneRepository();
		$rules  = new InMemoryDestinationRuleRepository();
		$zones->save( [ 'id' => 77, 'internal_name' => 'Delete me', 'status' => RecordStatus::Active->value ] );
		$groups->save_group(
			[
				'zone_id'          => 77,
				'root_location_id' => 5,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [ [ 'location_id' => 9, 'membership' => CoverageMembership::Include->value ] ],
				'postcodes'        => [ [ 'postcode_value' => 'GA-1', 'match_mode' => 'exact' ] ],
			]
		);
		$rules->replaceForZone( 77, [ [ 'rule_type' => 'country', 'rule_value' => 'GH' ] ] );
		$cleanup = new DeliveryAreaCoverageCleanup( $groups, $rules, $zones );
		self::assertTrue( $cleanup->hard_delete_zone( 77 ) );
		self::assertSame( [], $groups->list_by_zone( 77 ) );
		self::assertNull( $zones->findById( 77 ) );
	}

	public function test_hard_delete_failure_keeps_business_record(): void {
		$inner  = new InMemoryCoverageGroupRepository();
		$groups = new class( $inner ) implements CoverageGroupRepositoryInterface {
			public function __construct( private CoverageGroupRepositoryInterface $inner ) {
			}
			public function list_by_zone( int $zone_id ): array {
				return $this->inner->list_by_zone( $zone_id );
			}
			public function list_by_zone_ids( array $zone_ids ): array {
				return $this->inner->list_by_zone_ids( $zone_ids );
			}
			public function find_by_id( int $id ): ?\CetechDeliveryEngine\Domain\Coverage\CoverageGroup {
				return $this->inner->find_by_id( $id );
			}
			public function save_group( array $payload ): \CetechDeliveryEngine\Domain\Coverage\CoverageGroup {
				return $this->inner->save_group( $payload );
			}
			public function delete_group( int $id ): void {
				$this->inner->delete_group( $id );
			}
			public function delete_by_zone( int $zone_id ): void {
				throw new \RuntimeException( 'coverage cleanup failed' );
			}
			public function replace_members( int $group_id, array $members ): void {
				$this->inner->replace_members( $group_id, $members );
			}
			public function replace_postcodes( int $group_id, array $postcodes ): void {
				$this->inner->replace_postcodes( $group_id, $postcodes );
			}
			public function replace_for_zone( int $zone_id, array $groups ): array {
				return $this->inner->replace_for_zone( $zone_id, $groups );
			}
			public function count_review_required(): int {
				return $this->inner->count_review_required();
			}
		};
		$zones = new InMemoryDestinationZoneRepository();
		$rules = new InMemoryDestinationRuleRepository();
		$zones->save( [ 'id' => 88, 'internal_name' => 'Keep me', 'status' => RecordStatus::Active->value ] );
		$inner->save_group(
			[
				'zone_id'          => 88,
				'root_location_id' => 5,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$cleanup = new DeliveryAreaCoverageCleanup( $groups, $rules, $zones );
		self::assertFalse( $cleanup->hard_delete_zone( 88 ) );
		self::assertNotNull( $zones->findById( 88 ) );
		self::assertCount( 1, $inner->list_by_zone( 88 ) );
	}

	public function test_admin_type_neutral_normalization_is_country_neutral(): void {
		self::assertSame( 'greater accra', GeographyNameNormalizer::administrative_core( 'Greater Accra Region' ) );
		self::assertSame( 'california', GeographyNameNormalizer::administrative_core( 'California State' ) );
		self::assertSame( 'ontario', GeographyNameNormalizer::administrative_core( 'Ontario Province' ) );
		self::assertSame( 'tokyo', GeographyNameNormalizer::administrative_core( 'Tokyo Prefecture' ) );
	}

	public function test_wp_search_uses_prefix_not_middle_wildcard(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( WpdbCanonicalLocationRepository::class ) )->getFileName() );
		self::assertStringNotContainsString( "LIKE '%/", $src );
		self::assertStringContainsString( 'descendant_prefix', $src );
	}

	public function test_verifier_prints_detected_schema_target(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/scripts/verify-production-package-autoload.php' );
		self::assertStringNotContainsString( 'Schema target 4', $script );
		self::assertStringContainsString( 'Schema target " . (string) $target', $script );
		self::assertStringContainsString( "getConstant( 'TARGET' )", $script );
	}

	public function test_ghana_qualification_manifest_exists(): void {
		$path = dirname( __DIR__, 3 ) . '/docs/qualification/ghana-authoritative-geography-manifest.md';
		self::assertFileExists( $path );
		$body = (string) file_get_contents( $path );
		self::assertStringContainsString( 'Ghana Statistical Service', $body );
		self::assertStringContainsString( 'Greater Accra', $body );
		self::assertStringContainsString( 'Ashanti', $body );
		self::assertStringContainsString( 'Accra', $body );
		self::assertStringContainsString( 'Tema', $body );
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

	private function endpoint( GhanaGeographyFixture $geo, InMemoryGeographyPackRepository $packs ): StorefrontGeographyEndpoint {
		return new StorefrontGeographyEndpoint(
			$geo->locations,
			new CanonicalLocationResolver( $geo->locations, $geo->locations ),
			$packs,
			$geo->locations
		);
	}

	/**
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geo4' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		return $file;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_import( GeoNamesPackImporter $importer, InMemoryGeographyPackRepository $packs, $pack, string $file ): array {
		$result = [ 'status' => GeographyPackStatus::Importing->value ];
		$guard  = 0;
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 50 ) {
			$pack = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 20 );
			++$guard;
		}

		return $result;
	}

	private function row(
		string $id,
		string $name,
		string $ascii,
		string $class,
		string $code,
		string $admin1,
		string $admin2,
		string $country = 'GH'
	): string {
		$parts         = array_fill( 0, 19, '' );
		$parts[0]      = $id;
		$parts[1]      = $name;
		$parts[2]      = $ascii;
		$parts[6]      = $class;
		$parts[7]      = $code;
		$parts[8]      = $country;
		$parts[10]     = $admin1;
		$parts[11]     = $admin2;
		$parts[18]     = '2024-01-01';

		return implode( "\t", $parts );
	}
}
