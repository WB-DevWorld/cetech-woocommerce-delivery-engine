<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( string $scheme = 'auth' ): string { return $GLOBALS['cetech_de_n17_native_identity_key'] ?? 'private-promotion-native-handoff-fixture-key'; } }
}

namespace CetechDeliveryEngine\Tests\Support\DeliveryPromotion {

use CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Integrations\DeliveryQuote\FixedPromotionNativeOrderEvidence;

require_once __DIR__ . '/PromotionNativeHandoffFixture.php';

/** Explicit unit model: scalar SQL snapshots never invoke a native getter. */
final class PromotionNativeUnitFixture {
	public static function create( bool $hpos = false ): array {
		$fixture = PromotionNativeHandoffFixture::create();
		$GLOBALS['blog_id'] = 1; $GLOBALS['current_user'] = (object) [ 'ID' => 77, 'caps' => [ 'manage_woocommerce' => true ], 'allcaps' => [ 'manage_woocommerce' => true ], 'roles' => [ 'shop_manager' ] ];
		unset( $GLOBALS['woocommerce'] ); $_GET = []; $_POST = [];
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) { class_alias( PromotionNativeUnitMode::class, '\Automattic\WooCommerce\Utilities\OrderUtil' ); }
		foreach ( [ 'enabled', 'hpos' ] as $property ) { if ( property_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', $property ) ) { \Automattic\WooCommerce\Utilities\OrderUtil::${$property} = $hpos; } }
		$order = new PromotionNativeUnitOrder( $fixture ); $factory = new PromotionNativeUnitFactory( $order, $hpos ); $order->factory = $factory; $factory->refresh();
		$mapping = $fixture['mapping']; $guard = FixedPromotionNativeOrderEvidence::capture( $factory, $order, $fixture['quote'], $mapping, $fixture['tax_source'], static fn(): bool => true );
		$candidate = null === $guard ? null : PromotionNativeHandoffFixture::acknowledged( $fixture, 100, $mapping, $guard->evidence_digest() )->binding;
		return compact( 'fixture', 'order', 'factory', 'mapping', 'guard', 'candidate' );
	}
}

final class PromotionNativeUnitMode {
	public static bool $enabled = false;
	public static function custom_orders_table_usage_is_enabled(): bool { return self::$enabled; }
}

class PromotionNativeUnitOrder extends \WC_Order {
	protected int $id = 100;
	protected array $data = [ 'customer_id' => 0, 'order_key' => 'wc_order_private_promotion_unit' ];
	protected array $changes = [];
	public array $lines; public array $shipping; public array $meta = []; public array $fees = []; public array $coupons = []; public array $tax_items = [];
	public int $gets = 0; public int $saves = 0; public string $state = 'pending'; public mixed $date_paid = null; public bool $paid = false;
	public string $total = '34.50'; public string $cart_tax = '0'; public string $shipping_total = '9.50'; public string $shipping_tax = '0'; public string $total_tax = '0';
	public ?\Closure $on_get = null; public ?PromotionNativeUnitFactory $factory = null;
	public function __construct( public array $fixture ) { parent::__construct( [ 'id' => 100 ] ); $this->lines = [ new PromotionNativeUnitLine( $this ) ]; $this->shipping = [ new PromotionNativeUnitShipping( $this ) ]; }
	public function __clone() { $this->lines = array_map( static fn( object $item ): object => clone $item, $this->lines ); $this->shipping = array_map( static fn( object $item ): object => clone $item, $this->shipping ); }
	public function touch(): void { ++$this->gets; if ( null !== $this->on_get ) { ( $this->on_get )( $this ); } }
	public function get_id(): int { $this->touch(); return $this->id; }
	public function get_items( string $type = '' ): array { $this->touch(); return match ( $type ) { 'shipping' => $this->shipping, 'fee' => $this->fees, 'coupon' => $this->coupons, 'tax' => $this->tax_items, default => $this->lines }; }
	public function get_currency( string $context = 'view' ): string { $this->touch(); return 'GHS'; }
	public function get_customer_id( string $context = 'view' ): int { $this->touch(); return array_replace( $this->data, $this->changes )['customer_id']; }
	public function get_order_key( string $context = 'view' ): string { $this->touch(); return array_replace( $this->data, $this->changes )['order_key']; }
	public function get_total( string $context = 'view' ): string { $this->touch(); return $this->total; }
	public function get_total_tax( string $context = 'view' ): string { $this->touch(); return $this->total_tax; }
	public function get_cart_tax( string $context = 'view' ): string { $this->touch(); return $this->cart_tax; }
	public function get_shipping_total( string $context = 'view' ): string { $this->touch(); return $this->shipping_total; }
	public function get_shipping_tax( string $context = 'view' ): string { $this->touch(); return $this->shipping_tax; }
	public function get_status(): string { $this->touch(); return $this->state; }
	public function is_paid(): bool { $this->touch(); return $this->paid; }
	public function get_date_paid( $context = 'view' ): mixed { $this->touch(); return $this->date_paid; }
	public function get_shipping_country( string $context = 'view' ): string { $this->touch(); return $this->fixture['destination']['country']; }
	public function get_shipping_state( string $context = 'view' ): string { $this->touch(); return $this->fixture['destination']['state']; }
	public function get_shipping_city( string $context = 'view' ): string { $this->touch(); return $this->fixture['destination']['city']; }
	public function get_shipping_postcode( string $context = 'view' ): string { $this->touch(); return $this->fixture['destination']['postcode']; }
	public function get_shipping_address_1( string $context = 'view' ): string { $this->touch(); return $this->fixture['destination']['address']; }
	public function get_shipping_address_2( string $context = 'view' ): string { $this->touch(); return $this->fixture['destination']['address_2']; }
	public function get_meta( string $key, bool $single = true ): mixed { $this->touch(); return $this->meta[$key] ?? ''; }
	public function meta_exists( string $key ): bool { $this->touch(); return array_key_exists( $key, $this->meta ); }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[$key] = $value; }
	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void { $this->meta[$key] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[$key] ); }
	public function save(): void { ++$this->saves; $this->factory?->refresh(); }
	public function read_meta_data( bool $force = false ): void {}
	public function change_auth( string $key, mixed $value ): void { $this->changes[$key] = $value; }
}

