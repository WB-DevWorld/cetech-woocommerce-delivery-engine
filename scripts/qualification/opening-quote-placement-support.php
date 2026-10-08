<?php

declare(strict_types=1);

require_once __DIR__ . '/opening-quote-cart-support.php';

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory as QuotePlacementFactoryContract;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnection;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionMysqliTransport;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionResult;
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionTransport;

/** Only the marked disposable database may receive placement fault injection. */
final class CetechQuotePlacementFactory implements QuotePlacementFactoryContract {

	private array $sessions = [];
	public int $binding_writes = 0;
	public int $binding_commits = 0;
	public int $private_quote_reads = 0;
	public int $masked_binding_acks = 0;
	public int $verified_clocks = 0;
	public ?int $fault_connection = null;
	public bool $mask_next_binding_ack = false;
	public ?int $mask_binding_commit_number = null;
	public ?QuoteTime $clock = null;
	public array $timeline = [];

	public function __construct( private wpdb $db ) {
		if ( '1' !== (string) get_option( 'cetech_opening_qualification_disposable' ) || ! preg_match( '/\A127\.0\.0\.1(?::[0-9]+)?\z/D', DB_HOST ) || ! preg_match( '/\Acetech_wp_opening_qualification(?:_[a-z0-9]+)?\z/D', DB_NAME ) ) { throw new RuntimeException( 'Quote placement fixture refused its database.' ); }
	}

	public function open(): OperationSession {
		$host = $this->db->parse_db_host( DB_HOST );
		if ( ! is_array( $host ) ) { throw new RuntimeException( 'Quote placement fixture connection unavailable.' ); }
		$native = OperationConnectionMysqliTransport::connect( $host[0], DB_USER, DB_PASSWORD, DB_NAME, $host[1] ?: 3306, $host[2], $this->db->charset ?: 'utf8mb4', defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 );
		if ( null !== $this->clock ) {
			$epoch = $this->clock->epoch_microseconds(); $timestamp = intdiv( $epoch, 1000000 ) . '.' . str_pad( (string) ( $epoch % 1000000 ), 6, '0', STR_PAD_LEFT );
			$set = $native->execute( 'SET timestamp=' . $timestamp ); $read = $native->execute( 'SELECT UTC_TIMESTAMP(6) AS utc' );
			if ( ! $set->acknowledged || ! $read->acknowledged || [ [ 'utc' => $this->clock->sql() ] ] !== $read->rows ) { $native->close(); throw new RuntimeException( 'Quote placement fixture SQL clock unavailable.' ); }
			++$this->verified_clocks;
		}
		$session = new OperationConnection( get_current_blog_id(), $this->db->prefix, new CetechQuotePlacementTransport( $native, $this, $this->db->prefix ), $this->db->charset ?: 'utf8mb4', $this->db->collate ?: '' );
		$this->sessions[$native->connection_id()] = $session;
		return $session;
	}

	public function close_all(): bool { $ok = true; foreach ( $this->sessions as $session ) { if ( $session->in_transaction() ) { $ok = $session->rollback() && $ok; } $ok = $session->retire() && $ok; } return $ok; }
	public function all_retired(): bool { foreach ( $this->sessions as $session ) { if ( ! $session->is_retired() ) { return false; } } return true; }
	public function fault_retired(): bool { return null !== $this->fault_connection && isset( $this->sessions[$this->fault_connection] ) && $this->sessions[$this->fault_connection]->is_retired(); }
}

