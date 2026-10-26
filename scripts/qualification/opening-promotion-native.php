<?php
declare(strict_types=1);

use CetechDeliveryEngine\Application\DeliveryQuote\PromiseQuotePlacementActivation;
use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliverySnapshot, OrderDeliverySnapshotReader, QuoteNativeOrderFacts, QuoteNativeOrderHistory, QuoteNativeOrderStager};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteJson};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Infrastructure\WordPress\OperationConnectionFactory as NativeOperationFactory;
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativeOrderEvidence;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionNativeHandoffFixture;

require_once dirname( __DIR__, 2 ) . '/tests/Support/DeliveryPromotion/PromotionNativeHandoffFixture.php';

/** Tracks real dedicated native SQL owners; no fake transport or test bootstrap is installed. */
final class CetechPromotionNativeFactory implements OperationConnectionFactory {
	private NativeOperationFactory $native;
	private array $sessions = [];
	public function __construct() { $this->native = new NativeOperationFactory(); }
	public function open(): OperationSession { $session = $this->native->open(); $this->sessions[] = $session; return $session; }
	public function run( callable $work ): mixed {
		$session = $this->open();
		try { if ( ! $session->begin() ) { throw new RuntimeException( 'N17 owned SQL is unavailable.' ); } return $work( $session ); }
		finally { $rolled_back = ! $session->in_transaction() || $session->rollback(); $retired = $session->retire(); if ( ! $rolled_back || ! $retired || ! $session->is_retired() || $session->in_transaction() ) { throw new RuntimeException( 'N17 owned SQL termination was not acknowledged.' ); } }
	}
	public function retire_all(): bool { $ok = true; foreach ( $this->sessions as $session ) { if ( $session->in_transaction() ) { $ok = $session->rollback() && $ok; } if ( ! $session->is_retired() ) { $ok = $session->retire() && $ok; } $ok = $session->is_retired() && $ok; } return $ok; }
}

