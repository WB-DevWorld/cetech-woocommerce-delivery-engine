<?php
/** Separate native P05 proof after the complete retained, P02 and P03 primary receipts. */
declare(strict_types=1);

if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || '127.0.0.1' !== getenv( 'CETECH_DE_WP_DB_HOST' ) || ! defined( 'ABSPATH' ) || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! class_exists( 'WC_Order' ) || ! class_exists( CetechDeliveryEngine\Bootstrap\Plugin::class ) || is_multisite() || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || is_file( WP_CONTENT_DIR . '/object-cache.php' ) || is_file( WP_CONTENT_DIR . '/db.php' ) ) { throw new RuntimeException( 'P05 requires the marked disposable native default-cache loopback fixture.' ); }
foreach ( get_included_files() as $file ) { if ( str_ends_with( str_replace( '\\', '/', $file ), '/tests/bootstrap.php' ) ) { throw new RuntimeException( 'Unit bootstrap is forbidden in P05 native proof.' ); } }
if ( 10 !== count( $args ) ) { throw new RuntimeException( 'P05 requires report, native storage mode and eight preceding primary receipts.' ); }
[ $report_path, $mode, $native_path, $cpt_path, $http_path, $p02_path, $p03_path, $p04_hpos_path, $p04_cpt_path, $p04_http_path ] = $args;
if ( ! is_string( $report_path ) || ! is_dir( dirname( $report_path ) ) || file_exists( $report_path ) || is_link( $report_path ) || ! in_array( $mode, [ 'hpos_on', 'hpos_off' ], true ) || ( 'hpos_on' === $mode ) !== Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) { throw new RuntimeException( 'P05 receipt allocation or native storage authority differs.' ); }
global $wpdb;
$root = dirname( (string) ( new ReflectionClass( CetechDeliveryEngine\Bootstrap\Plugin::class ) )->getFileName(), 3 );
$map = [];
foreach ( [ 'src', 'database' ] as $directory ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() && 'php' === $file->getExtension() ) { $map[substr( $file->getPathname(), strlen( $root ) + 1 )] = hash_file( 'sha256', $file->getPathname() ); } } }
foreach ( [ 'cetech-woocommerce-delivery-engine.php', 'uninstall.php' ] as $name ) { $map[$name] = hash_file( 'sha256', $root . '/' . $name ); } ksort( $map, SORT_STRING );
$source = (string) getenv( 'CETECH_DE_QUALIFICATION_HEAD' ); $candidate = (string) getenv( 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD' ); $tree = (string) getenv( 'CETECH_DE_QUALIFICATION_TREE' );
foreach ( [ $source, $candidate, $tree ] as $identity ) { if ( 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $identity ) ) { throw new RuntimeException( 'P05 immutable execution identity is unavailable.' ); } }
$preceding = [];
foreach ( [ 'native' => [ $native_path, 495 ], 'cpt' => [ $cpt_path, 20 ], 'http' => [ $http_path, 143 ], 'p02' => [ $p02_path, 19 ], 'p03' => [ $p03_path, 49 ], 'p04_hpos' => [ $p04_hpos_path, 33 ], 'p04_cpt' => [ $p04_cpt_path, 33 ], 'p04_http' => [ $p04_http_path, 14 ] ] as $kind => [ $path, $count ] ) {
	if ( ! is_string( $path ) || ! is_file( $path ) || filesize( $path ) > 16777216 ) { throw new RuntimeException( 'P05 requires the complete preceding primary receipts.' ); }
	$prior = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $prior ) || 'PASS' !== ( $prior['status'] ?? null ) || $source !== ( $prior['source_head'] ?? null ) || $candidate !== ( $prior['candidate_head'] ?? null ) || $tree !== ( $prior['source_tree'] ?? null ) || $map !== ( $prior['installed_php_sources'] ?? null ) || ! is_array( $prior['cases'] ?? null ) || $count !== count( $prior['cases'] ) ) { throw new RuntimeException( 'P05 preceding primary source identity or inventory differs.' ); }
	foreach ( $prior['cases'] as $case ) { if ( ! is_array( $case ) || 'PASS' !== ( $case['status'] ?? null ) ) { throw new RuntimeException( 'P05 cannot compensate for a failed preceding primary case.' ); } }
	$preceding[$kind] = [ 'sha256' => hash_file( 'sha256', $path ), 'cases' => $count ];
}
$report = [
	'format' => 'cetech-opening-promise-native-configuration-v1', 'source_head' => $source, 'candidate_head' => $candidate, 'source_tree' => $tree,
	'installed_php_sources' => $map, 'installed_php_sources_hash' => hash( 'sha256', json_encode( $map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ),
	'environment' => [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'database_version' => (string) $wpdb->get_var( 'SELECT VERSION()' ), 'hpos' => (string) get_option( 'woocommerce_custom_orders_table_enabled' ), 'schema_before' => (string) get_option( 'cetech_de_db_version' ), 'context' => 'Fresh WP-CLI native P05 configuration; actual protected native administration and immutable original/current shipment facts', 'background_requests' => 'WP Cron disabled; Action Scheduler async request runner suppressed in this process' ],
	'limits' => [ 'Marked disposable native WordPress; default promise adoption remains OFF.', 'Authentic native authorization, immutable policy lifecycle and saved order/shipment proofs; customer request/browser parity has a separate receipt.', 'No live payment, external gateway, target theme, persistent cache or operational certification claim.', 'All eight preceding P01-P04 primary receipts pass independently on the same installed production source.' ],
	'preceding_receipts' => $preceding, 'status' => 'RUNNING', 'cases' => [],
];
$write = static function () use ( &$report, $report_path ): void { if ( false === file_put_contents( $report_path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" ) ) { throw new RuntimeException( 'P05 could not persist its closed native receipt.' ); } };
$check = static function ( string $id, bool $condition, array $evidence ) use ( &$report, $write ): void {
	if ( 1 !== preg_match( '/\ANATIVE-W2P05-[A-Z0-9-]+\z/D', $id ) || [] === $evidence ) { throw new RuntimeException( 'P05 case has no exact source-derived observations.' ); }
	foreach ( $report['cases'] as $case ) { if ( $id === $case['id'] ) { throw new RuntimeException( 'P05 source case is duplicated.' ); } }
	foreach ( $evidence as $name => $value ) { if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-z0-9_]+\z/D', $name ) || ! is_bool( $value ) ) { throw new RuntimeException( 'P05 observations must be exact booleans.' ); } }
	$passed = $condition && ! in_array( false, $evidence, true ); $report['cases'][] = [ 'id' => $id, 'status' => $passed ? 'PASS' : 'FAIL', 'evidence' => $evidence ]; $write();
	fwrite( STDOUT, 'opening_native_promise_native-configuration_case=' . $id . ' result=' . ( $passed ? 'PASS' : 'FAIL' ) . PHP_EOL );
	if ( ! $passed ) { throw new RuntimeException( 'P05 native case diverged; see the closed receipt.' ); }
};
$write(); add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
try {
	$module = require __DIR__ . '/opening-promise-native-configuration.php'; if ( ! is_callable( $module ) ) { throw new RuntimeException( 'P05 source case module is unavailable.' ); }
	$module( $check, $mode ); $report['status'] = 'PASS'; $write(); echo 'opening_promise_native-configuration_qualification=PASS mode=' . $mode . ' cases=' . count( $report['cases'] ) . PHP_EOL;
} catch ( Throwable $error ) { $report['status'] = 'FAIL'; $report['error'] = [ 'class' => get_class( $error ) ]; $write(); throw $error; }
