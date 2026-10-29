<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\OrderSurfaceAuthority;
use CetechDeliveryEngine\Tests\Unit\Shipment\ShipmentCreationFixtures;
use PHPUnit\Framework\TestCase;
use WC_Email;
use WC_Order;
use WC_Order_Item_Product;

/**
 * COR-034: customer and email meaning derive from the frozen machine fulfilment choice, not
 * English label inference; owner, guest-key and customer-email routing are qualified; an
 * invalid later snapshot member stays visible to staff.
 */
final class CustomerOrderMachineFactsAndAuthorityTest extends TestCase {

	private const DELIVERY_GROUP = 'international|delivery|12';

	private const PICKUP_GROUP = 'in_store|store_pickup|pickup';

	private FeatureFlags $flags;

	private CustomerOrderDeliverySummaryBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_customer_order_delivery_summary' => 1,
			'cetech_de_enable_customer_email_delivery_summary' => 1,
			'cetech_de_enable_shipment_records'                => 1,
		];
		$GLOBALS['cetech_de_test_caps']              = [];
		$GLOBALS['cetech_de_test_is_view_order']     = false;
		$GLOBALS['cetech_de_test_is_order_received'] = false;
		$GLOBALS['cetech_de_test_wc_orders']         = [];
		$_GET          = [];
		$this->flags   = new FeatureFlags();
		$this->builder = new CustomerOrderDeliverySummaryBuilder( new OrderDeliverySnapshotReader(), new OrderDeliverySnapshotIntegrity() );
	}

	protected function tearDown(): void {
		$_GET = [];
		$GLOBALS['cetech_de_test_is_view_order']     = false;
		$GLOBALS['cetech_de_test_is_order_received'] = false;
	}

	public function test_the_machine_fact_decides_the_meaning_and_a_translated_label_cannot(): void {
		$pickup   = new CustomerOrderDeliveryLineSummary( 'Lampe', 'Abholung am Schalter', 'Im Geschäft', 'Abholung im Geschäft', null, 'Bereit in 2 Stunden', null, null, null, 'Hauptausstellung', null, null, 11, 'store_pickup' );
		$delivery = new CustomerOrderDeliveryLineSummary( 'Stuhl', 'Lieferung Standard', 'Lager', 'Lieferung', null, '3–6 Werktage', null, null, null, null, null, null, 12, 'delivery' );
		$legacy   = new CustomerOrderDeliveryLineSummary( 'Old', 'Counter pickup', 'In store', 'Store pickup', null, null, null, null, null, 'Main showroom', null, null, 13 );

		self::assertSame( 'store_pickup', $pickup->fulfilment_choice_slug(), 'the machine fact wins over a label with no English keyword' );
		self::assertSame( 'delivery', $delivery->fulfilment_choice_slug() );
		self::assertSame( 'store_pickup', $legacy->fulfilment_choice_slug(), 'a legacy summary without a machine fact still falls back to its label' );

		$built = $this->builder->build( $this->mixed_order( 501 ) );
		self::assertNotNull( $built );
		$slugs = array_map( static fn ( CustomerOrderDeliveryLineSummary $line ): ?string => $line->fulfilment_choice_slug(), $built->lines );
		self::assertSame( [ 'delivery', 'store_pickup' ], $slugs, 'the builder carries the frozen choice from the line snapshot' );
		self::assertSame( [ 'delivery', 'store_pickup' ], array_map( static fn ( CustomerOrderDeliveryLineSummary $line ): string => $line->fulfilment_choice, $built->lines ) );
	}

	public function test_html_and_plain_text_emails_render_pickup_and_delivery_from_the_frozen_facts(): void {
		$order    = $this->mixed_order( 502 );
		$renderer = new CustomerOrderDeliveryEmailSummaryRenderer( $this->flags, new Requirements(), $this->builder );

		$html  = $this->capture( static fn () => $renderer->render( $order, false, false, new WC_Email( true ) ) );
		$plain = $this->capture( static fn () => $renderer->render( $order, false, true, new WC_Email( true ) ) );

		foreach ( [ $html, $plain ] as $output ) {
			self::assertStringContainsString( 'Estimated delivery', $output );
			self::assertStringContainsString( '5-7 business days', $output );
			self::assertStringContainsString( 'Ready for pickup', $output, 'the pickup line is rendered as pickup from the machine fact' );
			self::assertStringContainsString( '2 hours', $output );
			self::assertStringNotContainsString( 'in_store', $output, 'machine slugs never reach the customer' );
			self::assertStringNotContainsString( 'international', $output );
		}
		self::assertStringNotContainsString( '<', $plain, 'plain text carries no markup' );
		self::assertStringContainsString( '<h2>', $html );
	}

	public function test_email_summary_is_added_only_to_a_customer_email(): void {
		$order    = $this->mixed_order( 503 );
		$renderer = new CustomerOrderDeliveryEmailSummaryRenderer( $this->flags, new Requirements(), $this->builder );

		self::assertSame( '', $this->capture( static fn () => $renderer->render( $order, false, false ) ), 'a missing email object is refused' );
		self::assertSame( '', $this->capture( static fn () => $renderer->render( $order, false, false, false ) ) );
		self::assertSame( '', $this->capture( static fn () => $renderer->render( $order, false, false, new WC_Email( false ) ) ), 'a non-customer email gets no customer summary' );
		self::assertSame( '', $this->capture( static fn () => $renderer->render( $order, true, false, new WC_Email( true ) ) ), 'admin copies get no customer summary' );
		self::assertStringContainsString( 'Delivery details', $this->capture( static fn () => $renderer->render( $order, false, false, new WC_Email( true ) ) ) );
	}

	public function test_view_order_surface_requires_the_order_viewer_capability(): void {
		$order    = $this->mixed_order( 504 );
		$renderer = new CustomerOrderDeliverySummaryRenderer( $this->flags, new Requirements(), $this->builder );

		$GLOBALS['cetech_de_test_is_view_order'] = true;
		self::assertSame( '', $this->capture( static fn () => $renderer->render( $order ) ), 'a foreign viewer without view_order sees nothing' );

		$GLOBALS['cetech_de_test_caps'] = [ 'view_order' => true ];
		self::assertStringContainsString( 'Delivery details', $this->capture( static fn () => $renderer->render( $order ) ) );

		$GLOBALS['cetech_de_test_is_view_order'] = false;
		self::assertSame( '', $this->capture( static fn () => $renderer->render( $order ) ), 'an unknown surface is refused even for an owner' );
	}

	public function test_order_received_surface_accepts_the_guest_key_or_the_owner_only(): void {
		$order    = $this->mixed_order( 505, 'wc_order_abc123' );
		$renderer = new CustomerOrderDeliverySummaryRenderer( $this->flags, new Requirements(), $this->builder );
		$GLOBALS['cetech_de_test_is_order_received'] = true;

		self::assertFalse( OrderSurfaceAuthority::may_view( $order ), 'no key and no owner' );
		$_GET['key'] = 'wc_order_wrong';
		self::assertFalse( OrderSurfaceAuthority::may_view( $order ), 'a wrong key is refused' );
		self::assertSame( '', $this->capture( static fn () => $renderer->render( $order ) ) );

		$_GET['key'] = 'wc_order_abc123';
		self::assertTrue( OrderSurfaceAuthority::may_view( $order ) );
		self::assertStringContainsString( 'Delivery details', $this->capture( static fn () => $renderer->render( $order ) ) );

		$_GET = [];
		$GLOBALS['cetech_de_test_caps'] = [ 'view_order' => true ];
		self::assertTrue( OrderSurfaceAuthority::may_view( $order ), 'the logged-in owner needs no key' );
	}

	public function test_staff_see_an_invalid_later_group_member_instead_of_only_the_first(): void {
		$GLOBALS['cetech_de_test_caps'] = [ 'manage_woocommerce' => true ];
		$valid  = ShipmentCreationFixtures::product_item_from_line( ShipmentCreationFixtures::line( self::DELIVERY_GROUP, 10, 501, 'International Air', '5-7 business days', '25.00', 'international', 'delivery', 12, 'Good Widget' ) );
		$broken = new WC_Order_Item_Product(
			[
				'id'   => 502,
				'name' => 'Broken Lamp',
				'meta' => [
					OrderDeliverySnapshot::META_LINE_SNAPSHOT         => '{"not json',
					OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => OrderDeliverySnapshot::VERSION,
				],
			]
		);
		$order = ShipmentCreationFixtures::paid_order(
			506,
			[ $valid, $broken ],
			[ ShipmentCreationFixtures::wc_shipping_line( self::DELIVERY_GROUP ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( self::DELIVERY_GROUP ) ] )
		);

		$display = new OrderDeliverySnapshotAdminDisplay( new OrderDeliverySnapshotReader(), new OrderDeliverySnapshotIntegrity() );
		$html    = $this->capture( static fn () => $display->render_meta_box( $order ) );

		self::assertStringContainsString( 'Good Widget', $html );
		self::assertStringContainsString( 'Broken Lamp', $html, 'the unreadable member is listed, not hidden' );
		self::assertStringContainsString( 'Malformed delivery snapshot', $html );
		self::assertStringNotContainsString( '{"not json', $html, 'raw meta is never shown' );
	}

	// ------------------------------------------------------------------ helpers

	private function mixed_order( int $id, string $key = '' ): WC_Order {
		$delivery = ShipmentCreationFixtures::line( self::DELIVERY_GROUP, 10, 601, 'International Air', '5-7 business days', '25.00', 'international', 'delivery', 12, 'Chair' );
		$pickup   = ShipmentCreationFixtures::line( self::PICKUP_GROUP, 21, 602, 'Counter pickup', 'Ready in 2 hours', '0.00' );

		return ShipmentCreationFixtures::paid_order(
			$id,
			[ ShipmentCreationFixtures::product_item_from_line( $delivery ), ShipmentCreationFixtures::product_item_from_line( $pickup ) ],
			[ ShipmentCreationFixtures::wc_shipping_line( self::DELIVERY_GROUP ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( self::DELIVERY_GROUP ), ShipmentCreationFixtures::group( self::PICKUP_GROUP, '0.00', 1, true ) ] ),
			true,
			'processing',
			false,
			'bacs',
			[ 'order_key' => $key ]
		);
	}

	private function capture( callable $render ): string {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}
}
