<?php
/** P06 pure budget receipt on the extracted production package, after P05 primary proofs. */
declare(strict_types=1);

if ( PHP_SAPI !== 'cli' || '1' !== getenv( 'CETECH_DE_PROMISE_BOUNDS_QUALIFICATION' ) || defined( 'ABSPATH' ) || class_exists( 'PHPUnit\\Framework\\TestCase', false ) || 6 !== $argc ) { throw new RuntimeException( 'P06 bounds require explicit pure CLI, report, package and three P05 receipts.' ); }
[ , $report_path, $root, $p05_hpos, $p05_cpt, $p05_http ] = $argv; $root = realpath( $root );
if ( false === $root || ! is_file( $root . '/vendor/autoload.php' ) || ! is_dir( dirname( $report_path ) ) || file_exists( $report_path ) || is_link( $report_path ) ) { throw new RuntimeException( 'P06 bounds package or fresh receipt allocation unavailable.' ); }
$psr4 = require $root . '/vendor/composer/autoload_psr4.php'; foreach ( $psr4 as $namespace => $paths ) { if ( str_starts_with( $namespace, 'PHPUnit\\' ) || str_starts_with( $namespace, 'CetechDeliveryEngine\\Tests\\' ) ) { throw new RuntimeException( 'P06 bounds require a production-only autoloader.' ); } }
require $root . '/vendor/autoload.php'; $class = CetechDeliveryEngine\Application\ServicePromise\Calculation\DeterministicPromiseCalculator::class;
if ( realpath( (string) ( new ReflectionClass( $class ) )->getFileName() ) !== $root . '/src/Application/ServicePromise/Calculation/DeterministicPromiseCalculator.php' ) { throw new RuntimeException( 'P06 calculator is outside the extracted package.' ); }
$map = []; foreach ( [ 'src', 'database' ] as $directory ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() && 'php' === $file->getExtension() ) { $map[substr( $file->getPathname(), strlen( $root ) + 1 )] = hash_file( 'sha256', $file->getPathname() ); } } }
foreach ( [ 'cetech-woocommerce-delivery-engine.php', 'uninstall.php' ] as $name ) { $map[$name] = hash_file( 'sha256', $root . '/' . $name ); } ksort( $map, SORT_STRING );
$source = (string) getenv( 'CETECH_DE_QUALIFICATION_HEAD' ); $candidate = (string) getenv( 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD' ); $tree = (string) getenv( 'CETECH_DE_QUALIFICATION_TREE' );
foreach ( [ $source, $candidate, $tree ] as $identity ) { if ( 1 !== preg_match( '/\A[0-9a-f]{40}\z/D', $identity ) ) { throw new RuntimeException( 'P06 immutable execution identity unavailable.' ); } }
$preceding = []; foreach ( [ 'p05_hpos' => [ $p05_hpos, 27 ], 'p05_cpt' => [ $p05_cpt, 27 ], 'p05_http' => [ $p05_http, 19 ] ] as $kind => [ $path, $count ] ) {
	if ( ! is_file( $path ) || filesize( $path ) > 16777216 ) { throw new RuntimeException( 'P06 requires complete P05 primary receipts.' ); }
	$prior = json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $prior ) || 'PASS' !== ( $prior['status'] ?? null ) || $source !== ( $prior['source_head'] ?? null ) || $candidate !== ( $prior['candidate_head'] ?? null ) || $tree !== ( $prior['source_tree'] ?? null ) || $map !== ( $prior['installed_php_sources'] ?? null ) || ! is_array( $prior['cases'] ?? null ) || $count !== count( $prior['cases'] ) ) { throw new RuntimeException( 'P06 preceding identity, exact source or inventory differs.' ); }
	foreach ( $prior['cases'] as $case ) { if ( ! is_array( $case ) || 'PASS' !== ( $case['status'] ?? null ) ) { throw new RuntimeException( 'P06 cannot compensate for failed P05 evidence.' ); } }
	$preceding[$kind] = [ 'sha256' => hash_file( 'sha256', $path ), 'cases' => $count ];
}
$report = [ 'format' => 'cetech-opening-promise-qualification-bounds-v1', 'source_head' => $source, 'candidate_head' => $candidate, 'source_tree' => $tree, 'installed_php_sources' => $map, 'installed_php_sources_hash' => hash( 'sha256', json_encode( $map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ), 'environment' => [ 'php' => PHP_VERSION, 'timezone_data_version' => timezone_version_get(), 'context' => 'Pure CLI P06 measured boundary vectors using extracted production-only autoload; no WordPress, SQL or native cart claim' ], 'limits' => [ 'Pure manual captured values and codec work observations only.', 'Actual native cart and owned SQL query/callback observations have distinct evidence.', 'The 200-group input bound does not promise a winning oversized serialized packet.', 'No network, capacity reservation, mounted capacity adapter or live operational acceptance.' ], 'preceding_receipts' => $preceding, 'status' => 'RUNNING', 'cases' => [] ];
$write = static function () use ( &$report, $report_path ): void { if ( false === file_put_contents( $report_path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" ) ) { throw new RuntimeException( 'P06 cannot persist bounds receipt.' ); } };
$check = static function ( string $id, bool $condition, array $evidence, array $observations ) use ( &$report, $write ): void {
	if ( 1 !== preg_match( '/\APURE-W2P06-[A-Z0-9-]+\z/D', $id ) || [] === $evidence || [] === $observations ) { throw new RuntimeException( 'P06 bounds case has no exact observations.' ); }
	foreach ( $report['cases'] as $case ) { if ( $id === $case['id'] ) { throw new RuntimeException( 'P06 bounds source case duplicated.' ); } }
	foreach ( $evidence as $name => $value ) { if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-z0-9_]+\z/D', $name ) || ! is_bool( $value ) ) { throw new RuntimeException( 'P06 bounds evidence must be closed booleans.' ); } }
	foreach ( $observations as $name => $value ) { if ( ! is_string( $name ) || 1 !== preg_match( '/\A[a-z0-9_]+\z/D', $name ) || ! is_int( $value ) || $value < 0 || $value > 2000000 ) { throw new RuntimeException( 'P06 work observations must be bounded nonnegative integers.' ); } }
	$passed = $condition && ! in_array( false, $evidence, true ); $report['cases'][] = [ 'id' => $id, 'status' => $passed ? 'PASS' : 'FAIL', 'evidence' => $evidence, 'observations' => $observations ]; $write(); echo 'opening_pure_case=' . $id . ' result=' . ( $passed ? 'PASS' : 'FAIL' ) . PHP_EOL;
	if ( ! $passed ) { throw new RuntimeException( 'P06 bounds case failed; see receipt.' ); }
};
$write(); require __DIR__ . '/promise-calculation-vectors.php';
try { $module = require __DIR__ . '/promise-qualification-bounds-cases.php'; $module( $check ); $report['status'] = 'PASS'; $write(); echo 'opening_promise_qualification_bounds=PASS pure=' . count( $report['cases'] ) . PHP_EOL; }
catch ( Throwable $error ) { $report['status'] = 'FAIL'; $report['error'] = [ 'class' => get_class( $error ) ]; $write(); throw $error; }
