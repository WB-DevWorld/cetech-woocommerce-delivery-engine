<?php

declare(strict_types=1);

/**
 * Controlled schema 2→3 dry-run against a disposable MySQL database.
 *
 * NOT for FLAIROC. Uses a minimal wpdb stub + PDO. Do not commit credentials.
 *
 * Usage:
 *   php scripts/stage3-schema-2-to-3-dry-run.php
 *
 * Env overrides (optional):
 *   STAGE3_DB_HOST (default 127.0.0.1)
 *   STAGE3_DB_PORT (default 3307)
 *   STAGE3_DB_NAME (default cetech_de_stage3)
 *   STAGE3_DB_USER (default root)
 *   STAGE3_DB_PASS (default stage3test)
 */

use CetechDeliveryEngine\Application\Configuration\LegacyConfigurationMigrator;
use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Domain\Configuration\LegacyProductRuleMigrationMapper;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Support\Logger;

$root = dirname( __DIR__ );

require_once $root . '/vendor/autoload.php';

$host = getenv( 'STAGE3_DB_HOST' ) ?: '127.0.0.1';
$port = (int) ( getenv( 'STAGE3_DB_PORT' ) ?: 3307 );
$name = getenv( 'STAGE3_DB_NAME' ) ?: 'cetech_de_stage3';
$user = getenv( 'STAGE3_DB_USER' ) ?: 'root';
$pass = getenv( 'STAGE3_DB_PASS' ) ?: 'stage3test';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/scripts/stage3-wp-stubs/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

