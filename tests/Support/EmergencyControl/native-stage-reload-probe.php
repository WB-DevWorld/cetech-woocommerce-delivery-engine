<?php
declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order {
	/** Isolated saved-plan seam; runtime ordering and strong native bindings are production code. */
	final class QuoteNativeOrderStager {
		public int $checks = 0;
		public function saved_guard( \WC_Order $order, mixed $quote, mixed $binding ): object {
			++$this->checks;
			if ( [] !== $order->get_items( 'fee' ) || [] !== $order->get_items( 'coupon' ) ) { throw new \UnexpectedValueException( 'Unsupported native item.' ); }
			if ( 'guard_method' === \Calls::$mode ) { $order->set( 'payment_method', 'other_gateway' ); }
			if ( 'guard_title' === \Calls::$mode ) { $order->set( 'payment_method_title', 'Other payment' ); }
			return new class {
				public function snapshot_digest(): string { return 'snapshot' === \Calls::$mode ? 'changed' : 'snapshot'; }
				public function context_digest(): string { return 'context' === \Calls::$mode ? 'changed' : 'context'; }
			};
		}
	}
}

namespace {
	require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
	use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
	use CetechDeliveryEngine\Application\Order\{QuoteNativeOrderStager,QuoteNativeOrderStageResult};
	use CetechDeliveryEngine\Integrations\DeliveryQuote\{QuoteOrderPayLocalBinding,QuotePlacementRuntime};
	final class Calls { public static string $mode; }
	class WC_Meta_Data { protected array $data; protected array $current_data; public function __construct( array $data ) { $this->data = $this->current_data = $data; } }
	class WC_Data { protected array $data; protected array $changes = []; protected array $meta_data; protected int $id = 19; public function __construct( array $data ) { $this->data = $data; $this->meta_data = [ new WC_Meta_Data( [ 'id' => 1, 'key' => '_cetech_de_delivery_quote_format', 'value' => '1' ] ) ]; } public function set( string $key, mixed $value ): void { $this->changes[$key] = $value; } public function get_meta_data(): array { return $this->meta_data; } }
	class WC_Order_Item extends WC_Data {}
	class WC_Order_Item_Product extends WC_Order_Item {}
	class WC_Order_Item_Shipping extends WC_Order_Item {}
	class WC_Order_Item_Tax extends WC_Order_Item {}
	class WC_Abstract_Order extends WC_Data { protected array $items = []; public function get_items( string $type ): array { $key = [ 'line_item' => 'line_items', 'shipping' => 'shipping_lines', 'tax' => 'tax_lines', 'fee' => 'fee_lines', 'coupon' => 'coupon_lines' ][$type]; return $this->items[$key] ??= []; } public function groups(): array { return array_keys( $this->items ); } }
	class WC_Order extends WC_Abstract_Order {
		public function __construct() { parent::__construct( [ 'status' => 'pending', 'total' => '67.70', 'billing' => [ 'address_1' => 'PRIVATE ORIGINAL' ], 'payment_method' => 'native_gateway', 'payment_method_title' => 'Native payment', 'date_modified' => null ] ); $this->items = [ 'line_items' => [ 24 => new WC_Order_Item_Product( [ 'total' => '20.00', 'taxes' => [ 'total' => [ 1 => '2.00' ], 'subtotal' => [ 1 => '2.00' ] ] ] ) ], 'shipping_lines' => [], 'tax_lines' => [] ]; }
		public function get_payment_method( string $context ): string { return $this->changes['payment_method'] ?? $this->data['payment_method']; }
		public function get_payment_method_title( string $context ): string { return $this->changes['payment_method_title'] ?? $this->data['payment_method_title']; }
		public function mutate_meta(): void { $this->meta_data[] = new WC_Meta_Data( [ 'id' => 2, 'key' => '_cetech_de_quote_reference', 'value' => 'changed' ] ); }
		public function add_fee(): void { $this->items['fee_lines'] = [ 'unsupported-native-fee' ]; }
		public function add_coupon(): void { $this->items['coupon_lines'] = [ 'unsupported-native-coupon' ]; }
		public function native_created_shape(): void { $this->data['status'] = ''; $this->meta_data = [ new WC_Meta_Data( [ 'key' => '_native_a', 'value' => 2, 'id' => 2 ] ), new WC_Meta_Data( [ 'key' => '_native_b', 'value' => 'b', 'id' => 3 ] ), ...$this->meta_data ]; }
		public function native_saved_shape( bool $processed = false ): void { $this->meta_data = [ new WC_Meta_Data( [ 'id' => 3, 'key' => '_native_b', 'value' => 'b' ] ), new WC_Meta_Data( [ 'id' => 2, 'key' => '_native_a', 'value' => '2' ] ), ...$this->meta_data, new WC_Meta_Data( [ 'id' => 4, 'key' => '_native_c', 'value' => 'c' ] ) ]; $groups = $processed ? [ 'coupon_lines', 'line_items', 'tax_lines', 'shipping_lines', 'fee_lines' ] : [ 'line_items', 'fee_lines', 'coupon_lines', 'shipping_lines', 'tax_lines' ]; $next = []; foreach ( $groups as $key ) { $next[$key] = $this->items[$key] ?? []; } $this->items = $next; }
		public function add_unknown_group(): void { $this->items['unrecognized_lines'] = []; }
		public function change_native_metadata(): void { $this->meta_data[0] = new WC_Meta_Data( [ 'key' => '_native_a', 'value' => 'changed', 'id' => 2 ] ); }
	}
	class ForeignOrder extends WC_Order {}
	function get_current_blog_id(): int { return 1; }
	$GLOBALS['blog_id'] = 1; Calls::$mode = $argv[1] ?? 'matching';
	$saved_mode = str_starts_with( Calls::$mode, 'saved_' ); $mode = $saved_mode ? substr( Calls::$mode, 6 ) : Calls::$mode;
	$original = new WC_Order(); $original->get_items( 'fee' ); $original->get_items( 'coupon' );
	$fresh = 'foreign_class' === $mode ? new ForeignOrder() : new WC_Order(); $readback = new WC_Order(); $readback->get_items( 'fee' ); $readback->get_items( 'coupon' );
	if ( $saved_mode ) { $original->native_created_shape(); $readback->native_saved_shape(); $fresh->native_saved_shape( true ); }
	$local = EmergencyCheckoutLocalBinding::capture( $original ); if ( null === $local ) { throw new \RuntimeException( 'Native original capture failed.' ); }
	$native = QuoteOrderPayLocalBinding::capture( $original ); $before = $native->same_native_facts( $fresh );
	$stager = new QuoteNativeOrderStager(); $runtime = ( new \ReflectionClass( QuotePlacementRuntime::class ) )->newInstanceWithoutConstructor(); ( new \ReflectionProperty( $runtime, 'stager' ) )->setValue( $runtime, $stager );
	$evidence = new class { public function quote_record(): mixed { return null; } }; $binding = new class { public function row(): array { return [ 'snapshot_digest' => 'snapshot', 'context_digest' => 'context' ]; } };
	$bindings = $saved_mode ? ( new \ReflectionMethod( $runtime, 'stage_bindings' ) )->invoke( $runtime, $original, new QuoteNativeOrderStageResult( 1, 19, true, [], 'snapshot', 'context', [], $readback ) ) : [ 'created' => $original, 'local' => $local, 'original' => $native, 'native' => $native ];
	if ( 'original_money' === $mode ) { $original->set( 'total', '999.99' ); }
	if ( 'original_metadata' === $mode ) { $original->change_native_metadata(); }
	if ( 'method' === $mode ) { $fresh->set( 'payment_method', 'other_gateway' ); }
	if ( 'title' === $mode ) { $fresh->set( 'payment_method_title', 'Other payment' ); }
	if ( 'billing' === $mode ) { $fresh->set( 'billing', [ 'address_1' => 'PRIVATE CHANGED' ] ); }
	if ( 'line_money' === $mode ) { $fresh->get_items( 'line_item' )[24]->set( 'total', '999.99' ); }
	if ( 'metadata' === $mode ) { $fresh->mutate_meta(); }
	if ( 'fee' === $mode ) { $fresh->add_fee(); }
	if ( 'coupon' === $mode ) { $fresh->add_coupon(); }
	if ( 'unknown_group' === $mode ) { $fresh->add_unknown_group(); }
	if ( 'literal' === $mode ) { $fresh = $original; }
	$entry = [ ...$bindings, 'method' => 'native_gateway', 'title' => 'Native payment', 'evidence' => $evidence, 'binding' => $binding ];
	try { $matched = ( new \ReflectionMethod( $runtime, 'stage_matches' ) )->invoke( $runtime, $fresh, $entry ); } catch ( \Throwable ) { $matched = false; }
	echo json_encode( [ 'matched_before_prewarm' => $before, 'matched' => $matched, 'saved_checks' => $stager->checks, 'loaded_groups' => $fresh->groups(), 'original_unchanged' => $local->unchanged() ], JSON_THROW_ON_ERROR );
}
