<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use PHPUnit\Framework\TestCase;

final class ShipmentRepositoryTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

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

		$GLOBALS['wpdb']     = $this->wpdb;
		$this->repository    = new WpdbShipmentRepository();
	}

	public function test_table_names_follow_prefix_convention(): void {
		self::assertSame( 'wp_delivery_engine_shipments', TableNames::for( ShipmentSchema::SHIPMENTS_SUFFIX ) );
		self::assertSame( 'wp_delivery_engine_shipment_items', TableNames::for( ShipmentSchema::ITEMS_SUFFIX ) );
		self::assertSame( 'wp_delivery_engine_shipment_events', TableNames::for( ShipmentSchema::EVENTS_SUFFIX ) );
	}

	public function test_create_find_and_update_round_trip(): void {
		$created = $this->repository->create(
			Shipment::create(
				order_id: 1001,
				delivery_group_id: 'international|delivery|12',
				status: ShipmentStatus::AwaitingFulfilment,
				fulfilment_availability: 'international',
				fulfilment_choice: 'delivery',
				delivery_offer_id: 12,
				delivery_offer_public_label: 'International Air',
				route: 'air',
				service_level: 'express',
				carrier_visibility: 'assigned_by_store',
				logistics_profile_id: 5,
				supplier_id: 7,
				origin_id: 3,
				currency_code: 'GBP',
				customer_paid_shipping_amount: '25.000000',
				rate_card_id: 9,
				rate_card_code: 'AIR-INT',
				internal_cost: '8.500000',
				eta_original: '5-7 business days',
				public_note: 'Leave at reception',
				private_note: 'Use pallet wrap'
			)
		);

		self::assertGreaterThan( 0, $created->id );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $created->status );
		self::assertSame( '25.000000', $created->customer_paid_shipping_amount );
		self::assertSame( 'Leave at reception', $created->public_note );
		self::assertSame( 'Use pallet wrap', $created->private_note );
		self::assertSame( '5-7 business days', $created->eta_original );
		self::assertSame( '5-7 business days', $created->eta_current );

		$found = $this->repository->findById( $created->id );
		self::assertNotNull( $found );
		self::assertSame( $created->idempotency_key, $found->idempotency_key );

		$by_group = $this->repository->findByOrderAndGroup( 1001, 'international|delivery|12' );
		self::assertNotNull( $by_group );
		self::assertSame( $created->id, $by_group->id );

		$updated = $this->repository->update(
			$created->withStatus( ShipmentStatus::Dispatched )->withTracking( '1Z999', 'https://example.test/1Z999', 'Example Carrier' )
		);

		self::assertSame( ShipmentStatus::Dispatched, $updated->status );
		self::assertSame( 'dispatched', $updated->status->value );
		self::assertSame( '1Z999', $updated->tracking_number );
		self::assertSame( 'https://example.test/1Z999', $updated->tracking_url );
	}

	public function test_create_is_idempotent_for_order_and_group(): void {
		$first = $this->repository->create( Shipment::create( 44, 'air|delivery|2' ) );
		$again = $this->repository->create(
			Shipment::create( 44, 'air|delivery|2', ShipmentStatus::Processing )
		);

		self::assertSame( $first->id, $again->id );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $again->status );
		self::assertCount( 1, $this->repository->findByOrderId( 44 ) );
	}

	public function test_database_unique_constraint_rejects_duplicate_order_group(): void {
		$this->repository->create( Shipment::create( 55, 'sea|delivery|8' ) );

		$duplicate = $this->wpdb->insert(
			'wp_delivery_engine_shipments',
			[
				'order_id'          => 55,
				'shipment_number'   => 'DE-55-dup',
				'idempotency_key'   => '55|sea|delivery|8-other',
				'delivery_group_id' => 'sea|delivery|8',
				'status'            => ShipmentStatus::Processing->value,
			]
		);

		self::assertFalse( $duplicate );
		self::assertStringContainsString( 'Duplicate', $this->wpdb->last_error );

		$recovered = $this->repository->create( Shipment::create( 55, 'sea|delivery|8' ) );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $recovered->status );
	}

	public function test_items_and_events_persist_with_machine_codes(): void {
		$shipment = $this->repository->create( Shipment::create( 70, 'local|delivery|1' ) );

		$this->repository->replaceItems(
			$shipment->id,
			[
				ShipmentItem::create( $shipment->id, 70, 501, 2, 88, null, 'Widget' ),
				ShipmentItem::create( $shipment->id, 70, 502, 1, 89, 90, 'Gadget' ),
			]
		);

		$items = $this->repository->findItems( $shipment->id );
		self::assertCount( 2, $items );
		self::assertSame( 501, $items[0]->order_item_id );
		self::assertSame( 'Widget', $items[0]->product_name_snapshot );

		$this->repository->replaceItems(
			$shipment->id,
			[
				ShipmentItem::create( $shipment->id, 70, 501, 3, 88, null, 'Widget' ),
			]
		);
		self::assertCount( 1, $this->repository->findItems( $shipment->id ) );

		$created_event = $this->repository->appendEvent(
			ShipmentEvent::create(
				$shipment->id,
				ShipmentEventType::Created,
				ShipmentEventSource::System,
				null,
				ShipmentStatus::AwaitingFulfilment,
				null,
				null,
				null,
				'2026-08-18 09:00:00'
			)
		);
		$changed = $this->repository->appendEvent(
			ShipmentEvent::create(
				$shipment->id,
				ShipmentEventType::StatusChanged,
				ShipmentEventSource::Staff,
				ShipmentStatus::AwaitingFulfilment,
				ShipmentStatus::Processing,
				null,
				'Warehouse started pick',
				7,
				'2026-08-18 10:00:00'
			)
		);

		$events = $this->repository->findEvents( $shipment->id );
		self::assertCount( 2, $events );
		self::assertSame( ShipmentEventType::Created, $created_event->event_type->knownType() );
		self::assertSame( 'created', $created_event->event_type->value );
		self::assertSame( 'staff', $changed->source->value );
		self::assertSame( ShipmentStatus::Processing, $changed->to_status );
		self::assertNotSame( 'Processing', $changed->to_status?->value );
		self::assertSame( 'Warehouse started pick', $changed->internal_note );
		self::assertNull( $changed->public_note );
		self::assertSame(
			[ 'created', 'status_changed' ],
			array_map( static fn ( ShipmentEvent $event ): string => $event->event_type->value, $events )
		);
	}

	public function test_list_paginates_and_filters_by_status_machine_code(): void {
		$this->repository->create( Shipment::create( 1, 'g-a' ) );
		$second = $this->repository->create( Shipment::create( 2, 'g-b' ) );
		$this->repository->create( Shipment::create( 3, 'g-c' ) );
		$this->repository->update( $second->withStatus( ShipmentStatus::Dispatched ) );

		$page_one = $this->repository->list( [], 1, 2 );
		self::assertSame( 3, $page_one->total );
		self::assertCount( 2, $page_one->items );
		self::assertSame( 1, $page_one->page );
		self::assertSame( 2, $page_one->per_page );

		$page_two = $this->repository->list( [], 2, 2 );
		self::assertCount( 1, $page_two->items );

		$dispatched = $this->repository->list( [ 'status' => ShipmentStatus::Dispatched->value ], 1, 20 );
		self::assertSame( 1, $dispatched->total );
		self::assertSame( 2, $dispatched->items[0]->order_id );

		$translated = $this->repository->list( [ 'status' => 'Dispatched' ], 1, 20 );
		self::assertSame( 0, $translated->total );

		$by_order = $this->repository->list( [ 'order_id' => 3 ], 1, 20 );
		self::assertSame( 1, $by_order->total );
		self::assertSame( 'g-c', $by_order->items[0]->delivery_group_id );
	}

	public function test_list_search_is_prefix_and_count_items_batches(): void {
		$one = $this->repository->create(
			Shipment::create( order_id: 44, delivery_group_id: 'g-1', shipment_number: '44-D1' )
		);
		$two = $this->repository->create(
			Shipment::create( order_id: 55, delivery_group_id: 'g-2', shipment_number: '55-D1' )
		);
		$this->repository->update( $two->withTracking( 'TRK-555', null, null ) );
		$this->repository->replaceItems(
			$one->id,
			[
				ShipmentItem::create( $one->id, 44, 1, 1, 10, null, 'A' ),
				ShipmentItem::create( $one->id, 44, 2, 1, 11, null, 'B' ),
			]
		);

		$by_number = $this->repository->list( [ 'search' => '55-D' ], 1, 20 );
		self::assertSame( 1, $by_number->total );
		self::assertSame( '55-D1', $by_number->items[0]->shipment_number );

		$by_tracking = $this->repository->list( [ 'search' => 'TRK-' ], 1, 20 );
		self::assertSame( 1, $by_tracking->total );

		$counts = $this->repository->countItemsByShipmentIds( [ $one->id, $two->id ] );
		self::assertSame( 2, $counts[ $one->id ] );
		self::assertArrayNotHasKey( $two->id, $counts );
	}

	public function test_corrupt_translated_status_is_rejected_on_hydrate(): void {
		$this->wpdb->insert(
			'wp_delivery_engine_shipments',
			[
				'order_id'          => 9,
				'shipment_number'   => 'DE-9-x',
				'idempotency_key'   => '9|bad',
				'delivery_group_id' => 'bad',
				'status'            => 'Awaiting fulfilment',
			]
		);

		$this->expectException( \RuntimeException::class );
		$this->repository->findByOrderAndGroup( 9, 'bad' );
	}
}
