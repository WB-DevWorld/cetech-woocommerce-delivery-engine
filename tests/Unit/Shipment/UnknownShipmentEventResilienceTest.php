<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEventCode;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Admin\ShipmentPresentation;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use PHPUnit\Framework\TestCase;

final class UnknownShipmentEventResilienceTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_caps']    = [ 'manage_shipments' => true, 'edit_shop_orders' => true ];
		$GLOBALS['cetech_de_test_wc_orders'] = [];
		$_GET = [];

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

	public function test_known_created_event_hydrates_with_typed_semantics(): void {
		$shipment = $this->repository->create( Shipment::create( 501, 'g-known', shipment_number: '501-D1' ) );
		$event    = $this->repository->appendEvent(
			ShipmentEvent::create(
				$shipment->id,
				ShipmentEventType::Created,
				ShipmentEventSource::System,
				null,
				ShipmentStatus::AwaitingFulfilment
			)
		);

		self::assertSame( 'created', $event->event_type->value );
		self::assertTrue( $event->event_type->isKnown() );
		self::assertTrue( $event->event_type->is( ShipmentEventType::Created ) );
		self::assertSame( ShipmentEventType::Created, $event->event_type->knownType() );
		self::assertSame( 'created', $this->stored_event_type( $event->id ) );
		self::assertNotSame( 'Awaiting fulfilment', $this->stored_event_type( $event->id ) );
		self::assertSame(
			'Awaiting fulfilment',
			ShipmentPresentation::event_label( $event )
		);
	}

	public function test_unknown_event_code_hydrates_and_preserves_raw_machine_code(): void {
		$shipment = $this->persist_shipment_with_item( 502, 'g-unknown', '502-D1' );
		$this->insert_raw_event( $shipment->id, 'carrier_exception_v2' );

		$before = $this->stored_event_row_for( $shipment->id, 'carrier_exception_v2' );
		$events = $this->repository->findEvents( $shipment->id );
		$after  = $this->stored_event_row_for( $shipment->id, 'carrier_exception_v2' );

		self::assertCount( 1, $events );
		$event = $events[0];
		self::assertSame( 'carrier_exception_v2', $event->event_type->value );
		self::assertFalse( $event->event_type->isKnown() );
		self::assertNull( $event->event_type->knownType() );
		self::assertFalse( $event->event_type->is( ShipmentEventType::Created ) );
		self::assertFalse( $event->event_type->is( ShipmentEventType::StatusChanged ) );
		self::assertSame( $before, $after );
		self::assertSame( 'carrier_exception_v2', $this->stored_event_type_for_shipment( $shipment->id ) );
	}

	public function test_unknown_event_does_not_block_shipment_aggregate_or_history(): void {
		$shipment = $this->persist_shipment_with_item( 503, 'g-load', '503-D1' );
		$this->repository->appendEvent(
			ShipmentEvent::create( $shipment->id, ShipmentEventType::Created, ShipmentEventSource::System )
		);
		$this->insert_raw_event( $shipment->id, 'future_mystery_event' );

		$loaded = $this->repository->findById( $shipment->id );
		$items  = $this->repository->findItems( $shipment->id );
		$events = $this->repository->findEvents( $shipment->id );
		$detail = ( new ShipmentWorkspaceQuery( $this->repository ) )->detail( $shipment->id );

		self::assertNotNull( $loaded );
		self::assertSame( $shipment->id, $loaded->id );
		self::assertCount( 1, $items );
		self::assertCount( 2, $events );
		self::assertNotNull( $detail );
		self::assertCount( 2, $detail->events );
		self::assertEqualsCanonicalizing(
			[ 'created', 'future_mystery_event' ],
			array_map( static fn ( ShipmentEvent $event ): string => $event->event_type->value, $detail->events )
		);
	}

	public function test_unknown_event_renders_safe_generic_label_without_payload_or_raw_code(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_shipment_records'] = 1;
		$shipment = $this->persist_shipment_with_item( 504, 'g-ui', '504-D1' );
		$this->insert_raw_event( $shipment->id, 'future_mystery_event', 'Leave at reception' );

		$page = new ShipmentsPage( new FeatureFlags(), new ShipmentWorkspaceQuery( $this->repository ) );
		$_GET = [ 'shipment' => (string) $shipment->id ];
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Shipment update', $html );
		self::assertStringContainsString( 'Leave at reception', $html );
		self::assertStringNotContainsString( 'future_mystery_event', $html );
		self::assertStringNotContainsString( 'a:1:{', $html );
		self::assertStringNotContainsString( 'O:8:"stdClass"', $html );
		self::assertSame(
			'Shipment update',
			ShipmentPresentation::event_type_label( 'future_mystery_event' )
		);
	}

	public function test_unknown_event_does_not_satisfy_created_workflow_semantics(): void {
		$shipment = $this->persist_shipment_with_item( 505, 'g-workflow', '505-D1' );
		$this->insert_raw_event( $shipment->id, 'carrier_exception_v2' );

		$result = $this->repository->ensureCompleteAggregate(
			$shipment,
			[ ShipmentItem::create( $shipment->id, 505, 501, 1, 10, null, 'Widget' ) ]
		);

		$types = array_map(
			static fn ( ShipmentEvent $event ): string => $event->event_type->value,
			$this->repository->findEvents( $shipment->id )
		);

		self::assertContains( 'created', $types );
		self::assertContains( 'carrier_exception_v2', $types );
		self::assertSame( 1, substr_count( implode( ' ', $types ), 'created' ) );
		unset( $result );
	}

	public function test_unknown_current_shipment_status_still_rejected(): void {
		$this->repository->create( Shipment::create( 506, 'g-status', shipment_number: '506-D1' ) );
		$this->wpdb->update(
			'wp_delivery_engine_shipments',
			[ 'status' => 'Awaiting fulfilment' ],
			[ 'order_id' => 506 ]
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Persisted shipment status is not a recognised machine code.' );
		$this->repository->findByOrderAndGroup( 506, 'g-status' );
	}

	public function test_create_persists_machine_event_code_not_translated_label(): void {
		$code = ShipmentEventCode::fromKnown( ShipmentEventType::Created );

		self::assertSame( 'created', $code->value );
		self::assertNotSame( 'Awaiting fulfilment', $code->value );
		self::assertNotSame( 'Shipment update', $code->value );
	}

	private function persist_shipment_with_item( int $order_id, string $group, string $number ): Shipment {
		$shipment = $this->repository->create(
			Shipment::create( $order_id, $group, shipment_number: $number )
		);
		$this->repository->replaceItems(
			$shipment->id,
			[ ShipmentItem::create( $shipment->id, $order_id, 501, 1, 10, null, 'Widget' ) ]
		);

		return $shipment;
	}

	private function insert_raw_event( int $shipment_id, string $event_type, ?string $public_note = null ): void {
		$this->wpdb->insert(
			'wp_delivery_engine_shipment_events',
			[
				'shipment_id'   => $shipment_id,
				'event_type'    => $event_type,
				'from_status'   => null,
				'to_status'     => null,
				'public_note'   => $public_note,
				'internal_note' => null,
				'actor_user_id' => null,
				'source'        => ShipmentEventSource::System->value,
				'event_at'      => '2026-08-18 12:00:00',
				'created_at'    => '2026-08-18 12:00:00',
			]
		);
	}

	private function stored_event_type( int $event_id ): string {
		foreach ( $this->wpdb_event_rows() as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $event_id ) {
				return (string) $row['event_type'];
			}
		}

		self::fail( 'Stored event row was not found.' );
	}

	private function stored_event_type_for_shipment( int $shipment_id ): string {
		$row = $this->stored_event_row_for( $shipment_id, 'carrier_exception_v2' );

		return (string) $row['event_type'];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stored_event_row_for( int $shipment_id, string $event_type ): array {
		foreach ( $this->wpdb_event_rows() as $row ) {
			if ( (int) ( $row['shipment_id'] ?? 0 ) === $shipment_id && (string) ( $row['event_type'] ?? '' ) === $event_type ) {
				return $row;
			}
		}

		self::fail( 'Stored event row was not found.' );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function wpdb_event_rows(): array {
		return $this->wpdb->table_rows( 'wp_delivery_engine_shipment_events' );
	}
}