/** Mask only a sent and acknowledged actual binding-effect COMMIT; never a read/reservation. */
final class CetechQuotePlacementTransport implements OperationConnectionTransport {
	private bool $binding_effect = false;
	public function __construct( private OperationConnectionTransport $native, private CetechQuotePlacementFactory $factory, private string $prefix ) {}
	public function execute( string $sql ): OperationConnectionResult {
		if ( preg_match( '/\A\s*START\s+TRANSACTION\b/i', $sql ) ) { $this->binding_effect = false; }
		$result = $this->native->execute( $sql );
		if ( $result->sent && preg_match( '/\A\s*SELECT\b/i', $sql ) && preg_match( '/\b(?:FROM|JOIN)\s+`?' . preg_quote( $this->prefix . 'delivery_engine_delivery_quote', '/' ) . '(?:s|_bindings)`?\b/i', $sql ) ) { ++$this->factory->private_quote_reads; }
		if ( $result->acknowledged && preg_match( '/\A\s*(?:INSERT\s+INTO|UPDATE)\s+`?' . preg_quote( $this->prefix . 'delivery_engine_delivery_quote_bindings', '/' ) . '`?\b/i', $sql ) ) { $this->binding_effect = true; ++$this->factory->binding_writes; $this->factory->timeline[] = 'actual_binding_write'; }
		if ( preg_match( '/\A\s*(?:ROLLBACK|COMMIT)\b/i', $sql ) ) {
			$committed_binding = $result->acknowledged && $this->binding_effect && preg_match( '/\A\s*COMMIT\b/i', $sql );
			$this->binding_effect = false;
			if ( $committed_binding ) {
				++$this->factory->binding_commits; $this->factory->timeline[] = 'actual_binding_commit';
				if ( $this->factory->mask_next_binding_ack || $this->factory->mask_binding_commit_number === $this->factory->binding_commits ) { $this->factory->mask_next_binding_ack = false; $this->factory->mask_binding_commit_number = null; ++$this->factory->masked_binding_acks; $this->factory->fault_connection = $this->native->connection_id(); $this->factory->timeline[] = 'sent_binding_ack_masked'; return new OperationConnectionResult( false, true, errno: 2013 ); }
			}
		}
		return $result;
	}
	public function connection_id(): int { return $this->native->connection_id(); }
	public function transaction_state(): ?array { return $this->native->transaction_state(); }
	public function escape( string $value ): string { return $this->native->escape( $value ); }
	public function close(): bool { return $this->native->close(); }
}

/** Native receipt protocol discloses only typed observations, never raw rows/keys/tokens. */
final class CetechQuotePlacementObservation {
	public const BOOLS = [ 'actual_installed_native_wp', 'actual_native_woo_order', 'actual_native_checkout_create_order', 'actual_hpos_enabled', 'acknowledged', 'binding_prepared', 'binding_verified', 'binding_sealed', 'same_original_binding', 'snapshot_bytes_unchanged', 'quote_body_unchanged', 'historical_reader_supported', 'native_logical_mapping_matches', 'native_items_saved', 'original_envelope_reconciled', 'uncertain_connection_retired', 'final_admission_denied', 'no_payment_invoked', 'no_second_binding_effect', 'local_mutation_detected', 'physical_mutation_detected', 'late_pause_won', 'late_expiry_won', 'owned_connections_retired', 'cleanup_restored', 'owned_orders_removed', 'quote_operation_history_restored', 'native_shipping_cache_hit', 'rate_references_attached', 'native_rate_money_unchanged', 'native_rate_objects_unchanged', 'original_rejection_receipt_unchanged' ];
	public const COUNTERS = [ 'native_line_count', 'native_shipping_count', 'binding_writes', 'binding_commits', 'masked_binding_acks', 'verified_sql_clocks', 'gateway_calls', 'free_completion_calls' ];
	public static function valid( array $facts ): bool {
		if ( [] === $facts || count( $facts ) > count( self::BOOLS ) + count( self::COUNTERS ) ) { return false; }
		foreach ( $facts as $name => $value ) {
			if ( in_array( $name, self::BOOLS, true ) ) { if ( ! is_bool( $value ) ) { return false; } }
			elseif ( in_array( $name, self::COUNTERS, true ) ) { if ( ! is_int( $value ) || $value < 0 || $value > 100000 ) { return false; } }
			else { return false; }
		}
		return true;
	}
	public static function error_class( Throwable $error ): string { return $error instanceof Error ? 'Error' : ( $error instanceof InvalidArgumentException ? 'InvalidArgumentException' : 'RuntimeException' ); }
}

if ( class_exists( 'WC_Payment_Gateway' ) && ! class_exists( 'CetechQuotePlacementNativeGateway', false ) ) {
	class CetechQuotePlacementNativeGateway extends \WC_Payment_Gateway {
		private int $native_payment_calls = 0;
		public function native_payment_calls(): int { return $this->native_payment_calls; }
		public function __construct() { $this->id = 'cetech_q06_native_gateway'; $this->title = 'Q06 native qualification payment'; $this->enabled = 'yes'; $this->has_fields = false; $this->supports = [ 'products' ]; }
		public function is_available() { return true; }
		public function process_payment( $order_id ) { ++$this->native_payment_calls; $order = wc_get_order( $order_id ); if ( ! $order instanceof \WC_Order ) { throw new \RuntimeException( 'Native Q06 paid order unavailable.' ); } $order->payment_complete( 'Q06-SYNTHETIC-TRANSACTION' ); return [ 'result' => 'success', 'redirect' => $this->get_return_url( $order ) ]; }
	}
}

