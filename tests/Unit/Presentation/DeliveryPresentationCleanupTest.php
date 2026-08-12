<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DeliveryPresentationCleanupTest extends TestCase {

	public function test_customer_summary_rows_use_distinct_labels(): void {
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
				'Fulfilment',
				'Delivery method',
				'Delivery option',
				'Estimated delivery',
			],
			$keys
		);
		self::assertNotContains( 'Delivery', $keys );
		self::assertCount( 4, array_unique( $keys ) );
		self::assertSame( '3–6 business days', $rows[3]['value'] );
	}

	public function test_store_pickup_uses_method_and_ready_for_pickup_labels(): void {
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

		self::assertSame( 'Fulfilment', $keys[0] );
		self::assertSame( 'Method', $keys[1] );
		self::assertSame( 'Delivery option', $keys[2] );
		self::assertSame( 'Ready for pickup', $keys[3] );
	}

	public function test_cart_capture_helper_returns_labeled_rows_not_repeated_delivery(): void {
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
		self::assertSame( 'Fulfilment', $rows[0]['key'] );
		self::assertSame( 'Delivery method', $rows[1]['key'] );
		self::assertSame( 'Delivery option', $rows[2]['key'] );
		self::assertSame( 'Estimated delivery', $rows[3]['key'] );
	}

	public function test_protected_order_item_meta_keys_are_hidden_from_normal_wc_item_ui(): void {
		$display = new OrderDeliverySnapshotAdminDisplay(
			new OrderDeliverySnapshotReader(),
			new OrderDeliverySnapshotIntegrity()
		);

		$hidden = $display->hide_protected_order_item_meta( [ '_qty', '_tax_class' ] );

		self::assertContains( OrderDeliverySnapshot::META_LINE_SNAPSHOT, $hidden );
		self::assertContains( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, $hidden );
		self::assertContains( '_qty', $hidden );
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
