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
use CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProviderMappingRepository;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class Geo7TechnicalCorrectionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['cetech_de_test_options'] = [];
		parent::tearDown();
	}

	public function test_expired_old_worker_cannot_finalize_after_newer_target(): void {
		$wpdb  = $this->wpdb_with_geography_tables();
		$repo  = new WpdbCanonicalLocationRepository();
		$packs = new WpdbGeographyPackRepository();
		$active = $repo->save( $this->location( 0, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', RecordStatus::Active, '', 'Ghana' ) );
		$staged = $repo->save(
			$this->location(
				0,
				'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
				RecordStatus::Inactive,
				'token-a',
				'Staged Town',
				$active->id
			)
		);
		$pack = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Importing->value,
				'progress'         => [
					'target_token'      => 'token-b',
					'active_generation' => 1,
					'target_generation' => 2,
					'last_successful'   => [
						'checksum'         => 'keep-me',
						'dataset_version'  => 'old',
						'generation_token' => 'live-token',
					],
				],
			]
		);
		$service = $this->service();
		$old     = $this->invoke( $service, 'acquire_lifecycle_lock', $pack->id, 'tick' );
		self::assertNotSame( '', $old );
		$key   = 'cetech_de_geo_pack_cas_' . $pack->id;
		$lease = get_option( $key );
		self::assertIsArray( $lease );
		$lease['expires_at'] = time() - 10;
		update_option( $key, $lease, false );
		$new = $this->invoke( $service, 'acquire_lifecycle_lock', $pack->id, 'tick' );
		self::assertNotSame( '', $new );
		self::assertNotSame( $old, $new );
		self::assertFalse( $this->invoke( $service, 'renew_lifecycle_lock', $pack->id, $old ) );
		self::assertTrue( $this->invoke( $service, 'owns_lifecycle_lock', $pack->id, $new ) );

		try {
			$repo->promote_generation(
				'token-a',
				static function () use ( $packs, $pack ): void {
					$packs->update_progress(
						$pack->id,
						GeographyPackStatus::Ready,
						'0',
						[
							'active_generation' => 2,
							'target_token'      => 'token-a',
							'dataset_checksum'  => 'sum',
							'last_successful'   => [
								'generation_token' => 'token-a',
							],
						],
						'',
						gmdate( 'Y-m-d H:i:s' ),
						'token-a'
					);
				}
			);
			self::fail( 'Old worker must not finalize after a newer target is created.' );
		} catch ( GeographyPackTokenFenceException $e ) {
			self::assertStringContainsString( 'token fence', $e->getMessage() );
		}

		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Importing, $fresh?->status );
		self::assertSame( 'token-b', $fresh?->target_token() );
		self::assertSame( 'keep-me', (string) ( $fresh?->last_successful()['checksum'] ?? '' ) );
		self::assertSame( 'live-token', (string) ( $fresh?->last_successful()['generation_token'] ?? '' ) );
		self::assertFalse( (bool) $repo->find_by_id( $staged->id )?->isActive() );
		unset( $wpdb );
	}

	public function test_provider_mapping_upsert_throws_on_db_failure(): void {
		$wpdb = $this->wpdb_with_geography_tables();
		$repo = new WpdbProviderMappingRepository();
		$wpdb->fail_next_insert_table = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Failed to write provider mapping' );
		$repo->upsert( 1, GeographyProvider::GeoNames, '2301660', 9, '1', '', 'A', 'ADM1', [], 'token-a' );
	}

	public function test_mapping_write_failure_fails_target_and_keeps_prior_active(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$file  = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
			]
		);
		$pack = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'dataset_version'  => 'old',
				'checksum'         => 'keep-me',
				'status'           => GeographyPackStatus::Ready->value,
				'source_reference' => $file,
				'progress'         => [
					'active_generation' => 1,
					'target_generation' => 1,
					'target_token'      => 'live-token',
					'last_successful'   => [
						'checksum'         => 'keep-me',
						'dataset_version'  => 'old',
						'source_reference' => $file,
						'generation_token' => 'live-token',
						'active_generation' => 1,
					],
				],
			]
		);
		$importer = $this->importer( $geo, $packs );
		$updated  = $importer->begin_dataset( $pack, $file, hash_file( 'sha256', $file ) ?: 'new', 'new' );
		$geo->locations->fail_next_upsert = true;
		$result = $importer->import_batch( $updated, $file, 20 );
		self::assertSame( GeographyPackStatus::Failed->value, $result['status'] ?? '' );
		self::assertSame( 'import_write_failed', $result['error'] ?? '' );
		$fresh = $packs->find_by_id( $updated->id );
		self::assertSame( GeographyPackStatus::Failed, $fresh?->status );
		self::assertSame( 'keep-me', (string) ( $fresh?->last_successful()['checksum'] ?? '' ) );
		self::assertSame( 'live-token', (string) ( $fresh?->last_successful()['generation_token'] ?? '' ) );
		self::assertTrue( $geo->locations->find_by_id( $geo->ghana->id )?->isActive() ?? false );
		self::assertTrue( $geo->locations->find_by_id( $geo->greater_accra->id )?->isActive() ?? false );
	}

	public function test_checksum_mismatch_marks_target_failed_and_keeps_last_successful(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$file  = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
			]
		);
		$pack = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'checksum'         => 'not-the-file-checksum',
				'status'           => GeographyPackStatus::Importing->value,
				'source_reference' => $file,
				'progress'         => [
					'target_token'      => 'token-a',
					'active_generation' => 1,
					'last_successful'   => [
						'checksum'         => 'keep-me',
						'dataset_version'  => 'old',
						'generation_token' => 'live-token',
					],
				],
			]
		);
		$service = new GeographyPackService(
			$packs,
			$this->importer( $geo, $packs ),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$result = $service->tick( $pack->id, $file, 20, 'token-a' );
		self::assertSame( GeographyPackStatus::Failed->value, $result['status'] ?? '' );
		self::assertSame( 'checksum_mismatch', $result['error'] ?? '' );
		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Failed, $fresh?->status );
		self::assertStringContainsString( 'checksum', (string) $fresh?->last_error );
		self::assertSame( 'keep-me', (string) ( $fresh?->last_successful()['checksum'] ?? '' ) );
		self::assertSame( 'live-token', (string) ( $fresh?->last_successful()['generation_token'] ?? '' ) );
	}

	public function test_ready_last_successful_preserves_promoted_token_and_generation(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$file  = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
			]
		);
		$pack = $packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Pending->value,
				'source_reference' => $file,
			]
		);
		$importer = $this->importer( $geo, $packs );
		$pack     = $importer->begin_dataset( $pack, $file, hash_file( 'sha256', $file ) ?: 'sum', '2026.geo7' );
		$token    = $pack->target_token();
		self::assertNotSame( '', $token );
		$result = $this->run_import( $importer, $packs, $pack, $file );
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] ?? '' );
		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( $token, (string) ( $fresh?->last_successful()['generation_token'] ?? '' ) );
		self::assertSame( $token, (string) ( $fresh?->last_successful()['attempt_token'] ?? '' ) );
		self::assertSame( $token, $fresh?->target_token() );
		self::assertGreaterThan( 0, (int) ( $fresh?->last_successful()['active_generation'] ?? 0 ) );
		self::assertNotSame( '', (string) ( $fresh?->last_successful()['checksum'] ?? '' ) );
		self::assertNotSame( '', (string) ( $fresh?->last_successful()['source_reference'] ?? '' ) );
		self::assertNotSame( '', (string) ( $fresh?->last_successful()['installed_at'] ?? '' ) );
	}

	public function test_abandoned_token_files_are_removed_without_deleting_active_source(): void {
		$dir = sys_get_temp_dir() . '/cetech-geo7-clean-' . bin2hex( random_bytes( 4 ) );
		self::assertTrue( mkdir( $dir, 0777, true ) );
		$active     = $dir . '/GH.keep-token.abcabcabcabc.txt';
		$abandoned  = $dir . '/GH.old-token.defdefdefdef.txt';
		$abandoned_zip = $dir . '/GH.old-token.zip';
		file_put_contents( $active, "keep\n" );
		file_put_contents( $abandoned, "old\n" );
		file_put_contents( $abandoned_zip, 'zip' );
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
			$this->importer( $geo, $packs ),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$deleted = $service->cleanup_abandoned_generation_files( $pack, 'old-token', $dir );
		self::assertGreaterThan( 0, $deleted );
		self::assertFileExists( $active );
		self::assertFileDoesNotExist( $abandoned );
		self::assertFileDoesNotExist( $abandoned_zip );
		@unlink( $active );
		@rmdir( $dir );
	}

	public function test_download_releases_lease_before_remote_io(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Geography/GeographyPackService.php' );
		self::assertMatchesRegularExpression(
			'/function download_tick\(.*?release_lifecycle_lock\(\s*\$pack_id,\s*\$owner\s*\).*?safe_download\(/s',
			$source
		);
		self::assertStringContainsString( 'renew_lifecycle_lock', $source );
		self::assertStringContainsString( 'cleanup_abandoned_generation_files', $source );
		self::assertStringContainsString( 'checksum_mismatch', $source );
		self::assertStringContainsString( 'Failed to write provider mapping', (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Infrastructure/Persistence/WpdbProviderMappingRepository.php' ) );
	}

	public function test_owner_can_renew_unstolen_lease(): void {
		$service = $this->service();
		$owner   = $this->invoke( $service, 'acquire_lifecycle_lock', 11, 'tick' );
		self::assertNotSame( '', $owner );
		$key   = 'cetech_de_geo_pack_cas_11';
		$lease = get_option( $key );
		self::assertIsArray( $lease );
		$lease['expires_at'] = time() - 5;
		update_option( $key, $lease, false );
		self::assertTrue( $this->invoke( $service, 'renew_lifecycle_lock', 11, $owner ) );
		$renewed = get_option( $key );
		self::assertIsArray( $renewed );
		self::assertGreaterThan( time(), (int) ( $renewed['expires_at'] ?? 0 ) );
		self::assertSame( $owner, $renewed['owner'] ?? null );
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

	/**
	 * @return mixed
	 */
	private function invoke( GeographyPackService $service, string $method, mixed ...$args ) {
		$ref = new \ReflectionMethod( $service, $method );
		$ref->setAccessible( true );

		return $ref->invoke( $service, ...$args );
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
		$file = tempnam( sys_get_temp_dir(), 'geo7' );
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