/** Native tracked order/group/item relationships confer deletion authority; new IDs alone never do. */
final class CetechQuotePlacementOperationalCleanup {
    public static function plan(array $before, array $owned_orders, array $rows): array {
        $authorized = array_fill_keys(['shipments', 'shipment_items', 'shipment_events', 'audit_log'], []); $complete = true; $shipments = [];
        $id = static function(mixed $value): ?int { return (is_int($value) && $value > 0 || is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $value)) ? (int)$value : null; };
        $new = static function(string $suffix, array $row) use ($before, $id): bool { $value = $id($row['id'] ?? null); return null === $value || !in_array($value, array_map($id, $before[$suffix] ?? []), true); };
        foreach ($rows['shipments'] ?? [] as $row) {
            if (!$new('shipments', $row)) { continue; } $shipment = $id($row['id'] ?? null); $order = $id($row['order_id'] ?? null); $group = $row['delivery_group_id'] ?? null;
            $owned = null !== $shipment && null !== $order && isset($owned_orders[$order]) && is_string($group) && in_array($group, $owned_orders[$order]['groups'], true)
                && ($row['idempotency_key'] ?? null) === CetechDeliveryEngine\Domain\Shipment\ShipmentIdentity::key($order, $group)
                && ($row['shipment_number'] ?? null) === CetechDeliveryEngine\Domain\Shipment\ShipmentIdentity::stable_shipment_number($order, $group);
            if ($owned) { $shipments[$shipment] = $order; $authorized['shipments'][] = $shipment; } else { $complete = false; }
        }
        foreach ($rows['shipment_items'] ?? [] as $row) {
            if (!$new('shipment_items', $row)) { continue; } $value = $id($row['id'] ?? null); $shipment = $id($row['shipment_id'] ?? null); $order = $id($row['order_id'] ?? null); $item = $id($row['order_item_id'] ?? null);
            if (null !== $value && null !== $shipment && isset($shipments[$shipment]) && $shipments[$shipment] === $order && in_array($item, $owned_orders[$order]['items'], true)) { $authorized['shipment_items'][] = $value; } else { $complete = false; }
        }
        foreach ($rows['shipment_events'] ?? [] as $row) {
            if (!$new('shipment_events', $row)) { continue; } $value = $id($row['id'] ?? null); $shipment = $id($row['shipment_id'] ?? null);
            if (null !== $value && null !== $shipment && isset($shipments[$shipment]) && in_array($row['event_type'] ?? null, ['created', 'status_changed'], true) && in_array($row['source'] ?? null, ['system', 'woocommerce'], true) && null === ($row['actor_user_id'] ?? null)) { $authorized['shipment_events'][] = $value; } else { $complete = false; }
        }
        foreach ($rows['audit_log'] ?? [] as $row) {
            if (!$new('audit_log', $row)) { continue; } $value = $id($row['id'] ?? null); $order = $id($row['entity_id'] ?? null); $raw = $row['new_value'] ?? null; $facts = null;
            try { if (is_string($raw) && strlen($raw) <= 16384) { $facts = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } } catch (Throwable) {}
            $owned = null !== $value && null !== $order && isset($owned_orders[$order]) && 'order' === ($row['entity_type'] ?? null) && null === ($row['actor_user_id'] ?? null) && null === ($row['previous_value'] ?? null) && null === ($row['site_context'] ?? null) && is_array($facts) && 'system' === ($facts['source'] ?? null);
            if ($owned && 'shipment_creation_failed' === ($row['action'] ?? null)) {
                $owned = [] === array_diff(array_keys($facts), ['error_code', 'source']) && 2 === count($facts) && in_array($facts['error_code'] ?? null, array_column(CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode::cases(), 'value'), true);
            } elseif ($owned && in_array($row['action'] ?? null, ['shipment_creation_succeeded', 'shipment_creation_idempotent'], true)) {
                $keys = $facts['shipment_ids'] ?? null; $outcomes = 'shipment_creation_succeeded' === $row['action'] ? ['created', 'completed_existing_incomplete', 'zero_shipments_pickup_only'] : ['already_exists_complete'];
                $owned = [] === array_diff(array_keys($facts), ['outcome', 'source', 'shipment_ids']) && 3 === count($facts) && in_array($facts['outcome'] ?? null, $outcomes, true) && is_array($keys) && array_is_list($keys) && count($keys) <= 200 && count($keys) === count(array_unique($keys));
                if ($owned) { foreach ($keys as $key) { if (!is_int($key) || ($shipments[$key] ?? null) !== $order) { $owned = false; break; } } }
            } else { $owned = false; }
            if ($owned) { $authorized['audit_log'][] = $value; } else { $complete = false; }
        }
        return ['authorized' => $authorized, 'ownership_complete' => $complete];
    }
}

