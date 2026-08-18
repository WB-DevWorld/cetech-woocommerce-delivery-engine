<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\PaidOrderShipmentSubscriber;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationOutcome;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\InMemoryAuditLogRepository;
use PHPUnit\Framework\TestCase;

final class ShipmentServiceCreationTest extends TestCase {

	private FeatureFlags $flags;

	private ShipmentRepositoryInterface $shipments;

	private ShipmentCreationFailureStore $failures;

	private InMemoryAuditLogRepository $audit;

	private ShipmentService $service;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']   = [];
		$GLOBALS['cetech_de_test_wc_orders'] = [];

		$this->flags     = new FeatureFlags();
		$this->shipments = ShipmentCreationFixtures::repository();
		$this->failures  = new ShipmentCreationFailureStore();
		$this->audit     = new InMemoryAuditLogRepository();
		$this->service   = new ShipmentService(
			$this->flags,
			new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() ),
			new HistoricalShipmentPlanner(),
			$this->shipments,
			$this->failures,
			$this->audit,
			new Logger()
		);
	}

	public function test_feature_flag_off_is_a_noop(): void {
		$order = $this->paid_delivery_order( 2001 );

		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::FeatureDisabled, $result->outcome );
		self::assertSame( [], $this->shipments->findByOrderId( 2001 ) );
		self::assertFalse( $this->failures->is_failed( $order ) );
		self::assertSame( [], $this->failures->failed_order_ids() );
	}

	public function test_unpaid_order_is_a_noop(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order = $this->paid_delivery_order( 2002, paid: false, status: 'pending' );

		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::NotPaid, $result->outcome );
		self::assertSame( [], $this->shipments->findByOrderId( 2002 ) );
	}

	public function test_non_delivery_engine_order_is_a_noop(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order = ShipmentCreationFixtures::paid_order( 2003, [], [], null, true, 'processing' );

		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::NotDeliveryEngineOrder, $result->outcome );
		self::assertSame( [], $this->shipments->findByOrderId( 2003 ) );
		self::assertFalse( $this->failures->is_failed( $order ) );
	}

	public function test_pickup_only_paid_order_is_success_with_zero_shipments(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$pickup = 'in_store|store_pickup|pickup';
		$line   = ShipmentCreationFixtures::line( $pickup, 21, 602, 'Store Pickup', null, '0.00' );
		$order  = ShipmentCreationFixtures::paid_order(
			2004,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $pickup, '0.00', 1, true ) ], 'GBP', 4, '0.00' )
		);

		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::ZeroShipmentsPickupOnly, $result->outcome );
		self::assertTrue( $result->is_success() );
		self::assertSame( [], $this->shipments->findByOrderId( 2004 ) );
		self::assertFalse( $this->failures->is_failed( $order ) );
		self::assertSame( 'succeeded', $order->get_meta( ShipmentCreationFailureStore::STATE_META, true ) );
	}

	public function test_paid_delivery_order_creates_shipment_items_and_initial_event(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order  = $this->paid_delivery_order( 2005 );
		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::Created, $result->outcome );
		self::assertCount( 1, $result->shipments );
		$shipment = $result->shipments[0];
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $shipment->status );
		self::assertSame( 'awaiting_fulfilment', $shipment->status->value );
		self::assertNotSame( 'Awaiting fulfilment', $shipment->status->value );
		self::assertSame( 'international|delivery|12', $shipment->delivery_group_id );
		self::assertSame( 'International Air', $shipment->delivery_offer_public_label );
		self::assertSame( '5-7 business days', $shipment->eta_original );
		self::assertSame( '5-7 business days', $shipment->eta_current );
		self::assertSame( '25.0000', $shipment->customer_paid_shipping_amount );
		self::assertNull( $shipment->supplier_id );
		self::assertNull( $shipment->origin_id );
		self::assertNull( $shipment->logistics_profile_id );
		self::assertNull( $shipment->route );
		self::assertNull( $shipment->service_level );
		self::assertNull( $shipment->public_carrier_name );

		$items = $this->shipments->findItems( $shipment->id );
		self::assertCount( 1, $items );
		self::assertSame( 501, $items[0]->order_item_id );

		$events = $this->shipments->findEvents( $shipment->id );
		self::assertCount( 1, $events );
		self::assertSame( ShipmentEventType::Created, $events[0]->event_type->knownType() );
		self::assertSame( 'created', $events[0]->event_type->value );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $events[0]->to_status );
	}

	public function test_second_identical_invocation_creates_none(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order = $this->paid_delivery_order( 2006 );
		$first = $this->service->create_for_paid_order( $order );
		$again = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::Created, $first->outcome );
		self::assertSame( ShipmentCreationOutcome::AlreadyExistsComplete, $again->outcome );
		self::assertCount( 1, $this->shipments->findByOrderId( 2006 ) );
		self::assertCount( 1, $this->shipments->findEvents( $first->shipments[0]->id ) );
		self::assertCount( 1, $this->shipments->findItems( $first->shipments[0]->id ) );
	}

	public function test_cancelled_order_does_not_newly_create(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order = $this->paid_delivery_order( 2007, paid: true, status: 'cancelled' );

		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::Ineligible, $result->outcome );
		self::assertSame( [], $this->shipments->findByOrderId( 2007 ) );
	}

	public function test_malformed_snapshot_records_persistent_failure(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order = ShipmentCreationFixtures::paid_order(
			2008,
			[],
			[],
			null,
			true,
			'processing'
		);
		$order->update_meta_data( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, '{not-json' );
		$order->update_meta_data( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, '1' );
		$order->save();

		$result = $this->service->create_for_paid_order( $order );

		self::assertSame( ShipmentCreationOutcome::InvalidSnapshot, $result->outcome );
		self::assertSame( ShipmentCreationErrorCode::MalformedGroupSnapshot, $result->error_code );
		self::assertTrue( $this->failures->is_failed( $order ) );
		self::assertSame( [ 2008 ], $this->failures->failed_order_ids() );
		self::assertSame( 'shipment_creation_failed', $this->audit->entries[0]['action'] ?? null );
	}

	public function test_payment_and_status_hooks_are_idempotent(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$order      = $this->paid_delivery_order( 2009 );
		$subscriber = new PaidOrderShipmentSubscriber( $this->service );

		$subscriber->handle_payment_complete( 2009 );
		$subscriber->handle_payment_complete( 2009 );
		$subscriber->handle_paid_status( 2009, $order );

		self::assertCount( 1, $this->shipments->findByOrderId( 2009 ) );
		$shipment = $this->shipments->findByOrderId( 2009 )[0];
		self::assertCount( 1, $this->shipments->findEvents( $shipment->id ) );
	}

	public function test_processing_and_completed_fallbacks_require_paid(): void {
		$this->flags->set( 'enable_shipment_records', true );
		$unpaid      = $this->paid_delivery_order( 2010, paid: false, status: 'processing' );
		$subscriber  = new PaidOrderShipmentSubscriber( $this->service );
		$subscriber->handle_paid_status( 2010, $unpaid );
		self::assertSame( [], $this->shipments->findByOrderId( 2010 ) );

		$completed = $this->paid_delivery_order( 2011, paid: true, status: 'completed' );
		$subscriber->handle_paid_status( 2011, $completed );
		self::assertCount( 1, $this->shipments->findByOrderId( 2011 ) );
	}

	private function paid_delivery_order( int $order_id, bool $paid = true, string $status = 'processing' ): \WC_Order {
		$group_id = 'international|delivery|12';
		$line     = ShipmentCreationFixtures::line( $group_id );

		return ShipmentCreationFixtures::paid_order(
			$order_id,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[ ShipmentCreationFixtures::wc_shipping_line( $group_id ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id ) ] ),
			$paid,
			$status
		);
	}
}
