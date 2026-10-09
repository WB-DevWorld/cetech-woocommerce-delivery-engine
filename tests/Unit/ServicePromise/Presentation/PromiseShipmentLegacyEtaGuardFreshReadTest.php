<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Presentation;

use CetechDeliveryEngine\Application\Order\{DeliveryQuoteSnapshotEnvelope, OrderDeliverySnapshot};
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Presentation\Admin\PromiseShipmentLegacyEtaGuard;
use CetechDeliveryEngine\Tests\Support\RestoresWordPressFixtureGlobals;
use PHPUnit\Framework\TestCase;

final class PromiseShipmentLegacyEtaGuardFreshReadTest extends TestCase {
	use RestoresWordPressFixtureGlobals;
	protected function setUp(): void { $this->remember_fixture_globals(); $GLOBALS['cetech_de_test_wc_orders'] = []; $GLOBALS['cetech_de_test_persisted_wc_orders'] = []; }
	protected function tearDown(): void { $this->restore_fixture_globals(); }
	private function required(): \WC_Order { return new \WC_Order( [ 'id' => 40, 'meta' => [ OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION => '3', DeliveryQuoteSnapshotEnvelope::META_FORMAT => '2' ] ] ); }
	public function test_cached_dirty_legacy_carrier_cannot_substitute_for_persisted_required_promise_markers(): void {
		$saved = $this->required(); $cached = new \WC_Order( [ 'id' => 40 ] ); $GLOBALS['cetech_de_test_persisted_wc_orders'][40] = $saved; $GLOBALS['cetech_de_test_wc_orders'][40] = $cached;
		self::assertSame( $cached, wc_get_order( 40 ) ); self::assertTrue( PromiseShipmentLegacyEtaGuard::allows_order( $cached ) ); self::assertFalse( PromiseShipmentLegacyEtaGuard::allows_text_edit( Shipment::create( 40, 'g-40' ) ) );
		self::assertSame( '3', $saved->get_meta( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION ) ); self::assertSame( '2', $saved->get_meta( DeliveryQuoteSnapshotEnvelope::META_FORMAT ) );
	}
	public function test_dirty_cached_promise_marker_does_not_rewrite_a_persisted_established_legacy_order(): void {
		$saved = new \WC_Order( [ 'id' => 40 ] ); $GLOBALS['cetech_de_test_persisted_wc_orders'][40] = $saved; $GLOBALS['cetech_de_test_wc_orders'][40] = $this->required();
		self::assertFalse( PromiseShipmentLegacyEtaGuard::allows_order( wc_get_order( 40 ) ) ); self::assertTrue( PromiseShipmentLegacyEtaGuard::allows_text_edit( Shipment::create( 40, 'g-40' ) ) ); self::assertSame( '', $saved->get_meta( DeliveryQuoteSnapshotEnvelope::META_FORMAT ) );
	}
	public function test_independent_native_reader_requires_exact_existing_order_and_detaches_line_carriers(): void {
		$shipment = Shipment::create( 40, 'g-40' ); self::assertFalse( PromiseShipmentLegacyEtaGuard::allows_text_edit( $shipment ) );
		$GLOBALS['cetech_de_test_persisted_wc_orders'][40] = new \WC_Order( [ 'id' => 41 ] ); self::assertFalse( PromiseShipmentLegacyEtaGuard::allows_text_edit( $shipment ) );
		$item = new \WC_Order_Item_Product( [ 'id' => 501, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '3' ] ] ); $saved = new \WC_Order( [ 'id' => 40, 'items' => [ $item ] ] ); $GLOBALS['cetech_de_test_persisted_wc_orders'][40] = $saved;
		self::assertFalse( PromiseShipmentLegacyEtaGuard::allows_text_edit( $shipment ) ); $fresh = new \WC_Order( 40 ); self::assertNotSame( $saved, $fresh ); self::assertNotSame( $item, $fresh->get_items()[0] );
		$fresh->get_items()[0]->delete_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION ); self::assertSame( '3', $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION ) );
	}
}
