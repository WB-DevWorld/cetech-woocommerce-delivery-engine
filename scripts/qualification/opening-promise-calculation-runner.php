<?php
/** Separate CLI-only P03 proof, after exact retained and P02 primary qualification. */
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'CETECH_DE_PROMISE_CALCULATION_QUALIFICATION' ) || defined( 'ABSPATH' ) || class_exists( 'PHPUnit\\Framework\\TestCase', false ) ) {
	throw new RuntimeException( 'P03 requires an explicit pure production CLI process.' );
}
if ( 7 !== $argc ) { throw new RuntimeException( 'P03 requires report, installed production root and four preceding receipts.' ); }
[ , $report_path, $plugin_root, $native_path, $cpt_path, $http_path, $p02_path ] = $argv;
$plugin_root = realpath( $plugin_root );
if ( false === $plugin_root || ! is_file( $plugin_root . '/vendor/autoload.php' ) || ! is_dir( dirname( $report_path ) ) ) { throw new RuntimeException( 'P03 installed production autoload or receipt directory is unavailable.' ); }
$psr4 = require $plugin_root . '/vendor/composer/autoload_psr4.php';
foreach ( $psr4 as $namespace => $paths ) {
	if ( str_starts_with( $namespace, 'PHPUnit\\' ) || str_starts_with( $namespace, 'CetechDeliveryEngine\\Tests\\' ) ) { throw new RuntimeException( 'P03 must use a production-only autoloader.' ); }
}
require $plugin_root . '/vendor/autoload.php';
$calculator_class = CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator::class;
if ( ! class_exists( $calculator_class ) || realpath( (string) ( new ReflectionClass( $calculator_class ) )->getFileName() ) !== $plugin_root . '/src/Application/ServicePromise/Calculation/DeterministicPromiseCalculator.php' ) { throw new RuntimeException( 'P03 calculator does not originate in the installed production package.' ); }

$installed_sources = [];
foreach ( [ 'src', 'database' ] as $directory ) {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) { $installed_sources[substr( $file->getPathname(), strlen( $plugin_root ) + 1 )] = hash_file( 'sha256', $file->getPathname() ); }
	}
}
foreach ( [ 'cetech-woocommerce-delivery-engine.php', 'uninstall.php' ] as $file ) { $installed_sources[$file] = hash_file( 'sha256', $plugin_root . '/' . $file ); }
ksort( $installed_sources, SORT_STRING );
$source = (string) getenv( 'CETECH_DE_QUALIFICATION_HEAD' ); $candidate = (string) getenv( 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD' ); $tree = (string) getenv( 'CETECH_DE_QUALIFICATION_TREE' );
foreach ( [ $source, $candidate, $tree ] as $identity ) { if ( 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $identity ) ) { throw new RuntimeException( 'P03 immutable qualification identity is unavailable.' ); } }
$preceding = [];
foreach ( [ 'native' => [ $native_path, 495 ], 'cpt' => [ $cpt_path, 20 ], 'http' => [ $http_path, 143 ], 'p02' => [ $p02_path, 19 ] ] as $kind => [ $path, $count ] ) {
	if ( ! is_file( $path ) || filesize( $path ) > 16777216 ) { throw new RuntimeException( 'P03 requires complete preceding primary receipts.' ); }
	$prior = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $prior ) || 'PASS' !== ( $prior['status'] ?? null ) || $source !== ( $prior['source_head'] ?? null ) || $candidate !== ( $prior['candidate_head'] ?? null ) || $tree !== ( $prior['source_tree'] ?? null ) || $installed_sources !== ( $prior['installed_php_sources'] ?? null ) || ! is_array( $prior['cases'] ?? null ) || $count !== count( $prior['cases'] ) ) { throw new RuntimeException( 'P03 preceding primary identity or inventory differs.' ); }
	foreach ( $prior['cases'] as $case ) { if ( ! is_array( $case ) || 'PASS' !== ( $case['status'] ?? null ) ) { throw new RuntimeException( 'P03 cannot compensate for a failed preceding primary case.' ); } }
	$preceding[$kind] = [ 'sha256' => hash_file( 'sha256', $path ), 'cases' => $count ];
}

