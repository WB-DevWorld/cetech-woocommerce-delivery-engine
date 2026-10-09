<?php
/**
 * Separate P02 native storage checks after retained native/CPT/HTTP qualification.
 * Production autoload and WordPress functions are used; no tests/bootstrap.php.
 */
declare(strict_types=1);

if (
	'1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' )
	|| '127.0.0.1' !== getenv( 'CETECH_DE_WP_DB_HOST' )
	|| ! defined( 'ABSPATH' )
	|| ! defined( 'DB_HOST' ) || ! preg_match( '/^127\\.0\\.0\\.1(?::[0-9]+)?$/D', DB_HOST )
	|| ! defined( 'WP_ADMIN' ) || ! WP_ADMIN
	|| ! defined( 'DB_NAME' ) || ! preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME )
	|| '1' !== (string) get_option( 'cetech_opening_qualification_disposable' )
	|| ! class_exists( 'WC_Product' )
	|| ! class_exists( 'CetechDeliveryEngine\\Bootstrap\\Plugin' )
) {
	throw new RuntimeException( 'Refusing native qualification outside the marked disposable loopback fixture.' );
}

$report_path = isset( $args[0] ) ? (string) $args[0] : '';
if ( '' === $report_path || ! is_dir( dirname( $report_path ) ) ) {
	throw new RuntimeException( 'Native qualification needs an existing receipt directory.' );
}

global $wpdb;
$plugin_root = dirname( (string) ( new ReflectionClass( 'CetechDeliveryEngine\\Bootstrap\\Plugin' ) )->getFileName(), 3 );
$installed_sources = array();
foreach ( array( 'src', 'database' ) as $directory ) {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$relative = substr( $file->getPathname(), strlen( $plugin_root ) + 1 );
			$installed_sources[ $relative ] = hash_file( 'sha256', $file->getPathname() );
		}
	}
}
foreach ( array( 'cetech-woocommerce-delivery-engine.php', 'uninstall.php' ) as $file ) {
	$installed_sources[ $file ] = hash_file( 'sha256', $plugin_root . '/' . $file );
}
ksort( $installed_sources, SORT_STRING );
$report = array(
	'format'      => 'cetech-opening-promise-storage-v1',
	'source_head' => (string) getenv( 'CETECH_DE_QUALIFICATION_HEAD' ),
	'candidate_head' => (string) getenv( 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD' ),
	'source_tree' => (string) getenv( 'CETECH_DE_QUALIFICATION_TREE' ),
	'installed_php_sources' => $installed_sources,
	'installed_php_sources_hash' => hash( 'sha256', json_encode( $installed_sources, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ),
	'environment' => array(
		'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ),
		'woocommerce' => WC_VERSION, 'database_version' => (string) $wpdb->get_var( 'SELECT VERSION()' ),
		'hpos' => (string) get_option( 'woocommerce_custom_orders_table_enabled' ),
		'schema_before' => (string) get_option( 'cetech_de_db_version' ),
		'context' => 'WP-CLI native P02 storage; explicit unmounted internal authorizer and isolated owned SQL namespace',
		'background_requests' => 'WP Cron disabled; Action Scheduler async request runner suppressed in this process',
	),
	'limits' => array(
		'Native wpdb and owned SQL only; no HTTP/customer promise calculation or receipt mounting.',
		'Explicit internal fixture adopter; no settings/import/job/editor or checkout adoption.',
		'Publication refusals are simulated WordPress hooks; no crash/atomicity claim.',
		'Historical quote seed uses the existing synthetic profile; no native order or payment claim.',
	),
	'status' => 'RUNNING', 'cases' => array(),
);
$report['retained_receipts'] = [];
foreach ( [ 'native' => 495, 'cpt' => 20, 'http' => 143 ] as $kind => $count ) {
	$index = [ 'native' => 1, 'cpt' => 2, 'http' => 3 ][$kind]; $path = $args[$index] ?? null;
	if ( ! is_string( $path ) || ! is_file( $path ) || filesize( $path ) > 16777216 ) { throw new RuntimeException( 'P02 requires the complete preceding primary receipts.' ); }
	$prior = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $prior ) || 'PASS' !== ( $prior['status'] ?? null ) || $report['source_head'] !== ( $prior['source_head'] ?? null ) || $report['candidate_head'] !== ( $prior['candidate_head'] ?? null ) || $report['source_tree'] !== ( $prior['source_tree'] ?? null ) || $installed_sources !== ( $prior['installed_php_sources'] ?? null ) || ! is_array( $prior['cases'] ?? null ) || $count !== count( $prior['cases'] ) ) { throw new RuntimeException( 'P02 preceding primary identity or inventory differs.' ); }
	foreach ( $prior['cases'] as $case ) { if ( ! is_array( $case ) || 'PASS' !== ( $case['status'] ?? null ) ) { throw new RuntimeException( 'P02 requires every preceding primary case to pass.' ); } }
	$report['retained_receipts'][$kind] = [ 'sha256' => hash_file( 'sha256', $path ), 'cases' => $count ];
}
$write = static function () use ( &$report, $report_path ): void {
	$json = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	if ( false === file_put_contents( $report_path, $json . "\n" ) ) {
		throw new RuntimeException( 'Could not persist the qualification receipt.' );
	}
};
$check = static function ( string $id, bool $condition, array $evidence = array() ) use ( &$report, $write ): void {
	foreach ( $report['cases'] as $case ) {
		if ( $case['id'] === $id ) {
			throw new RuntimeException( 'Duplicate qualification case ID: ' . $id );
		}
	}
	$report['cases'][] = array( 'id' => $id, 'status' => $condition ? 'PASS' : 'FAIL', 'evidence' => $evidence );
	$write();
	echo 'opening_native_case=' . $id . ' result=' . ( $condition ? 'PASS' : 'FAIL' ) . PHP_EOL;
	if ( ! $condition ) {
		echo 'opening_native_failure_evidence=' . json_encode( end( $report['cases'] ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . PHP_EOL;
		throw new RuntimeException( 'Native qualification diverged: ' . $id );
	}
};

$write();
echo 'opening_installed_php_sources=' . $report['installed_php_sources_hash'] . ' files=' . count( $installed_sources ) . PHP_EOL;
echo 'opening_candidate_head=' . $report['candidate_head'] . ' checkout=' . $report['source_head'] . ' tree=' . $report['source_tree'] . PHP_EOL;
// Leave suppression in place through shutdown on this dedicated fixture process.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );
try {
	$run = require __DIR__ . '/opening-promise-storage.php';
	if ( ! is_callable( $run ) ) { throw new RuntimeException( 'P02 storage module is unavailable.' ); }
	$run( $check );
	$report['status'] = 'PASS'; $write();
	echo 'opening_promise_storage_qualification=PASS cases=' . count( $report['cases'] ) . PHP_EOL;
} catch ( Throwable $error ) {
	$report['status'] = 'FAIL';
	$report['error'] = array( 'class' => get_class( $error ) );
	$write();
	throw $error;
}
