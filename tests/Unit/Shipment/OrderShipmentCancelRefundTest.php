<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\OrderShipmentOperationsSubscriber;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentRefundInspector;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use PHPUnit\Framework\TestCase;
use WC_Order;

final class OrderShipmentCancelRefundTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private ShipmentOperationsIssueStore $issues;

	private OrderShipmentOperationsSubscriber $subscriber;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']   = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_wc_orders'] = [];

		$this->repository = ShipmentCreationFixtures::repository();
		$this->issues     = new ShipmentOperationsIssueStore();
		$status           = new ShipmentStatusService( $this->repository, $this->issues );
		$this->subscriber = new OrderShipmentOperationsSubscriber(
			new FeatureFlags(),
			$this->repository,
			$status,
			new ShipmentRefundInspector(),
			$this->issues
		);
	}

	/**
	 * @dataProvider pre_dispatch_cancelled_statuses
	 */
	public function test_cancelled_order_auto_cancels_pre_dispatch_shipment( ShipmentStatus $status ): void {
		$order    = $this->order( 4401, $status->value );
		$shipment = $this->store_shipment( 4401, $status, 501 );

		$this->subscriber->handle_order_cancelled( $order->get_id(), $order );

		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertSame( ShipmentStatus::Cancelled, $saved->status );
		self::assertSame( '25.00', $saved->customer_paid_shipping_amount );
		$events = $this->status_events( $shipment->id );
		self::assertCount( 1, $events );
		self::assertSame( ShipmentEventSource::WooCommerce, $events[0]->source );
		self::assertSame( ShipmentStatusService::REASON_ORDER_CANCELLED, $events[0]->internal_note );
		self::assertSame( [], $this->issues->all() );
	}

	/**
	 * @return list<array{0: ShipmentStatus}>
	 */
	public static function pre_dispatch_cancelled_statuses(): array {
		return [
			[ ShipmentStatus::AwaitingFulfilment ],
			[ ShipmentStatus::Processing ],
		];
	}

	/**
	 * @dataProvider progressed_cancelled_statuses
	 */
	public function test_cancelled_order_does_not_rewrite_progressed_shipment( ShipmentStatus $status ): void {
		$order    = $this->order( 4410, $status->value );
		$shipment = $this->store_shipment( 4410, $status, 501 );

		$this->subscriber->handle_order_cancelled( $order->get_id(), $order );

		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertSame( $status, $saved->status );
		self::assertSame( [], $this->status_events( $shipment->id ) );
		self::assertArrayHasKey( $shipment->id, $this->issues->all() );
		self::assertContains(
			ShipmentOperationsIssueStore::CODE_ORDER_CANCELLED_AFTER_PROGRESS,
			$this->issues->all()[ $shipment->id ]['codes']
		);
	}

	/**
	 * @return list<array{0: ShipmentStatus}>
	 */
	public static function progressed_cancelled_statuses(): array {
		return [
			[ ShipmentStatus::Dispatched ],
			[ ShipmentStatus::InTransit ],
			[ ShipmentStatus::Delayed ],
			[ ShipmentStatus::Delivered ],
		];
	}

	public function test_full_refund_before_dispatch_cancels_only_that_shipment(): void {
		$order = $this->order( 4420, 'processing' );
		$one   = $this->store_shipment( 4420, ShipmentStatus::AwaitingFulfilment, 501, 'g-a', '4420-D1' );
		$two   = $this->store_shipment( 4420, ShipmentStatus::AwaitingFulfilment, 502, 'g-b', '4420-D2' );
		$order->set_refunded_qty( 501, -1 );

		$this->subscriber->handle_order_refunded( $order->get_id() );

		self::assertSame( ShipmentStatus::Cancelled, $this->repository->findById( $one->id )?->status );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $this->repository->findById( $two->id )?->status );
		self::assertCount( 1, $this->status_events( $one->id ) );
		self::assertSame( [], $this->status_events( $two->id ) );
		self::assertSame( '25.00', $this->repository->findById( $one->id )?->customer_paid_shipping_amount );
		self::assertNotEmpty( $this->repository->findEvents( $one->id ) );
	}

	public function test_full_refund_after_dispatch_keeps_status_and_needs_review(): void {
		$order    = $this->order( 4421, 'processing' );
		$shipment = $this->store_shipment( 4421, ShipmentStatus::InTransit, 501 );
		$order->set_refunded_qty( 501, -1 );

		$this->subscriber->handle_order_refunded( $order->get_id() );

		self::assertSame( ShipmentStatus::InTransit, $this->repository->findById( $shipment->id )?->status );
		self::assertContains(
			ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW,
			$this->issues->all()[ $shipment->id ]['codes']
		);
	}

	public function test_partial_refund_keeps_status_and_needs_review(): void {
		$order    = $this->order( 4422, 'processing' );
		$shipment = $this->store_shipment( 4422, ShipmentStatus::Processing, 501, 'g-a', '4422-D1', 2 );
		$order->set_refunded_qty( 501, -1 );

		$this->subscriber->handle_order_refunded( $order->get_id() );

		self::assertSame( ShipmentStatus::Processing, $this->repository->findById( $shipment->id )?->status );
		self::assertContains(
			ShipmentOperationsIssueStore::CODE_REFUND_REQUIRES_REVIEW,
			$this->issues->all()[ $shipment->id ]['codes']
		);
	}

	public function test_full_quantity_refund_on_one_awaiting_shipment_cancels_it(): void {
		$order    = $this->order( 4423, 'processing' );
		$shipment = $this->store_shipment( 4423, ShipmentStatus::Processing, 501, 'g-a', '4423-D1', 2 );
		$order->set_refunded_qty( 501, -2 );

		$this->subscriber->handle_order_refunded( $order->get_id() );

		self::assertSame( ShipmentStatus::Cancelled, $this->repository->findById( $shipment->id )?->status );
		self::assertSame( ShipmentStatusService::REASON_SHIPMENT_QUANTITIES_REFUNDED, $this->status_events( $shipment->id )[0]->internal_note );
	}

	public function test_repeated_hooks_are_idempotent(): void {
		$order    = $this->order( 4424, 'cancelled' );
		$shipment = $this->store_shipment( 4424, ShipmentStatus::AwaitingFulfilment, 501 );

		$this->subscriber->handle_order_cancelled( $order->get_id(), $order );
		$this->subscriber->handle_order_cancelled( $order->get_id(), $order );
		$this->subscriber->handle_order_refunded( $order->get_id() );

		self::assertCount( 1, $this->status_events( $shipment->id ) );
		self::assertSame( ShipmentStatus::Cancelled, $this->repository->findById( $shipment->id )?->status );
	}

	public function test_delayed_shipment_appears_on_needs_attention_and_clears_after_recovery(): void {
		$delayed = $this->store_shipment( 4425, ShipmentStatus::Delayed, 501 );
		$query   = new ShipmentOperationsIssueQuery( new FeatureFlags(), $this->repository, $this->issues );
		$list    = $query->list();

		self::assertNotEmpty( $list );
		self::assertSame( $delayed->id, $list[0]['shipment_id'] );
		self::assertContains( 'delayed', $list[0]['codes'] );

		( new ShipmentStatusService( $this->repository, $this->issues ) )->change(
			$delayed->id,
			ShipmentStatus::InTransit,
			\CetechDeliveryEngine\Application\Shipment\ShipmentStatusChangeRequest::staff_normal( 'Moving again', 9 )
		);

		$after = $query->list();
		self::assertSame( [], $after );
	}

	public function test_flag_off_does_not_mutate_shipments(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_shipment_records'] = 0;
		$subscriber = new OrderShipmentOperationsSubscriber(
			new FeatureFlags(),
			$this->repository,
			new ShipmentStatusService( $this->repository, $this->issues ),
			new ShipmentRefundInspector(),
			$this->issues
		);
		$order    = $this->order( 4426, 'cancelled' );
		$shipment = $this->store_shipment( 4426, ShipmentStatus::AwaitingFulfilment, 501 );

		$subscriber->handle_order_cancelled( $order->get_id(), $order );

		self::assertSame( ShipmentStatus::AwaitingFulfilment, $this->repository->findById( $shipment->id )?->status );
	}

	private function order( int $order_id, string $unused_status ): WC_Order {
		unset( $unused_status );

		$order = new WC_Order(
			[
				'id'           => $order_id,
				'order_number' => (string) $order_id,
				'paid'         => true,
				'status'       => 'cancelled',
			]
		);
		$order->save();

		return $order;
	}

	private function store_shipment(
		int $order_id,
		ShipmentStatus $status,
		int $order_item_id,
		string $group = 'g-a',
		string $number = '',
		int $quantity = 1
	): Shipment {
		$created = $this->repository->create(
			Shipment::create(
				$order_id,
				$group,
				$status,
				delivery_offer_public_label: 'Air Shipping',
				currency_code: 'GBP',
				customer_paid_shipping_amount: '25.00',
				eta_original: '5-7 business days',
				shipment_number: '' !== $number ? $number : $order_id . '-D1'
			)
		);
		$this->repository->replaceItems(
			$created->id,
			[ ShipmentItem::create( $created->id, $order_id, $order_item_id, $quantity, 10, null, 'Widget' ) ]
		);

		return $created;
	}

	/**
	 * @return list<ShipmentEvent>
	 */
	private function status_events( int $shipment_id ): array {
		return array_values(
			array_filter(
				$this->repository->findEvents( $shipment_id ),
				static fn ( ShipmentEvent $event ): bool => $event->event_type->is( ShipmentEventType::StatusChanged )
			)
		);
	}
}
