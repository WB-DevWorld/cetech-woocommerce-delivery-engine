<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetFilters;
use CetechDeliveryEngine\Application\Bulk\Catalog\WooCommerceCatalogTargetQuery;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Tests\Support\RealMysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Physical MariaDB proof for COR-004. Excluded from the default suite.
 *
 * @group cor004-real-db
 */
final class CatalogTargetIdentitySqlTest extends TestCase {

	private \PDO $pdo;

	private RealMysqliWpdb $wpdb;

	private string $digest = '';

	protected function setUp(): void {
		parent::setUp();
		$wpdb = $this->connect();
		if ( ! $wpdb instanceof RealMysqliWpdb ) {
			self::markTestSkipped( 'Disposable COR-004 MariaDB is not reachable.' );
		}
		$this->wpdb = $wpdb;
		$this->pdo  = $wpdb->pdo();
		$database   = (string) $this->pdo->query( 'SELECT DATABASE()' )->fetchColumn();
		if ( ! str_starts_with( $database, 'cetech_cor004_' ) ) {
			self::fail( 'Refusing to mutate a database outside the COR-004 disposable prefix.' );
		}
		$GLOBALS['wpdb'] = $wpdb;
		$this->install_fixture();
		$this->digest = $this->snapshot_digest();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_taxonomy_term_identity_is_not_term_taxonomy_id(): void {
		$category = $this->ids( [ CatalogTargetFilters::CATEGORY_ID => 50 ] );
		$tag      = $this->ids( [ CatalogTargetFilters::TAG_ID => 900 ] );
		$shipping = $this->ids( [ CatalogTargetFilters::SHIPPING_CLASS_ID => 70 ] );
		$collision = $this->ids( [ CatalogTargetFilters::CATEGORY_ID => 900 ] );

		self::assertSame( [ 101 ], $category );
		self::assertSame( [ 102 ], $tag );
		self::assertSame( [ 103 ], $shipping );
		self::assertSame( [], $collision );
		self::assertStringContainsString( 'term_id', $this->last_sql() );
		self::assertStringNotContainsString( 'term_taxonomy_id = 50', $this->last_sql() );
		$this->assert_unchanged();
	}

	public function test_pickup_endpoint_ignores_other_endpoints_and_store_pickup_offers(): void {
		self::assertSame( [ 201 ], $this->ids( [ CatalogTargetFilters::PICKUP_LOCATION_ID => 7 ] ) );
		self::assertSame( [ 202 ], $this->ids( [ CatalogTargetFilters::PICKUP_LOCATION_ID => 8 ] ) );
		$sql = $this->last_sql();
		self::assertStringNotContainsString( 'store_pickup', $sql );
		self::assertStringNotContainsString( 'LIKE CONCAT', $sql );
		$this->assert_unchanged();
	}

	public function test_offer_member_one_does_not_match_member_ten_and_remove_is_not_inclusion(): void {
		$ids = $this->ids( [ CatalogTargetFilters::DELIVERY_OPTION_ID => 1 ] );

		self::assertSame( [ 301, 304, 305, 306 ], $ids );
		self::assertStringContainsString( 'JSON_CONTAINS', $this->last_sql() );
		self::assertStringNotContainsString( 'LIKE', $this->last_sql() );
		$this->assert_unchanged();
	}

	public function test_count_preview_and_selected_identity_agree_and_unknown_keys_do_not_write(): void {
		$filters    = [ CatalogTargetFilters::CATEGORY_ID => 50 ];
		$definition = $this->definition( $filters );
		$query      = new WooCommerceCatalogTargetQuery();
		$page       = $this->ids( $filters );

		self::assertSame( $page, $this->target_ids( $query, $definition ) );
		self::assertSame( count( $page ), $query->count( $definition ) );

		$selected = new CatalogTargetDefinition(
			BulkTargetScope::SelectedIds,
			[ 101, 401, 99999 ],
			[],
			[],
			\CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy::PreserveOverrides,
			false,
			CatalogTargetDefinition::TARGET_PRODUCT
		);
		self::assertSame( [ 101 ], $this->target_ids( $query, $selected ) );
		self::assertSame( 1, $query->count( $selected ) );

		$this->wpdb->clear_sql_log();
		try {
			$query->count(
				new CatalogTargetDefinition(
					BulkTargetScope::MatchingFilters,
					[],
					[ 'not_a_catalog_key' => 'broaden' ]
				)
			);
			self::fail( 'Unsupported filters must be rejected.' );
		} catch ( \InvalidArgumentException $exception ) {
			self::assertSame( 'Unsupported catalog filter.', $exception->getMessage() );
		}
		self::assertSame( [], $this->wpdb->sql_log );
		$this->assert_unchanged();
	}

	private function connect(): ?RealMysqliWpdb {
		if ( ! class_exists( \PDO::class ) || ! in_array( 'mysql', \PDO::getAvailableDrivers(), true ) ) {
			return null;
		}
		$host = (string) ( getenv( 'CETECH_DE_COR004_DB_HOST' ) ?: '127.0.0.1' );
		$port = (int) ( getenv( 'CETECH_DE_COR004_DB_PORT' ) ?: 33079 );
		$user = (string) ( getenv( 'CETECH_DE_COR004_DB_USER' ) ?: 'root' );
		$pass = (string) ( getenv( 'CETECH_DE_COR004_DB_PASSWORD' ) ?: 'cetech-cor004' );
		$name = (string) ( getenv( 'CETECH_DE_COR004_DB_NAME' ) ?: 'cetech_cor004_catalog' );
		try {
			$server = new \PDO( "mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
			$server->exec( 'CREATE DATABASE IF NOT EXISTS `' . str_replace( '`', '', $name ) . '`' );
			$pdo = new \PDO( "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC ] );
		} catch ( \PDOException ) {
			return null;
		}

		return new RealMysqliWpdb( $pdo, 'cor004_' );
	}

	private function install_fixture(): void {
		$prefix = 'cor004_';
		$engine = $prefix . 'delivery_engine_';
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . 'term_relationships`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . 'term_taxonomy`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . 'terms`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . 'postmeta`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $prefix . 'posts`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $engine . 'configuration_collections`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $engine . 'configuration_fields`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $engine . 'configuration_scopes`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $engine . 'delivery_offers`' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS `' . $engine . 'pickup_locations`' );
		$this->pdo->exec( "CREATE TABLE `{$prefix}posts` (ID bigint unsigned NOT NULL, post_type varchar(20) NOT NULL, post_status varchar(20) NOT NULL, post_title text NOT NULL, post_parent bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY (ID))" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}postmeta` (meta_id bigint unsigned NOT NULL AUTO_INCREMENT, post_id bigint unsigned NOT NULL, meta_key varchar(255) DEFAULT NULL, meta_value longtext, PRIMARY KEY (meta_id))" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}terms` (term_id bigint unsigned NOT NULL, name varchar(200) NOT NULL, slug varchar(200) NOT NULL, PRIMARY KEY (term_id))" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}term_taxonomy` (term_taxonomy_id bigint unsigned NOT NULL, term_id bigint unsigned NOT NULL, taxonomy varchar(32) NOT NULL, PRIMARY KEY (term_taxonomy_id))" );
		$this->pdo->exec( "CREATE TABLE `{$prefix}term_relationships` (object_id bigint unsigned NOT NULL, term_taxonomy_id bigint unsigned NOT NULL, term_order int NOT NULL DEFAULT 0, PRIMARY KEY (object_id, term_taxonomy_id))" );
		$this->pdo->exec( "CREATE TABLE `{$engine}configuration_scopes` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_type varchar(32) NOT NULL, scope_id bigint NOT NULL, slice_key varchar(191) NOT NULL, status varchar(32) NOT NULL, PRIMARY KEY (id))" );
		$this->pdo->exec( "CREATE TABLE `{$engine}configuration_fields` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, value_text longtext, PRIMARY KEY (id))" );
		$this->pdo->exec( "CREATE TABLE `{$engine}configuration_collections` (id bigint unsigned NOT NULL AUTO_INCREMENT, scope_row_id bigint unsigned NOT NULL, field_key varchar(64) NOT NULL, mode varchar(32) NOT NULL, members_json longtext NOT NULL, PRIMARY KEY (id))" );
		$this->pdo->exec( "CREATE TABLE `{$engine}delivery_offers` (id bigint unsigned NOT NULL, route varchar(64) NOT NULL, PRIMARY KEY (id))" );
		$this->pdo->exec( "CREATE TABLE `{$engine}pickup_locations` (id bigint unsigned NOT NULL, PRIMARY KEY (id))" );

		$this->insert_terms();
		$this->insert_posts();
		$this->insert_configuration();
	}

	private function insert_terms(): void {
		$prefix = 'cor004_';
		$this->pdo->exec( "INSERT INTO `{$prefix}terms` (term_id, name, slug) VALUES (50,'Category Fifty','cat-50'),(900,'Tag Nine Hundred','tag-900'),(70,'Ship Seventy','ship-70'),(770,'Foreign Taxonomy','foreign-770')" );
		$this->pdo->exec( "INSERT INTO `{$prefix}term_taxonomy` (term_taxonomy_id, term_id, taxonomy) VALUES (900,50,'product_cat'),(50,900,'product_tag'),(770,70,'product_shipping_class'),(70,770,'product_cat')" );
		$this->pdo->exec( "INSERT INTO `{$prefix}term_relationships` (object_id, term_taxonomy_id) VALUES (101,900),(102,50),(103,770),(104,70)" );
	}

	private function insert_posts(): void {
		$prefix = 'cor004_';
		$this->pdo->exec(
			"INSERT INTO `{$prefix}posts` (ID, post_type, post_status, post_title, post_parent) VALUES
			(101,'product','publish','Category member',0),
			(102,'product','publish','Tag member',0),
			(103,'product','publish','Shipping member',0),
			(104,'product','publish','Taxonomy collision',0),
			(201,'product','publish','Pickup A',0),
			(202,'product','publish','Pickup B',0),
			(203,'product','publish','In store only',0),
			(204,'product','publish','Unrelated store pickup',0),
			(301,'product','publish','Replace offer 1',0),
			(302,'product','publish','Replace offer 10',0),
			(303,'product','publish','Remove offer 1',0),
			(304,'product','publish','Add offer 1',0),
			(305,'product','publish','Inherit offer 1',0),
			(306,'product','publish','Replace both',0),
			(401,'product_variation','publish','Wrong type',101)"
		);
	}

	private function insert_configuration(): void {
		$engine = 'cor004_delivery_engine_';
		$this->pdo->exec( "INSERT INTO `{$engine}pickup_locations` (id) VALUES (7),(8)" );
		$this->pdo->exec( "INSERT INTO `{$engine}delivery_offers` (id, route) VALUES (1,'store_pickup'),(10,'store_pickup')" );
		$this->pdo->exec( "INSERT INTO `{$engine}configuration_scopes` (id, scope_type, scope_id, slice_key, status) VALUES
			(1,'global',0,'','active'),
			(2,'product',201,'','active'),
			(3,'product',202,'','active'),
			(4,'product',203,'','active'),
			(5,'product',204,'','active'),
			(11,'product',101,'','active'),
			(12,'product',102,'','active'),
			(13,'product',103,'','active'),
			(14,'product',104,'','active'),
			(15,'product',201,'','active'),
			(16,'product',202,'','active'),
			(17,'product',203,'','active'),
			(6,'product',301,'','active'),
			(7,'product',302,'','active'),
			(8,'product',303,'','active'),
			(9,'product',304,'','active'),
			(10,'product',306,'','active')" );
		$pickup = ConfigurationFieldKey::PICKUP_LOCATION_ID;
		$fulfil = ConfigurationFieldKey::FULFILMENT_AVAILABILITY;
		$this->pdo->exec( "INSERT INTO `{$engine}configuration_fields` (scope_row_id, field_key, mode, value_text) VALUES
			(2,'{$pickup}','override','7'),
			(3,'{$pickup}','override','8'),
			(4,'{$fulfil}','override','in_store')" );
		$offers = ConfigurationFieldKey::DELIVERY_OFFER_IDS;
		$this->pdo->exec( "INSERT INTO `{$engine}configuration_collections` (scope_row_id, field_key, mode, members_json) VALUES
			(1,'{$offers}','replace','[1]'),
			(5,'{$offers}','replace','[10]'),
			(11,'{$offers}','replace','[]'),
			(12,'{$offers}','replace','[]'),
			(13,'{$offers}','replace','[]'),
			(14,'{$offers}','replace','[]'),
			(15,'{$offers}','replace','[]'),
			(16,'{$offers}','replace','[]'),
			(17,'{$offers}','replace','[]'),
			(6,'{$offers}','replace','[1]'),
			(7,'{$offers}','replace','[10]'),
			(8,'{$offers}','remove','[1]'),
			(9,'{$offers}','add','[1]'),
			(10,'{$offers}','replace','[1,10]')" );
		unset( $offers );
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return list<int>
	 */
	private function ids( array $filters ): array {
		return $this->target_ids( new WooCommerceCatalogTargetQuery(), $this->definition( $filters ) );
	}

	/**
	 * @return list<int>
	 */
	private function target_ids( WooCommerceCatalogTargetQuery $query, CatalogTargetDefinition $definition ): array {
		$ids = [];
		foreach ( $query->page_after( $definition, 0, 50 ) as $target ) {
			$ids[] = $target->id;
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $filters
	 */
	private function definition( array $filters ): CatalogTargetDefinition {
		return CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => $filters,
			]
		);
	}

	private function last_sql(): string {
		$log = $this->wpdb->sql_log;

		return (string) ( $log[ count( $log ) - 1 ] ?? '' );
	}

	private function snapshot_digest(): string {
		$parts = [];
		foreach ( [ 'cor004_posts', 'cor004_term_relationships', 'cor004_delivery_engine_configuration_fields', 'cor004_delivery_engine_configuration_collections' ] as $table ) {
			$parts[] = $table . ':' . (string) $this->pdo->query( 'CHECKSUM TABLE `' . $table . '`' )->fetchColumn( 1 );
		}

		return implode( '|', $parts );
	}

	private function assert_unchanged(): void {
		self::assertSame( $this->digest, $this->snapshot_digest() );
	}
}
