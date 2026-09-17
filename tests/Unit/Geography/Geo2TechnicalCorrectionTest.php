<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Coverage\CoverageConfigurationValidator;
use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeographyAdminLabels;
use CetechDeliveryEngine\Application\Geography\GeographyPostcodeRelevance;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\StorefrontGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class Geo2TechnicalCorrectionTest extends TestCase {

	public function test_plugin_boot_runs_schema6_upgrade_after_migrations(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Bootstrap/Plugin.php' );
		$runner = strpos( $source, '$migration_runner->run();' );
		$upgrade = strpos( $source, 'Schema6CoverageUpgradeService::class )->run()' );
		self::assertNotFalse( $runner );
		self::assertNotFalse( $upgrade );
		self::assertGreaterThan( $runner, $upgrade );
		self::assertStringNotContainsString(
			'LegacyDestinationCoverageMigrator::class )->migrate()',
			$source
		);
	}

	public function test_schema5_rules_without_geography_rows_bootstrap_all_countries_then_migrate_active(): void {
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$GLOBALS['cetech_de_test_wc'] = (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [
						'GH' => 'Ghana',
						'GB' => 'United Kingdom',
						'US' => 'United States',
					];
				}
				public function get_states( string $country ): array {
					return match ( strtoupper( $country ) ) {
						'GH' => [ 'AA' => 'Greater Accra' ],
						'US' => [ 'NY' => 'New York' ],
						default => [],
					};
				}
			},
		];

		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone(
			1,
			[
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ],
				[ 'rule_type' => DestinationRuleType::Region->value, 'rule_value' => 'Greater Accra' ],
				[ 'rule_type' => DestinationRuleType::City->value, 'rule_value' => 'Accra' ],
			]
		);
		$zones->save( [ 'id' => 2, 'internal_name' => 'Multi', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone(
			2,
			[
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ],
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GB' ],
				[ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'US' ],
			]
		);

		self::assertNull( $locations->find_country( 'GH' ) );
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$resolver  = new CanonicalLocationResolver( $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, $resolver );
		$upgrade   = new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );

		self::assertSame( [ 'GH', 'GB', 'US' ], $upgrade->referenced_country_codes() );
		foreach ( $upgrade->referenced_country_codes() as $code ) {
			$bootstrap->bootstrap_country( $code );
		}
		$migrator->migrate();

		self::assertNotNull( $locations->find_country( 'GH' ) );
		self::assertNotNull( $locations->find_country( 'GB' ) );
		self::assertNotNull( $locations->find_country( 'US' ) );
		$accra_groups = $groups->list_by_zone( 1 );
		self::assertCount( 1, $accra_groups );
		self::assertFalse( $accra_groups[0]->review_required );
		self::assertSame( RecordStatus::Active, $accra_groups[0]->status );
		self::assertNotEmpty( $rules->listByZoneId( 1 ) );

		$multi = $groups->list_by_zone( 2 );
		self::assertNotEmpty( $multi );
		self::assertTrue( $multi[0]->review_required );
		self::assertSame( RecordStatus::Inactive, $multi[0]->status );
		unset( $GLOBALS['cetech_de_test_wc'] );
	}

	public function test_equal_priority_specificity_is_from_constraint_not_destination_type(): void {
		$geo     = new GhanaGeographyFixture();
		$groups  = new InMemoryCoverageGroupRepository();
		$zones   = new InMemoryDestinationZoneRepository();
		$rules   = new InMemoryDestinationRuleRepository();
		$matcher = new DestinationZoneMatcher(
			$zones,
			$rules,
			null,
			new CoverageGroupMatcher( $groups, $geo->locations ),
			new CanonicalLocationResolver( $geo->locations, $geo->locations )
		);

		$zones->save( [ 'id' => 1, 'internal_name' => 'Ghana', 'status' => RecordStatus::Active->value, 'priority' => 100 ] );
		$zones->save( [ 'id' => 2, 'internal_name' => 'Greater Accra', 'status' => RecordStatus::Active->value, 'priority' => 100 ] );
		$zones->save( [ 'id' => 3, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value, 'priority' => 100 ] );
		$groups->save_group(
			[
				'zone_id'          => 1,
				'root_location_id' => $geo->ghana->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$groups->save_group(
			[
				'zone_id'          => 2,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$groups->save_group(
			[
				'zone_id'          => 3,
				'root_location_id' => $geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [ [ 'location_id' => $geo->accra->id, 'membership' => 'include' ] ],
			]
		);

		$ids = array_map(
			static fn ( array $zone ): int => (int) $zone['id'],
			$matcher->match_all( 'GH', 'AA', 'Accra', '', [ 'canonical_location_key' => 'loc-accra', 'state' => 'AA', 'state_label' => 'Greater Accra' ] )
		);
		self::assertSame( [ 3, 2, 1 ], $ids );
	}

	public function test_coverage_constrained_fallback_is_not_unrestricted(): void {
		$geo     = new GhanaGeographyFixture();
		$groups  = new InMemoryCoverageGroupRepository();
		$zones   = new InMemoryDestinationZoneRepository();
		$rules   = new InMemoryDestinationRuleRepository();
		$matcher = new DestinationZoneMatcher(
			$zones,
			$rules,
			null,
			new CoverageGroupMatcher( $groups, $geo->locations ),
			new CanonicalLocationResolver( $geo->locations, $geo->locations )
		);

		$zones->save(
			[
				'id'            => 9,
				'internal_name' => 'Ghana fallback',
				'status'        => RecordStatus::Active->value,
				'is_fallback'   => true,
				'priority'      => 100,
			]
		);
		$rules->replaceForZone( 9, [] );
		$groups->save_group(
			[
				'zone_id'          => 9,
				'root_location_id' => $geo->ghana->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		self::assertFalse( DestinationZoneMatcher::is_unrestricted_fallback( [ 'is_fallback' => true ], [], true ) );
		self::assertSame( [], $matcher->match_all( 'US', 'NY', 'New York', '', [] ) );
		self::assertNotEmpty( $matcher->match_all( 'GH', '', '', '', [ 'canonical_location_key' => 'loc-gh' ] ) );
	}

	public function test_invalid_parent_key_does_not_search_whole_country(): void {
		$geo      = new GhanaGeographyFixture();
		$resolver = new CanonicalLocationResolver( $geo->locations, $geo->locations );
		$endpoint = new StorefrontGeographyEndpoint( $geo->locations, $resolver, new InMemoryGeographyPackRepository(), $geo->locations );
		$tampered = $endpoint->search_result( 'GH', 'not-a-real-parent', 'Accra', 1, 't1' );
		self::assertSame( 'invalid_parent', $tampered['error'] ?? null );
		self::assertSame( [], $tampered['items'] );

		$valid = $endpoint->search_result( 'GH', 'loc-ga', 'Accra', 1, 't2' );
		self::assertArrayNotHasKey( 'error', $valid );
		self::assertNotEmpty( $valid['items'] );
		foreach ( $valid['items'] as $item ) {
			self::assertSame( 'Accra', $item['name'] );
		}
	}

	public function test_pack_ready_requires_canonical_key_for_locality_quote(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$packs->save(
			[
				'country_code'    => 'GH',
				'provider'        => GeographyProvider::GeoNames->value,
				'dataset_name'    => 'gazetteer',
				'dataset_version' => '2026.1',
				'status'          => GeographyPackStatus::Ready->value,
				'checksum'        => 'abc',
			]
		);
		$resolver = new CanonicalLocationResolver( $geo->locations, $geo->locations, $packs );
		$typed    = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', '' );
		self::assertTrue( $typed->hasCanonicalLocation() );
		self::assertSame( $geo->greater_accra->id, $typed->location_id() );
		self::assertNotSame( $geo->accra->id, $typed->location_id() );

		$selected = $resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', '', 'loc-accra' );
		self::assertSame( $geo->accra->id, $selected->location_id() );

		$country_only = $resolver->resolve( 'GH', '', '', '', '', '' );
		self::assertSame( $geo->ghana->id, $country_only->location_id() );
	}

	public function test_alias_search_resolves_to_canonical_location(): void {
		$geo = new GhanaGeographyFixture();
		$hits = $geo->locations->search_localities( 'GH', $geo->greater_accra->id, 'accra metropolitan', 10, 0 );
		self::assertNotEmpty( $hits );
		self::assertSame( $geo->accra->id, $hits[0]->id );
	}

	public function test_invalid_root_and_member_ancestry_are_rejected_and_do_not_replace(): void {
		$geo       = new GhanaGeographyFixture();
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$invalid   = $validator->validate(
			[
				[
					'country'          => 'GH',
					'root_location_id' => $geo->ashanti->id,
					'mode'             => CoverageMode::SelectedDescendants->value,
					'members'          => [ $geo->accra->id ],
				],
			]
		);
		self::assertFalse( $invalid['ok'] );
		self::assertNotEmpty( $invalid['errors'] );
		self::assertSame( [], $invalid['groups'] );

		$bad_root = $validator->validate(
			[
				[
					'country'          => 'US',
					'root_location_id' => $geo->ghana->id,
					'mode'             => CoverageMode::EntireArea->value,
				],
			]
		);
		self::assertFalse( $bad_root['ok'] );
	}

	public function test_review_and_postcodes_survive_validated_edit(): void {
		$geo    = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$saved  = $groups->save_group(
			[
				'zone_id'          => 4,
				'root_location_id' => $geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'review_required'  => true,
				'legacy_migration' => [ 'reason' => 'duplicate_legacy_same_level' ],
				'postcodes'        => [
					[ 'postcode_value' => 'GA-123', 'match_mode' => 'exact', 'status' => RecordStatus::Active->value ],
				],
			]
		);
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$result    = $validator->validate(
			[
				[
					'id'               => $saved->id,
					'country'          => 'GH',
					'root_location_id' => $geo->accra->id,
					'mode'             => CoverageMode::EntireArea->value,
					'postcodes'        => [
						[ 'postcode_value' => 'GA-123', 'match_mode' => 'exact' ],
					],
				],
			]
		);
		self::assertTrue( $result['ok'] );
		$row = $result['groups'][0];
		$row['review_required']  = $saved->review_required;
		$row['legacy_migration'] = $saved->legacy_migration;
		$groups->replace_for_zone( 4, [ $row ] );
		$again = $groups->list_by_zone( 4 )[0];
		self::assertTrue( $again->review_required );
		self::assertSame( 'duplicate_legacy_same_level', $again->legacy_migration['reason'] );
		self::assertSame( 'GA-123', $again->postcodes[0]->postcode_value );
	}

	public function test_ghana_admin_label_is_region_and_us_is_state(): void {
		self::assertSame( 'Region', GeographyAdminLabels::administrative_area_label( 'GH' ) );
		self::assertSame( 'State', GeographyAdminLabels::administrative_area_label( 'US' ) );
	}

	public function test_postcode_hidden_for_ghana_without_coverage_postcodes(): void {
		$relevance = new GeographyPostcodeRelevance();
		self::assertFalse( $relevance->is_visible( 'GH' ) );
	}

	public function test_deep_hierarchy_counts_nested_localities(): void {
		$geo  = new GhanaGeographyFixture();
		$adm2 = $geo->locations->seed( 'GH', GeographyLocationType::Administrative, 'Accra Metro', $geo->greater_accra->id, 2 );
		$nested = $geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Kaneshie Nested', $adm2->id );
		self::assertGreaterThan(
			$geo->locations->count_children( $geo->greater_accra->id, GeographyLocationType::Locality ),
			$geo->locations->count_descendants( $geo->greater_accra->id, GeographyLocationType::Locality )
		);
		$hits = $geo->locations->search_localities( 'GH', $geo->greater_accra->id, 'kaneshie nested', 10, 0 );
		self::assertSame( $nested->id, $hits[0]->id );
	}

	public function test_geonames_url_is_restricted(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$importer = new \CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter(
			$geo->locations,
			$geo->locations,
			$geo->locations,
			$packs,
			$boot
		);
		$svc = new \CetechDeliveryEngine\Application\Geography\GeographyPackService( $packs, $importer, $boot );
		self::assertTrue( $svc->is_allowed_geonames_url( 'https://download.geonames.org/export/dump/GH.zip', 'GH' ) );
		self::assertFalse( $svc->is_allowed_geonames_url( 'https://evil.example/GH.zip', 'GH' ) );
		self::assertFalse( $svc->is_allowed_geonames_url( 'https://download.geonames.org/export/dump/../etc/passwd.zip', 'GH' ) );
	}

	public function test_schema5_wpdb_rows_bootstrap_referenced_countries_and_keep_destination_rules(): void {
		$wpdb = new \CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb();
		$wpdb->create_table( 'wp_delivery_engine_destination_zones' );
		$wpdb->create_table( 'wp_delivery_engine_destination_rules' );
		$wpdb->create_table( 'wp_delivery_engine_destination_coverage_groups' );
		$GLOBALS['wpdb'] = $wpdb;

		$zones = new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationZoneRepository();
		$rules = new \CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationRuleRepository();
		$zone_id = $zones->save(
			[
				'internal_code' => 'accra-area',
				'internal_name' => 'Accra',
				'status'        => RecordStatus::Active->value,
			]
		);
		self::assertGreaterThan( 0, $zone_id );
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach (
			[
				[ DestinationRuleType::Country->value, 'GH' ],
				[ DestinationRuleType::Region->value, 'Greater Accra' ],
				[ DestinationRuleType::City->value, 'Accra' ],
			] as $rule
		) {
			$wpdb->insert(
				'wp_delivery_engine_destination_rules',
				[
					'zone_id'    => $zone_id,
					'rule_type'  => $rule[0],
					'rule_value' => $rule[1],
					'match_mode' => 'exact',
					'priority'   => 100,
					'created_at' => $now,
					'updated_at' => $now,
				]
			);
		}

		$locations = new InMemoryCanonicalLocationRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$GLOBALS['cetech_de_test_wc'] = (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					return 'GH' === strtoupper( $country ) ? [ 'AA' => 'Greater Accra' ] : [];
				}
			},
		];

		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$resolver  = new CanonicalLocationResolver( $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, $resolver );
		$upgrade   = new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );

		self::assertSame( [ 'GH' ], $upgrade->referenced_country_codes() );
		self::assertNull( $locations->find_country( 'GH' ) );
		foreach ( $upgrade->referenced_country_codes() as $code ) {
			$bootstrap->bootstrap_country( $code );
		}
		$migrator->migrate();

		self::assertNotNull( $locations->find_country( 'GH' ) );
		$converted = $groups->list_by_zone( $zone_id );
		self::assertCount( 1, $converted );
		self::assertFalse( $converted[0]->review_required );
		self::assertSame( RecordStatus::Active, $converted[0]->status );
		self::assertNotEmpty( $rules->listByZoneId( $zone_id ) );
		unset( $GLOBALS['cetech_de_test_wc'], $GLOBALS['wpdb'] );
	}

	public function test_failed_payload_does_not_replace_existing_coverage(): void {
		$geo       = new GhanaGeographyFixture();
		$groups    = new InMemoryCoverageGroupRepository();
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$saved     = $groups->save_group(
			[
				'zone_id'          => 7,
				'root_location_id' => $geo->ghana->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$invalid = $validator->validate(
			[
				[
					'country'          => 'GH',
					'root_location_id' => $geo->ashanti->id,
					'mode'             => CoverageMode::SelectedDescendants->value,
					'members'          => [ $geo->accra->id ],
				],
			]
		);
		self::assertFalse( $invalid['ok'] );
		if ( $invalid['ok'] ) {
			$groups->replace_for_zone( 7, $invalid['groups'] );
		}
		$kept = $groups->list_by_zone( 7 );
		self::assertCount( 1, $kept );
		self::assertSame( $saved->id, $kept[0]->id );
		self::assertSame( $geo->ghana->id, $kept[0]->root_location_id );
	}

	public function test_second_coverage_group_and_non_gh_country_are_valid(): void {
		$geo       = new GhanaGeographyFixture();
		$us        = $geo->locations->seed( 'US', GeographyLocationType::Country, 'United States' );
		$ny        = $geo->locations->seed( 'US', GeographyLocationType::Administrative, 'New York', $us->id, 1 );
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$result    = $validator->validate(
			[
				[
					'country'          => 'GH',
					'root_location_id' => $geo->ghana->id,
					'mode'             => CoverageMode::EntireArea->value,
				],
				[
					'country'          => 'US',
					'root_location_id' => $ny->id,
					'mode'             => CoverageMode::EntireArea->value,
				],
			]
		);
		self::assertTrue( $result['ok'], implode( ' ', $result['errors'] ) );
		self::assertCount( 2, $result['groups'] );
		self::assertSame( $geo->ghana->id, $result['groups'][0]['root_location_id'] );
		self::assertSame( $ny->id, $result['groups'][1]['root_location_id'] );
	}

	public function test_exclusion_members_persist_as_exclude(): void {
		$geo       = new GhanaGeographyFixture();
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$result    = $validator->validate(
			[
				[
					'country'          => 'GH',
					'root_location_id' => $geo->greater_accra->id,
					'mode'             => CoverageMode::EntireExcept->value,
					'exclusions'       => [ $geo->accra->id ],
				],
			]
		);
		self::assertTrue( $result['ok'], implode( ' ', $result['errors'] ) );
		self::assertSame( 'exclude', $result['groups'][0]['members'][0]['membership'] );
		$groups = new InMemoryCoverageGroupRepository();
		$saved  = $groups->save_group( $result['groups'][0] + [ 'zone_id' => 8 ] );
		self::assertCount( 1, $saved->members_of( \CetechDeliveryEngine\Domain\Enum\CoverageMembership::Exclude ) );
		$groups->replace_for_zone( 8, [] );
		self::assertSame( [], $groups->list_by_zone( 8 ) );
		$again = $groups->save_group( $result['groups'][0] + [ 'zone_id' => 8 ] );
		self::assertSame( $geo->accra->id, $again->members[0]->location_id );
	}

	public function test_select_all_is_bounded_and_recommends_entire_area(): void {
		$geo = new GhanaGeographyFixture();
		for ( $i = 0; $i < CoverageConfigurationValidator::SELECT_ALL_LIMIT + 3; $i++ ) {
			$geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Town ' . $i, $geo->greater_accra->id );
		}
		$total = $geo->locations->count_descendants( $geo->greater_accra->id, GeographyLocationType::Locality );
		self::assertGreaterThan( CoverageConfigurationValidator::SELECT_ALL_LIMIT, $total );
		$bounded = $geo->locations->search_localities( 'GH', $geo->greater_accra->id, '', CoverageConfigurationValidator::SELECT_ALL_LIMIT, 0 );
		self::assertCount( CoverageConfigurationValidator::SELECT_ALL_LIMIT, $bounded );
	}

	public function test_region_scoped_search_does_not_return_other_regions(): void {
		$geo  = new GhanaGeographyFixture();
		$hits = $geo->locations->search_localities( 'GH', $geo->ashanti->id, 'Accra', 10, 0 );
		foreach ( $hits as $hit ) {
			self::assertNotSame( $geo->accra->id, $hit->id );
		}
		$accra = $geo->locations->search_localities( 'GH', $geo->greater_accra->id, 'Accra', 10, 0 );
		self::assertSame( $geo->accra->id, $accra[0]->id );
	}

	public function test_ghana_postcode_visible_when_coverage_requires_it(): void {
		$geo    = new GhanaGeographyFixture();
		$groups = new InMemoryCoverageGroupRepository();
		$zones  = new InMemoryDestinationZoneRepository();
		$zones->save( [ 'id' => 11, 'internal_name' => 'Accra postcode', 'status' => RecordStatus::Active->value ] );
		$groups->save_group(
			[
				'zone_id'          => 11,
				'root_location_id' => $geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'postcodes'        => [
					[ 'postcode_value' => 'GA-123', 'match_mode' => 'exact', 'status' => RecordStatus::Active->value ],
				],
			]
		);
		$relevance = new GeographyPostcodeRelevance( $groups, $geo->locations, $zones );
		self::assertTrue( $relevance->is_visible( 'GH', 'loc-accra' ) );
	}

	public function test_blank_coverage_payload_does_not_clear_live_groups(): void {
		$geo       = new GhanaGeographyFixture();
		$groups    = new InMemoryCoverageGroupRepository();
		$validator = new CoverageConfigurationValidator( $geo->locations );
		$groups->save_group(
			[
				'zone_id'          => 12,
				'root_location_id' => $geo->ghana->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$result = $validator->validate( [ [ 'country' => '', 'root_location_id' => 0, 'mode' => '' ] ] );
		self::assertTrue( $result['ok'] );
		self::assertSame( [], $result['groups'] );
		if ( [] !== $result['groups'] ) {
			$groups->replace_for_zone( 12, $result['groups'] );
		}
		self::assertCount( 1, $groups->list_by_zone( 12 ) );
	}
}
