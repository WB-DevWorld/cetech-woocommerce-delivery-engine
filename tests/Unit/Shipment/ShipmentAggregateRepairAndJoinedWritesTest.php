<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentEvaluator;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\ManualShipmentCreationPreviewFactory;
use CetechDeliveryEngine\Application\Shipment\OrderShipmentOperationsSubscriber;
use CetechDeliveryEngine\Application\Shipment\ShipmentEtaService;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentRefundInspector;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusChangeRequest;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingInput;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingService;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentAggregateWriteResult;
use CetechDeliveryEngine\Domain\Shipment\ShipmentConcurrentEditException;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use PHPUnit\Framework\TestCase;
use WC_Order;

/**
 * COR-031: an existing parent-only or wrong-item aggregate is not complete; repair and the
 * Created event stay idempotent under a row fence, faults and retry, from the frozen item set.
 * COR-032: status, ETA and tracking write their own columns and their event together, guarded
 * by what the editor loaded; a terminal retry repairs a partial write and keeps the original ETA.
 */
final class ShipmentAggregateRepairAndJoinedWritesTest extends TestCase {

	private const GROUP = 'international|delivery|12';

	private FakeWpdb $wpdb;

	private WpdbShipmentRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']   = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_wc_orders'] = [];
		$this->repository = ShipmentCreationFixtures::repository();
		$this->wpdb       = $GLOBALS['wpdb'];
	}

	// ------------------------------------------------------------------ COR-031

	public function test_repair_runs_under_a_row_fence_and_appends_created_once(): void {
		$partial = $this->repository->create( Shipment::create( 44, self::GROUP ) );
		$this->repository->replaceItems( $partial->id, [ $this->widget( 44 ) ] );
		self::assertCount( 0, $this->repository->findEvents( $partial->id ), 'items without Created is not complete' );
		self::assertFalse( $this->repository->aggregateIsComplete( $partial->id, [ $this->widget( 44 ) ] ) );

		$this->wpdb->sql_log = [];
		$repaired = $this->write( 44 );

		self::assertSame( ShipmentAggregateWriteResult::REPAIRED, $repaired->status );
		self::assertNotEmpty( array_filter( $this->wpdb->sql_log, static fn ( string $sql ): bool => str_contains( $sql, 'FOR UPDATE' ) ), 'the parent row is locked for the repair' );
		self::assertCount( 1, $this->created_events( $partial->id ) );

		$again = $this->write( 44 );
		self::assertSame( ShipmentAggregateWriteResult::ALREADY_COMPLETE, $again->status );
		self::assertCount( 1, $this->created_events( $partial->id ), 'a second independent writer appends no second Created' );
	}

	public function test_wrong_quantity_or_extra_item_is_not_complete_and_is_repaired_from_the_frozen_set(): void {
		$existing = $this->write( 44 )->shipment;
		$this->repository->replaceItems(
			$existing->id,
			[
				ShipmentItem::create( 0, 44, 501, 3, 10, null, 'Widget' ),
				ShipmentItem::create( 0, 44, 777, 1, 10, null, 'Stray' ),
			]
		);

		self::assertFalse( $this->repository->aggregateIsComplete( $existing->id, [ $this->widget( 44 ) ] ), 'wrong quantity and an extra item are not a complete aggregate' );

		$repaired = $this->write( 44 );

		self::assertSame( ShipmentAggregateWriteResult::REPAIRED, $repaired->status );
		$items = $this->repository->findItems( $existing->id );
		self::assertCount( 1, $items );
		self::assertSame( 501, $items[0]->order_item_id );
		self::assertSame( 1, $items[0]->quantity, 'the frozen required quantity wins over the stored one' );
		self::assertTrue( $this->repository->aggregateIsComplete( $existing->id, [ $this->widget( 44 ) ] ) );
		self::assertCount( 1, $this->created_events( $existing->id ) );
	}

	public function test_created_insert_fault_during_repair_rolls_back_and_the_aggregate_stays_repairable(): void {
		$partial = $this->repository->create( Shipment::create( 44, self::GROUP ) );
		$this->wpdb->fail_next_insert_table = 'wp_delivery_engine_shipment_events';

		try {
			$this->write( 44 );
			self::fail( 'the Created insert fault must abort the repair' );
		} catch ( \RuntimeException $exception ) {
			self::assertStringContainsString( 'shipment event', strtolower( $exception->getMessage() ) );
		}

		self::assertCount( 0, $this->repository->findItems( $partial->id ), 'the partial item write rolled back with the failed event' );
		self::assertFalse( $this->repository->aggregateIsComplete( $partial->id, [ $this->widget( 44 ) ] ) );

		$retry = $this->write( 44 );
		self::assertSame( ShipmentAggregateWriteResult::REPAIRED, $retry->status );
		self::assertSame( $partial->id, $retry->shipment->id );
		self::assertCount( 1, $this->created_events( $partial->id ) );
	}

	public function test_parent_only_aggregate_keeps_the_cod_task_and_manual_preview_visible(): void {
		$order = $this->cod_delivery_order( 4301 );
		$this->repository->create( Shipment::create( 4301, self::GROUP, shipment_number: '4301-D1' ) );

		$flags     = new FeatureFlags();
		$factory   = new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() );
		$planner   = new HistoricalShipmentPlanner();
		$evaluator = new CodAwaitingShipmentEvaluator( $flags, $factory, $planner, $this->repository, new CodAwaitingShipmentStore() );
		$preview   = new ManualShipmentCreationPreviewFactory( $flags, $factory, $planner, $this->repository, $evaluator );

		self::assertTrue( $evaluator->should_await_manual_creation( $order ), 'a parent-only aggregate is not a created shipment' );

		$view = $preview->from_order( $order );
		self::assertTrue( $view->can_create() );
		self::assertTrue( $view->groups[0]->needs_creation );
		self::assertSame( '4301-D1', $view->groups[0]->existing_shipment_number, 'staff see which row the creation repairs' );

		$this->write( 4301 );
		self::assertFalse( $evaluator->should_await_manual_creation( $order ) );
		self::assertFalse( $preview->from_order( $order )->groups[0]->needs_creation );
	}

	// ------------------------------------------------------------------ COR-032

	public function test_status_row_and_event_are_written_together_and_roll_back_together(): void {
		$shipment = $this->write( 44 )->shipment;
		$status   = new ShipmentStatusService( $this->repository, new ShipmentOperationsIssueStore() );

		$this->wpdb->fail_next_insert_table = 'wp_delivery_engine_shipment_events';

		try {
			$status->change( $shipment->id, ShipmentStatus::Processing, ShipmentStatusChangeRequest::staff_normal( '', 9 ) );
			self::fail( 'a failed event insert must abort the status write' );
		} catch ( \RuntimeException ) {
			// expected
		}

		self::assertSame( ShipmentStatus::AwaitingFulfilment, $this->repository->findById( $shipment->id )?->status, 'the row did not move without its event' );
		self::assertCount( 0, $this->status_events( $shipment->id ) );

		$result = $status->change( $shipment->id, ShipmentStatus::Processing, ShipmentStatusChangeRequest::staff_normal( '', 9 ) );
		self::assertTrue( $result->ok );
		self::assertSame( ShipmentStatus::Processing, $this->repository->findById( $shipment->id )?->status );
		self::assertCount( 1, $this->status_events( $shipment->id ) );
	}

	public function test_guarded_write_touches_only_its_own_columns_and_refuses_a_stale_guard(): void {
		$shipment = $this->write( 44 )->shipment;
		$this->wpdb->update( 'wp_delivery_engine_shipments', [ 'tracking_number' => 'T-1', 'status' => ShipmentStatus::Dispatched->value ], [ 'id' => $shipment->id ] );

		$eta = $this->repository->updateFieldsWithEvent(
			$shipment->id,
			[ 'eta_current' => 'Tomorrow' ],
			[ 'eta_current' => '5-7 business days' ],
			ShipmentEvent::create( $shipment->id, ShipmentEventType::EtaUpdated, ShipmentEventSource::Staff, null, null, null, 'carrier delay', 9 )
		);

		self::assertSame( 'Tomorrow', $eta->eta_current );
		self::assertSame( '5-7 business days', $eta->eta_original, 'the original ETA is never written' );
		self::assertSame( 'T-1', $eta->tracking_number, 'an unrelated concurrent edit survives' );
		self::assertSame( ShipmentStatus::Dispatched, $eta->status );

		try {
			$this->repository->updateFieldsWithEvent(
				$shipment->id,
				[ 'status' => ShipmentStatus::Processing->value ],
				[ 'status' => ShipmentStatus::AwaitingFulfilment->value ],
				ShipmentEvent::create( $shipment->id, ShipmentEventType::StatusChanged, ShipmentEventSource::Staff, ShipmentStatus::AwaitingFulfilment, ShipmentStatus::Processing )
			);
			self::fail( 'a stale guard must refuse the write' );
		} catch ( ShipmentConcurrentEditException ) {
			// expected
		}

		self::assertSame( ShipmentStatus::Dispatched, $this->repository->findById( $shipment->id )?->status );
		self::assertCount( 0, $this->status_events( $shipment->id ), 'no event is written for a refused edit' );
	}

	public function test_eta_and_tracking_services_report_a_concurrent_edit_instead_of_overwriting(): void {
		$shipment = $this->write( 44 )->shipment;
		$eta      = new ShipmentEtaService( $this->repository );
		$tracking = new ShipmentTrackingService( $this->repository );

		$first = $eta->update_current( $shipment->id, 'Next week', 'port congestion', 9 );
		self::assertTrue( $first->ok );
		self::assertSame( '5-7 business days', $first->shipment?->eta_original );

		$saved = $tracking->save( $shipment->id, new ShipmentTrackingInput( 'DHL', 'TRK-1', 'https://example.test/t/1', '', 'Left the depot' ), 9 );
		self::assertTrue( $saved->ok );
		self::assertSame( 'Next week', $saved->shipment?->eta_current, 'the tracking save did not overwrite the ETA edit' );
		self::assertSame( 'TRK-1', $saved->shipment?->tracking_number );

		$types = array_map( static fn ( ShipmentEvent $event ): string => $event->event_type->value, $this->repository->findEvents( $shipment->id ) );
		self::assertContains( ShipmentEventType::EtaUpdated->value, $types );
		self::assertContains( ShipmentEventType::TrackingAdded->value, $types );
		self::assertContains( ShipmentEventType::NoteAdded->value, $types );
	}

	public function test_services_no_longer_write_whole_rows(): void {
		foreach ( [ 'ShipmentStatusService', 'ShipmentEtaService', 'ShipmentTrackingService' ] as $class ) {
			$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Shipment/' . $class . '.php' );
			self::assertStringNotContainsString( '->update(', $source, $class . ' must not overwrite the whole row' );
			self::assertStringContainsString( 'updateFieldsWithEvent', $source );
		}
	}

	public function test_repeated_cancellation_repairs_a_row_left_terminal_without_its_event(): void {
		$shipment = $this->write( 4424 )->shipment;
		// A partial earlier write: the row reached Cancelled but its StatusChanged event was lost.
		$this->wpdb->update( 'wp_delivery_engine_shipments', [ 'status' => ShipmentStatus::Cancelled->value ], [ 'id' => $shipment->id ] );
		self::assertCount( 0, $this->status_events( $shipment->id ) );

		$issues     = new ShipmentOperationsIssueStore();
		$subscriber = new OrderShipmentOperationsSubscriber(
			new FeatureFlags(),
			$this->repository,
			new ShipmentStatusService( $this->repository, $issues ),
			new ShipmentRefundInspector(),
			$issues
		);
		$order = new WC_Order( [ 'id' => 4424, 'order_number' => '4424', 'paid' => true, 'status' => 'cancelled' ] );
		$order->save();

		$subscriber->handle_order_cancelled( 4424, $order );
		$subscriber->handle_order_cancelled( 4424, $order );

		$events = $this->status_events( $shipment->id );
		self::assertCount( 1, $events, 'the retry appends the missing event exactly once' );
		self::assertSame( ShipmentStatus::Cancelled, $events[0]->to_status );
		self::assertSame( ShipmentEventSource::WooCommerce, $events[0]->source );
		self::assertSame( ShipmentStatus::Cancelled, $this->repository->findById( $shipment->id )?->status );
	}

	// ------------------------------------------------------------------ helpers

	private function write( int $order_id ): ShipmentAggregateWriteResult {
		return $this->repository->ensureCompleteAggregate(
			Shipment::create(
				order_id: $order_id,
				delivery_group_id: self::GROUP,
				status: ShipmentStatus::AwaitingFulfilment,
				fulfilment_availability: 'international',
				fulfilment_choice: 'delivery',
				delivery_offer_id: 12,
				delivery_offer_public_label: 'International Air',
				currency_code: 'GBP',
				customer_paid_shipping_amount: '25.0000',
				eta_original: '5-7 business days',
				shipment_number: $order_id . '-D1'
			),
			[ $this->widget( $order_id ) ]
		);
	}

	private function widget( int $order_id ): ShipmentItem {
		return ShipmentItem::create( 0, $order_id, 501, 1, 10, null, 'Widget' );
	}

	/**
	 * @return list<ShipmentEvent>
	 */
	private function created_events( int $shipment_id ): array {
		return array_values(
			array_filter(
				$this->repository->findEvents( $shipment_id ),
				static fn ( ShipmentEvent $event ): bool => $event->event_type->is( ShipmentEventType::Created )
			)
		);
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

	private function cod_delivery_order( int $order_id ): WC_Order {
		$line = ShipmentCreationFixtures::line( self::GROUP );

		return ShipmentCreationFixtures::paid_order(
			$order_id,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[ ShipmentCreationFixtures::wc_shipping_line( self::GROUP ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( self::GROUP ) ] ),
			true,
			'processing',
			null,
			'cod'
		);
	}
}
