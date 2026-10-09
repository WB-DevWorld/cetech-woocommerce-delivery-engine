<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\ServicePromise\Persistence\PromiseVersionReadService;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\ServicePromise\{PromiseCalendarReference, PromisePolicyReference};
use CetechDeliveryEngine\Domain\ServicePromise\Persistence\PromiseSiteBinding;

/** Fresh SHORTINIT WordPress and installed production Composer bytes; no plugin hooks or unit bootstrap. */
$path = $argv[1] ?? null; $factory = null;
try {
	if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || dirname( $path ) !== sys_get_temp_dir() || filesize( $path ) > 16384 || 0600 !== ( fileperms( $path ) & 0777 ) ) { throw new RuntimeException(); }
	$config = json_decode( (string) file_get_contents( $path ), true, 8, JSON_THROW_ON_ERROR ); $keys = is_array( $config ) ? array_keys( $config ) : []; sort( $keys );
	$expected = [ 'wp_load', 'plugin_root', 'prefix', 'site', 'site_key', 'policy_reference', 'calendar_reference', 'policy_body_hash', 'calendar_body_hash' ]; sort( $expected );
	if ( $keys !== $expected || ! is_string( $config['wp_load'] ) || ! is_file( $config['wp_load'] ) || ! str_ends_with( $config['wp_load'], '/wp-load.php' ) || ! is_string( $config['plugin_root'] ) || ! is_string( $config['prefix'] ) || 1 !== preg_match( '/\Agc6_[a-f0-9]{12}_\z/D', $config['prefix'] ) || 99176 !== $config['site'] || ! is_string( $config['site_key'] ) ) { throw new RuntimeException(); }
	foreach ( [ 'policy_body_hash', 'calendar_body_hash' ] as $key ) { if ( ! is_string( $config[$key] ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $config[$key] ) ) { throw new RuntimeException(); } }
	define( 'SHORTINIT', true ); define( 'WP_ADMIN', true ); require $config['wp_load'];
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $GLOBALS['wpdb'] instanceof wpdb || wpdb::class !== get_class( $GLOBALS['wpdb'] ) || ! isset( $GLOBALS['wp_object_cache'] ) || WP_Object_Cache::class !== get_class( $GLOBALS['wp_object_cache'] ) || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || is_file( WP_CONTENT_DIR . '/object-cache.php' ) || is_file( WP_CONTENT_DIR . '/db.php' ) ) { throw new RuntimeException(); }
	$installed = realpath( WP_CONTENT_DIR . '/plugins/cetech-woocommerce-delivery-engine' );
	if ( false === $installed || $installed !== realpath( $config['plugin_root'] ) || ! is_file( $installed . '/vendor/autoload.php' ) ) { throw new RuntimeException(); }
	require $installed . '/vendor/autoload.php'; require_once __DIR__ . '/opening-promise-storage-support.php';
	foreach ( get_included_files() as $file ) { if ( str_ends_with( str_replace( '\\', '/', $file ), '/tests/bootstrap.php' ) ) { throw new RuntimeException(); } }
	$GLOBALS['wpdb']->set_prefix( $config['prefix'] ); $GLOBALS['blog_id'] = $config['site']; $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	$binding = PromiseSiteBinding::bind( $config['site'], $config['site_key'] ); $factory = new CetechNativeQuoteLifecycleFactory( $GLOBALS['wpdb'], $config['site'], $config['prefix'] );
	$reads = new PromiseVersionReadService( $binding, $factory, new CetechNativePromiseAuthorizer( $binding ) );
	$actor = new OperationIdentity( $config['site'], 'native-p02-internal', 'user:1', 'promise.versions.read', 1, 'promise-versions:' . $binding->site_key(), 'fresh-native-p02-private-read' );
	$policy = $reads->load_policy_versions( $actor, [ PromisePolicyReference::from_array( $config['policy_reference'] ) ] ); $calendar = $reads->load_calendar_versions( $actor, [ PromiseCalendarReference::from_array( $config['calendar_reference'] ) ] );
	$exact = count( $policy ) === 1 && count( $calendar ) === 1 && hash_equals( $config['policy_body_hash'], hash( 'sha256', $policy[0]->to_private_json() ) ) && hash_equals( $config['calendar_body_hash'], hash( 'sha256', $calendar[0]->to_private_json() ) );
	$closed = ! $factory->has_active_owner() && $factory->close_all();
	if ( ! $exact || ! $closed || '10' !== (string) get_option( 'cetech_de_db_version' ) ) { throw new RuntimeException(); }
	echo json_encode( [ 'status' => 'PASS', 'process_id' => getmypid(), 'wp_load' => true, 'installed_candidate_autoload' => true, 'default_object_cache' => true, 'exact_historical_bodies' => $exact, 'all_owners_retired' => $closed ], JSON_THROW_ON_ERROR );
} catch ( Throwable ) {
	if ( null !== $factory ) { try { $factory->close_all(); } catch ( Throwable ) {} }
	echo json_encode( [ 'status' => 'FAIL', 'process_id' => getmypid() ], JSON_THROW_ON_ERROR ); exit( 1 );
}
