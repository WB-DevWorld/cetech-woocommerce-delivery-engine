<?php

declare(strict_types=1);

/** Fresh OS process, real WP options/default cache, no plugin boot or unit bootstrap. */
$config_path = $argv[1] ?? '';
if ( ! is_string( $config_path ) || ! is_file( $config_path ) || is_link( $config_path ) || strlen( $config_path ) > 4096 ) { exit( 2 ); }
try {
	$config = json_decode( (string) file_get_contents( $config_path ), true, 4, JSON_THROW_ON_ERROR );
	if ( ! is_array( $config ) || ! isset( $config['wp_load'], $config['prefix'], $config['site'], $config['expectations'] ) || ! is_string( $config['wp_load'] ) || ! str_ends_with( $config['wp_load'], '/wp-load.php' ) || ! is_file( $config['wp_load'] ) || ! is_string( $config['prefix'] ) || 1 !== preg_match( '/\Aop_proof_[0-9]+_[a-f0-9]{8}_\z/D', $config['prefix'] ) || ! is_int( $config['site'] ) || $config['site'] < 1 || ! is_array( $config['expectations'] ) || count( $config['expectations'] ) > 8 ) { throw new RuntimeException(); }
	define( 'SHORTINIT', true );
	define( 'WP_ADMIN', true );
	require $config['wp_load'];
	if ( ! defined( 'DB_HOST' ) || 1 !== preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || 1 !== preg_match( '/^cetech_wp_opening_qualification(?:_[a-z0-9]+)?$/D', DB_NAME ) || ! $GLOBALS['wpdb'] instanceof wpdb || ! class_exists( WP_Object_Cache::class ) || ! function_exists( 'get_option' ) ) { throw new RuntimeException(); }
	$GLOBALS['wpdb']->set_prefix( $config['prefix'] );
	$GLOBALS['blog_id'] = $config['site'];
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	$present = 0; $absent = 0;
	foreach ( $config['expectations'] as $expected ) {
		if ( ! is_array( $expected ) || ! is_string( $expected['name'] ?? null ) || 1 !== preg_match( '/\Acetech_de_[a-zA-Z0-9_]+\z/D', $expected['name'] ) || ! is_bool( $expected['absent'] ?? null ) ) { throw new RuntimeException(); }
		$name = $expected['name'];
		$raw = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( "SELECT option_value,autoload FROM `{$GLOBALS['wpdb']->options}` WHERE option_name=%s", $name ), ARRAY_A );
		$value = get_option( $name, 'C06-NATIVE-OPTION-ABSENT' );
		if ( $expected['absent'] ) {
			if ( null !== $raw || 'C06-NATIVE-OPTION-ABSENT' !== $value ) { throw new RuntimeException(); }
			++$absent;
		} else {
			if ( ! is_array( $raw ) || ! is_string( $expected['raw_hash'] ?? null ) || ! hash_equals( $expected['raw_hash'], hash( 'sha256', (string) $raw['option_value'] ) ) || ! hash_equals( (string) $expected['value_hash'], hash( 'sha256', serialize( $value ) ) ) || ( $expected['autoload'] ?? null ) !== $raw['autoload'] ) { throw new RuntimeException(); }
			++$present;
		}
	}
	echo json_encode( [ 'status' => 'PASS', 'present' => $present, 'absent' => $absent ], JSON_THROW_ON_ERROR );
} catch ( Throwable ) {
	echo '{"status":"FAIL"}';
	exit( 1 );
}
