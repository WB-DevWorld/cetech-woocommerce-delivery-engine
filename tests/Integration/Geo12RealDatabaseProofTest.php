<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyPackIncompletePreparationException;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Isolated MariaDB proofs for issue #23 geo.12 scale and CAS.
 * Excluded from default CI. Fail closed if the database is unreachable.
 *
 * @group geo12-real-db
 */
final class Geo12RealDatabaseProofTest extends TestCase {

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
		$this->reset_schema();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'] );
		parent::tearDown();
	}

	/**
	 * @group geo12-real-db
	 */
	public function test_more_than_ten_thousand_metadata_drafts_before_first_hierarchy_root(): void {
		$started = microtime( true );
		$this->install_schema6();
		$repo     = new WpdbCanonicalLocationRepository();
		$country  = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		for ( $i = 1; $i <= 10001; ++$i ) {
			$this->save_name_draft( $repo, $this->key( 'name', $i ), 'Place ' . $i, $region_a->id, 'token-scale' );
		}
		$root = $this->save_reparent( $repo, $this->key( 'town', 1 ), 'Late Town', $region_a, $region_b, 'token-scale' );
		$leaf = $repo->save( $this->blank_location( $this->key( 'leaf', 1 ), 'Late Leaf', $root->id, GeographyLocationType::Locality ) );

		$partial = $repo->prepare_generation( 'token-scale', 200, 0 );
		self::assertFalse( $partial['done'] );
		try {
			$repo->finalize_generation( 'token-scale' );
			self::fail( 'Finalize must fail closed when preparation is incomplete.' );
		} catch ( GeographyPackIncompletePreparationException $e ) {
			self::assertStringContainsString( 'incomplete', strtolower( $e->getMessage() ) );
		}

		$state = $this->prepare_until_done( $repo, 'token-scale', 200, 2000 );
		self::assertTrue( $state['done'] );
		$prepared_leaf = $repo->find_by_id( $leaf->id );
		self::assertSame( 'token-scale', $prepared_leaf?->prepared_generation_token );
		self::assertTrue( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $region_b->id ) );
		$repo->finalize_generation( 'token-scale' );
		self::assertSame( $region_b->id, $repo->find_by_id( $root->id )?->parent_location_id );
		fwrite( STDERR, sprintf( "geo12-scale metadata drafts: %.2fs\n", microtime( true ) - $started ) );
	}

	/**
	 * @group geo12-real-db
	 */
	public function test_more_than_one_thousand_hierarchy_changing_roots(): void {
		$started = microtime( true );
		$this->install_schema6();
		$repo     = new WpdbCanonicalLocationRepository();
		$country  = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Greater Accra', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Ashanti', $country->id, GeographyLocationType::Administrative, 1 ) );
		$roots    = [];
		$leaves   = [];
		for ( $i = 1; $i <= 1001; ++$i ) {
			$roots[ $i ]  = $this->save_reparent( $repo, $this->key( 'town', $i ), 'Town ' . $i, $region_a, $region_b, 'token-many' );
			$leaves[ $i ] = $repo->save( $this->blank_location( $this->key( 'leaf', $i ), 'Leaf ' . $i, $roots[ $i ]->id, GeographyLocationType::Locality ) );
		}

		$state = $this->prepare_until_done( $repo, 'token-many', 50, 5000 );
		self::assertTrue( $state['done'] );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaves[1001]->id )?->prepared_ancestry_path, $region_b->id ) );
		$repo->finalize_generation( 'token-many' );
		self::assertSame( $region_b->id, $repo->find_by_id( $roots[1001]->id )?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaves[1001]->id )?->ancestry_path, $region_b->id ) );
		fwrite( STDERR, sprintf( "geo12-scale hierarchy roots: %.2fs\n", microtime( true ) - $started ) );
	}

	/**
	 * @group geo12-real-db
	 */
	public function test_real_sql_cas_failure_stays_failed(): void {
		$this->install_schema6();
		$this->wpdb->query(
			'CREATE TABLE IF NOT EXISTS `' . $this->wpdb->options . '` (
				option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				option_name varchar(191) NOT NULL,
				option_value longtext NOT NULL,
				autoload varchar(20) NOT NULL DEFAULT "yes",
				PRIMARY KEY (option_id),
				UNIQUE KEY option_name (option_name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
		);
		$expected = serialize(
			[
				'status' => Schema6CoverageUpgradeService::STATUS_COMPLETED,
				'owner'  => 'done',
			]
		);
		$this->wpdb->insert(
			$this->wpdb->options,
			[
				'option_name'  => Schema6CoverageUpgradeService::OPTION_KEY,
				'option_value' => $expected,
				'autoload'     => 'no',
			]
		);
		$updated = $this->wpdb->update(
			$this->wpdb->options,
			[ 'option_value' => serialize( [ 'owner' => 'thief' ] ) ],
			[
				'option_name'  => Schema6CoverageUpgradeService::OPTION_KEY,
				'option_value' => serialize( [ 'owner' => 'missing' ] ),
			]
		);
		self::assertSame( 0, (int) $updated );
		$stored = (string) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT option_value FROM `' . $this->wpdb->options . '` WHERE option_name = %s',
				Schema6CoverageUpgradeService::OPTION_KEY
			)
		);
		self::assertSame( $expected, $stored );
	}

	private function install_schema6(): void {
		$charset = $this->wpdb->get_charset_collate();
		foreach ( GeographySchema::create_table_statements( $charset ) as $sql ) {
			self::assertNotFalse( $this->wpdb->query( $sql ), $this->wpdb->last_error );
		}
		foreach ( CoverageSchema::create_table_statements( $charset ) as $sql ) {
			self::assertNotFalse( $this->wpdb->query( $sql ), $this->wpdb->last_error );
		}
	}

	private function reset_schema(): void {
		$suffixes = array_merge(
			GeographySchema::SUFFIXES,
			CoverageSchema::SUFFIXES
		);
		foreach ( $suffixes as $suffix ) {
			$this->wpdb->query( 'DROP TABLE IF EXISTS `' . TableNames::for( $suffix ) . '`' );
		}
		$this->wpdb->query( 'DROP TABLE IF EXISTS `' . $this->wpdb->options . '`' );
	}

	private function blank_location(
		string $key,
		string $name,
		?int $parent = null,
		GeographyLocationType $type = GeographyLocationType::Country,
		?int $level = null
	): CanonicalLocation {
		return new CanonicalLocation(
			0,
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
			RecordStatus::Active,
			''
		);
	}

	private function save_reparent(
		WpdbCanonicalLocationRepository $repo,
		string $key,
		string $name,
		CanonicalLocation $from,
		CanonicalLocation $to,
		string $token,
		?int $live_parent = null
	): CanonicalLocation {
		return $repo->save(
			new CanonicalLocation(
				0,
				$key,
				'GH',
				$live_parent ?? $from->id,
				GeographyLocationType::Locality,
				null,
				$name,
				strtolower( $name ),
				strtolower( $name ),
				null,
				null,
				RecordStatus::Active,
				'',
				1,
				(string) json_encode(
					[
						'generation_token'   => $token,
						'canonical_name'     => $name,
						'parent_location_id' => $to->id,
					]
				),
				'',
				$token
			)
		);
	}

	private function save_name_draft( WpdbCanonicalLocationRepository $repo, string $key, string $name, int $parent_id, string $token ): CanonicalLocation {
		return $repo->save(
			new CanonicalLocation(
				0,
				$key,
				'GH',
				$parent_id,
				GeographyLocationType::Locality,
				null,
				$name,
				strtolower( $name ),
				strtolower( $name ),
				null,
				null,
				RecordStatus::Active,
				'',
				1,
				(string) json_encode(
					[
						'canonical_name'  => $name . ' Renamed',
						'normalized_name' => strtolower( $name . ' Renamed' ),
					]
				),
				'',
				$token
			)
		);
	}

	/**
	 * @return array{processed:int,last_id:int,done:bool,hierarchy_root_cursor:int,hierarchy_descendant_cursor:int,hierarchy_root_id:int}
	 */
	private function prepare_until_done( WpdbCanonicalLocationRepository $repo, string $token, int $limit = 20, int $max_batches = 500 ): array {
		$after     = 0;
		$hierarchy = [];
		$batches   = 0;
		$prepared  = [];
		do {
			$prepared  = $repo->prepare_generation( $token, $limit, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = [
				'hierarchy_root_cursor'       => (int) $prepared['hierarchy_root_cursor'],
				'hierarchy_descendant_cursor' => (int) $prepared['hierarchy_descendant_cursor'],
				'hierarchy_root_id'           => (int) $prepared['hierarchy_root_id'],
			];
			++$batches;
		} while ( ! $prepared['done'] && $batches < $max_batches );
		self::assertTrue( $prepared['done'], 'Preparation must complete without treating a scan budget as DONE.' );

		return $prepared;
	}

	private function key( string $kind, int $n ): string {
		return sprintf( 'c0000000-0000-4000-8%03s-%012d', substr( $kind, 0, 3 ), $n );
	}

	private function woo_stub(): object {
		return (object) [
			'countries' => new class() {
				public function get_countries(): array {
					return [ 'GH' => 'Ghana' ];
				}
				public function get_states( string $country ): array {
					return [];
				}
			},
		];
	}
}
