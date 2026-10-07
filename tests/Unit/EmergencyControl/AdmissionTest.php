<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

require_once __DIR__ . '/CheckoutTestFixtures.php';

use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutAdmissionService;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnership;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use PHPUnit\Framework\TestCase;

final class AdmissionTest extends TestCase {
	private CheckoutSourceFixture $source; private CheckoutControlFixture $control; private CheckoutQuoteFixture $quote; private EmergencyOwnershipLatch $latch; private EmergencyCheckoutAdmissionService $service; private \WC_Order $order;
	protected function setUp(): void {
		$GLOBALS['blog_id'] = 1; $GLOBALS['cetech_de_test_wc_products'] = [ 10 => new \WC_Product( [ 'id' => 10, 'type' => 'simple' ] ) ];
		$this->source = new CheckoutSourceFixture(); $this->control = new CheckoutControlFixture(); $this->quote = new CheckoutQuoteFixture(); $this->latch = new EmergencyOwnershipLatch();
		$this->service = new EmergencyCheckoutAdmissionService( $this->control, new EmergencyOwnershipClassifier( [ $this->source ] ), $this->quote, $this->latch );
		$this->order = new \WC_Order( [ 'id' => 19, 'items' => [ new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1 ] ) ] ] );
	}
	protected function tearDown(): void { unset( $GLOBALS['blog_id'], $GLOBALS['cetech_de_test_wc_products'] ); }
	public function test_enabled_fresh_proof_stamps_only_after_confirmed_release(): void {
		$stamp_during_lock = true; $this->control->during_confirm = function () use ( &$stamp_during_lock ): void { $stamp_during_lock = $this->service->admitted( $this->order, 'classic' ); };
		$r = $this->service->final_order( $this->order, 'classic' ); self::assertTrue( $r->allowed ); self::assertFalse( $stamp_during_lock ); self::assertTrue( $this->control->released ); self::assertTrue( $this->service->admitted( $this->order, 'classic' ) ); self::assertSame( 1, $this->quote->calls );
	}
	public function test_pause_after_early_validation_blocks_every_final_surface(): void {
		self::assertTrue( $this->service->early_product( 10 )->allowed ); $this->control->pause();
		foreach ( [ 'classic', 'blocks', 'store_api', 'order_pay' ] as $route ) { $r = $this->service->final_order( $this->order, $route ); self::assertFalse( $r->allowed ); self::assertSame( 'checkout_suspended', $r->code ); self::assertFalse( $this->service->admitted( $this->order, $route ) ); }
		self::assertSame( 0, $this->quote->calls );
	}
	public function test_pause_between_quote_and_final_lock_refuses(): void { $this->quote->during_validation = function (): void { $this->control->pause(); }; $r = $this->service->final_order( $this->order, 'classic' ); self::assertSame( 'checkout_suspended', $r->code ); self::assertFalse( $this->service->admitted( $this->order, 'classic' ) ); }
	public function test_pause_then_resume_between_quote_and_lock_is_stale(): void { $this->quote->during_validation = function (): void { $this->control->pause(); $this->control->resume(); }; $r = $this->service->final_order( $this->order, 'store_api' ); self::assertSame( 'stale_control_revision', $r->code ); self::assertFalse( $r->allowed ); }
	public function test_unknown_release_or_read_never_stamps(): void {
		$this->control->during_confirm = function (): void { $this->control->failure = 'outcome_unknown'; };
		$r = $this->service->final_order( $this->order, 'classic' ); self::assertSame( 'control_unavailable', $r->code ); self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
	}
	public function test_changed_server_facts_during_quote_and_under_lock_refuse(): void {
		$this->quote->during_validation = function (): void { $this->quote->hash = 'changed-destination'; }; self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed ); self::assertSame( 0, $this->control->confirms );
		$this->quote->during_validation = null; $this->control->during_confirm = function (): void { $this->quote->hash = 'changed-quantity'; }; self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed ); self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
	}
	public function test_unavailable_cached_quote_does_not_pass_final_guard(): void { $this->quote->valid = false; self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed ); self::assertSame( 0, $this->control->confirms ); }
	public function test_enabled_zero_price_and_pickup_still_require_admission(): void { $this->source->pickup = true; $r = $this->service->final_order( $this->order, 'classic' ); self::assertTrue( $r->allowed ); self::assertSame( 1, $this->control->confirms ); }
	public function test_paused_zero_total_and_pickup_cannot_be_manufactured_free(): void { $this->source->pickup = true; $this->control->pause(); self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed ); self::assertSame( 0, $this->quote->calls ); }
	public function test_pure_native_cart_and_unpaid_order_never_load_control_or_quote(): void { $this->source->managed = false; $this->control->pause(); self::assertTrue( $this->service->early_cart( [ 'line' => [ 'product_id' => 10, 'quantity' => 1 ] ] )->allowed ); self::assertSame( 'unmanaged', $this->service->final_order( $this->order, 'order_pay' )->code ); self::assertSame( 0, $this->control->reads ); self::assertSame( 0, $this->quote->calls ); }
	public function test_removed_marker_stays_owned_for_surviving_line_only(): void { $this->source->managed = false; $this->latch->capture_line( 'line', [ 'product_id' => 10, 'quantity' => 1 ], EmergencyOwnership::Managed ); $this->control->pause(); self::assertFalse( $this->service->early_cart( [ 'line' => [ 'product_id' => 10, 'quantity' => 1 ] ] )->allowed ); self::assertTrue( $this->service->early_cart( [] )->allowed ); }
	public function test_empty_cart_final_latch_reconstructs_actual_order_and_forbids_forged_stamp(): void { $this->source->managed = false; $this->latch->capture_line( 'line', [ 'product_id' => 10, 'quantity' => 1 ], EmergencyOwnership::Managed ); $this->control->pause(); $this->order->update_meta_data( 'cetech_de_admitted', [ 'enabled' => true, 'revision' => 999 ] ); self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed ); self::assertFalse( $this->service->admitted( $this->order, 'classic' ) ); }
	public function test_old_pending_order_pay_needs_fresh_quote_after_resume_without_writes(): void { $this->control->resume(); $before = $this->order->get_status(); $this->quote->valid = false; self::assertSame( 'checkout_revalidation_required', $this->service->final_order( $this->order, 'order_pay' )->code ); self::assertSame( $before, $this->order->get_status() ); }
	public function test_paid_history_does_not_load_checkout_control(): void { $paid = new \WC_Order( [ 'id' => 2, 'paid' => true ] ); $this->control->pause(); self::assertSame( 'already_paid', $this->service->final_order( $paid, 'order_pay' )->code ); self::assertSame( 0, $this->control->reads ); self::assertSame( 0, $this->quote->calls ); }
	public function test_already_admitted_payment_may_finish_after_later_pause(): void { self::assertTrue( $this->service->final_order( $this->order, 'classic' )->allowed ); $this->control->pause(); self::assertTrue( $this->service->final_order( $this->order, 'classic' )->allowed ); self::assertSame( 1, $this->quote->calls ); }
	public function test_stamp_is_not_transferable_to_another_order_object_or_route_or_site(): void { $this->service->final_order( $this->order, 'classic' ); self::assertFalse( $this->service->admitted( clone $this->order, 'classic' ) ); self::assertFalse( $this->service->admitted( $this->order, 'order_pay' ) ); $GLOBALS['blog_id'] = 2; self::assertFalse( $this->service->admitted( $this->order, 'classic' ) ); }
	public function test_unknown_surface_cannot_partially_adopt_policy(): void { self::assertSame( 'unsupported_activation_policy', $this->service->final_order( $this->order, 'percentage_cohort' )->code ); self::assertSame( 0, $this->control->reads ); }
	public function test_wrong_site_control_does_not_mint_stamp(): void { $this->control->state = EmergencyControlState::absent( 2 ); self::assertFalse( $this->service->final_order( $this->order, 'store_api' )->allowed ); }
	public function test_final_lock_comparison_never_calls_filtered_quote_fingerprint(): void {
		$calls_under_lock = 0;
		$this->quote->on_fingerprint = function () use ( &$calls_under_lock ): void { if ( $this->control->locked ) { ++$calls_under_lock; } };
		self::assertTrue( $this->service->final_order( $this->order, 'classic' )->allowed );
		self::assertSame( 0, $calls_under_lock );
		self::assertTrue( $this->service->admitted( $this->order, 'classic' ) );
	}
	public function test_raw_loaded_order_mutation_under_lock_refuses_even_if_filtered_hash_is_unchanged(): void {
		$this->control->during_confirm = function (): void { $this->order->set_status( 'cancelled' ); };
		self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed );
		self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
	}
	public function test_post_release_filtered_read_cannot_change_bound_raw_data_before_stamp(): void {
		$this->quote->on_fingerprint = function (): void { if ( $this->control->released ) { $this->order->set_status( 'cancelled' ); } };
		self::assertFalse( $this->service->final_order( $this->order, 'classic' )->allowed );
		self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
	}
	public function test_existing_stamp_is_invalidated_by_raw_mutation_hidden_by_a_view_filter(): void {
		self::assertTrue( $this->service->final_order( $this->order, 'classic' )->allowed );
		$this->order->set_status( 'cancelled' );
		self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
	}
	public function test_late_pause_reports_suspension_when_owned_snapshot_facts_are_missing(): void {
		$context = \CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext::pickup( 55 );
		$this->latch->capture_line( 'line', [ 'product_id' => 10, 'quantity' => 1, 'cetech_de_customer_context' => $context->toArray() ], EmergencyOwnership::Managed );
		$this->latch->bind_order_line( 'line', $this->order->get_items( 'line_item' )[0] );
		$this->control->pause(); $result = $this->service->final_order( $this->order, 'classic' );
		self::assertFalse( $result->allowed ); self::assertSame( 'checkout_suspended', $result->code );
		self::assertSame( 0, $this->quote->calls ); self::assertFalse( $this->service->admitted( $this->order, 'classic' ) );
		$this->control->resume(); $resumed = $this->service->final_order( $this->order, 'classic' );
		self::assertFalse( $resumed->allowed ); self::assertSame( 'checkout_revalidation_required', $resumed->code );
		self::assertSame( 0, $this->quote->calls ); self::assertSame( 0, $this->control->confirms );
	}
	public function test_order_pay_does_not_adopt_an_unrelated_cart_ownership_latch(): void {
		$this->source->managed = false;
		$this->latch->capture_line( 'unrelated-cart-line', [ 'product_id' => 10, 'quantity' => 2 ], EmergencyOwnership::Managed );
		$result = $this->service->final_order( $this->order, 'order_pay' );
		self::assertTrue( $result->allowed ); self::assertSame( 'unmanaged', $result->code );
		self::assertSame( 0, $this->control->reads ); self::assertSame( 0, $this->quote->calls );
		$this->source->managed = true; $this->quote->valid = false;
		$managed = $this->service->final_order( $this->order, 'order_pay' );
		self::assertFalse( $managed->allowed ); self::assertSame( 'checkout_revalidation_required', $managed->code );
		self::assertSame( 1, $this->quote->calls ); self::assertSame( 0, $this->control->confirms );
	}
}