/** Exact new order IDs and exact placement namespaces are the only extra cleanup authority. */
final class CetechQuotePlacementFixture {
	public CetechQuoteCartFixture $cart;
	public CetechQuotePlacementFactory $factory;
	public array $orders = [];
	private array $commands = [];
	private array $placement_namespaces = [];
	private array $initial_history = [];
	public function __construct( private wpdb $db ) { $this->cart = new CetechQuoteCartFixture( $db ); $this->factory = new CetechQuotePlacementFactory( $db ); }
	public function prepare(): void {
		$this->cart->prepare(); $this->initial_history = $this->cart->history();
		foreach ( WC()->cart->cart_contents as &$item ) { if ( ! array_key_exists( 'variation', $item ) ) { $item['variation'] = []; } } unset( $item );
		// The retained disposable fixture installs its own native WC_Cart. An
		// already initialized CLI cart must not save that old cart into this
		// fixture's session. The provider's exact hook snapshot restores it.
		$hook = $GLOBALS['wp_filter']['woocommerce_after_calculate_totals'] ?? null;
		if ( $hook instanceof WP_Hook && WP_Hook::class === get_class( $hook ) ) {
			foreach ( $hook->callbacks[1000] ?? [] as $entry ) {
				$callback = $entry['function'] ?? null;
				if ( ! is_array( $callback ) || 2 !== count( $callback ) || ! is_object( $callback[0] ) || WC_Cart_Session::class !== get_class( $callback[0] ) || 'set_session' !== $callback[1] || 1 !== ( $entry['accepted_args'] ?? null ) ) { continue; }
				$property = new ReflectionProperty( $callback[0], 'cart' );
				if ( $property->isInitialized( $callback[0] ) && $property->getValue( $callback[0] ) !== WC()->cart ) { remove_action( 'woocommerce_after_calculate_totals', $callback, 1000 ); }
			}
		}
		( new CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteRateReferenceRuntime( $this->cart->environment, $this->cart->sessions, true ) )->register();
		$this->cart->native->set_option( CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore::OPTION_NAME, CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState::record_json( get_current_blog_id(), 'enabled', 990001, 'resume_verified', 1, time() ) );
		$this->cart->native->recalculate();
	}
	public function register_gateway(): \WC_Payment_Gateway {
		$gateway = new CetechQuotePlacementNativeGateway();
		add_filter( 'woocommerce_available_payment_gateways', static function ( array $gateways ) use ( $gateway ): array { $gateways[$gateway->id] = $gateway; return $gateways; }, 10, 1 );
		return $gateway;
	}
	public function confirmed_evidence(): CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementEvidence {
		$runtime = new CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteReviewRuntime( $this->cart->service, true );
		$current = $runtime->current_facts(); $review = $runtime->dispatch( [ 'action' => 'refresh', 'generation' => $current['generation'], 'review_token' => CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId::generate()->value() ] );
		if ( 'review_required' !== $review['status'] ) { throw new RuntimeException( 'Native Q06 explicit quote review unavailable.' ); }
		$confirmed = $runtime->dispatch( [ 'action' => 'confirm', 'generation' => $review['generation'] ] );
		if ( 'confirmed' !== $confirmed['status'] ) { throw new RuntimeException( 'Native Q06 explicit quote confirmation unavailable.' ); }
		$evidence = ( new CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader( $this->cart->environment, $this->cart->sessions, $this->factory ) )->current( CetechDeliveryEngine\Domain\Contracts\RequestContext::create() );
		if ( null === $evidence ) { throw new RuntimeException( 'Native Q06 accepted private quote evidence unavailable.' ); }
		return $evidence;
	}
	public function placement_service( CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementEvidence $evidence ): CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementService {
		$durable = new CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableService( $this->factory, new CetechDeliveryEngine\Application\DeliveryQuote\QuoteProviderRegistry(), [ $evidence, 'authorize' ], evidence: $evidence->guard() );
		$service = new CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementService( $durable, $evidence );
		if ( ! $evidence->owner()->equals( $this->cart->owner() ) ) { throw new RuntimeException( 'Native Q06 placement owner changed.' ); }
		$names = CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand::binding_namespaces( $evidence->owner(), $evidence->header(), $service->placement_id(), true );
		$this->placement_namespaces[$names['bind']] = 'delivery_quote.bind'; $this->placement_namespaces[$names['seal']] = 'delivery_quote.seal';
		$this->placement_namespaces[CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand::verification_namespace( $evidence->owner(), $evidence->header(), $service->placement_id() )] = 'delivery_quote.verify_binding';
		return $service;
	}
	public function track_result( CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableResult $result ): void { if ( null !== $result->command ) { $this->commands[$result->command->identity->namespace_digest()] = $result->command; } $this->cart->native->track( $result ); }
	public function create_order( bool $allow_refusal = false, string $method = '' ): WC_Order {
		$data = [ 'billing_first_name' => 'Synthetic', 'billing_last_name' => 'Shopper', 'billing_country' => 'GH', 'billing_state' => 'AA', 'billing_city' => 'Accra', 'billing_postcode' => '00001', 'billing_address_1' => 'PRIVATE-Q06-NATIVE-FIXTURE-ADDRESS', 'billing_email' => 'q06@example.invalid', 'shipping_first_name' => 'Synthetic', 'shipping_last_name' => 'Shopper', 'shipping_country' => 'GH', 'shipping_state' => 'AA', 'shipping_city' => 'Accra', 'shipping_postcode' => '00001', 'shipping_address_1' => 'PRIVATE-Q04-NATIVE-FIXTURE-ADDRESS', 'ship_to_different_address' => 1, 'payment_method' => $method ];
		WC()->session->set( 'order_awaiting_payment', 0 );
		$id = WC()->checkout()->create_order( $data );
		if ( is_wp_error( $id ) && $allow_refusal && [] !== $this->orders ) { $id = end( $this->orders ); }
		if ( is_wp_error( $id ) || ! is_int( $id ) || $id < 1 ) { throw new RuntimeException( 'Native Q06 Woo checkout order was not created.' ); }
		$this->orders[] = $id; $order = new WC_Order( $id );
		if ( ! $order instanceof WC_Order || WC_Order::class !== get_class( $order ) ) { throw new RuntimeException( 'Native Q06 created order is unavailable.' ); }
		$order->get_meta_data(); foreach ( $order->get_items( [ 'line_item', 'shipping' ] ) as $item ) { $item->get_meta_data(); }
		return $order;
	}
	public function binding_row( string $quote_id ): ?array {
		$rows = $this->db->get_results( $this->db->prepare( 'SELECT * FROM `' . TableNames::for( 'delivery_quote_bindings' ) . '` WHERE site_id=%d AND quote_uuid=%s LIMIT 2', get_current_blog_id(), $quote_id ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error || count( $rows ) > 1 ) { throw new RuntimeException( 'Native Q06 binding observation unavailable.' ); }
		return $rows[0] ?? null;
	}
	/** HPOS/classic raw protected bytes; order status/payment mutations are deliberately excluded. */
	public function snapshot_bytes( WC_Order $order ): array {
		$hpos = Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$table = $hpos ? $this->db->prefix . 'wc_orders_meta' : $this->db->postmeta; $parent = $hpos ? 'order_id' : 'post_id';
		$pk = $hpos ? 'id' : 'meta_id';
		$rows = $this->db->get_results( $this->db->prepare( "SELECT `{$pk}` AS id,meta_key,meta_value FROM `{$table}` WHERE `{$parent}`=%d AND LEFT(meta_key,11)='_cetech_de_' ORDER BY `{$pk}`", $order->get_id() ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->db->last_error ) { throw new RuntimeException( 'Native Q06 order snapshot bytes unavailable.' ); }
		$items = [];
		foreach ( $order->get_items( [ 'line_item', 'shipping', 'tax' ] ) as $id => $item ) {
			$meta = $this->db->get_results( $this->db->prepare( "SELECT meta_id,meta_key,meta_value FROM `{$this->db->prefix}woocommerce_order_itemmeta` WHERE order_item_id=%d AND (LEFT(meta_key,11)='_cetech_de_' OR meta_key='cetech_de_group_id' OR meta_key IN ('_product_id','_variation_id','_qty','_line_total','_line_tax','_line_subtotal','_line_subtotal_tax','_line_tax_data','method_id','instance_id','cost','total_tax','taxes','rate_id','label','compound','tax_amount','shipping_tax_amount','rate_percent')) ORDER BY meta_id", $id ), ARRAY_A );
			if ( ! is_array( $meta ) || '' !== $this->db->last_error ) { throw new RuntimeException( 'Native Q06 line snapshot bytes unavailable.' ); } $items[$id] = $meta;
		}
		return [ 'order' => $rows, 'items' => $items ];
	}
	public function cleanup(): array {
		$this->factory->clock = null; $ok = $this->factory->close_all();
		$owned_orders = []; $operational_rows = []; $before = ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'domain_before' ) )->getValue( $this->cart->native ); $operational_before = [];
		foreach ( array_unique( $this->orders ) as $id ) {
			$order = wc_get_order( $id ); if ( ! $order instanceof WC_Order ) { continue; } $groups = [];
			foreach ( $order->get_items( 'line_item' ) as $item ) { $group = $item->get_meta( 'cetech_de_group_id', true ); if ( is_string( $group ) && '' !== $group && strlen( $group ) <= 191 ) { $groups[] = $group; } }
			$owned_orders[$id] = [ 'groups' => array_values( array_unique( $groups ) ), 'items' => array_keys( $order->get_items( 'line_item' ) ) ];
		}
		foreach ( [ 'shipments', 'shipment_items', 'shipment_events', 'audit_log' ] as $suffix ) { $operational_before[$suffix] = array_column( $before[$suffix], 'id' ); $operational_rows[$suffix] = CetechNativeQuoteProviderFixture::rows( TableNames::for( $suffix ) ); }
		$operational = CetechQuotePlacementOperationalCleanup::plan( $operational_before, $owned_orders, $operational_rows ); $ok = $operational['ownership_complete'] && $ok;
		foreach ( array_reverse( array_unique( $this->orders ) ) as $id ) { $order = wc_get_order( $id ); if ( $order instanceof WC_Order ) { $order->delete( true ); } $ok = ! wc_get_order( $id ) && $ok; }
		$orders_removed = true; foreach ( $this->orders as $id ) { $orders_removed = ! wc_get_order( $id ) && $orders_removed; }
		$namespaces = $this->placement_namespaces;
		foreach ( $this->commands as $namespace => $command ) {
			if ( ! in_array( $command->identity->operation, [ 'delivery_quote.bind', 'delivery_quote.verify_binding', 'delivery_quote.seal' ], true ) || $command->owner()->site_id() !== get_current_blog_id() || ! $command->owner()->equals( $this->cart->owner() ) ) { throw new RuntimeException( 'Native Q06 cleanup refuses foreign placement authority.' ); }
			$namespaces[$namespace] = $command->identity->operation;
		}
		$initial = array_column( $this->initial_history['delivery_quote_bindings'] ?? [], 'id' );
		foreach ( $this->cart->history()['delivery_quote_bindings'] as $row ) {
			if ( in_array( $row['id'], $initial, true ) ) { continue; }
			$quote = CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow::from_row( $this->cart->row( $row['quote_uuid'] ) );
			if ( ! $quote->header()->owner()->equals( $this->cart->owner() ) ) { throw new RuntimeException( 'Native Q06 cleanup refuses a foreign binding.' ); }
			$binding = CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row( $row, $quote );
			$namespaces[$binding->row()['bind_namespace_hash']] = 'delivery_quote.bind'; $namespaces[$binding->row()['seal_namespace_hash']] = 'delivery_quote.seal';
			$namespaces[CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableCommand::verification_namespace( $quote->header()->owner(), $quote->header(), $binding->row()['placement_uuid'] )] = 'delivery_quote.verify_binding';
		}
		foreach ( $namespaces as $namespace => $operation ) {
			$records = TableNames::for( 'operation_records' ); $rows = $this->db->get_results( $this->db->prepare( "SELECT id FROM `{$records}` WHERE site_id=%d AND namespace_hash=%s AND operation=%s LIMIT 2", get_current_blog_id(), $namespace, $operation ), ARRAY_A );
			if ( ! is_array( $rows ) || count( $rows ) > 1 || '' !== $this->db->last_error ) { throw new RuntimeException( 'Native Q06 cleanup exact namespace unavailable.' ); }
			foreach ( $rows as $row ) { $ok = false !== $this->db->delete( TableNames::for( 'operation_changes' ), [ 'site_id' => get_current_blog_id(), 'operation_id' => (int) $row['id'] ] ) && $ok; $ok = false !== $this->db->delete( $records, [ 'site_id' => get_current_blog_id(), 'id' => (int) $row['id'] ] ) && $ok; }
		}
		// Exact saved native order/group/item relationships and canonical callbacks,
		// captured before native deletion, grant only these operational row IDs.
		$entities = ( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'entities' ) )->getValue( $this->cart->native );
		foreach ( $operational['authorized'] as $suffix => $ids ) { foreach ( $ids as $id ) { $entities[] = [ $suffix, $id ]; } }
		( new ReflectionProperty( CetechNativeQuoteProviderFixture::class, 'entities' ) )->setValue( $this->cart->native, $entities );
		$cleanup = $this->cart->cleanup();
		return [ 'cleanup_restored' => $ok && $orders_removed && $cleanup['cleanup_restored'] && $this->factory->all_retired(), 'owned_orders_removed' => $orders_removed, 'quote_operation_history_restored' => $cleanup['domain35_and_quote_operation_history_restored'], 'owned_connections_retired' => $this->factory->all_retired() && $cleanup['all_owned_connections_retired'] ];
	}
}

