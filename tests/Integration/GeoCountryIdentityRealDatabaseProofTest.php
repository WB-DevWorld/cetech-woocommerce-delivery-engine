<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Geography\CountryIdentityReconciler;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbLocationAliasRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProviderMappingRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * MariaDB proofs for issue #35 country identity and set-based name drafts.
 *
 * @group geo-live-real-db
 * @group geo15-real-db
 */
final class GeoCountryIdentityRealDatabaseProofTest extends TestCase {

	private RealMysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$wpdb = RealMysqliWpdb::try_connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::fail( 'BLOCKED BY TEST DEPENDENCY: isolated MySQL/MariaDB is not reachable.' );
		}
		$this->wpdb                   = $wpdb;
		$GLOBALS['wpdb']              = $wpdb;
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$this->reset_schema();
		$this->install_schema();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'] );
		parent::tearDown();
	}

	/**
	 * @group geo-live-real-db
	 * @group geo15-real-db
	 */
	public function test_pclh_cannot_overwrite_woocommerce_country_and_repair_is_in_place(): void {
		$stack    = $this->stack();
		$importer = $stack['importer'];
		$packs    = $stack['packs'];
		$locations = $stack['locations'];
		$mappings  = $stack['mappings'];
		$file      = $this->gazetteer_file(
			[
				$this->row( '2300660', 'Republic of Ghana', 'Republic of Ghana', 'A', 'PCLI', '00', '', 'Ghana', 'GH', '8.1', '-1.2' ),
				$this->row( '2302058', 'Dagomba', 'Dagomba', 'A', 'PCLH', '06', '2302058', 'Dagomba', 'GH', '9.5', '-0.25' ),
				$this->row( '2306106', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01' ),
				$this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01' ),
			]
		);
		$pack = $importer->begin_dataset( $importer->ensure_pack( 'GH', $file ), $file, hash_file( 'sha256', $file ) ?: 'sum', '2026.identity' );
		$this->import_until_ready( $importer, $packs, $pack->id, $file );
		$ghana = $locations->find_country( 'GH' );
		self::assertNotNull( $ghana );
		self::assertSame( 'Ghana', $ghana->canonical_name );
		self::assertSame( 'ghana', $ghana->normalized_name );
		self::assertSame( $ghana->id, $mappings->find_location_id( GeographyProvider::GeoNames, '2300660' ) );
		self::assertNull( $mappings->find_location_id( GeographyProvider::GeoNames, '2302058' ) );
		self::assertNotNull( $mappings->find_location_id( GeographyProvider::GeoNames, '2306108' ) );
		self::assertSame( 0, $stack['reconciler']->source_scan_count );

		$locations->save(
			new CanonicalLocation(
				$ghana->id,
				$ghana->location_key,
				'GH',
				null,
				GeographyLocationType::Country,
				null,
				'Dagomba',
				'ghana',
				'dagomba',
				9.5,
				-0.25,
				RecordStatus::Active,
				$ghana->ancestry_path,
				$ghana->generation
			)
		);
		$mappings->upsert( $ghana->id, GeographyProvider::GeoNames, '2302058', $pack->id, '2019-09-01', '06', 'A', 'PCLH', [ 'ascii_name' => 'Dagomba' ] );
		$stack['aliases']->add_alias( $ghana->id, 'Dagomba', 'dagomba' );
		$repaired = $stack['reconciler']->repair_country_code( 'GH' );
		$after    = $locations->find_by_id( $ghana->id );
		self::assertTrue( (bool) ( $repaired['changed'] ?? false ) );
		self::assertSame( $ghana->id, $after?->id );
		self::assertSame( $ghana->location_key, $after?->location_key );
		self::assertSame( 'Ghana', $after?->canonical_name );
		self::assertSame( 'ghana', $after?->normalized_name );
		self::assertEqualsWithDelta( 8.1, (float) $after?->latitude, 0.000001 );
		self::assertEqualsWithDelta( -1.2, (float) $after?->longitude, 0.000001 );
		self::assertSame( 1, $stack['reconciler']->source_scan_count );
		self::assertSame( $ghana->id, $mappings->find_location_id( GeographyProvider::GeoNames, '2300660' ) );
		self::assertNull( $mappings->find_location_id( GeographyProvider::GeoNames, '2302058' ) );
		unlink( $file );
	}

	/**
	 * @group geo-live-real-db
	 * @group geo15-real-db
	 */
	public function test_set_based_promotion_keeps_normalized_name_aligned_with_canonical_name(): void {
		$stack     = $this->stack();
		$importer  = $stack['importer'];
		$packs     = $stack['packs'];
		$locations = $stack['locations'];
		$file      = $this->gazetteer_file( [ $this->row( '88', 'Old Town', 'Old Town', 'P', 'PPL', '01' ) ] );
		$pack      = $importer->begin_dataset( $importer->ensure_pack( 'GH', $file ), $file, 'aaa', '1' );
		$this->import_until_ready( $importer, $packs, $pack->id, $file );
		$id = $stack['mappings']->find_location_id( GeographyProvider::GeoNames, '88' );
		self::assertNotNull( $id );
		file_put_contents( $file, $this->row( '88', 'New Town', 'New Town', 'P', 'PPL', '01', '', 'Old Town' ) . "\n" );
		$pack = $packs->find_by_id( $pack->id );
		self::assertNotNull( $pack );
		$pack = $importer->begin_dataset( $pack, $file, 'bbb', '2' );
		$this->import_until_ready( $importer, $packs, $pack->id, $file );
		$after = $locations->find_by_id( $id );
		self::assertSame( 'New Town', $after?->canonical_name );
		self::assertSame( GeographyNameNormalizer::normalize( 'New Town' ), $after?->normalized_name );
		unlink( $file );
	}

	/**
	 * @return array{
	 *   locations:WpdbCanonicalLocationRepository,
	 *   aliases:WpdbLocationAliasRepository,
	 *   mappings:WpdbProviderMappingRepository,
	 *   packs:WpdbGeographyPackRepository,
	 *   importer:GeoNamesPackImporter,
	 *   reconciler:CountryIdentityReconciler
	 * }
	 */
	private function stack(): array {
		$locations = new WpdbCanonicalLocationRepository();
		$aliases   = new WpdbLocationAliasRepository( $locations );
		$mappings  = new WpdbProviderMappingRepository();
		$packs     = new WpdbGeographyPackRepository();
		$boot      = new WooCommerceGeographyBootstrap( $locations, $aliases, $mappings );
		$parser    = new GeoNamesGazetteerParser();
		$reconciler = new CountryIdentityReconciler( $locations, $aliases, $mappings, $packs, $boot, $parser );
		$importer   = new GeoNamesPackImporter( $locations, $aliases, $mappings, $packs, $boot, $parser, $reconciler );

		return [
			'locations'  => $locations,
			'aliases'    => $aliases,
			'mappings'   => $mappings,
			'packs'      => $packs,
			'importer'   => $importer,
			'reconciler' => $reconciler,
		];
	}

	private function import_until_ready( GeoNamesPackImporter $importer, WpdbGeographyPackRepository $packs, int $pack_id, string $file ): void {
		$result = [ 'status' => '' ];
		$guard  = 0;
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 60 ) {
			$pack = $packs->find_by_id( $pack_id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 20 );
			++$guard;
		}
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] );
	}

	/**
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geoiddb' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		return $file;
	}

	private function row(
		string $id,
		string $name,
		string $ascii,
		string $class,
		string $code,
		string $admin1,
		string $admin2 = '',
		string $alts = '',
		string $country = 'GH',
		string $lat = '5.55',
		string $lon = '-0.2'
	): string {
		$parts     = array_fill( 0, 19, '' );
		$parts[0]  = $id;
		$parts[1]  = $name;
		$parts[2]  = $ascii;
		$parts[3]  = $alts;
		$parts[4]  = $lat;
		$parts[5]  = $lon;
		$parts[6]  = $class;
		$parts[7]  = $code;
		$parts[8]  = $country;
		$parts[10] = $admin1;
		$parts[11] = $admin2;
		$parts[18] = '2024-01-01';

		return implode( "\t", $parts );
	}

	private function install_schema(): void {
		$charset = $this->wpdb->get_charset_collate();
		foreach ( GeographySchema::create_table_statements( $charset ) as $sql ) {
			self::assertNotFalse( $this->wpdb->query( $sql ), $this->wpdb->last_error );
		}
		foreach ( CoverageSchema::create_table_statements( $charset ) as $sql ) {
			self::assertNotFalse( $this->wpdb->query( $sql ), $this->wpdb->last_error );
		}
	}

	private function reset_schema(): void {
		foreach ( array_merge( GeographySchema::SUFFIXES, CoverageSchema::SUFFIXES ) as $suffix ) {
			$table = TableNames::for( $suffix );
			$this->wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' );
		}
	}

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					unset( $country );

					return [];
				}
			},
		];
	}
}
