<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteOrderPayLocalBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderPayContinuationTest extends TestCase {
	public function test_native_method_and_title_change_does_not_renew_or_change_immutable_facts(): void {
		$order = $this->order(); $binding = QuoteOrderPayLocalBinding::capture( $order ); $data = $this->data( $order ); $data['payment_method'] = 'alternate_native_gateway'; $data['payment_method_title'] = 'Alternate native gateway'; $this->write( $order, $data );
		self::assertTrue( $binding->unchanged() ); self::assertTrue( $binding->payment_matches( 'alternate_native_gateway', 'Alternate native gateway' ) ); self::assertFalse( $binding->payment_matches( 'different_gateway', 'Alternate native gateway' ) );
	}
	#[DataProvider( 'forbidden_changes' )]
	public function test_unsaved_native_order_changes_are_denied( string $field, mixed $value ): void {
		$order = $this->order(); $binding = QuoteOrderPayLocalBinding::capture( $order ); $data = $this->data( $order ); $data[$field] = $value; $this->write( $order, $data ); self::assertFalse( $binding->unchanged() );
	}
	public static function forbidden_changes(): array { return [ [ 'total', '0.00' ], [ 'billing_address_1', 'Changed billing address' ], [ 'shipping_address_1', 'Changed destination' ], [ 'customer_id', 99 ], [ 'order_key', 'replacement_key' ], [ 'status', 'completed' ] ]; }
	public function test_unsaved_native_line_money_cannot_reach_gateway(): void {
		$order = $this->order(); $order->add_product_item( 31 ); $binding = QuoteOrderPayLocalBinding::capture( $order ); $item = $order->get_items()[31]; $p = new \ReflectionProperty( \WC_Order_Item_Product::class, 'data' ); $data = $p->getValue( $item ); $data['total'] = '999.99'; $p->setValue( $item, $data ); self::assertFalse( $binding->unchanged() );
	}
	public function test_unsaved_mandatory_quote_metadata_cannot_reach_gateway(): void {
		$order = $this->order(); $order->update_meta_data( '_cetech_de_quote_native_tax_source', 'retained private source' ); $binding = QuoteOrderPayLocalBinding::capture( $order ); $order->update_meta_data( '_cetech_de_quote_native_tax_source', 'changed private source' ); self::assertFalse( $binding->unchanged() );
	}
	public function test_native_item_replacement_cannot_inherit_original_binding(): void {
		$order = $this->order(); $order->add_product_item( 31 ); $binding = QuoteOrderPayLocalBinding::capture( $order ); $order->add_product_item( 31 ); self::assertFalse( $binding->unchanged() );
	}
	private function order(): \WC_Order { return new \WC_Order( [ 'id' => 19, 'total' => '25.00', 'customer_id' => 7, 'order_key' => 'retained_key', 'status' => 'pending', 'billing_address_1' => 'Original billing address', 'shipping_address_1' => 'Original destination', 'payment_method' => 'first_native_gateway', 'payment_method_title' => 'First native gateway' ] ); }
	private function data( \WC_Order $order ): array { return ( new \ReflectionProperty( \WC_Order::class, 'data' ) )->getValue( $order ); }
	private function write( \WC_Order $order, array $data ): void { ( new \ReflectionProperty( \WC_Order::class, 'data' ) )->setValue( $order, $data ); }
}
