<?php
/**
 * Targeted native checks, loaded by WP-CLI on the third disposable CI site.
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
	'format'      => 'cetech-opening-native-qualification-v1',
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
		'context' => 'WP-CLI with native wp-admin flag and explicitly selected fixture principals',
		'background_requests' => 'WP Cron disabled; Action Scheduler async request runner suppressed in this process',
	),
	'limits' => array(
		'No HTTP/browser authentication or session transport proof.',
		'No live site, order, payment, theme/cache or release qualification.',
		'Migration option-write denials are simulated hooks; no physical storage crash/atomicity proof.',
		'Canonical scenarios and policy-dependent portions retain their separate gates.',
	),
	'status' => 'RUNNING', 'cases' => array(),
);
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
	$lifecycle = require __DIR__ . '/opening-data-lifecycle.php';
	$emergency = require __DIR__ . '/opening-emergency-control.php';
	foreach ( array( 'authority', 'public-import', 'configuration', 'migrations', 'operation', 'operation-migration', 'operation-lifecycle', 'rule-lifecycle', 'rule-lifecycle-migration', 'rule-lifecycle-preservation', 'snapshot-readers', 'quote-storage' ) as $module ) {
		$run = require __DIR__ . '/opening-' . $module . '.php';
		if ( ! is_callable( $run ) ) {
			throw new RuntimeException( 'Invalid qualification module: ' . $module );
		}
		if ( 'snapshot-readers' === $module ) {
			$run( $check, static function ( callable $physical, callable $historical ) use ( $lifecycle, $emergency, $check ): void {
				$lifecycle( $check, [ 'physical' => $physical, 'historical' => $historical ] );
				$emergency( $check, [ 'physical' => $physical, 'historical' => $historical ] );
			} );
		} else { $run( $check ); }
	}
	$lifecycle( $check );
	$emergency( $check );
	$check( 'NATIVE-FIXTURE-SCHEMA-RESTORED', '9' === (string) get_option( 'cetech_de_db_version' ), array( 'schema_after' => (string) get_option( 'cetech_de_db_version' ) ) );
	$report['status'] = 'PASS';
	$write();
	echo 'opening_native_qualification=PASS cases=' . count( $report['cases'] ) . PHP_EOL;
} catch ( Throwable $error ) {
	$report['status'] = 'FAIL';
	$report['error'] = array( 'class' => get_class( $error ), 'message' => $error->getMessage() );
	$write();
	throw $error;
}
