<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Tests\Unit\Shipment\ShipmentCreationFixtures;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Regression for the QA.1 checkout / View Order fatal: map_line_snapshot must receive
 * the WooCommerce order-item ID from build_line_from_item, not dereference a missing $item.
 */
final class CustomerOrderDeliverySummaryBuilderTest extends TestCase {

	private FeatureFlags $flags;

	private CustomerOrderDeliverySummaryBuilder $builder;

	private WpdbShipmentRepository $shipments;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']       = [
			'date_format' => 'Y-m-d',
			'time_format' => 'H:i',
		];
		$GLOBALS['cetech_de_test_is_view_order'] = true;
		$GLOBALS['cetech_de_test_wc_orders']     = [];

		$this->flags   = new FeatureFlags();
		$this->builder = new CustomerOrderDeliverySummaryBuilder(
			new OrderDeliverySnapshotReader(),
			new OrderDeliverySnapshotIntegrity()
		);
		$this->shipments = ShipmentCreationFixtures::repository();

		$this->flags->set( 'enable_customer_order_delivery_summary', true );
		$this->flags->set( 'enable_customer_email_delivery_summary', true );
	}

	public function test_historical_line_snapshot_propagates_order_item_id_without_null_dereference(): void {
		$order   = $this->flairoc_style_order( 39735, 19 );
		$summary = $this->builder->build( $order );

		self::assertNotNull( $summary );
		self::assertCount( 1, $summary->lines );
		self::assertSame( 19, $summary->lines[0]->order_item_id );
		self::assertSame( 'FLAIROC Delivery Engine QA Product', $summary->lines[0]->product_name );
		self::assertSame( 'FLAIROC QA Standard Delivery', $summary->lines[0]->delivery_option_label );
		self::assertNull( $order->get_items()[0]->get_product() );
	}

	public function test_existing_rc4_snapshot_still_renders_compact_summary(): void {
		$order   = $this->flairoc_style_order( 39735, 19 );
		$html    = $this->render_view_order( $order );
		$email   = $this->render_customer_email( $order );

		self::assertStringContainsString( 'Delivery details', $html );
		self::assertStringContainsString( 'FLAIROC QA Standard Delivery', $html );
		self::assertStringContainsString( '3–6 business days', $html );
		self::assertStringNotContainsString( 'Fulfilment', $html );
		self::assertStringNotContainsString( '250.0000', $html );

		self::assertStringContainsString( 'Delivery details', $email );
		self::assertStringContainsString( 'FLAIROC QA Standard Delivery', $email );
	}

	public function test_deleted_current_product_still_renders_from_saved_order_snapshot(): void {
		$item = $this->line_item( 19, 'Historical QA Product', null );
		$order = new WC_Order(
			[
				'id'    => 39740,
				'items' => [ $item ],
			]
		);

		$summary = $this->builder->build( $order );
		$html    = $this->render_view_order( $order );

		self::assertNotNull( $summary );
		self::assertSame( 19, $summary->lines[0]->order_item_id );
		self::assertSame( 'Historical QA Product', $summary->lines[0]->product_name );
		self::assertNull( $item->get_product() );
		self::assertStringContainsString( 'FLAIROC QA Standard Delivery', $html );
		self::assertStringContainsString( '3–6 business days', $html );
	}

	public function test_view_order_and_email_paths_do_not_fatal(): void {
		$order = $this->flairoc_style_order( 39735, 19 );

		$this->render_view_order( $order );
		$this->render_customer_email( $order );
		$this->render_customer_email( $order, true );

		$this->addToAssertionCount( 1 );
	}

	public function test_shipment_records_off_keeps_rc4_compact_summary(): void {
		$order = $this->flairoc_style_order( 39735, 19 );
		$this->store_covering_shipment( 39735, 19 );

		$html = $this->render_view_order( $order );

		self::assertStringContainsString( 'Delivery details', $html );
		self::assertStringContainsString( 'FLAIROC QA Standard Delivery', $html );
	}

	public function test_shipment_records_on_suppresses_covered_delivery_line(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order = $this->flairoc_style_order( 39735, 19 );
		$this->store_covering_shipment( 39735, 19 );

		$html = $this->render_view_order( $order );

		self::assertSame( '', $html );
	}

	public function test_shipment_card_suppression_uses_order_item_identity(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$covered   = $this->line_item( 19, 'Covered product' );
		$uncovered = $this->line_item( 20, 'Uncovered product', null, 'in_warehouse|delivery|2', 'Express Delivery' );
		$order     = new WC_Order(
			[
				'id'    => 39741,
				'items' => [ $covered, $uncovered ],
			]
		);
		$this->store_covering_shipment( 39741, 19 );

		$summary = $this->builder->build( $order );
		self::assertNotNull( $summary );
		self::assertSame( 19, $summary->lines[0]->order_item_id );
		self::assertSame( 20, $summary->lines[1]->order_item_id );

		$html = $this->render_view_order( $order );

		self::assertStringContainsString( 'Delivery details', $html );
		self::assertStringContainsString( 'Express Delivery', $html );
		self::assertStringNotContainsString( 'Covered product', $html );
		self::assertStringNotContainsString( 'FLAIROC QA Standard Delivery', $html );
	}

	private function flairoc_style_order( int $order_id, int $order_item_id ): WC_Order {
		return new WC_Order(
			[
				'id'    => $order_id,
				'items' => [ $this->line_item( $order_item_id, 'FLAIROC Delivery Engine QA Product' ) ],
			]
		);
	}

	private function line_item(
		int $order_item_id,
		string $name,
		mixed $product = null,
		string $group_id = 'in_warehouse|delivery|1',
		string $label = 'FLAIROC QA Standard Delivery'
	): WC_Order_Item_Product {
		$line = ShipmentCreationFixtures::line(
			$group_id,
			39705,
			$order_item_id,
			$label,
			'Estimated 3–6 business days',
			'250.0000',
			'in_warehouse',
			'delivery',
			1,
			$name,
			1,
			null,
			null,
			null,
			'USD',
			1
		);
		$item = ShipmentCreationFixtures::product_item_from_line( $line );

		return new WC_Order_Item_Product(
			[
				'id'      => $order_item_id,
				'name'    => $name,
				'product' => $product,
				'meta'    => [
					\CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT         => $item->get_meta( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ),
					\CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => $item->get_meta( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, true ),
				],
			]
		);
	}

	private function store_covering_shipment( int $order_id, int $order_item_id ): void {
		$shipment = $this->shipments->create(
			Shipment::create(
				$order_id,
				'in_warehouse|delivery|1',
				delivery_offer_public_label: 'FLAIROC QA Standard Delivery',
				shipment_number: $order_id . '-D1'
			)
		);
		$this->shipments->replaceItems(
			$shipment->id,
			[ ShipmentItem::create( $shipment->id, $order_id, $order_item_id, 1, 39705, null, 'FLAIROC Delivery Engine QA Product' ) ]
		);
	}

	private function render_view_order( WC_Order $order ): string {
		$renderer = new CustomerOrderDeliverySummaryRenderer(
			$this->flags,
			new Requirements(),
			$this->builder,
			new CustomerShipmentQuery( $this->flags, $this->shipments )
		);

		ob_start();
		$renderer->render( $order );

		return (string) ob_get_clean();
	}

	private function render_customer_email( WC_Order $order, bool $plain_text = false ): string {
		$renderer = new CustomerOrderDeliveryEmailSummaryRenderer(
			$this->flags,
			new Requirements(),
			$this->builder
		);

		ob_start();
		$renderer->render( $order, false, $plain_text );

		return (string) ob_get_clean();
	}
}
