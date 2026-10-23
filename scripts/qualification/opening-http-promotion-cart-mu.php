<?php
/** N18 marked native composition only; public promotion mounting stays OFF. */
declare(strict_types=1);

/** Failure facts only; this observer never creates, retries or stages an order. */
function cetech_n18_placement_tracked_order(mixed $order): bool {
    try {
        return $order instanceof WC_Order && $order->get_id()>0
            && in_array($order->get_id(),$GLOBALS['cetech_n18_owned_orders']??[],true)
            && function_exists('get_current_user_id') && $order->get_customer_id()===get_current_user_id();
    } catch (Throwable) { return false; }
}
function cetech_n18_observe_checkout_exception(mixed $order): void {
    if (true===($GLOBALS['cetech_n18_creating_order']??false) && cetech_n18_placement_tracked_order($order)) {
        $GLOBALS['cetech_n18_checkout_exception_observed']=true;
    }
}
function cetech_n18_placement_failure(Throwable $error): array {
    $phases=['entry','current_bundle','checkout_address','namespace_tracking','budget_before','create_order','create_order_wp_error','created_order_identity','explicit_stage','final_admission','sealed_read','saved_history','package_read','persist_placed_order','complete'];
    $phase=$GLOBALS['cetech_n18_placement_phase']??'entry';
    $phase=is_string($phase)&&in_array($phase,$phases,true)?$phase:'entry';
    $wp_error=true===($GLOBALS['cetech_n18_placement_wp_error_returned']??false)&&'create_order_wp_error'===$phase;
    $class=get_class($error);
    $classes=['Exception','RuntimeException','LogicException','InvalidArgumentException','UnexpectedValueException','Error','TypeError','ParseError','OtherError'];
    $endpoint=$GLOBALS['cetech_n18_checkout_endpoint_verified']??null;
    return ['format'=>'cetech-n18-placement-failure-v1','phase'=>$phase,'kind'=>$wp_error?'wp_error':'throwable',
        // Woo returns only WP_Error and discards the original caught exception.
        'error_class'=>$wp_error?null:(in_array($class,$classes,true)?$class:'OtherError'),
        'checkout_exception_observed'=>true===($GLOBALS['cetech_n18_checkout_exception_observed']??false),
        'tracked_native_order'=>cetech_n18_placement_tracked_order($GLOBALS['cetech_n18_created_order']??null),
        'checkout_endpoint_verified'=>is_bool($endpoint)?$endpoint:null];
}