class PromotionNativeUnitLine extends \WC_Order_Item_Product {
	public string $amount = '25'; public int $product = 10; public int $variation = 0; public int $quantity = 2; public array $meta = []; public int $saves = 0;
	public function __construct( private PromotionNativeUnitOrder $order ) { parent::__construct( [ 'id' => 901, 'product_id' => 10, 'quantity' => 2 ] ); }
	public function get_id(): int { $this->order->touch(); return 901; }
	public function get_order_id( string $context = 'view' ): int { $this->order->touch(); return 100; }
	public function get_product_id(): int { $this->order->touch(); return $this->product; }
	public function get_variation_id(): int { $this->order->touch(); return $this->variation; }
	public function get_quantity(): int { $this->order->touch(); return $this->quantity; }
	public function get_total( string $context = 'view' ): string { $this->order->touch(); return $this->amount; }
	public function get_total_tax( string $context = 'view' ): string { $this->order->touch(); return '0'; }
	public function get_subtotal( string $context = 'view' ): string { $this->order->touch(); return '25'; }
	public function get_subtotal_tax( string $context = 'view' ): string { $this->order->touch(); return '0'; }
	public function get_taxes( string $context = 'view' ): array { $this->order->touch(); return [ 'total' => [], 'subtotal' => [] ]; }
	public function get_meta( string $key, bool $single = true ): mixed { $this->order->touch(); return $this->meta[$key] ?? ''; }
	public function meta_exists( string $key ): bool { $this->order->touch(); return array_key_exists( $key, $this->meta ); }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[$key] = $value; }
	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void { $this->meta[$key] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[$key] ); }
	public function save(): void { ++$this->saves; $this->order->factory?->refresh(); }
}

class PromotionNativeUnitShipping extends \WC_Order_Item_Shipping {
	public string $amount = '9.50'; public string $label = 'Delivery'; public string $group;
	public function __construct( private PromotionNativeUnitOrder $order ) { parent::__construct(); $this->group = $order->fixture['group']; }
	public function get_id(): int { $this->order->touch(); return 902; }
	public function get_method_id( string $context = 'view' ): string { $this->order->touch(); return 'delivery_engine_selected_offer'; }
	public function get_instance_id( string $context = 'view' ): int { $this->order->touch(); return 0; }
	public function get_total(): string { $this->order->touch(); return $this->amount; }
	public function get_total_tax( string $context = 'view' ): string { $this->order->touch(); return '0'; }
	public function get_name( string $context = 'view' ): string { $this->order->touch(); return $this->label; }
	public function get_taxes( string $context = 'view' ): array { $this->order->touch(); return [ 'total' => [] ]; }
	public function get_meta( string $key, bool $single = true ): mixed { $this->order->touch(); return 'cetech_de_group_id' === $key ? $this->group : ''; }
}

