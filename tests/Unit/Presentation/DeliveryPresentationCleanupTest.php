<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Admin\OrderShippingItemPresentationGuard;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DeliveryPresentationCleanupTest extends TestCase {

	public function test_customer_summary_rows_use_compact_public_contract(): void {
		$rows = DeliveryPresentationLabels::format_public_summary_rows(
			[
				'fulfilment_availability_label' => 'In warehouse',
				'fulfilment_choice_label'       => 'Delivery',
				'delivery_offer_public_label'   => 'FLAIROC QA Standard Delivery',
				'estimate_text'                 => 'Estimated 3–6 business days',
			],
			'delivery'
		);

		$keys = array_column( $rows, 'key' );

		self::assertSame(
			[
				'Delivery option',
				'Estimated delivery',
			],
			$keys
		);
		self::assertNotContains( 'Fulfilment', $keys );
		self::assertNotContains( 'Delivery method', $keys );
		self::assertCount( 2, array_unique( $keys ) );
		self::assertSame( '3–6 business days', $rows[1]['value'] );
	}

	public function test_store_pickup_uses_ready_for_pickup_without_method_label(): void {
		$rows = DeliveryPresentationLabels::format_public_summary_rows(
			[
				'fulfilment_availability_label' => 'In store',
				'fulfilment_choice_label'       => 'Store pickup',
				'delivery_offer_public_label'   => 'Counter pickup',
				'estimate_text'                 => 'Estimated 1–2 business days',
			],
			'store_pickup'
		);

		$keys = array_column( $rows, 'key' );

		self::assertSame( 'Delivery option', $keys[0] );
		self::assertSame( 'Ready for pickup', $keys[1] );
		self::assertNotContains( 'Fulfilment', $keys );
		self::assertNotContains( 'Method', $keys );
	}

	public function test_cart_capture_helper_returns_compact_rows_not_fulfilment_or_method(): void {
		$rows = CartDeliverySelectionCapture::formatPublicSummaryRows(
			[
				'fulfilment_availability_label' => 'In warehouse',
				'fulfilment_choice_label'       => 'Delivery',
				'delivery_offer_public_label'   => 'FLAIROC QA Standard Delivery',
				'estimate_text'                 => 'Estimated 3–6 business days',
			],
			'delivery'
		);

		$delivery_key_count = 0;
		foreach ( $rows as $row ) {
			if ( 'Delivery' === $row['key'] ) {
				++$delivery_key_count;
			}
		}

		self::assertSame( 0, $delivery_key_count );
		self::assertSame( 'Delivery option', $rows[0]['key'] );
		self::assertSame( 'Estimated delivery', $rows[1]['key'] );
		self::assertCount( 2, $rows );
	}

	public function test_protected_order_item_meta_keys_are_hidden_from_normal_wc_item_ui(): void {
		$display = new OrderDeliverySnapshotAdminDisplay(
			new OrderDeliverySnapshotReader(),
			new OrderDeliverySnapshotIntegrity()
		);

		$hidden = $display->hide_protected_order_item_meta( [ '_qty', '_tax_class' ] );

		self::assertContains( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $hidden );
		self::assertContains( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, $hidden );
		self::assertContains( OrderShippingItemPresentationGuard::GROUP_ID_META_KEY, $hidden );
		self::assertContains( '_qty', $hidden );
	}

	public function test_shipping_group_identity_meta_is_hidden_from_formatted_order_meta(): void {
		$guard = new OrderShippingItemPresentationGuard();

		$hidden = $guard->hide_technical_order_item_meta( [] );
		self::assertContains( 'cetech_de_group_id', $hidden );
		self::assertContains( 'package_qty', $hidden );

		$group_meta = (object) [
			'key'         => 'cetech_de_group_id',
			'value'       => 'in_warehouse|delivery|1',
			'display_key' => 'cetech_de_group_id',
			'display_value' => 'in_warehouse|delivery|1',
		];
		$package_qty = (object) [
			'key'         => 'package_qty',
			'value'       => '2',
			'display_key' => 'Package Qty',
			'display_value' => '2',
		];
		$impl_label = (object) [
			'key'         => 'Shipping Method',
			'value'       => 'Delivery engine selected offer',
			'display_key' => 'Shipping Method',
			'display_value' => 'Delivery engine selected offer',
		];
		$keep = (object) [
			'key'         => 'Note',
			'value'       => 'Leave at door',
			'display_key' => 'Note',
			'display_value' => 'Leave at door',
		];

		// Exercise key classification without WooCommerce bootstrap.
		self::assertTrue( $guard->is_technical_meta_key( 'cetech_de_group_id' ) );
		self::assertTrue( $guard->is_technical_meta_key( 'Package Qty' ) );
		self::assertTrue( $guard->is_technical_meta_key( '_cetech_de_delivery_snapshot' ) );
		self::assertFalse( $guard->is_technical_meta_key( 'Note' ) );

		$formatted = $guard->strip_technical_formatted_meta(
			[
				1 => $group_meta,
				2 => $package_qty,
				3 => $impl_label,
				4 => $keep,
			],
			null
		);

		self::assertArrayNotHasKey( 1, $formatted );
		self::assertArrayNotHasKey( 2, $formatted );
		self::assertArrayNotHasKey( 3, $formatted );
		self::assertArrayHasKey( 4, $formatted );

		self::assertSame(
			'Delivery',
			$guard->operational_shipping_method_title( 'Delivery Engine — Selected Offer', null )
		);
		self::assertSame(
			'Delivery',
			$guard->operational_shipping_method_title( 'delivery_engine_selected_offer', null )
		);
		self::assertSame(
			'Delivery',
			$guard->operational_shipping_method_title( 'Delivery', null )
		);
	}

	public function test_group_identity_storage_key_remains_available_for_snapshots(): void {
		self::assertSame( 'cetech_de_group_id', OrderShippingItemPresentationGuard::GROUP_ID_META_KEY );
		self::assertSame( 'delivery_engine_selected_offer', OrderShippingItemPresentationGuard::SHIPPING_METHOD_ID );

		$shipping_method_file = dirname( __DIR__, 3 ) . '/src/Infrastructure/WooCommerce/Shipping/SelectedOfferShippingMethod.php';
		$source               = file_get_contents( $shipping_method_file );
		self::assertIsString( $source );
		self::assertStringContainsString( "'cetech_de_group_id'", $source );
		self::assertStringContainsString( "method_title       = __( 'Delivery'", $source );
		self::assertStringNotContainsString( 'Delivery Engine — Selected Offer', $source );
	}

	public function test_admin_panel_title_is_operational_delivery_information(): void {
		$reflection = new ReflectionClass( OrderDeliverySnapshotAdminDisplay::class );
		$source     = file_get_contents( $reflection->getFileName() );

		self::assertIsString( $source );
		self::assertStringContainsString( "__( 'Delivery information'", $source );
		self::assertStringNotContainsString( 'Delivery Engine — Order Snapshots', $source );
		self::assertStringNotContainsString( 'Snapshot version', $source );
		self::assertStringNotContainsString( 'Quote status', $source );
		self::assertStringNotContainsString( 'Stored line version', $source );
		self::assertStringNotContainsString( 'Internal IDs', $source );
		self::assertStringContainsString( 'DeliveryPresentationLabels::fulfilment()', $source );
		self::assertStringContainsString( 'DeliveryPresentationLabels::delivery_charge()', $source );
	}

	public function test_protected_meta_keys_remain_defined_for_storage(): void {
		self::assertSame( '_cetech_de_delivery_snapshot', OrderDeliverySnapshot::META_LINE_SNAPSHOT );
		self::assertSame( '_cetech_de_delivery_snapshot_version', OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION );
		self::assertSame( '_cetech_de_delivery_quote_snapshot', OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT );
		self::assertSame( '_cetech_de_order_delivery_snapshot_version', OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION );
	}
}
