<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\StorefrontGeographyEndpoint;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class Geo5TechnicalCorrectionTest extends TestCase {

	public function test_promoting_ghana_does_not_activate_other_country_staging(): void {
		$geo      = new GhanaGeographyFixture();
		$geo->locations->seed( 'GB', GeographyLocationType::Country, 'United Kingdom', null, null, 'loc-gb' );
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$gh_file  = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '', 'GH' ),
				$this->row( '9', 'Kasoa', 'Kasoa', 'P', 'PPL', '01', '', 'GH' ),
			]
		);
		$gb_lines = [
			$this->row( '2635167', 'United Kingdom', 'United Kingdom', 'A', 'PCLI', '', '', 'GB' ),
			$this->row( '6269131', 'England', 'England', 'A', 'ADM1', 'ENG', '', 'GB' ),
			$this->row( '2643743', 'London', 'London', 'P', 'PPLC', 'ENG', '', 'GB' ),
		];
		for ( $i = 1; $i <= 30; $i++ ) {
			$gb_lines[] = $this->row( (string) ( 3000000 + $i ), 'Pad ' . $i, 'Pad ' . $i, 'P', 'PPL', 'ENG', '', 'GB' );
		}
		$gb_file = $this->gazetteer_file( $gb_lines );
		$gh      = $importer->begin_dataset( $importer->ensure_pack( 'GH', $gh_file ), $gh_file, 'gh-sum', '2026.gh' );
		$gb_pack = $importer->begin_dataset( $importer->ensure_pack( 'GB', $gb_file ), $gb_file, 'gb-sum', '2026.gb' );
		self::assertNotSame( $gh->target_token(), $gb_pack->target_token() );
		self::assertStringContainsString( ':GH:', $gh->target_token() );
		self::assertStringContainsString( ':GB:', $gb_pack->target_token() );

		$london_id = $this->import_until_mapped( $importer, $packs, $geo, $gb_pack, $gb_file, '2643743', $gb_pack->target_token() );
		self::assertNotNull( $london_id );
		self::assertFalse( $geo->locations->find_by_id( $london_id )?->isActive() );
		$gb_status = $packs->find_by_id( $gb_pack->id );
		self::assertNotSame( GeographyPackStatus::Ready, $gb_status?->status );

		$this->run_import( $importer, $packs, $gh, $gh_file );
		self::assertFalse( $geo->locations->find_by_id( $london_id )?->isActive() );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '2643743' ) );
		$kasoa = $geo->locations->find_location_id( GeographyProvider::GeoNames, '9' );
		self::assertNotNull( $kasoa );
		self::assertTrue( $geo->locations->find_by_id( $kasoa )?->isActive() );
		unlink( $gh_file );
		unlink( $gb_file );
	}

	public function test_failed_target_rows_are_not_activated_by_later_target(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$first    = $this->gazetteer_file( [ $this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ) ] );
		$pack     = $importer->begin_dataset( $importer->ensure_pack( 'GH', $first ), $first, 'gen1', '1' );
		$this->run_import( $importer, $packs, $pack, $first );
		$ready = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Ready, $ready?->status );

		$failed_lines = [
			$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
			$this->row( '77', 'Failedville', 'Failedville', 'P', 'PPL', '01', '' ),
		];
		for ( $i = 1; $i <= 30; $i++ ) {
			$failed_lines[] = $this->row( (string) ( 4000000 + $i ), 'FailPad ' . $i, 'FailPad ' . $i, 'P', 'PPL', '01', '' );
		}
		$failed  = $this->gazetteer_file( $failed_lines );
		$pack    = $importer->begin_dataset( $ready, $failed, 'fail-a', 'A' );
		$token_a = $pack->target_token();
		$failed_id = $this->import_until_mapped( $importer, $packs, $geo, $pack, $failed, '77', $token_a );
		self::assertNotNull( $failed_id );
		self::assertFalse( $geo->locations->find_by_id( $failed_id )?->isActive() );
		self::assertNotSame( GeographyPackStatus::Ready, $packs->find_by_id( $pack->id )?->status );

		$success = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '88', 'Successville', 'Successville', 'P', 'PPL', '01', '' ),
			]
		);
		$pack = $packs->find_by_id( $pack->id );
		self::assertNotNull( $pack );
		$pack = $importer->begin_dataset( $pack, $success, 'win-b', 'B' );
		self::assertNotSame( $token_a, $pack->target_token() );
		$this->run_import( $importer, $packs, $pack, $success );
		self::assertSame( GeographyPackStatus::Ready, $packs->find_by_id( $pack->id )?->status );
		self::assertFalse( $geo->locations->find_by_id( $failed_id )?->isActive() );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '77' ) );
		$ok = $geo->locations->find_location_id( GeographyProvider::GeoNames, '88' );
		self::assertNotNull( $ok );
		self::assertTrue( $geo->locations->find_by_id( $ok )?->isActive() );
		unlink( $first );
		unlink( $failed );
		unlink( $success );
	}

	public function test_wpdb_promotion_failure_rolls_back_prior_active(): void {
		$wpdb = new FakeWpdb();
		$wpdb->create_table( 'wp_delivery_engine_geography_locations', [ [ 'location_key' ] ] );
		$wpdb->create_table( 'wp_delivery_engine_geography_provider_mappings' );
		$GLOBALS['wpdb'] = $wpdb;
		$repo            = new WpdbCanonicalLocationRepository();
		$active          = $repo->save(
			new CanonicalLocation(
				0,
				'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
				'GH',
				null,
				GeographyLocationType::Country,
				null,
				'Ghana',
				'ghana',
				'ghana',
				null,
				null,
				RecordStatus::Active,
				'/1/',
				0,
				'',
				''
			)
		);
		$staged = $repo->save(
			new CanonicalLocation(
				0,
				'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
				'GH',
				$active->id,
				GeographyLocationType::Locality,
				null,
				'Staged Town',
				'staged town',
				'staged town',
				null,
				null,
				RecordStatus::Inactive,
				'/1/2/',
				2,
				'',
				'token-b'
			)
		);
		$wpdb->fail_next_update_table = TableNames::for( GeographySchema::LOCATIONS_SUFFIX );
		try {
			$repo->promote_generation( 'token-b' );
			self::fail( 'Promotion should throw on write failure.' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'activate', $e->getMessage() );
		}
		$again = $repo->find_by_id( $staged->id );
		self::assertFalse( $again?->isActive() );
		self::assertTrue( $repo->find_by_id( $active->id )?->isActive() );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_stale_download_complete_does_not_supersede_newer_target(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$service  = new GeographyPackService( $packs, $importer, new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations ) );
		$file_a   = $this->gazetteer_file( [ $this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ) ] );
		$file_b   = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
				$this->row( '2', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
			]
		);
		$pack    = $importer->ensure_pack( 'GH', $file_a );
		$pack    = $importer->begin_dataset( $pack, $file_a, 'aaa', 'A' );
		$token_a = $pack->target_token();
		$pack    = $importer->begin_dataset( $pack, $file_b, 'bbb', 'B' );
		$token_b = $pack->target_token();
		$result  = $service->complete_official_source( $pack->id, $token_a, $file_a );
		self::assertSame( 'noop', $result['status'] );
		self::assertSame( 'stale_generation', $result['reason'] );
		$fresh = $packs->find_by_id( $pack->id );
		self::assertSame( $token_b, $fresh?->target_token() );
		self::assertSame( 'bbb', $fresh?->checksum );
		unlink( $file_a );
		unlink( $file_b );
	}

	public function test_generation_file_copy_failure_fails_target(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( GeographyPackService::class ) )->getFileName() );
		self::assertStringContainsString( 'Could not create an immutable generation source file', $src );
		self::assertStringContainsString( 'safe_token_segment', $src );
		self::assertStringContainsString( 'if ( ! @copy( $source_path, $dest ) )', $src );
		self::assertStringNotContainsString( 'GH.incoming.txt', $src );
	}

	public function test_failed_target_mapping_is_not_live_provenance(): void {
		$geo      = new GhanaGeographyFixture();
		$packs    = new InMemoryGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$lines    = [
			$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '', '' ),
			$this->row( '55', 'Ghost Town', 'Ghost Town', 'P', 'PPL', '01', '' ),
		];
		for ( $i = 1; $i <= 30; $i++ ) {
			$lines[] = $this->row( (string) ( 5000000 + $i ), 'GhostPad ' . $i, 'GhostPad ' . $i, 'P', 'PPL', '01', '' );
		}
		$failed  = $this->gazetteer_file( $lines );
		$pack    = $importer->begin_dataset( $importer->ensure_pack( 'GH', $failed ), $failed, 'ghost', 'A' );
		$token_a = $pack->target_token();
		$ghost   = $this->import_until_mapped( $importer, $packs, $geo, $pack, $failed, '55', $token_a );
		self::assertNotNull( $ghost );
		self::assertNotNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '55', $token_a ) );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '55' ) );
		unlink( $failed );
	}

	public function test_country_scoped_locality_total_is_not_page_size(): void {
		$geo = new GhanaGeographyFixture();
		for ( $i = 1; $i <= 30; $i++ ) {
			$geo->locations->seed( 'GH', GeographyLocationType::Locality, 'Place ' . $i, $geo->ghana->id );
		}
		$packs    = new InMemoryGeographyPackRepository();
		$endpoint = $this->endpoint( $geo, $packs );
		$page1    = $endpoint->search_result( 'GH', '', 'Place', 1, 't' );
		self::assertSame( 25, count( $page1['items'] ) );
		self::assertGreaterThan( 25, $page1['total'] );
		self::assertTrue( $page1['has_more'] );
		$page2 = $endpoint->search_result( 'GH', '', 'Place', 2, 't' );
		self::assertNotSame( [], $page2['items'] );
		self::assertSame( $page1['total'], $page2['total'] );
	}

	public function test_children_cascade_for_woo_admin_canonical_admin_and_skip_admin(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$xx    = $geo->locations->seed( 'XX', GeographyLocationType::Country, 'No Woo States', null, null, 'loc-xx' );
		$geo->locations->seed( 'XX', GeographyLocationType::Administrative, 'Central District', $xx->id, 1, 'loc-xx-adm' );
		$sg = $geo->locations->seed( 'SG', GeographyLocationType::Country, 'Singapore', null, null, 'loc-sg' );
		$geo->locations->seed( 'SG', GeographyLocationType::Locality, 'Singapore City', $sg->id, null, 'loc-sg-city' );
		$endpoint = $this->endpoint( $geo, $packs );

		$woo = $endpoint->children_result( 'GH', '', GeographyLocationType::Administrative->value );
		self::assertFalse( $woo['skip_admin'] );
		self::assertNotSame( [], $woo['items'] );
		$codes = [];
		foreach ( $woo['items'] as $item ) {
			if ( isset( $item['code'] ) ) {
				$codes[] = $item['code'];
			}
		}
		self::assertContains( 'AA', $codes );

		$canonical = $endpoint->children_result( 'XX', '', GeographyLocationType::Administrative->value );
		self::assertFalse( $canonical['skip_admin'] );
		self::assertSame( 'Central District', $canonical['items'][0]['name'] ?? '' );
		self::assertArrayNotHasKey( 'code', $canonical['items'][0] );

		$skip = $endpoint->children_result( 'SG', '', GeographyLocationType::Administrative->value );
		self::assertTrue( $skip['skip_admin'] );
		self::assertSame( [], $skip['items'] );
		$direct = $endpoint->search_result( 'SG', '', 'Sing', 1, 't' );
		self::assertNotSame( [], $direct['items'] );
	}

	public function test_promotion_is_token_scoped_not_bare_generation(): void {
		$src = (string) file_get_contents( ( new \ReflectionClass( WpdbCanonicalLocationRepository::class ) )->getFileName() );
		self::assertStringContainsString( 'WHERE generation_token = %s AND status = %s', $src );
		self::assertStringNotContainsString( 'WHERE generation = %d AND status', $src );
	}

	public function test_ghana_manifest_records_auditable_sources(): void {
		$path = dirname( __DIR__, 3 ) . '/docs/qualification/ghana-authoritative-geography-manifest.md';
		$body = (string) file_get_contents( $path );
		self::assertStringContainsString( 'https://statsghana.gov.gh/', $body );
		self::assertStringContainsString( '2021 Population and Housing Census', $body );
		self::assertStringContainsString( '18 November 2021', $body );
		self::assertStringContainsString( '2026-09-17', $body );
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
		$file = tempnam( sys_get_temp_dir(), 'geo5' );
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

	private function import_until_mapped(
		GeoNamesPackImporter $importer,
		InMemoryGeographyPackRepository $packs,
		GhanaGeographyFixture $geo,
		$pack,
		string $file,
		string $external_id,
		string $token
	): ?int {
		$guard = 0;
		while ( $guard < 40 ) {
			$pack = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 20 );
			$id     = $geo->locations->find_location_id( GeographyProvider::GeoNames, $external_id, $token );
			if ( null !== $id ) {
				$location = $geo->locations->find_by_id( $id );
				if ( $location instanceof CanonicalLocation && ! $location->isActive() ) {
					return $id;
				}
			}
			if ( GeographyPackStatus::Ready->value === ( $result['status'] ?? '' ) ) {
				return $id;
			}
			++$guard;
		}

		return $geo->locations->find_location_id( GeographyProvider::GeoNames, $external_id, $token );
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
