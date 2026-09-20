<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class Geo9TechnicalCorrectionTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_prepare_does_not_mutate_active_reparent_until_finalize(): void {
		$fixture = $this->reparent_fixture();
		$repo    = $fixture['repo'];
		$town_id = $fixture['town']->id;
		$region_a = $fixture['region_a'];
		$region_b = $fixture['region_b'];

		$after = 0;
		do {
			$prepared = $repo->prepare_generation( 'token-reparent', 50, $after );
			$after    = (int) $prepared['last_id'];
			$town     = $repo->find_by_id( $town_id );
			self::assertSame( $region_a->id, $town?->parent_location_id );
			self::assertTrue( LocationAncestry::path_contains( (string) $town?->ancestry_path, $region_a->id ) );
			self::assertFalse( LocationAncestry::path_contains( (string) $town?->ancestry_path, $region_b->id ) );
		} while ( ! $prepared['done'] );

		$repo->finalize_generation( 'token-reparent' );
		$town = $repo->find_by_id( $town_id );
		self::assertSame( $region_b->id, $town?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $town?->ancestry_path, $region_b->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $town?->ancestry_path, $region_a->id ) );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_abandon_after_prepare_leaves_live_hierarchy_under_old_parent(): void {
		$fixture = $this->reparent_fixture();
		$repo    = $fixture['repo'];
		$child   = $fixture['child'];
		$town    = $fixture['town'];
		$region_a = $fixture['region_a'];
		$old_town_path  = $town->ancestry_path;
		$old_child_path = $child->ancestry_path;

		$repo->prepare_generation( 'token-reparent', 200, 0 );
		$repo->abandon_generation( 'token-reparent' );

		$again_town  = $repo->find_by_id( $town->id );
		$again_child = $repo->find_by_id( $child->id );
		self::assertSame( $region_a->id, $again_town?->parent_location_id );
		self::assertSame( $old_town_path, $again_town?->ancestry_path );
		self::assertSame( $town->id, $again_child?->parent_location_id );
		self::assertSame( $old_child_path, $again_child?->ancestry_path );
		self::assertSame( '', $again_town?->prepared_generation_token );
		self::assertSame( '', $again_town?->draft_generation_token );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_failed_finalize_leaves_live_hierarchy_unchanged(): void {
		$fixture = $this->reparent_fixture();
		$repo    = $fixture['repo'];
		$wpdb    = $fixture['wpdb'];
		$town    = $fixture['town'];
		$child   = $fixture['child'];
		$region_a = $fixture['region_a'];
		$old_town_path  = $town->ancestry_path;
		$old_child_path = $child->ancestry_path;

		$repo->prepare_generation( 'token-reparent', 200, 0 );
		$wpdb->fail_next_update_column = 'ancestry_path';
		try {
			$repo->finalize_generation( 'token-reparent' );
			self::fail( 'Finalize must throw when prepared ancestry cannot be applied.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'ancestry', $e->getMessage() );
		}

		$again_town  = $repo->find_by_id( $town->id );
		$again_child = $repo->find_by_id( $child->id );
		self::assertSame( $region_a->id, $again_town?->parent_location_id );
		self::assertSame( $old_town_path, $again_town?->ancestry_path );
		self::assertSame( $old_child_path, $again_child?->ancestry_path );
		self::assertSame( 'token-reparent', $again_town?->draft_generation_token );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_large_subtree_prepare_is_bounded_and_switches_together(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'Ghana' ) );
		$region_a = $repo->save(
			$this->blank_location( 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 )
		);
		$region_b = $repo->save(
			$this->blank_location( 'cccccccccccccccccccccccccccccccccccc', 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 )
		);
		$town = $repo->save(
			new CanonicalLocation(
				0,
				'dddddddd-dddd-dddd-dddd-dddddddddddd',
				'GH',
				$region_a->id,
				GeographyLocationType::Locality,
				null,
				'Example Town',
				'example town',
				'example town',
				null,
				null,
				RecordStatus::Active,
				'',
				1,
				(string) json_encode(
					[
						'generation_token'   => 'token-big',
						'canonical_name'     => 'Example Town',
						'parent_location_id' => $region_b->id,
					]
				),
				'',
				'token-big'
			)
		);
		$descendant_ids = [];
		for ( $i = 0; $i < 5001; $i++ ) {
			$saved = $repo->save(
				$this->blank_location(
					sprintf( 'eeeeeeee-eeee-eeee-eeee-%012d', $i ),
					'Child ' . $i,
					$town->id,
					GeographyLocationType::Locality
				)
			);
			$descendant_ids[] = $saved->id;
		}
		$live_town_path = (string) $repo->find_by_id( $town->id )?->ancestry_path;
		$wpdb->max_select_row_count = 0;
		$after = 0;
		$batches = 0;
		$seen_resume = false;
		do {
			$before_last = $after;
			$prepared = $repo->prepare_generation( 'token-big', 200, $after );
			$after    = (int) $prepared['last_id'];
			if ( $batches > 0 && ! $prepared['done'] ) {
				$seen_resume = true;
			}
			$live = $repo->find_by_id( $town->id );
			self::assertSame( $region_a->id, $live?->parent_location_id );
			self::assertSame( $live_town_path, $live?->ancestry_path );
			$sample = $repo->find_by_id( $descendant_ids[0] );
			self::assertTrue( LocationAncestry::path_contains( (string) $sample?->ancestry_path, $region_a->id ) );
			self::assertFalse( LocationAncestry::path_contains( (string) $sample?->ancestry_path, $region_b->id ) );
			self::assertLessThan( 250, $wpdb->max_select_row_count );
			if ( $prepared['processed'] > 0 && $after === $before_last && $batches > 0 ) {
				$seen_resume = true;
			}
			++$batches;
		} while ( ! $prepared['done'] && $batches < 100 );

		self::assertTrue( $prepared['done'] );
		self::assertGreaterThan( 1, $batches );
		self::assertTrue( $seen_resume );
		$concat_limited = false;
		foreach ( $wpdb->sql_log as $sql ) {
			if ( str_contains( strtoupper( $sql ), 'CONCAT' ) && str_contains( strtoupper( $sql ), 'LIMIT' ) ) {
				$concat_limited = true;
				break;
			}
		}
		self::assertTrue( $concat_limited );

		$repo->finalize_generation( 'token-big' );
		$moved = $repo->find_by_id( $town->id );
		self::assertSame( $region_b->id, $moved?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $moved?->ancestry_path, $region_b->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $moved?->ancestry_path, $region_a->id ) );
		$last = $repo->find_by_id( $descendant_ids[5000] );
		self::assertTrue( LocationAncestry::path_contains( (string) $last?->ancestry_path, $region_b->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $last?->ancestry_path, $region_a->id ) );
		unset( $wpdb );
	}

	public function test_expected_token_rejects_empty_current_target_without_update(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$packs = new WpdbGeographyPackRepository();
		$pack  = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'progress'     => [
					'active_generation' => 1,
				],
			]
		);
		self::assertSame( '', $pack->target_token() );
		try {
			$packs->update_progress(
				$pack->id,
				GeographyPackStatus::Importing,
				'0',
				[ 'target_token' => 'token-a' ],
				'',
				null,
				'token-a'
			);
			self::fail( 'Empty current target must not accept a non-empty expected token.' );
		} catch ( GeographyPackTokenFenceException $e ) {
			self::assertStringContainsString( 'token fence', $e->getMessage() );
		}
		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Importing, $fresh?->status );
		self::assertSame( '', $fresh?->target_token() );
		unset( $wpdb );
	}

	/**
	 * @return array{wpdb: FakeWpdb, repo: WpdbCanonicalLocationRepository, region_a: CanonicalLocation, region_b: CanonicalLocation, town: CanonicalLocation, child: CanonicalLocation}
	 */
	private function reparent_fixture(): array {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'Ghana' ) );
		$region_a = $repo->save(
			$this->blank_location( 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 )
		);
		$region_b = $repo->save(
			$this->blank_location( 'cccccccccccccccccccccccccccccccccccc', 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 )
		);
		$town = $repo->save(
			new CanonicalLocation(
				0,
				'dddddddd-dddd-dddd-dddd-dddddddddddd',
				'GH',
				$region_a->id,
				GeographyLocationType::Locality,
				null,
				'Example Town',
				'example town',
				'example town',
				null,
				null,
				RecordStatus::Active,
				'',
				1,
				(string) json_encode(
					[
						'generation_token'   => 'token-reparent',
						'canonical_name'     => 'Example Town',
						'parent_location_id' => $region_b->id,
					]
				),
				'',
				'token-reparent'
			)
		);
		$child = $repo->save(
			$this->blank_location( 'ffffffff-ffff-ffff-ffff-ffffffffffff', 'Nested Hamlet', $town->id, GeographyLocationType::Locality )
		);

		return [
			'wpdb'     => $wpdb,
			'repo'     => $repo,
			'region_a' => $region_a,
			'region_b' => $region_b,
			'town'     => $town,
			'child'    => $child,
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
			'',
			1
		);
	}
}
