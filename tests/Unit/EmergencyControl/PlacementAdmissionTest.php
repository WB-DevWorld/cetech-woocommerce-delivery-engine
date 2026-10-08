<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

require_once __DIR__ . '/CheckoutTestFixtures.php';

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutFacts;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyFinalPlacementGuard;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use PHPUnit\Framework\TestCase;

final class PlacementAdmissionTest extends TestCase {
	private CheckoutControlFixture $control;
	private CheckoutQuoteFixture $quote;
	private PlacementGuardFixture $placement;
	private EmergencyCheckoutAdmissionService $service;
	private \WC_Order $order;

	protected function setUp(): void {
		$GLOBALS['blog_id'] = 1;
		$GLOBALS['cetech_de_test_wc_products'] = [ 10 => new \WC_Product( [ 'id' => 10, 'type' => 'simple' ] ) ];
		$this->control = new CheckoutControlFixture(); $this->quote = new CheckoutQuoteFixture();
		$this->placement = new PlacementGuardFixture();
		$this->service = new EmergencyCheckoutAdmissionService( $this->control, new EmergencyOwnershipClassifier( [ new CheckoutSourceFixture() ] ), $this->quote, new EmergencyOwnershipLatch(), $this->placement );
		$this->order = new \WC_Order( [ 'id' => 19, 'items' => [ new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1 ] ) ] ] );
	}
	protected function tearDown(): void { unset( $GLOBALS['blog_id'], $GLOBALS['cetech_de_test_wc_products'] ); }

	public function test_c07_confirmation_is_tentative_until_final_receipt_acknowledgement(): void {
		$this->placement->complete_ok = false;
		$this->placement->on_complete = function ( int $revision, EmergencyCheckoutLocalBinding $binding ): void {
			self::assertSame( 1, $revision ); self::assertTrue( $this->control->released ); self::assertFalse( $this->control->locked );
			self::assertTrue( $binding->unchanged() ); self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
		};
		self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed );
		self::assertSame( 1, $this->control->confirms ); self::assertSame( 1, $this->placement->completes ); self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
	}

	public function test_acknowledged_continuation_does_not_reseal_after_later_pause(): void {
		self::assertTrue( $this->service->final_order( $this->order, 'classic' )->allowed );
		$this->control->pause();
		self::assertTrue( $this->service->final_order( $this->order, 'classic' )->allowed );
		self::assertSame( 1, $this->placement->completes ); self::assertSame( 1, $this->control->confirms );
	}

	public function test_changed_acknowledged_order_is_denied_without_new_stamp(): void {
		self::assertTrue( $this->service->final_order( $this->order, 'classic' )->allowed );
		$this->order->set_status( 'cancelled' );
		self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed );
		self::assertSame( 1, $this->placement->completes ); self::assertSame( 1, $this->control->confirms );
	}

	public function test_mutation_after_receipt_cannot_enter_gateway_branch(): void {
		$this->placement->on_complete = function (): void { $this->order->set_status( 'cancelled' ); };
		self::assertFalse( $this->service->final_order( $this->order, 'store_api' )->allowed );
		self::assertFalse( $this->service->admitted( $this->order, 'store_api' ) );
	}

	public function test_unknown_receipt_exception_never_creates_a_stamp(): void {
		$this->placement->on_complete = static function (): void { throw new \RuntimeException( 'PRIVATE_DIAGNOSTIC' ); };
		self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed );
		self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
	}

	public function test_same_request_receipt_memory_is_required_on_stamp_reentry(): void {
		self::assertTrue( $this->service->final_order( $this->order, 'order_pay' )->allowed );
		$this->placement->continuation_ok = false;
		self::assertFalse( $this->service->final_order( $this->order, 'order_pay' )->allowed );
		self::assertSame( 1, $this->placement->completes ); self::assertSame( 1, $this->control->confirms );
	}

	public function test_paid_callback_keeps_history_and_does_not_adopt_checkout(): void {
		$paid = new \WC_Order( [ 'id' => 20, 'paid' => true ] ); $this->control->pause();
		self::assertSame( 'already_paid', $this->service->final_order( $paid, 'order_pay' )->code );
		self::assertSame( 0, $this->placement->completes ); self::assertSame( 0, $this->control->reads );
	}

	public function test_marker_presence_and_value_are_part_of_saved_fingerprint(): void {
		$order = new CheckoutOrderFixture( [ 'id' => 19, 'items' => [] ] );
		$absent = EmergencyCheckoutFacts::order_fingerprint( $order ); self::assertNotNull( $absent );
		$order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, null );
		$null = EmergencyCheckoutFacts::order_fingerprint( $order ); self::assertNotSame( $absent, $null );
		$order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, 1 );
		self::assertNotSame( $null, EmergencyCheckoutFacts::order_fingerprint( $order ) );
	}
	public function test_private_saved_draft_and_reference_mutations_change_fingerprint(): void {
		$order = new CheckoutOrderFixture( [ 'id' => 19, 'items' => [] ] );
		$before = EmergencyCheckoutFacts::order_fingerprint( $order );
		$order->update_meta_data( \CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts::META_DRAFT, 'captured-private-draft' );
		$draft = EmergencyCheckoutFacts::order_fingerprint( $order ); self::assertNotSame( $before, $draft );
		$order->update_meta_data( \CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts::META_REFERENCE, 'original-private-reference' );
		$reference = EmergencyCheckoutFacts::order_fingerprint( $order ); self::assertNotSame( $draft, $reference );
		$order->update_meta_data( \CetechDeliveryEngine\Application\Order\QuoteNativeOrderFacts::META_TAX_SOURCE, 'captured-private-tax-source' );
		self::assertNotSame( $reference, EmergencyCheckoutFacts::order_fingerprint( $order ) );
	}
	public function test_null_quote_marker_still_reaches_mandatory_guard_for_unmanaged_catalog(): void {
		$source = new CheckoutSourceFixture(); $source->managed = false;
		$guard = new PlacementGuardFixture(); $guard->complete_ok = false;
		$service = new EmergencyCheckoutAdmissionService( $this->control, new EmergencyOwnershipClassifier( [ $source ] ), $this->quote, new EmergencyOwnershipLatch(), $guard );
		$this->order->update_meta_data( DeliveryQuoteSnapshotEnvelope::META_FORMAT, null );
		self::assertFalse( $service->final_order( $this->order, 'order_pay' )->allowed ); self::assertSame( 1, $guard->completes ); self::assertSame( 1, $this->control->confirms );
	}
}

final class PlacementGuardFixture implements EmergencyFinalPlacementGuard {
	public bool $complete_ok = true;
	public bool $continuation_ok = true;
	public int $completes = 0;
	public ?\Closure $on_complete = null;
	public function complete( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $binding ): bool { ++$this->completes; if ( null !== $this->on_complete ) { ( $this->on_complete )( $control_revision, $binding ); } return $this->complete_ok; }
	public function admitted( \WC_Order $order, string $route, int $control_revision, EmergencyCheckoutLocalBinding $binding ): bool { return $this->complete_ok && $this->continuation_ok && $binding->unchanged(); }
}