if ( '1' !== getenv( 'CETECH_DE_HTTP_OPENING_QUALIFICATION' ) || '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) ) { return; }
$q05_site = getenv( 'CETECH_DE_HTTP_FIXTURE_SITE' ); $q05_private = getenv( 'CETECH_DE_HTTP_PRIVATE_DIR' ); $q05_path = getenv( 'CETECH_DE_HTTP_PROMOTION_CART_STATE' );
if ( ! is_string( $q05_site ) || ! is_string( $q05_private ) || ! is_string( $q05_path ) || ! defined( 'ABSPATH' ) || realpath( ABSPATH ) !== realpath( $q05_site ) || ! defined( 'DB_HOST' ) || ! preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! defined( 'DB_NAME' ) || ! preg_match( '/\Acetech_wp_opening_qualification_[a-z0-9]+\z/D', DB_NAME ) || realpath( dirname( $q05_path ) ) !== realpath( $q05_private ) || str_starts_with( realpath( $q05_private ) . '/', rtrim( realpath( ABSPATH ), '/' ) . '/' ) ) { throw new RuntimeException( 'Q05 MU refused its environment.' ); }
if (!defined('DISABLE_WP_CRON')||!DISABLE_WP_CRON) { throw new RuntimeException('N18 requires disabled native cron.'); }
add_filter('action_scheduler_allow_async_request_runner','__return_false',PHP_INT_MAX);
if (!(defined('WP_CLI') && WP_CLI) && isset($_GET['cetech_opening_http_probe'])) {
    $token = getenv('CETECH_DE_HTTP_PROBE_TOKEN'); $received = $_SERVER['HTTP_X_CETECH_OPENING_PROBE'] ?? null;
    if ('1' !== $_GET['cetech_opening_http_probe'] || !is_string($token) || !preg_match('/\A[a-f0-9]{48}\z/D', $token) || !is_string($received) || !hash_equals($token, $received)) { http_response_code(403); exit; }
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    $all_ini=ini_get_all(null,false);ksort($all_ini);$original_ini=$all_ini;$original_ini['opcache.jit']='1235';$extensions=[];foreach(get_loaded_extensions()as$name){$extensions[$name]=phpversion($name);}ksort($extensions);$opcache=function_exists('opcache_get_status')?opcache_get_status(false):false;
    $runtime=['sapi'=>PHP_SAPI,'php_version'=>PHP_VERSION,'binary_sha256'=>hash_file('sha256',PHP_BINARY),'ini_sha256'=>hash('sha256',json_encode($all_ini,JSON_THROW_ON_ERROR)),'original_ini_sha256'=>hash('sha256',json_encode($original_ini,JSON_THROW_ON_ERROR)),'extensions_sha256'=>hash('sha256',json_encode($extensions,JSON_THROW_ON_ERROR)),'jit_disabled_with_opcache'=>ini_get('opcache.jit')==='disable'&&is_array($opcache)&&true===($opcache['opcache_enabled']??null)&&false===($opcache['jit']['enabled']??null)&&false===($opcache['jit']['on']??null)];
    echo json_encode(['format'=>'cetech-opening-http-owned-listener-v1','probe_sha256'=>hash('sha256',$token),'site_path_sha256'=>hash('sha256',realpath(ABSPATH)),'database_name_sha256'=>hash('sha256',DB_NAME),'source_head'=>getenv('CETECH_DE_QUALIFICATION_HEAD'),'candidate_head'=>getenv('CETECH_DE_QUALIFICATION_CANDIDATE_HEAD'),'source_tree'=>getenv('CETECH_DE_QUALIFICATION_TREE'),'runtime'=>$runtime], JSON_THROW_ON_ERROR); exit;
}
if ( ! is_file( $q05_path ) ) { return; }
function cetech_q05_state(): array {
	$path = getenv( 'CETECH_DE_HTTP_PROMOTION_CART_STATE' ); if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) || 0 !== ( fileperms( $path ) & 0077 ) ) { throw new RuntimeException( 'Q05 state is not private.' ); }
	$size=filesize($path); if (!is_int($size)||$size<2||$size>16777216) { throw new RuntimeException('N18 private state exceeds its finite bound.'); }
	$state = json_decode( file_get_contents( $path ), true, 32, JSON_THROW_ON_ERROR ); if ( ! is_array( $state ) || ( $state['site_path'] ?? null ) !== realpath( ABSPATH ) || ( $state['database_name'] ?? null ) !== DB_NAME || ! preg_match( '/\A[a-f0-9]{48}\z/D', (string) ( $state['fixture_token'] ?? '' ) ) ) { throw new RuntimeException( 'Q05 state binding is invalid.' ); } return $state;
}
function cetech_q05_write( array $state ): void {
	$path = getenv( 'CETECH_DE_HTTP_PROMOTION_CART_STATE' ); $temporary = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
	try { if ( false === file_put_contents( $temporary, json_encode( $state, JSON_THROW_ON_ERROR ) ) || ! chmod( $temporary, 0600 ) || ! rename( $temporary, $path ) ) { throw new RuntimeException( 'Q05 private tracking failed.' ); } } finally { if ( is_file( $temporary ) ) { unlink( $temporary ); } }
}
$q05_initial = cetech_q05_state(); if ( true === ( $q05_initial['cleanup_done'] ?? false ) ) { return; }
add_action('cetech_de_services_registered',static function($container):void {
    if (defined('WP_CLI')&&WP_CLI) { return; }
    if ('1'!==(string)get_option('cetech_opening_qualification_disposable')) { throw new RuntimeException('N18 marked site required.'); }
    $support=getenv('CETECH_DE_HTTP_N18_SUPPORT');
    if (!is_string($support)||!is_file($support)||is_link($support)||'opening-http-promotion-cart-support.php'!==basename($support)) { throw new RuntimeException('N18 support source unavailable.'); }
    require_once $support; global $wpdb; CetechPromotionCartHttpFixture::mount($container,$wpdb,cetech_q05_state());
});
add_action( 'template_redirect', static function (): void {
	if ( ! isset( $_GET['cetech_q05_fixture'] ) ) { return; }
	$state = cetech_q05_state(); $token = $_SERVER['HTTP_X_CETECH_Q05_FIXTURE'] ?? '';
	if ( ! is_string( $token ) || ! hash_equals( $state['fixture_token'], $token ) || ! in_array( get_current_user_id(), [ 0, $state['user_id'] ], true ) ) { wp_send_json_error( [ 'code' => 'fixture_forbidden' ], 403 ); }
	$mode = $_GET['cetech_q05_fixture']; if ( ! is_string( $mode ) || ! in_array( $mode, [ 'inspect', 'seed', 'price', 'place', 'placed' ], true ) ) { wp_send_json_error( [ 'code' => 'fixture_mode' ], 400 ); }
	if ( ! WC()->session || ! WC()->cart ) { wc_load_cart(); } WC()->session->set_customer_session_cookie( true );
	global $wpdb; $runtime = $GLOBALS['cetech_q05_review_runtime']; $sessions = $GLOBALS['cetech_q05_sessions'];
	if ('placed'===$mode) { WC()->cart->get_cart(); get_option('woocommerce_currency'); get_option('woocommerce_price_num_decimals'); wp_salt('auth'); wp_send_json_success(CetechPromotionCartHttpFixture::placed_history($state)); }
	if ('place'===$mode) {
		if ('POST'!==($_SERVER['REQUEST_METHOD']??'')||!is_string($_POST['nonce']??null)||!wp_verify_nonce($_POST['nonce'],'cetech_q05_fixture')) { wp_send_json_error(['code'=>'fixture_nonce'],403); }
		$GLOBALS['cetech_n18_placement_phase']='entry'; $GLOBALS['cetech_n18_placement_wp_error_returned']=false;
		$GLOBALS['cetech_n18_checkout_endpoint_verified']=null; $GLOBALS['cetech_n18_checkout_exception_observed']=false;
		add_action('woocommerce_checkout_order_exception','cetech_n18_observe_checkout_exception',PHP_INT_MAX,1);
		$failure=null;
		try {
			WC()->cart->get_cart(); get_option('woocommerce_currency'); get_option('woocommerce_price_num_decimals'); wp_salt('auth');
			$result=CetechPromotionCartHttpFixture::place($wpdb,$state);
		} catch (Throwable $error) {
			$failure=cetech_n18_placement_failure($error);
		} finally {
			remove_action('woocommerce_checkout_order_exception','cetech_n18_observe_checkout_exception',PHP_INT_MAX);
		}
		if (null!==$failure) { wp_send_json_error(['cetech_n18_placement_failure'=>$failure],503); return; }
		wp_send_json_success($result);
	}
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
