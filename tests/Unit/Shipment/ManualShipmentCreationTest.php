<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentEvaluator;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\ManualShipmentCreationMessages;
use CetechDeliveryEngine\Application\Shipment\ManualShipmentCreationPreview;
use CetechDeliveryEngine\Application\Shipment\ManualShipmentCreationPreviewFactory;
use CetechDeliveryEngine\Application\Shipment\PaidOrderShipmentSubscriber;
use CetechDeliveryEngine\Application\Shipment\ShipmentActivityCursor;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationOutcome;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\InMemoryAuditLogRepository;
use PHPUnit\Framework\TestCase;

final class ManualShipmentCreationTest extends TestCase {

	private FeatureFlags $flags;

	private ShipmentRepositoryInterface $shipments;

	private ShipmentService $service;

	private ManualShipmentCreationPreviewFactory $preview;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']   = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_wc_orders'] = [];
		$GLOBALS['cetech_de_test_caps']      = [ 'manage_shipments' => true ];
		$GLOBALS['cetech_de_test_is_admin']  = true;
		$GLOBALS['cetech_de_test_user_id']   = 3;
		$_POST                               = [];

		$this->flags     = new FeatureFlags();
		$this->shipments = ShipmentCreationFixtures::repository();
		$factory         = new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() );
		$planner         = new HistoricalShipmentPlanner();
		$evaluator       = new CodAwaitingShipmentEvaluator(
			$this->flags,
			$factory,
			$planner,
			$this->shipments,
			new CodAwaitingShipmentStore()
		);
		$this->service = new ShipmentService(
			$this->flags,
			$factory,
			$planner,
			$this->shipments,
			new ShipmentCreationFailureStore(),
			new InMemoryAuditLogRepository(),
			new Logger(),
			$evaluator
		);
		$this->preview = new ManualShipmentCreationPreviewFactory(
			$this->flags,
			$factory,
			$planner,
			$this->shipments,
			$evaluator
		);
		$this->flags->set( 'enable_shipment_records', true );
	}

	public function test_preview_loads_historical_snapshot_without_current_configuration(): void {
		$order    = $this->cod_delivery_order( 4201 );
		$preview  = $this->preview->from_order( $order );

		self::assertTrue( $preview->can_create() );
		self::assertSame( '4201', $preview->order_number );
		self::assertSame( 'Ada Lovelace', $preview->customer_name );
		self::assertSame( 'Cash on Delivery', $preview->payment_method_label );
		self::assertSame( '1 Harbour Street, Kingston', $preview->destination_summary );
		self::assertSame( 'International Air', $preview->groups[0]->delivery_option_label );
		self::assertSame( '5-7 business days', $preview->groups[0]->eta_original );
		self::assertTrue( $preview->groups[0]->needs_creation );
		self::assertSame( 'Widget', $preview->items[0]->name );
		self::assertSame( 1, $preview->items[0]->quantity );

		$factory_source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Application/Shipment/ManualShipmentCreationPreviewFactory.php'
		);
		$service_source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Application/Shipment/ShipmentService.php'
		);
		self::assertStringNotContainsString( 'EffectiveConfigurationResolver', $factory_source );
		self::assertStringNotContainsString( 'EffectiveConfigurationResolver', $service_source );
	}

	public function test_staff_confirm_creates_exactly_one_shipment_with_staff_actor(): void {
		$order  = $this->cod_delivery_order( 4202 );
		$result = $this->service->create_from_historical_order_for_staff( $order, 3 );

		self::assertSame( ShipmentCreationOutcome::Created, $result->outcome );
		self::assertCount( 1, $this->shipments->findByOrderId( 4202 ) );
		$shipment = $this->shipments->findByOrderId( 4202 )[0];
		self::assertSame( 'international|delivery|12', $shipment->delivery_group_id );
		self::assertSame( 'International Air', $shipment->delivery_offer_public_label );
		self::assertSame( '5-7 business days', $shipment->eta_original );
		self::assertCount( 1, $this->shipments->findItems( $shipment->id ) );

		$events = $this->shipments->findEvents( $shipment->id );
		self::assertCount( 1, $events );
		self::assertSame( ShipmentEventType::Created, $events[0]->event_type->knownType() );
		self::assertSame( ShipmentEventSource::Staff, $events[0]->source );
		self::assertSame( 3, $events[0]->actor_user_id );
	}

	public function test_duplicate_manual_submission_does_not_create_a_second_shipment(): void {
		$order = $this->cod_delivery_order( 4203 );
		$first = $this->service->create_from_historical_order_for_staff( $order, 3 );
		$again = $this->service->create_from_historical_order_for_staff( $order, 3 );

		self::assertSame( ShipmentCreationOutcome::Created, $first->outcome );
		self::assertSame( ShipmentCreationOutcome::AlreadyExistsComplete, $again->outcome );
		self::assertCount( 1, $this->shipments->findByOrderId( 4203 ) );
		self::assertCount( 1, $this->shipments->findEvents( $first->shipments[0]->id ) );
		self::assertCount( 1, $this->shipments->findItems( $first->shipments[0]->id ) );
	}

	public function test_multiple_historical_groups_create_one_shipment_each_and_skip_pickup(): void {
		$air    = 'international|delivery|12';
		$sea    = 'international|delivery|13';
		$pickup = 'in_store|store_pickup|pickup';
		$air_line = ShipmentCreationFixtures::line( $air, 10, 501, 'Air Shipping' );
		$sea_line = ShipmentCreationFixtures::line( $sea, 11, 502, 'Sea Shipping', '10-14 business days', '15.00' );
		$pick_line = ShipmentCreationFixtures::line( $pickup, 21, 503, 'Store Pickup', null, '0.00' );
		$order = ShipmentCreationFixtures::paid_order(
			4204,
			[
				ShipmentCreationFixtures::product_item_from_line( $air_line ),
				ShipmentCreationFixtures::product_item_from_line( $sea_line ),
				ShipmentCreationFixtures::product_item_from_line( $pick_line ),
			],
			[
				ShipmentCreationFixtures::wc_shipping_line( $air ),
				ShipmentCreationFixtures::wc_shipping_line( $sea, '15.00' ),
			],
			ShipmentCreationFixtures::package(
				[
					ShipmentCreationFixtures::group( $air, '25.00', 1 ),
					ShipmentCreationFixtures::group( $sea, '15.00', 2 ),
					ShipmentCreationFixtures::group( $pickup, '0.00', 3, true ),
				],
				'GBP',
				4,
				'40.00'
			),
			true,
			'processing',
			null,
			'cod'
		);

		$preview = $this->preview->from_order( $order );
		self::assertTrue( $preview->can_create() );
		$pickup_groups = array_values( array_filter( $preview->groups, static fn ( $group ): bool => $group->is_pickup ) );
		self::assertCount( 1, $pickup_groups );
		self::assertFalse( $pickup_groups[0]->needs_creation );

		$result = $this->service->create_from_historical_order_for_staff( $order, 3 );
		self::assertSame( ShipmentCreationOutcome::Created, $result->outcome );
		self::assertCount( 2, $this->shipments->findByOrderId( 4204 ) );
		$groups = [];
		foreach ( $this->shipments->findByOrderId( 4204 ) as $shipment ) {
			$groups[] = $shipment->delivery_group_id;
		}
		self::assertContains( $air, $groups );
		self::assertContains( $sea, $groups );
		self::assertNotContains( $pickup, $groups );
	}

	public function test_unknown_order_and_missing_snapshot_fail_safely(): void {
		$missing = $this->preview->from_order_id( 999999 );
		self::assertSame( ManualShipmentCreationPreview::CODE_ORDER_NOT_FOUND, $missing->code );
		self::assertSame( ManualShipmentCreationMessages::for_preview_code( ManualShipmentCreationPreview::CODE_ORDER_NOT_FOUND ), $missing->message );

		$order = ShipmentCreationFixtures::paid_order( 4205, [], [], null, true, 'processing', null, 'cod' );
		$none  = $this->preview->from_order( $order );
		self::assertSame( ManualShipmentCreationPreview::CODE_NO_SNAPSHOT, $none->code );
		self::assertFalse( $none->can_create() );
	}

	public function test_pickup_only_preview_explains_no_shipment_is_required(): void {
		$pickup = 'in_store|store_pickup|pickup';
		$line   = ShipmentCreationFixtures::line( $pickup, 21, 602, 'Store Pickup', null, '0.00' );
		$order  = ShipmentCreationFixtures::paid_order(
			4206,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $pickup, '0.00', 1, true ) ], 'GBP', 4, '0.00' ),
			true,
			'processing',
			null,
			'cod'
		);

		$preview = $this->preview->from_order( $order );
		self::assertSame( ManualShipmentCreationPreview::CODE_PICKUP_ONLY, $preview->code );
		self::assertFalse( $preview->can_create() );
	}

	public function test_already_created_preview_shows_existing_shipment(): void {
		$order = $this->cod_delivery_order( 4207 );
		$this->service->create_from_historical_order_for_staff( $order, 3 );
		$preview = $this->preview->from_order( $order );

		self::assertTrue( $preview->is_already_created() );
		self::assertFalse( $preview->can_create() );
		self::assertNotNull( $preview->groups[0]->existing_shipment_number );
	}

	public function test_unauthorized_create_post_is_rejected(): void {
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = false;
		$_POST['cetech_de_action'] = ShipmentsPage::ACTION_CREATE_FROM_ORDER;
		$_POST['cetech_de_nonce']  = 'test-nonce-' . ShipmentsPage::ACTION_CREATE_FROM_ORDER;
		$_POST['order_id']         = '4208';
		$this->ensure_redirect_stub();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cetech_de_test_redirect' );
		( new AdminActionHandler( new AdminNoticeService() ) )->verify_post(
			ShipmentsPage::ACTION_CREATE_FROM_ORDER,
			ShipmentsPage::ACTION_CREATE_FROM_ORDER,
			'manage_shipments',
			ShipmentsPage::SLUG
		);
	}

	public function test_invalid_nonce_is_rejected(): void {
		$_POST['cetech_de_action'] = ShipmentsPage::ACTION_CREATE_FROM_ORDER;
		$_POST['cetech_de_nonce']  = 'forged';
		$_POST['order_id']         = '4209';
		$this->ensure_redirect_stub();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cetech_de_test_redirect' );
		( new AdminActionHandler( new AdminNoticeService() ) )->verify_post(
			ShipmentsPage::ACTION_CREATE_FROM_ORDER,
			ShipmentsPage::ACTION_CREATE_FROM_ORDER,
			'manage_shipments',
			ShipmentsPage::SLUG
		);
	}

	public function test_payment_complete_after_manual_cod_creation_is_idempotent(): void {
		$order = $this->cod_delivery_order( 4210 );
		$manual = $this->service->create_from_historical_order_for_staff( $order, 3 );
		self::assertSame( ShipmentCreationOutcome::Created, $manual->outcome );
		self::assertCount( 1, $this->shipments->findByOrderId( 4210 ) );
		$shipment = $this->shipments->findByOrderId( 4210 )[0];
		self::assertCount( 1, $this->shipments->findItems( $shipment->id ) );
		self::assertCount( 1, $this->shipments->findEvents( $shipment->id ) );

		( new PaidOrderShipmentSubscriber( $this->service ) )->handle_payment_complete( 4210 );

		self::assertCount( 1, $this->shipments->findByOrderId( 4210 ) );
		self::assertCount( 1, $this->shipments->findItems( $shipment->id ) );
		$events = $this->shipments->findEvents( $shipment->id );
		self::assertCount( 1, $events );
		self::assertSame( ShipmentEventSource::Staff, $events[0]->source );
		self::assertSame( 3, $events[0]->actor_user_id );
		self::assertSame( ShipmentEventType::Created, $events[0]->event_type->knownType() );
	}

	public function test_manual_creation_is_unreviewed_activity_for_other_staff(): void {
		$GLOBALS['cetech_de_test_user_meta'] = [];
		$order = $this->cod_delivery_order( 4211 );
		$this->service->create_from_historical_order_for_staff( $order, 3 );

		$activity = new ShipmentActivityCursor( $this->flags, $this->shipments );
		$GLOBALS['cetech_de_test_user_id'] = 4;
		self::assertSame( 1, $activity->unreviewed_shipment_count_for_current_user() );

		$GLOBALS['cetech_de_test_user_id'] = 3;
		$page = new ShipmentsPage(
			$this->flags,
			new \CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery( $this->shipments ),
			null,
			null,
			null,
			null,
			$activity
		);
		ob_start();
		$page->render();
		ob_end_clean();

		self::assertSame( 0, $activity->unreviewed_shipment_count_for_current_user() );
		$GLOBALS['cetech_de_test_user_id'] = 4;
		self::assertSame( 1, $activity->unreviewed_shipment_count_for_current_user() );
	}

	public function test_create_from_order_url_uses_one_preview_entry_point(): void {
		$url = ShipmentsPage::create_from_order_url( 39741 );
		self::assertStringContainsString( 'cetech-delivery-engine-shipments', $url );
		self::assertStringContainsString( ManualShipmentCreationPreviewFactory::ORDER_ID_QUERY . '=39741', $url );
	}

	private function cod_delivery_order( int $order_id ): \WC_Order {
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
			'cod',
			[
				'billing_first_name' => 'Ada',
				'billing_last_name'  => 'Lovelace',
				'shipping_address'   => '1 Harbour Street, Kingston',
			]
		);
	}

	private function ensure_redirect_stub(): void {
		if ( ! function_exists( 'wp_safe_redirect' ) ) {
			eval(
				'namespace { function wp_safe_redirect( $location, $status = 302, $x_redirect_by = "WordPress" ) {
					$GLOBALS["cetech_de_test_redirects"][] = $location;
					throw new \\RuntimeException( "cetech_de_test_redirect" );
				} }'
			);
		}

		if ( ! function_exists( 'add_query_arg' ) ) {
			eval(
				'namespace { function add_query_arg( ...$args ) {
					if ( isset( $args[0] ) && is_array( $args[0] ) ) {
						$base = isset( $args[1] ) ? (string) $args[1] : "https://example.test/wp-admin/admin.php";
						return $base . "?" . http_build_query( $args[0] );
					}

					return "https://example.test/wp-admin/admin.php";
				} }'
			);
		}
	}
}
