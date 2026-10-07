<?php
/** Explicitly enabled CI-only route/gateway/barriers; never installed in a product. */
declare(strict_types=1);

if ( '1' !== getenv( 'CETECH_DE_HTTP_OPENING_QUALIFICATION' ) || '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) ) { return; }
$c07_site = getenv( 'CETECH_DE_HTTP_FIXTURE_SITE' ); $c07_private = getenv( 'CETECH_DE_HTTP_PRIVATE_DIR' ); $c07_state_path = getenv( 'CETECH_DE_HTTP_EMERGENCY_STATE' );
if ( ! is_string( $c07_site ) || ! is_string( $c07_private ) || ! is_string( $c07_state_path ) || ! defined( 'ABSPATH' ) || realpath( ABSPATH ) !== realpath( $c07_site ) || ! defined( 'DB_HOST' ) || ! preg_match( '/^127\.0\.0\.1(?::[0-9]+)?$/D', DB_HOST ) || ! defined( 'DB_NAME' ) || ! preg_match( '/^cetech_wp_opening_qualification_[a-z0-9]+$/D', DB_NAME ) || realpath( dirname( $c07_state_path ) ) !== realpath( $c07_private ) || str_starts_with( realpath( $c07_private ) . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ) ) { throw new RuntimeException( 'C07 MU fixture refused its environment.' ); }
if ( ! is_file( $c07_state_path ) ) { return; }
if ( is_link( $c07_state_path ) || 0 !== ( fileperms( $c07_state_path ) & 0077 ) ) { throw new RuntimeException( 'C07 MU private state is not protected.' ); }
$c07_state = json_decode( file_get_contents( $c07_state_path ), true, 64, JSON_THROW_ON_ERROR );
if ( ! is_array( $c07_state ) || ( $c07_state['site_path'] ?? null ) !== realpath( ABSPATH ) || ( $c07_state['database_name'] ?? null ) !== DB_NAME || ! preg_match( '/^[a-f0-9]{48}$/D', (string) ( $c07_state['fixture_token'] ?? '' ) ) ) { throw new RuntimeException( 'C07 MU state binding is invalid.' ); }

function cetech_c07_private_state(): array {
	$path = getenv( 'CETECH_DE_HTTP_EMERGENCY_STATE' );
	if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || 0 !== ( fileperms( $path ) & 0077 ) ) { throw new RuntimeException( 'C07 private state is unavailable.' ); }
	$state = json_decode( file_get_contents( $path ), true, 64, JSON_THROW_ON_ERROR );
	if ( ! is_array( $state ) || $state['site_path'] !== realpath( ABSPATH ) || $state['database_name'] !== DB_NAME ) { throw new RuntimeException( 'C07 private state binding changed.' ); } return $state;
}
function cetech_c07_private_write( array $state ): void {
	$path = getenv( 'CETECH_DE_HTTP_EMERGENCY_STATE' ); $temporary = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
	try { if ( false === file_put_contents( $temporary, json_encode( $state, JSON_THROW_ON_ERROR ) ) || ! chmod( $temporary, 0600 ) || ! rename( $temporary, $path ) ) { throw new RuntimeException( 'C07 private fixture tracking failed.' ); } } finally { if ( is_file( $temporary ) ) { unlink( $temporary ); } }
}
function cetech_c07_fixture_principal(): bool { $state = cetech_c07_private_state(); return $state['user_id'] === get_current_user_id() && $state['site_id'] === get_current_blog_id(); }
function cetech_c07_source_identity(): array {
	$root = dirname( ( new ReflectionClass( 'CetechDeliveryEngine\\Bootstrap\\Plugin' ) )->getFileName(), 3 ); $map = [];
	foreach ( [ 'src', 'database' ] as $part ) { foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $part, FilesystemIterator::SKIP_DOTS ) ) as $file ) { if ( $file->isFile() && 'php' === $file->getExtension() ) { $map[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = hash_file( 'sha256', $file->getPathname() ); if ( count( $map ) > 1000 ) { throw new RuntimeException( 'C07 installed map exceeded its fixture bound.' ); } } } }
	foreach ( [ 'cetech-woocommerce-delivery-engine.php', 'uninstall.php' ] as $file ) { $map[$file] = hash_file( 'sha256', $root . '/' . $file ); } ksort( $map, SORT_STRING );
	return [ 'source_head' => getenv( 'CETECH_DE_QUALIFICATION_HEAD' ), 'candidate_head' => getenv( 'CETECH_DE_QUALIFICATION_CANDIDATE_HEAD' ), 'source_tree' => getenv( 'CETECH_DE_QUALIFICATION_TREE' ), 'installed_php_sources_hash' => hash( 'sha256', json_encode( $map, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ) ];
}
// Explicit CI equivalent of WCFM's current identity API; not a WCFM plugin qualification.
if ( ! function_exists( 'wcfm_is_vendor' ) ) { function wcfm_is_vendor( $user_id = null ): bool { $state = cetech_c07_private_state(); return true === ( $state['restricted_vendor'] ?? false ) && (int) ( $user_id ?? get_current_user_id() ) === $state['user_id']; } }
function cetech_c07_barrier( string $phase ): void {
	if ( defined( 'WP_CLI' ) && WP_CLI || ! cetech_c07_fixture_principal() ) { return; }
	$barrier = get_option( 'cetech_opening_c07_barrier', null );
	if ( ! is_array( $barrier ) || $phase !== ( $barrier['phase'] ?? null ) || true !== ( $barrier['armed'] ?? null ) ) { return; }
	$state = cetech_c07_private_state(); require_once $state['support_file']; $fixture = new CetechOpeningEmergencyFixture( $state );
	if ( 'classic_empty_cart' === $phase ) { WC()->cart->empty_cart(); }
	else { $result = $fixture->transition( 'checkout_suspended', 'incident_pause' ); if ( 'accepted' !== $result->outcome->state ) { throw new RuntimeException( 'C07 controlled transition barrier was not accepted.' ); } }
	$barrier['armed'] = false; $barrier['triggered'] = true; update_option( 'cetech_opening_c07_barrier', $barrier, false ); cetech_c07_private_write( $fixture->state );
}

