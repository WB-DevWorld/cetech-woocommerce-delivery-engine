<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingInput;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingService;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Application\Shipment\TrackingUrl;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\ShipmentPresentation;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use PHPUnit\Framework\TestCase;

final class StaffShipmentTrackingTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private FakeWpdb $wpdb;

	private ShipmentTrackingService $tracking;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']    = [];
		$GLOBALS['cetech_de_test_caps']       = [ 'manage_shipments' => true ];
		$GLOBALS['cetech_de_test_is_admin']   = true;
		$GLOBALS['cetech_de_test_logged_in']  = true;
		$GLOBALS['cetech_de_test_user_id']    = 9;
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects']  = [];
		$_POST = [];
		$_GET  = [];

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
		$GLOBALS['wpdb']      = $this->wpdb;
		$this->repository     = new WpdbShipmentRepository();
		$this->tracking       = new ShipmentTrackingService( $this->repository );
	}

	public function test_https_and_http_urls_are_accepted_and_unsafe_schemes_are_rejected(): void {
		self::assertSame( 'https://carrier.example/track?n=1', TrackingUrl::normalize( 'https://carrier.example/track?n=1' ) );
		self::assertSame( 'http://carrier.example/track', TrackingUrl::normalize( 'http://carrier.example/track' ) );
		self::assertNull( TrackingUrl::normalize( '' ) );
		self::assertFalse( TrackingUrl::is_safe_http_url( 'javascript:alert(1)' ) );
		self::assertFalse( TrackingUrl::is_safe_http_url( 'data:text/html,hi' ) );
		self::assertFalse( TrackingUrl::is_safe_http_url( 'file:///etc/passwd' ) );
		self::assertFalse( TrackingUrl::is_safe_http_url( 'not-a-url' ) );
	}

	public function test_first_tracking_save_adds_event_without_changing_status_or_exposing_payload(): void {
		$shipment = $this->store_shipment( 801 );

		$result = $this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( 'DHL', 'ABC-123', 'https://dhl.example/ABC-123', '2026-08-18', 'Leave with reception' ),
			9
		);

		self::assertTrue( $result->ok );
		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $saved->status );
		self::assertSame( 'DHL', $saved->tracking_carrier_display );
		self::assertSame( 'ABC-123', $saved->tracking_number );
		self::assertSame( 'https://dhl.example/ABC-123', $saved->tracking_url );
		self::assertNotNull( $saved->dispatch_at );
		self::assertSame( 'Leave with reception', $saved->public_note );
		self::assertSame( 'Keep shrink-wrapped', $saved->private_note );

		$events = $this->repository->findEvents( $shipment->id );
		$types  = array_map( static fn ( ShipmentEvent $event ): string => $event->event_type->value, $events );
		self::assertContains( ShipmentEventType::TrackingAdded->value, $types );
		self::assertContains( ShipmentEventType::NoteAdded->value, $types );
		self::assertSame(
			'Tracking added',
			ShipmentPresentation::event_type_label( ShipmentEventType::TrackingAdded->value )
		);

		foreach ( $events as $event ) {
			self::assertNotSame( 'ABC-123', $event->public_note );
			self::assertNotSame( 'Leave with reception', $event->public_note );
			self::assertNull( $event->internal_note );
		}
	}

	public function test_changed_tracking_updates_history_and_identical_save_is_stable(): void {
		$shipment = $this->store_shipment( 802 );
		$this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( 'DHL', 'AAA', 'https://dhl.example/AAA', '', '' ),
			9
		);
		$second = $this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( 'DHL', 'BBB', 'https://dhl.example/BBB', '', '' ),
			9
		);
		$same = $this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( 'DHL', 'BBB', 'https://dhl.example/BBB', '', '' ),
			9
		);

		self::assertTrue( $second->ok );
		self::assertTrue( $same->unchanged );
		$types = array_map( static fn ( ShipmentEvent $event ): string => $event->event_type->value, $this->repository->findEvents( $shipment->id ) );
		self::assertSame( 1, substr_count( implode( ' ', $types ), ShipmentEventType::TrackingAdded->value ) );
		self::assertContains( ShipmentEventType::TrackingUpdated->value, $types );
	}

	public function test_clearing_tracking_is_allowed_and_keeps_history(): void {
		$shipment = $this->store_shipment( 803 );
		$this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( 'DHL', 'ABC', 'https://dhl.example/ABC', '2026-08-18', 'Note' ),
			9
		);
		$cleared = $this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( '', '', '', '', '' ),
			9
		);

		self::assertTrue( $cleared->ok );
		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertNull( $saved->tracking_number );
		self::assertNull( $saved->tracking_url );
		self::assertNull( $saved->tracking_carrier_display );
		self::assertNull( $saved->dispatch_at );
		self::assertNull( $saved->public_note );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $saved->status );

		$notes = array_map( static fn ( ShipmentEvent $event ): string => (string) $event->public_note, $this->repository->findEvents( $shipment->id ) );
		self::assertContains( 'Tracking details were cleared.', $notes );
	}

	public function test_invalid_url_and_dispatch_date_are_rejected(): void {
		$shipment = $this->store_shipment( 804 );
		$unsafe   = $this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( '', '', 'javascript:alert(1)', '', '' ),
			9
		);
		$bad_date = $this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( '', '', '', '18/08/2026', '' ),
			9
		);

		self::assertFalse( $unsafe->ok );
		self::assertFalse( $bad_date->ok );
		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertNull( $saved->tracking_url );
		self::assertSame( ShipmentStatus::AwaitingFulfilment, $saved->status );
	}

	public function test_staff_form_is_present_when_tracking_service_is_wired(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_shipment_records'] = 1;
		$GLOBALS['cetech_de_test_caps']['view_private_delivery_costs'] = true;
		$shipment = $this->store_shipment( 805 );
		$page     = new ShipmentsPage(
			new FeatureFlags(),
			new ShipmentWorkspaceQuery( $this->repository ),
			new AdminActionHandler( new AdminNoticeService() ),
			$this->tracking
		);

		$_GET['shipment'] = (string) $shipment->id;
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="tracking_carrier"', $html );
		self::assertStringContainsString( 'name="tracking_number"', $html );
		self::assertStringContainsString( 'name="tracking_url"', $html );
		self::assertStringContainsString( 'name="dispatch_date"', $html );
		self::assertStringContainsString( 'name="public_note"', $html );
		self::assertStringContainsString( 'Visible to the customer', $html );
		self::assertStringContainsString( 'Keep shrink-wrapped', $html );
		self::assertStringContainsString( 'does not mark the shipment as dispatched', $html );
		self::assertStringNotContainsString( 'name="status"', $html );
	}

	public function test_unauthorized_and_invalid_nonce_posts_are_denied(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_shipment_records'] = 1;
		$page = new ShipmentsPage(
			new FeatureFlags(),
			new ShipmentWorkspaceQuery( $this->repository ),
			new AdminActionHandler( new AdminNoticeService() ),
			$this->tracking
		);

		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = false;
		$_POST = [
			'cetech_de_action' => ShipmentsPage::ACTION_SAVE_TRACKING,
			'cetech_de_nonce'  => 'test-nonce-' . ShipmentsPage::ACTION_SAVE_TRACKING,
			'shipment_id'      => '1',
		];

		try {
			$page->handle_actions();
			self::fail( 'Unauthorized tracking save should redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}

		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = true;
		$_POST['cetech_de_nonce'] = 'forged';

		try {
			$page->handle_actions();
			self::fail( 'Invalid nonce should redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	public function test_unknown_event_resilience_still_holds_after_tracking_save(): void {
		$shipment = $this->store_shipment( 806 );
		$this->wpdb->insert(
			'wp_delivery_engine_shipment_events',
			[
				'shipment_id'   => $shipment->id,
				'event_type'    => 'future_mystery_event',
				'from_status'   => null,
				'to_status'     => null,
				'public_note'   => null,
				'internal_note' => null,
				'actor_user_id' => null,
				'source'        => ShipmentEventSource::System->value,
				'event_at'      => '2026-08-18 12:00:00',
				'created_at'    => '2026-08-18 12:00:00',
			]
		);

		$this->tracking->save(
			$shipment->id,
			new ShipmentTrackingInput( '', 'ZZ-1', '', '', '' ),
			9
		);

		$events = $this->repository->findEvents( $shipment->id );
		$codes  = array_map( static fn ( ShipmentEvent $event ): string => $event->event_type->value, $events );
		self::assertContains( 'future_mystery_event', $codes );
		self::assertSame( 'Shipment update', ShipmentPresentation::event_type_label( 'future_mystery_event' ) );
	}

	private function store_shipment( int $order_id ): Shipment {
		$shipment = $this->repository->create(
			Shipment::create(
				$order_id,
				'g-' . $order_id,
				shipment_number: $order_id . '-D1',
				private_note: 'Keep shrink-wrapped'
			)
		);
		$this->repository->replaceItems(
			$shipment->id,
			[ ShipmentItem::create( $shipment->id, $order_id, 501, 1, 10, null, 'Widget' ) ]
		);

		return $shipment;
	}
}
