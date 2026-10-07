<?php
/** Actual native cart sources; only the explicitly mounted review transport is exercised. */
declare(strict_types=1);

require __DIR__ . '/opening-http-fixture-common.php';
require_once __DIR__ . '/opening-quote-cart-support.php';

use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

if ( ! isset( $args ) || count( $args ) < 3 ) { throw new RuntimeException( 'Q05 fixture expects MODE PRIVATE_STATE OUTPUT.' ); }
[ $mode, $state_path, $output_path ] = $args;
if ( ! in_array( $mode, [ 'preparequotecart', 'snapshotquotecart', 'ratechangequotecart', 'restorepricequotecart', 'classicpagequotecart', 'blockspagequotecart', 'cleanupquotecart' ], true ) ) { throw new RuntimeException( 'Unknown Q05 fixture mode.' ); }
global $wpdb;
if ( 'preparequotecart' === $mode ) {
	if ( file_exists( $state_path ) ) { throw new RuntimeException( 'Refusing to replace Q05 private credentials.' ); }
	$identity = opening_http_identity(); $native = new CetechNativeQuoteProviderFixture( $wpdb ); $state = null; $created_user = 0; $created_pages = [];
	try {
		$native->install(); $native->set_option( 'woocommerce_shipping_debug_mode', 'no' ); $native->recalculate();
		$suffix = bin2hex( random_bytes( 6 ) ); $password = bin2hex( random_bytes( 24 ) ); $username = 'q05_' . $suffix;
		$user = wp_create_user( $username, $password, $username . '@example.invalid' ); if ( is_wp_error( $user ) || ! is_int( $user ) || $user < 1 ) { throw new RuntimeException( 'Q05 native user allocation failed.' ); } $created_user = $user; ( new WP_User( $user ) )->set_role( 'customer' );
		$page_ids = []; foreach ( [ 'classic' => '[woocommerce_checkout]', 'blocks' => '<!-- wp:woocommerce/checkout /-->' ] as $kind => $content ) { $id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Q05 native review ' . $kind . ':' . $suffix, 'post_content' => $content ], true ); if ( is_wp_error( $id ) || ! is_int( $id ) || $id < 1 ) { throw new RuntimeException( 'Q05 native page allocation failed.' ); } $page_ids[$kind] = $id; $created_pages[] = $id; }
		$native->set_option( 'woocommerce_checkout_page_id', $page_ids['classic'] );
		$native->set_option( 'woocommerce_coming_soon', 'no' ); $native->set_option( 'woocommerce_store_pages_only', 'no' );
		$state = [ 'state_path' => $state_path, 'site_path' => realpath( ABSPATH ), 'database_name' => DB_NAME, 'site_id' => get_current_blog_id(), 'probe_token' => getenv( 'CETECH_DE_HTTP_PROBE_TOKEN' ), 'fixture_token' => bin2hex( random_bytes( 24 ) ), 'base_url' => 'http://127.0.0.1:8085', 'identity' => $identity, 'support_file' => realpath( __DIR__ . '/opening-quote-cart-support.php' ), 'user_id' => $user, 'username' => $username, 'password' => $password, 'page_ids' => array_values( $page_ids ), 'classic_page_id' => $page_ids['classic'], 'blocks_page_id' => $page_ids['blocks'], 'classic_page_url' => get_permalink( $page_ids['classic'] ), 'blocks_page_url' => get_permalink( $page_ids['blocks'] ), 'fixture_url' => 'http://127.0.0.1:8085/?cetech_q05_fixture=inspect', 'seed_url' => 'http://127.0.0.1:8085/?cetech_q05_fixture=seed', 'price_url' => 'http://127.0.0.1:8085/?cetech_q05_fixture=price', 'classic_url' => WC_AJAX::get_endpoint( 'cetech_delivery_quote_review' ), 'store_cart_url' => rest_url( 'wc/store/v1/cart' ), 'store_extensions_url' => rest_url( 'wc/store/v1/cart/extensions' ), 'store_customer_url' => rest_url( 'wc/store/v1/cart/update-customer' ), 'checkout_url' => rest_url( 'wc/store/v1/checkout' ), 'native' => CetechQuoteCartHttpFixture::export_native( $native ), 'owners' => [], 'cleanup_done' => false ];
		$state['browser_state_path'] = $state_path . '.browser.json'; $browser_state = $state; unset( $browser_state['native'], $browser_state['owners'] ); unset( $browser_state['identity']['installed_php_sources'] ); opening_http_write_json( $state['browser_state_path'], $browser_state, true );
		opening_http_write_json( $state_path, $state, true ); opening_http_write_json( $output_path, [ 'identity' => $state['identity'], 'snapshot' => [ 'history_counts' => CetechQuoteCartHttpFixture::counts( $wpdb ), 'rate_amount' => '7.0000', 'prepared_mount_is_fixture_only' => true ] ] );
	} catch ( Throwable $error ) { if ( is_array( $state ) ) { opening_http_write_json( $state_path, $state, true ); } else { foreach ( $created_pages as $id ) { wp_delete_post( $id, true ); } if ( $created_user > 0 ) { if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; } wp_delete_user( $created_user ); } $native->cleanup(); } throw $error; }
	return;
}
$state = opening_http_read_state( $state_path );
if ( 'cleanupquotecart' === $mode ) {
	if ( true === $state['cleanup_done'] ) { opening_http_write_json( $output_path, $state['cleanup'] ); return; }
	$cleanup = CetechQuoteCartHttpFixture::cleanup( $wpdb, $state ); $state['cleanup_done'] = true; $state['cleanup'] = $cleanup; opening_http_write_json( $state_path, $state, true ); opening_http_write_json( $output_path, $cleanup ); return;
}
if ( true === $state['cleanup_done'] ) { throw new RuntimeException( 'Q05 fixture was already cleaned.' ); }
if ( in_array( $mode, [ 'ratechangequotecart', 'restorepricequotecart' ], true ) ) { if ( false === $wpdb->update( TableNames::for( 'rate_cards' ), [ 'base_amount' => 'ratechangequotecart' === $mode ? '9.0000' : '7.0000' ], [ 'id' => $state['native']['rate'] ] ) ) { throw new RuntimeException( 'Q05 owned rate update failed.' ); } }
if ( in_array( $mode, [ 'classicpagequotecart', 'blockspagequotecart' ], true ) ) { update_option( 'woocommerce_checkout_page_id', $state[ 'classicpagequotecart' === $mode ? 'classic_page_id' : 'blocks_page_id' ], false ); }
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT base_amount FROM `' . TableNames::for( 'rate_cards' ) . '` WHERE id=%d', $state['native']['rate'] ), ARRAY_A );
opening_http_write_json( $state_path, $state, true ); opening_http_write_json( $output_path, [ 'snapshot' => [ 'history_counts' => CetechQuoteCartHttpFixture::counts( $wpdb ), 'rate_amount' => $row['base_amount'] ?? null, 'prepared_mount_is_fixture_only' => true ] ] );