/** Owns one product and order; every physical fault is restored before the next case. */
final class CetechPromotionNativeFixture {
	public CetechPromotionNativeFactory $factory;
	public QuoteNativeOrderStager $stager;
	public ?WC_Product_Simple $product = null;
	public ?WC_Order $order = null;
	public array $seed = [];
	public array $baseline;
	private int $user;
	private int $actor;
	private bool $hpos;
	private array $actions;
	private array $queues;
	private array $adoption;
	public function __construct( private wpdb $db, string $mode ) {
		$this->hpos = 'hpos_on' === $mode; $this->user = get_current_user_id(); $this->actor = $this->user;
		if ( $this->actor < 1 ) { $users = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); $this->actor = (int) ( $users[0] ?? 0 ); }
		if ( $this->actor < 1 ) { throw new RuntimeException( 'N17 disposable native actor is unavailable.' ); }
		$this->factory = new CetechPromotionNativeFactory(); $this->stager = new QuoteNativeOrderStager( $this->factory );
		$this->baseline = $this->census(); $this->actions = $this->rows( "SELECT * FROM `{$db->prefix}actionscheduler_actions` ORDER BY action_id" );
		$this->queues = $this->queue_rows();
		$this->adoption = $this->adoption_rows();
	}
	public function prepare(): void {
		wp_set_current_user( $this->actor );
		$this->product = new WC_Product_Simple(); $this->product->set_name( 'N17 synthetic native handoff' ); $this->product->set_status( 'draft' ); $this->product->set_regular_price( '10.00' ); $this->product->set_tax_status( 'none' ); $this->product->save();
		$this->order = new WC_Order(); $this->order->set_status( 'pending' ); $this->order->set_currency( 'GHS' ); $this->order->set_customer_id( $this->actor ); $this->order->set_order_key( wc_generate_order_key() );
		$this->order->set_address( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE-N17-ADDRESS', 'address_2' => '' ], 'shipping' );
		$this->order->set_address( [ 'country' => 'GH', 'state' => 'AA', 'city' => 'Accra', 'postcode' => '00001', 'address_1' => 'PRIVATE-N17-ADDRESS', 'address_2' => '' ], 'billing' ); $this->order->update_meta_data( 'is_vat_exempt', 'yes' ); $this->order->save();
		$line = new WC_Order_Item_Product(); $line->set_product( $this->product ); $line->set_quantity( 2 ); $line->set_subtotal( '20.00' ); $line->set_total( '20.00' ); $line->set_taxes( [ 'total' => [], 'subtotal' => [] ] ); $line->update_meta_data( QuoteNativeOrderFacts::META_LINE_KEY, 'line_one' ); $this->order->add_item( $line ); $this->order->save();
		$this->seed = PromotionNativeHandoffFixture::create( [ 'product_id' => $this->product->get_id(), 'customer_id' => $this->actor, 'site_id' => get_current_blog_id(), 'table_prefix' => $this->db->prefix, 'order_id' => $this->order->get_id(), 'item_id' => $line->get_id(), 'private_key' => wp_salt( 'auth' ) ] );
		$shipping = new WC_Order_Item_Shipping(); $shipping->set_method_id( 'delivery_engine_selected_offer' ); $shipping->set_instance_id( 1 ); $shipping->set_method_title( $this->seed['shipping_label'] ); $shipping->set_total( $this->seed['shipping_final'] ); $shipping->set_taxes( [ 'total' => [] ] ); $shipping->update_meta_data( 'cetech_de_group_id', $this->seed['group'] ); $this->order->add_item( $shipping );
		$this->order->set_shipping_total( $this->seed['shipping_final'] ); $this->order->set_shipping_tax( '0.00' ); $this->order->set_cart_tax( '0.00' ); $this->order->set_total( '29.50' ); $this->order->save();
		$this->order = $this->fresh();
	}
	public function authorize( WC_Order $order ): bool { return get_current_user_id() === $this->actor && $this->actor > 0 && $order->get_id() === $this->order?->get_id() && $order->get_customer_id( 'edit' ) === $this->actor && '' !== $order->get_order_key( 'edit' ); }
	public function fresh(): WC_Order { $fresh = new WC_Order( $this->order->get_id() ); $fresh->get_meta_data(); foreach ( $fresh->get_items( [ 'line_item', 'shipping', 'tax' ] ) as $item ) { $item->get_meta_data(); } return $fresh; }
	public function capture( ?callable $auth = null, ?array $mapping = null ): ?FixedPromotionNativeOrderEvidence { return FixedPromotionNativeOrderEvidence::capture( $this->factory, $this->order, $this->seed['quote'], $mapping ?? $this->seed['mapping'], $this->seed['tax_source'], $auth ?? [ $this, 'authorize' ] ); }
	public function verify_order( FixedPromotionNativeOrderEvidence $guard, QuoteBinding $binding ): bool { return $this->factory->run( fn( OperationSession $s ): bool => $s->validate_tables( $guard->tables( $s ) ) && $guard->verify( $s, $this->seed['quote'], $binding ) ); }
	public function verify_saved( $guard, QuoteBinding $binding ): bool { return $this->factory->run( static fn( OperationSession $s ): bool => $s->validate_tables( $guard->tables( $s ) ) && $guard->verify( $s, $binding ) ); }
	public function native_money(): array { $o = $this->fresh(); $paid = $o->get_date_paid( 'edit' ); return [ $o->get_currency( 'edit' ), $o->get_total( 'edit' ), $o->get_total_tax( 'edit' ), $o->get_shipping_total( 'edit' ), $o->get_shipping_tax( 'edit' ), $o->get_status( 'edit' ), null === $paid ? null : $paid->getTimestamp() ]; }
	public function snapshot_bytes(): array {
		$id = $this->order->get_id(); $table = $this->db->prefix . ( $this->hpos ? 'wc_orders_meta' : 'postmeta' ); $parent = $this->hpos ? 'order_id' : 'post_id';
		return [ 'order' => $this->rows( $this->db->prepare( "SELECT * FROM `{$table}` WHERE `{$parent}`=%d AND LEFT(meta_key,11)='_cetech_de_' ORDER BY meta_key,meta_value", $id ) ), 'items' => $this->rows( $this->db->prepare( "SELECT m.* FROM `{$this->db->prefix}woocommerce_order_itemmeta` m INNER JOIN `{$this->db->prefix}woocommerce_order_items` i ON i.order_item_id=m.order_item_id WHERE i.order_id=%d AND LEFT(m.meta_key,11)='_cetech_de_' ORDER BY m.order_item_id,m.meta_key,m.meta_value", $id ) ) ];
	}
	/** A borrowed WordPress connection makes the fault; guards always open a distinct owned connection. */
	public function mutate( string $table, array $where, array $changes, callable $observe ): bool {
		if ( 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new RuntimeException( 'N17 mutation table differs.' ); }
		$clauses = []; $values = []; foreach ( $where as $key => $value ) { if ( 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $key ) ) { throw new RuntimeException( 'N17 mutation key differs.' ); } $clauses[] = "`{$key}`=%s"; $values[] = (string) $value; }
		$rows = $this->rows( $this->db->prepare( "SELECT * FROM `{$table}` WHERE " . implode( ' AND ', $clauses ) . ' LIMIT 2', ...$values ) ); if ( count( $rows ) > 1 ) { throw new RuntimeException( 'N17 mutation requires one exact owned coordinate.' ); }
		try {
			$changed = [] === $rows ? $this->db->insert( $table, array_replace( $where, $changes ) ) : $this->db->update( $table, $changes, $where );
			if ( 1 !== $changed ) { throw new RuntimeException( 'N17 mutation did not affect its one owned row.' ); } return true === $observe();
		} finally { if ( [] === $rows ) { $this->db->delete( $table, $where ); } else { $this->db->replace( $table, $rows[0] ); } }
	}
	public function order_mutation( string $name, callable $observe ): bool {
		$p = $this->db->prefix; $id = $this->order->get_id();
		if ( $this->hpos ) {
			[ $table, $where, $changes ] = match( $name ) {
				'customer' => [ $p . 'wc_orders', [ 'id' => $id ], [ 'customer_id' => 0 ] ], 'status' => [ $p . 'wc_orders', [ 'id' => $id ], [ 'status' => 'wc-processing' ] ],
				'key' => [ $p . 'wc_order_operational_data', [ 'order_id' => $id ], [ 'order_key' => 'wc_order_n17_changed' ] ], 'paid' => [ $p . 'wc_order_operational_data', [ 'order_id' => $id ], [ 'date_paid_gmt' => '2026-10-23 12:00:00' ] ],
				'money' => [ $p . 'wc_orders', [ 'id' => $id ], [ 'total_amount' => '30.50' ] ], 'address' => [ $p . 'wc_order_addresses', [ 'order_id' => $id, 'address_type' => 'shipping' ], [ 'address_1' => 'CHANGED-N17-ADDRESS' ] ],
				default => throw new RuntimeException( 'N17 physical mutation is undeclared.' ),
			};
		} elseif ( 'status' === $name ) { $table = $p . 'posts'; $where = [ 'ID' => $id ]; $changes = [ 'post_status' => 'wc-processing' ]; }
		else { $table = $p . 'postmeta'; $where = [ 'post_id' => $id, 'meta_key' => match( $name ) { 'customer' => '_customer_user', 'key' => '_order_key', 'paid' => '_date_paid', 'money' => '_order_total', 'address' => '_shipping_address_1', default => throw new RuntimeException( 'N17 physical mutation is undeclared.' ) } ]; $changes = [ 'meta_value' => match( $name ) { 'customer' => '0', 'key' => 'wc_order_n17_changed', 'paid' => '1792756800', 'money' => '30.50', 'address' => 'CHANGED-N17-ADDRESS' } ]; }
		return $this->mutate( $table, $where, $changes, $observe );
	}
	public function adoption_off_unchanged(): bool { $rows = $this->adoption_rows(); return $rows === $this->adoption && false === PromiseQuotePlacementActivation::decode( $rows[0]['option_value'] ?? null )['enabled']; }
	private function adoption_rows(): array { return $this->rows( $this->db->prepare( "SELECT * FROM `{$this->db->options}` WHERE option_name=%s ORDER BY option_id", PromiseQuotePlacementActivation::OPTION ) ); }
	private function queue_rows(): array { $out = []; foreach ( [ 'actions' => 'action_id', 'claims' => 'claim_id', 'groups' => 'group_id', 'logs' => 'log_id' ] as $suffix => $key ) { $out[$suffix] = $this->rows( "SELECT * FROM `{$this->db->prefix}actionscheduler_{$suffix}` ORDER BY `{$key}`" ); } return $out; }
	private function rows( string $sql ): array { $rows = $this->db->get_results( $sql, ARRAY_A ); if ( ! is_array( $rows ) || '' !== $this->db->last_error ) { throw new RuntimeException( 'N17 finite physical observation failed.' ); } return $rows; }
	private function census(): array {
		$tables = $this->rows( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $this->db->prefix . 'delivery_engine_' ) . '%' ) );
		$names = [ $this->db->posts, $this->db->postmeta, $this->db->prefix . 'woocommerce_order_items', $this->db->prefix . 'woocommerce_order_itemmeta', $this->db->prefix . 'wc_orders', $this->db->prefix . 'wc_orders_meta', $this->db->prefix . 'wc_order_addresses', $this->db->prefix . 'wc_order_operational_data' ]; foreach ( $tables as $row ) { $names[] = (string) reset( $row ); }
		$checksums = []; foreach ( $names as $table ) { if ( 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new RuntimeException( 'N17 physical inventory differs.' ); } $rows = $this->rows( "CHECKSUM TABLE `{$table}`" ); $sum = $rows[0]['Checksum'] ?? null; if ( null === $sum ) { throw new RuntimeException( 'N17 physical checksum is unavailable.' ); } $checksums[$table] = (string) $sum; } ksort( $checksums, SORT_STRING ); return $checksums;
	}
	public function cleanup(): array {
		$connections = $this->factory->retire_all(); $owned = true; $product_id = null === $this->product ? 0 : $this->product->get_id();
		if ( null !== $this->order ) { $id = $this->order->get_id(); $order = new WC_Order( $id ); $order->delete( true ); $owned = false === wc_get_order( $id ); }
		if ( null !== $this->product ) { $this->product->delete( true ); $owned = null === get_post( $product_id ) && $owned; }
		// Only an unclaimed, pending native lookup action with exactly this product ID is owned.
		$before_ids = array_map( 'intval', array_column( $this->actions, 'action_id' ) ); $current = $this->rows( "SELECT * FROM `{$this->db->prefix}actionscheduler_actions` ORDER BY action_id" ); $jobs_owned = true; $owned_groups = [];
		foreach ( $current as $row ) { if ( in_array( (int) $row['action_id'], $before_ids, true ) ) { continue; } $args = json_decode( $row['args'], true ); $is_owned = $product_id > 0 && 'woocommerce_run_product_attribute_lookup_update_callback' === $row['hook'] && 'pending' === $row['status'] && 0 === (int) $row['claim_id'] && is_array( $args ) && array_is_list( $args ) && 2 === count( $args ) && $args[0] === $product_id && in_array( $args[1], [ 1, 2, 3 ], true ); if ( ! $is_owned ) { $jobs_owned = false; continue; } $owned_groups[] = (int) $row['group_id']; $this->db->delete( $this->db->prefix . 'actionscheduler_logs', [ 'action_id' => (int) $row['action_id'] ] ); $this->db->delete( $this->db->prefix . 'actionscheduler_actions', [ 'action_id' => (int) $row['action_id'] ] ); }
		$original_groups = array_map( 'intval', array_column( $this->queues['groups'], 'group_id' ) );
		foreach ( array_unique( $owned_groups ) as $group_id ) { if ( in_array( $group_id, $original_groups, true ) ) { continue; } $rows = $this->rows( $this->db->prepare( "SELECT * FROM `{$this->db->prefix}actionscheduler_groups` WHERE group_id=%d LIMIT 2", $group_id ) ); $remaining = $this->rows( $this->db->prepare( "SELECT action_id FROM `{$this->db->prefix}actionscheduler_actions` WHERE group_id=%d LIMIT 1", $group_id ) ); if ( 1 === count( $rows ) && 'woocommerce-db-updates' === $rows[0]['slug'] && [] === $remaining ) { $this->db->delete( $this->db->prefix . 'actionscheduler_groups', [ 'group_id' => $group_id, 'slug' => 'woocommerce-db-updates' ] ); } }
		$queue = $jobs_owned && $this->queues === $this->queue_rows();
		$adoption = $this->adoption_off_unchanged(); $history = $this->baseline === $this->census(); wp_set_current_user( $this->user );
		return [ 'owned_orders_and_products_removed' => $owned, 'original_business_and_native_rows_unchanged' => $history, 'original_queue_rows_unchanged_and_unclaimed' => $queue, 'default_adoption_off_unchanged' => $adoption, 'all_owned_connections_retired' => $connections, 'original_actor_restored' => get_current_user_id() === $this->user ];
	}
}

