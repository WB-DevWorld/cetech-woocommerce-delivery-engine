<?php
declare(strict_types=1);
// Isolated pinned native data/changes shape; actual Woo route proof is separate.
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteOrderPayLocalBinding;

class WC_Data {
	protected int $id; protected array $data; protected array $changes = []; protected array $meta_data = [];
	public function __construct( int $id, array $data ) { $this->id = $id; $this->data = $data; }
	public function change( string $key, mixed $value ): void { $this->changes[$key] = $value; }
	public function apply_changes(): void { $this->data = array_replace_recursive( $this->data, $this->changes ); $this->changes = []; }
	public function meta( WC_Meta_Data $meta ): void { $this->meta_data[] = $meta; }
}
class WC_Order_Item_Product extends WC_Data {}
class WC_Order_Item_Shipping extends WC_Data {}
class WC_Order_Item_Tax extends WC_Data {}
class WC_Order_Item_Fee extends WC_Data {}
class WC_Meta_Data {
	protected array $data; protected array $current_data;
	public function __construct( string $key, string $value ) { $this->data = $this->current_data = [ 'id' => 21, 'key' => $key, 'value' => $value ]; }
	public function change( string $value ): void { $this->current_data['value'] = $value; }
}
class WC_DateTime extends DateTime {}
class WC_Order extends WC_Data {
	protected array $items;
	public function __construct() {
		parent::__construct( 100, [ 'status' => 'pending', 'total' => '12.50', 'currency' => 'GHS', 'customer_id' => 7, 'order_key' => 'wc_order_q06_native_key', 'payment_method' => 'old_gateway', 'payment_method_title' => 'Old title', 'date_modified' => new WC_DateTime( '2026-10-08 00:00:00', new DateTimeZone( 'UTC' ) ), 'billing' => [ 'address_1' => 'Original billing', 'country' => 'GH' ], 'shipping' => [ 'address_1' => 'Original shipping', 'country' => 'GH' ] ] );
		$this->items = [ 'line_items' => [ 10 => new WC_Order_Item_Product( 10, [ 'total' => '10.00', 'subtotal' => '10.00', 'taxes' => [ 'total' => [ 7 => '0.5', 8 => '0.75' ], 'subtotal' => [ 7 => '0.5', 8 => '0.75' ] ] ] ) ], 'shipping_lines' => [], 'tax_lines' => [], 'fee_lines' => [], 'coupon_lines' => [] ];
		$this->meta( new WC_Meta_Data( '_cetech_de_delivery_quote_format', '1' ) );
	}
	public function line(): WC_Order_Item_Product { return $this->items['line_items'][10]; }
	public function protected_meta(): WC_Meta_Data { return $this->meta_data[0]; }
	public function add_fee(): void { $this->items['fee_lines'][] = new WC_Order_Item_Fee( 12, [ 'total' => '0.00' ] ); }
}
class ForeignOrder extends WC_Order {}
$GLOBALS['blog_id'] = 1;
$order = new WC_Order(); $binding = QuoteOrderPayLocalBinding::capture( $order ); $mode = $argv[1] ?? 'same'; $fresh = new WC_Order();
switch ( $mode ) {
	case 'method': $order->change( 'payment_method', 'new_gateway' ); $order->change( 'payment_method_title', 'New title' ); break;
	case 'native_save': $order->change( 'payment_method', 'new_gateway' ); $order->change( 'payment_method_title', 'New title' ); $order->change( 'date_modified', new WC_DateTime( '2026-10-08 00:00:01', new DateTimeZone( 'UTC' ) ) ); $order->apply_changes(); break;
	case 'line_money': $order->line()->change( 'total', '999.00' ); break;
	case 'billing': $order->change( 'billing', [ 'address_1' => 'Other billing' ] ); break;
	case 'tax_map_remove': $order->line()->change( 'taxes', [ 'total' => [ 7 => '0.5' ], 'subtotal' => [ 7 => '0.5' ] ] ); break;
	case 'protected_meta': $order->protected_meta()->change( '999' ); break;
	case 'fee': $order->add_fee(); break;
	case 'site': $GLOBALS['blog_id'] = 2; break;
	case 'reload_changed': $fresh->line()->change( 'total', '999.00' ); break;
	case 'reload_foreign': $fresh = new ForeignOrder(); break;
}
$private = 0; foreach ( [ static fn() => json_encode( $binding, JSON_THROW_ON_ERROR ), static fn() => serialize( $binding ) ] as $encode ) { try { $encode(); } catch ( LogicException ) { ++$private; } }
echo json_encode( [ 'unchanged' => $binding->unchanged(), 'same_native' => $binding->same_native_facts( $fresh ), 'selected_method' => $binding->payment_matches( 'new_gateway', 'New title' ), 'private_encodings_refused' => $private ], JSON_THROW_ON_ERROR );
