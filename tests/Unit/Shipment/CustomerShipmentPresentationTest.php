<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Order\CustomerOrderDeliveryLineSummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummary;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingInput;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerShipmentRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WC_Order;

final class CustomerShipmentPresentationTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private FakeWpdb $wpdb;

	private CustomerShipmentQuery $query;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']     = [];
		$GLOBALS['cetech_de_test_is_view_order'] = true;
		$GLOBALS['cetech_de_test_caps']        = [];

		$this->wpdb = new FakeWpdb();
		$this->wpdb->create_table(
			'wp_delivery_engine_shipments',
			[
				[ 'idempotency_key' ],
				[ 'order_id', 'delivery_group_id' ],
			]
		);
		$this->wpdb->create_table(
			'wp_delivery_engine_shipment_items',
			[
				[ 'shipment_id', 'order_item_id' ],
			]
		);
		$this->wpdb->create_table( 'wp_delivery_engine_shipment_events' );
		$GLOBALS['wpdb']  = $this->wpdb;
		$this->repository = new WpdbShipmentRepository();
		$this->query      = new CustomerShipmentQuery( new FeatureFlags(), $this->repository );
	}

	public function test_flag_off_hides_customer_shipment_cards(): void {
		$order = $this->order( 901 );
		$this->store_delivery_shipment( 901, 'g-air', '901-D1', 'Air Shipping', 701, 'Historical Widget' );

		$html = $this->render_cards( $order );

		self::assertSame( '', $html );
		self::assertSame( [], $this->query->cards_for_order( $order ) );
	}

	public function test_rightful_order_renders_card_and_another_order_does_not(): void {
		$this->enable_shipment_records();
		$owner = $this->order( 902 );
		$other = $this->order( 903 );
		$this->store_delivery_shipment( 902, 'g-air', '902-D1', 'Air Shipping', 702, 'Historical Widget' );

		$owner_html = $this->render_cards( $owner );
		$other_html = $this->render_cards( $other );

		self::assertStringContainsString( 'Shipment 902-D1', $owner_html );
		self::assertStringContainsString( 'Air Shipping', $owner_html );
		self::assertStringContainsString( 'Awaiting fulfilment', $owner_html );
		self::assertStringContainsString( 'Estimated delivery to your address', $owner_html );
		self::assertStringContainsString( '3-5 business days', $owner_html );
		self::assertStringContainsString( 'Historical Widget', $owner_html );
		self::assertStringContainsString( '1 item', $owner_html );
		self::assertStringNotContainsString( 'awaiting_fulfilment', $owner_html );
		self::assertStringNotContainsString( '902-D1', $other_html );
		self::assertSame( [], $this->query->cards_for_order( $other ) );
	}

	public function test_multiple_shipments_render_separately(): void {
		$this->enable_shipment_records();
		$order = $this->order( 904 );
		$this->store_delivery_shipment( 904, 'g-air', '904-D1', 'Air Shipping', 711, 'Air item' );
		$this->store_delivery_shipment( 904, 'g-sea', '904-D2', 'Sea Shipping', 712, 'Sea item' );

		$html = $this->render_cards( $order );

		self::assertStringContainsString( 'Shipment 904-D1', $html );
		self::assertStringContainsString( 'Shipment 904-D2', $html );
		self::assertStringContainsString( 'Air Shipping', $html );
		self::assertStringContainsString( 'Sea Shipping', $html );
		self::assertStringNotContainsString( 'Air Shipping, Sea Shipping', $html );
	}

	public function test_tracking_number_without_url_has_no_track_button(): void {
		$this->enable_shipment_records();
		$this->enable_tracking_links();
		$order    = $this->order( 905 );
		$shipment = $this->store_delivery_shipment( 905, 'g-air', '905-D1', 'Air Shipping', 721, 'Widget' );
		( new ShipmentTrackingService( $this->repository ) )->save(
			$shipment->id,
			new ShipmentTrackingInput( 'DHL', 'TRACK-905', '', '', 'Leave at reception' ),
			1
		);

		$html = $this->render_cards( $order );

		self::assertStringContainsString( 'TRACK-905', $html );
		self::assertStringContainsString( 'DHL', $html );
		self::assertStringContainsString( 'Leave at reception', $html );
		self::assertStringNotContainsString( 'Track shipment', $html );
		self::assertStringNotContainsString( 'Keep shrink-wrapped', $html );
		self::assertStringNotContainsString( 'supplier', strtolower( $html ) );
		self::assertStringNotContainsString( 'origin', strtolower( $html ) );
		self::assertStringNotContainsString( 'logistics_profile', $html );
		self::assertStringNotContainsString( 'rate_card', $html );
		self::assertStringNotContainsString( 'g-air', $html );
		self::assertStringNotContainsString( '25.00', $html );
		self::assertStringNotContainsString( 'History', $html );
		self::assertStringNotContainsString( 'created', $html );
	}

	public function test_track_button_requires_valid_url_and_tracking_links_flag(): void {
		$this->enable_shipment_records();
		$order    = $this->order( 906 );
		$shipment = $this->store_delivery_shipment( 906, 'g-air', '906-D1', 'Air Shipping', 731, 'Widget' );
		( new ShipmentTrackingService( $this->repository ) )->save(
			$shipment->id,
			new ShipmentTrackingInput( '', 'TRACK-906', 'https://carrier.example/TRACK-906', '', '' ),
			1
		);

		$without_flag = $this->render_cards( $order );
		self::assertStringContainsString( 'TRACK-906', $without_flag );
		self::assertStringNotContainsString( 'Track shipment', $without_flag );

		$this->enable_tracking_links();
		$with_flag = $this->render_cards( $order );
		self::assertStringContainsString( 'Track shipment', $with_flag );
		self::assertStringContainsString( 'https://carrier.example/TRACK-906', $with_flag );
		self::assertStringContainsString( 'rel="noopener noreferrer"', $with_flag );
		self::assertStringContainsString( 'cetech-de-customer-shipment__track-link', $with_flag );
	}

	public function test_pickup_is_not_a_shipment_card_and_rc4_pickup_remains(): void {
		$this->enable_shipment_records();
		$order = $this->order( 907 );
		$this->store_delivery_shipment( 907, 'g-air', '907-D1', 'Air Shipping', 741, 'Air item' );
		$this->repository->create(
			Shipment::create(
				907,
				'g-pickup',
				fulfilment_choice: 'store_pickup',
				delivery_offer_public_label: 'Counter pickup',
				shipment_number: '907-P1'
			)
		);

		$cards = $this->render_cards( $order );
		self::assertStringContainsString( 'Shipment 907-D1', $cards );
		self::assertStringNotContainsString( '907-P1', $cards );

		$summary_html = $this->render_rc4_summary(
			$order,
			[
				new CustomerOrderDeliveryLineSummary(
					'Pickup item',
					'Counter pickup',
					'In store',
					'Store pickup',
					null,
					'1-2 business days',
					null,
					null,
					null,
					'Main showroom',
					null,
					null,
					801
				),
				new CustomerOrderDeliveryLineSummary(
					'Air item',
					'Air Shipping',
					'In warehouse',
					'Delivery',
					null,
					'3-5 business days',
					null,
					'25.00',
					null,
					null,
					null,
					null,
					741
				),
			]
		);

		self::assertStringContainsString( 'Delivery details', $summary_html );
		self::assertStringContainsString( 'Counter pickup', $summary_html );
		self::assertStringContainsString( 'Ready for pickup', $summary_html );
		self::assertStringNotContainsString( 'Air Shipping', $summary_html );
		self::assertStringNotContainsString( '25.00', $summary_html );
	}

	public function test_eta_updated_estimate_wording_and_mobile_safe_classes(): void {
		$this->enable_shipment_records();
		$order = $this->order( 908 );
		$this->repository->create(
			Shipment::create(
				908,
				'g-air',
				delivery_offer_public_label: 'Air Shipping',
				eta_original: '3-5 business days',
				eta_current: '6-8 business days',
				shipment_number: '908-D1'
			)
		);
		$created = $this->repository->findByOrderAndGroup( 908, 'g-air' );
		self::assertNotNull( $created );
		$this->repository->replaceItems(
			$created->id,
			[ ShipmentItem::create( $created->id, 908, 751, 1, 10, null, 'Widget' ) ]
		);

		$html = $this->render_cards( $order );
		self::assertStringContainsString( '6-8 business days (updated estimate)', $html );
		self::assertStringNotContainsString( '3-5 business days', $html );
		self::assertStringContainsString( 'cetech-de-customer-shipment', $html );
		self::assertStringContainsString( '<article', $html );
		self::assertStringContainsString( '<h3', $html );
	}

	public function test_dto_never_exposes_private_fields(): void {
		$this->enable_shipment_records();
		$order    = $this->order( 909 );
		$shipment = $this->store_delivery_shipment( 909, 'secret-group', '909-D1', 'Air Shipping', 761, 'Widget' );
		$updated  = $this->repository->findById( $shipment->id );
		self::assertNotNull( $updated );

		$cards = $this->query->cards_for_order( $order );
		self::assertCount( 1, $cards );
		$encoded = (string) json_encode( $cards[0] );
		self::assertStringNotContainsString( 'Keep shrink-wrapped', $encoded );
		self::assertStringNotContainsString( 'secret-group', $encoded );
		self::assertStringNotContainsString( 'idempotency', $encoded );
		self::assertStringNotContainsString( 'supplier', $encoded );
		self::assertStringNotContainsString( 'internal_cost', $encoded );
		self::assertStringNotContainsString( 'rate_card', $encoded );
	}

	private function enable_shipment_records(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_shipment_records'] = 1;
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_customer_order_delivery_summary'] = 1;
		$this->query = new CustomerShipmentQuery( new FeatureFlags(), $this->repository );
	}

	private function enable_tracking_links(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_tracking_links'] = 1;
		$this->query = new CustomerShipmentQuery( new FeatureFlags(), $this->repository );
	}

	private function order( int $order_id ): WC_Order {
		return new WC_Order( [ 'id' => $order_id, 'order_number' => (string) $order_id ] );
	}

	private function store_delivery_shipment(
		int $order_id,
		string $group,
		string $number,
		string $label,
		int $order_item_id,
		string $item_name
	): Shipment {
		$shipment = $this->repository->create(
			Shipment::create(
				$order_id,
				$group,
				delivery_offer_public_label: $label,
				currency_code: 'GBP',
				customer_paid_shipping_amount: '25.00',
				eta_original: '3-5 business days',
				supplier_id: 44,
				origin_id: 55,
				logistics_profile_id: 66,
				rate_card_id: 77,
				rate_card_code: 'rc-secret',
				internal_cost: '4.00',
				private_note: 'Keep shrink-wrapped',
				shipment_number: $number
			)
		);
		$this->repository->replaceItems(
			$shipment->id,
			[ ShipmentItem::create( $shipment->id, $order_id, $order_item_id, 1, 10, null, $item_name ) ]
		);

		return $shipment;
	}

	private function render_cards( WC_Order $order ): string {
		$renderer = new CustomerShipmentRenderer(
			new FeatureFlags(),
			new Requirements(),
			$this->query
		);

		ob_start();
		$renderer->render( $order );

		return (string) ob_get_clean();
	}

	/**
	 * @param list<CustomerOrderDeliveryLineSummary> $lines
	 */
	private function render_rc4_summary( WC_Order $order, array $lines ): string {
		$renderer = new CustomerOrderDeliverySummaryRenderer(
			new FeatureFlags(),
			new Requirements(),
			( new ReflectionClass( CustomerOrderDeliverySummaryBuilder::class ) )->newInstanceWithoutConstructor(),
			$this->query
		);
		$method = new ReflectionMethod( $renderer, 'render_summary' );
		if ( PHP_VERSION_ID < 80500 ) {
			$method->setAccessible( true );
		}

		$covered = $this->query->covered_order_item_ids( (int) $order->get_id() );

		ob_start();
		$method->invoke( $renderer, new CustomerOrderDeliverySummary( $lines, null ), $covered );

		return (string) ob_get_clean();
	}
}
