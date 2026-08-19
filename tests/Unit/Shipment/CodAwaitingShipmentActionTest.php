<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionCountQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentEvaluator;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\PaidOrderShipmentSubscriber;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationOutcome;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\InMemoryAuditLogRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CodAwaitingShipmentActionTest extends TestCase {

	private FeatureFlags $flags;

	private ShipmentRepositoryInterface $shipments;

	private CodAwaitingShipmentStore $store;

	private CodAwaitingShipmentEvaluator $evaluator;

	private CodAwaitingShipmentQuery $query;

	private ShipmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']   = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_wc_orders'] = [];
		$GLOBALS['cetech_de_test_caps']      = [
			'manage_shipments'              => true,
			'manage_product_delivery_rules' => false,
		];

		$this->flags     = new FeatureFlags();
		$this->shipments = ShipmentCreationFixtures::repository();
		$this->store     = new CodAwaitingShipmentStore();
		$factory         = new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() );
		$planner         = new HistoricalShipmentPlanner();
		$this->evaluator = new CodAwaitingShipmentEvaluator(
			$this->flags,
			$factory,
			$planner,
			$this->shipments,
			$this->store
		);
		$this->query   = new CodAwaitingShipmentQuery( $this->store, $this->evaluator );
		$this->service = new ShipmentService(
			$this->flags,
			$factory,
			$planner,
			$this->shipments,
			new ShipmentCreationFailureStore(),
			new InMemoryAuditLogRepository(),
			new Logger(),
			$this->evaluator
		);
		$this->flags->set( 'enable_shipment_records', true );
	}

	public function test_unpaid_cod_delivery_order_is_action_required_not_an_error(): void {
		$order = $this->cod_delivery_order( 4101 );

		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::NotPaid, $result->outcome );
		self::assertSame( [], $this->shipments->findByOrderId( 4101 ) );
		self::assertTrue( $this->store->is_awaiting( $order ) );

		$items = $this->query->list();
		self::assertCount( 1, $items );
		self::assertSame( 4101, $items[0]['order_id'] );
		self::assertSame( '4101', $items[0]['order_number'] );
		self::assertSame( 'Cash on Delivery', $items[0]['payment_method_label'] );
		self::assertSame( 'Cash on Delivery order awaiting shipment creation', $items[0]['title'] );
		self::assertSame( 'Shipment has not been created yet.', $items[0]['detail'] );
		self::assertStringContainsString( 'post.php?post=4101', $items[0]['url'] );
		self::assertStringNotContainsString( 'failure', strtolower( $items[0]['title'] ) );
		self::assertStringNotContainsString( 'broken', strtolower( $items[0]['title'] ) );
		self::assertSame( 1, $this->attention_count() );
	}

	public function test_pickup_only_cod_is_not_action_required(): void {
		$pickup = 'in_store|store_pickup|pickup';
		$line   = ShipmentCreationFixtures::line( $pickup, 21, 602, 'Store Pickup', null, '0.00' );
		$order  = ShipmentCreationFixtures::paid_order(
			4102,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $pickup, '0.00', 1, true ) ], 'GBP', 4, '0.00' ),
			true,
			'processing',
			null,
			'cod'
		);

		( new PaidOrderShipmentSubscriber( $this->service ) )->handle_paid_status( 4102, $order );
		$this->evaluator->sync( $order );

		self::assertFalse( $this->store->is_awaiting( $order ) );
		self::assertSame( [], $this->query->list() );
		self::assertSame( 0, $this->attention_count() );
	}

	public function test_cancelled_cod_is_not_action_required(): void {
		$order = $this->cod_delivery_order( 4103 );
		$this->service->create_for_paid_order( $order );
		self::assertCount( 1, $this->query->list() );

		$order->set_status( 'cancelled' );
		$order->save();
		$this->evaluator->sync( $order );

		self::assertSame( [], $this->query->list() );
		self::assertFalse( $this->store->is_awaiting( $order ) );
	}

	public function test_creating_the_shipment_clears_the_cod_task(): void {
		$order = $this->cod_delivery_order( 4104 );
		$this->service->create_for_paid_order( $order );
		self::assertSame( 1, $this->attention_count() );

		$created = $this->service->create_from_historical_order_for_staff( $order, 3 );

		self::assertSame( ShipmentCreationOutcome::Created, $created->outcome );
		self::assertSame( [], $this->query->list() );
		self::assertSame( 0, $this->attention_count() );
	}

	public function test_opening_needs_attention_does_not_clear_cod_task(): void {
		$order = $this->cod_delivery_order( 4105 );
		$this->service->create_for_paid_order( $order );

		self::assertCount( 1, $this->query->list() );
		self::assertCount( 1, $this->query->list() );
		self::assertTrue( $this->store->is_awaiting( $order ) );
	}

	public function test_non_cod_unpaid_order_is_not_indexed(): void {
		$plain = $this->paid_style_order( 4107, 'bacs' );

		$this->service->create_for_paid_order( $plain );

		self::assertSame( [], $this->query->list() );
	}

	private function attention_count(): int {
		$catalog = ( new ReflectionClass( NeedsAttentionQuery::class ) )->newInstanceWithoutConstructor();

		return ( new NeedsAttentionCountQuery(
			$catalog,
			new ShipmentCreationIssueQuery( new ShipmentCreationFailureStore() ),
			new ShipmentOperationsIssueQuery(
				$this->flags,
				$this->shipments,
				new ShipmentOperationsIssueStore()
			),
			$this->flags,
			$this->query
		) )->unresolved_count_for_current_user();
	}

	private function cod_delivery_order( int $order_id ): \WC_Order {
		return $this->paid_style_order( $order_id, 'cod' );
	}

	private function paid_style_order( int $order_id, string $payment_method ): \WC_Order {
		$group_id = 'international|delivery|12';
		$line     = ShipmentCreationFixtures::line( $group_id );

		return ShipmentCreationFixtures::paid_order(
			$order_id,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[ ShipmentCreationFixtures::wc_shipping_line( $group_id ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id ) ] ),
			true,
			'processing',
			null,
			$payment_method,
			[
				'billing_first_name' => 'Ada',
				'billing_last_name'  => 'Lovelace',
				'shipping_address'   => '1 Harbour Street, Kingston',
			]
		);
	}
}
