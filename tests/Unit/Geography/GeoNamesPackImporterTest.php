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
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 40 ) {
			$pack   = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 10 );
			++$guard;
		}
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] );
		self::assertGreaterThan( 0, $guard );
		self::assertNotNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306108' ) );
		self::assertNull( $geo->locations->find_location_id( GeographyProvider::GeoNames, '9999999' ) );

		$ghana_children = $geo->locations->list_children( $geo->ghana->id, null, 250, 0 );
		foreach ( $ghana_children as $child ) {
			self::assertFalse( $child->isCountry() );
			self::assertNotSame( 'ghana', $child->normalized_name );
		}
		self::assertSame( $geo->ghana->id, $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306104' ) );

		$mapped = $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306108' );
		$retry  = $importer->import_batch( $pack, $file, 50 );
		self::assertSame( GeographyPackStatus::Ready->value, $retry['status'] );
		self::assertSame( $mapped, $geo->locations->find_location_id( GeographyProvider::GeoNames, '2306108' ) );

		unlink( $file );
	}

	public function test_adm_hierarchy_and_locality_attaches_to_deepest_parent(): void {
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
		$file = tempnam( sys_get_temp_dir(), 'admgeo' );
		self::assertIsString( $file );
		$lines = [
			$this->row( '1', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '', '' ),
			$this->row( '2', 'Accra Metropolitan', 'Accra Metropolitan', 'A', 'ADM2', '01', '001', '' ),
			$this->row( '3', 'Ablekuma', 'Ablekuma', 'A', 'ADM3', '01', '001', '', '01' ),
			$this->row( '4', 'Kaneshie', 'Kaneshie', 'P', 'PPL', '01', '001', '', '01', '04' ),
		];
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );
		$pack = $importer->ensure_pack( 'GH', $file );
		$pack = $importer->begin_dataset( $pack, $file, hash_file( 'sha256', $file ) ?: 'abc', '2026.testhier' );
		$result = [ 'status' => GeographyPackStatus::Importing->value ];
		$guard  = 0;
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 40 ) {
			$pack   = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $file, 20 );
			++$guard;
		}
		self::assertSame( GeographyPackStatus::Ready->value, $result['status'] );

		$adm2_id = $geo->locations->find_location_id( GeographyProvider::GeoNames, '2' );
		$adm3_id = $geo->locations->find_location_id( GeographyProvider::GeoNames, '3' );
		$loc_id  = $geo->locations->find_location_id( GeographyProvider::GeoNames, '4' );
		self::assertNotNull( $adm2_id );
		self::assertNotNull( $adm3_id );
		self::assertNotNull( $loc_id );
		$adm2 = $geo->locations->find_by_id( $adm2_id );
		$adm3 = $geo->locations->find_by_id( $adm3_id );
		$loc  = $geo->locations->find_by_id( $loc_id );
		self::assertSame( 2, $adm2?->administrative_level );
		self::assertSame( 3, $adm3?->administrative_level );
		self::assertSame( $adm2_id, $adm3?->parent_location_id );
		self::assertSame( $adm3_id, $loc?->parent_location_id );

		unlink( $file );
	}

	public function test_scan_bound_does_not_require_unlimited_irrelevant_rows(): void {
		$parser = new GeoNamesGazetteerParser();
		$file   = tempnam( sys_get_temp_dir(), 'scanb' );
		self::assertIsString( $file );
		$lines = [];
		for ( $i = 0; $i < 80; $i++ ) {
			$parts      = array_fill( 0, 19, '' );
			$parts[0]   = (string) ( 1000 + $i );
			$parts[1]   = 'Hotel ' . $i;
			$parts[6]   = 'S';
			$parts[7]   = 'HTL';
			$parts[8]   = 'GH';
			$lines[]    = implode( "\t", $parts );
		}
		$lines[] = $this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01', '', '', '' );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		$scanned = 0;
		$relevant = 0;
		foreach ( $parser->iterate_file( $file, 0, 10, 30, 4.0 ) as $row ) {
			if ( ! empty( $row['_batch_end'] ) ) {
				$scanned = (int) ( $row['_scanned'] ?? $scanned );
				self::assertLessThanOrEqual( 30, $scanned );
				break;
			}
			if ( empty( $row['_skip'] ) ) {
				++$relevant;
			}
		}
		self::assertSame( 0, $relevant );
		self::assertLessThanOrEqual( 30, $scanned );

		unlink( $file );
	}

	public function test_update_resets_cursor_and_retry_resumes_same_dataset(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$bootstrap = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $bootstrap, new GeoNamesGazetteerParser() );
		$service  = new \CetechDeliveryEngine\Application\Geography\GeographyPackService( $packs, $importer, $bootstrap );

		$file1 = tempnam( sys_get_temp_dir(), 'p1' );
		$file2 = tempnam( sys_get_temp_dir(), 'p2' );
		self::assertIsString( $file1 );
		self::assertIsString( $file2 );
		file_put_contents( $file1, $this->row( '2306106', 'Greater Accra Region', 'Greater Accra Region', 'A', 'ADM1', '01', '', '', '' ) . "\n" );
		file_put_contents( $file2, $this->row( '2306108', 'Accra', 'Accra', 'P', 'PPLC', '01', '', '', '' ) . "\n" );

		$pack = $service->install( 'GH', $file1 );
		$pack = $packs->find_by_id( $pack->id );
		self::assertNotNull( $pack );
		$guard = 0;
		while ( GeographyPackStatus::Ready !== $pack->status && $guard < 40 ) {
			$result = $service->tick( $pack->id, $file1, 20 );
			self::assertNotSame( GeographyPackStatus::Failed->value, (string) ( $result['status'] ?? '' ) );
			$pack = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			++$guard;
		}
		self::assertSame( GeographyPackStatus::Ready, $pack->status );
		$old_cursor = $pack->import_cursor;
		self::assertNotSame( '', $pack->checksum );
		self::assertNotSame( '', $pack->dataset_version );
		self::assertNotSame( '—', $pack->dataset_version );

		$updated = $service->update( 'GH', $file2 );
		self::assertSame( '0', $updated->import_cursor, 'new dataset must not resume the previous EOF cursor' );
		self::assertNotSame( $old_cursor === '0' ? 'skip' : $old_cursor, $updated->checksum === $pack->checksum ? $updated->checksum : $pack->checksum );
		self::assertNotSame( $pack->checksum, $updated->checksum );

		$mid = $service->tick( $updated->id, $file2, 1 );
		$resumed = $service->retry( $updated->id, $file2 );
		self::assertSame( $updated->checksum, $resumed->checksum );
		self::assertSame( (string) ( $mid['cursor'] ?? $resumed->import_cursor ), $resumed->import_cursor );

		unlink( $file1 );
		unlink( $file2 );
	}

	public function test_provider_rename_preserves_canonical_id(): void {
		$geo   = new GhanaGeographyFixture();
		$packs = new InMemoryGeographyPackRepository();
		$bootstrap = new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations );
		$importer = new GeoNamesPackImporter( $geo->locations, $geo->locations, $geo->locations, $packs, $bootstrap, new GeoNamesGazetteerParser() );
		$file = tempnam( sys_get_temp_dir(), 'ren' );
		self::assertIsString( $file );
		file_put_contents( $file, $this->row( '88', 'Old Town', 'Old Town', 'P', 'PPL', '01', '', '', '' ) . "\n" );
		$pack = $importer->begin_dataset( $importer->ensure_pack( 'GH', $file ), $file, 'aaa', '1' );
		$guard = 0;
		$result = [ 'status' => '' ];
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 30 ) {
			$pack   = $packs->find_by_id( $pack->id );
			$result = $importer->import_batch( $pack, $file, 20 );
			++$guard;
		}
		$id = $geo->locations->find_location_id( GeographyProvider::GeoNames, '88' );
		self::assertNotNull( $id );
		$before = $geo->locations->find_by_id( $id );
		file_put_contents( $file, $this->row( '88', 'New Town', 'New Town', 'P', 'PPL', '01', '', 'Old Town,Accra Town', '' ) . "\n" );
		$pack = $packs->find_by_id( $pack->id );
		self::assertNotNull( $pack );
		self::assertSame( GeographyPackStatus::Ready, $pack->status );
		$pack = $importer->begin_dataset( $pack, $file, 'bbb', '2' );
		self::assertSame( 'Old Town', $geo->locations->find_by_id( $id )?->canonical_name );
		$guard = 0;
		$result = [ 'status' => '' ];
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && $guard < 30 ) {
			$pack   = $packs->find_by_id( $pack->id );
			self::assertSame( 'Old Town', $geo->locations->find_by_id( $id )?->canonical_name );
			$result = $importer->import_batch( $pack, $file, 20 );
			++$guard;
		}
		self::assertSame( $id, $geo->locations->find_location_id( GeographyProvider::GeoNames, '88' ) );
		$after = $geo->locations->find_by_id( $id );
		self::assertSame( $before?->location_key, $after?->location_key );
		self::assertSame( 'New Town', $after?->canonical_name );
		self::assertContains( 'Old Town', $geo->locations->list_for_location( $id ) );
		self::assertContains( 'Accra Town', $geo->locations->list_for_location( $id ) );

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
		string $alts,
		string $admin3 = '',
		string $admin4 = ''
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
		$parts[12] = $admin3;
		$parts[13] = $admin4;
		$parts[18] = '2024-01-01';

		return implode( "\t", $parts );
	}
}
