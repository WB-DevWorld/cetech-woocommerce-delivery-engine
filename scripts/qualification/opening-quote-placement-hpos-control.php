<?php

declare(strict_types=1);

/** Three tracked native options; fresh processes, never a forged in-request authority switch. */
if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || true !== WP_ADMIN || '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) ) { throw new RuntimeException( 'Q06 HPOS control requires the marked disposable site.' ); }
$mode = $args[0] ?? null; $path = $args[1] ?? null;
if ( ! in_array( $mode, [ 'prepare', 'restore', 'verify' ], true ) || ! is_string( $path ) || ! is_dir( dirname( $path ) ) || is_link( $path ) ) { throw new RuntimeException( 'Invalid Q06 HPOS control arguments.' ); }
global $wpdb;
$names = [ 'woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled', 'woocommerce_custom_orders_table_background_sync_mode' ];
$option = static function ( string $name ) use ( $wpdb ): ?array {
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id,option_name,option_value,autoload FROM `{$wpdb->options}` WHERE option_name=%s LIMIT 2", $name ), ARRAY_A );
	if ( ! is_array( $rows ) || count( $rows ) > 1 || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Q06 HPOS option observation unavailable.' ); } return $rows[0] ?? null;
};
$protected = static function () use ( $wpdb ): string {
	$queries = [
		"SELECT post_id,meta_key,meta_value FROM `{$wpdb->postmeta}` WHERE LEFT(meta_key,11)='_cetech_de_' ORDER BY post_id,meta_key,meta_value",
		"SELECT order_id,meta_key,meta_value FROM `{$wpdb->prefix}wc_orders_meta` WHERE LEFT(meta_key,11)='_cetech_de_' ORDER BY order_id,meta_key,meta_value",
		"SELECT order_item_id,meta_key,meta_value FROM `{$wpdb->prefix}woocommerce_order_itemmeta` WHERE LEFT(meta_key,11)='_cetech_de_' ORDER BY order_item_id,meta_key,meta_value",
	]; $out = [];
	foreach ( $queries as $sql ) { $rows = $wpdb->get_results( $sql, ARRAY_A ); if ( ! is_array( $rows ) || count( $rows ) > 20000 || '' !== $wpdb->last_error ) { throw new RuntimeException( 'Q06 HPOS protected history observation unavailable.' ); } $out[] = $rows; }
	return hash( 'sha256', json_encode( $out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
};
$invalidate = static function ( string $name ): void { wp_cache_delete( $name, 'options' ); wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); };
if ( 'prepare' === $mode ) {
	if ( file_exists( $path ) || ! Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) { throw new RuntimeException( 'Q06 HPOS control refuses an existing envelope or wrong initial authority.' ); }
	$state = [ 'format' => 'cetech-q06-hpos-private-control-v1', 'site_path' => realpath( ABSPATH ), 'database' => DB_NAME, 'site' => get_current_blog_id(), 'options' => [], 'protected_digest' => $protected() ];
	foreach ( $names as $name ) { $state['options'][$name] = $option( $name ); }
	if ( false === file_put_contents( $path, json_encode( $state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ) || ! chmod( $path, 0600 ) ) { throw new RuntimeException( 'Q06 HPOS private control could not be saved.' ); }
	foreach ( array_combine( $names, [ 'no', 'no', 'off' ] ) as $name => $value ) { $original = $state['options'][$name]; $ok = null === $original ? $wpdb->insert( $wpdb->options, [ 'option_name' => $name, 'option_value' => $value, 'autoload' => 'off' ] ) : $wpdb->update( $wpdb->options, [ 'option_value' => $value ], [ 'option_id' => $original['option_id'], 'option_name' => $name ] ); if ( false === $ok ) { throw new RuntimeException( 'Q06 HPOS tracked option preparation failed.' ); } $invalidate( $name ); }
	echo "q06_hpos_control=prepared_for_fresh_off_process\n"; return;
}
$raw = file_get_contents( $path ); if ( ! is_string( $raw ) || strlen( $raw ) > 65536 ) { throw new RuntimeException( 'Q06 HPOS private control unavailable.' ); }
$state = json_decode( $raw, true, 16, JSON_THROW_ON_ERROR );
if ( ! is_array( $state ) || [ 'format', 'site_path', 'database', 'site', 'options', 'protected_digest' ] !== array_keys( $state ) || 'cetech-q06-hpos-private-control-v1' !== $state['format'] || $state['site_path'] !== realpath( ABSPATH ) || $state['database'] !== DB_NAME || $state['site'] !== get_current_blog_id() || array_keys( $state['options'] ) !== $names ) { throw new RuntimeException( 'Q06 HPOS private control identity differs.' ); }
if ( 'restore' === $mode ) {
	foreach ( $state['options'] as $name => $row ) { if ( null === $row ) { $ok = $wpdb->delete( $wpdb->options, [ 'option_name' => $name ] ); } else { if ( array_keys( $row ) !== [ 'option_id', 'option_name', 'option_value', 'autoload' ] || $row['option_name'] !== $name ) { throw new RuntimeException( 'Q06 HPOS original option is invalid.' ); } $ok = $wpdb->replace( $wpdb->options, $row ); } if ( false === $ok ) { throw new RuntimeException( 'Q06 HPOS exact option restoration failed.' ); } $invalidate( $name ); }
	echo "q06_hpos_control=original_rows_restored\n"; return;
}
$same = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() && $protected() === $state['protected_digest'];
foreach ( $state['options'] as $name => $row ) { $same = $row === $option( $name ) && $same; }
if ( ! $same ) { throw new RuntimeException( 'Q06 HPOS fresh authority/options/protected history restoration differs.' ); }
if ( ! unlink( $path ) ) { throw new RuntimeException( 'Q06 HPOS private control removal failed.' ); }
echo "q06_hpos_control=fresh_on_exact_options_and_protected_history_restored\n";
