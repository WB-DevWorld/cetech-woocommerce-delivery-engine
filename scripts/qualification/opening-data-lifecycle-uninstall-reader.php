<?php

declare(strict_types=1);

/** Fresh standalone WP-only uninstall; own copied dependencies, no Composer/Woo/plugins. */
$path = $argv[1] ?? '';
if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) ) { exit( 2 ); }
try {
	$config = json_decode( (string) file_get_contents( $path ), true, 4, JSON_THROW_ON_ERROR );
	if ( ! is_array( $config ) || ! is_string( $config['wp_load'] ?? null ) || ! str_ends_with( $config['wp_load'], '/wp-load.php' ) || ! is_file( $config['wp_load'] ) || ! is_string( $config['uninstall'] ?? null ) || ! is_file( $config['uninstall'] ) || ! is_string( $config['prefix'] ?? null ) || 1 !== preg_match( '/\Aop_proof_[0-9]+_[a-f0-9]{8}_\z/D', $config['prefix'] ) || ! is_int( $config['site'] ?? null ) || $config['site'] < 1 ) { throw new RuntimeException(); }
	define( 'SHORTINIT', true ); define( 'WP_ADMIN', true );
	require $config['wp_load'];
	if ( ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $GLOBALS['wpdb'] instanceof wpdb ) { throw new RuntimeException(); }
	foreach ( [ 'class-wp-role.php', 'class-wp-roles.php', 'capabilities.php' ] as $core ) { require_once ABSPATH . WPINC . '/' . $core; }
	$GLOBALS['wpdb']->set_prefix( $config['prefix'] ); $GLOBALS['blog_id'] = $config['site']; $GLOBALS['wp_object_cache'] = new WP_Object_Cache(); $GLOBALS['wp_user_roles'] = null; $GLOBALS['wp_roles'] = new WP_Roles( $config['site'] );
	get_option( 'cetech_de_delete_data_on_uninstall' ); get_option( 'cetech_de_capabilities_version' ); get_option( 'cetech_de_db_version' );
	define( 'WP_UNINSTALL_PLUGIN', 'cetech-c06-native-standalone-proof' );
	require $config['uninstall'];
	$raw = get_option( 'cetech_de_data_lifecycle_uninstall_status', '' );
	$status = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true, 4, JSON_THROW_ON_ERROR ) : null;
	echo json_encode( [ 'status' => 'PASS', 'uninstall_status' => is_array( $status ) ? ( $status['status'] ?? null ) : null, 'intent_present' => false !== get_option( 'cetech_de_delete_data_on_uninstall', false ), 'composer_loaded' => class_exists( 'Composer\\Autoload\\ClassLoader', false ), 'woocommerce_loaded' => class_exists( 'WooCommerce', false ) ], JSON_THROW_ON_ERROR );
} catch ( Throwable ) { echo '{"status":"FAIL"}'; exit( 1 ); }
