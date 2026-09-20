<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Application\Geography\GeoNamesGazetteerParser;
use CetechDeliveryEngine\Application\Geography\GeoNamesPackImporter;
use CetechDeliveryEngine\Application\Geography\LegacyDestinationCoverageMigrator;
use CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService;
use CetechDeliveryEngine\Application\Geography\WooCommerceGeographyBootstrap;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCanonicalLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCoverageGroupRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationZoneRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbGeographyPackRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbLocationAliasRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProviderMappingRepository;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Isolated MariaDB/MySQL proofs for issue #23 geo.11. Excluded from default CI.
 *
 * @group geo11-real-db
 */
final class Geo11RealDatabaseProofTest extends TestCase {

	private RealMysqliWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$wpdb = RealMysqliWpdb::try_connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::markTestSkipped( 'BLOCKED BY TEST DEPENDENCY: isolated MySQL/MariaDB is not reachable.' );
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

	public function test_schema6_fresh_creation_and_rc11_upgrade_keep_rate_card_zone_ids(): void {
		$this->install_schema5_core();
		$zones = new WpdbDestinationZoneRepository();
		$zone_id = $zones->save(
			[
				'internal_code' => 'accra-metro',
				'internal_name' => 'Accra Metro',
				'status'        => RecordStatus::Active->value,
				'priority'      => 10,
			]
		);
		self::assertGreaterThan( 0, $zone_id );
		$this->wpdb->insert(
			TableNames::for( 'rate_cards' ),
			[
				'internal_code'        => 'rc-accra',
				'delivery_offer_id'    => 1,
				'destination_zone_id'  => $zone_id,
				'charge_type'          => 'flat',
				'base_amount'          => '12.0000',
				'base_currency'        => 'GHS',
				'status'               => RecordStatus::Active->value,
			]
		);
		self::assertSame( '', $this->wpdb->last_error, $this->wpdb->last_error );
		$before = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT destination_zone_id FROM `' . TableNames::for( 'rate_cards' ) . '` WHERE internal_code = %s',
				'rc-accra'
			)
		);

		$this->install_schema6();
		self::assertTrue( $this->table_exists( GeographySchema::LOCATIONS_SUFFIX ) );
		self::assertTrue( $this->table_exists( CoverageSchema::GROUPS_SUFFIX ) );
		self::assertTrue( $this->table_exists( 'destination_rules' ) );
		$columns = $this->wpdb->get_results( 'SHOW COLUMNS FROM `' . TableNames::for( GeographySchema::LOCATIONS_SUFFIX ) . '`' );
		$names   = array_map( static fn ( array $row ): string => (string) ( $row['Field'] ?? '' ), $columns );
		self::assertContains( 'prepared_hierarchy_root_id', $names );
		self::assertContains( 'prepared_generation_token', $names );
		$indexes = $this->wpdb->get_results( 'SHOW INDEX FROM `' . TableNames::for( GeographySchema::LOCATIONS_SUFFIX ) . '`' );
		$index_names = array_map( static fn ( array $row ): string => (string) ( $row['Key_name'] ?? '' ), $indexes );
		self::assertContains( 'prepared_hierarchy_root_id', $index_names );

		$after = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT destination_zone_id FROM `' . TableNames::for( 'rate_cards' ) . '` WHERE internal_code = %s',
				'rc-accra'
			)
		);
		self::assertSame( (string) $zone_id, (string) $before );
		self::assertSame( (string) $zone_id, (string) $after );
	}

	public function test_more_than_five_hundred_zones_migrate_and_late_zone_matches(): void {
		$this->install_schema5_core();
		$this->install_schema6();
		$locations = new WpdbCanonicalLocationRepository();
		$aliases   = new WpdbLocationAliasRepository( $locations );
		$mappings  = new WpdbProviderMappingRepository();
		$zones     = new WpdbDestinationZoneRepository();
		$rules     = new WpdbDestinationRuleRepository();
		$groups    = new WpdbCoverageGroupRepository();
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $aliases, $mappings );
		$migrator  = new LegacyDestinationCoverageMigrator(
			$zones,
			$rules,
			$groups,
			$locations,
			new CanonicalLocationResolver( $locations, $aliases )
		);

		for ( $i = 1; $i <= 501; ++$i ) {
			$id = $zones->save(
				[
					'internal_code' => sprintf( 'zone-%03d', $i ),
					'internal_name' => 'Zone ' . $i,
					'status'        => RecordStatus::Active->value,
					'priority'      => 501 === $i ? 1 : 200,
				]
			);
			self::assertSame( $i, $id );
			$rules->replaceForZone(
				$id,
				[
					[
						'rule_type'  => DestinationRuleType::Country->value,
						'rule_value' => 'GH',
						'match_mode' => 'exact',
						'priority'   => 100,
					],
				]
			);
		}

		$first = $migrator->migrate( false, 0, 3 );
		self::assertFalse( $first['complete'] );
		self::assertGreaterThan( 0, (int) $first['last_zone_id'] );
		self::assertLessThan( 501, (int) $first['last_zone_id'] );
		$cursor = (int) $first['last_zone_id'];

		$second = $migrator->migrate( false, $cursor );
		self::assertTrue( $second['complete'] );
		self::assertSame( 501, (int) $second['last_zone_id'] );

		$group_rows = $this->wpdb->get_results( 'SELECT zone_id, COUNT(*) AS n FROM `' . TableNames::for( CoverageSchema::GROUPS_SUFFIX ) . '` GROUP BY zone_id' );
		self::assertCount( 501, $group_rows );
		foreach ( $group_rows as $row ) {
			self::assertSame( '1', (string) ( $row['n'] ?? '0' ) );
		}
		self::assertNotEmpty( $groups->list_by_zone( 501 ) );

		$retry = $migrator->migrate( false, 0 );
		self::assertTrue( $retry['complete'] );
		$again = $this->wpdb->get_var( 'SELECT COUNT(*) FROM `' . TableNames::for( CoverageSchema::GROUPS_SUFFIX ) . '`' );
		self::assertSame( '501', (string) $again );

		$bootstrap->bootstrap_country( 'GH' );
		$matcher = new DestinationZoneMatcher(
			$zones,
			$rules,
			null,
			new CoverageGroupMatcher( $groups, $locations ),
			new CanonicalLocationResolver( $locations, $aliases )
		);
		$matches = $matcher->match_all( 'GH', '', '', '' );
		$ids     = array_map( static fn ( array $zone ): int => (int) ( $zone['id'] ?? 0 ), $matches );
		self::assertContains( 501, $ids );
	}

	public function test_lease_cas_and_nested_hierarchy_transaction_on_real_sql(): void {
		$this->install_schema5_core();
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

		$first  = serialize( [ 'owner' => 'owner-a', 'status' => Schema6CoverageUpgradeService::STATUS_RUNNING ] );
		$second = serialize( [ 'owner' => 'owner-b', 'status' => Schema6CoverageUpgradeService::STATUS_RUNNING ] );
		$this->wpdb->insert(
			$this->wpdb->options,
			[
				'option_name'  => Schema6CoverageUpgradeService::OPTION_KEY,
				'option_value' => $first,
				'autoload'     => 'no',
			]
		);

		$updated_a = $this->wpdb->update(
			$this->wpdb->options,
			[ 'option_value' => $second ],
			[
				'option_name'  => Schema6CoverageUpgradeService::OPTION_KEY,
				'option_value' => $first,
			]
		);
		$other = RealMysqliWpdb::try_connect();
		self::assertInstanceOf( RealMysqliWpdb::class, $other );
		$updated_b = $other->update(
			$this->wpdb->options,
			[ 'option_value' => serialize( [ 'owner' => 'owner-c' ] ) ],
			[
				'option_name'  => Schema6CoverageUpgradeService::OPTION_KEY,
				'option_value' => $first,
			]
		);
		self::assertGreaterThan( 0, (int) $updated_a );
		self::assertSame( 0, (int) $updated_b );

		$repo    = new WpdbCanonicalLocationRepository();
		$country = $repo->save( $this->blank_location( $this->key( 'country', 1 ), 'Ghana' ) );
		$region_a = $repo->save( $this->blank_location( $this->key( 'region', 1 ), 'Region A', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_b = $repo->save( $this->blank_location( $this->key( 'region', 2 ), 'Region B', $country->id, GeographyLocationType::Administrative, 1 ) );
		$region_c = $repo->save( $this->blank_location( $this->key( 'region', 3 ), 'Region C', $country->id, GeographyLocationType::Administrative, 1 ) );
		$parent   = $this->save_reparent( $repo, $this->key( 'root', 1 ), 'ParentRoot', $region_a, $region_b, 'token-real' );
		$child    = $this->save_reparent( $repo, $this->key( 'root', 2 ), 'ChildRoot', $region_a, $region_c, 'token-real', $parent->id );
		$leaf     = $repo->save( $this->blank_location( $this->key( 'leaf', 1 ), 'Leaf', $child->id, GeographyLocationType::Locality ) );

		$after = 0;
		$hierarchy = [];
		$prepared  = [ 'done' => false, 'last_id' => 0, 'hierarchy_root_cursor' => 0, 'hierarchy_descendant_cursor' => 0, 'hierarchy_root_id' => 0 ];
		for ( $i = 0; $i < 50; ++$i ) {
			$prepared = $repo->prepare_generation( 'token-real', 20, $after, $hierarchy );
			$after    = (int) $prepared['last_id'];
			$hierarchy = [
				'hierarchy_root_cursor'       => (int) $prepared['hierarchy_root_cursor'],
				'hierarchy_descendant_cursor' => (int) $prepared['hierarchy_descendant_cursor'],
				'hierarchy_root_id'           => (int) $prepared['hierarchy_root_id'],
			];
			if ( $prepared['done'] ) {
				break;
			}
		}
		self::assertTrue( $prepared['done'] );
		$prepared_leaf = $repo->find_by_id( $leaf->id );
		self::assertTrue( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $region_c->id ) );
		self::assertFalse( LocationAncestry::path_contains( (string) $prepared_leaf?->prepared_ancestry_path, $region_b->id ) );
		self::assertSame( $region_a->id, $repo->find_by_id( $parent->id )?->parent_location_id );

		$table = TableNames::for( GeographySchema::LOCATIONS_SUFFIX );
		$this->wpdb->query( 'START TRANSACTION' );
		$this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE `{$table}` SET canonical_name = %s WHERE id = %d",
				'Rolled Back Leaf',
				$leaf->id
			)
		);
		$this->wpdb->query( 'ROLLBACK' );
		self::assertSame( 'Leaf', $repo->find_by_id( $leaf->id )?->canonical_name );

		$repo->finalize_generation( 'token-real' );
		self::assertSame( $region_b->id, $repo->find_by_id( $parent->id )?->parent_location_id );
		self::assertSame( $region_c->id, $repo->find_by_id( $child->id )?->parent_location_id );
		self::assertTrue( LocationAncestry::path_contains( (string) $repo->find_by_id( $leaf->id )?->ancestry_path, $region_c->id ) );
	}

	public function test_official_ghana_geonames_pack_or_explicit_dependency_block(): void {
		$url  = 'https://download.geonames.org/export/dump/GH.zip';
		$dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cetech-geo11-GH.zip';
		$txt  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cetech-geo11-GH.txt';
		if ( ! $this->download_file( $url, $dest ) ) {
			self::markTestSkipped( 'BLOCKED BY TEST DEPENDENCY: official GeoNames GH.zip could not be downloaded.' );
		}
		self::assertFileExists( $dest );
		$zip_bytes = (int) filesize( $dest );
		self::assertGreaterThan( 1000, $zip_bytes );
		$zip_checksum = hash_file( 'sha256', $dest );
		self::assertIsString( $zip_checksum );
		if ( ! class_exists( \ZipArchive::class ) ) {
			@unlink( $dest );
			self::markTestSkipped( 'BLOCKED BY TEST DEPENDENCY: PHP ZipArchive is not available.' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $dest ) ) {
			@unlink( $dest );
			self::markTestSkipped( 'BLOCKED BY TEST DEPENDENCY: official GeoNames GH.zip could not be opened.' );
		}
		$stream = $zip->getStream( 'GH.txt' );
		if ( ! is_resource( $stream ) ) {
			$zip->close();
			@unlink( $dest );
			self::markTestSkipped( 'BLOCKED BY TEST DEPENDENCY: GH.txt is missing from the official archive.' );
		}
		$out = fopen( $txt, 'wb' );
		self::assertNotFalse( $out );
		stream_copy_to_stream( $stream, $out );
		fclose( $stream );
		fclose( $out );
		$zip->close();
		$txt_checksum = hash_file( 'sha256', $txt );
		self::assertIsString( $txt_checksum );

		$this->install_schema6();
		$locations = new WpdbCanonicalLocationRepository();
		$aliases   = new WpdbLocationAliasRepository( $locations );
		$mappings  = new WpdbProviderMappingRepository();
		$packs     = new WpdbGeographyPackRepository();
		$bootstrap = new WooCommerceGeographyBootstrap( $locations, $aliases, $mappings );
		$importer  = new GeoNamesPackImporter( $locations, $aliases, $mappings, $packs, $bootstrap, new GeoNamesGazetteerParser() );
		$pack      = $importer->ensure_pack( 'GH', $txt );
		$pack      = $importer->begin_dataset( $pack, $txt, $txt_checksum, 'geonames-gh' );
		$result    = $importer->import_batch( $pack, $txt, 250 );
		$guard     = 0;
		while ( GeographyPackStatus::Ready->value !== ( $result['status'] ?? '' ) && GeographyPackStatus::Failed->value !== ( $result['status'] ?? '' ) && $guard < 2000 ) {
			$pack   = $packs->find_by_id( $pack->id );
			self::assertNotNull( $pack );
			$result = $importer->import_batch( $pack, $txt, 250 );
			++$guard;
		}
		$pack = $packs->find_by_id( $pack->id );
		self::assertNotNull( $pack );
		self::assertSame(
			GeographyPackStatus::Ready,
			$pack->status,
			(string) wp_json_encode(
				[
					'result'    => $result,
					'guard'     => $guard,
					'cursor'    => $pack->import_cursor,
					'error'     => $pack->last_error,
					'phase'     => $pack->progress['phase'] ?? '',
					'processed' => $pack->progress['processed'] ?? 0,
					'imported'  => $pack->progress['imported'] ?? 0,
				]
			)
		);
		self::assertNotEmpty( $pack->last_successful() );
		$ghana = $locations->find_country( 'GH' );
		self::assertNotNull( $ghana );
		$country_count = (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM `" . TableNames::for( GeographySchema::LOCATIONS_SUFFIX ) . "` WHERE location_type = 'country' AND country_code = 'GH'"
		);
		self::assertSame( 1, $country_count );
		$alias_names = $aliases->list_for_location( $ghana->id );
		self::assertTrue(
			'Ghana' === $ghana->canonical_name || in_array( 'Ghana', $alias_names, true ),
			'GH country node must be Ghana by canonical name or alias. Observed: ' . $ghana->canonical_name
		);
		$greater = $locations->find_unique_administrative_core( 'GH', $ghana->id, 'Greater Accra', 1 );
		self::assertNotNull( $greater );
		$siblings = 0;
		$offset   = 0;
		do {
			$page = $locations->list_children( $ghana->id, GeographyLocationType::Administrative, 250, $offset );
			foreach ( $page as $child ) {
				if ( GeographyNameNormalizer::administrative_core( $child->canonical_name ) === 'greater accra' ) {
					++$siblings;
				}
			}
			$offset += count( $page );
		} while ( 250 === count( $page ) );
		self::assertSame( 1, $siblings );
		$accra = $this->find_named_descendant( $greater, 'accra' );
		self::assertInstanceOf( CanonicalLocation::class, $accra );
		self::assertTrue( LocationAncestry::path_contains( $accra->ancestry_path, $greater->id ) );
		$tema = $this->find_named_descendant( $greater, 'tema' );
		if ( ! $tema instanceof CanonicalLocation ) {
			$tema = $this->find_named_descendant( $ghana, 'tema' );
		}
		self::assertInstanceOf( CanonicalLocation::class, $tema );
		self::assertTrue( LocationAncestry::path_contains( $tema->ancestry_path, $greater->id ) );
		$location_count = (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM `' . TableNames::for( GeographySchema::LOCATIONS_SUFFIX ) . '`' );
		fwrite( STDERR, sprintf(
			"GH pack zip_bytes=%d zip_sha256=%s txt_sha256=%s locations=%d greater_accra_id=%d accra_id=%d tema_id=%d ready=%s\n",
			$zip_bytes,
			$zip_checksum,
			$txt_checksum,
			$location_count,
			$greater->id,
			$accra->id,
			$tema->id,
			$pack->status->value
		) );
		@unlink( $txt );
		@unlink( $dest );
	}

	private function install_schema5_core(): void {
		$charset = $this->wpdb->get_charset_collate();
		$zones   = TableNames::for( 'destination_zones' );
		$rules   = TableNames::for( 'destination_rules' );
		$cards   = TableNames::for( 'rate_cards' );
		$this->wpdb->query(
			"CREATE TABLE {$zones} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				internal_code varchar(64) NOT NULL,
				internal_name varchar(255) NOT NULL,
				public_label varchar(255) DEFAULT NULL,
				is_fallback tinyint(1) NOT NULL DEFAULT 0,
				remote_area_flag tinyint(1) NOT NULL DEFAULT 0,
				priority int(11) NOT NULL DEFAULT 100,
				status varchar(16) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY internal_code (internal_code)
			) ENGINE=InnoDB {$charset}"
		);
		$this->wpdb->query(
			"CREATE TABLE {$rules} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				zone_id bigint(20) unsigned NOT NULL,
				rule_type varchar(32) NOT NULL,
				rule_value varchar(255) NOT NULL,
				match_mode varchar(16) NOT NULL DEFAULT 'exact',
				priority int(11) NOT NULL DEFAULT 100,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY zone_id (zone_id)
			) ENGINE=InnoDB {$charset}"
		);
		$this->wpdb->query(
			"CREATE TABLE {$cards} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				internal_code varchar(64) NOT NULL,
				delivery_offer_id bigint(20) unsigned NOT NULL,
				destination_zone_id bigint(20) unsigned NOT NULL,
				logistics_profile_id bigint(20) unsigned DEFAULT NULL,
				supplier_id bigint(20) unsigned DEFAULT NULL,
				origin_id bigint(20) unsigned DEFAULT NULL,
				charge_type varchar(32) NOT NULL,
				base_amount decimal(19,4) NOT NULL DEFAULT 0.0000,
				base_currency char(3) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY internal_code (internal_code)
			) ENGINE=InnoDB {$charset}"
		);
		self::assertSame( '', $this->wpdb->last_error, $this->wpdb->last_error );
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
			CoverageSchema::SUFFIXES,
			[ 'destination_zones', 'destination_rules', 'rate_cards' ]
		);
		foreach ( $suffixes as $suffix ) {
			$this->wpdb->query( 'DROP TABLE IF EXISTS `' . TableNames::for( $suffix ) . '`' );
		}
		$this->wpdb->query( 'DROP TABLE IF EXISTS `' . $this->wpdb->options . '`' );
	}

	private function table_exists( string $suffix ): bool {
		$table = TableNames::for( $suffix );
		$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
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

	private function find_named_descendant( CanonicalLocation $ancestor, string $normalized_name ): ?CanonicalLocation {
		$table = TableNames::for( GeographySchema::LOCATIONS_SUFFIX );
		$like  = $this->wpdb->esc_like( $ancestor->ancestry_path ) . '%';
		$row   = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE country_code = %s AND normalized_name = %s AND (id = %d OR parent_location_id = %d OR ancestry_path LIKE %s) AND status = %s ORDER BY id ASC LIMIT 1",
				$ancestor->country_code,
				$normalized_name,
				$ancestor->id,
				$ancestor->id,
				$like,
				RecordStatus::Active->value
			)
		);

		return is_array( $row ) ? CanonicalLocation::fromRow( $row ) : null;
	}

	private function key( string $kind, int $n ): string {
		return sprintf( 'b0000000-0000-4000-8%03s-%012d', substr( $kind, 0, 3 ), $n );
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

	private function download_file( string $url, string $destination ): bool {
		if ( is_file( $destination ) && filesize( $destination ) > 1000 ) {
			return true;
		}
		if ( $this->command_exists( 'curl.exe' ) ) {
			$command = sprintf(
				'%s -L --max-time 60 -A %s -o %s %s',
				escapeshellarg( 'curl.exe' ),
				escapeshellarg( 'CETECH-geo11-proof' ),
				escapeshellarg( $destination ),
				escapeshellarg( $url )
			);
			exec( $command, $output, $code );
			if ( 0 === $code && is_file( $destination ) && filesize( $destination ) > 1000 ) {
				return true;
			}
		}
		$context = stream_context_create(
			[
				'http' => [
					'timeout' => 20,
					'header'  => "User-Agent: CETECH-geo11-proof\r\n",
				],
			]
		);
		$data = @file_get_contents( $url, false, $context );
		if ( ! is_string( $data ) || strlen( $data ) < 1000 ) {
			return false;
		}

		return false !== file_put_contents( $destination, $data );
	}

	private function command_exists( string $name ): bool {
		$paths = explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) );
		foreach ( $paths as $path ) {
			$candidate = rtrim( $path, '\\/' ) . DIRECTORY_SEPARATOR . $name;
			if ( is_file( $candidate ) ) {
				return true;
			}
		}

		return false;
	}
}
