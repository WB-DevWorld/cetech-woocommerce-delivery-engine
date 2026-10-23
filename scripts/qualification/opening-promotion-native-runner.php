<?php
/** Standalone N17 native handoff proof; retained qualification inventories stay independent. */
declare(strict_types=1);

if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || '127.0.0.1' !== getenv( 'CETECH_DE_WP_DB_HOST' ) || ! defined( 'ABSPATH' ) || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! class_exists( 'WC_Order' ) || ! class_exists( CetechDeliveryEngine\Bootstrap\Plugin::class ) || is_multisite() || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || is_file( WP_CONTENT_DIR . '/object-cache.php' ) || is_file( WP_CONTENT_DIR . '/db.php' ) ) { throw new RuntimeException( 'N17 requires the marked disposable native default-cache loopback fixture.' ); }
foreach ( get_included_files() as $file ) { if ( str_ends_with( str_replace( '\\', '/', $file ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'Unit bootstrap is forbidden in N17 native proof.' ); } }
if ( 2 !== count( $args ) ) { throw new RuntimeException( 'N17 requires its receipt path and native storage mode.' ); }
[ $report_path, $mode ] = $args;
if ( ! is_string( $report_path ) || ! is_dir( dirname( $report_path ) ) || file_exists( $report_path ) || is_link( $report_path ) || ! in_array( $mode, [ 'hpos_on', 'hpos_off' ], true ) || ( 'hpos_on' === $mode ) !== Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) { throw new RuntimeException( 'N17 receipt allocation or native storage authority differs.' ); }
global $wpdb;
$root = dirname( (string) ( new ReflectionClass( CetechDeliveryEngine\Bootstrap\Plugin::class ) )->getFileName(), 3 ); $map = [];
foreach ( [ 'src', 'database' ] as $directory ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() && 'php' === $file->getExtension() ) { $map[substr( $file->getPathname(), strlen( $root ) + 1 )] = hash_file( 'sha256', $file->getPathname() ); } } }
foreach ( [ 'cetech-woocommerce-delivery-engine.php', 'uninstall.php' ] as $name ) { $map[$name] = hash_file( 'sha256', $root . '/' . $name ); } ksort( $map, SORT_STRING );
$source = (string) getenv( 'CETECH_DE_QUALIFICATION_HEAD' ); $candidate = (string) getenv( 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD' ); $tree = (string) getenv( 'CETECH_DE_QUALIFICATION_TREE' );
foreach ( [ $source, $candidate, $tree ] as $identity ) { if ( 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $identity ) ) { throw new RuntimeException( 'N17 immutable execution identity is unavailable.' ); } }
$report = [
	'format' => 'cetech-opening-promotion-native-v1', 'source_head' => $source, 'candidate_head' => $candidate, 'source_tree' => $tree,
	'installed_php_sources' => $map, 'installed_php_sources_hash' => hash( 'sha256', json_encode( $map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ),
	'environment' => [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'database_version' => (string) $wpdb->get_var( 'SELECT VERSION()' ), 'hpos' => (string) get_option( 'woocommerce_custom_orders_table_enabled' ), 'schema_before' => (string) get_option( 'cetech_de_db_version' ), 'context' => 'Fresh WP-CLI N17 process; installed package autoload, actual Woo CRUD, owned SQL physical guards and saved format3 history', 'background_requests' => 'WP Cron disabled; Action Scheduler async request runner suppressed in this process' ],
	'limits' => [ 'Trusted synthetic economics, source inputs and typed prepared1 acknowledgment are explicit test inputs; they are not merchant funding, C03 history authority, checkout or payment proof.', 'Actual native HPOS/CPT order capture, saved packet bytes and second-connection mutation refusals are qualified only in this marked disposable fixture.', 'Promise adoption remains OFF; no default promotion provider, gateway, payment or queue runner is mounted.', 'Retained 495 native, 20 CPT and 143 HTTP inventories remain separate and unchanged.' ],
	'status' => 'RUNNING', 'cases' => [],
];
$write = static function () use ( &$report, $report_path ): void { if ( false === file_put_contents( $report_path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" ) ) { throw new RuntimeException( 'N17 could not persist its closed native receipt.' ); } };
$check = static function ( string $id, bool $condition, array $evidence ) use ( &$report, $write ): void {
	if ( 1 !== preg_match( '/\ANATIVE-N17-[A-Z0-9-]+\z/D', $id ) || [] === $evidence ) { throw new RuntimeException( 'N17 case has no exact source observations.' ); }
	foreach ( $report['cases'] as $case ) { if ( $id === $case['id'] ) { throw new RuntimeException( 'N17 source case is duplicated.' ); } }
	foreach ( $evidence as $name => $value ) { if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-z0-9_]+\z/D', $name ) || ! is_bool( $value ) ) { throw new RuntimeException( 'N17 observations must be exact booleans.' ); } }
	$passed = $condition && ! in_array( false, $evidence, true ); $report['cases'][] = [ 'id' => $id, 'status' => $passed ? 'PASS' : 'FAIL', 'evidence' => $evidence ]; $write();
	fwrite( STDOUT, 'opening_promotion_native_case=' . $id . ' result=' . ( $passed ? 'PASS' : 'FAIL' ) . PHP_EOL );
	if ( ! $passed ) { throw new RuntimeException( 'N17 native case diverged; see the closed receipt.' ); }
};
$write(); add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
try {
	$module = require __DIR__ . '/opening-promotion-native.php'; if ( ! is_callable( $module ) ) { throw new RuntimeException( 'N17 source module is unavailable.' ); }
	$module( $check, $mode, count( $map ) ); if ( 12 !== count( $report['cases'] ) ) { throw new RuntimeException( 'N17 requires exactly twelve native cases per storage mode.' ); }
	$report['status'] = 'PASS'; $write(); echo 'opening_promotion_native_qualification=PASS mode=' . $mode . ' cases=' . count( $report['cases'] ) . PHP_EOL;
} catch ( Throwable $error ) { $report['status'] = 'FAIL'; $report['error'] = [ 'class' => get_class( $error ) ]; $write(); throw $error; }
