<?php

declare(strict_types=1);

/** Fresh native CPT authority with an independent complete receipt and installed-source identity. */
if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || true !== WP_ADMIN || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() || ! preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) ) { throw new RuntimeException( 'Q06 HPOS-off qualification requires the fresh marked CPT process.' ); }
$path = $args[0] ?? null; if ( ! is_string( $path ) || ! is_dir( dirname( $path ) ) || is_link( $path ) ) { throw new RuntimeException( 'Q06 HPOS-off receipt path unavailable.' ); }
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
global $wpdb;
$root = dirname( (string) ( new ReflectionClass( CetechDeliveryEngine\Bootstrap\Plugin::class ) )->getFileName(), 3 ); $map = [];
foreach ( [ 'src', 'database' ] as $directory ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() && 'php' === $file->getExtension() ) { $map[substr( $file->getPathname(), strlen( $root ) + 1 )] = hash_file( 'sha256', $file->getPathname() ); } } }
foreach ( [ 'cetech-woocommerce-delivery-engine.php', 'uninstall.php' ] as $name ) { $map[$name] = hash_file( 'sha256', $root . '/' . $name ); } ksort( $map, SORT_STRING );
$report = [ 'format' => 'cetech-opening-native-qualification-v1', 'source_head' => (string) getenv( 'CETECH_DE_QUALIFICATION_HEAD' ), 'candidate_head' => (string) getenv( 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD' ), 'source_tree' => (string) getenv( 'CETECH_DE_QUALIFICATION_TREE' ), 'installed_php_sources' => $map, 'installed_php_sources_hash' => hash( 'sha256', json_encode( $map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ), 'environment' => [ 'php' => PHP_VERSION, 'wordpress' => get_bloginfo( 'version' ), 'woocommerce' => WC_VERSION, 'database_version' => (string) $wpdb->get_var( 'SELECT VERSION()' ), 'hpos' => (string) get_option( 'woocommerce_custom_orders_table_enabled' ), 'context' => 'fresh WP-CLI CPT authority; actual Woo CRUD/native C07/production quote placement' ], 'limits' => [ 'HTTP/gateway/browser routes have their separate qualification.', 'This receipt covers the declared marked native CPT disposable process.' ], 'status' => 'RUNNING', 'cases' => [] ];
$write = static function () use ( &$report, $path ): void { if ( false === file_put_contents( $path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" ) ) { throw new RuntimeException( 'Q06 HPOS-off receipt persistence failed.' ); } };
$check = static function ( string $id, bool $condition, array $evidence = [] ) use ( &$report, $write ): void {
	foreach ( $report['cases'] as $prior ) { if ( $prior['id'] === $id ) { throw new RuntimeException( 'Duplicate Q06 HPOS-off case.' ); } }
	$report['cases'][] = [ 'id' => $id, 'status' => $condition ? 'PASS' : 'FAIL', 'evidence' => $evidence ]; $write();
	if ( ! $condition ) { throw new RuntimeException( 'Required native Q06 HPOS-off case failed.' ); }
};
$write();
try { $module = require __DIR__ . '/opening-quote-placement.php'; $module( $check, 'hpos_off' ); $report['status'] = 'PASS'; $write(); }
catch ( Throwable $error ) { $report['status'] = 'FAIL'; $write(); throw $error; }
