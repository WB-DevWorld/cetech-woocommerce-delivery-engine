<?php

declare(strict_types=1);

require __DIR__ . '/opening-http-fixture-common.php';
require_once __DIR__ . '/opening-emergency-fixture-support.php';

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlCommand;
use CetechDeliveryEngine\Bootstrap\DataLifecycleManifest;

function opening_c07_snapshot( CetechOpeningEmergencyFixture $fixture ): array {
	$state = $fixture->state; $control = CetechOpeningEmergencyFixture::service()->read( $state['site_id'] );
	$rows = CetechOpeningEmergencyFixture::option( CetechOpeningEmergencyFixture::CONTROL );
	$records = CetechOpeningEmergencyFixture::rows( 'operation_records' ); $changes = CetechOpeningEmergencyFixture::rows( 'operation_changes' );
	$owned = array_values( array_filter( $records, static fn ( array $row ): bool => EmergencyControlCommand::OPERATION === $row['operation'] && in_array( $row['namespace_hash'], $state['namespaces'], true ) ) );
	$physical = CetechOpeningEmergencyFixture::order_bytes( $state['orders'] ); $preserved = [];
	foreach ( DataLifecycleManifest::DOMAIN_TABLE_SUFFIXES as $suffix ) { if ( ! in_array( $suffix, [ 'operation_records', 'operation_changes' ], true ) ) { $preserved[ $suffix ] = CetechOpeningEmergencyFixture::hash( CetechOpeningEmergencyFixture::rows( $suffix ) ); } }
	$grants = opening_http_grants( $state['user_id'], $state['role'], [ 'read', 'view_admin_dashboard', 'view_delivery_engine', 'manage_delivery_settings', 'view_delivery_diagnostics' ] );
	$orders = [];
	foreach ( $state['orders'] as $id ) { $order = wc_get_order( $id ); if ( $order instanceof WC_Order ) { $order->read_meta_data( true ); $orders[ (string) $id ] = [ 'status' => $order->get_status(), 'total' => $order->get_total(), 'currency' => $order->get_currency(), 'paid' => null !== $order->get_date_paid(), 'needs_payment' => $order->needs_payment() ]; } }
	return [ 'ready' => $control->available, 'state' => $control->state?->state, 'revision' => $control->state?->revision, 'initialized' => $control->state?->initialized, 'control_hash' => null === $rows ? null : hash( 'sha256', $rows['option_value'] ), 'autoload' => $rows['autoload'] ?? null, 'records_count' => count( $owned ), 'events_count' => count( array_filter( $changes, static fn ( array $row ): bool => in_array( $row['operation_id'], array_column( $owned, 'id' ), true ) ) ), 'gateway_count' => (int) get_option( CetechOpeningEmergencyFixture::GATEWAY_COUNT, 0 ), 'order_hash' => CetechOpeningEmergencyFixture::hash( $physical ), 'protected_order_hash' => CetechOpeningEmergencyFixture::hash( CetechOpeningEmergencyFixture::order_bytes( $state['protected_orders'] ?? $state['orders'] ) ), 'orders' => $orders, 'preserved_hashes' => $preserved, 'grants' => $grants['native'], 'grants_match_physical' => $grants['match'], 'barrier' => get_option( CetechOpeningEmergencyFixture::BARRIER, null ), 'coming_soon' => $fixture->coming_soon_facts() ];
}

