<?php

declare(strict_types=1);

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteReadiness;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteRepository;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory;

/** Fresh WordPress process and native connection. No plugins or unit bootstrap. */
$path = $argv[1] ?? '';
if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) ) { exit( 2 ); }
$session = null;
$phase = 'configuration';
$checks = [];
$progress = [ 'process_id' => getmypid(), 'wp_load' => false, 'default_object_cache' => false, 'installed_candidate_autoload' => false ];
try {
	$config = json_decode( (string) file_get_contents( $path ), true, 4, JSON_THROW_ON_ERROR );
	$keys = is_array( $config ) ? array_keys( $config ) : []; sort( $keys );
	$expected = [ 'body_hash', 'header_hash', 'plugin_root', 'prefix', 'quote_uuid', 'row_hash', 'site', 'wp_load' ]; sort( $expected );
	if ( $keys !== $expected || ! is_string( $config['wp_load'] ) || ! str_ends_with( $config['wp_load'], '/wp-load.php' ) || ! is_file( $config['wp_load'] ) || ! is_string( $config['plugin_root'] ) || ! is_string( $config['prefix'] ) || 1 !== preg_match( '/\Agc6_[a-f0-9]{12}_\z/D', $config['prefix'] ) || ! is_int( $config['site'] ) || 99175 !== $config['site'] || ! is_string( $config['quote_uuid'] ) ) { throw new RuntimeException(); }
	foreach ( [ 'row_hash', 'header_hash', 'body_hash' ] as $name ) { if ( ! is_string( $config[$name] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $config[$name] ) ) { throw new RuntimeException(); } }
	define( 'SHORTINIT', true ); define( 'WP_ADMIN', true );
	$phase = 'wp_load';
	require $config['wp_load'];
	$progress['wp_load'] = true;
	$phase = 'fixture_authority';
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $GLOBALS['wpdb'] instanceof wpdb || wpdb::class !== get_class( $GLOBALS['wpdb'] ) || ! isset( $GLOBALS['wp_object_cache'] ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) ) { throw new RuntimeException(); }
	$progress['default_object_cache'] = true;
	$phase = 'installed_autoload';
	// The CI checkout has no Composer tree. Load the exact installed
	// production candidate whose source map the parent native runner records.
	$installed = realpath( WP_CONTENT_DIR . '/plugins/cetech-woocommerce-delivery-engine' );
	if ( false === $installed || $installed !== realpath( $config['plugin_root'] ) ) { throw new RuntimeException(); }
	$vendor = $installed . '/vendor/autoload.php';
	if ( ! is_file( $vendor ) || ! is_file( $installed . '/cetech-woocommerce-delivery-engine.php' ) ) { throw new RuntimeException(); }
	require $vendor;
	$progress['installed_candidate_autoload'] = true;
	foreach ( get_included_files() as $included ) { if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) { throw new RuntimeException(); } }
	$GLOBALS['wpdb']->set_prefix( $config['prefix'] ); $GLOBALS['blog_id'] = $config['site']; $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	$phase = 'native_connection';
	$session = ( new OperationConnectionFactory() )->open();
	if ( $session->site_id() !== $config['site'] || $session->table_prefix() !== $config['prefix'] ) { throw new RuntimeException(); }
	$phase = 'readiness';
	$readiness = new DeliveryQuoteReadiness( $session ); $readiness->assert_ready();
	$phase = 'read_owner';
	if ( ! $session->begin() ) { throw new RuntimeException(); }
	$phase = 'quote_read';
	$row = ( new DeliveryQuoteRepository( $session, $readiness ) )->find_quote( QuoteId::from_string( $config['quote_uuid'] ) );
	$facts = $row?->row();
	$phase = 'immutable_comparison';
	$checks = [
		'exact_row' => is_array( $facts ) && hash_equals( $config['row_hash'], hash( 'sha256', json_encode( $facts, JSON_THROW_ON_ERROR ) ) ),
		'immutable_header' => is_array( $facts ) && hash_equals( $config['header_hash'], hash( 'sha256', $facts['header_json'] ) ),
		'immutable_body' => is_array( $facts ) && is_string( $facts['private_body_json'] ) && hash_equals( $config['body_hash'], hash( 'sha256', $facts['private_body_json'] ) ),
	];
	$exact = $checks['exact_row'] && $checks['immutable_header'] && $checks['immutable_body'];
	$schema = (string) get_option( 'cetech_de_db_version' );
	$checks['schema9'] = '9' === $schema;
	$phase = 'read_release';
	$released = $session->rollback(); $retired = $session->retire();
	$checks['rolled_back'] = $released; $checks['retired'] = $retired;
	if ( ! $exact || ! $released || ! $retired || '9' !== $schema ) { throw new RuntimeException(); }
	echo json_encode( [ 'status' => 'PASS', 'phase' => 'complete', 'schema' => $schema ] + $progress + $checks, JSON_THROW_ON_ERROR );
} catch ( Throwable $error ) {
	if ( null !== $session ) { try { if ( $session->in_transaction() ) { $session->rollback(); } $session->retire(); } catch ( Throwable ) {} }
	$error_class = match ( get_class( $error ) ) {
		RuntimeException::class => 'RuntimeException', Error::class => 'Error', TypeError::class => 'TypeError', JsonException::class => 'JsonException', InvalidArgumentException::class => 'InvalidArgumentException', LogicException::class => 'LogicException',
		'CetechDeliveryEngine\\Application\\Operation\\OperationStorageException' => 'OperationStorageException', default => 'other',
	};
	echo json_encode( [ 'status' => 'FAIL', 'phase' => $phase, 'error_class' => $error_class, 'checks' => $checks ] + $progress, JSON_THROW_ON_ERROR ); exit( 1 );
}
