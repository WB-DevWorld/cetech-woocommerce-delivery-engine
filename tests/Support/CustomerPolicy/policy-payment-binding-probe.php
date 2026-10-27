<?php
declare(strict_types=1);
// Isolated pinned native data/changes shape; actual Woo route proof is separate.
require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
use CetechDeliveryEngine\Integrations\CustomerPolicy\PolicyNativePaymentLocalBinding;
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteOrderPayLocalBinding;

class WC_Data {
	protected int $id; protected array $data; protected array $changes = []; protected array $meta_data = [];
	public function __construct( int $id, array $data ) { $this->id = $id; $this->data = $data; }
	public function change( string $key, mixed $value ): void { $this->changes[$key] = $value; }
	public function apply_changes(): void { $this->data = array_replace_recursive( $this->data, $this->changes ); $this->changes = []; }
	public function meta( WC_Meta_Data $meta ): void { $this->meta_data[] = $meta; }
}
class WC_Order_Item_Product extends WC_Data {public function raw_policy():WC_Meta_Data{return $this->meta_data[0];}}
class WC_Order_Item_Shipping extends WC_Data {}
class WC_Order_Item_Tax extends WC_Data {}
class WC_Order_Item_Fee extends WC_Data {}
class WC_Meta_Data {
	protected array $data; protected array $current_data;
	public function __construct( string $key, string $value ) { $this->data = $this->current_data = [ 'id' => 21, 'key' => $key, 'value' => $value ]; }
	public function change( string $value ): void { $this->current_data['value'] = $value; }
	public function hostile(): void { $this->current_data['value'] = new PolicyPoison(); }
}
class WC_DateTime extends DateTime {}
class WC_Order extends WC_Data {
	protected array $items;
	public function __construct() {
		parent::__construct( 100, [ 'status' => 'pending', 'total' => '12.50', 'currency' => 'GHS', 'customer_id' => 7, 'order_key' => 'wc_order_q06_native_key', 'payment_method' => 'old_gateway', 'payment_method_title' => 'Old title', 'date_modified' => new WC_DateTime( '2026-10-08 00:00:00', new DateTimeZone( 'UTC' ) ), 'billing' => [ 'address_1' => 'Original billing', 'country' => 'GH' ], 'shipping' => [ 'address_1' => 'Original shipping', 'country' => 'GH' ] ] );
		$this->items = [ 'line_items' => [ 10 => new WC_Order_Item_Product( 10, [ 'total' => '10.00', 'subtotal' => '10.00', 'taxes' => [ 'total' => [ 7 => '0.5', 8 => '0.75' ], 'subtotal' => [ 7 => '0.5', 8 => '0.75' ] ] ] ) ], 'shipping_lines' => [], 'tax_lines' => [], 'fee_lines' => [], 'coupon_lines' => [] ];
		$this->meta( new WC_Meta_Data( '_cetech_de_delivery_quote_format', '1' ) );
		// Opaque large strings exercise raw-byte guards, not valid policy packet authority.
		for($i=0;$i<8;++$i){$item=new WC_Order_Item_Product(10+$i,['total'=>'10.00','taxes'=>[]]);$item->meta(new WC_Meta_Data('_cetech_de_policy_packet',str_repeat('x',250000)));$this->items['line_items'][10+$i]=$item;}
		$this->meta(new WC_Meta_Data('_cetech_de_policy_manifest',str_repeat('m',32000)));
		$this->meta(new WC_Meta_Data('_cetech_de_policy_attempt','550e8400-e29b-41d4-a716-446655440000'));
	}
	public function line(): WC_Order_Item_Product { return $this->items['line_items'][10]; }
	public function protected_meta(): WC_Meta_Data { return $this->meta_data[0]; }
	public function policy_mutate(string $mode):void { if('policy_byte'===$mode){$this->items['line_items'][10]->raw_policy()->change(str_repeat('y',250000));}elseif('hostile'===$mode){$this->items['line_items'][10]->raw_policy()->hostile();}elseif('policy_row'===$mode){$this->items['line_items'][10]->meta(new WC_Meta_Data('_cetech_de_policy_format','1'));}elseif('attempt_byte'===$mode){$this->meta_data[2]->change('550e8400-e29b-41d4-a716-446655440001');}elseif('attempt_overflow'===$mode){$this->meta_data[2]->change(str_repeat('a',37));}elseif('status'===$mode){$this->change('status','processing');} }
	public function add_fee(): void { $this->items['fee_lines'][] = new WC_Order_Item_Fee( 12, [ 'total' => '0.00' ] ); }
}
class ForeignOrder extends WC_Order {}
class PolicyPoison implements JsonSerializable { public static int $calls=0; public function jsonSerialize():mixed {++self::$calls;throw new RuntimeException('must not execute');} }
$GLOBALS['blog_id'] = 1;
$order = new WC_Order(); $old_refused=false;try{QuoteOrderPayLocalBinding::capture($order);}catch(Throwable){$old_refused=true;} $binding = PolicyNativePaymentLocalBinding::capture( $order ); $mode = $argv[1] ?? 'same'; $fresh = new WC_Order();
switch ( $mode ) {
	case 'policy_byte':case 'policy_row':case 'hostile':case 'status':case 'attempt_byte':case 'attempt_overflow': $order->policy_mutate($mode);break;
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
echo json_encode( [ 'old_refused'=>$old_refused,'unchanged' => $binding->unchanged(), 'same_native' => $binding->same_native_facts( $fresh ), 'selected_method' => $binding->payment_matches( 'new_gateway', 'New title' ), 'private_encodings_refused' => $private,'hostile_calls'=>PolicyPoison::$calls ], JSON_THROW_ON_ERROR );