if ( ! isset( $args ) || count( $args ) < 3 ) { throw new RuntimeException( 'C07 fixture expects MODE PRIVATE_STATE OUTPUT [VALUE].' ); }
[ $mode, $state_path, $output_path ] = $args;
$allowed = [ 'prepareemergency', 'snapshotemergency', 'emergencycaps', 'vendorprincipalemergency', 'staffprincipalemergency', 'pauseemergency', 'resumeemergency', 'armclassicemergency', 'armstoreemergency', 'armemptyemergency', 'blockspageemergency', 'classicpageemergency', 'disableflagsemergency', 'restoreflagsemergency', 'ratechangeemergency', 'restorepriceemergency', 'trackemergency', 'cleanupemergency' ];
if ( ! in_array( $mode, $allowed, true ) ) { throw new RuntimeException( 'Unknown C07 fixture mode.' ); }
$original_user = get_current_user_id();
if ( 'prepareemergency' === $mode ) {
	if ( file_exists( $state_path ) ) { throw new RuntimeException( 'Refusing to replace a C07 credential state.' ); }
	$fixture = new CetechOpeningEmergencyFixture();
	try {
		$fixture->prepare(); wp_set_current_user( $fixture->state['user_id'] );
		$fixture->state += [ 'probe_token' => getenv( 'CETECH_DE_HTTP_PROBE_TOKEN' ), 'fixture_token' => bin2hex( random_bytes( 24 ) ), 'base_url' => 'http://127.0.0.1:8085', 'identity' => opening_http_identity(), 'support_file' => realpath( __DIR__ . '/opening-emergency-fixture-support.php' ), 'store_checkout_url' => rest_url( 'wc/store/v1/checkout' ) ];
		foreach ( [ 'new' => [ false, false ], 'legacy' => [ true, false ], 'missing' => [ false, true ], 'ordinary' => [ false, false ] ] as $kind => [ $legacy, $missing ] ) {
			$order = $fixture->order( 'ordinary' === $kind ? 'unmanaged' : 'managed', $legacy, $missing ); $fixture->state[ $kind . '_order_id' ] = $order->get_id(); $fixture->state[ $kind . '_order_pay_url' ] = $order->get_checkout_payment_url();
		}
		$fixture->state['protected_orders'] = $fixture->state['orders'];
		opening_http_write_json( $state_path, $fixture->state, true );
		opening_http_write_json( $output_path, [ 'identity' => $fixture->state['identity'], 'snapshot' => opening_c07_snapshot( $fixture ) ] );
	} catch ( Throwable $error ) { if ( isset( $fixture->state['user_id'] ) ) { opening_http_write_json( $state_path, $fixture->state, true ); } throw $error; }
	finally { wp_set_current_user( 0 ); wp_set_current_user( $original_user ); }
	return;
}
$fixture = new CetechOpeningEmergencyFixture( opening_http_read_state( $state_path ) );
wp_set_current_user( $fixture->state['user_id'] );
try {
	if ( 'cleanupemergency' === $mode ) {
		$output = $fixture->cleanup(); opening_http_write_json( $output_path, $output ); return;
	}
	if ( 'vendorprincipalemergency' === $mode || 'staffprincipalemergency' === $mode ) { $fixture->state['restricted_vendor'] = 'vendorprincipalemergency' === $mode; }
	if ( 'emergencycaps' === $mode ) {
		$role = get_role( $fixture->state['role'] ); if ( ! $role instanceof WP_Role ) { throw new RuntimeException( 'C07 fixture role no longer exists.' ); }
		if ( '1' === (string) ( $args[3] ?? '' ) ) { $role->add_cap( 'manage_delivery_settings', true ); } else { $role->remove_cap( 'manage_delivery_settings' ); }
		wp_set_current_user( 0 ); clean_user_cache( $fixture->state['user_id'] ); wp_set_current_user( $fixture->state['user_id'] );
	}
	if ( 'pauseemergency' === $mode || 'resumeemergency' === $mode ) {
		$result = $fixture->transition( 'pauseemergency' === $mode ? 'checkout_suspended' : 'enabled', 'pauseemergency' === $mode ? 'operator_pause' : 'resume_verified' );
		if ( ! in_array( $result->outcome->state, [ 'accepted', 'not_applicable' ], true ) ) { throw new RuntimeException( 'C07 fixture transition was not confirmed.' ); }
	}
	if ( in_array( $mode, [ 'armclassicemergency', 'armstoreemergency', 'armemptyemergency' ], true ) ) { update_option( CetechOpeningEmergencyFixture::BARRIER, [ 'phase' => match ( $mode ) { 'armclassicemergency' => 'classic_after_validation', 'armstoreemergency' => 'store_after_update', default => 'classic_empty_cart' }, 'armed' => true, 'triggered' => false ], false ); }
	if ( 'blockspageemergency' === $mode ) { update_option( 'woocommerce_checkout_page_id', $fixture->state['blocks_page_id'], false ); }
	if ( 'classicpageemergency' === $mode ) { update_option( 'woocommerce_checkout_page_id', $fixture->state['classic_page_id'], false ); }
	if ( 'disableflagsemergency' === $mode || 'restoreflagsemergency' === $mode ) { foreach ( [ 'enable_classic_checkout_adapter', 'enable_woocommerce_shipping_rate_calculation', 'enable_order_delivery_snapshot_persistence' ] as $flag ) { update_option( 'cetech_de_' . $flag, 'restoreflagsemergency' === $mode ? 1 : 0, false ); } }
	if ( 'ratechangeemergency' === $mode || 'restorepriceemergency' === $mode ) {
		global $wpdb; $wpdb->update( CetechDeliveryEngine\Infrastructure\Persistence\TableNames::for( 'rate_cards' ), [ 'base_amount' => 'ratechangeemergency' === $mode ? '9.0000' : '7.0000' ], [ 'id' => $fixture->state['rate_id'] ] );
	}
	if ( 'trackemergency' === $mode ) { $token = (string) ( $args[3] ?? '' ); if ( ! CetechDeliveryEngine\Domain\Contracts\RequestContext::is_valid_identifier( $token ) ) { throw new RuntimeException( 'C07 fixture tracking token is invalid.' ); } $fixture->state['namespaces'][] = EmergencyControlCommand::identity( $fixture->state['site_id'], $fixture->state['user_id'], $token )->namespace_digest(); }
	opening_http_write_json( $state_path, $fixture->state, true ); opening_http_write_json( $output_path, [ 'snapshot' => opening_c07_snapshot( $fixture ) ] );
} finally { wp_set_current_user( 0 ); wp_set_current_user( $original_user ); }
