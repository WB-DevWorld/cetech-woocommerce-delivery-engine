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
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Isolated MariaDB proofs for issue #23 geo.15 pack-lease zero-row renewal.
 * Excluded from default CI. Fail closed if the database is unreachable.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013, DE-PERF-002.
 *
 * @group geo15-real-db
 */
final class Geo15RealDatabaseProofTest extends TestCase {

	private RealMysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$wpdb = RealMysqliWpdb::try_connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::fail( 'BLOCKED BY TEST DEPENDENCY: isolated MySQL/MariaDB is not reachable.' );
		}
		$this->wpdb          = $wpdb;
		$GLOBALS['wpdb']     = $wpdb;
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$GLOBALS['cetech_de_test_options'] = [];
		$this->reset_schema();
		$this->install_schema();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'] );
		parent::tearDown();
	}

	/**
	 * @group geo15-real-db
	 */
	public function test_real_acquire_then_immediate_renew_succeeds_without_sleep(): void {
		$packs = new WpdbGeographyPackRepository();
		$pack  = $this->insert_pack( $packs );
		$now   = time();
		$ttl   = GeographyPackService::LOCK_TTL_SECONDS;
		$owner = $packs->acquire_lease( $pack->id, 'tick', $now, $ttl );
		self::assertNotSame( '', $owner );

		$this->wpdb->clear_sql_log();
		self::assertTrue( $packs->renew_lease( $pack->id, $owner, $now, $ttl ) );
		$lease = $packs->current_lease( $pack->id );
		self::assertSame( $owner, $lease['owner'] ?? null );
		self::assertSame( $now + $ttl, (int) ( $lease['expires_at'] ?? 0 ) );
		self::assertSame( 1, $this->update_count( $this->wpdb->sql_log ) );
	}

	/**
	 * @group geo15-real-db
	 */
	public function test_same_owner_same_expiry_renew_is_successful_noop(): void {
		$packs = new WpdbGeographyPackRepository();
		$pack  = $this->insert_pack( $packs );
		$now   = time();
		$ttl   = GeographyPackService::LOCK_TTL_SECONDS;
		$owner = $packs->acquire_lease( $pack->id, 'tick', $now, $ttl );
		self::assertNotSame( '', $owner );
		self::assertTrue( $packs->renew_lease( $pack->id, $owner, $now, $ttl ) );

		$this->wpdb->clear_sql_log();
		self::assertTrue( $packs->renew_lease( $pack->id, $owner, $now, $ttl ) );
		$affected = $this->wpdb->rows_affected;
		self::assertSame( $owner, $packs->current_lease( $pack->id )['owner'] ?? null );
		self::assertSame( 1, $this->update_count( $this->wpdb->sql_log ) );
		if ( 0 === $affected ) {
			self::assertGreaterThanOrEqual( 1, $this->select_count( $this->wpdb->sql_log ) );
		}
	}

	/**
	 * @group geo15-real-db
	 */
	public function test_stale_owner_renew_fails_and_does_not_write(): void {
		$packs = new WpdbGeographyPackRepository();
		$pack  = $this->insert_pack( $packs );
		$now   = time();
		$ttl   = GeographyPackService::LOCK_TTL_SECONDS;
		$owner_a = $packs->acquire_lease( $pack->id, 'tick', $now, $ttl );
		self::assertNotSame( '', $owner_a );
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		self::assertNotFalse(
			$this->wpdb->query(
				'UPDATE `' . $table . '` SET `lease_owner` = \'tick:owner-b\' WHERE `id` = ' . (int) $pack->id
			)
		);

		$this->wpdb->clear_sql_log();
		self::assertFalse( $packs->renew_lease( $pack->id, $owner_a, $now, $ttl ) );
		self::assertSame( 'tick:owner-b', $packs->current_lease( $pack->id )['owner'] ?? null );
		self::assertSame( 1, $this->update_count( $this->wpdb->sql_log ) );
	}

	/**
	 * @group geo15-real-db
	 */
	public function test_zero_row_persisted_mismatch_fails_without_fallback_write(): void {
		$packs = new WpdbGeographyPackRepository();
		$pack  = $this->insert_pack( $packs );
		$now   = time();
		$ttl   = GeographyPackService::LOCK_TTL_SECONDS;
		$owner = $packs->acquire_lease( $pack->id, 'tick', $now, $ttl );
		self::assertNotSame( '', $owner );
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		self::assertNotFalse(
			$this->wpdb->query(
				'UPDATE `' . $table . '` SET `lease_owner` = \'tick:other\', `lease_expires_at` = ' . ( $now + 5 ) . ' WHERE `id` = ' . (int) $pack->id
			)
		);

		$this->wpdb->clear_sql_log();
		self::assertFalse( $packs->renew_lease( $pack->id, $owner, $now, $ttl ) );
		$lease = $packs->current_lease( $pack->id );
		self::assertSame( 'tick:other', $lease['owner'] ?? null );
		self::assertSame( $now + 5, (int) ( $lease['expires_at'] ?? 0 ) );
		self::assertSame( 1, $this->update_count( $this->wpdb->sql_log ) );
	}

	/**
	 * @group geo15-real-db
	 */
	public function test_tick_acquire_and_immediate_renew_is_not_lock_lost_and_reaches_ready(): void {
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
			]
		);
		$pack = $importer->begin_dataset(
			$importer->ensure_pack( 'GH', $file ),
			$file,
			hash_file( 'sha256', $file ) ?: 'sum',
			'2026.geo15'
		);

		$first = $service->tick( $pack->id, $file, 1, $pack->target_token() );
		self::assertNotSame( 'lock_lost', (string) ( $first['reason'] ?? '' ) );

		$result = $this->tick_until_terminal( $service, $pack->id, $file, $pack->target_token() );
		self::assertSame( GeographyPackStatus::Ready->value, (string) ( $result['status'] ?? '' ), (string) ( $result['error'] ?? '' ) );
		$ready = $packs->find_by_id( $pack->id );
		self::assertSame( GeographyPackStatus::Ready, $ready?->status );
		$accra = $geo->locations->find_location_id( GeographyProvider::GeoNames, '3' );
		self::assertNotNull( $accra );

		$retried = $service->retry( $pack->id, $file );
		$again   = $this->tick_until_terminal( $service, $retried->id, $file, $retried->target_token() );
		self::assertNotSame( 'lock_lost', (string) ( $again['reason'] ?? '' ) );
		self::assertSame( $accra, $geo->locations->find_location_id( GeographyProvider::GeoNames, '3' ) );
		unlink( $file );
	}

	/**
	 * @group geo15-real-db
	 */
	public function test_empty_pack_still_fails(): void {
		$geo     = new GhanaGeographyFixture();
		$packs   = new WpdbGeographyPackRepository();
		$service = new GeographyPackService(
			$packs,
			$this->importer( $geo, $packs ),
			new WooCommerceGeographyBootstrap( $geo->locations, $geo->locations, $geo->locations )
		);
		$empty = tempnam( sys_get_temp_dir(), 'geo15empty' );
		self::assertIsString( $empty );
		file_put_contents( $empty, '' );
		$failed = $service->update( 'GH', $empty );
		self::assertSame( GeographyPackStatus::Failed, $failed->status );
		self::assertNotSame( GeographyPackStatus::Ready, $failed->status );
		unlink( $empty );
	}

	private function insert_pack( WpdbGeographyPackRepository $packs ): \CetechDeliveryEngine\Domain\Geography\GeographyPack {
		return $packs->save(
			[
				'country_code' => 'GH',
				'provider'     => GeographyProvider::GeoNames->value,
				'dataset_name' => 'gazetteer',
				'status'       => GeographyPackStatus::Pending->value,
			]
		);
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
	 * @return array<string, mixed>
	 */
	private function tick_until_terminal( GeographyPackService $service, int $pack_id, string $file, string $token ): array {
		$result = [ 'status' => GeographyPackStatus::Importing->value ];
		for ( $i = 0; $i < 50; ++$i ) {
			$result = $service->tick( $pack_id, $file, 20, $token );
			self::assertNotSame( 'lock_lost', (string) ( $result['reason'] ?? '' ) );
			$status = (string) ( $result['status'] ?? '' );
			if ( GeographyPackStatus::Ready->value === $status || GeographyPackStatus::Failed->value === $status ) {
				return $result;
			}
		}

		return $result;
	}

	/**
	 * @param list<string> $log
	 */
	private function update_count( array $log ): int {
		$count = 0;
		foreach ( $log as $sql ) {
			if ( str_starts_with( strtoupper( ltrim( $sql ) ), 'UPDATE' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<string> $log
	 */
	private function select_count( array $log ): int {
		$count = 0;
		foreach ( $log as $sql ) {
			if ( str_starts_with( strtoupper( ltrim( $sql ) ), 'SELECT' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<string> $lines
	 */
	private function gazetteer_file( array $lines ): string {
		$file = tempnam( sys_get_temp_dir(), 'geo15db' );
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
			$this->wpdb->query( 'DROP TABLE IF EXISTS `' . TableNames::for( $suffix ) . '`' );
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
