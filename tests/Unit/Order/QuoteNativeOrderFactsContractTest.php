<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts as Facts;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionNativeHandoffFixture as Fixture;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionNativeHandoffFixture.php';

/** Shared extraction contracts; these models do not claim a native order or C03 history. */
final class QuoteNativeOrderFactsContractTest extends TestCase {
	public function test_dynamic_fixture_keeps_actual_destination_identity_and_native_customer_scope(): void {
		$f = Fixture::create( [ 'product_id' => 71, 'variation_id' => 72, 'item_id' => 730, 'customer_id' => 9, 'site_id' => 4, 'table_prefix' => 'owned_' ] );
		$line = $f['draft']->private_facts()['lines'][0];
		self::assertSame( DeliveryGroupIdentity::forHistorical( $line['selection'], CustomerCartContext::fromArray( $line['customer_context'] ) ), $f['group'] );
		self::assertSame( 3, $f['quote']->header()->format_version() );
		self::assertTrue( $f['tax_source']->matches_context( $f['quote']->context() ) );
		$selectors = $f['tax_source']->private_facts()['source']['selectors'];
		self::assertSame( [ 9, 4, 'owned_' ], [ $selectors['customer_id'], $selectors['site_id'], $selectors['table_prefix'] ] );
		$terms = $f['quote']->terms()->private_facts()['groups'][0];
		self::assertSame( [ '12.5000', '3.0000', '9.5000' ], [ $terms['list']['amount'], $terms['promotion']['amount']['amount'], $terms['final']['amount'] ] );
		$mapped = $f['mapping']['groups'][0]['lines'][0];
		self::assertSame( [ 71, 72, 71, 730 ], [ $mapped['product_id'], $mapped['variation_id'], $mapped['parent_id'], $mapped['item_id'] ] );
	}

	public function test_immutable_fixture_seed_reuse_does_not_reuse_bind_acknowledgments(): void {
		$a = Fixture::create(); $b = Fixture::create();
		self::assertSame( $a['quote'], $b['quote'] );
		$one = Fixture::acknowledged( $a, 100, $a['mapping'], str_repeat( 'a', 64 ) );
		$two = Fixture::acknowledged( $b, 100, $b['mapping'], str_repeat( 'a', 64 ) );
		self::assertSame( 'accepted', $one->attempt->outcome->state );
		self::assertSame( 'prepared', $one->binding->state() );
		self::assertNotSame( $one->binding->row()['placement_uuid'], $two->binding->row()['placement_uuid'] );
		self::assertNull( $one->binding->row()['verified_at'] );
	}

	public function test_fixture_refuses_a_group_from_another_destination(): void {
		$this->expectException( \InvalidArgumentException::class );
		Fixture::create( [ 'group' => 'in_warehouse|delivery|20|0000000000000000' ] );
	}

	public function test_mapping_keeps_captured_identity_and_refuses_claimed_wrong_product(): void {
		$f = Fixture::create(); $order = new NativeFactsContractOrder();
		self::assertSame( $f['mapping'], Facts::mapping( $order, $f['quote'] ) );
		$order->lines[0] = new NativeFactsContractLine( 11 );
		$order->lines[0]->update_meta_data( Facts::META_LINE_KEY, 'line_one' );
		$this->expectException( \UnexpectedValueException::class ); Facts::mapping( $order, $f['quote'] );
	}

	public function test_native_capture_preserves_woo_product_and_grand_money_without_repricing(): void {
		$order = new NativeFactsContractOrder(); $before = Facts::native( $order );
		self::assertSame( [ '34.5', '9.5', '25', '2' ], [ $before['total'], $before['shipping_total'], $before['lines'][0]['total'], $before['lines'][0]['quantity'] ] );
		$order->grand_total = '49.500000'; $order->lines[0]->amount = '40.0000';
		$after = Facts::native( $order );
		self::assertSame( [ '49.5', '40', '9.5' ], [ $after['total'], $after['lines'][0]['total'], $after['shipping_total'] ] );
		self::assertNotSame( Facts::digest( 'native-test', $before ), Facts::digest( 'native-test', $after ) );
	}

	public function test_noncanonical_native_money_and_unsupported_fee_refuse(): void {
		foreach ( [ '09.50', '-1', '1e2' ] as $amount ) {
			$order = new NativeFactsContractOrder(); $order->grand_total = $amount;
			try { Facts::native( $order ); self::fail( 'Noncanonical native amount accepted.' ); } catch ( \UnexpectedValueException ) {}
		}
		$order = new NativeFactsContractOrder(); $order->fees = [ new \stdClass() ];
		$this->expectException( \UnexpectedValueException::class ); Facts::native( $order );
	}

