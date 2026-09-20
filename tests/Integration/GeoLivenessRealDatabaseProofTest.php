<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\GeographyPackService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Tests\Support\ActionSchedulerUniqueStore;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Isolated MariaDB proofs for issue #33 geography pack liveness recovery.
 * Excluded from default CI. Fail closed if the database is unreachable.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013, DE-PERF-002.
 *
 * @group geo-live-real-db
 * @group geo15-real-db
 */
final class GeoLivenessRealDatabaseProofTest extends TestCase {

	private RealMysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$wpdb = RealMysqliWpdb::try_connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::fail( 'BLOCKED BY TEST DEPENDENCY: isolated MySQL/MariaDB is not reachable.' );
		}
		require_once dirname( __DIR__ ) . '/Support/action-scheduler-test-functions.php';
		$this->wpdb                        = $wpdb;
		$GLOBALS['wpdb']                   = $wpdb;
		$GLOBALS['cetech_de_test_wc']      = $this->woo_stub();
		$GLOBALS['cetech_de_test_options'] = [];
		$this->reset_schema();
		$this->install_schema();
		ActionSchedulerUniqueStore::install();
	}

	protected function tearDown(): void {
		ActionSchedulerUniqueStore::uninstall();
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'] );
		parent::tearDown();
	}

	/**
	 * @group geo-live-real-db
	 * @group geo15-real-db
	 */
	public function test_orphan_recovery_keeps_cursor_checksum_source_and_token(): void {
		$store    = $GLOBALS['cetech_de_as_store'];
		self::assertInstanceOf( ActionSchedulerUniqueStore::class, $store );
		$geo      = new GhanaGeographyFixture();
		$packs    = new WpdbGeographyPackRepository();
		$importer = $this->importer( $geo, $packs );
		$service  = new GeographyPackService(
			$packs,
			$importer,
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$file = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '' ),
				$this->row( '2', 'Greater Accra', 'Greater Accra', 'A', 'ADM1', '01' ),
				$this->row( '3', 'Accra', 'Accra', 'P', 'PPLC', '01' ),
				$this->row( '4', 'Tema', 'Tema', 'P', 'PPL', '01' ),
				$this->row( '5', 'Madina', 'Madina', 'P', 'PPL', '01' ),
			]
		);
		$pack = $importer->begin_dataset(
			$importer->ensure_pack( 'GH', $file ),
			$file,
			hash_file( 'sha256', $file ) ?: 'sum',
			'2026.geo-live'
		);
		$first = $service->tick( $pack->id, $file, 1, $pack->target_token() );
		self::assertNotSame( 'lock_lost', (string) ( $first['reason'] ?? '' ) );
		$mid = $packs->find_by_id( $pack->id );
		self::assertInstanceOf( \CetechDeliveryEngine\Domain\Geography\GeographyPack::class, $mid );
		self::assertSame( GeographyPackStatus::Importing, $mid->status );

		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		self::assertNotFalse(
			$this->wpdb->query(
				'UPDATE `' . $table . '` SET `lease_owner` = \'\', `lease_role` = \'\', `lease_acquired_at` = 0, `lease_expires_at` = 0 WHERE `id` = ' . (int) $mid->id
			)
		);
		$store->reset();
		$service->ensure_import_liveness();
		$group = GeographyPackService::GROUP . '-' . $mid->id;
		self::assertSame( 1, $store->count_by_status( GeographyPackService::HOOK, $group, ActionSchedulerUniqueStore::STATUS_PENDING ) );
		$after = $packs->find_by_id( $mid->id );
		self::assertSame( $mid->import_cursor, $after?->import_cursor );
		self::assertSame( $mid->checksum, $after?->checksum );
		self::assertSame( $mid->target_token(), $after?->target_token() );
		self::assertSame( $mid->source_reference, $after?->source_reference );
		self::assertSame( $file, $after?->source_reference );
		unlink( $file );
	}

	/**
	 * @group geo-live-real-db
	 * @group geo15-real-db
	 */
	public function test_expired_lease_permits_recovery_and_existing_cas_lease_tests_still_hold(): void {
		$packs = new WpdbGeographyPackRepository();
		$geo   = new GhanaGeographyFixture();
		$file  = $this->gazetteer_file(
			[
				$this->row( '1', 'Ghana', 'Ghana', 'A', 'PCLI', '' ),
				$this->row( '2', 'Greater Accra', 'Greater Accra', 'A', 'ADM1', '01' ),
			]
		);
		$importer = $this->importer( $geo, $packs );
		$pack     = $importer->begin_dataset(
			$importer->ensure_pack( 'GH', $file ),
			$file,
			hash_file( 'sha256', $file ) ?: 'sum',
			'2026.geo-live'
		);
		$now   = time();
		$ttl   = GeographyPackService::LOCK_TTL_SECONDS;
		$owner = $packs->acquire_lease( $pack->id, 'tick', $now, $ttl );
		self::assertNotSame( '', $owner );
		self::assertTrue( $packs->renew_lease( $pack->id, $owner, $now, $ttl ) );
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		self::assertNotFalse(
			$this->wpdb->query(
				'UPDATE `' . $table . '` SET `lease_expires_at` = ' . ( $now - 5 ) . ' WHERE `id` = ' . (int) $pack->id
			)
		);
		$recovered = $packs->acquire_lease( $pack->id, 'tick', $now, $ttl );
		self::assertNotSame( '', $recovered );
		self::assertNotSame( $owner, $recovered );
		unlink( $file );
	}

	private function importer( GhanaGeographyFixture $geo, WpdbGeographyPackRepository $packs ): GeoNamesPackImporter {
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
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geolivedb' );
		self::assertIsString( $file );
		file_put_contents( $file, implode( "\n", $lines ) . "\n" );

		return $file;
	}

	private function row( string $id, string $name, string $ascii, string $class, string $code, string $admin1, string $admin2 = '', string $country = 'GH' ): string {
		$parts     = array_fill( 0, 19, '' );
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
		$suffixes = array_merge( GeographySchema::SUFFIXES, CoverageSchema::SUFFIXES );
		foreach ( $suffixes as $suffix ) {
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

					return [ 'AA' => 'Greater Accra' ];
				}
			},
		];
	}
}