$report = [
	'format' => 'cetech-opening-promise-calculation-v1', 'source_head' => $source, 'candidate_head' => $candidate, 'source_tree' => $tree,
	'installed_php_sources' => $installed_sources, 'installed_php_sources_hash' => hash( 'sha256', json_encode( $installed_sources, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ),
	'environment' => [ 'php' => PHP_VERSION, 'timezone_data_version' => timezone_version_get(), 'context' => 'Pure CLI P03 calculation with installed production-only autoload; no WordPress bootstrap', 'runtime_origin' => 'Independent explicit qualification fixture context; consistency only, no native collector or timezone-file attestation' ],
	'limits' => [ 'Pure manual captured-input vectors; no native clock/event/admission or shopper-flow claim.', 'No WordPress, SQL owner, network, capacity observer, reservation or payment is invoked.', 'Retained 495/20/143 and P02 19 primary receipts must pass independently on the same installed source.' ],
	'preceding_receipts' => $preceding, 'status' => 'RUNNING', 'cases' => [],
];
$write = static function () use ( &$report, $report_path ): void {
	$bytes = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	if ( false === file_put_contents( $report_path, $bytes ) ) { throw new RuntimeException( 'P03 could not persist its closed receipt.' ); }
};
$proof = null;
$fingerprints = static function ( array $facts ) use ( &$proof ): void {
	if ( [ 'kind', 'input_digest', 'result_digest', 'calendar_digests', 'steps' ] !== array_keys( $facts ) || ! in_array( $facts['kind'], [ 'calculation', 'calendar_math', 'cart' ], true ) || ! is_string( $facts['input_digest'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $facts['input_digest'] ) || ! is_string( $facts['result_digest'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $facts['result_digest'] ) || ! is_array( $facts['calendar_digests'] ) || count( $facts['calendar_digests'] ) > 16 || ! is_int( $facts['steps'] ) || $facts['steps'] < 0 || $facts['steps'] > 100000 ) { throw new RuntimeException( 'P03 fingerprint projection is invalid.' ); }
	foreach ( $facts['calendar_digests'] as $digest ) { if ( ! is_string( $digest ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/D', $digest ) ) { throw new RuntimeException( 'P03 calendar fingerprint is invalid.' ); } }
	$proof = $facts;
};
$check = static function ( string $id, bool $condition, array $evidence, string $kind ) use ( &$report, &$proof, $write ): void {
	if ( null === $proof || $proof['kind'] !== $kind || [] === $evidence || 1 !== preg_match( '/\APURE-W2P03-[A-Z0-9-]+\z/D', $id ) ) { throw new RuntimeException( 'P03 case lacks its exact source-defined calculation witness.' ); }
	foreach ( $report['cases'] as $case ) { if ( $id === $case['id'] ) { throw new RuntimeException( 'P03 source case is duplicated.' ); } }
	foreach ( $evidence as $name => $value ) { if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-z0-9_]+\z/D', $name ) || ! is_bool( $value ) ) { throw new RuntimeException( 'P03 observations must be exact booleans.' ); } }
	$passed = $condition && ! in_array( false, $evidence, true );
	$report['cases'][] = [ 'id' => $id, 'status' => $passed ? 'PASS' : 'FAIL', 'evidence' => $evidence, 'fingerprints' => $proof ]; $proof = null; $write();
	echo 'opening_pure_case=' . $id . ' result=' . ( $passed ? 'PASS' : 'FAIL' ) . PHP_EOL;
	if ( ! $passed ) { throw new RuntimeException( 'P03 pure calculation case diverged.' ); }
};
$write();
require __DIR__ . '/promise-calculation-vectors.php';
try {
	$run = require __DIR__ . '/promise-calculation-cases.php';
	if ( ! is_callable( $run ) ) { throw new RuntimeException( 'P03 vector source is unavailable.' ); }
	$run( $check, $fingerprints ); $report['status'] = 'PASS'; $write();
	echo 'opening_promise_calculation_qualification=PASS pure=' . count( $report['cases'] ) . ' php=' . PHP_VERSION . ' timezone_data_version=' . timezone_version_get() . PHP_EOL;
} catch ( Throwable $error ) {
	$report['status'] = 'FAIL'; $report['error'] = [ 'class' => get_class( $error ) ]; $write();
	throw new RuntimeException( 'P03 pure qualification failed; see closed receipt.' );
}
