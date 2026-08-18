<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusChangeRequest;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusTransitionPolicy;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
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

final class ShipmentStatusWorkflowTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private ShipmentStatusService $status;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']   = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_caps']      = [
			'manage_shipments'        => true,
			'update_shipment_status'  => true,
		];
		$GLOBALS['cetech_de_test_is_admin']  = true;
		$GLOBALS['cetech_de_test_logged_in'] = true;
		$GLOBALS['cetech_de_test_user_id']   = 9;
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects'] = [];
		$_POST = [];
		$_GET  = [];

		$this->repository = ShipmentCreationFixtures::repository();
		$this->status     = new ShipmentStatusService( $this->repository, new ShipmentOperationsIssueStore() );
	}

	public function test_normal_transition_matrix_allows_only_documented_paths(): void {
		$allowed = [
			[ ShipmentStatus::AwaitingFulfilment, ShipmentStatus::Processing ],
			[ ShipmentStatus::AwaitingFulfilment, ShipmentStatus::Cancelled ],
			[ ShipmentStatus::Processing, ShipmentStatus::Dispatched ],
			[ ShipmentStatus::Processing, ShipmentStatus::Delayed ],
			[ ShipmentStatus::Processing, ShipmentStatus::Cancelled ],
			[ ShipmentStatus::Dispatched, ShipmentStatus::InTransit ],
			[ ShipmentStatus::Dispatched, ShipmentStatus::Delayed ],
			[ ShipmentStatus::InTransit, ShipmentStatus::Delivered ],
			[ ShipmentStatus::InTransit, ShipmentStatus::Delayed ],
			[ ShipmentStatus::Delayed, ShipmentStatus::Processing ],
			[ ShipmentStatus::Delayed, ShipmentStatus::Dispatched ],
			[ ShipmentStatus::Delayed, ShipmentStatus::InTransit ],
			[ ShipmentStatus::Delayed, ShipmentStatus::Delivered ],
			[ ShipmentStatus::Delayed, ShipmentStatus::Cancelled ],
		];

		foreach ( $allowed as [ $from, $to ] ) {
			self::assertTrue( ShipmentStatusTransitionPolicy::allows_normal( $from, $to ), $from->value . ' -> ' . $to->value );
		}

		foreach ( ShipmentStatus::cases() as $from ) {
			foreach ( ShipmentStatus::cases() as $to ) {
				if ( $from === $to ) {
					continue;
				}

				$ok = false;
				foreach ( $allowed as [ $allowed_from, $allowed_to ] ) {
					if ( $allowed_from === $from && $allowed_to === $to ) {
						$ok = true;
						break;
					}
				}

				if ( ! $ok ) {
					self::assertFalse(
						ShipmentStatusTransitionPolicy::allows_normal( $from, $to ),
						'disallowed ' . $from->value . ' -> ' . $to->value
					);
				}
			}
		}

		self::assertTrue( ShipmentStatusTransitionPolicy::is_terminal( ShipmentStatus::Delivered ) );
		self::assertTrue( ShipmentStatusTransitionPolicy::is_terminal( ShipmentStatus::Cancelled ) );
	}

	public function test_each_allowed_normal_transition_persists_and_records_one_event(): void {
		$paths = [
			[ ShipmentStatus::AwaitingFulfilment, ShipmentStatus::Processing, '' ],
			[ ShipmentStatus::Processing, ShipmentStatus::Dispatched, '' ],
			[ ShipmentStatus::Dispatched, ShipmentStatus::InTransit, '' ],
			[ ShipmentStatus::InTransit, ShipmentStatus::Delivered, '' ],
		];

		foreach ( $paths as $i => [ $from, $to, $reason ] ) {
			$shipment = $this->store_with_status( 4100 + $i, $from );
			$result   = $this->status->change(
				$shipment->id,
				$to,
				ShipmentStatusChangeRequest::staff_normal( $reason, 9 )
			);

			self::assertTrue( $result->ok, $from->value . ' -> ' . $to->value );
			$saved = $this->repository->findById( $shipment->id );
			self::assertNotNull( $saved );
			self::assertSame( $to, $saved->status );
			self::assertSame( '25.00', $saved->customer_paid_shipping_amount );
			self::assertNull( $saved->dispatch_at );

			$events = $this->status_events( $shipment->id );
			self::assertCount( 1, $events );
			self::assertSame( $from, $events[0]->from_status );
			self::assertSame( $to, $events[0]->to_status );
			self::assertSame( ShipmentEventSource::Staff, $events[0]->source );
			self::assertSame( 9, $events[0]->actor_user_id );
		}
	}

	public function test_delayed_entry_and_recovery_preserve_history(): void {
		$shipment = $this->store_with_status( 4201, ShipmentStatus::Processing );

		$delayed = $this->status->change(
			$shipment->id,
			ShipmentStatus::Delayed,
			ShipmentStatusChangeRequest::staff_normal( 'Carrier missed collection', 9 )
		);
		self::assertTrue( $delayed->ok );

		$resumed = $this->status->change(
			$shipment->id,
			ShipmentStatus::Dispatched,
			ShipmentStatusChangeRequest::staff_normal( 'Collection completed', 9 )
		);
		self::assertTrue( $resumed->ok );

		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertSame( ShipmentStatus::Dispatched, $saved->status );

		$events = $this->status_events( $shipment->id );
		self::assertCount( 2, $events );
		self::assertSame( ShipmentStatus::Processing, $events[0]->from_status );
		self::assertSame( ShipmentStatus::Delayed, $events[0]->to_status );
		self::assertSame( 'Carrier missed collection', $events[0]->internal_note );
		self::assertSame( ShipmentStatus::Delayed, $events[1]->from_status );
		self::assertSame( ShipmentStatus::Dispatched, $events[1]->to_status );
		self::assertSame( 'Collection completed', $events[1]->internal_note );
	}

	public function test_delayed_and_cancel_without_reason_are_rejected(): void {
		$processing = $this->store_with_status( 4202, ShipmentStatus::Processing );

		$delayed = $this->status->change(
			$processing->id,
			ShipmentStatus::Delayed,
			ShipmentStatusChangeRequest::staff_normal( '', 9 )
		);
		self::assertFalse( $delayed->ok );
		self::assertSame( 'reason_required', $delayed->code );

		$cancel = $this->status->change(
			$processing->id,
			ShipmentStatus::Cancelled,
			ShipmentStatusChangeRequest::staff_normal( '   ', 9 )
		);
		self::assertFalse( $cancel->ok );
		self::assertSame( 'reason_required', $cancel->code );
		self::assertSame( ShipmentStatus::Processing, $this->repository->findById( $processing->id )?->status );
	}

	public function test_terminal_statuses_reject_ordinary_transitions(): void {
		$delivered = $this->store_with_status( 4203, ShipmentStatus::Delivered );
		$result    = $this->status->change(
			$delivered->id,
			ShipmentStatus::InTransit,
			ShipmentStatusChangeRequest::staff_normal( 'oops', 9 )
		);

		self::assertFalse( $result->ok );
		self::assertSame( 'transition_not_allowed', $result->code );
		self::assertSame( ShipmentStatus::Delivered, $this->repository->findById( $delivered->id )?->status );
		self::assertSame( [], $this->status_events( $delivered->id ) );
	}

	public function test_disallowed_skip_ahead_is_rejected(): void {
		$awaiting = $this->store_with_status( 4204, ShipmentStatus::AwaitingFulfilment );
		$result   = $this->status->change(
			$awaiting->id,
			ShipmentStatus::Dispatched,
			ShipmentStatusChangeRequest::staff_normal( '', 9 )
		);

		self::assertFalse( $result->ok );
		self::assertSame( 'transition_not_allowed', $result->code );
	}

	public function test_corrective_change_requires_reason_and_preserves_prior_events(): void {
		$shipment = $this->store_with_status( 4205, ShipmentStatus::Delivered );

		$missing = $this->status->change(
			$shipment->id,
			ShipmentStatus::InTransit,
			ShipmentStatusChangeRequest::staff_correction( '', 9 )
		);
		self::assertFalse( $missing->ok );
		self::assertSame( 'reason_required', $missing->code );

		$ok = $this->status->change(
			$shipment->id,
			ShipmentStatus::InTransit,
			ShipmentStatusChangeRequest::staff_correction( 'Marked delivered one day early', 9 )
		);
		self::assertTrue( $ok->ok );

		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertSame( ShipmentStatus::InTransit, $saved->status );
		self::assertSame( '25.00', $saved->customer_paid_shipping_amount );

		$events = $this->status_events( $shipment->id );
		self::assertCount( 1, $events );
		self::assertSame( ShipmentStatusService::CORRECTION_MARKER, $events[0]->public_note );
		self::assertSame( 'Marked delivered one day early', $events[0]->internal_note );
		self::assertStringContainsString( 'Status corrected', ShipmentPresentation::event_label( $events[0] ) );
		self::assertStringContainsString( 'In transit', ShipmentPresentation::event_label( $events[0] ) );
	}

	public function test_repeat_same_status_does_not_append_event(): void {
		$shipment = $this->store_with_status( 4206, ShipmentStatus::Processing );
		$first    = $this->status->change(
			$shipment->id,
			ShipmentStatus::Processing,
			ShipmentStatusChangeRequest::staff_normal( '', 9 )
		);

		self::assertTrue( $first->ok );
		self::assertTrue( $first->unchanged );
		self::assertSame( [], $this->status_events( $shipment->id ) );
	}

	public function test_invalid_status_code_is_rejected_by_request_parser(): void {
		self::assertNull( $this->status->target_from_request( 'Awaiting fulfilment' ) );
		self::assertNull( $this->status->target_from_request( 'not_a_status' ) );
		self::assertSame( ShipmentStatus::Dispatched, $this->status->target_from_request( 'dispatched' ) );
	}

	public function test_automatic_cancel_is_limited_to_pre_dispatch_states(): void {
		$awaiting = $this->store_with_status( 4207, ShipmentStatus::AwaitingFulfilment );
		$ok       = $this->status->change(
			$awaiting->id,
			ShipmentStatus::Cancelled,
			ShipmentStatusChangeRequest::automatic( ShipmentEventSource::WooCommerce, ShipmentStatusService::REASON_ORDER_CANCELLED )
		);
		self::assertTrue( $ok->ok );

		$dispatched = $this->store_with_status( 4208, ShipmentStatus::Dispatched );
		$blocked    = $this->status->change(
			$dispatched->id,
			ShipmentStatus::Cancelled,
			ShipmentStatusChangeRequest::automatic( ShipmentEventSource::WooCommerce, ShipmentStatusService::REASON_ORDER_CANCELLED )
		);
		self::assertFalse( $blocked->ok );
		self::assertSame( ShipmentStatus::Dispatched, $this->repository->findById( $dispatched->id )?->status );
	}

	public function test_customer_card_shows_new_status_and_hides_private_reason(): void {
		$shipment = $this->store_with_status( 4209, ShipmentStatus::Processing );
		$this->status->change(
			$shipment->id,
			ShipmentStatus::Delayed,
			ShipmentStatusChangeRequest::staff_normal( 'SECRET-STAFF-REASON', 9 )
		);

		$query = new CustomerShipmentQuery( new FeatureFlags(), $this->repository );
		$cards = $query->cards_for_order( new \WC_Order( [ 'id' => 4209 ] ) );

		self::assertCount( 1, $cards );
		self::assertSame( 'Delayed', $cards[0]->status_label );
		self::assertStringNotContainsString( 'SECRET-STAFF-REASON', $cards[0]->status_label );
		self::assertNull( $cards[0]->public_note );
	}

	public function test_staff_page_shows_actions_not_an_ordinary_status_dropdown(): void {
		$shipment = $this->store_with_status( 4210, ShipmentStatus::Processing );
		$page     = $this->page();

		$_GET['shipment'] = (string) $shipment->id;
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Mark as Dispatched', $html );
		self::assertStringContainsString( 'Mark as Delayed / issue', $html );
		self::assertStringContainsString( 'Cancel shipment', $html );
		self::assertStringContainsString( 'Correct status', $html );
		self::assertStringContainsString( 'cetech-de-correct-status', $html );
		self::assertStringNotContainsString( 'name="status" id="cetech-de-workflow-status"', $html );
		self::assertStringContainsString( 'Mark as Dispatched', $html );
		self::assertStringNotContainsString( 'Invented processing days', $html );
	}

	public function test_status_post_requires_capability_and_nonce(): void {
		$shipment = $this->store_with_status( 4211, ShipmentStatus::AwaitingFulfilment );
		$page     = $this->page();

		$GLOBALS['cetech_de_test_caps']['update_shipment_status'] = false;
		$_POST = [
			'cetech_de_action' => ShipmentsPage::ACTION_CHANGE_STATUS,
			'cetech_de_nonce'  => 'test-nonce-' . ShipmentsPage::ACTION_CHANGE_STATUS,
			'shipment_id'      => (string) $shipment->id,
			'target_status'    => ShipmentStatus::Processing->value,
		];

		try {
			$page->handle_actions();
			self::fail( 'Unauthorized status change should redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}

		self::assertSame( ShipmentStatus::AwaitingFulfilment, $this->repository->findById( $shipment->id )?->status );

		$GLOBALS['cetech_de_test_caps']['update_shipment_status'] = true;
		$_POST['cetech_de_nonce'] = 'forged';

		try {
			$page->handle_actions();
			self::fail( 'Invalid nonce should redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}
	}

	public function test_unknown_event_code_still_hydrates_after_status_change(): void {
		$shipment = $this->store_with_status( 4212, ShipmentStatus::AwaitingFulfilment );
		$wpdb     = $GLOBALS['wpdb'];
		$wpdb->insert(
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

		$this->status->change(
			$shipment->id,
			ShipmentStatus::Processing,
			ShipmentStatusChangeRequest::staff_normal( '', 9 )
		);

		$codes = array_map(
			static fn ( ShipmentEvent $event ): string => $event->event_type->value,
			$this->repository->findEvents( $shipment->id )
		);
		self::assertContains( 'future_mystery_event', $codes );
		self::assertContains( ShipmentEventType::StatusChanged->value, $codes );
		self::assertSame( 'Shipment update', ShipmentPresentation::event_type_label( 'future_mystery_event' ) );
	}

	private function page(): ShipmentsPage {
		return new ShipmentsPage(
			new FeatureFlags(),
			new ShipmentWorkspaceQuery( $this->repository ),
			new AdminActionHandler( new AdminNoticeService() ),
			null,
			$this->status
		);
	}

	private function store_with_status( int $order_id, ShipmentStatus $status ): Shipment {
		$created = $this->repository->create(
			Shipment::create(
				$order_id,
				'g-' . $order_id,
				$status,
				delivery_offer_public_label: 'Air Shipping',
				currency_code: 'GBP',
				customer_paid_shipping_amount: '25.00',
				eta_original: '5-7 business days',
				shipment_number: $order_id . '-D1'
			)
		);
		$this->repository->replaceItems(
			$created->id,
			[ ShipmentItem::create( $created->id, $order_id, 501, 1, 10, null, 'Widget' ) ]
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
