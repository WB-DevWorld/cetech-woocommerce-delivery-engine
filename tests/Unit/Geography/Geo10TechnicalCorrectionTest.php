<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyPackIncompletePreparationException;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class Geo10TechnicalCorrectionTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_name_only_drafts_do_not_starve_reparent_beyond_first_fifty(): void {
		$fixture = $this->many_drafts_fixture( 60, 15, 14 );
		$repo    = $fixture['repo'];
		$town    = $fixture['towns'][0];
		$descendants = $fixture['towns'][0]['descendants'];
		$region_a = $fixture['region_a'];
		$region_b = $fixture['region_b'];
		$name_ids = $fixture['name_only_ids'];

		$state = $this->prepare_until_done( $repo, 'token-geo10', 20, $name_ids, $region_a, $town['root']->id );
		self::assertTrue( $state['prepared']['done'] );
		self::assertGreaterThan( 50, count( $name_ids ) );

		foreach ( $descendants as $child ) {
			$prepared = $repo->find_by_id( $child->id );
			self::assertSame( 'token-geo10', $prepared?->prepared_generation_token );
			self::assertTrue( LocationAncestry::path_contains( (string) $prepared?->prepared_ancestry_path, $region_b->id ) );
			self::assertFalse( LocationAncestry::path_contains( (string) $prepared?->prepared_ancestry_path, $region_a->id ) );
			self::assertTrue( LocationAncestry::path_contains( (string) $prepared?->ancestry_path, $region_a->id ) );
			self::assertFalse( LocationAncestry::path_contains( (string) $prepared?->ancestry_path, $region_b->id ) );
		}

		$repo->finalize_generation( 'token-geo10' );
		$moved = $repo->find_by_id( $town['root']->id );
		self::assertSame( $region_b->id, $moved?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $moved?->ancestry_path, $region_b->id ) );
		foreach ( $descendants as $child ) {
			$live = $repo->find_by_id( $child->id );
			self::assertTrue( LocationAncestry::path_contains( (string) $live?->ancestry_path, $region_b->id ) );
			self::assertFalse( LocationAncestry::path_contains( (string) $live?->ancestry_path, $region_a->id ) );
		}
		unset( $GLOBALS['wpdb'] );
	}

	public function test_multiple_reparent_roots_beyond_first_fifty_prepare_and_switch(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_c = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Eastern', $country->id, GeographyLocationType::Administrative, 1 ) );

		$name_ids = [];
		for ( $i = 1; $i <= 55; ++$i ) {
			$saved = $this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-multi' );
			$name_ids[] = $saved->id;
		}
		$first = $this->save_reparent_with_descendants( $repo, $this->key( 'town', 1 ), 'Town One', $region_a, $region_b, 'token-multi', 8 );
		for ( $i = 56; $i <= 60; ++$i ) {
			$saved = $this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-multi' );
			$name_ids[] = $saved->id;
		}
		$second = $this->save_reparent_with_descendants( $repo, $this->key( 'town', 2 ), 'Town Two', $region_a, $region_c, 'token-multi', 8 );

		self::assertGreaterThan( 50, $first['root']->id );
		self::assertGreaterThan( $first['root']->id, $second['root']->id );

		$state = $this->prepare_until_done( $repo, 'token-multi', 15, $name_ids, $region_a, $first['root']->id, $second['root']->id );
		self::assertTrue( $state['prepared']['done'] );

		foreach ( [ $first, $second ] as $tree ) {
			$target = $tree === $first ? $region_b : $region_c;
			foreach ( $tree['descendants'] as $child ) {
				$prepared = $repo->find_by_id( $child->id );
				self::assertSame( 'token-multi', $prepared?->prepared_generation_token );
				self::assertTrue( LocationAncestry::path_contains( (string) $prepared?->prepared_ancestry_path, $target->id ) );
				self::assertTrue( LocationAncestry::path_contains( (string) $prepared?->ancestry_path, $region_a->id ) );
			}
		}

		$repo->finalize_generation( 'token-multi' );
		self::assertSame( $region_b->id, $repo->find_by_id( $first['root']->id )?->parent_location_id );
		self::assertSame( $region_c->id, $repo->find_by_id( $second['root']->id )?->parent_location_id );
		foreach ( $first['descendants'] as $child ) {
			self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $child->id )?->ancestry_path, $region_b->id ) );
		}
		foreach ( $second['descendants'] as $child ) {
			self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $child->id )?->ancestry_path, $region_c->id ) );
		}
		unset( $wpdb );
	}

	public function test_interrupt_and_resume_between_hierarchy_roots_does_not_skip_or_corrupt(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_c = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Eastern', $country->id, GeographyLocationType::Administrative, 1 ) );
		$name_ids = [];
		for ( $i = 1; $i <= 60; ++$i ) {
			$name_ids[] = $this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-resume' )->id;
		}
		$first  = $this->save_reparent_with_descendants( $repo, $this->key( 'town', 1 ), 'Town One', $region_a, $region_b, 'token-resume', 20 );
		$second = $this->save_reparent_with_descendants( $repo, $this->key( 'town', 2 ), 'Town Two', $region_a, $region_c, 'token-resume', 20 );

		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		$snapshot  = null;
		do {
			$prepared  = $repo->prepare_generation( 'token-resume', 8, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			++$batches;
			if ( null === $snapshot && ( $prepared['hierarchy_root_id'] > 0 || $prepared['hierarchy_root_cursor'] >= $first['root']->id ) ) {
				$snapshot = [
					'after'      => $after,
					'hierarchy'  => $hierarchy,
					'first_done' => $this->count_prepared( $repo, $first['descendants'] ),
				];
				break;
			}
		} while ( ! $prepared['done'] && $batches < 200 );

		self::assertNotNull( $snapshot );
		self::assertLessThan( count( $first['descendants'] ) + count( $second['descendants'] ), $this->count_prepared( $repo, array_merge( $first['descendants'], $second['descendants'] ) ) );

		$after     = $snapshot['after'];
		$hierarchy = $snapshot['hierarchy'];
		do {
			$prepared  = $repo->prepare_generation( 'token-resume', 8, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			self::assertNotContains( $prepared['hierarchy_root_id'], $name_ids );
			++$batches;
		} while ( ! $prepared['done'] && $batches < 400 );

		self::assertTrue( $prepared['done'] );
		self::assertSame( count( $first['descendants'] ), $this->count_prepared( $repo, $first['descendants'] ) );
		self::assertSame( count( $second['descendants'] ), $this->count_prepared( $repo, $second['descendants'] ) );

		foreach ( $first['descendants'] as $child ) {
			$path = '/' . trim( (string) $repo->find_by_id( $child->id )?->prepared_ancestry_path, '/' ) . '/';
			self::assertSame( 1, substr_count( $path, '/' . $region_b->id . '/' ) );
		}

		$repo->finalize_generation( 'token-resume' );
		self::assertSame( $region_b->id, $repo->find_by_id( $first['root']->id )?->parent_location_id );
		self::assertSame( $region_c->id, $repo->find_by_id( $second['root']->id )?->parent_location_id );
		unset( $wpdb );
	}

	public function test_finalize_fails_closed_while_descendant_preparation_outstanding(): void {
		$fixture = $this->many_drafts_fixture( 60, 25, 14 );
		$repo    = $fixture['repo'];
		$town    = $fixture['towns'][0];
		$region_a = $fixture['region_a'];
		$region_b = $fixture['region_b'];

		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		$partial   = false;
		do {
			$prepared  = $repo->prepare_generation( 'token-geo10', 12, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			$ready     = $this->count_prepared( $repo, $town['descendants'] );
			if ( $ready > 0 && $ready < count( $town['descendants'] ) ) {
				$partial = true;
				break;
			}
			++$batches;
		} while ( ! $prepared['done'] && $batches < 200 );

		self::assertTrue( $partial );
		self::assertFalse( $prepared['done'] );

		try {
			$repo->finalize_generation( 'token-geo10' );
			self::fail( 'Finalize must fail closed while descendant hierarchy preparation is outstanding.' );
		} catch ( GeographyPackIncompletePreparationException $e ) {
			self::assertStringContainsString( 'incomplete', strtolower( $e->getMessage() ) );
		}

		$root = $repo->find_by_id( $town['root']->id );
		self::assertSame( $region_a->id, $root?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $root?->ancestry_path, $region_a->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $root?->ancestry_path, $region_b->id ) );
		foreach ( $town['descendants'] as $child ) {
			$live = $repo->find_by_id( $child->id );
			self::assertTrue( LocationAncestry::path_contains( (string) $live?->ancestry_path, $region_a->id ) );
			self::assertFalse( LocationAncestry::path_contains( (string) $live?->ancestry_path, $region_b->id ) );
		}
		unset( $GLOBALS['wpdb'] );
	}

	public function test_ordinary_metadata_drafts_do_not_occupy_hierarchy_root_queue(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );

		$name_ids = [];
		for ( $i = 1; $i <= 40; ++$i ) {
			$name_ids[] = $this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-meta' )->id;
		}
		for ( $i = 41; $i <= 80; ++$i ) {
			$name_ids[] = $this->save_alias_draft( $repo, $this->key( 'alias', $i ), 'Alias ' . $i, $region_a->id, 'token-meta' )->id;
		}
		for ( $i = 81; $i <= 120; ++$i ) {
			$name_ids[] = $this->save_coord_draft( $repo, $this->key( 'coord', $i ), 'Coord ' . $i, $region_a->id, 'token-meta' )->id;
		}
		$town = $this->save_reparent_with_descendants( $repo, $this->key( 'town', 1 ), 'Moved Town', $region_a, $region_b, 'token-meta', 12 );

		$seen_root = [];
		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		do {
			$prepared  = $repo->prepare_generation( 'token-meta', 25, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			if ( $prepared['hierarchy_root_id'] > 0 ) {
				$seen_root[] = $prepared['hierarchy_root_id'];
			}
			self::assertNotContains( $prepared['hierarchy_root_id'], $name_ids );
			++$batches;
		} while ( ! $prepared['done'] && $batches < 400 );

		self::assertTrue( $prepared['done'] );
		self::assertContains( $town['root']->id, $seen_root );
		self::assertSame( count( $town['descendants'] ), $this->count_prepared( $repo, $town['descendants'] ) );
		$repo->finalize_generation( 'token-meta' );
		self::assertSame( $region_b->id, $repo->find_by_id( $town['root']->id )?->parent_location_id );
		unset( $wpdb );
	}

	/**
	 * @param list<int> $name_ids
	 * @return array{prepared: array<string, mixed>, hierarchy: array<string, int>, batches: int}
	 */
	private function prepare_until_done(
		WpdbCanonicalLocationRepository $repo,
		string $token,
		int $limit,
		array $name_ids,
		CanonicalLocation $live_region,
		int ...$allowed_roots
	): array {
		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		do {
			$prepared  = $repo->prepare_generation( $token, $limit, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = $this->hierarchy_from( $prepared );
			self::assertNotContains( $prepared['hierarchy_root_id'], $name_ids );
			if ( $prepared['hierarchy_root_id'] > 0 ) {
				self::assertContains( $prepared['hierarchy_root_id'], $allowed_roots );
			}
			$root = $repo->find_by_id( $allowed_roots[0] );
			self::assertSame( $live_region->id, $root?->parent_location_id );
			self::assertTrue( LocationAncestry::path_contains( (string) $root?->ancestry_path, $live_region->id ) );
			++$batches;
		} while ( ! $prepared['done'] && $batches < 500 );

		self::assertTrue( $prepared['done'] );

		return [
			'prepared'  => $prepared,
			'hierarchy' => $hierarchy,
			'batches'   => $batches,
		];
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

	/**
	 * @param list<CanonicalLocation> $locations
	 */
	private function count_prepared( WpdbCanonicalLocationRepository $repo, array $locations ): int {
		$ready = 0;
		foreach ( $locations as $location ) {
			$fresh = $repo->find_by_id( $location->id );
			if ( $fresh instanceof CanonicalLocation && '' !== $fresh->prepared_generation_token && '' !== $fresh->prepared_ancestry_path ) {
				++$ready;
			}
		}

		return $ready;
	}

	/**
	 * @return array{repo: WpdbCanonicalLocationRepository, region_a: CanonicalLocation, region_b: CanonicalLocation, name_only_ids: list<int>, towns: list<array{root: CanonicalLocation, descendants: list<CanonicalLocation>}>}
	 */
	private function many_drafts_fixture( int $leading_names, int $descendant_count, int $trailing_names ): array {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$name_ids = [];
		for ( $i = 1; $i <= $leading_names; ++$i ) {
			$name_ids[] = $this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-geo10' )->id;
		}
		$town = $this->save_reparent_with_descendants( $repo, $this->key( 'town', 1 ), 'Moved Town', $region_a, $region_b, 'token-geo10', $descendant_count );
		for ( $i = $leading_names + 1; $i <= $leading_names + $trailing_names; ++$i ) {
			$name_ids[] = $this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-geo10' )->id;
		}

		return [
			'repo'          => $repo,
			'region_a'      => $region_a,
			'region_b'      => $region_b,
			'name_only_ids' => $name_ids,
			'towns'         => [ $town ],
			'wpdb'          => $wpdb,
		];
	}

	/**
	 * @return array{root: CanonicalLocation, descendants: list<CanonicalLocation>}
	 */
	private function save_reparent_with_descendants(
		WpdbCanonicalLocationRepository $repo,
		string $key,
		string $name,
		CanonicalLocation $from,
		CanonicalLocation $to,
		string $token,
		int $descendants
	): array {
		$root = $repo->save(
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
		$children = [];
		for ( $i = 1; $i <= $descendants; ++$i ) {
			$children[] = $repo->save(
				$this->blank_location( $key . '-c' . $i, $name . ' Child ' . $i, $root->id, GeographyLocationType::Locality )
			);
		}

		return [
			'root'        => $root,
			'descendants' => $children,
		];
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

	private function save_alias_draft( WpdbCanonicalLocationRepository $repo, string $key, string $name, int $parent_id, string $token ): CanonicalLocation {
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
						'aliases' => [
							[
								'alias'      => $name . ' Alt',
								'normalized' => strtolower( $name . ' Alt' ),
							],
						],
					]
				),
				'',
				$token
			)
		);
	}

	private function save_coord_draft( WpdbCanonicalLocationRepository $repo, string $key, string $name, int $parent_id, string $token ): CanonicalLocation {
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
				5.55,
				-0.2,
				RecordStatus::Active,
				'',
				1,
				(string) json_encode(
					[
						'latitude'  => '6.12',
						'longitude' => '-0.25',
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
			'',
			1
		);
	}

	private function key( string $kind, int $n ): string {
		return sprintf( 'a0000000-0000-4000-8%03s-%012d', substr( $kind, 0, 3 ), $n );
	}
}