class PromotionNativeUnitFactory implements OperationConnectionFactory {
	public array $physical = []; public array $lifecycle_order = []; public array $lifecycle_paid = []; public array $mode_rows;
	public int $opens = 0; public int $rollbacks = 0; public int $retirements = 0; public int $commits = 0; public int $queries = 0;
	public array $sql = []; public array $sessions = []; public bool $refuse_rollback = false; public bool $refuse_retire = false; public bool $refuse_tables = false; public bool $refuse_begin = false;
	public int $site = 1; public string $prefix = 'wp_'; public string $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	public ?\Closure $on_open = null; public ?\Closure $on_read = null; public ?\Closure $on_metadata = null; public ?\Closure $on_rollback = null; public ?\Closure $on_retire = null;
	public function __construct( public PromotionNativeUnitOrder $order, public bool $hpos ) { $this->mode_rows = [ [ 'option_id' => '1', 'option_name' => 'woocommerce_custom_orders_table_enabled', 'option_value' => $hpos ? 'yes' : 'no' ] ]; }
	public function open(): OperationSession { ++$this->opens; $session = new PromotionNativeUnitSession( $this ); $this->sessions[] = $session; if ( null !== $this->on_open ) { ( $this->on_open )( $session, $this ); } return $session; }
	/** Native saves happen outside SQL; SQL returns this scalar snapshot unchanged. */
	public function refresh(): void {
		$o = $this->order; $native = QuoteNativeOrderFacts::native( $o ); $items = []; $meta = [];
		foreach ( $native['lines'] as $line ) {
			$items[] = [ 'order_item_id' => (string) $line['id'], 'order_item_type' => 'line_item', 'order_item_name' => 'Product', 'order_id' => '100' ];
			$values = [ '_product_id' => (string) $line['product_id'], '_variation_id' => (string) $line['variation_id'], '_qty' => $line['quantity'], '_line_total' => $line['total'], '_line_tax' => $line['tax'], '_line_subtotal' => $line['subtotal'], '_line_subtotal_tax' => $line['subtotal_tax'], '_line_tax_data' => serialize( $line['taxes'] ) ];
			foreach ( $o->lines as $object ) { if ( $object->get_id() === $line['id'] ) { $values = array_replace( $values, $object->meta ); } }
			foreach ( $values as $key => $value ) { $meta[] = [ 'order_item_id' => (string) $line['id'], 'meta_key' => $key, 'meta_value' => (string) $value ]; }
		}
		foreach ( $native['shipping'] as $line ) {
			$items[] = [ 'order_item_id' => (string) $line['id'], 'order_item_type' => 'shipping', 'order_item_name' => $line['label'], 'order_id' => '100' ];
			foreach ( [ 'method_id' => $line['method_id'], 'instance_id' => (string) $line['instance_id'], 'cost' => $line['total'], 'total_tax' => $line['tax'], 'taxes' => serialize( $line['taxes'] ), 'cetech_de_group_id' => $line['group_id'] ] as $key => $value ) { $meta[] = [ 'order_item_id' => (string) $line['id'], 'meta_key' => $key, 'meta_value' => $value ]; }
		}
		usort( $items, static fn( array $a, array $b ): int => (int) $a['order_item_id'] <=> (int) $b['order_item_id'] );
		usort( $meta, static fn( array $a, array $b ): int => [ (int) $a['order_item_id'], $a['meta_key'], $a['meta_value'] ] <=> [ (int) $b['order_item_id'], $b['meta_key'], $b['meta_value'] ] );
		$this->physical = [ 'items' => $items, 'item_meta' => $meta ]; $order_meta = $o->meta;
		if ( $this->hpos ) {
			$this->physical['order'] = [ [ 'id' => '100', 'status' => 'wc-' . $o->state, 'currency' => $native['currency'], 'total_amount' => $native['total'], 'tax_amount' => $native['cart_tax'], 'customer_id' => (string) $native['customer_id'] ] ];
			$this->physical['money'] = [ [ 'order_id' => '100', 'order_key' => $native['order_key'], 'shipping_total_amount' => $native['shipping_total'], 'shipping_tax_amount' => $native['shipping_tax'] ] ];
			$this->physical['address'] = [ [ 'order_id' => '100', 'address_type' => 'shipping', ...$native['address'] ] ];
			$this->lifecycle_order = [ [ 'id' => '100', 'type' => 'shop_order', 'parent_order_id' => '0' ] ]; $this->lifecycle_paid = [ [ 'order_id' => '100', 'date_paid_gmt' => $o->date_paid ] ];
		} else {
			$this->physical['order'] = [ [ 'ID' => '100', 'post_type' => 'shop_order', 'post_status' => 'wc-' . $o->state ] ];
			$order_meta = array_replace( [ '_order_key' => $native['order_key'], '_order_currency' => $native['currency'], '_order_total' => $native['total'], '_order_tax' => $native['cart_tax'], '_order_shipping' => $native['shipping_total'], '_order_shipping_tax' => $native['shipping_tax'], '_customer_user' => (string) $native['customer_id'] ], $order_meta );
			foreach ( $native['address'] as $key => $value ) { $order_meta['_shipping_' . $key] = $value; }
			$this->lifecycle_order = [ [ 'ID' => '100', 'post_type' => 'shop_order', 'post_parent' => '0' ] ]; $this->lifecycle_paid = null === $o->date_paid ? [] : [ [ 'meta_key' => '_date_paid', 'meta_value' => $o->date_paid ] ];
		}
		ksort( $order_meta, SORT_STRING ); $this->physical['order_meta'] = []; foreach ( $order_meta as $key => $value ) { $this->physical['order_meta'][] = [ 'meta_key' => $key, 'meta_value' => (string) $value ]; }
	}
}

