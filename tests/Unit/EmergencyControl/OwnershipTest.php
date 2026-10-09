<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

require_once __DIR__ . '/CheckoutTestFixtures.php';

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyConfigurationOwnershipProbeInterface;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnership;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipClassifier;
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyOwnershipLatch;
use CetechDeliveryEngine\Application\EmergencyControl\WpdbEmergencyConfigurationOwnershipProbe;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\DeliveryQuoteSnapshotEnvelope;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use CetechDeliveryEngine\Tests\Unit\Order\PromiseSnapshotFixtures;
use PHPUnit\Framework\TestCase;

final class OwnershipTest extends TestCase {
	private CheckoutSourceFixture $source;
	private EmergencyOwnershipClassifier $classifier;
	protected function setUp(): void {
		$GLOBALS['blog_id'] = 1;
		$GLOBALS['cetech_de_test_wc_products'] = [ 10 => new \WC_Product( [ 'id' => 10, 'type' => 'simple' ] ), 11 => new \WC_Product( [ 'id' => 11, 'type' => 'variable' ] ), 12 => new \WC_Product( [ 'id' => 12, 'type' => 'variation', 'parent_id' => 11 ] ) ];
		$this->source = new CheckoutSourceFixture(); $this->classifier = new EmergencyOwnershipClassifier( [ $this->source ] );
	}
	protected function tearDown(): void { unset( $GLOBALS['cetech_de_test_wc_products'], $GLOBALS['blog_id'] ); }
	private function line( array $extra = [] ): array { return $extra + [ 'product_id' => 10, 'variation_id' => 0, 'quantity' => 1 ]; }
	public function test_managed_current_configuration_ignores_all_selector_flags(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_feature_flags'] = [ 'enable_product_delivery_selector' => false, 'enable_shipping_rate_calculation' => false ];
		self::assertSame( EmergencyOwnership::Managed, $this->classifier->line( 'line', $this->line() ) );
	}
	public function test_unavailable_options_do_not_mean_unmanaged(): void { self::assertSame( EmergencyOwnership::Managed, $this->classifier->product( 10 ) ); }
	public function test_successful_authoritative_no_match_is_native(): void { $this->source->managed = false; self::assertSame( EmergencyOwnership::Unmanaged, $this->classifier->product( 10 ) ); }
	public function test_failed_resolver_is_not_no_match(): void { $this->source->failed = true; self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->product( 10 ) ); }
	public function test_unconfigured_ecr_failure_does_not_block_proven_native_product(): void {
		$this->source->managed = false; $ecr = new CheckoutSourceFixture(); $ecr->failed = true; $ecr->label = 'ecr';
		$probe = $this->createMock( EmergencyConfigurationOwnershipProbeInterface::class ); $probe->method( 'ownership' )->willReturn( EmergencyOwnership::Unmanaged );
		self::assertSame( EmergencyOwnership::Unmanaged, ( new EmergencyOwnershipClassifier( [ $this->source, $ecr ], null, $probe ) )->product( 10 ) );
	}
	public function test_failed_physical_probe_does_not_mask_itself_as_native(): void {
		$this->source->managed = false; $probe = $this->createMock( EmergencyConfigurationOwnershipProbeInterface::class ); $probe->method( 'ownership' )->willReturn( EmergencyOwnership::Unresolved );
		self::assertSame( EmergencyOwnership::Unresolved, ( new EmergencyOwnershipClassifier( [ $this->source ], null, $probe ) )->product( 10 ) );
	}
	public function test_malformed_owned_markers_remain_managed_before_cleanup(): void {
		$this->source->managed = false;
		foreach ( [ CartDeliverySelectionCapture::CART_SELECTION_KEY, CartDeliverySelectionCapture::CART_HASH_KEY, 'cetech_de_customer_context' ] as $marker ) { self::assertSame( EmergencyOwnership::Managed, $this->classifier->line( 'line', $this->line( [ $marker => false ] ) ) ); }
	}
	public function test_exact_variation_parent_and_actual_cart_product_are_checked(): void {
		self::assertSame( EmergencyOwnership::Managed, $this->classifier->product( 11, 12 ) );
		self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->product( 10, 12 ) );
		self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->line( 'line', $this->line( [ 'data' => $GLOBALS['cetech_de_test_wc_products'][12] ] ) ) );
	}
	public function test_fractional_quantity_and_trailing_product_id_refuse(): void {
		self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->line( 'line', $this->line( [ 'quantity' => 1.5 ] ) ) );
		self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->line( 'line', $this->line( [ 'product_id' => '10garbage' ] ) ) );
	}
	public function test_200_line_and_package_ceiling_is_complete_not_truncated(): void {
		$this->source->managed = false; $lines = []; for ( $i = 0; $i < 200; ++$i ) { $lines[ (string) $i ] = $this->line(); }
		self::assertSame( EmergencyOwnership::Unmanaged, $this->classifier->cart( $lines, array_fill( 0, 200, [] ) ) );
		$lines['over'] = $this->line(); self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->cart( $lines ) );
		self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->cart( [], array_fill( 0, 201, [] ) ) );
	}
	public function test_malformed_owned_package_is_never_a_native_residual(): void { $this->source->managed = false; self::assertSame( EmergencyOwnership::Managed, $this->classifier->cart( [ 'line' => $this->line() ], [ [ 'cetech_de' => null ] ] ) ); }
	public function test_current_configuration_is_refreshed_before_classification(): void {
		$this->source->managed = false; $calls = 0; $classifier = new EmergencyOwnershipClassifier( [ $this->source ], null, null, function () use ( &$calls ): void { ++$calls; $this->source->managed = true; } );
		self::assertSame( EmergencyOwnership::Managed, $classifier->product( 10 ) ); self::assertSame( 1, $calls );
	}
	public function test_order_malformed_snapshot_is_owned_even_after_configuration_removed(): void {
		$this->source->managed = false; $item = new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => false ] ] );
		self::assertSame( EmergencyOwnership::Managed, $this->classifier->order( new \WC_Order( [ 'items' => [ $item ] ] ) ) );
	}
	public function test_request_latch_binds_empty_cart_to_actual_order_not_client_stamp(): void {
		$latch = new EmergencyOwnershipLatch(); $latch->capture_line( 'line', $this->line(), EmergencyOwnership::Managed );
		$item = new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1 ] ); $order = new \WC_Order( [ 'items' => [ $item ] ] );
		$latch->bind_order_line( 'line', $item ); self::assertTrue( $latch->matches_order( $order ) );
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'items' => [ new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 2 ] ) ], 'meta' => [ 'cetech_de_admitted' => true ] ] ) ) );
		$latch->forget_line( 'line' ); self::assertFalse( $latch->has_possible_ownership() );
	}
	public function test_latch_binds_context_even_when_products_and_quantities_are_unchanged(): void {
		$latch = new EmergencyOwnershipLatch(); $context = \CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext::pickup( 55 );
		$latch->capture_line( 'line', $this->line( [ 'cetech_de_customer_context' => $context->toArray() ] ), EmergencyOwnership::Managed );
		$raw = [ 'contract_version' => '1', 'snapshot_version' => '2', 'product_id' => 10, 'variation_id' => null, 'fulfilment_availability' => 'in_store', 'fulfilment_choice' => 'store_pickup', 'delivery_offer_id' => null, 'quantity' => 1, 'currency_code' => 'GHS', 'quoted_amount' => null, 'quote_status' => 'selection_only', 'snapshotted_at' => '2026-10-07T01:00:00+00:00', 'customer_context_version' => 1, 'pickup_location_id' => 55 ];
		$item = new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => json_encode( $raw, JSON_THROW_ON_ERROR ), OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '2' ] ] );
		$order = new \WC_Order( [ 'items' => [ $item ] ] ); self::assertTrue( $latch->matches_order( $order ) );
		$raw['pickup_location_id'] = 56; $item->update_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, json_encode( $raw, JSON_THROW_ON_ERROR ) ); self::assertFalse( $latch->matches_order( $order ) );
	}
	/** Pure required-reader fixture; this does not establish a native placement receipt. */
	private function promise_latch(): array {
		$context = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
		$latch = new EmergencyOwnershipLatch();
		$latch->capture_line( 'promise-line', [ 'product_id' => 16, 'variation_id' => 0, 'quantity' => 2, 'cetech_de_customer_context' => $context->toArray() ], EmergencyOwnership::Managed );
		$raw = PromiseSnapshotFixtures::line();
		$item = new CheckoutPersistedItemFixture( [ 'id' => 501, 'product_id' => 16, 'quantity' => 2, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => json_encode( $raw, JSON_THROW_ON_ERROR ), OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '3', DeliveryQuoteSnapshotEnvelope::META_FORMAT => '2' ] ], 19 );
		$order = new \WC_Order( [ 'id' => 19, 'items' => [ $item ] ] );
		$latch->bind_order_line( 'promise-line', $item ); $latch->freeze_saved_order( $order );
		return [ $latch, $item, $order, $raw ];
	}
	public function test_latch_recognizes_exact_required_promise_customer_context_after_saved_reload(): void {
		[ $latch, $item, $order ] = $this->promise_latch();
		$raw = $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true );
		self::assertTrue( $latch->matches_order( $order ) );
		self::assertTrue( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ clone $item ] ] ) ) );
		self::assertSame( $raw, $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ) );
	}
	public function test_required_promise_context_changes_still_refuse_exact_product_quantity_and_packet(): void {
		[ $latch, $item, $order, $raw ] = $this->promise_latch();
		$context = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryKumasi() );
		$raw['matching_location'] = $context->matching_location?->toArray(); $raw['delivery_address'] = $context->delivery_address?->toArray();
		$raw['matching_identity'] = $context->matching_identity; $raw['delivery_location_identity'] = $context->delivery_location_identity;
		$changed = clone $item; $changed->update_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, json_encode( $raw, JSON_THROW_ON_ERROR ) );
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $changed ] ] ) ) );
		self::assertTrue( $latch->matches_order( $order ) );
		$GLOBALS['blog_id'] = 2; self::assertFalse( $latch->matches_order( $order ) );
	}
	public function test_required_promise_missing_packet_and_future_outer_do_not_project_customer_context(): void {
		[ $latch, $item, $order, $raw ] = $this->promise_latch();
		$missing = $raw; unset( $missing['delivery_quote'] );
		$future = $raw; $future['snapshot_version'] = '9';
		foreach ( [ $missing, $future ] as $bad ) {
			$changed = clone $item; $changed->update_meta_data( OrderDeliverySnapshot::META_LINE_SNAPSHOT, json_encode( $bad, JSON_THROW_ON_ERROR ) );
			self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $changed ] ] ) ) );
		}
		self::assertTrue( $latch->matches_order( $order ) );
	}
	public function test_physical_probe_checks_read_failure_before_declaring_absence(): void {
		$old = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class { public string $prefix = 'wp_'; public string $last_error = ''; public function prepare( string $sql, mixed ...$args ): string { return $sql; } public function get_results( string $sql, mixed $format ): array { $this->last_error = 'PRIVATE_SQL'; return []; } };
		try { self::assertSame( EmergencyOwnership::Unresolved, ( new WpdbEmergencyConfigurationOwnershipProbe() )->ownership( 10, null ) ); } finally { $GLOBALS['wpdb'] = $old; }
	}
	public function test_latch_accepts_the_exact_persisted_item_after_woocommerce_reload(): void {
		$latch = new EmergencyOwnershipLatch(); $latch->capture_line( 'line', $this->line(), EmergencyOwnership::Managed );
		$original = new CheckoutPersistedItemFixture( [ 'id' => 71, 'product_id' => 10, 'quantity' => 1 ], 19 );
		$order = new \WC_Order( [ 'id' => 19, 'items' => [ $original ] ] ); $latch->bind_order_line( 'line', $original );
		$latch->freeze_saved_order( $order );
		$reloaded = new CheckoutPersistedItemFixture( [ 'id' => 71, 'product_id' => 10, 'quantity' => 1 ], 19 );
		self::assertNotSame( $original, $reloaded );
		self::assertTrue( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $reloaded ] ] ) ) );
	}
	private function persisted_pickup( int $item_id = 71, int $owner_id = 19, int $pickup = 55, array $extra = [] ): CheckoutPersistedItemFixture {
		$raw = [ 'contract_version' => '1', 'snapshot_version' => '2', 'product_id' => 10, 'variation_id' => null, 'fulfilment_availability' => 'in_store', 'fulfilment_choice' => 'store_pickup', 'delivery_offer_id' => null, 'quantity' => 1, 'currency_code' => 'GHS', 'quoted_amount' => null, 'quote_status' => 'selection_only', 'snapshotted_at' => '2026-10-07T01:00:00+00:00', 'customer_context_version' => 1, 'pickup_location_id' => $pickup ];
		return new CheckoutPersistedItemFixture( $extra + [ 'id' => $item_id, 'product_id' => 10, 'quantity' => 1, 'meta' => [ OrderDeliverySnapshot::META_LINE_SNAPSHOT => json_encode( $raw, JSON_THROW_ON_ERROR ), OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => '2' ] ], $owner_id );
	}
	private function frozen_pickup(): array {
		$latch = new EmergencyOwnershipLatch(); $context = \CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext::pickup( 55 );
		$latch->capture_line( 'line', $this->line( [ 'cetech_de_customer_context' => $context->toArray() ] ), EmergencyOwnership::Managed );
		$item = $this->persisted_pickup(); $order = new \WC_Order( [ 'id' => 19, 'items' => [ $item ] ] );
		$latch->bind_order_line( 'line', $item ); $latch->freeze_saved_order( $order );
		return [ $latch, $item, $order ];
	}
	public function test_saved_coordinates_never_accept_a_different_same_product_line_or_context(): void {
		[ $latch ] = $this->frozen_pickup();
		self::assertTrue( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $this->persisted_pickup() ] ] ) ) );
		foreach ( [ $this->persisted_pickup( item_id: 72 ), $this->persisted_pickup( owner_id: 20 ), $this->persisted_pickup( pickup: 56 ), $this->persisted_pickup( extra: [ 'quantity' => 2 ] ), $this->persisted_pickup( extra: [ 'product_id' => 11 ] ), $this->persisted_pickup( extra: [ 'variation_id' => 12 ] ) ] as $wrong ) {
			self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $wrong ] ] ) ) );
		}
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 20, 'items' => [ $this->persisted_pickup() ] ] ) ) );
		$GLOBALS['blog_id'] = 2; self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $this->persisted_pickup() ] ] ) ) );
	}
	public function test_original_reference_mutation_cannot_reassign_frozen_coordinates(): void {
		[ $latch, $item, $order ] = $this->frozen_pickup(); $item->mutate_raw( 'id', 72 );
		$latch->freeze_saved_order( $order );
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $this->persisted_pickup( item_id: 72 ) ] ] ) ) );
		[ $latch, $item ] = $this->frozen_pickup(); $item->change_owner( 20 );
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $this->persisted_pickup() ] ] ) ) );
	}
	public function test_raw_original_quantity_mutation_hidden_by_view_getter_still_refuses_reload(): void {
		[ $latch, $item ] = $this->frozen_pickup(); $item->view_quantity = 1; $item->mutate_raw( 'quantity', 2 );
		self::assertSame( 1, $item->get_quantity() );
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $this->persisted_pickup() ] ] ) ) );
	}
	public function test_unsaved_reference_does_not_transfer_before_a_trusted_save_boundary(): void {
		$latch = new EmergencyOwnershipLatch(); $latch->capture_line( 'line', $this->line(), EmergencyOwnership::Managed );
		$item = new \WC_Order_Item_Product( [ 'product_id' => 10, 'quantity' => 1 ] ); $latch->bind_order_line( 'line', $item );
		self::assertTrue( $latch->matches_order( new \WC_Order( [ 'items' => [ $item ] ] ) ) );
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'items' => [ clone $item ] ] ) ) );
	}
	public function test_saved_boundary_refuses_missing_mapping_duplicate_physical_ids_and_later_rebinding(): void {
		$latch = new EmergencyOwnershipLatch(); $latch->capture_line( 'unbound', $this->line(), EmergencyOwnership::Managed );
		$order = new \WC_Order( [ 'id' => 19, 'items' => [ $this->persisted_pickup() ] ] ); $latch->freeze_saved_order( $order ); self::assertFalse( $latch->matches_order( $order ) );
		$latch = new EmergencyOwnershipLatch(); $items = [ $this->persisted_pickup(), $this->persisted_pickup() ];
		foreach ( $items as $key => $item ) { $latch->capture_line( (string) $key, $this->line(), EmergencyOwnership::Managed ); $latch->bind_order_line( (string) $key, $item ); }
		$order = new \WC_Order( [ 'id' => 19, 'items' => $items ] ); $latch->freeze_saved_order( $order ); self::assertFalse( $latch->matches_order( $order ) );
		[ $latch ] = $this->frozen_pickup(); $latch->bind_order_line( 'line', $this->persisted_pickup( item_id: 72 ) );
		self::assertFalse( $latch->matches_order( new \WC_Order( [ 'id' => 19, 'items' => [ $this->persisted_pickup() ] ] ) ) );
	}
	public function test_actual_native_persister_mapping_is_not_delivery_ownership(): void {
		$this->source->managed = false; $order = checkout_native_mapping_order(); $item = $order->get_items( 'line_item' )[0];
		self::assertSame( 'ordinary-key', $item->get_meta( OrderDeliverySnapshot::META_CART_ITEM_KEY, true ) );
		foreach ( [ OrderDeliverySnapshot::META_LINE_SNAPSHOT, OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION ] as $key ) { self::assertSame( '', $item->get_meta( $key, true ) ); }
		foreach ( [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION ] as $key ) { self::assertSame( '', $order->get_meta( $key, true ) ); }
		self::assertSame( EmergencyOwnership::Unmanaged, $this->classifier->order( $order ) );
		$this->source->failed = true; self::assertSame( EmergencyOwnership::Unresolved, $this->classifier->order( $order ) );
	}
	public function test_mapping_only_exception_never_masks_real_or_malformed_delivery_evidence(): void {
		$this->source->managed = false;
		foreach ( [ OrderDeliverySnapshot::META_LINE_SNAPSHOT, OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION ] as $key ) {
			$order = checkout_native_mapping_order(); $order->get_items( 'line_item' )[0]->update_meta_data( $key, false );
			self::assertSame( EmergencyOwnership::Managed, $this->classifier->order( $order ) );
		}
		foreach ( [ OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION ] as $key ) {
			$order = checkout_native_mapping_order(); $order->update_meta_data( $key, false ); self::assertSame( EmergencyOwnership::Managed, $this->classifier->order( $order ) );
		}
		foreach ( [ [ 'method_id' => \CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod::METHOD_ID ], [ 'method_id' => 'flat_rate', 'meta' => [ 'cetech_de_group_id' => false ] ] ] as $shipping ) {
			$native = checkout_native_mapping_order(); $order = new \WC_Order( [ 'id' => 19, 'items' => $native->get_items(), 'shipping_items' => [ new \WC_Order_Item_Shipping( $shipping ) ] ] );
			self::assertSame( EmergencyOwnership::Managed, $this->classifier->order( $order ) );
		}
		$this->source->managed = true; self::assertSame( EmergencyOwnership::Managed, $this->classifier->order( checkout_native_mapping_order() ) );
	}
}