add_action( 'plugins_loaded', static function (): void {
	if ( '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! class_exists( 'WC_Payment_Gateway' ) || ! class_exists( 'CetechDeliveryEngine\Bootstrap\Plugin' ) ) { throw new RuntimeException( 'C07 MU plugin requires the marked native Woo fixture.' ); }
	class CetechOpeningEmergencyGateway extends WC_Payment_Gateway {
		public function __construct() { $this->id = 'cetech_c07_local_gateway'; $this->method_title = 'C07 local qualification gateway'; $this->title = 'C07 local test payment'; $this->enabled = 'yes'; $this->has_fields = false; $this->supports = [ 'products' ]; }
		public function is_available() { return cetech_c07_fixture_principal() && parent::is_available(); }
		public function process_payment( $order_id ) { if ( ! cetech_c07_fixture_principal() ) { throw new RuntimeException( 'C07 local gateway refused a different principal.' ); } update_option( 'cetech_opening_c07_gateway_count', (int) get_option( 'cetech_opening_c07_gateway_count', 0 ) + 1, false ); $order = wc_get_order( $order_id ); if ( ! $order instanceof WC_Order ) { throw new RuntimeException( 'C07 local payment order is unavailable.' ); } $order->payment_complete(); return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ]; }
	}
	add_filter( 'woocommerce_payment_gateways', static function ( array $methods ): array { $methods[] = CetechOpeningEmergencyGateway::class; return $methods; } );
	add_action( 'woocommerce_after_checkout_validation', static function (): void { cetech_c07_barrier( 'classic_after_validation' ); }, 1000 );
	add_action( 'woocommerce_store_api_checkout_update_order_from_request', static function (): void { cetech_c07_barrier( 'store_after_update' ); }, 1000 );
	add_action( 'woocommerce_checkout_order_processed', static function (): void { cetech_c07_barrier( 'classic_empty_cart' ); }, 1000 );
	$track = static function ( mixed $order ): void { if ( defined( 'WP_CLI' ) && WP_CLI || ! $order instanceof WC_Order || ! cetech_c07_fixture_principal() ) { return; } $state = cetech_c07_private_state(); if ( ! in_array( $order->get_id(), $state['orders'], true ) ) { $state['orders'][] = $order->get_id(); cetech_c07_private_write( $state ); } };
	add_action( 'woocommerce_checkout_order_created', $track, 1000 ); add_action( 'woocommerce_store_api_checkout_order_created', $track, 1000 );
	add_action( 'template_redirect', static function (): void {
		if ( ! isset( $_GET['cetech_c07_fixture'] ) ) { return; }
		$state = cetech_c07_private_state(); $token = $_SERVER['HTTP_X_CETECH_C07_FIXTURE'] ?? '';
		if ( ! is_string( $token ) || ! hash_equals( $state['fixture_token'], $token ) || ! cetech_c07_fixture_principal() ) { wp_send_json_error( [ 'code' => 'fixture_forbidden' ], 403 ); }
		require_once $state['support_file']; $fixture = new CetechOpeningEmergencyFixture( $state );
		if ( ! WC()->session || ! WC()->cart ) { wc_load_cart(); } WC()->session->set_customer_session_cookie( true );
		$mode = (string) $_GET['cetech_c07_fixture'];
		if ( ! in_array( $mode, [ 'inspect', 'seed', 'pause' ], true ) ) { wp_send_json_error( [ 'code' => 'fixture_mode' ], 400 ); }
		if ( 'pause' === $mode ) { if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! wp_verify_nonce( (string) ( $_POST['nonce'] ?? '' ), 'cetech_c07_fixture' ) ) { wp_send_json_error( [ 'code' => 'fixture_nonce' ], 403 ); } $result = $fixture->transition( 'checkout_suspended', 'incident_pause' ); if ( 'accepted' !== $result->outcome->state ) { wp_send_json_error( [ 'code' => 'fixture_transition' ], 503 ); } cetech_c07_private_write( $fixture->state ); }
		if ( 'seed' === $mode ) {
			if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! wp_verify_nonce( (string) ( $_POST['nonce'] ?? '' ), 'cetech_c07_fixture' ) ) { wp_send_json_error( [ 'code' => 'fixture_nonce' ], 403 ); }
			$scenario = (string) ( $_POST['scenario'] ?? '' ); if ( ! in_array( $scenario, [ 'managed', 'pickup', 'mixed', 'unmanaged' ], true ) ) { wp_send_json_error( [ 'code' => 'fixture_scenario' ], 400 ); }
			WC()->cart->empty_cart(); $contents = [];
			foreach ( 'mixed' === $scenario ? [ 'managed', 'unmanaged' ] : [ $scenario ] as $kind ) { $item = $fixture->item( $kind ); $contents[ $item['key'] ] = $item; }
			WC()->cart->cart_contents = $contents; WC()->customer->set_billing_country( 'GH' ); WC()->customer->set_billing_state( 'AA' ); WC()->customer->set_billing_postcode( '00001' ); WC()->customer->set_billing_city( 'Accra' ); WC()->customer->set_billing_address_1( 'PRIVATE-C07-SYNTHETIC-ADDRESS' ); WC()->customer->set_billing_first_name( 'Synthetic' ); WC()->customer->set_billing_last_name( 'Shopper' ); WC()->customer->set_billing_email( 'c07-shopper@example.invalid' ); WC()->customer->set_billing_phone( '0200000000' ); WC()->customer->set_shipping_country( 'GH' ); WC()->customer->set_shipping_state( 'AA' ); WC()->customer->set_shipping_postcode( '00001' ); WC()->customer->set_shipping_first_name( 'Synthetic' ); WC()->customer->set_shipping_last_name( 'Shopper' ); WC()->customer->set_shipping_city( 'Accra' ); WC()->customer->set_shipping_address_1( 'PRIVATE-C07-SYNTHETIC-ADDRESS' ); WC()->customer->save(); WC()->cart->calculate_totals();
			$chosen = []; foreach ( WC()->shipping()->get_packages() as $index => $package ) { $rates = $package['rates'] ?? []; $chosen[ $index ] = [] === $rates ? '' : (string) array_key_first( $rates ); } WC()->session->set( 'chosen_shipping_methods', $chosen ); WC()->cart->calculate_totals(); WC()->cart->set_session(); WC()->session->save_data();
		}
		$packages = []; foreach ( WC()->shipping()->get_packages() as $package ) { $rates = []; foreach ( $package['rates'] ?? [] as $rate ) { $rates[] = [ 'id' => $rate->get_id(), 'method' => $rate->get_method_id(), 'cost' => $rate->get_cost() ]; } $packages[] = [ 'managed' => ! empty( $package['cetech_de']['managed'] ), 'rates' => $rates ]; }
		wp_send_json_success( [ 'nonce' => wp_create_nonce( 'cetech_c07_fixture' ), 'store_nonce' => wp_create_nonce( 'wc_store_api' ), 'count' => WC()->cart->get_cart_contents_count(), 'total' => WC()->cart->get_total( 'edit' ), 'packages' => $packages, 'gateway_count' => (int) get_option( 'cetech_opening_c07_gateway_count', 0 ), 'source_identity' => cetech_c07_source_identity() ] );
	}, -100 );
}, 999 );
