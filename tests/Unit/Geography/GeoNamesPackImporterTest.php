<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Geography;

use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryGeographyPackRepository;
use PHPUnit\Framework\TestCase;

final class GeoNamesPackImporterTest extends TestCase {

	public function test_batched_idempotent_resume_and_skips_pois(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$bootstrap = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$importer = new GeoNamesPackImporter(
			$geo->locations,
			$geo->locations,
			$geo->locations,
			$packs,
			$bootstrap,
			new GeoNamesGazetteerParser()
		);

		$file = tempnam( sys_get_temp_dir(), 'ghgeo' );
		self::assertIsString( $file );
		$lines = [
			$this->row( '2306104', 'Ghana', 'Ghana', 'A', 'PCLI', '', '', '' ),
			$this->row( '2306106', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '', '' ),
			$this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01', '', 'Akkra' ),
			$this->row( '9999999', 'Some Hotel', 'Some Hotel', 'S', 'HTL', '01', '', '' ),
			$this->row( '2306110', 'Tema', 'Tema', 'P', 'PPL', '01', '', '' ),
		];
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		$pack = $importer->ensure_pack( 'GH', $file );
		$result = $importer->import_batch( $pack, $file, 2 );
		$guard  = 0;
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 12 ) {
			$pack   = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 10 );
			++$guard;
		}
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] );
		self::assertGreaterThan( 0, $guard );
		self::assertNotNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306108' ) );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '9999999' ) );

		$mapped = $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306108' );
		$retry  = $importer->import_batch( $pack, $file, 50 );
		self::assertSame( GeographyPackStatus::Ready->value, $retry['status'] );
		self::assertSame( $mapped, $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306108' ) );

		unlink( $file );
	}

	private function row(
		string $id,
		string $name,
		string $ascii,
		string $class,
		string $code,
		string $admin1,
		string $admin2,
		string $alts
	): string {
		$parts = array_fill( 0, 19, '' );
		$parts[0]  = $id;
		$parts[1]  = $name;
		$parts[2]  = $ascii;
		$parts[3]  = $alts;
		$parts[4]  = '5.55';
		$parts[5]  = '-0.2';
		$parts[6]  = $class;
		$parts[7]  = $code;
		$parts[8]  = 'GH';
		$parts[10] = $admin1;
		$parts[11] = $admin2;
		$parts[18] = '2024-01-01';

		return implode( "\t", $parts );
	}
}
