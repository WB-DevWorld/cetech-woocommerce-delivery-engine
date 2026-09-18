<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyPackConcurrentPromotionException;
use CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class Geo8TechnicalCorrectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['cetech_de_test_options'] = [];
		parent::tearDown();
	}

	public function test_expired_lease_stale_takeover_blocks_old_renew(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$packs = new WpdbGeographyPackRepository();
		$pack  = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'progress'     => [ 'target_token' => 'token-a' ],
			]
		);
		$now   = time();
		$owner_a = $packs->acquire_lease( $pack->id, 'tick', $now, 120 );
		self::assertNotSame( '', $owner_a );
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$wpdb->update( $table, [ 'lease_expires_at' => $now - 10 ], [ 'id' => $pack->id ] );
		$owner_b = $packs->acquire_lease( $pack->id, 'tick', $now, 120 );
		self::assertNotSame( '', $owner_b );
		self::assertNotSame( $owner_a, $owner_b );
		self::assertFalse( $packs->renew_lease( $pack->id, $owner_a, $now + 1, 120 ) );
		self::assertSame( $owner_b, $packs->current_lease( $pack->id )['owner'] );
		unset( $wpdb );
	}

	public function test_expired_lease_stale_takeover_blocks_old_release(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$packs = new WpdbGeographyPackRepository();
		$pack  = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'progress'     => [ 'target_token' => 'token-a' ],
			]
		);
		$now     = time();
		$owner_a = $packs->acquire_lease( $pack->id, 'tick', $now, 120 );
		$table   = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$wpdb->update( $table, [ 'lease_expires_at' => $now - 10 ], [ 'id' => $pack->id ] );
		$owner_b = $packs->acquire_lease( $pack->id, 'tick', $now, 120 );
		self::assertNotSame( '', $owner_b );
		self::assertFalse( $packs->release_lease( $pack->id, $owner_a ) );
		self::assertSame( $owner_b, $packs->current_lease( $pack->id )['owner'] );
		unset( $wpdb );
	}

	public function test_target_fence_ready_update_affects_zero_rows(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$packs = new WpdbGeographyPackRepository();
		$pack  = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'progress'     => [
					'target_token'      => 'token-b',
					'active_generation' => 1,
					'last_successful'   => [
						'checksum'         => 'keep-me',
						'generation_token' => 'live-token',
					],
				],
			]
		);
		try {
			$packs->update_progress(
				$pack->id,
				GeographyPackStatus::Ready,
				'0',
				[
					'target_token'      => 'token-a',
					'active_generation' => 2,
					'dataset_checksum'  => 'sum',
				],
				'',
				gmdate( 'Y-m-d H:i:s' ),
				'token-a'
			);
			self::fail( 'Stale token Ready write must throw a fence exception.' );
		} catch ( GeographyPackTokenFenceException $e ) {
			self::assertStringContainsString( 'token fence', $e->getMessage() );
		}
		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Importing, $fresh?->status );
		self::assertSame( 'token-b', $fresh?->target_token() );
		self::assertSame( 'keep-me', (string) ( $fresh?->last_successful()['checksum'] ?? '' ) );
		unset( $wpdb );
	}

	public function test_same_target_only_one_finalization_commits(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$repo  = new WpdbCanonicalLocationRepository();
		$packs = new WpdbGeographyPackRepository();
		$active = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, '', 'Ghana' ) );
		$staged = $repo->save(
			$this->location( 0, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', RecordStatus::Inactive, 'token-a', 'Staged Town', $active->id )
		);
		$pack = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'progress'     => [
					'target_token'      => 'token-a',
					'target_generation' => 2,
					'active_generation' => 1,
				],
			]
		);
		$ready = static function () use ( $packs, $pack ): void {
			$packs->update_progress(
				$pack->id,
				GeographyPackStatus::Ready,
				'0',
				[
					'target_token'      => 'token-a',
					'active_generation' => 2,
					'dataset_checksum'  => 'sum',
					'last_successful'   => [ 'generation_token' => 'token-a' ],
				],
				'',
				gmdate( 'Y-m-d H:i:s' ),
				'token-a'
			);
		};
		$first = $repo->finalize_generation( 'token-a', $ready );
		self::assertSame( 1, $first );
		self::assertTrue( (bool) $repo->find_by_id( $staged->id )?->isActive() );
		try {
			$repo->finalize_generation( 'token-a', $ready );
			self::fail( 'Second finalization of the same target must not commit.' );
		} catch ( GeographyPackConcurrentPromotionException $e ) {
			self::assertStringContainsString( 'zero rows', $e->getMessage() );
		}
		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Ready, $fresh?->status );
		self::assertSame( 'token-a', (string) ( $fresh?->last_successful()['generation_token'] ?? '' ) );
		unset( $wpdb );
	}

	public function test_official_download_supersedes_unfinished_target_staging(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$pack  = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Importing->value,
				'checksum'         => 'keep-me',
				'source_reference' => '/tmp/gh-live.txt',
				'progress'         => [
					'target_token'      => 'token-a',
					'active_generation' => 1,
					'last_successful'   => [
						'checksum'         => 'keep-me',
						'dataset_version'  => 'old',
						'generation_token' => 'live-token',
						'source_reference' => '/tmp/gh-live.txt',
					],
				],
			]
		);
		$staged = $geo->locations->save(
			new CanonicalLocation(
				0,
				'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
				'GH',
				$geo->greater_accra->id,
				GeographyLocationType::Locality,
				null,
				'Ghost Town',
				'ghost town',
				'ghost town',
				null,
				null,
				RecordStatus::Inactive,
				'',
				2,
				'',
				'token-a'
			)
		);
		$geo->locations->upsert( $staged->id, GeographyProvider::GeoNames, 'ghost-ext', $pack->id, 'A', '', '', '', [], 'token-a' );
		$geo->locations->add_alias( $staged->id, 'Ghost Alias', 'ghost alias', '', 'alternate', false, 'token-a' );
		$service = new GeographyPackService(
			$packs,
			new GeoNamesPackImporter(
				$geo->locations,
				$geo->locations,
				$geo->locations,
				$packs,
				new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ),
				new GeoNamesGazetteerParser()
			),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$updated = $service->queue_official_download( 'GH' );
		self::assertNotSame( 'token-a', $updated->target_token() );
		self::assertSame( 'keep-me', (string) ( $updated->last_successful()['checksum'] ?? '' ) );
		self::assertSame( 'live-token', (string) ( $updated->last_successful()['generation_token'] ?? '' ) );
		self::assertNull( $geo->locations->find_by_id( $staged->id ) );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, 'ghost-ext', 'token-a' ) );
		self::assertSame( 'Accra', $geo->locations->find_by_id( $geo->accra->id )?->canonical_name );
	}

	public function test_incoming_file_is_removed_after_immutable_copy(): void {
		$dir = sys_get_temp_dir() . '/cetech-geo8-in-' . bin2hex( random_bytes( 4 ) );
		self::assertTrue( mkdir( $dir, 0777, true ) );
		$incoming = $dir . '/GH.abc123.incoming.txt';
		$immutable = $dir . '/GH.token-a.deadbeefdead.txt';
		file_put_contents( $incoming, "gazetteer\n" );
		$geo     = new GhanaGeographyFixture();
		$packs   = new InMemoryGeographyPackRepository();
		$service = new GeographyPackService(
			$packs,
			new GeoNamesPackImporter(
				$geo->locations,
				$geo->locations,
				$geo->locations,
				$packs,
				new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
			),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$ref = new \ReflectionMethod( $service, 'cleanup_incoming_source' );
		$ref->setAccessible( true );
		$ref->invoke( $service, $incoming, $dir, $immutable );
		self::assertFileDoesNotExist( $incoming );
		@rmdir( $dir );
	}

	public function test_cleanup_includes_stale_incoming_without_deleting_active_source(): void {
		$dir = sys_get_temp_dir() . '/cetech-geo8-clean-' . bin2hex( random_bytes( 4 ) );
		self::assertTrue( mkdir( $dir, 0777, true ) );
		$active    = $dir . '/GH.keep-token.abcabcabcabc.txt';
		$incoming  = $dir . '/GH.old-token.incoming.txt';
		$abandoned = $dir . '/GH.old-token.defdefdefdef.txt';
		file_put_contents( $active, "keep\n" );
		file_put_contents( $incoming, "tmp\n" );
		file_put_contents( $abandoned, "old\n" );
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$pack  = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Importing->value,
				'source_reference' => $active,
				'progress'         => [
					'target_token'    => 'new-token',
					'last_successful' => [
						'checksum'         => 'abcabcabcabcffffffffffffffff',
						'source_reference' => $active,
						'generation_token' => 'keep-token',
					],
				],
			]
		);
		$service = new GeographyPackService(
			$packs,
			new GeoNamesPackImporter(
				$geo->locations,
				$geo->locations,
				$geo->locations,
				$packs,
				new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
			),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$deleted = $service->cleanup_abandoned_generation_files( $pack, 'old-token', $dir );
		self::assertGreaterThan( 0, $deleted );
		self::assertFileExists( $active );
		self::assertFileDoesNotExist( $incoming );
		self::assertFileDoesNotExist( $abandoned );
		@unlink( $active );
		@rmdir( $dir );
	}

	public function test_large_generation_prepare_is_bounded_and_finalize_is_set_based(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$repo  = new WpdbCanonicalLocationRepository();
		$packs = new WpdbGeographyPackRepository();
		$country = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, '', 'Ghana' ) );
		for ( $i = 0; $i < 250; $i++ ) {
			$repo->save(
				$this->location(
					0,
					sprintf( 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbb%06d', $i ),
					RecordStatus::Inactive,
					'token-big',
					'Place ' . $i,
					$country->id,
					GeographyLocationType::Locality
				)
			);
		}
		$pack = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'progress'     => [
					'target_token'      => 'token-big',
					'target_generation' => 2,
				],
			]
		);
		$prepared = $repo->prepare_generation( 'token-big', 50, 0 );
		self::assertTrue( $prepared['done'] );
		self::assertSame( 0, $prepared['processed'] );
		$selects = array_values(
			array_filter(
				$wpdb->sql_log,
				static fn( string $sql ): bool => str_starts_with( strtoupper( trim( $sql ) ), 'SELECT *' )
					&& str_contains( $sql, 'draft_generation_token' )
			)
		);
		self::assertNotEmpty( $selects );
		self::assertTrue( str_contains( strtoupper( $selects[0] ), 'LIMIT 50' ) );
		$before = $wpdb->id_only_updates;
		$activated = $repo->finalize_generation(
			'token-big',
			static function () use ( $packs, $pack ): void {
				$packs->update_progress(
					$pack->id,
					GeographyPackStatus::Ready,
					'0',
					[
						'target_token'      => 'token-big',
						'active_generation' => 2,
						'dataset_checksum'  => 'sum',
						'last_successful'   => [ 'generation_token' => 'token-big' ],
					],
					'',
					gmdate( 'Y-m-d H:i:s' ),
					'token-big'
				);
			}
		);
		self::assertSame( 250, $activated );
		self::assertLessThan( 10, $wpdb->id_only_updates - $before );
		$set_based = false;
		foreach ( $wpdb->sql_log as $sql ) {
			if ( str_contains( $sql, 'generation_token' ) && str_contains( $sql, 'inactive' ) && str_starts_with( strtoupper( trim( $sql ) ), 'UPDATE' ) ) {
				$set_based = true;
				break;
			}
		}
		self::assertTrue( $set_based );
		self::assertTrue( (bool) $repo->find_by_id( $country->id )?->isActive() );
		unset( $wpdb );
	}

	public function test_promotion_failure_leaves_old_active_authoritative(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$repo  = new WpdbCanonicalLocationRepository();
		$packs = new WpdbGeographyPackRepository();
		$active = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, 'live-token', 'Ghana' ) );
		$staged = $repo->save(
			$this->location( 0, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', RecordStatus::Inactive, 'token-b', 'Staged Town', $active->id )
		);
		$pack = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'checksum'     => 'keep-me',
				'progress'     => [
					'target_token'      => 'token-b',
					'active_generation' => 1,
					'last_successful'   => [
						'checksum'         => 'keep-me',
						'generation_token' => 'live-token',
					],
				],
			]
		);
		$wpdb->fail_next_update_table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		try {
			$repo->finalize_generation(
				'token-b',
				static function () use ( $packs, $pack ): void {
					$packs->update_progress(
						$pack->id,
						GeographyPackStatus::Ready,
						'0',
						[
							'target_token'      => 'token-b',
							'active_generation' => 2,
							'dataset_checksum'  => 'sum',
						],
						'',
						gmdate( 'Y-m-d H:i:s' ),
						'token-b'
					);
				}
			);
			self::fail( 'Pack Ready failure must abort promotion.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'Failed to update geography pack progress', $e->getMessage() );
		}
		self::assertTrue( (bool) $repo->find_by_id( $active->id )?->isActive() );
		self::assertFalse( (bool) $repo->find_by_id( $staged->id )?->isActive() );
		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Importing, $fresh?->status );
		self::assertSame( 'keep-me', (string) ( $fresh?->last_successful()['checksum'] ?? '' ) );
		self::assertSame( 'live-token', (string) ( $fresh?->last_successful()['generation_token'] ?? '' ) );
		unset( $wpdb );
	}

	public function test_prepare_does_not_expose_active_name_changes(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$active = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, 'live-token', 'Accra' ) );
		$repo->save(
			new CanonicalLocation(
				$active->id,
				$active->location_key,
				$active->country_code,
				$active->parent_location_id,
				$active->location_type,
				$active->administrative_level,
				$active->canonical_name,
				$active->normalized_name,
				$active->ascii_name,
				$active->latitude,
				$active->longitude,
				$active->status,
				$active->ancestry_path,
				$active->generation,
				(string) json_encode( [ 'canonical_name' => 'Accra Metro', 'normalized_name' => 'accra metro' ] ),
				$active->generation_token,
				'token-prep'
			)
		);
		$prepared = $repo->prepare_generation( 'token-prep', 50, 0 );
		self::assertSame( 1, $prepared['processed'] );
		self::assertSame( 'Accra', $repo->find_by_id( $active->id )?->canonical_name );
		$repo->finalize_generation( 'token-prep' );
		self::assertSame( 'Accra Metro', $repo->find_by_id( $active->id )?->canonical_name );
		unset( $wpdb );
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

	private function location(
		int $id,
		string $key,
		RecordStatus $status,
		string $token,
		string $name,
		?int $parent = null,
		GeographyLocationType $type = GeographyLocationType::Country,
		?int $level = null
	): CanonicalLocation {
		return new CanonicalLocation(
			$id,
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
			$status,
			$parent ? '/1/' : '/1/',
			$status === RecordStatus::Inactive ? 2 : 1,
			'',
			$token
		);
	}
}