class PromotionNativeUnitSession implements OperationSession {
	public bool $active = false; public bool $retired = false;
	public function __construct( public PromotionNativeUnitFactory $factory ) {}
	private function metadata( string $name, mixed $value ): mixed { if ( null !== $this->factory->on_metadata ) { return ( $this->factory->on_metadata )( $name, $value, $this ); } return $value; }
	public function site_id(): int { return $this->metadata( 'site', $this->factory->site ); }
	public function table_prefix(): string { return $this->metadata( 'prefix', $this->factory->prefix ); }
	public function charset_collate(): string { return $this->metadata( 'charset', $this->factory->charset ); }
	public function begin(): bool { $this->active = ! $this->factory->refuse_begin; return $this->active; }
	public function commit(): OperationCommitResult { ++$this->factory->commits; throw new \LogicException( 'No commit in a native read proof.' ); }
	public function rollback(): bool { ++$this->factory->rollbacks; if ( null !== $this->factory->on_rollback ) { ( $this->factory->on_rollback )( $this ); } $this->active = false; return ! $this->factory->refuse_rollback; }
	public function retire(): bool { ++$this->factory->retirements; if ( null !== $this->factory->on_retire ) { ( $this->factory->on_retire )( $this ); } $this->retired = ! $this->factory->refuse_retire; return $this->retired; }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->active; }
	public function validate_tables( array $table_names ): bool { return $this->active && ! $this->factory->refuse_tables; }
	public function query( string $sql ): int|false { ++$this->factory->queries; throw new \LogicException( 'No DML in a native read proof.' ); }
	public function get_row( string $sql ): array|null|false { $rows = $this->get_results( $sql ); return false === $rows ? false : ( $rows[0] ?? null ); }
	public function get_results( string $sql ): array|false {
		$f = $this->factory; $f->sql[] = $sql; if ( null !== $f->on_read ) { ( $f->on_read )( $sql, $this ); }
		if ( str_contains( $sql, 'woocommerce_custom_orders_table_enabled' ) ) { return $f->mode_rows; }
		if ( str_contains( $sql, 'SELECT id,type,parent_order_id' ) || str_contains( $sql, 'SELECT ID,post_type,post_parent' ) ) { return $f->lifecycle_order; }
		if ( str_contains( $sql, 'date_paid_gmt' ) || str_contains( $sql, "meta_key IN ('_date_paid','_paid_date')" ) ) { return $f->lifecycle_paid; }
		if ( str_contains( $sql, 'woocommerce_order_itemmeta' ) ) { return $f->physical['item_meta']; }
		if ( str_contains( $sql, 'woocommerce_order_items' ) ) { return $f->physical['items']; }
		if ( str_contains( $sql, 'wc_order_operational_data' ) ) { return $f->physical['money']; }
		if ( str_contains( $sql, 'wc_order_addresses' ) ) { return $f->physical['address']; }
		if ( str_contains( $sql, 'wc_orders_meta' ) || str_contains( $sql, 'postmeta' ) ) { return $f->physical['order_meta']; }
		if ( str_contains( $sql, 'wc_orders' ) || str_contains( $sql, 'posts' ) ) { return $f->physical['order']; }
		return false;
	}
	public function prepare( string $sql, mixed ...$args ): string { $i = 0; return preg_replace_callback( '/%[ds]/', static function( array $m ) use ( $args, &$i ): string { $value = $args[$i++]; return '%d' === $m[0] ? (string) $value : "'" . str_replace( "'", "''", $value ) . "'"; }, $sql ); }
	public function errno(): int { return 0; }
	public function insert_id(): int { return 0; }
}
}