return static function ( callable $check, string $mode = 'hpos_on', int $installed_count = 0 ): void {
	global $wpdb;
	if ( '1' !== getenv( 'CETECH_DE_NATIVE_OPENING_QUALIFICATION' ) || ! $wpdb instanceof wpdb || wpdb::class !== get_class( $wpdb ) || ( 'hpos_on' === $mode ) !== Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) { throw new RuntimeException( 'N17 native module requires its marked storage fixture.' ); }
	$fixture = new CetechPromotionNativeFixture( $wpdb, $mode ); $failure = null;
	$refuses = static function ( callable $work ): bool { try { $work(); return false; } catch ( Throwable ) { return true; } };
	try {
		$check( 'NATIVE-N17-INSTALLED-DEFAULT-OFF', true, [ 'actual_wordpress_and_woo' => defined( 'ABSPATH' ) && class_exists( WC_Order::class ), 'installed1004_production_php' => 1004 === $installed_count, 'installed_schema12' => '12' === (string) get_option( 'cetech_de_db_version' ), 'adoption_off_unchanged' => $fixture->adoption_off_unchanged() ] );
		$fixture->prepare(); $seed = $fixture->seed; $auth = [ $fixture, 'authorize' ]; $order = $fixture->order;
		$mapping = $fixture->stager->promotion_mapping( $order, $seed['evidence'], $auth ); $guard = $fixture->capture();
		$check( 'NATIVE-N17-ACTUAL-NATIVE-ORDER-CAPTURE', null !== $guard, [ 'actual_saved_order_and_product' => $order->get_id() > 0 && $fixture->product->get_id() > 0, 'complete_actual_item_mapping' => $mapping === $seed['mapping'], 'format3_accepted_quote' => 3 === $seed['quote']->header()->format_version() && 'accepted' === $seed['quote']->state(), 'physical_guard_captured' => null !== $guard ] );
		$denied = null === $fixture->capture( static fn( WC_Order $o ): bool => false ); $actor = get_current_user_id(); wp_set_current_user( 0 ); try { $guest = null === $fixture->capture(); } finally { wp_set_current_user( $actor ); }
		$check( 'NATIVE-N17-FOREIGN-AND-GUEST-AUTHORITY-REFUSE', $denied && $guest, [ 'explicit_foreign_denial' => $denied, 'guest_without_owned_order_access_denied' => $guest, 'physical_capture_needs_exact_order_authority' => $refuses( fn() => $fixture->stager->promotion_mapping( $order, $seed['evidence'], static fn( WC_Order $o ): bool => false ) ) ] );
		$ack = PromotionNativeHandoffFixture::acknowledged( $seed, $order->get_id(), $mapping, $guard->evidence_digest() );
		$wp_connection = (string) $wpdb->get_var( 'SELECT CONNECTION_ID()' ); $distinct = $fixture->factory->run( static fn( OperationSession $s ): bool => (string) $s->get_row( 'SELECT CONNECTION_ID() AS id' )['id'] !== $wp_connection );
		$check( 'NATIVE-N17-OWNED-CURRENT-SQL-POSITIVE', $fixture->verify_order( $guard, $ack->binding ), [ 'real_distinct_owned_connection' => $distinct, 'current_native_physical_evidence_matches' => $fixture->verify_order( $guard, $ack->binding ), 'synthetic_typed_ack_is_prepared1_only' => 'prepared' === $ack->binding->state() && 1 === $ack->binding->revision() ] );
		$mutations = []; foreach ( [ 'customer', 'key', 'status', 'paid', 'money', 'address' ] as $name ) { $mutations[$name . '_mutation_refused'] = $fixture->order_mutation( $name, fn(): bool => ! $fixture->verify_order( $guard, $ack->binding ) ); }
		$check( 'NATIVE-N17-PHYSICAL-IDENTITY-MONEY-PAID-FENCE', ! in_array( false, $mutations, true ), [ 'customer_mutation_refused' => $mutations['customer_mutation_refused'], 'key_mutation_refused' => $mutations['key_mutation_refused'], 'status_mutation_refused' => $mutations['status_mutation_refused'], 'paid_mutation_refused' => $mutations['paid_mutation_refused'], 'money_mutation_refused' => $mutations['money_mutation_refused'], 'address_mutation_refused' => $mutations['address_mutation_refused'] ] );
		$line_id = $mapping['groups'][0]['lines'][0]['item_id']; $shipping = array_values( $order->get_items( 'shipping' ) )[0]; $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$mapping_bad = $mapping; ++$mapping_bad['groups'][0]['lines'][0]['item_id'];
		$line_refused = $fixture->mutate( $meta, [ 'order_item_id' => $line_id, 'meta_key' => '_qty' ], [ 'meta_value' => '3' ], fn(): bool => ! $fixture->verify_order( $guard, $ack->binding ) );
		$shipping_refused = $fixture->mutate( $meta, [ 'order_item_id' => $shipping->get_id(), 'meta_key' => 'cost' ], [ 'meta_value' => '10.50' ], fn(): bool => ! $fixture->verify_order( $guard, $ack->binding ) );
		$tax_refused = $fixture->mutate( $meta, [ 'order_item_id' => $line_id, 'meta_key' => '_line_tax_data' ], [ 'meta_value' => serialize( [ 'total' => [ 999 => '1.00' ], 'subtotal' => [ 999 => '1.00' ] ] ) ], fn(): bool => ! $fixture->verify_order( $guard, $ack->binding ) );
		$check( 'NATIVE-N17-COMPLETE-LINE-SHIPPING-TAX-MAPPING', $line_refused && $shipping_refused && $tax_refused, [ 'line_quantity_mutation_refused' => $line_refused, 'shipping_mutation_refused' => $shipping_refused, 'native_tax_map_mutation_refused' => $tax_refused, 'foreign_actual_item_mapping_refused' => null === $fixture->capture( mapping: $mapping_bad ), 'original_physical_facts_restored' => $fixture->verify_order( $guard, $ack->binding ) ] );
		$money = $fixture->native_money(); $saved = $fixture->stager->stage_promotion( $order, $ack, $seed['draft'], $seed['tax_source'], 'classic', $auth ); $fresh = $fixture->fresh(); $package = ( new OrderDeliverySnapshotReader() )->read_package( $fresh ); $lines_supported = true; foreach ( $fresh->get_items( 'line_item' ) as $item ) { $line = ( new OrderDeliverySnapshotReader() )->read_line( $item ); $lines_supported = $lines_supported && 'recorded' === $line->delivery_quote?->status && 3 === $line->delivery_quote?->envelope?->format(); }
		$check( 'NATIVE-N17-OUTER4-MARKER3-SAVED-READER', QuoteNativeOrderHistory::verify( $fresh ), [ 'actual_saved_outer4' => '4' === $fresh->get_meta( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true ), 'actual_saved_marker3' => '3' === $fresh->get_meta( DeliveryQuoteSnapshotEnvelope::META_FORMAT, true ), 'package_format3_supported' => 'recorded' === $package->delivery_quote?->status && 3 === $package->delivery_quote?->envelope?->format(), 'every_actual_line_format3_supported' => $lines_supported, 'native_totals_status_paid_time_unchanged' => $money === $fixture->native_money() ] );
		$bytes = $fixture->snapshot_bytes(); $repeat = $fixture->stager->stage_promotion( $order, $ack, $seed['draft'], $seed['tax_source'], 'classic', $auth );
		$check( 'NATIVE-N17-EXACT-PREPARED1-RETRY-PRESERVES-BYTES', $bytes === $fixture->snapshot_bytes(), [ 'protected_order_and_line_bytes_identical' => $bytes === $fixture->snapshot_bytes(), 'captured_snapshot_digest_identical' => $saved->snapshot_digest() === $repeat->snapshot_digest(), 'captured_context_digest_identical' => $saved->context_digest() === $repeat->context_digest(), 'native_money_and_paid_time_identical' => $money === $fixture->native_money() ] );
		$order->update_meta_data( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, '{"broken":true}' ); $order->save(); $broken = $fixture->snapshot_bytes();
		try { $malformed_refused = $refuses( fn() => $fixture->stager->stage_promotion( $order, $ack, $seed['draft'], $seed['tax_source'], 'classic', $auth ) ); $not_overwritten = $broken === $fixture->snapshot_bytes(); }
		finally { $raw = $bytes['order']; foreach ( $raw as $row ) { if ( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT === $row['meta_key'] ) { $order->update_meta_data( $row['meta_key'], $row['meta_value'] ); } } $order->save(); }
		$check( 'NATIVE-N17-OWNED-MALFORMED-HISTORY-NO-OVERWRITE', $malformed_refused && $not_overwritten, [ 'malformed_owned_packet_refused' => $malformed_refused, 'malformed_private_bytes_not_replaced' => $not_overwritten, 'original_packet_bytes_restored' => $bytes === $fixture->snapshot_bytes() ] );
		$verified_row = array_replace( $ack->binding->row(), [ 'revision' => 2, 'verified_at' => $ack->binding->row()['created_at'], 'snapshot_digest' => $saved->snapshot_digest(), 'context_digest' => $saved->context_digest() ] ); $verified = QuoteBinding::from_row( $verified_row, $seed['quote'] ); $sealed = QuoteBinding::from_row( array_replace( $verified_row, [ 'state' => 'sealed', 'revision' => 3, 'sealed_at' => $verified_row['verified_at'] ] ), $seed['quote'] );
		$saved_guard = $fixture->stager->saved_promotion_guard( $order, $seed['quote'], $verified, $auth );
		$check( 'NATIVE-N17-SAVED-GUARD-PREPARED2-SEALED3', $fixture->verify_saved( $saved_guard, $verified ) && $fixture->verify_saved( $saved_guard, $sealed ), [ 'prepared2_exact_saved_physical_facts' => $fixture->verify_saved( $saved_guard, $verified ), 'sealed3_same_saved_physical_facts' => $fixture->verify_saved( $saved_guard, $sealed ), 'original_order_guard_survives_only_protected_metadata' => $fixture->verify_order( $guard, $ack->binding ), 'synthetic_later_bindings_grant_no_payment' => null === $fixture->fresh()->get_date_paid( 'edit' ) ] );
		$table = $wpdb->prefix . ( 'hpos_on' === $mode ? 'wc_orders_meta' : 'postmeta' ); $parent = 'hpos_on' === $mode ? 'order_id' : 'post_id';
		$packet_refused = $fixture->mutate( $table, [ $parent => $order->get_id(), 'meta_key' => OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT ], [ 'meta_value' => '{"changed":true}' ], fn(): bool => ! $fixture->verify_saved( $saved_guard, $verified ) );
		$check( 'NATIVE-N17-SECOND-CONNECTION-SAVED-BYTES-FENCE', $packet_refused, [ 'physical_packet_mutation_refused' => $packet_refused, 'separate_native_connection_proved' => $distinct, 'exact_restored_saved_guard_matches' => $fixture->verify_saved( $saved_guard, $verified ), 'native_totals_paid_time_and_adoption_unchanged' => $money === $fixture->native_money() && $fixture->adoption_off_unchanged() ] );
	} catch ( Throwable $error ) { $failure = $error; throw $error; }
	finally {
		try { $cleanup = $fixture->cleanup(); $check( 'NATIVE-N17-TRACKED-NATIVE-CLEANUP', ! in_array( false, $cleanup, true ), [ 'owned_orders_and_products_removed' => $cleanup['owned_orders_and_products_removed'], 'original_business_and_native_rows_unchanged' => $cleanup['original_business_and_native_rows_unchanged'], 'original_queue_rows_unchanged_and_unclaimed' => $cleanup['original_queue_rows_unchanged_and_unclaimed'], 'default_adoption_off_unchanged' => $cleanup['default_adoption_off_unchanged'], 'all_owned_connections_retired' => $cleanup['all_owned_connections_retired'], 'original_actor_restored' => $cleanup['original_actor_restored'] ] ); }
		catch ( Throwable $cleanup_error ) { if ( null === $failure ) { throw $cleanup_error; } }
	}
};
