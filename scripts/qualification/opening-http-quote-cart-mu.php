<?php
/** Marked disposable native site only; the product's prepared-review mount stays off. */
declare(strict_types=1);

if ( '1' !== getenv( 'CETECH_DE_HTTP_OPENING_QUALIFICATION' ) || '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) ) { return; }
$q05_site = getenv( 'CETECH_DE_HTTP_FIXTURE_SITE' ); $q05_private = getenv( 'CETECH_DE_HTTP_PRIVATE_DIR' ); $q05_path = getenv( 'CETECH_DE_HTTP_QUOTE_CART_STATE' );
if ( ! is_string( $q05_site ) || ! is_string( $q05_private ) || ! is_string( $q05_path ) || ! defined( 'ABSPATH' ) || realpath( ABSPATH ) !== realpath( $q05_site ) || ! defined( 'DB_HOST' ) || ! preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! defined( 'DB_NAME' ) || ! preg_match( '/\Acetech_wp_opening_qualification_[a-z0-9]+\z/D', DB_NAME ) || realpath( dirname( $q05_path ) ) !== realpath( $q05_private ) || str_starts_with( realpath( $q05_private ) . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ) ) { throw new RuntimeException( 'Q05 MU refused its environment.' ); }
if ( ! is_file( $q05_path ) ) { return; }
function cetech_q05_state(): array {
	$path = getenv( 'CETECH_DE_HTTP_QUOTE_CART_STATE' ); if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || 0 !== ( fileperms( $path ) & 0077 ) ) { throw new RuntimeException( 'Q05 state is not private.' ); }
	$state = json_decode( file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR ); if ( ! is_array( $state ) || ( $state['site_path'] ?? null ) !== realpath( ABSPATH ) || ( $state['database_name'] ?? null ) !== DB_NAME || ! preg_match( '/\A[a-f0-9]{48}\z/D', (string) ( $state['fixture_token'] ?? '' ) ) ) { throw new RuntimeException( 'Q05 state binding is invalid.' ); } return $state;
}
function cetech_q05_write( array $state ): void {
	$path = getenv( 'CETECH_DE_HTTP_QUOTE_CART_STATE' ); $temporary = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
	try { if ( false === file_put_contents( $temporary, json_encode( $state, JSON_THROW_ON_ERROR ) ) || ! chmod( $temporary, 0600 ) || ! rename( $temporary, $path ) ) { throw new RuntimeException( 'Q05 private tracking failed.' ); } } finally { if ( is_file( $temporary ) ) { unlink( $temporary ); } }
}
$q05_initial = cetech_q05_state(); if ( true === ( $q05_initial['cleanup_done'] ?? false ) ) { return; }
// Install the same production composition with an observed native connection.
// Fault masks affect only this marked, private disposable qualification site.
add_action( 'cetech_de_services_registered', static function ( $container ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }
	$state = cetech_q05_state(); $cart_support = $state['support_file'] ?? null;
	if ( ! is_string( $cart_support ) || ! is_file( $cart_support ) || is_link( $cart_support ) || 'opening-quote-cart-support.php' !== basename( $cart_support ) ) { throw new RuntimeException( 'Q06 support source is unavailable.' ); }
	$source = dirname( $cart_support ) . '/opening-http-quote-placement-support.php';
	if ( ! is_file( $source ) || is_link( $source ) ) { throw new RuntimeException( 'Q06 support source is unavailable.' ); }
	require_once $source;
	$container->singleton( CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory::class, static function () { global $wpdb; return CetechQuotePlacementHttpFixture::factory( $wpdb ); } );
} );
add_action( 'init', static function (): void {
	if ( defined( 'WP_CLI' ) && WP_CLI ) { return; }
	if ( '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! class_exists( 'CetechDeliveryEngine\Bootstrap\Plugin' ) ) { throw new RuntimeException( 'Q05 mount requires its marked native site.' ); }
	$state = cetech_q05_state(); $support = $state['support_file'] ?? null;
	if ( ! is_string( $support ) || ! is_file( $support ) || is_link( $support ) || 'opening-quote-cart-support.php' !== basename( $support ) ) { throw new RuntimeException( 'Q05 support source is unavailable.' ); }
	require_once $support; global $wpdb;
	[ $service, $sessions, $factory, $observed ] = CetechQuoteCartHttpFixture::service( $wpdb );
	$container = CetechDeliveryEngine\Bootstrap\Plugin::instance()->container();
	$runtime = $container->get( CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementActivation::class )->active()
		? $container->get( CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime::class )
		: new CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime( $service, true );
	$runtime->register();
	// This explicit fixture-only composition is neither a shopper feature flag nor placement activation.
	$GLOBALS['cetech_q05_review_runtime'] = $runtime; $GLOBALS['cetech_q05_sessions'] = $sessions; $GLOBALS['cetech_q05_factory'] = $factory;
	$placement_support = dirname( $support ) . '/opening-http-quote-placement-support.php';
	if ( ! is_file( $placement_support ) || is_link( $placement_support ) ) { throw new RuntimeException( 'Q06 support source is unavailable.' ); }
	require_once $placement_support; CetechQuotePlacementHttpFixture::register();
	add_action( 'shutdown', static function () use ( $sessions, $factory, $observed ): void {
		try {
			if ( WC()->session instanceof WC_Session_Handler && WC()->cart instanceof WC_Cart && WC()->customer instanceof WC_Customer ) {
				$state = cetech_q05_state(); $owner = CetechQuoteCartHttpFixture::track_owner( $state, $sessions );
				$failure = CetechQuoteCartHttpFixture::failure_observation( $observed, $factory );
				if ( null !== $failure ) { $state['failure_observations'][$owner->digest()] = $failure; }
				elseif ( true === $observed->diagnostics['prepare_entered'] || true === $observed->diagnostics['evidence_called'] ) { unset( $state['failure_observations'][$owner->digest()] ); }
				cetech_q05_write( $state );
			}
		} finally { $factory->close_all(); }
	}, 19 );
}, 100 );
add_action( 'template_redirect', static function (): void {
	if ( ! isset( $_GET['cetech_q05_fixture'] ) ) { return; }
	$state = cetech_q05_state(); $token = $_SERVER['HTTP_X_CETECH_Q05_FIXTURE'] ?? '';
	if ( ! is_string( $token ) || ! hash_equals( $state['fixture_token'], $token ) || ! in_array( get_current_user_id(), [ 0, $state['user_id'] ], true ) ) { wp_send_json_error( [ 'code' => 'fixture_forbidden' ], 403 ); }
	$mode = $_GET['cetech_q05_fixture']; if ( ! is_string( $mode ) || ! in_array( $mode, [ 'inspect', 'seed', 'price' ], true ) ) { wp_send_json_error( [ 'code' => 'fixture_mode' ], 400 ); }
	if ( ! WC()->session || ! WC()->cart ) { wc_load_cart(); } WC()->session->set_customer_session_cookie( true );
	global $wpdb; $runtime = $GLOBALS['cetech_q05_review_runtime']; $sessions = $GLOBALS['cetech_q05_sessions'];
	if ( 'inspect' !== $mode ) {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! is_string( $_POST['nonce'] ?? null ) || ! wp_verify_nonce( $_POST['nonce'], 'cetech_q05_fixture' ) ) { wp_send_json_error( [ 'code' => 'fixture_nonce' ], 403 ); }
		if ( 'price' === $mode && false === $wpdb->update( CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for( 'rate_cards' ), [ 'base_amount' => '9.0000' ], [ 'id' => $state['native']['rate'] ] ) ) { wp_send_json_error( [ 'code' => 'fixture_price' ], 503 ); }
		$native = CetechQuoteCartHttpFixture::hydrate_native( $wpdb, $state['native'] );
		if ( 'seed' === $mode ) { $native->cart(); }
		else { WC()->cart->get_cart(); $native->recalculate(); }
	} else { WC()->cart->get_cart(); }
	// These normal native reads load the already resolved display settings before the raw pre-gate draft.
	get_option( 'woocommerce_currency' ); get_option( 'woocommerce_price_num_decimals' ); wp_salt( 'auth' );
	$owner = CetechQuoteCartHttpFixture::track_owner( $state, $sessions ); cetech_q05_write( $state ); $facts = $runtime->current_facts(); $envelope = $sessions->load( $owner ); $quote_state = null; $quote_body = null; $uuid = $envelope?->header()?->id()->value();
	if ( null !== $uuid ) { $row = $wpdb->get_row( $wpdb->prepare( 'SELECT state,body_digest FROM `' . CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for( 'delivery_quotes' ) . '` WHERE site_id=%d AND quote_uuid=%s', get_current_blog_id(), $uuid ), ARRAY_A ); if ( is_array( $row ) ) { $quote_state = $row['state']; $quote_body = $row['body_digest']; } }
	$body_digests = []; foreach ( CetechNativeQuoteProviderFixture::rows( CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for( 'delivery_quotes' ) ) as $row ) { if ( isset( $state['owners'][$row['owner_digest']] ) ) { $body_digests[$row['quote_uuid']] = $row['body_digest']; if ( count( $body_digests ) > 200 ) { throw new RuntimeException( 'Q05 fixture quote observation exceeded its bound.' ); } } }
	$diagnostic = $state['failure_observations'][$owner->digest()] ?? null;
	wp_send_json_success( [ ...CetechQuoteCartHttpFixture::placement_counts( $wpdb ), 'quote_body_digests' => $body_digests, 'fixture_nonce' => wp_create_nonce( 'cetech_q05_fixture' ), 'review_nonce' => wp_create_nonce( CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime::NONCE_ACTION ), 'store_nonce' => wp_create_nonce( 'wc_store_api' ), 'facts' => $facts, 'history_counts' => CetechQuoteCartHttpFixture::counts( $wpdb ), 'choice_digest' => CetechQuoteCartHttpFixture::choice_digest(), 'owner_digest' => $owner->digest(), 'native_user_id' => get_current_user_id(), 'native_session_key_digest' => hash( 'sha256', WC()->session->get_customer_id() ), 'current_quote_state' => $quote_state, 'quote_body_digest' => $quote_body, 'private_uuid' => $uuid, 'source_identity' => [ 'source_head' => $state['identity']['source_head'], 'candidate_head' => $state['identity']['candidate_head'], 'source_tree' => $state['identity']['source_tree'], 'installed_php_sources_hash' => $state['identity']['installed_php_sources_hash'] ], 'prepared_mount_is_fixture_only' => true, ...( is_array( $diagnostic ) ? [ 'failure_observation' => $diagnostic ] : [] ) ] );
}, -100 );
