<?php

/** Private CLI observation of one pretracked real HTTP order; production owns all staff page actions. */
declare(strict_types=1);

$shipment_bridge_args = $args ?? null; unset( $args );
require_once __DIR__ . '/opening-http-promise-handoff-support.php';
if ( is_array( $shipment_bridge_args ) ) { $args = $shipment_bridge_args; } unset( $shipment_bridge_args );

use CetechDeliveryEngine\Application\Order\{OrderDeliverySnapshot, OrderDeliverySnapshotReader};
use CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Domain\Contracts\{OperationIdentity, RequestContext};
use CetechDeliveryEngine\Domain\ServicePromise\PublicPromiseView;
use CetechDeliveryEngine\Domain\ServicePromise\Shipment\ShipmentPromiseCommand;
use CetechDeliveryEngine\Domain\Shipment\{Shipment, ShipmentRepositoryInterface};
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Integrations\ServicePromise\Shipment\NativeShipmentPromiseRuntime;

final class CetechPromiseNativeConfigurationHttpShipmentFixture {
	private const TABLES = [ 'shipments', 'shipment_items', 'shipment_events', 'audit_log', 'shipment_promises', 'operation_records', 'operation_changes' ];

	private static function rows( string $suffix ): array { return CetechPromiseHandoffHttpFixture::rows( 'SELECT * FROM `' . TableNames::for( $suffix ) . '` ORDER BY id' ); }
	private static function all(): array { $out = []; foreach ( self::TABLES as $suffix ) { $out[$suffix] = self::rows( $suffix ); } return $out; }
	private static function save( array $state, string $path ): void { opening_http_write_json( $path, $state, true ); }
	private static function guard( array $state ): void {
		CetechQuotePlacementHttpFixture::guard( $state );
		if ( true !== ( $state['p05']['active'] ?? false ) || true !== ( $state['p04']['active'] ?? false ) || true !== ( $state['q06']['active'] ?? false ) || get_current_blog_id() !== $state['site_id'] || ! defined( 'WP_ADMIN' ) || true !== WP_ADMIN ) { throw new RuntimeException( 'P05 HTTP shipment fixture is inactive or unbound.' ); }
	}
	private static function order( array $state, int $id ): WC_Order {
		if ( ! in_array( $id, $state['q06']['orders'], true ) ) { throw new RuntimeException( 'P05 HTTP shipment refuses an untracked order.' ); }
		$order = wc_get_order( $id ); $paid = $order instanceof WC_Order ? $order->get_date_paid() : null;
		if ( ! $order instanceof WC_Order || $order->get_id() !== $id || $order->get_customer_id() !== $state['user_id'] || ! $order->is_paid() || ! $paid instanceof DateTimeInterface || $paid->getTimestamp() < 1 || ! CetechQuotePlacementHttpFixture::sealed( $order ) ) { throw new RuntimeException( 'P05 HTTP shipment requires an exact owned paid sealed order.' ); }
		$read = ( new OrderDeliverySnapshotReader() )->read_package( $order ); $envelope = $read->delivery_quote?->envelope;
		if ( '' !== $read->error || null === $envelope || ! $envelope->is_promise() ) { throw new RuntimeException( 'P05 HTTP shipment original packet unavailable.' ); }
		foreach ( $envelope->promise_packet()->private_facts()['groups'] as $group ) { if ( $group['packet']['input_json'] === '' || json_decode( $group['packet']['input_json'], true, 512, JSON_THROW_ON_ERROR )['site_id'] !== $state['p04']['token'] ) { throw new RuntimeException( 'P05 HTTP shipment foreign original packet.' ); } }
		return $order;
	}
	private static function snapshot( WC_Order $order ): string {
		$items = []; foreach ( $order->get_items( 'line_item' ) as $item ) { $items[] = [ 'id' => $item->get_id(), 'snapshot' => $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ), 'amount' => $item->get_total(), 'tax' => $item->get_total_tax() ]; }
		return opening_http_hash( [ 'snapshot' => $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true ), 'currency' => $order->get_currency(), 'total' => $order->get_total(), 'shipping' => $order->get_shipping_total(), 'tax' => $order->get_total_tax(), 'items' => $items ] );
	}
	private static function repository(): ShipmentRepositoryInterface { return Plugin::instance()->container()->get( ShipmentRepositoryInterface::class ); }
	private static function shipment( array $state ): Shipment {
		$fixture = $state['p05_http_shipment']; $shipment = self::repository()->findById( $fixture['shipment_id'] );
		if ( ! $shipment instanceof Shipment || $shipment->order_id !== $fixture['order_id'] || $shipment->delivery_group_id !== $fixture['group_id'] ) { throw new RuntimeException( 'P05 HTTP shipment aggregate identity changed.' ); }
		return $shipment;
	}
	private static function original_identity( array $row ): array {
		$out = []; foreach ( [ 'shipment_id', 'order_id', 'delivery_group_id', 'original_json', 'original_digest', 'packet_json', 'packet_digest', 'original_namespace_hash', 'original_intent_hash', 'created_at' ] as $field ) { $out[$field] = $row[$field]; } return $out;
	}
	private static function register_receipts( array &$state, array $row ): void {
		global $wpdb; $fixture = &$state['p05_http_shipment'];
		foreach ( [ 'original' => ShipmentPromiseCommand::CREATE, 'current' => ShipmentPromiseCommand::UPDATE ] as $role => $operation ) {
			$namespace = $row[$role . '_namespace_hash']; if ( null === $namespace ) { continue; }
			if ( ( $fixture['namespaces'][$namespace] ?? null ) !== $operation ) { throw new RuntimeException( 'P05 HTTP shipment receipt lacked a predeclared native command namespace.' ); }
			$records = CetechPromiseHandoffHttpFixture::rows( $wpdb->prepare( 'SELECT * FROM `' . TableNames::for( 'operation_records' ) . '` WHERE site_id=%d AND namespace_hash=%s LIMIT 2', $state['site_id'], $namespace ) );
			if ( 1 !== count( $records ) || $records[0]['operation'] !== $operation || $records[0]['target_hash'] !== $fixture['target_hash'] || 'accepted' !== $records[0]['state'] || in_array( $records[0]['id'], array_column( $fixture['before']['operation_records'], 'id' ), true ) ) { throw new RuntimeException( 'P05 HTTP shipment receipt cleanup authority unavailable.' ); }
		}
	}
	public static function prepare( array &$state, string $path ): array {
		global $wpdb; self::guard( $state );
		if ( isset( $state['p05_http_shipment'] ) ) { throw new RuntimeException( 'P05 HTTP shipment fixture already prepared.' ); }
		$order = null; foreach ( array_reverse( $state['q06']['orders'] ) as $id ) { try { $candidate = self::order( $state, $id ); if ( [] === self::repository()->findByOrderId( $id ) ) { $order = $candidate; break; } } catch ( Throwable ) {} }
		if ( ! $order instanceof WC_Order ) { throw new RuntimeException( 'P05 HTTP shipment has no eligible pretracked paid order without an aggregate.' ); }
		$read = ( new OrderDeliverySnapshotReader() )->read_package( $order ); $groups = $read->snapshot?->groups;
		if ( ! is_array( $groups ) || 1 !== count( $groups ) || ! $groups[0] instanceof CetechDeliveryEngine\Application\Order\OrderDeliveryGroupSnapshot ) { throw new RuntimeException( 'P05 HTTP shipment requires one exact historical group.' ); }
		$group = $groups[0]->group_id; $target = 'shipment-promise:' . $order->get_id() . ':' . hash( 'sha256', $group );
		$original_key = hash( 'sha256', 'original:' . $read->delivery_quote->envelope->to_private_json() ); $identity = new OperationIdentity( $state['site_id'], 'native-shipment-promise', 'staff:1', ShipmentPromiseCommand::CREATE, 1, $target, $original_key );
		$state['p05_http_shipment'] = [ 'active' => true, 'order_id' => $order->get_id(), 'shipment_id' => null, 'group_id' => $group, 'target_hash' => hash( 'sha256', 'cetech-operation-target-v1:' . $target ), 'before' => self::all(), 'namespaces' => [ $identity->namespace_digest() => ShipmentPromiseCommand::CREATE ], 'snapshot_hash' => self::snapshot( $order ), 'original' => null ]; self::save( $state, $path );
		$native = CetechQuoteCartHttpFixture::hydrate_native( $wpdb, $state['native'] ); foreach ( [ 'cetech_de_enable_shipment_records', 'cetech_de_enable_customer_order_delivery_summary' ] as $name ) { $native->set_option( $name, '1' ); } $state['native'] = CetechQuoteCartHttpFixture::export_native( $native ); self::save( $state, $path );
		$actor = get_current_user_id(); wp_set_current_user( 1 );
		try {
			if ( ! current_user_can( 'manage_shipments' ) || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) { throw new RuntimeException( 'P05 HTTP shipment actual administrator unavailable.' ); }
			$result = Plugin::instance()->container()->get( ShipmentService::class )->create_from_historical_order_for_staff( $order, 1 );
			$list = self::repository()->findByOrderId( $order->get_id() ); if ( ! $result->outcome->is_success() || 1 !== count( $list ) ) { throw new RuntimeException( 'P05 HTTP shipment mounted original aggregate did not acknowledge.' ); }
			$shipment = $list[0]; $fixture = &$state['p05_http_shipment']; $fixture['shipment_id'] = $shipment->id; $fixture['group_id'] = $shipment->delivery_group_id; $fixture['target_hash'] = hash( 'sha256', 'cetech-operation-target-v1:' . ShipmentPromiseCommand::target_key( $shipment ) ); self::save( $state, $path );
			$out = self::inspect( $state, $path ); if ( ! $out['snapshot_preserved'] || 2 !== $out['revision'] ) { throw new RuntimeException( 'P05 HTTP shipment original checkpoint changed.' ); }
			return $out;
		} finally { wp_set_current_user( $actor ); }
	}
	public static function track_staff( array &$state, string $path, string $token ): array {
		self::guard( $state ); if ( ! RequestContext::is_valid_identifier( $token ) || true !== ( $state['p05_http_shipment']['active'] ?? false ) ) { throw new RuntimeException( 'P05 HTTP shipment staff command declaration refused.' ); }
		$shipment = self::shipment( $state ); $owned = $state['p05']['admin']; $user = get_userdata( $owned['id'] );
		if ( ! $user || $user->user_login !== $owned['login'] || ! str_starts_with( $owned['login'], 'p05_admin_' ) ) { throw new RuntimeException( 'P05 HTTP shipment staff command actor is not the tracked administrator.' ); }
		$identity = new OperationIdentity( $state['site_id'], 'native-shipment-promise', 'staff:' . $owned['id'], ShipmentPromiseCommand::UPDATE, 1, ShipmentPromiseCommand::target_key( $shipment ), $token );
		$state['p05_http_shipment']['namespaces'][$identity->namespace_digest()] = ShipmentPromiseCommand::UPDATE; self::save( $state, $path ); return [ 'ready' => true, 'exact_staff_namespace_predeclared' => true ];
	}
	public static function inspect( array &$state, string $path ): array {
		self::guard( $state ); $fixture = &$state['p05_http_shipment']; $order = self::order( $state, $fixture['order_id'] ); $shipment = self::shipment( $state );
		$actor = get_current_user_id(); wp_set_current_user( 1 );
		try { $service = Plugin::instance()->container()->get( NativeShipmentPromiseRuntime::class )->service_for_order( $order ); $stored = $service?->read_for_order( $shipment, $order ); } finally { wp_set_current_user( $actor ); }
		if ( null === $stored ) { throw new RuntimeException( 'P05 HTTP shipment acknowledged original/current read unavailable.' ); }
		$row = $stored->row(); self::register_receipts( $state, $row ); $original = self::original_identity( $row ); if ( null === $fixture['original'] ) { $fixture['original'] = $original; } self::save( $state, $path );
		$current = $stored->current()?->public_facts(); $text = null === $current ? __( 'No separate current estimate has been recorded.', 'cetech-woocommerce-delivery-engine' ) : ( 'unavailable' === $current['state'] ? __( 'Current estimate unavailable.', 'cetech-woocommerce-delivery-engine' ) : implode( '; ', array_map( static fn( array $view ): string => ( new PublicPromiseFormatter() )->text( PublicPromiseView::from_array( [ 'format_version' => 1, 'state' => 'absolute_window', 'reason_codes' => [] ] + $view ) ), $current['views'] ) ) );
		$history = []; foreach ( self::all() as $suffix => $rows ) { $prior = array_fill_keys( array_column( $fixture['before'][$suffix], 'id' ), true ); $history[$suffix] = array_values( array_filter( $rows, static fn( array $r ): bool => ! isset( $prior[$r['id']] ) ) ); }
		return [ 'ready' => true, 'order_id' => $order->get_id(), 'shipment_id' => $shipment->id, 'view_order_url' => wc_get_endpoint_url( 'view-order', (string) $order->get_id(), wc_get_page_permalink( 'myaccount' ) ), 'received_url' => $order->get_checkout_order_received_url(), 'revision' => $row['revision'], 'original_text' => $stored->original_public()['customer_text'], 'current_text' => $text, 'current' => $current, 'reason_sha256' => null === $stored->current() ? null : hash( 'sha256', $stored->current()->private_facts()['reason'] ), 'snapshot_preserved' => $fixture['snapshot_hash'] === self::snapshot( $order ), 'original_preserved' => $fixture['original'] === $original, 'material_hash' => opening_http_hash( $history ) ];
	}
	public static function cleanup( array &$state, string $path ): array {
		global $wpdb; CetechQuotePlacementHttpFixture::guard( $state );
		if ( ! isset( $state['p05_http_shipment'] ) ) { return [ 'owned_shipment_rows_removed' => true, 'owned_operation_history_removed' => true, 'original_order_preserved' => true, 'all_prior_rows_restored' => true ]; }
		$fixture = &$state['p05_http_shipment']; if ( true === ( $fixture['cleanup_done'] ?? false ) ) { return $fixture['cleanup']; }
		self::guard( $state );
		if ( null === $fixture['shipment_id'] ) {
			$list = self::repository()->findByOrderId( $fixture['order_id'] );
			if ( 1 === count( $list ) && $list[0]->order_id === $fixture['order_id'] && $list[0]->delivery_group_id === $fixture['group_id'] && ! in_array( (string) $list[0]->id, array_column( $fixture['before']['shipments'], 'id' ), true ) ) { $fixture['shipment_id'] = $list[0]->id; self::save( $state, $path ); }
			elseif ( [] === $list ) {
				$order = self::order( $state, $fixture['order_id'] ); if ( $fixture['snapshot_hash'] !== self::snapshot( $order ) ) { throw new RuntimeException( 'P05 HTTP shipment failed preparation changed its original order.' ); }
				$ok = self::remove_operations( $state ); $prior = array_fill_keys( array_column( $fixture['before']['audit_log'], 'id' ), true );
				foreach ( self::rows( 'audit_log' ) as $row ) { if ( ! isset( $prior[$row['id']] ) && 'order' === $row['entity_type'] && (int) $row['entity_id'] === $fixture['order_id'] && 'shipment_creation_failed' === $row['action'] ) { $ok = 1 === $wpdb->delete( TableNames::for( 'audit_log' ), [ 'id' => (int) $row['id'] ] ) && $ok; } }
				return self::finish_cleanup( $state, $path, $ok, $order );
			} else { throw new RuntimeException( 'P05 HTTP shipment incomplete preparation cannot widen cleanup authority.' ); }
		}
		$inspection = self::inspect( $state, $path ); if ( ! $inspection['snapshot_preserved'] || ! $inspection['original_preserved'] ) { throw new RuntimeException( 'P05 HTTP shipment refuses mutated historical original cleanup.' ); }
		$shipment = self::shipment( $state ); $allowed = [];
		foreach ( [ 'shipment_promises', 'shipment_events', 'shipment_items', 'audit_log', 'shipments' ] as $suffix ) {
			$prior = array_fill_keys( array_column( $fixture['before'][$suffix], 'id' ), true ); $allowed[$suffix] = [];
			foreach ( self::rows( $suffix ) as $row ) {
				if ( isset( $prior[$row['id']] ) ) { continue; }
				$own = 'shipments' === $suffix ? ( (int) $row['id'] === $shipment->id && (int) $row['order_id'] === $shipment->order_id && $row['delivery_group_id'] === $shipment->delivery_group_id ) : ( 'audit_log' === $suffix ? ( 'order' === $row['entity_type'] && (int) $row['entity_id'] === $shipment->order_id && in_array( $row['action'], [ 'shipment_creation_failed', 'shipment_creation_succeeded', 'shipment_creation_idempotent' ], true ) ) : ( (int) $row['shipment_id'] === $shipment->id ) );
				if ( ! $own ) { continue; } if ( 'shipment_promises' === $suffix && ( (int) $row['site_id'] !== $state['site_id'] || (int) $row['order_id'] !== $shipment->order_id || $row['site_key'] !== $state['p04']['token'] || $row['delivery_group_id'] !== $shipment->delivery_group_id ) ) { throw new RuntimeException( 'P05 HTTP shipment foreign original cleanup refused.' ); }
				$allowed[$suffix][] = $row;
			}
		}
		$ok = self::remove_operations( $state );
		foreach ( $allowed as $suffix => $rows ) { foreach ( $rows as $row ) { $ok = 1 === $wpdb->delete( TableNames::for( $suffix ), [ 'id' => (int) $row['id'] ] ) && $ok; } }
		return self::finish_cleanup( $state, $path, $ok, self::order( $state, $fixture['order_id'] ) );
	}
	private static function remove_operations( array $state ): bool {
		global $wpdb; $fixture = $state['p05_http_shipment']; $ok = true; $prior = array_fill_keys( array_column( $fixture['before']['operation_records'], 'id' ), true );
		foreach ( $fixture['namespaces'] as $namespace => $operation ) {
			$records = CetechPromiseHandoffHttpFixture::rows( $wpdb->prepare( 'SELECT * FROM `' . TableNames::for( 'operation_records' ) . '` WHERE site_id=%d AND namespace_hash=%s LIMIT 2', $state['site_id'], $namespace ) );
			if ( [] === $records ) { continue; }
			if ( 1 !== count( $records ) || isset( $prior[$records[0]['id']] ) || $records[0]['operation'] !== $operation || $records[0]['target_hash'] !== $fixture['target_hash'] ) { throw new RuntimeException( 'P05 HTTP shipment exact operation cleanup refused.' ); }
			$ok = false !== $wpdb->delete( TableNames::for( 'operation_changes' ), [ 'site_id' => $state['site_id'], 'operation_id' => (int) $records[0]['id'] ] ) && $ok;
			$ok = 1 === $wpdb->delete( TableNames::for( 'operation_records' ), [ 'site_id' => $state['site_id'], 'id' => (int) $records[0]['id'] ] ) && $ok;
		}
		return $ok;
	}
	private static function finish_cleanup( array &$state, string $path, bool $ok, WC_Order $order ): array {
		$fixture = &$state['p05_http_shipment'];
		$restored = true; foreach ( $fixture['before'] as $suffix => $rows ) { $restored = $rows === self::rows( $suffix ) && $restored; }
		$preserved = $fixture['snapshot_hash'] === self::snapshot( $order );
		$fixture['active'] = false; $fixture['cleanup_done'] = true; $fixture['cleanup'] = [ 'owned_shipment_rows_removed' => $ok && [] === self::repository()->findByOrderId( $order->get_id() ), 'owned_operation_history_removed' => $ok, 'original_order_preserved' => $preserved, 'all_prior_rows_restored' => $restored ]; self::save( $state, $path ); return $fixture['cleanup'];
	}
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! isset( $args ) || 3 !== count( $args ) ) { throw new RuntimeException( 'P05 HTTP shipment helper is private CLI only.' ); }
require_once __DIR__ . '/opening-http-fixture-common.php';
[ $mode, $state_path, $output_path ] = $args; $state = opening_http_read_state( $state_path );
$output = str_starts_with( $mode, 'trackhttpstaff:' ) ? CetechPromiseNativeConfigurationHttpShipmentFixture::track_staff( $state, $state_path, substr( $mode, strlen( 'trackhttpstaff:' ) ) ) : match ( $mode ) { 'preparehttpafterbrowser' => CetechPromiseNativeConfigurationHttpShipmentFixture::prepare( $state, $state_path ), 'inspecthttpshipment' => CetechPromiseNativeConfigurationHttpShipmentFixture::inspect( $state, $state_path ), 'cleanuphttpshipment' => CetechPromiseNativeConfigurationHttpShipmentFixture::cleanup( $state, $state_path ), default => throw new RuntimeException( 'P05 HTTP shipment helper mode refused.' ) };
opening_http_write_json( $output_path, $output );
