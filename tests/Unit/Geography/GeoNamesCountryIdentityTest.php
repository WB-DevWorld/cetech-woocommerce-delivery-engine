<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

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
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #35 — GeoNames country identity: classifier, pack fence, name
 * consistency, multiple-PCL order, global feature contract, and in-place repair.
 */
final class GeoNamesCountryIdentityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cetech_de_test_wc'] );
		parent::tearDown();
	}

	public function test_parser_accepts_only_exact_country_identity_codes(): void {
		$parser = new GeoNamesGazetteerParser();
		foreach ( GeoNamesGazetteerParser::COUNTRY_FEATURE_CODES as $code ) {
			self::assertTrue( $parser->is_country_feature( $code ), $code );
			self::assertTrue( $parser->is_relevant_feature( 'A', $code ), $code );
			self::assertGreaterThan( 0, $parser->country_identity_rank( $code ), $code );
		}
		foreach ( [ 'PCLH', 'PCL', 'PCLX', 'ADM1', 'PPL' ] as $code ) {
			self::assertFalse( $parser->is_country_feature( $code ), $code );
			self::assertSame( 0, $parser->country_identity_rank( $code ), $code );
		}
		self::assertFalse( $parser->is_relevant_feature( 'A', 'PCLH' ) );
		self::assertFalse( $parser->is_relevant_feature( 'A', 'PCL' ) );
		self::assertTrue( $parser->is_relevant_feature( 'A', 'ADM1' ) );
		self::assertNull( $parser->parse_line( $this->row( '2302058', 'Dagomba', 'Dagomba', 'A', 'PCLH', '06', '2302058' ) ) );
	}

	public function test_global_country_territory_and_rejected_pcl_contract(): void {
		$parser = new GeoNamesGazetteerParser();
		self::assertTrue( $parser->is_country_feature( 'PCLI' ) );
		self::assertTrue( $parser->is_country_feature( 'PCLD' ) );
		self::assertTrue( $parser->is_country_feature( 'PCLF' ) );
		self::assertTrue( $parser->is_country_feature( 'PCLS' ) );
		self::assertTrue( $parser->is_country_feature( 'PCLIX' ) );
		self::assertFalse( $parser->is_country_feature( 'PCLH' ) );
		self::assertFalse( $parser->is_country_feature( 'PCL' ) );
		self::assertGreaterThan( $parser->country_identity_rank( 'PCLD' ), $parser->country_identity_rank( 'PCLI' ) );
	}

	public function test_dagomba_pclh_does_not_rename_ghana_country(): void {
		$state = $this->import_lines(
			[
				$this->row( '2300660', 'Republic of Ghana', 'Republic of Ghana', 'A', 'PCLI', '00', '', 'Ghana,Gaana', 'GH', '8.1', '-1.2' ),
				$this->row( '2302058', 'Dagomba', 'Dagomba', 'A', 'PCLH', '06', '2302058', 'Dagomba', 'GH', '9.5', '-0.25' ),
				$this->row( '2306106', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '' ),
				$this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01', '' ),
			]
		);
		$ghana = $state['geo']->locations->find_country( 'GH' );
		self::assertNotNull( $ghana );
		self::assertSame( $state['geo']->ghana->id, $ghana->id );
		self::assertSame( $state['geo']->ghana->location_key, $ghana->location_key );
		self::assertSame( 'Ghana', $ghana->canonical_name );
		self::assertSame( 'ghana', $ghana->normalized_name );
		self::assertSame( $ghana->id, $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2300660' ) );
		self::assertNull( $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2302058' ) );
		self::assertNotNull( $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2306108' ) );
		self::assertContains( 'Republic of Ghana', $state['geo']->locations->list_for_location( $ghana->id ) );
		self::assertNotContains( 'Dagomba', $state['geo']->locations->list_for_location( $ghana->id ) );
	}

	public function test_reversed_pcl_family_order_does_not_change_country_identity(): void {
		$forward = [
			$this->row( '2300660', 'Republic of Ghana', 'Republic of Ghana', 'A', 'PCLI', '00' ),
			$this->row( '2302058', 'Dagomba', 'Dagomba', 'A', 'PCLH', '06', '2302058' ),
			$this->row( '99', 'Mystery Polity', 'Mystery Polity', 'A', 'PCL', '' ),
			$this->row( '2306106', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01' ),
			$this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01' ),
		];
		$reverse = [
			$this->row( '99', 'Mystery Polity', 'Mystery Polity', 'A', 'PCL', '' ),
			$this->row( '2302058', 'Dagomba', 'Dagomba', 'A', 'PCLH', '06', '2302058' ),
			$this->row( '2300660', 'Republic of Ghana', 'Republic of Ghana', 'A', 'PCLI', '00' ),
			$this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01' ),
			$this->row( '2306106', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01' ),
		];
		$a = $this->import_lines( $forward );
		$b = $this->import_lines( $reverse );
		foreach ( [ $a, $b ] as $state ) {
			$ghana = $state['geo']->locations->find_country( 'GH' );
			self::assertSame( 'Ghana', $ghana?->canonical_name );
			self::assertSame( 'ghana', $ghana?->normalized_name );
			self::assertSame( $ghana?->id, $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2300660' ) );
			self::assertNull( $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2302058' ) );
			self::assertNull( $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '99' ) );
			self::assertNotNull( $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2306108' ) );
		}
	}

	public function test_foreign_country_row_does_not_attach_to_pack_country(): void {
		$state = $this->import_lines(
			[
				$this->row( '2635167', 'United Kingdom', 'United Kingdom', 'A', 'PCLI', '', '', '', 'GB' ),
				$this->row( '2300660', 'Republic of Ghana', 'Republic of Ghana', 'A', 'PCLI', '00' ),
			]
		);
		$ghana = $state['geo']->locations->find_country( 'GH' );
		self::assertSame( 'Ghana', $ghana?->canonical_name );
		self::assertSame( $ghana?->id, $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2300660' ) );
		self::assertNull( $state['geo']->locations->find_location_id( GeographyProvider::GeoNames, '2635167' ) );
	}

	public function test_generic_pcl_territory_keeps_woo_root_and_imports_descendants(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$boot->bootstrap_country( 'IM' );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $boot, new GeoNamesGazetteerParser() );
		$file     = $this->gazetteer_file(
			[
				$this->row( '3042237', 'Isle of Man', 'Isle of Man', 'A', 'PCL', '', '', '', 'IM' ),
				$this->row( '3042232', 'Douglas', 'Douglas', 'P', 'PPLC', '9782170', '', '', 'IM' ),
			]
		);
		$this->import_until_ready( $importer, $packs, $file, 'IM' );
		$im = $geo->locations->find_country( 'IM' );
		self::assertNotNull( $im );
		self::assertSame( 'Isle of Man', $im->canonical_name );
		self::assertSame( 'isle of man', $im->normalized_name );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '3042237' ) );
		self::assertNotNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '3042232' ) );
		unlink( $file );
	}

	public function test_dependent_territory_pcld_is_accepted_and_historical_pclh_is_not(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$boot->bootstrap_country( 'PR' );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $boot, new GeoNamesGazetteerParser() );
		$file     = $this->gazetteer_file(
			[
				$this->row( '4566966', 'Puerto Rico', 'Puerto Rico', 'A', 'PCLD', '', '', '', 'PR' ),
				$this->row( '1', 'Spanish Puerto Rico', 'Spanish Puerto Rico', 'A', 'PCLH', '', '', '', 'PR' ),
				$this->row( '2', 'San Juan', 'San Juan', 'P', 'PPLC', '001', '', '', 'PR' ),
			]
		);
		$this->import_until_ready( $importer, $packs, $file, 'PR' );
		$pr = $geo->locations->find_country( 'PR' );
		self::assertNotNull( $pr );
		self::assertSame( 'Puerto Rico', $pr->canonical_name );
		self::assertSame( 'puerto rico', $pr->normalized_name );
		self::assertSame( $pr->id, $geo->locations->find_location_id( GeographyProvider::GeoNames, '4566966' ) );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '1' ) );
		self::assertNotNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '2' ) );
		unlink( $file );
	}

	public function test_staged_canonical_name_draft_includes_normalized_name(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $boot, new GeoNamesGazetteerParser() );
		$file     = $this->gazetteer_file( [ $this->row( '88', 'Old Town', 'Old Town', 'P', 'PPL', '01' ) ] );
		$this->import_until_ready( $importer, $packs, $file, 'GH' );
		$id = $geo->locations->find_location_id( GeographyProvider::GeoNames, '88' );
		self::assertNotNull( $id );
		file_put_contents( $file, $this->row( '88', 'New Town', 'New Town', 'P', 'PPL', '01', '', 'Old Town' ) . "\n" );
		$pack = $packs->find_by_country_provider( 'GH', GeographyProvider::GeoNames );
		self::assertNotNull( $pack );
		$pack = $importer->begin_dataset( $pack, $file, 'bbb', '2' );
		$seen = false;
		$guard = 0;
		$result = [ 'status' => '' ];
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 40 ) {
			$pack   = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 20 );
			$loc    = $geo->locations->find_by_id( $id );
			$draft  = is_string( $loc?->draft_json ) && '' !== $loc->draft_json ? json_decode( $loc->draft_json, true ) : null;
			if ( is_array( $draft ) && isset( $draft['canonical_name'] ) ) {
				self::assertSame( 'New Town', $draft['canonical_name'] );
				self::assertSame( GeographyNameNormalizer::normalize( 'New Town' ), $draft['normalized_name'] );
				$seen = true;
			}
			++$guard;
		}
		self::assertTrue( $seen );
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] );
		$after = $geo->locations->find_by_id( $id );
		self::assertSame( 'New Town', $after?->canonical_name );
		self::assertSame( GeographyNameNormalizer::normalize( 'New Town' ), $after?->normalized_name );
		unlink( $file );
	}

	public function test_repair_restores_woocommerce_country_identity_in_place(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$ghana = $geo->ghana;
		$key   = $ghana->location_key;
		$id    = $ghana->id;
		$generation = $ghana->generation;
		$geo->locations->save(
			new CanonicalLocation(
				$id,
				$key,
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
				$generation
			)
		);
		$geo->locations->add_alias( $id, 'Ghana', 'ghana' );
		$geo->locations->add_alias( $id, 'Dagomba', 'dagomba' );
		$geo->locations->upsert( $id, GeographyProvider::WooCommerce, 'GH', null, 'woocommerce', '', 'A', 'PCLI' );
		$geo->locations->upsert( $id, GeographyProvider::GeoNames, '2300660', 1, '2024-09-05', '00', 'A', 'PCLI', [ 'ascii_name' => 'Republic of Ghana' ] );
		$geo->locations->upsert( $id, GeographyProvider::GeoNames, '2302058', 1, '2019-09-01', '06', 'A', 'PCLH', [ 'ascii_name' => 'Dagomba' ] );

		$file = $this->gazetteer_file(
			[ $this->row( '2300660', 'Republic of Ghana', 'Republic of Ghana', 'A', 'PCLI', '00', '', 'Ghana,Gaana', 'GH', '8.1', '-1.2' ) ]
		);
		$packs->save(
			[
				'country_code'     => 'GH',
				'provider'         => GeographyProvider::GeoNames->value,
				'dataset_name'     => 'gazetteer',
				'status'           => GeographyPackStatus::Ready->value,
				'source_reference' => $file,
			]
		);
		$reconciler = new CountryIdentityReconciler(
			$geo->locations,
			$geo->locations,
			$geo->locations,
			$packs,
			$boot,
			new GeoNamesGazetteerParser()
		);
		$result = $reconciler->repair_country_code( 'GH' );
		$after  = $geo->locations->find_by_id( $id );
		self::assertTrue( (bool) ( $result['changed'] ?? false ) );
		self::assertSame( $id, $after?->id );
		self::assertSame( $key, $after?->location_key );
		self::assertSame( 'GH', $after?->country_code );
		self::assertSame( 'Ghana', $after?->canonical_name );
		self::assertSame( 'ghana', $after?->normalized_name );
		self::assertSame( GeographyNameNormalizer::fold_ascii( 'Ghana' ), $after?->ascii_name );
		self::assertSame( 8.1, $after?->latitude );
		self::assertSame( -1.2, $after?->longitude );
		self::assertSame( $generation, $after?->generation );
		self::assertSame( 1, $reconciler->source_scan_count );
		unlink( $file );
		self::assertNull( $after?->parent_location_id );
		self::assertSame( $id, $geo->locations->find_location_id( GeographyProvider::GeoNames, '2300660' ) );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '2302058' ) );
		self::assertNotContains( 'Dagomba', $geo->locations->list_for_location( $id ) );
		self::assertSame( $geo->accra->id, $geo->locations->find_by_id( $geo->accra->id )?->id );

		$again = $reconciler->repair_country_code( 'GH' );
		self::assertFalse( (bool) ( $again['changed'] ?? true ) );
		self::assertSame( $key, $geo->locations->find_by_id( $id )?->location_key );
	}

	/**
	 * @param list<string> $lines
	 * @return array{geo:GhanaGeographyFixture,packs:InMemoryGeographyPackRepository}
	 */
	private function import_lines( array $lines ): array {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$boot  = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $boot, new GeoNamesGazetteerParser() );
		$file     = $this->gazetteer_file( $lines );
		$this->import_until_ready( $importer, $packs, $file, 'GH' );
		unlink( $file );

		return [
			'geo'   => $geo,
			'packs' => $packs,
		];
	}

	private function import_until_ready( GeoNamesPackImporter $importer, InMemoryGeographyPackRepository $packs, string $file, string $country ): void {
		$pack = $importer->begin_dataset( $importer->ensure_pack( $country, $file ), $file, hash_file( 'sha256', $file ) ?: 'sum', '2026.identity' );
		$result = [ 'status' => '' ];
		$guard  = 0;
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 50 ) {
			$pack   = $packs->find_by_id( $pack->id );
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
		$file = tempnam( sys_get_temp_dir(), 'geoid' );
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

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [
						'GH' => 'Ghana',
						'PR' => 'Puerto Rico',
						'US' => 'United States (US)',
						'GB' => 'United Kingdom (UK)',
						'IM' => 'Isle of Man',
					];
				}
				public function get_states( string $country ): array {
					unset( $country );

					return [];
				}
			},
		];
	}
}
