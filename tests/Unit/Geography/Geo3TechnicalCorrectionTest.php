<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Coverage\CoverageConfigurationValidator;
use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
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
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class Geo3TechnicalCorrectionTest extends TestCase {

	public function test_review_required_stays_inactive_until_explicit_resolve(): void {
		$geo    = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$saved  = $groups->save_group(
			[
				'zone_id'          => 8,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Inactive->value,
				'review_required'  => true,
				'legacy_migration' => [ 'reason' => 'unmapped_city' ],
			]
		);
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$ordinary  = $validator->validate(
			[
				[
					'id'               => $saved->id,
					'country'          => 'GH',
					'root_location_id' => $geo->greater_accra->id,
					'mode'             => CoverageMode::EntireArea->value,
				],
			],
			'',
			8,
			[ $saved ]
		);
		self::assertTrue( $ordinary['ok'] );
		self::assertTrue( $ordinary['groups'][0]['review_required'] );
		self::assertFalse( $ordinary['groups'][0]['resolve_review'] );
		self::assertSame( RecordStatus::Inactive->value, $ordinary['groups'][0]['status'] );

		$resolved = $validator->validate(
			[
				[
					'id'               => $saved->id,
					'country'          => 'GH',
					'root_location_id' => $geo->greater_accra->id,
					'mode'             => CoverageMode::EntireArea->value,
					'resolve_review'   => '1',
				],
			],
			'',
			8,
			[ $saved ]
		);
		self::assertFalse( $resolved['ok'] );
		self::assertNotEmpty( $resolved['errors'] );
	}

	public function test_foreign_group_id_is_rejected_by_validator(): void {
		$geo    = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$foreign = $groups->save_group(
			[
				'zone_id'          => 90,
				'root_location_id' => $geo->ashanti->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$result    = $validator->validate(
			[
				[
					'id'               => $foreign->id,
					'country'          => 'GH',
					'root_location_id' => $geo->ghana->id,
					'mode'             => CoverageMode::EntireArea->value,
				],
			],
			'',
			11,
			[]
		);
		self::assertFalse( $result['ok'] );
	}

	public function test_invalid_supplied_root_does_not_fallback_to_country(): void {
		$geo       = new GhanaGeographyFixture();
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$result    = $validator->validate(
			[
				[
					'country'          => 'GH',
					'root_location_id' => 99999,
					'mode'             => CoverageMode::EntireArea->value,
				],
			]
		);
		self::assertFalse( $result['ok'] );
		self::assertSame( [], $result['groups'] );
	}

	public function test_specificity_scale_and_most_specific_member_win(): void {
		$geo     = new GhanaGeographyFixture();
		$adm2    = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Accra Metro', $geo->greater_accra->id, 2 );
		$adm3    = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Ablekuma', $adm2->id, 3 );
		$adm4    = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Ablekuma South', $adm3->id, 4 );
		$local   = $geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Korle Gonno', $adm4->id );
		$groups  = new InMemoryCoverageGroupRepository();
		$matcher = new CoverageGroupMatcher( $groups, $geo->locations );
		$resolver = new CanonicalLocationResolver( $geo->locations, $geo->locations );
		$group   = $groups->save_group(
			[
				'zone_id'          => 50,
				'root_location_id' => $geo->ghana->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $geo->greater_accra->id, 'membership' => 'include' ],
					[ 'location_id' => $adm2->id, 'membership' => 'include' ],
					[ 'location_id' => $local->id, 'membership' => 'include' ],
				],
			]
		);
		$resolved = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Korle Gonno', '', $local->location_key );
		$result   = $matcher->match_group( $group, $resolved );
		self::assertTrue( $result['matched'] );
		self::assertSame( DestinationZoneMatcher::SPECIFICITY_LOCALITY, $result['specificity'] );

		$ranks = [
			DestinationZoneMatcher::SPECIFICITY_POSTCODE,
			DestinationZoneMatcher::SPECIFICITY_LOCALITY,
			DestinationZoneMatcher::SPECIFICITY_ADM4,
			DestinationZoneMatcher::SPECIFICITY_ADM3,
			DestinationZoneMatcher::SPECIFICITY_ADM2,
			DestinationZoneMatcher::SPECIFICITY_ADM1,
			DestinationZoneMatcher::SPECIFICITY_COUNTRY,
			DestinationZoneMatcher::SPECIFICITY_FALLBACK,
		];
		$copy = $ranks;
		rsort( $copy, SORT_NUMERIC );
		self::assertSame( $ranks, $copy );
		self::assertGreaterThan( DestinationZoneMatcher::SPECIFICITY_ADM2, DestinationZoneMatcher::SPECIFICITY_ADM4 );
		self::assertGreaterThan( DestinationZoneMatcher::SPECIFICITY_ADM4, DestinationZoneMatcher::SPECIFICITY_LOCALITY );
		self::assertSame( DestinationZoneMatcher::SPECIFICITY_CITY, DestinationZoneMatcher::SPECIFICITY_LOCALITY );
		self::assertSame( DestinationZoneMatcher::SPECIFICITY_REGION, DestinationZoneMatcher::SPECIFICITY_ADM1 );
		unset( $adm3, $adm4 );
	}

	public function test_schema6_constrained_fallback_is_not_unrestricted(): void {
		self::assertFalse(
			DestinationZoneMatcher::is_unrestricted_fallback(
				[ 'is_fallback' => true ],
				[],
				true
			)
		);
		self::assertTrue(
			DestinationZoneMatcher::is_unrestricted_fallback(
				[ 'is_fallback' => true ],
				[],
				false
			)
		);
		$rules = [
			[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Accra' ],
		];
		self::assertSame(
			DestinationZoneMatcher::SPECIFICITY_CITY,
			DestinationZoneMatcher::geographic_specificity_rank( $rules, true )
		);
	}

	public function test_inactive_review_coverage_falls_through_to_legacy_until_canonical_is_usable(): void {
		$geo   = new GhanaGeographyFixture();
		$zones = new InMemoryDestinationZoneRepository();
		$rules = new InMemoryDestinationRuleRepository();
		$groups = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 70, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value, 'priority' => 10 ] );
		$rules->replaceForZone(
			70,
			[
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ],
				[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Accra' ],
			]
		);
		$groups->save_group(
			[
				'zone_id'          => 70,
				'root_location_id' => $geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Inactive->value,
				'review_required'  => true,
			]
		);
		$matcher = new DestinationZoneMatcher(
			$zones,
			$rules,
			null,
			new CoverageGroupMatcher( $groups, $geo->locations ),
			new CanonicalLocationResolver( $geo->locations, $geo->locations )
		);
		$legacy = $matcher->match_all( 'GH', 'AA', 'Accra', '' );
		self::assertNotEmpty( $legacy );
		self::assertSame( 70, (int) $legacy[0]['id'] );

		$groups->save_group(
			[
				'id'               => $groups->list_by_zone( 70 )[0]->id,
				'zone_id'          => 70,
				'root_location_id' => $geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'review_required'  => false,
			]
		);
		$canonical = $matcher->match_all( 'GH', 'AA', 'Accra', '', [ 'canonical_location_key' => 'loc-accra' ] );
		self::assertSame( 70, (int) $canonical[0]['id'] );
	}

	public function test_pdp_text_locality_does_not_quote_while_woo_exact_city_does(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'      => GeographyPackStatus::Ready->value,
				'checksum'     => 'ready-1',
			]
		);
		$resolver = new CanonicalLocationResolver( $geo->locations, $geo->locations, $packs );
		$pdp      = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', '', CanonicalResolutionContext::ShopperSelector );
		self::assertNotSame( $geo->accra->id, $pdp->location_id() );
		$woo = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertSame( $geo->accra->id, $woo->location_id() );
		$typo = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Acccra', '', '', CanonicalResolutionContext::WooCommerceDestination );
		self::assertNotSame( $geo->accra->id, $typo->location_id() );
		$keyed = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', 'loc-accra', CanonicalResolutionContext::ShopperSelector );
		self::assertSame( $geo->accra->id, $keyed->location_id() );
	}

	public function test_alias_count_matches_search_pagination(): void {
		$geo = new GhanaGeographyFixture();
		$only_alias = $geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Hidden Alias Town', $geo->greater_accra->id, null, 'loc-hidden-alias' );
		$geo->locations->add_alias( $only_alias->id, 'Secret Alias Village', 'secret alias village' );
		$query = 'secret alias village';
		$total = $geo->locations->count_descendants( $geo->greater_accra->id, GeographyLocationType::Locality, $query );
		$page1 = $geo->locations->search_localities( 'GH', $geo->greater_accra->id, $query, 1, 0 );
		self::assertSame( 1, $total );
		self::assertCount( 1, $page1 );
		self::assertSame( $only_alias->id, $page1[0]->id );
		$page2 = $geo->locations->search_localities( 'GH', $geo->greater_accra->id, $query, 1, 1 );
		self::assertSame( [], $page2 );
	}

	public function test_reparent_rebuilds_nested_locality_ancestry_and_keeps_id(): void {
		$geo  = new GhanaGeographyFixture();
		$adm2 = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Old Metro', $geo->greater_accra->id, 2 );
		$town = $geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Nested Town', $adm2->id );
		$old_adm2_path = $adm2->ancestry_path;
		$old_town_path = $town->ancestry_path;
		$new_parent    = $geo->ashanti;
		$new_path      = \CetechDeliveryEngine\Domain\Geography\LocationAncestry::append_path( $new_parent->ancestry_path, $adm2->id );
		$geo->locations->save(
			new CanonicalLocation(
				$adm2->id,
				$adm2->location_key,
				$adm2->country_code,
				$new_parent->id,
				$adm2->location_type,
				$adm2->administrative_level,
				$adm2->canonical_name,
				$adm2->normalized_name,
				$adm2->ascii_name,
				null,
				null,
				$adm2->status,
				$new_path
			)
		);
		$geo->locations->update_ancestry_path( $adm2->id, $new_path );
		$updated = $geo->locations->rebuild_descendant_ancestry( $adm2->id, $old_adm2_path, $new_path );
		self::assertGreaterThan( 0, $updated );
		$moved_town = $geo->locations->find_by_id( $town->id );
		self::assertSame( $town->id, $moved_town->id );
		self::assertNotSame( $old_town_path, $moved_town->ancestry_path );
		self::assertStringContainsString( '/' . $geo->ashanti->id . '/', $moved_town->ancestry_path );
		self::assertStringNotContainsString( '/' . $geo->greater_accra->id . '/', $moved_town->ancestry_path );
	}

	public function test_usable_pack_survives_importing_update_status(): void {
		$packs = new InMemoryGeographyPackRepository();
		$ready = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'dataset_version'  => '2026.1',
				'checksum'         => 'success-1',
				'status'           => GeographyPackStatus::Ready->value,
				'installed_at'     => '2026-01-01 00:00:00',
				'progress'         => [
					'last_successful' => [
						'checksum'         => 'success-1',
						'dataset_version'  => '2026.1',
						'installed_at'    => '2026-01-01 00:00:00',
					],
				],
			]
		);
		self::assertTrue( $ready->has_usable_dataset() );
		$updating = $packs->save(
			[
				'id'           => $ready->id,
				'status'       => GeographyPackStatus::Importing->value,
				'checksum'     => 'next-2',
				'dataset_version' => '2026.2',
			]
		);
		self::assertTrue( $updating->has_usable_dataset() );
		self::assertSame( 'success-1', $updating->active_checksum() );
		self::assertSame( '2026-01-01 00:00:00', $updating->installed_at );
		$omitted = $packs->save(
			[
				'id'     => $ready->id,
				'status' => GeographyPackStatus::Failed->value,
				'last_error' => 'download failed',
			]
		);
		self::assertTrue( $omitted->has_usable_dataset() );
		self::assertSame( '2026-01-01 00:00:00', $omitted->installed_at );
	}

	public function test_schema6_upgrade_is_not_rescan_after_completion(): void {
		unset( $GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] );
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$GLOBALS['wpdb'] = new \CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb();
		$GLOBALS['wpdb']->create_table( 'wp_delivery_engine_destination_coverage_groups' );
		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$GLOBALS['cetech_de_test_wc'] = (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					return [];
				}
			},
		];
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );
		$upgrade   = new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
		$first     = $upgrade->run();
		self::assertSame( Schema6CoverageUpgradeService::STATUS_COMPLETED, $first['state']['status'] );
		$second = $upgrade->maybe_run();
		self::assertSame( 'already_completed', $second['migration']['reason'] );
		$again = $upgrade->reconcile( true );
		self::assertArrayHasKey( 'migration', $again );
		unset( $GLOBALS['cetech_de_test_wc'], $GLOBALS['wpdb'] );
	}

	public function test_postcode_relevance_is_scoped_to_selected_geography(): void {
		$geo   = new GhanaGeographyFixture();
		$zones = new InMemoryDestinationZoneRepository();
		$groups = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 81, 'internal_name' => 'Tema postcode', 'status' => RecordStatus::Active->value ] );
		$groups->save_group(
			[
				'zone_id'          => 81,
				'root_location_id' => $geo->tema->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'postcodes'        => [
					[ 'postcode_value' => 'GT-001', 'match_mode' => 'exact', 'status' => RecordStatus::Active->value ],
				],
			]
		);
		$relevance = new GeographyPostcodeRelevance( $groups, $geo->locations, $zones );
		self::assertFalse( $relevance->is_visible( 'GH', 'loc-accra' ) );
		self::assertTrue( $relevance->is_visible( 'GH', 'loc-tema' ) );
	}

	public function test_geonames_importer_preserves_last_successful_on_new_dataset(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$pack  = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'dataset_version'  => 'old',
				'checksum'         => 'old-sum',
				'status'           => GeographyPackStatus::Ready->value,
				'installed_at'     => '2026-01-01 00:00:00',
				'source_reference' => '/tmp/GH.txt',
				'progress'         => [
					'last_successful' => [
						'checksum'        => 'old-sum',
						'dataset_version' => 'old',
						'installed_at'    => '2026-01-01 00:00:00',
					],
				],
			]
		);
		$boot     = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $boot );
		$updated  = $importer->begin_dataset( $pack, '/tmp/GH-new.txt', 'new-sum', '2026.2' );
		self::assertSame( GeographyPackStatus::Importing, $updated->status );
		self::assertSame( 'old-sum', $updated->active_checksum() );
		self::assertTrue( $updated->has_usable_dataset() );
		self::assertSame( '2026-01-01 00:00:00', $updated->installed_at );
		$svc = new GeographyPackService( $packs, $importer, $boot );
		self::assertTrue( $svc->is_allowed_geonames_url( GeographyPackService::geonames_url( 'GH' ), 'GH' ) );
	}

	public function test_storefront_pack_usable_during_failed_update(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Failed->value,
				'checksum'     => 'next',
				'progress'     => [
					'last_successful' => [ 'checksum' => 'good', 'dataset_version' => '1' ],
				],
			]
		);
		$endpoint = new StorefrontGeographyEndpoint( $geo->locations, new CanonicalLocationResolver( $geo->locations, $geo->locations, $packs ), $packs );
		self::assertTrue( $endpoint->country_has_locality_pack( 'GH' ) );
	}
}
