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
try {
	$config = json_decode( (string) file_get_contents( $path ), true, 4, JSON_THROW_ON_ERROR );
	$keys = is_array( $config ) ? array_keys( $config ) : []; sort( $keys );
	$expected = [ 'body_hash', 'header_hash', 'prefix', 'quote_uuid', 'row_hash', 'site', 'wp_load' ]; sort( $expected );
	if ( $keys !== $expected || ! is_string( $config['wp_load'] ) || ! str_ends_with( $config['wp_load'], '/wp-load.php' ) || ! is_file( $config['wp_load'] ) || ! is_string( $config['prefix'] ) || 1 !== preg_match( '/\Agc6_[a-f0-9]{12}_\z/D', $config['prefix'] ) || ! is_int( $config['site'] ) || 99175 !== $config['site'] || ! is_string( $config['quote_uuid'] ) ) { throw new RuntimeException(); }
	foreach ( [ 'row_hash', 'header_hash', 'body_hash' ] as $name ) { if ( ! is_string( $config[$name] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $config[$name] ) ) { throw new RuntimeException(); } }
	define( 'SHORTINIT', true ); define( 'WP_ADMIN', true );
	require $config['wp_load'];
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $GLOBALS['wpdb'] instanceof wpdb || wpdb::class !== get_class( $GLOBALS['wpdb'] ) || ! isset( $GLOBALS['wp_object_cache'] ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) ) { throw new RuntimeException(); }
	$vendor = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
	if ( ! is_file( $vendor ) ) { throw new RuntimeException(); }
	require $vendor;
	foreach ( get_included_files() as $included ) { if ( str_ends_with( str_replace( '\\', '/', $included ), '/tests/bootstrap.php' ) ) { throw new RuntimeException(); } }
	$GLOBALS['wpdb']->set_prefix( $config['prefix'] ); $GLOBALS['blog_id'] = $config['site']; $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	$session = ( new OperationConnectionFactory() )->open();
	if ( $session->site_id() !== $config['site'] || $session->table_prefix() !== $config['prefix'] ) { throw new RuntimeException(); }
	$readiness = new DeliveryQuoteReadiness( $session ); $readiness->assert_ready();
	if ( ! $session->begin() ) { throw new RuntimeException(); }
	$row = ( new DeliveryQuoteRepository( $session, $readiness ) )->find_quote( QuoteId::from_string( $config['quote_uuid'] ) );
	$facts = $row?->row();
	$exact = is_array( $facts ) && hash_equals( $config['row_hash'], hash( 'sha256', json_encode( $facts, JSON_THROW_ON_ERROR ) ) ) && hash_equals( $config['header_hash'], hash( 'sha256', $facts['header_json'] ) ) && is_string( $facts['private_body_json'] ) && hash_equals( $config['body_hash'], hash( 'sha256', $facts['private_body_json'] ) );
	$schema = (string) get_option( 'cetech_de_db_version' );
	$released = $session->rollback(); $retired = $session->retire();
	if ( ! $exact || ! $released || ! $retired || '9' !== $schema ) { throw new RuntimeException(); }
	echo json_encode( [ 'status' => 'PASS', 'process_id' => getmypid(), 'wp_load' => true, 'default_object_cache' => true, 'schema' => $schema, 'exact_row' => true, 'immutable_header' => true, 'immutable_body' => true, 'retired' => true ], JSON_THROW_ON_ERROR );
} catch ( Throwable ) {
	if ( null !== $session ) { try { if ( $session->in_transaction() ) { $session->rollback(); } $session->retire(); } catch ( Throwable ) {} }
	echo '{"status":"FAIL"}'; exit( 1 );
}