	public function test_cpt_physical_bytes_allow_decimal_padding_but_refuse_duplicate_native_identity(): void {
		$native = Facts::native( new NativeFactsContractOrder() ); $physical = $this->physical( $native, false );
		Facts::assert_physical( $physical, $native, false );
		$physical['order_meta'][] = [ 'meta_key' => '_order_key', 'meta_value' => $native['order_key'] ];
		$this->expectException( \UnexpectedValueException::class ); Facts::assert_physical( $physical, $native, false );
	}

	public function test_hpos_physical_bytes_pin_customer_address_and_woo_grand_money(): void {
		$native = Facts::native( new NativeFactsContractOrder() ); $physical = $this->physical( $native, true );
		Facts::assert_physical( $physical, $native, true );
		$physical['order'][0]['customer_id'] = '1';
		$this->expectException( \UnexpectedValueException::class ); Facts::assert_physical( $physical, $native, true );
	}

	private function physical( array $native, bool $hpos ): array {
		$meta = [ '_order_key' => $native['order_key'], '_order_currency' => 'GHS', '_customer_user' => '0', '_order_total' => '34.500000', '_order_tax' => '0.00', '_order_shipping' => '9.500000', '_order_shipping_tax' => '0.00' ];
		foreach ( $native['address'] as $field => $value ) { $meta['_shipping_' . $field] = $value; }
		$item = [ '_product_id' => '10', '_variation_id' => '0', '_qty' => '2.000', '_line_total' => '25.00', '_line_tax' => '0.00', '_line_subtotal' => '25.00', '_line_subtotal_tax' => '0.00', '_line_tax_data' => serialize( [ 'total' => [], 'subtotal' => [] ] ) ];
		return [ 'order' => $hpos ? [ [ 'currency' => 'GHS', 'customer_id' => '0', 'total_amount' => '34.500000', 'tax_amount' => '0.00' ] ] : [ [ 'post_type' => 'shop_order' ] ], 'money' => [ [ 'order_key' => $native['order_key'], 'shipping_total_amount' => '9.5000', 'shipping_tax_amount' => '0' ] ], 'address' => [ [ 'address_type' => 'shipping', ...$native['address'] ] ], 'order_meta' => array_map( static fn( string $key, string $value ): array => [ 'meta_key' => $key, 'meta_value' => $value ], array_keys( $meta ), $meta ), 'items' => [ [ 'order_item_id' => '901', 'order_item_type' => 'line_item', 'order_item_name' => 'Product', 'order_id' => '100' ] ], 'item_meta' => array_map( static fn( string $key, string $value ): array => [ 'order_item_id' => '901', 'meta_key' => $key, 'meta_value' => $value ], array_keys( $item ), $item ) ];
	}
}

final class NativeFactsContractLine extends \WC_Order_Item_Product {
	public string $amount = '25.0000';
	public function __construct( int $product = 10 ) { parent::__construct( [ 'id' => 901, 'product_id' => $product, 'quantity' => 2 ] ); }
	public function get_order_id( string $context = 'view' ): int { return 100; }
	public function get_total( string $context = 'view' ): string { return $this->amount; }
	public function get_total_tax( string $context = 'view' ): string { return '0.00'; }
	public function get_subtotal( string $context = 'view' ): string { return $this->amount; }
	public function get_subtotal_tax( string $context = 'view' ): string { return '0.00'; }
	public function get_taxes( string $context = 'view' ): array { return [ 'total' => [], 'subtotal' => [] ]; }
}

final class NativeFactsContractOrder extends \WC_Order {
	public array $lines; public array $fees = []; public string $grand_total = '34.5000';
	public function __construct() { parent::__construct( [ 'id' => 100 ] ); $this->lines = [ new NativeFactsContractLine() ]; }
	public function get_items( string $type = '' ): array { return match ( $type ) { 'line_item' => $this->lines, 'fee' => $this->fees, default => [] }; }
	public function get_currency( string $context = 'view' ): string { return 'GHS'; }
	public function get_customer_id( string $context = 'view' ): int { return 0; }
	public function get_order_key( string $context = 'view' ): string { return 'wc_order_facts_contract'; }
	public function get_total( string $context = 'view' ): string { return $this->grand_total; }
	public function get_total_tax( string $context = 'view' ): string { return '0.00'; }
	public function get_cart_tax( string $context = 'view' ): string { return '0.00'; }
	public function get_shipping_total( string $context = 'view' ): string { return '9.5000'; }
	public function get_shipping_tax( string $context = 'view' ): string { return '0.00'; }
	public function get_shipping_country( string $context = 'view' ): string { return 'GH'; }
	public function get_shipping_state( string $context = 'view' ): string { return 'AA'; }
	public function get_shipping_city( string $context = 'view' ): string { return 'Accra'; }
	public function get_shipping_postcode( string $context = 'view' ): string { return '00001'; }
	public function get_shipping_address_1( string $context = 'view' ): string { return 'PRIVATE-N17-ADDRESS'; }
	public function get_shipping_address_2( string $context = 'view' ): string { return ''; }
}