$GLOBALS['cetech_de_test_options'] = [];

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = true ): bool {
		$GLOBALS['cetech_de_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['cetech_de_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
		if ( array_key_exists( $option, $GLOBALS['cetech_de_test_options'] ) ) {
			return false;
		}

		$GLOBALS['cetech_de_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options | JSON_UNESCAPED_UNICODE, $depth );
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * Minimal WC product stub for variation parent resolution in dry-run fixtures.
	 *
	 * @return object{is_type(string): bool, get_parent_id(): int}|null
	 */
	function wc_get_product( $product_id ) {
		$product_id = (int) $product_id;

		if ( 501 === $product_id ) {
			return new class {
				public function is_type( string $type ): bool {
					return 'variation' === $type;
				}

				public function get_parent_id(): int {
					return 101;
				}
			};
		}

		return null;
	}
}


if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
	mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}

file_put_contents(
	ABSPATH . 'wp-admin/includes/upgrade.php',
	<<<'PHP'
<?php
function dbDelta( $queries ) {
	global $wpdb;
	$statements = is_array( $queries ) ? $queries : array( $queries );
	foreach ( $statements as $sql ) {
		$sql = trim( (string) $sql );
		if ( '' === $sql ) {
			continue;
		}
		// Convert WordPress-style CREATE TABLE to IF NOT EXISTS for idempotent dry-runs.
		$sql = preg_replace( '/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1 );
		$wpdb->query( $sql );
	}
}
PHP
);

final class Stage3DryRunWpdb {

	public string $prefix = 'wp_';

	public int $insert_id = 0;

	public string $last_error = '';

	private PDO $pdo;

	public function __construct( PDO $pdo ) {
		$this->pdo = $pdo;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( string $query, ...$args ): string {
		$i = 0;

		return (string) preg_replace_callback(
			'/%[sdfF]/',
			static function ( array $matches ) use ( &$i, $args ): string {
				if ( ! array_key_exists( $i, $args ) ) {
					throw new RuntimeException( 'wpdb prepare argument mismatch.' );
				}

				$value  = $args[ $i++ ];
				$format = $matches[0];

				if ( '%d' === $format ) {
					return (string) (int) $value;
				}

				if ( '%f' === $format || '%F' === $format ) {
					return (string) (float) $value;
				}

				return "'" . str_replace( [ "\\", "'" ], [ "\\\\", "\\'" ], (string) $value ) . "'";
			},
			$query
		);
	}

	public function query( string $sql ) {
		try {
			$result = $this->pdo->exec( $sql );
			$this->last_error = '';

			return false === $result ? false : $result;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	public function get_var( string $sql ) {
		try {
			$stmt = $this->pdo->query( $sql );
			if ( false === $stmt ) {
				return null;
			}
			$value = $stmt->fetchColumn();
			$this->last_error = '';

			return false === $value ? null : $value;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return null;
		}
	}

	public function get_row( string $sql, $output = ARRAY_A ) {
		try {
			$stmt = $this->pdo->query( $sql );
			if ( false === $stmt ) {
				return null;
			}
			$row = $stmt->fetch( PDO::FETCH_ASSOC );
			$this->last_error = '';

			return false === $row ? null : $row;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return null;
		}
	}

	public function get_results( string $sql, $output = ARRAY_A ) {
		try {
			$stmt = $this->pdo->query( $sql );
			if ( false === $stmt ) {
				return [];
			}
			$rows = $stmt->fetchAll( PDO::FETCH_ASSOC );
			$this->last_error = '';

			return is_array( $rows ) ? $rows : [];
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return [];
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @param list<string>|null    $format
	 */
	public function insert( string $table, array $data, $format = null ) {
		$columns = array_keys( $data );
		$placeholders = [];
		$values = [];

		foreach ( $columns as $column ) {
			$placeholders[] = ':' . $column;
			$values[ ':' . $column ] = $data[ $column ];
		}

		$sql = sprintf(
			'INSERT INTO `%s` (`%s`) VALUES (%s)',
			str_replace( '`', '``', $table ),
			implode( '`,`', array_map( static fn ( string $c ): string => str_replace( '`', '``', $c ), $columns ) ),
			implode( ',', $placeholders )
		);

		try {
			$stmt = $this->pdo->prepare( $sql );
			$ok   = $stmt->execute( $values );
			$this->insert_id = (int) $this->pdo->lastInsertId();
			$this->last_error = '';

			return $ok ? 1 : false;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		$set_parts = [];
		$values    = [];

		foreach ( $data as $column => $value ) {
			$key           = 'set_' . $column;
			$set_parts[]   = '`' . str_replace( '`', '``', $column ) . '` = :' . $key;
			$values[ ':' . $key ] = $value;
		}

		$where_parts = [];

		foreach ( $where as $column => $value ) {
			$key = 'where_' . $column;
			$where_parts[] = '`' . str_replace( '`', '``', $column ) . '` = :' . $key;
			$values[ ':' . $key ] = $value;
		}

		$sql = sprintf(
			'UPDATE `%s` SET %s WHERE %s',
			str_replace( '`', '``', $table ),
			implode( ', ', $set_parts ),
			implode( ' AND ', $where_parts )
		);

		try {
			$stmt = $this->pdo->prepare( $sql );
			$ok   = $stmt->execute( $values );
			$this->last_error = '';

			return $ok ? $stmt->rowCount() : false;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}

	/**
	 * @param array<string, mixed> $where
	 */
	public function delete( string $table, array $where, $where_format = null ) {
		$where_parts = [];
		$values      = [];

		foreach ( $where as $column => $value ) {
			$key = 'where_' . $column;
			$where_parts[] = '`' . str_replace( '`', '``', $column ) . '` = :' . $key;
			$values[ ':' . $key ] = $value;
		}

		$sql = sprintf(
			'DELETE FROM `%s` WHERE %s',
			str_replace( '`', '``', $table ),
			implode( ' AND ', $where_parts )
		);

		try {
			$stmt = $this->pdo->prepare( $sql );
			$ok   = $stmt->execute( $values );
			$this->last_error = '';

			return $ok ? $stmt->rowCount() : false;
		} catch ( Throwable $e ) {
			$this->last_error = $e->getMessage();

			return false;
		}
	}
}

function stage3_fail( string $message ): never {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function stage3_ok( string $message ): void {
	fwrite( STDOUT, "OK: {$message}\n" );
}

try {
	$dsn = sprintf( 'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name );
	$pdo = new PDO( $dsn, $user, $pass, [
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	] );
} catch ( Throwable $e ) {
	stage3_fail( 'Cannot connect to disposable MySQL: ' . $e->getMessage() );
}

$pdo->exec( 'SET NAMES utf8mb4' );

// Reset disposable schema.
foreach (
	[
		'wp_delivery_engine_configuration_collections',
		'wp_delivery_engine_configuration_fields',
		'wp_delivery_engine_configuration_scopes',
		'wp_delivery_engine_product_delivery_rules',
		'wp_delivery_engine_shipments',
	] as $table
) {
	$pdo->exec( "DROP TABLE IF EXISTS `{$table}`" );
}

$GLOBALS['wpdb'] = new Stage3DryRunWpdb( $pdo );
$wpdb            = $GLOBALS['wpdb'];

$charset = $wpdb->get_charset_collate();
$legacy  = TableNames::for( 'product_delivery_rules' );

$pdo->exec(
	"CREATE TABLE `{$legacy}` (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		target_type varchar(32) NOT NULL,
		target_id bigint(20) unsigned NOT NULL,
		target_label_snapshot varchar(255) DEFAULT NULL,
		fulfilment_availability varchar(64) NOT NULL,
		fulfilment_choice varchar(64) NOT NULL,
		delivery_offer_ids longtext DEFAULT NULL,
		logistics_profile_id bigint(20) unsigned DEFAULT NULL,
		supplier_id bigint(20) unsigned DEFAULT NULL,
		origin_id bigint(20) unsigned DEFAULT NULL,
		priority int(11) NOT NULL DEFAULT 100,
		status varchar(32) NOT NULL DEFAULT 'active',
		internal_notes longtext DEFAULT NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id)
	) {$charset}"
);

$legacy_fixture = [
	[
		'target_type'             => 'product',
		'target_id'               => 101,
		'target_label_snapshot'   => 'Product 101',
		'fulfilment_availability' => 'in_store',
		'fulfilment_choice'       => 'delivery',
		'delivery_offer_ids'      => wp_json_encode( [ 4, 5 ] ),
		'logistics_profile_id'    => 9,
		'supplier_id'             => null,
		'origin_id'               => 0,
		'priority'                => 0,
		'status'                  => 'active',
		'internal_notes'          => 'keep-private',
	],
	[
		'target_type'             => 'product',
		'target_id'               => 101,
		'target_label_snapshot'   => 'Product 101 intl',
		'fulfilment_availability' => 'international_fulfilment',
		'fulfilment_choice'       => 'delivery',
		'delivery_offer_ids'      => wp_json_encode( [ 7 ] ),
		'logistics_profile_id'    => 2,
		'supplier_id'             => 55,
		'origin_id'               => 3,
		'priority'                => 10,
		'status'                  => 'active',
		'internal_notes'          => null,
	],
	[
		'target_type'             => 'variation',
		'target_id'               => 501,
		'target_label_snapshot'   => 'Variation 501',
		'fulfilment_availability' => 'in_store',
		'fulfilment_choice'       => 'store_pickup',
		'delivery_offer_ids'      => wp_json_encode( [] ),
		'logistics_profile_id'    => null,
		'supplier_id'             => 12,
		'origin_id'               => null,
		'priority'                => 5,
		'status'                  => 'active',
		'internal_notes'          => null,
	],
	[
		'target_type'             => 'category',
		'target_id'               => 77,
		'target_label_snapshot'   => 'Category 77',
		'fulfilment_availability' => 'in_warehouse',
		'fulfilment_choice'       => 'delivery',
		'delivery_offer_ids'      => wp_json_encode( [ 1 ] ),
		'logistics_profile_id'    => 1,
		'supplier_id'             => 1,
		'origin_id'               => 1,
		'priority'                => 100,
		'status'                  => 'active',
		'internal_notes'          => 'quarantine-me',
	],
];

foreach ( $legacy_fixture as $row ) {
	$wpdb->insert( $legacy, $row );
}

$legacy_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy}`" );
if ( 4 !== $legacy_count_before ) {
	stage3_fail( 'Expected 4 legacy fixture rows before migration.' );
}

SchemaVersion::ensure_initialized();
SchemaVersion::set( '2' );
if ( '2' !== SchemaVersion::get() ) {
	stage3_fail( 'Failed to set schema version to 2 for dry-run baseline.' );
}

$migration = require $root . '/database/migrations/20260810160000_create_scoped_configuration_tables.php';

// --- First upgrade ---
$migration->up();
$migration->verify();
SchemaVersion::set( '3' );
stage3_ok( 'First upgrade reached schema 3 and verify() passed.' );

foreach ( ScopedConfigurationSchema::SUFFIXES as $suffix ) {
	if ( ! ConfigurationTables::exists( $suffix ) ) {
		stage3_fail( "Missing table after upgrade: {$suffix}" );
	}
}
stage3_ok( 'configuration_scopes / fields / collections created.' );

if ( ! ConfigurationTables::exists( 'product_delivery_rules' ) ) {
	stage3_fail( 'Legacy product_delivery_rules missing after upgrade.' );
}

$legacy_count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$legacy}`" );
if ( $legacy_count_after !== $legacy_count_before ) {
	stage3_fail( 'Legacy row count changed during migration.' );
}
stage3_ok( 'Legacy product_delivery_rules remains intact.' );

$repo = new WpdbScopedConfigurationRepository();
$global = $repo->getGlobalConfiguration();
if ( null === $global ) {
	stage3_fail( 'Global scope missing.' );
}

$product_scopes = $repo->findByScope(
	\CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product,
	101
);
if ( 2 !== count( $product_scopes ) ) {
	stage3_fail( 'Expected 2 product slices for product 101, got ' . count( $product_scopes ) );
}

$slice_keys = array_map( static fn ( $c ) => $c->scope->slice_key, $product_scopes );
sort( $slice_keys );
if ( [ 'in_store', 'international_fulfilment' ] !== $slice_keys ) {
	stage3_fail( 'Unexpected product slice keys: ' . implode( ',', $slice_keys ) );
}
stage3_ok( 'Representative legacy product rows migrated into distinct slices.' );

$category_scopes = $wpdb->get_var(
	"SELECT COUNT(*) FROM `" . TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX ) . "` WHERE scope_type = 'category'"
);
if ( (int) $category_scopes > 0 ) {
	stage3_fail( 'Category scope rows were written to v3 storage.' );
}

$report = get_option( ScopedConfigurationSchema::MIGRATION_REPORT_OPTION, [] );
$quarantined = is_array( $report['quarantined'] ?? null ) ? $report['quarantined'] : [];
$found_category_quarantine = false;
foreach ( $quarantined as $entry ) {
	if ( ( $entry['reason'] ?? '' ) === LegacyProductRuleMigrationMapper::QUARANTINE_CATEGORY ) {
		$found_category_quarantine = true;
		break;
	}
}
if ( ! $found_category_quarantine ) {
	stage3_fail( 'Category quarantine not recorded in migration report.' );
}
stage3_ok( 'Category rows remain quarantined.' );

$in_store = $repo->findByScopeAndSlice(
	\CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product,
	101,
	'in_store'
);
if ( null === $in_store ) {
	stage3_fail( 'Missing in_store product scope.' );
}
$supplier = $in_store->scalars['supplier_id'] ?? null;
$origin   = $in_store->scalars['origin_id'] ?? null;
$offers   = $in_store->collections['delivery_offer_ids'] ?? null;
if ( null === $supplier || \CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode::Disable !== $supplier->mode ) {
	stage3_fail( 'Migrated null supplier_id must be DISABLE.' );
}
if ( null === $origin || \CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode::Disable !== $origin->mode ) {
	stage3_fail( 'Migrated 0 origin_id must be DISABLE.' );
}
if ( null === $offers || \CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode::Replace !== $offers->mode || [ 4, 5 ] !== $offers->members ) {
	stage3_fail( 'Migrated offers must be REPLACE [4,5].' );
}
stage3_ok( 'Representative legacy field mappings verified.' );

$scope_count_1 = (int) $wpdb->get_var(
	'SELECT COUNT(*) FROM `' . TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX ) . '`'
);
$field_count_1 = (int) $wpdb->get_var(
	'SELECT COUNT(*) FROM `' . TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX ) . '`'
);
$collection_count_1 = (int) $wpdb->get_var(
	'SELECT COUNT(*) FROM `' . TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX ) . '`'
);
$versions_1 = $wpdb->get_results(
	'SELECT id, config_version FROM `' . TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX ) . '` ORDER BY id ASC'
);

// --- Repeat upgrade (idempotent) ---
$migration->up();
$migration->verify();
if ( '3' !== SchemaVersion::get() ) {
	stage3_fail( 'Schema version changed unexpectedly on repeat verify path.' );
}

$scope_count_2 = (int) $wpdb->get_var(
	'SELECT COUNT(*) FROM `' . TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX ) . '`'
);
$field_count_2 = (int) $wpdb->get_var(
	'SELECT COUNT(*) FROM `' . TableNames::for( ScopedConfigurationSchema::FIELDS_SUFFIX ) . '`'
);
$collection_count_2 = (int) $wpdb->get_var(
	'SELECT COUNT(*) FROM `' . TableNames::for( ScopedConfigurationSchema::COLLECTIONS_SUFFIX ) . '`'
);
$versions_2 = $wpdb->get_results(
	'SELECT id, config_version FROM `' . TableNames::for( ScopedConfigurationSchema::SCOPES_SUFFIX ) . '` ORDER BY id ASC'
);

if ( $scope_count_1 !== $scope_count_2 || $field_count_1 !== $field_count_2 || $collection_count_1 !== $collection_count_2 ) {
	stage3_fail( 'Repeat migration created duplicate scopes/fields/collections.' );
}
if ( $versions_1 !== $versions_2 ) {
	stage3_fail( 'Repeat migration incremented configuration versions.' );
}
stage3_ok( 'Second migration execution is idempotent; versions unchanged.' );

// --- Premature schema version / failure safety ---
SchemaVersion::set( '2' );
$runner = new MigrationRunner( new Logger() );
$runner->set_migrations( [
	new class implements \CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface {
		public function get_id(): string {
			return 'force_fail_for_dry_run';
		}
		public function get_version(): string {
			return '3';
		}
		public function up(): void {
			throw new RuntimeException( 'Induced dry-run failure.' );
		}
		public function verify(): void {
		}
	},
] );
$runner->run();
if ( '3' === SchemaVersion::get() ) {
	stage3_fail( 'Schema version marked 3 after induced failure.' );
}
if ( '2' !== SchemaVersion::get() ) {
	stage3_fail( 'Expected schema version to remain 2 after induced failure, got ' . SchemaVersion::get() );
}
stage3_ok( 'Schema version is not marked 3 prematurely on induced failure.' );

// Restore successful state for cleanliness.
SchemaVersion::set( '3' );

fwrite( STDOUT, "\nSTAGE3_SCHEMA_2_TO_3_DRY_RUN=PASS\n" );
fwrite( STDOUT, sprintf(
	"environment=docker-mysql://%s:%d/%s (disposable container cetech-de-stage3-mysql)\n",
	$host,
	$port,
	$name
) );
exit( 0 );
