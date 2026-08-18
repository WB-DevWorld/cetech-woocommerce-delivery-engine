<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentAggregateWriteResult;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use PHPUnit\Framework\TestCase;

final class ShipmentAggregateAtomicityTest extends TestCase {

	private FakeWpdb $wpdb;

	private WpdbShipmentRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = ShipmentCreationFixtures::repository();
		$this->wpdb       = $GLOBALS['wpdb'];
	}

	public function test_first_write_creates_complete_aggregate(): void {
		$result = $this->write_standard();

		self::assertSame( ShipmentAggregateWriteResult::CREATED, $result->status );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $result->shipment->status );
		self::assertCount( 1, $this->repository->findItems( $result->shipment->id ) );
		$events = $this->repository->findEvents( $result->shipment->id );
		self::assertCount( 1, $events );
		self::assertSame( ShipmentEventType::Created, $events[0]->event_type->knownType() );
	}

	public function test_second_write_is_already_complete(): void {
		$first  = $this->write_standard();
		$second = $this->write_standard();

		self::assertSame( $first->shipment->id, $second->shipment->id );
		self::assertSame( ShipmentAggregateWriteResult::ALREADY_COMPLETE, $second->status );
		self::assertCount( 1, $this->repository->findByOrderId( 44 ) );
		self::assertCount( 1, $this->repository->findItems( $first->shipment->id ) );
		self::assertCount( 1, $this->repository->findEvents( $first->shipment->id ) );
	}

	public function test_unique_order_group_identity_is_protected(): void {
		$this->write_standard();

		$duplicate = $this->wpdb->insert(
			'wp_delivery_engine_shipments',
			[
				'order_id'          => 44,
				'shipment_number'   => '44-D9',
				'idempotency_key'   => '44|international|delivery|12-other',
				'delivery_group_id' => 'international|delivery|12',
				'status'            => ShipmentStatus::AwaitingFulfilment->value,
			]
		);

		self::assertFalse( $duplicate );
		self::assertStringContainsString( 'Duplicate', $this->wpdb->last_error );
	}

	public function test_item_insert_failure_rolls_back_incomplete_shipment(): void {
		$this->wpdb->fail_next_insert_table = 'wp_delivery_engine_shipment_items';

		try {
			$this->write_standard();
			self::fail( 'Expected item insert failure to abort the aggregate write.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'shipment item', strtolower( $exception->getMessage() ) );
		}

		self::assertSame( [], $this->repository->findByOrderId( 44 ) );
	}

	public function test_event_insert_failure_rolls_back_incomplete_shipment(): void {
		$this->wpdb->fail_next_insert_table = 'wp_delivery_engine_shipment_events';

		try {
			$this->write_standard();
			self::fail( 'Expected event insert failure to abort the aggregate write.' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'shipment event', strtolower( $exception->getMessage() ) );
		}

		self::assertSame( [], $this->repository->findByOrderId( 44 ) );
	}

	public function test_retry_after_incomplete_aggregate_completes_without_duplicates(): void {
		$partial = $this->repository->create(
			Shipment::create( 44, 'international|delivery|12' )
		);

		self::assertCount( 0, $this->repository->findItems( $partial->id ) );
		self::assertCount( 0, $this->repository->findEvents( $partial->id ) );

		$repaired = $this->write_standard();

		self::assertSame( ShipmentAggregateWriteResult::REPAIRED, $repaired->status );
		self::assertSame( $partial->id, $repaired->shipment->id );
		self::assertCount( 1, $this->repository->findItems( $partial->id ) );
		self::assertCount( 1, $this->repository->findEvents( $partial->id ) );

		$again = $this->write_standard();
		self::assertSame( ShipmentAggregateWriteResult::ALREADY_COMPLETE, $again->status );
		self::assertCount( 1, $this->repository->findItems( $partial->id ) );
		self::assertCount( 1, $this->repository->findEvents( $partial->id ) );
	}

	public function test_incomplete_aggregate_is_not_treated_as_success(): void {
		$partial = $this->repository->create(
			Shipment::create( 55, 'sea|delivery|8' )
		);

		$existing = $this->repository->findByOrderAndGroup( 55, 'sea|delivery|8' );
		self::assertNotNull( $existing );
		self::assertCount( 0, $this->repository->findItems( $partial->id ) );

		$write = $this->repository->ensureCompleteAggregate(
			Shipment::create( 55, 'sea|delivery|8' ),
			[
				ShipmentItem::create( 0, 55, 701, 1, 11, null, 'Sea item' ),
			]
		);

		self::assertSame( ShipmentAggregateWriteResult::REPAIRED, $write->status );
		self::assertNotSame( ShipmentAggregateWriteResult::ALREADY_COMPLETE, $write->status );
		self::assertCount( 1, $this->repository->findItems( $partial->id ) );
		self::assertCount( 1, $this->repository->findEvents( $partial->id ) );
	}

	private function write_standard(): ShipmentAggregateWriteResult {
		return $this->repository->ensureCompleteAggregate(
			Shipment::create(
				order_id: 44,
				delivery_group_id: 'international|delivery|12',
				status: ShipmentStatus::AwaitingFulfilment,
				fulfilment_availability: 'international',
				fulfilment_choice: 'delivery',
				delivery_offer_id: 12,
				delivery_offer_public_label: 'International Air',
				currency_code: 'GBP',
				customer_paid_shipping_amount: '25.0000',
				eta_original: '5-7 business days',
				shipment_number: '44-D1'
			),
			[
				ShipmentItem::create( 0, 44, 501, 1, 10, null, 'Widget' ),
			]
		);
	}
}
