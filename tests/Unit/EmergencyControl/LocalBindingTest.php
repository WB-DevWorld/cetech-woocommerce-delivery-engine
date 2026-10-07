<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use PHPUnit\Framework\TestCase;

final class LocalBindingTest extends TestCase {
	protected function setUp(): void { $GLOBALS['blog_id'] = 1; }
	protected function tearDown(): void { unset( $GLOBALS['blog_id'] ); }
	private function order(): \WC_Order { return new \WC_Order( [ 'id' => 19, 'items' => [ new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1 ] ) ] ] ); }
	public function test_raw_loaded_binding_stays_unchanged_without_getter_calls(): void { $order = $this->order(); $binding = EmergencyCheckoutLocalBinding::capture( $order ); self::assertNotNull( $binding ); self::assertTrue( $binding->unchanged() ); self::assertTrue( $binding->unchanged() ); }
	public function test_bound_nested_item_snapshot_change_is_detected(): void { $order = $this->order(); $binding = EmergencyCheckoutLocalBinding::capture( $order ); $order->get_items()[0]->update_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, 'changed-private-snapshot' ); self::assertFalse( $binding->unchanged() ); }
	public function test_equally_valued_item_object_replacement_is_not_the_bound_identity(): void { $order = $this->order(); $binding = EmergencyCheckoutLocalBinding::capture( $order ); $property = new \ReflectionProperty( \WC_Order::class, 'data' ); $data = $property->getValue( $order ); $data['items'][0] = clone $data['items'][0]; $property->setValue( $order, $data ); self::assertFalse( $binding->unchanged() ); }
	public function test_native_date_value_is_supported_and_mutation_is_detected(): void { $date = new \DateTime( '2026-10-07T01:00:00+00:00' ); $order = new \WC_Order( [ 'date_paid' => $date ] ); $binding = EmergencyCheckoutLocalBinding::capture( $order ); self::assertNotNull( $binding ); self::assertTrue( $binding->unchanged() ); $date->modify( '+1 second' ); self::assertFalse( $binding->unchanged() ); }
	public function test_unknown_object_graph_refuses_without_invoking_arbitrary_serializer(): void { $calls = 0; $private = new class( $calls ) implements \JsonSerializable { public function __construct( private int &$calls ) {} public function jsonSerialize(): mixed { ++$this->calls; return 'PRIVATE_RAW_DATA'; } }; self::assertNull( EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'date_paid' => $private ] ) ) ); self::assertSame( 0, $calls ); }
	public function test_site_change_is_detected_without_calling_locale_or_site_filters(): void { $binding = EmergencyCheckoutLocalBinding::capture( $this->order() ); $GLOBALS['blog_id'] = 2; self::assertFalse( $binding->unchanged() ); }
	public function test_private_binding_cannot_be_generically_serialized(): void { $binding = EmergencyCheckoutLocalBinding::capture( $this->order() ); $this->expectException( \LogicException::class ); json_encode( $binding, JSON_THROW_ON_ERROR ); }
	public function test_inherited_native_private_data_remains_bound_but_shadow_data_is_not_trusted(): void {
		$order = new class( [ 'id' => 19 ] ) extends \WC_Order {};
		$binding = EmergencyCheckoutLocalBinding::capture( $order ); self::assertNotNull( $binding ); self::assertTrue( $binding->unchanged() );
		$order->set_status( 'cancelled' ); self::assertFalse( $binding->unchanged() );
		$shadow = new class( [ 'id' => 19 ] ) extends \WC_Order { private array $data = [ 'arbitrary_private_payload' => 'private' ]; };
		self::assertNull( EmergencyCheckoutLocalBinding::capture( $shadow ) );
	}
}