/** Genuine native C07 and production Q06 runtime; only typed observation/fault seams are added. */
final class CetechQuotePlacementNativeFlow {
	public CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementEvidence $evidence;
	public CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementService $service;
	public CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager $stager;
	public CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime $placement;
	public CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService $admission;
	public CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime $runtime;
	public ?CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableResult $prepared = null;
	public ?CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableResult $verified = null;
	public ?CetechDeliveryEngine\Application\Order\QuoteNativeOrderStageResult $saved = null;
	public ?CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding $physical_binding = null;
	public ?WC_Order $order = null;
	public ?Closure $before_seal = null;
	public ?Closure $before_freeze = null;
	public string $payment_method = '';
	public array $mapping = [];
	public bool $prefreeze_staged = false;
	public bool $creation_refused = false;
	private ?Throwable $creation_error = null;
	private ?WC_Order $creation_order = null;
	public function __construct( private CetechQuotePlacementFixture $fixture ) {
		// Each flow represents a fresh native request. Prior request continuations
		// cannot register another terminal payment filter in this one request;
		// the retained provider restores the exact original hook snapshot.
		foreach ( $GLOBALS['wp_filter']['woocommerce_available_payment_gateways']->callbacks ?? [] as $priority => $callbacks ) { foreach ( $callbacks as $entry ) {
			$fn = $entry['function'] ?? null;
			if ( is_array( $fn ) && 2 === count( $fn ) && $fn[0] instanceof CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime && 'guard_payment_gateways' === $fn[1] ) { remove_filter( 'woocommerce_available_payment_gateways', $fn, $priority ); }
		} }
		$this->evidence = $fixture->confirmed_evidence(); $this->service = $fixture->placement_service( $this->evidence ); $this->stager = new CetechDeliveryEngine\Application\Order\QuoteNativeOrderStager( $fixture->factory );
		$container = CetechDeliveryEngine\Bootstrap\Plugin::instance()->container(); $latch = new CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch();
		$control = $container->get( CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService::class ); $classifier = $container->get( CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier::class );
		$reader = new CetechDeliveryEngine\Application\DeliveryQuote\QuoteCartPlacementEvidenceReader( $fixture->cart->environment, $fixture->cart->sessions, $fixture->factory );
		$this->placement = new CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime( $fixture->factory, $reader, $this->stager, $container->get( CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutQuoteValidator::class ), static fn (): bool => true, static fn (): bool => true, guard_decorator: function ( $guard ) { if ( null !== $this->before_seal ) { ( $this->before_seal )(); } return $guard; } );
		$this->admission = new CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService( $control, $classifier, $this->placement, $latch, $this->placement );
		$this->runtime = new CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime( $control, $classifier, $latch, $this->admission );
	}
	public function create( string $fault = 'none' ): void {
		if ( ! in_array( $fault, [ 'none', 'prepare_ack', 'verify_ack' ], true ) ) { throw new RuntimeException( 'Invalid placement fixture fault.' ); }
		$hooks = [ 'woocommerce_checkout_create_order_line_item', 'woocommerce_checkout_order_created' ]; $before = [];
		foreach ( $hooks as $name ) {
			$before[$name] = isset( $GLOBALS['wp_filter'][$name] ) ? clone $GLOBALS['wp_filter'][$name] : null;
			foreach ( $GLOBALS['wp_filter'][$name]->callbacks ?? [] as $priority => $callbacks ) { foreach ( $callbacks as $entry ) {
				$fn = $entry['function'] ?? null;
				if ( is_array( $fn ) && 2 === count( $fn ) && is_object( $fn[0] ) && ( $fn[0] instanceof CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyCheckoutHooks && 'bind_order_line' === $fn[1] || $fn[0] instanceof CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime && 'freeze_saved_order' === $fn[1] || $fn[0] instanceof CetechDeliveryEngine\Integrations\DeliveryQuote\QuotePlacementRuntime && 'stage_classic' === $fn[1] ) ) { remove_action( $name, $fn, $priority ); }
			} }
		}
		$line = function ( WC_Order_Item_Product $item, string $key, array $values ): void { $item->update_meta_data( CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts::META_LINE_KEY, $key ); $this->runtime->latch_line( $key, $values ); $this->runtime->bind_order_line( $key, $item ); };
		$stage = function ( WC_Order $order ): void {
			$this->creation_order = $order;
			$this->fixture->orders[] = $order->get_id();
			try { $this->placement->stage_classic( $order ); }
			catch ( Throwable $error ) { $this->creation_refused = true; $this->creation_error = $error; throw $error; }
			$this->prefreeze_staged = CetechDeliveryEngine\Application\Order\QuoteNativeOrderHistory::verify( $order );
			if ( null !== $this->before_freeze ) { ( $this->before_freeze )( $order ); }
		};
		if ( 'none' !== $fault ) { $this->fixture->factory->mask_binding_commit_number = $this->fixture->factory->binding_commits + ( 'prepare_ack' === $fault ? 1 : 2 ); }
		add_action( $hooks[0], $line, PHP_INT_MAX, 3 ); add_action( $hooks[1], $stage, PHP_INT_MAX - 1, 1 ); add_action( $hooks[1], [ $this->runtime, 'freeze_saved_order' ], PHP_INT_MAX, 1 );
		try { $this->order = $this->fixture->create_order( 'none' !== $fault, $this->payment_method ); }
		catch ( Throwable $error ) { throw new RuntimeException( 'Native Q06 checkout stage unavailable.', 0, $this->creation_error ?? $error ); }
		finally { foreach ( $before as $name => $value ) { if ( null === $value ) { unset( $GLOBALS['wp_filter'][$name] ); } else { $GLOBALS['wp_filter'][$name] = $value; } } }
		if ( $this->creation_order instanceof WC_Order ) { $this->order = $this->creation_order; }
		$this->mapping = $this->stager->mapping( $this->order, $this->evidence );
		$physical = $this->fixture->binding_row( $this->evidence->header()->id()->value() );
		if ( null === $physical ) { throw new RuntimeException( 'Native Q06 physical placement was not prepared.', 0, $this->creation_error ); }
		$this->physical_binding = CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row( $physical, $this->evidence->quote_record() );
		if ( null !== $this->before_freeze ) { return; }
		if ( null !== $this->physical_binding->row()['verified_at'] ) { $this->saved = $this->stager->saved_guard( $this->order, $this->evidence->quote_record(), $this->physical_binding ); }
		if ( 'none' === $fault ) {
			$this->prepared = $this->service->prepare( $this->order->get_id(), $this->mapping, CetechDeliveryEngine\Domain\Contracts\RequestContext::create() ); $this->fixture->track_result( $this->prepared );
			$this->verified = $this->service->verify( $this->physical_binding, $this->saved, CetechDeliveryEngine\Domain\Contracts\RequestContext::create() ); $this->fixture->track_result( $this->verified );
		}
	}
	/** Finish the same original native checkout after the refusal facts were asserted. */
	public function recover_original(): bool {
		if ( ! $this->order instanceof WC_Order ) { return false; }
		$this->before_seal = null;
		$this->placement->stage_classic( $this->order );
		$this->runtime->freeze_saved_order( $this->order );
		return $this->admission->final_order( $this->order, 'classic' )->allowed;
	}
	public function replay_seal(): CetechDeliveryEngine\Application\DeliveryQuote\QuoteDurableResult {
		$control = CetechDeliveryEngine\Bootstrap\Plugin::instance()->container()->get( CetechDeliveryEngine\Application\EmergencyControl\EmergencyControlService::class )->read( get_current_blog_id() );
		$local = CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding::capture( $this->order );
		if ( null === $local || null === $control->state || null === $this->physical_binding || null === $this->saved ) { throw new RuntimeException( 'Native Q06 original final proof unavailable.' ); }
		$proof = CetechDeliveryEngine\Application\DeliveryQuote\QuotePlacementProof::capture( $this->physical_binding, $control->state->revision, $local, $this->saved );
		$result = $this->service->seal( $this->physical_binding, $proof, CetechDeliveryEngine\Domain\Contracts\RequestContext::create() ); $this->fixture->track_result( $result ); return $result;
	}
}
