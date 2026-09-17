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
use CetechDeliveryEngine\Domain\Geography\GeographyPack;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class Geo6TechnicalCorrectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['cetech_de_test_options'] = [];
		parent::tearDown();
	}

	public function test_pack_row_failure_rolls_back_geography_promotion(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$packs = new WpdbGeographyPackRepository();
		$active = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, '', 'Ghana' ) );
		$staged = $repo->save(
			$this->location(
				0,
				'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
				RecordStatus::Inactive,
				'token-b',
				'Staged Town',
				$active->id
			)
		);
		$pack = $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Importing->value,
				'progress'     => [
					'target_token'      => 'token-b',
					'active_generation' => 1,
					'target_generation' => 2,
				],
			]
		);
		$wpdb->fail_next_update_table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		try {
			$repo->promote_generation(
				'token-b',
				static function () use ( $packs, $pack ): void {
					$packs->update_progress(
						$pack->id,
						GeographyPackStatus::Ready,
						'0',
						[
							'active_generation' => 2,
							'target_token'      => 'token-b',
							'dataset_checksum'  => 'sum',
						],
						'',
						gmdate( 'Y-m-d H:i:s' )
					);
				}
			);
			self::fail( 'Promotion should throw when pack persistence fails.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'pack', $e->getMessage() );
		}
		self::assertFalse( $repo->find_by_id( $staged->id )?->isActive() );
		self::assertTrue( $repo->find_by_id( $active->id )?->isActive() );
		self::assertSame( GeographyPackStatus::Importing, $packs->find_by_id( $pack->id )?->status );
	}

	public function test_importer_ready_progress_failure_rolls_back_new_geography(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$file     = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '88', 'Rollbackville', 'Rollbackville', 'P', 'PPL', '01', '' ),
			]
		);
		$pack = $importer->begin_dataset( $importer->ensure_pack( 'GH', $file ), $file, 'win', '1' );
		$packs->fail_next_ready_progress = true;
		$result = $this->run_import( $importer, $packs, $pack, $file );
		self::assertSame( GeographyPackStatus::Failed->value, $result['status'] ?? '' );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '88' ) );
		self::assertSame( GeographyPackStatus::Failed, $packs->find_by_id( $pack->id )?->status );
		unlink( $file );
	}

	public function test_ancestry_update_failure_rolls_back_promotion(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, '', 'Ghana' ) );
		$admin   = $repo->save(
			$this->location( 0, 'cccccccc-cccc-cccc-cccc-cccccccccccc', RecordStatus::Active, '', 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 )
		);
		$draft = [
			'generation_token'   => 'token-b',
			'canonical_name'     => 'Accra',
			'parent_location_id' => $admin->id,
		];
		$locality = $repo->save(
			new CanonicalLocation(
				0,
				'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
				'GH',
				$country->id,
				GeographyLocationType::Locality,
				null,
				'Accra',
				'accra',
				'accra',
				null,
				null,
				RecordStatus::Active,
				'/' . $country->id . '/',
				1,
				(string) json_encode( $draft ),
				'',
				'token-b'
			)
		);
		$wpdb->fail_next_update_column = 'ancestry_path';
		try {
			$repo->promote_generation( 'token-b' );
			self::fail( 'Promotion should throw when ancestry persistence fails.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'ancestry', $e->getMessage() );
		}
		$again = $repo->find_by_id( $locality->id );
		self::assertSame( $country->id, $again?->parent_location_id );
		self::assertSame( 'token-b', $again?->draft_generation_token );
	}

	public function test_staged_mapping_delete_failure_rolls_back_promotion(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbCanonicalLocationRepository();
		$active = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, '', 'Ghana' ) );
		$staged = $repo->save(
			$this->location( 0, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', RecordStatus::Inactive, 'token-b', 'Staged Town', $active->id )
		);
		$mappings = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$wpdb->insert(
			$mappings,
			[
				'location_id'               => $staged->id,
				'provider'                  => GeographyProvider::GeoNames->value,
				'external_id'               => '99',
				'pack_id'                   => null,
				'dataset_version'           => '1',
				'provider_parent_reference' => '',
				'feature_class'             => 'P',
				'feature_code'              => 'PPL',
				'provider_metadata_json'    => '{}',
				'generation_token'          => 'token-b',
			]
		);
		$wpdb->fail_next_delete_table = $mappings;
		try {
			$repo->promote_generation( 'token-b' );
			self::fail( 'Promotion should throw when staged mapping delete fails.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'mapping', $e->getMessage() );
		}
		self::assertFalse( $repo->find_by_id( $staged->id )?->isActive() );
		$remaining = $wpdb->table_rows( $mappings );
		self::assertCount( 1, $remaining );
		self::assertSame( 'token-b', (string) ( $remaining[0]['generation_token'] ?? '' ) );
	}

	public function test_stale_abandoned_lock_is_recovered(): void {
		$service = $this->service();
		$key     = 'cetech_de_geo_pack_cas_42';
		add_option(
			$key,
			[
				'owner'       => 'dead:worker',
				'role'        => 'tick',
				'acquired_at' => 1,
				'expires_at'  => time() - 10,
			],
			'',
			false
		);
		$owner = $this->invoke_lock( $service, 'acquire_lifecycle_lock', 42, 'tick' );
		self::assertNotSame( '', $owner );
		$lease = get_option( $key );
		self::assertIsArray( $lease );
		self::assertSame( $owner, $lease['owner'] ?? null );
	}

	public function test_live_non_stale_lock_rejects_second_worker(): void {
		$service = $this->service();
		$first   = $this->invoke_lock( $service, 'acquire_lifecycle_lock', 7, 'tick' );
		self::assertNotSame( '', $first );
		$second = $this->invoke_lock( $service, 'acquire_lifecycle_lock', 7, 'update' );
		self::assertSame( '', $second );
	}

	public function test_wrong_owner_cannot_release_another_workers_lock(): void {
		$service = $this->service();
		$owner   = $this->invoke_lock( $service, 'acquire_lifecycle_lock', 9, 'tick' );
		self::assertNotSame( '', $owner );
		$this->invoke_lock( $service, 'release_lifecycle_lock', 9, 'other-owner' );
		$lease = get_option( 'cetech_de_geo_pack_cas_9' );
		self::assertIsArray( $lease );
		self::assertSame( $owner, $lease['owner'] ?? null );
		$this->invoke_lock( $service, 'release_lifecycle_lock', 9, $owner );
		self::assertFalse( get_option( 'cetech_de_geo_pack_cas_9', false ) );
	}

	public function test_update_rereads_latest_pack_after_lock(): void {
		$geo     = new GhanaGeographyFixture();
		$inner   = new InMemoryGeographyPackRepository();
		$fresh   = $inner->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'dataset_version'  => 'old',
				'checksum'         => 'keep-me',
				'status'           => GeographyPackStatus::Ready->value,
				'source_reference' => '/tmp/gh.txt',
				'progress'         => [
					'attempt_seq'       => 9,
					'active_generation' => 1,
					'target_generation' => 1,
					'target_token'      => 'old-token',
					'last_successful'   => [
						'checksum'         => 'keep-me',
						'dataset_version'  => 'old',
					],
				],
			]
		);
		$packs    = new class( $inner ) implements GeographyPackRepositoryInterface {
			public function __construct(
				private InMemoryGeographyPackRepository $inner
			) {
			}

			public function find_by_id( int $id ): ?GeographyPack {
				return $this->inner->find_by_id( $id );
			}

			public function find_by_country_provider( string $country_code, GeographyProvider $provider, string $dataset_name = 'gazetteer' ): ?GeographyPack {
				$fresh = $this->inner->find_by_country_provider( $country_code, $provider, $dataset_name );
				if ( ! $fresh instanceof GeographyPack ) {
					return null;
				}

				return new GeographyPack(
					$fresh->id,
					$fresh->country_code,
					$fresh->provider,
					$fresh->dataset_name,
					$fresh->dataset_version,
					$fresh->source_url,
					$fresh->source_reference,
					$fresh->checksum,
					$fresh->license_name,
					$fresh->license_url,
					$fresh->attribution_text,
					$fresh->status,
					$fresh->import_cursor,
					[
						'attempt_seq'       => 1,
						'active_generation' => 0,
						'target_generation' => 0,
					],
					$fresh->last_error,
					$fresh->installed_at
				);
			}

			public function list_all(): array {
				return $this->inner->list_all();
			}

			public function save( array $payload ): GeographyPack {
				return $this->inner->save( $payload );
			}

			public function update_progress(
				int $id,
				GeographyPackStatus $status,
				string $cursor,
				array $progress,
				string $last_error = '',
				?string $installed_at = null
			): void {
				$this->inner->update_progress( $id, $status, $cursor, $progress, $last_error, $installed_at );
			}
		};
		$importer = $this->importer( $geo, $packs );
		$service  = new GeographyPackService( $packs, $importer, new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ) );
		$file     = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
			]
		);
		$service->update( 'GH', $file );
		$after = $inner->find_by_id( $fresh->id );
		self::assertSame( 10, (int) ( $after?->progress['attempt_seq'] ?? 0 ) );
		self::assertSame( 'keep-me', (string) ( $after?->progress['last_successful']['checksum'] ?? '' ) );
		unlink( $file );
	}

	public function test_promotion_queries_token_scoped_drafts_only(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( WpdbCanonicalLocationRepository::class ) )->getFileName() );
		self::assertStringContainsString( 'WHERE draft_generation_token = %s', $src );
		self::assertDoesNotMatchRegularExpression( '/SELECT \* FROM `\{\$table\}` WHERE status = %s/', $src );

		$geo = new GhanaGeographyFixture();
		$ignored = $geo->locations->save(
			new CanonicalLocation(
				$geo->accra->id,
				$geo->accra->location_key,
				$geo->accra->country_code,
				$geo->accra->parent_location_id,
				$geo->accra->location_type,
				$geo->accra->administrative_level,
				$geo->accra->canonical_name,
				$geo->accra->normalized_name,
				$geo->accra->ascii_name,
				$geo->accra->latitude,
				$geo->accra->longitude,
				$geo->accra->status,
				$geo->accra->ancestry_path,
				$geo->accra->generation,
				(string) json_encode(
					[
						'generation_token' => 'token-b',
						'canonical_name'   => 'Should Not Apply',
					]
				),
				$geo->accra->generation_token,
				''
			)
		);
		$target = $geo->locations->save(
			new CanonicalLocation(
				$geo->tema->id,
				$geo->tema->location_key,
				$geo->tema->country_code,
				$geo->tema->parent_location_id,
				$geo->tema->location_type,
				$geo->tema->administrative_level,
				$geo->tema->canonical_name,
				$geo->tema->normalized_name,
				$geo->tema->ascii_name,
				$geo->tema->latitude,
				$geo->tema->longitude,
				$geo->tema->status,
				$geo->tema->ancestry_path,
				$geo->tema->generation,
				(string) json_encode(
					[
						'generation_token' => 'token-b',
						'canonical_name'   => 'Tema Harbour',
					]
				),
				$geo->tema->generation_token,
				'token-b'
			)
		);
		unset( $ignored, $target );
		$geo->locations->promote_generation( 'token-b' );
		self::assertSame( 'Accra', $geo->locations->find_by_id( $geo->accra->id )?->canonical_name );
		self::assertSame( 'Tema Harbour', $geo->locations->find_by_id( $geo->tema->id )?->canonical_name );
	}

	public function test_staged_alias_is_invisible_to_live_lookup(): void {
		$geo = new GhanaGeographyFixture();
		$geo->locations->add_alias( $geo->accra->id, 'Secret Staging', 'secret staging', '', 'alternate', false, 'token-b' );
		self::assertNull( $geo->locations->find_exact( 'GH', 'secret staging' ) );
		self::assertNotContains( 'Secret Staging', $geo->locations->list_for_location( $geo->accra->id ) );
		$geo->locations->promote_generation( 'token-b' );
		self::assertNotNull( $geo->locations->find_exact( 'GH', 'secret staging' ) );
		self::assertContains( 'Secret Staging', $geo->locations->list_for_location( $geo->accra->id ) );
	}

	public function test_staged_provider_mapping_is_invisible_to_live_lookup(): void {
		$geo = new GhanaGeographyFixture();
		$geo->locations->upsert(
			$geo->accra->id,
			GeographyProvider::GeoNames,
			'staged-ext',
			null,
			'1',
			'',
			'',
			'',
			[],
			'token-b'
		);
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, 'staged-ext' ) );
		self::assertNull( $geo->locations->find_external_id( $geo->accra->id, GeographyProvider::GeoNames ) );
		$geo->locations->promote_generation( 'token-b' );
		self::assertSame( $geo->accra->id, $geo->locations->find_location_id( GeographyProvider::GeoNames, 'staged-ext' ) );
	}

	public function test_failed_target_cleanup_does_not_affect_active_dataset(): void {
		$geo   = new GhanaGeographyFixture();
		$accra = $geo->accra;
		$failed = $geo->locations->save(
			new CanonicalLocation(
				0,
				'dddddddd-dddd-dddd-dddd-dddddddddddd',
				'GH',
				$geo->greater_accra->id,
				GeographyLocationType::Locality,
				null,
				'Failedville',
				'failedville',
				'failedville',
				null,
				null,
				RecordStatus::Inactive,
				'',
				2,
				'',
				'token-fail'
			)
		);
		$geo->locations->upsert( $failed->id, GeographyProvider::GeoNames, '77', null, '1', '', '', '', [], 'token-fail' );
		$geo->locations->add_alias( $failed->id, 'Ghost Alias', 'ghost alias', '', 'alternate', false, 'token-fail' );
		$geo->locations->abandon_generation( 'token-fail' );
		self::assertNull( $geo->locations->find_by_id( $failed->id ) );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '77', 'token-fail' ) );
		self::assertTrue( $geo->locations->find_by_id( $accra->id )?->isActive() );
		self::assertSame( 'Accra', $geo->locations->find_by_id( $accra->id )?->canonical_name );
		self::assertSame( 'Greater Accra', $geo->locations->find_by_id( $geo->greater_accra->id )?->canonical_name );
	}

	public function test_in_memory_ancestry_and_mapping_delete_failures_roll_back(): void {
		$geo = new GhanaGeographyFixture();
		$geo->locations->fail_next_ancestry = true;
		$geo->locations->save(
			new CanonicalLocation(
				$geo->madina->id,
				$geo->madina->location_key,
				$geo->madina->country_code,
				$geo->madina->parent_location_id,
				$geo->madina->location_type,
				$geo->madina->administrative_level,
				$geo->madina->canonical_name,
				$geo->madina->normalized_name,
				$geo->madina->ascii_name,
				$geo->madina->latitude,
				$geo->madina->longitude,
				$geo->madina->status,
				$geo->madina->ancestry_path,
				$geo->madina->generation,
				(string) json_encode(
					[
						'generation_token'   => 'token-b',
						'canonical_name'     => $geo->madina->canonical_name,
						'parent_location_id' => $geo->ashanti->id,
					]
				),
				$geo->madina->generation_token,
				'token-b'
			)
		);
		try {
			$geo->locations->promote_generation( 'token-b' );
			self::fail( 'Ancestry failure should throw.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'ancestry', $e->getMessage() );
		}
		self::assertSame( $geo->greater_accra->id, $geo->locations->find_by_id( $geo->madina->id )?->parent_location_id );

		$geo->locations->upsert( $geo->accra->id, GeographyProvider::GeoNames, 'rollback-map', null, '1', '', '', '', [], 'token-c' );
		$geo->locations->fail_next_staged_mapping_delete = true;
		try {
			$geo->locations->promote_generation( 'token-c' );
			self::fail( 'Mapping delete failure should throw.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'mapping', $e->getMessage() );
		}
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, 'rollback-map' ) );
		self::assertSame( $geo->accra->id, $geo->locations->find_location_id( GeographyProvider::GeoNames, 'rollback-map', 'token-c' ) );
	}

	private function service(): GeographyPackService {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();

		return new GeographyPackService(
			$packs,
			$this->importer( $geo, $packs ),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
	}

	private function invoke_lock( GeographyPackService $service, string $method, int $pack_id, string $owner_or_role ): string {
		$ref = new \ReflectionMethod( $service, $method );
		$ref->setAccessible( true );
		$result = $ref->invoke( $service, $pack_id, $owner_or_role );

		return is_string( $result ) ? $result : '';
	}

	private function importer( GhanaGeographyFixture $geo, GeographyPackRepositoryInterface $packs ): GeoNamesPackImporter {
		return new GeoNamesPackImporter(
			$geo->locations,
			$geo->locations,
			$geo->locations,
			$packs,
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ),
			new GeoNamesGazetteerParser()
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

	/**
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geo6' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		return $file;
	}

	private function row( string $id, string $name, string $ascii, string $class, string $code, string $admin1, string $admin2 = '', string $country = 'GH' ): string {
		$parts = array_fill( 0, 19, '' );
		$parts[0]  = $id;
		$parts[1]  = $name;
		$parts[2]  = $ascii;
		$parts[6]  = $class;
		$parts[7]  = $code;
		$parts[8]  = $country;
		$parts[10] = $admin1;
		$parts[11] = $admin2;

		return implode( "\t", $parts );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function run_import( GeoNamesPackImporter $importer, InMemoryGeographyPackRepository $packs, $pack, string $file ): array {
		$result = [ 'status' => GeographyPackStatus::Importing->value ];
		$guard  = 0;
		while ( $guard < 50 ) {
			$pack = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 20 );
			++$guard;
			if ( GeographyPackStatus::Ready->value === ( $result['status'] ?? '' ) ) {
				break;
			}
			if ( GeographyPackStatus::Failed->value === ( $result['status'] ?? '' ) ) {
				break;
			}
		}

		return $result;
	}
}
