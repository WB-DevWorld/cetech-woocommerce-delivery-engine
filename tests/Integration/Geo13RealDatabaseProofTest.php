<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyPackIncompletePreparationException;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCanonicalLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

/**
 * Isolated MariaDB proofs for issue #23 geo.13 worker fencing and bounded hierarchy discovery.
 * Excluded from default CI. Fail closed if the database is unreachable.
 *
 * Requirement IDs: DE-GEO-011, DE-GEO-012, DE-GEO-013, DE-PERF-002.
 *
 * @group geo13-real-db
 */
final class Geo13RealDatabaseProofTest extends TestCase {

	private RealMysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$wpdb = RealMysqliWpdb::try_connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::fail( 'BLOCKED BY TEST DEPENDENCY: isolated MySQL/MariaDB is not reachable.' );
		}
		$this->wpdb               = $wpdb;
		$GLOBALS['wpdb']          = $wpdb;
		$GLOBALS['cetech_de_test_wc'] = $this->woo_stub();
		$this->reset_schema();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['cetech_de_test_wc'] );
		parent::tearDown();
	}

	/**
	 * @group geo13-real-db
	 */
	public function test_more_than_ten_thousand_metadata_drafts_use_bounded_discovery(): void {
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

		$this->wpdb->clear_sql_log();
		$partial = $repo->prepare_generation( 'token-scale', 200, 0 );
		self::assertFalse( $partial['done'] );
		self::assertSame( [], $this->unbounded_draft_selects( $this->wpdb->sql_log, 'token-scale' ) );
		self::assertGreaterThan( 0, $this->bounded_draft_page_count( $this->wpdb->sql_log, 'token-scale' ) );
		self::assertSame( 0, $this->prepared_moving_root_query_count( $this->wpdb->sql_log ) );

		try {
			$repo->finalize_generation( 'token-scale' );
			self::fail( 'Finalize must fail closed when preparation is incomplete.' );
		} catch ( GeographyPackIncompletePreparationException $e ) {
			self::assertStringContainsString( 'incomplete', strtolower( $e->getMessage() ) );
		}

		$state = $this->prepare_until_done( $repo, 'token-scale', 200, 2000, (int) $partial['last_id'], [
			'hierarchy_root_cursor'       => (int) $partial['hierarchy_root_cursor'],
			'hierarchy_descendant_cursor' => (int) $partial['hierarchy_descendant_cursor'],
			'hierarchy_root_id'           => (int) $partial['hierarchy_root_id'],
		] );
		self::assertTrue( $state['done'] );
		self::assertSame( [], $this->unbounded_draft_selects( $this->wpdb->sql_log, 'token-scale' ) );
		$prepared_leaf = $repo->find_by_id( $leaf->id );
		self::assertSame( 'token-scale', $prepared_leaf?->prepared_generation_token );
		self::assertTrue( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $region_b->id ) );
		$repo->finalize_generation( 'token-scale' );
		self::assertSame( $region_b->id, $repo->find_by_id( $root->id )?->parent_location_id );
		fwrite( STDERR, sprintf( "geo13-scale metadata drafts: %.2fs queries=%d\n", microtime( true ) - $started, count( $this->wpdb->sql_log ) ) );
	}

	/**
	 * @group geo13-real-db
	 */
	public function test_more_than_one_thousand_hierarchy_changing_roots_use_durable_cursor(): void {
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

		$this->wpdb->clear_sql_log();
		$after        = 0;
		$hierarchy    = [];
		$cursors      = [];
		$batches      = 0;
		$prepared     = [];
		$interrupted  = false;
		do {
			$prepared  = $repo->prepare_generation( 'token-many', 50, $after, $hierarchy );
			$after     = (int) $prepared['last_id'];
			$hierarchy = [
				'hierarchy_root_cursor'       => (int) $prepared['hierarchy_root_cursor'],
				'hierarchy_descendant_cursor' => (int) $prepared['hierarchy_descendant_cursor'],
				'hierarchy_root_id'           => (int) $prepared['hierarchy_root_id'],
			];
			$cursors[] = (int) $prepared['hierarchy_root_cursor'];
			++$batches;
			if ( ! $interrupted && (int) $prepared['hierarchy_root_cursor'] > 0 && ! $prepared['done'] ) {
				$interrupted = true;
				try {
					$repo->finalize_generation( 'token-many' );
					self::fail( 'Finalize must fail closed after a partial root pass.' );
				} catch ( GeographyPackIncompletePreparationException $e ) {
					self::assertStringContainsString( 'incomplete', strtolower( $e->getMessage() ) );
				}
			}
		} while ( ! $prepared['done'] && $batches < 5000 );
		self::assertTrue( $interrupted, 'Must interrupt after the durable root cursor has advanced.' );
		self::assertTrue( $prepared['done'] );
		self::assertGreaterThan( 0, max( $cursors ) );
		$after_ids = $this->prepared_moving_root_after_ids( $this->wpdb->sql_log );
		self::assertNotSame( [], $after_ids );
		self::assertGreaterThan( 0, max( $after_ids ) );
		self::assertSame( [], $this->unbounded_draft_selects( $this->wpdb->sql_log, 'token-many' ) );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaves[1001]->id )?->prepared_ancestry_path, $region_b->id ) );
		$repo->finalize_generation( 'token-many' );
		self::assertSame( $region_b->id, $repo->find_by_id( $roots[1001]->id )?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaves[1001]->id )?->ancestry_path, $region_b->id ) );
		fwrite(
			STDERR,
			sprintf(
				"geo13-scale hierarchy roots: %.2fs batches=%d cursor=%d after_ids=%d queries=%d\n",
				microtime( true ) - $started,
				$batches,
				(int) $prepared['hierarchy_root_cursor'],
				count( $after_ids ),
				count( $this->wpdb->sql_log )
			)
		);
	}

	/**
	 * @group geo13-real-db
	 */
	public function test_stale_worker_is_fenced_on_real_cas_path(): void {
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
		$upgrade = $this->schema6_upgrade_service();
		$pass_id = 'pass-fence';
		$state_a = [
			'status'            => Schema6CoverageUpgradeService::STATUS_RUNNING,
			'owner'             => 'worker-A',
			'lease_owner'       => 'worker-A',
			'pass_id'           => $pass_id,
			'pass_kind'         => Schema6CoverageUpgradeService::PASS_INITIAL,
			'last_zone_id'      => 0,
			'lease_expires_at'  => time() + 180,
			'worker'            => 'worker-A',
			'worker_expires_at' => time() - 1,
		];
		$this->wpdb->insert(
			$this->wpdb->options,
			[
				'option_name'  => Schema6CoverageUpgradeService::OPTION_KEY,
				'option_value' => serialize( $state_a ),
				'autoload'     => 'no',
			]
		);
		$GLOBALS['cetech_de_test_options'][ Schema6CoverageUpgradeService::OPTION_KEY ] = $state_a;

		$claimed = $this->invoke( $upgrade, 'acquire_worker', [] );
		self::assertIsArray( $claimed );
		$worker_b = (string) ( $claimed['owner'] ?? '' );
		self::assertNotSame( '', $worker_b );
		self::assertNotSame( 'worker-A', $worker_b );
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );

		self::assertFalse( $this->invoke( $upgrade, 'renew_owner_lease', [ 'worker-A', $pass_id ] ) );
		self::assertFalse(
			$this->invoke(
				$upgrade,
				'owner_store',
				[
					'worker-A',
					$pass_id,
					array_merge(
						$upgrade->current_state(),
						[
							'last_zone_id' => 9,
							'status'       => Schema6CoverageUpgradeService::STATUS_COMPLETED,
							'worker'       => 'worker-A',
						]
					),
				]
			)
		);
		self::assertSame( $worker_b, (string) ( $upgrade->current_state()['worker'] ?? '' ) );
		self::assertTrue(
			$this->invoke(
				$upgrade,
				'owner_store',
				[
					$worker_b,
					$pass_id,
					array_merge( $upgrade->current_state(), [ 'last_zone_id' => 44 ] ),
				]
			)
		);
		self::assertSame( 44, (int) ( $upgrade->current_state()['last_zone_id'] ?? 0 ) );
		sleep( 1 );
		self::assertTrue( $this->invoke( $upgrade, 'renew_owner_lease', [ $worker_b, $pass_id ] ) );
		self::assertFalse(
			$this->invoke(
				$upgrade,
				'owner_store',
				[
					'worker-A',
					$pass_id,
					array_merge(
						$upgrade->current_state(),
						[
							'last_zone_id' => 1,
							'status'       => Schema6CoverageUpgradeService::STATUS_COMPLETED,
						]
					),
				]
			)
		);
		self::assertSame( 44, (int) ( $upgrade->current_state()['last_zone_id'] ?? 0 ) );
	}

	private function schema6_upgrade_service(): Schema6CoverageUpgradeService {
		$locations = new InMemoryCanonicalLocationRepository();
		$zones     = new InMemoryDestinationZoneRepository();
		$rules     = new InMemoryDestinationRuleRepository();
		$groups    = new InMemoryCoverageGroupRepository();
		$zones->save( [ 'id' => 1, 'internal_name' => 'Accra', 'status' => RecordStatus::Active->value ] );
		$rules->replaceForZone( 1, [ [ 'rule_type' => DestinationRuleType::Country->value, 'rule_value' => 'GH' ] ] );
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $locations, $locations );
		$migrator  = new LegacyDestinationCoverageMigrator( $zones, $rules, $groups, $locations, new CanonicalLocationResolver( $locations, $locations ) );

		return new Schema6CoverageUpgradeService( $zones, $rules, $bootstrap, $migrator );
	}

	/**
	 * @param list<mixed> $args
	 */
	private function invoke( object $object, string $method, array $args = [] ): mixed {
		$ref = new \ReflectionMethod( $object, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $object, $args );
	}

	/**
	 * @param list<string> $log
	 * @return list<string>
	 */
	private function unbounded_draft_selects( array $log, string $token ): array {
		$out = [];
		foreach ( $log as $sql ) {
			$normalized = (string) preg_replace( '/\s+/', ' ', $sql );
			if ( ! str_contains( $normalized, 'draft_generation_token' ) ) {
				continue;
			}
			if ( ! str_contains( $normalized, $token ) ) {
				continue;
			}
			if ( ! preg_match( '/SELECT\s+\*\s+FROM/i', $normalized ) ) {
				continue;
			}
			if ( preg_match( '/LIMIT\s+\d+/i', $normalized ) ) {
				continue;
			}
			$out[] = $sql;
		}

		return $out;
	}

	/**
	 * @param list<string> $log
	 */
	private function bounded_draft_page_count( array $log, string $token ): int {
		$count = 0;
		foreach ( $log as $sql ) {
			$normalized = (string) preg_replace( '/\s+/', ' ', $sql );
			if ( str_contains( $normalized, 'draft_generation_token' ) && str_contains( $normalized, $token ) && preg_match( '/LIMIT\s+200/i', $normalized ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<string> $log
	 */
	private function prepared_moving_root_query_count( array $log ): int {
		$count = 0;
		foreach ( $log as $sql ) {
			if ( str_contains( $sql, 'prepared_hierarchy_root_id = id' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param list<string> $log
	 * @return list<int>
	 */
	private function prepared_moving_root_after_ids( array $log ): array {
		$ids = [];
		foreach ( $log as $sql ) {
			if ( ! str_contains( $sql, 'prepared_hierarchy_root_id = id' ) ) {
				continue;
			}
			if ( preg_match( '/id > (\d+)/', $sql, $match ) ) {
				$ids[] = (int) $match[1];
			}
		}

		return $ids;
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
		string $token
	): CanonicalLocation {
		return $repo->save(
			new CanonicalLocation(
				0,
				$key,
				'GH',
				$from->id,
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
	 * @param array{hierarchy_root_cursor?:int,hierarchy_descendant_cursor?:int,hierarchy_root_id?:int} $hierarchy
	 * @return array{processed:int,last_id:int,done:bool,hierarchy_root_cursor:int,hierarchy_descendant_cursor:int,hierarchy_root_id:int}
	 */
	private function prepare_until_done(
		WpdbCanonicalLocationRepository $repo,
		string $token,
		int $limit = 20,
		int $max_batches = 500,
		int $after = 0,
		array $hierarchy = []
	): array {
		$batches  = 0;
		$prepared = [];
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
		return sprintf( 'e0000000-0000-4000-8%03s-%012d', substr( $kind, 0, 3 ), $n );
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
