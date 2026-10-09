<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Presentation;

use CetechDeliveryEngine\Application\ServicePromise\Presentation\PublicPromiseFormatter;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use CetechDeliveryEngine\Presentation\Frontend\PromiseShipmentSummaryRenderer;
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

final class PromiseShipmentSummaryRendererTest extends TestCase {
	private array $native_context = [];
	private array $query = [];
	protected function setUp(): void {
		foreach ( [ 'cetech_de_test_wc_orders', 'cetech_de_test_user_id', 'cetech_de_test_is_view_order', 'cetech_de_test_is_order_received' ] as $key ) { $this->native_context[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
		$this->query = $_GET; $_GET = [];
		$GLOBALS['cetech_de_test_wc_orders'] = []; $GLOBALS['cetech_de_test_user_id'] = 0; $GLOBALS['cetech_de_test_is_view_order'] = false; $GLOBALS['cetech_de_test_is_order_received'] = false;
	}
	protected function tearDown(): void { foreach ( $this->native_context as $key => [ $exists, $value ] ) { if ( $exists ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } } $_GET = $this->query; }
	private function order( int $customer = 20, string $key = 'wc_order_guest_key' ): \WC_Order { return new class( [ 'id' => 30 ], $customer, $key ) extends \WC_Order { public function __construct( array $data, private int $customer, private string $key ) { parent::__construct( $data ); } public function get_customer_id(): int { return $this->customer; } public function get_order_key(): string { return $this->key; } }; }
	public function test_owned_order_requires_real_customer_and_native_surface(): void {
		$order = $this->order(); $GLOBALS['cetech_de_test_wc_orders'][30] = $order; $GLOBALS['cetech_de_test_user_id'] = 20;
		self::assertFalse( PromiseShipmentSummaryRenderer::authorized( $order ) ); $GLOBALS['cetech_de_test_is_view_order'] = true; self::assertTrue( PromiseShipmentSummaryRenderer::authorized( $order ) ); $GLOBALS['cetech_de_test_user_id'] = 21; self::assertFalse( PromiseShipmentSummaryRenderer::authorized( $order ) );
	}
	public function test_guest_requires_exact_order_key_and_cannot_reuse_it_on_an_owned_order(): void {
		$order = $this->order( 0 ); $GLOBALS['cetech_de_test_wc_orders'][30] = $order; $GLOBALS['cetech_de_test_user_id'] = 0; $GLOBALS['cetech_de_test_is_order_received'] = true;
		self::assertFalse( PromiseShipmentSummaryRenderer::authorized( $order ) ); $_GET['key'] = 'foreign'; self::assertFalse( PromiseShipmentSummaryRenderer::authorized( $order ) ); $_GET['key'] = 'wc_order_guest_key'; self::assertTrue( PromiseShipmentSummaryRenderer::authorized( $order ) );
		$owned = $this->order( 20 ); $GLOBALS['cetech_de_test_wc_orders'][30] = $owned; self::assertFalse( PromiseShipmentSummaryRenderer::authorized( $owned ) );
	}
	public function test_spoofed_hook_order_customer_cannot_pick_another_customers_saved_history(): void {
		$GLOBALS['cetech_de_test_wc_orders'][30] = $this->order( 21 ); $GLOBALS['cetech_de_test_user_id'] = 20; $GLOBALS['cetech_de_test_is_view_order'] = true;
		self::assertFalse( PromiseShipmentSummaryRenderer::authorized( $this->order( 20 ) ) );
	}
	public function test_unauthorized_or_disabled_render_never_resolves_private_service_or_shipment_rows(): void {
		$order = $this->order(); $GLOBALS['cetech_de_test_wc_orders'][30] = $order; $GLOBALS['cetech_de_test_user_id'] = 21; $GLOBALS['cetech_de_test_is_view_order'] = true;
		$repo = $this->createMock( ShipmentRepositoryInterface::class ); $repo->expects( self::never() )->method( 'findByOrderId' ); $calls = 0; $resolver = static function() use ( &$calls ): null { ++$calls; return null; };
		ob_start(); ( new PromiseShipmentSummaryRenderer( $repo, $resolver, static fn(): bool => true ) )->render( $order ); ( new PromiseShipmentSummaryRenderer( $repo, $resolver ) )->render( $order ); $html = ob_get_clean(); self::assertSame( 0, $calls ); self::assertSame( '', $html );
	}
	public function test_original_relative_promise_and_current_absolute_prediction_are_separate_without_rewriting_original_text(): void {
		$result = PromiseHandoffFixture::result( PromiseHandoffFixture::input( [ 'anchor' => 'payment_confirmed', 'late_payment_rule' => 'relative_after_payment' ] ) ); $text = ( new PublicPromiseFormatter() )->text( \CetechDeliveryEngine\Domain\ServicePromise\PublicPromiseView::from_result( $result, 'delivery' ) );
		$original = \CetechDeliveryEngine\Domain\ServicePromise\Handoff\PromiseHistoricalPacket::capture( $result, $text )->public_facts(); $current = [ 'state' => 'absolute_window', 'views' => [ [ 'service_label' => 'Standard', 'display_timezone' => 'Africa/Accra', 'from' => '2026-10-10 10:10:00.000000', 'until' => '2026-10-10 10:20:00.000000' ] ], 'source' => 'payment_confirmed' ];
		$formatter = new PublicPromiseFormatter( static fn( string $text ): string => str_replace( ':', ' —', $text ) ); $html = PromiseShipmentSummaryRenderer::html( [ [ 'original' => $original, 'current' => $current ] ], $formatter );
		self::assertStringContainsString( 'Original recorded delivery estimate', $html ); self::assertStringContainsString( $text, $html ); self::assertStringContainsString( 'minutes after payment confirmation', $html ); self::assertStringContainsString( 'Current delivery estimate', $html ); self::assertStringContainsString( '2026-10-10 10:10', $html ); self::assertStringContainsString( 'Calculated after payment confirmation.', $html ); self::assertStringContainsString( 'data-cetech-de-shipment-original="1"', $html ); self::assertStringContainsString( 'data-cetech-de-shipment-current="1"', $html );
	}
	public function test_missing_or_unavailable_current_window_is_explicit_and_preserves_original(): void {
		foreach ( [ null, [ 'state' => 'unavailable', 'views' => [], 'source' => 'staff_revision' ] ] as $current ) { $html = PromiseShipmentSummaryRenderer::html( [ [ 'original' => PromiseHandoffFixture::packet()->public_facts(), 'current' => $current ] ] ); self::assertStringContainsString( 'Standard delivery: 10:10–10:20 UTC', $html ); self::assertStringNotContainsString( '1970', $html ); self::assertStringNotContainsString( 'event_key', $html ); }
	}
	public function test_extra_private_current_reason_is_refused_before_output(): void {
		$this->expectException( \InvalidArgumentException::class ); PromiseShipmentSummaryRenderer::html( [ [ 'original' => PromiseHandoffFixture::packet()->public_facts(), 'current' => [ 'state' => 'unavailable', 'views' => [], 'source' => 'staff_revision', 'reason' => 'PRIVATE-OPERATOR-NOTE' ] ] ] );
	}
}
