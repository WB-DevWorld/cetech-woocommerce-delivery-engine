<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationOutcome;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\NeedsAttentionPage;
use CetechDeliveryEngine\Presentation\Admin\ShipmentCreationErrorMessages;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\InMemoryAuditLogRepository;
use PHPUnit\Framework\TestCase;

final class ShipmentCreationFailureRetryTest extends TestCase {

	private FeatureFlags $flags;

	private ShipmentCreationFailureStore $failures;

	private InMemoryAuditLogRepository $audit;

	private ShipmentService $service;

	private ShipmentCreationIssueQuery $issues;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options']   = [];
		$GLOBALS['cetech_de_test_wc_orders'] = [];
		$GLOBALS['cetech_de_test_caps']      = [];
		$GLOBALS['cetech_de_test_is_admin']  = true;
		$_POST                               = [];

		$this->ensure_redirect_stub();

		$this->flags    = new FeatureFlags();
		$this->failures = new ShipmentCreationFailureStore();
		$this->audit    = new InMemoryAuditLogRepository();
		$this->service  = new ShipmentService(
			$this->flags,
			new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() ),
			new HistoricalShipmentPlanner(),
			ShipmentCreationFixtures::repository(),
			$this->failures,
			$this->audit,
			new Logger()
		);
		$this->issues = new ShipmentCreationIssueQuery( $this->failures );
		$this->flags->set( 'enable_shipment_records', true );
	}

	public function test_genuine_failure_is_queryable_for_needs_attention(): void {
		$order = $this->malformed_order( 3001 );
		$this->service->create_for_paid_order( $order );

		$items = $this->issues->list();
		self::assertCount( 1, $items );
		self::assertSame( 3001, $items[0]['order_id'] );
		self::assertSame( ShipmentCreationErrorCode::MalformedGroupSnapshot->value, $items[0]['error_code'] );
		self::assertSame(
			ShipmentCreationErrorMessages::describe( ShipmentCreationErrorCode::MalformedGroupSnapshot ),
			$items[0]['reason']
		);
		self::assertStringNotContainsString( 'SQL', $items[0]['reason'] );
		self::assertStringNotContainsString( 'Exception', $items[0]['reason'] );
		self::assertStringNotContainsString( 'stack', strtolower( $items[0]['reason'] ) );
		self::assertDoesNotMatchRegularExpression( '/\bSELECT\b|\bINSERT\b|RuntimeException/', $items[0]['reason'] );
	}

	public function test_authorized_retry_succeeds_and_resolves_current_issue(): void {
		$order = $this->malformed_order( 3002 );
		$failed = $this->service->create_for_paid_order( $order );
		self::assertSame( ShipmentCreationOutcome::InvalidSnapshot, $failed->outcome );
		self::assertCount( 1, $this->issues->list() );

		$repaired_order = $this->valid_order( 3002 );
		$retry          = $this->service->create_for_paid_order( $repaired_order, ShipmentEventSource::Retry );

		self::assertSame( ShipmentCreationOutcome::Created, $retry->outcome );
		self::assertTrue( $retry->is_success() );
		self::assertFalse( $this->failures->is_failed( $repaired_order ) );
		self::assertSame( [], $this->issues->list() );
		self::assertSame( 'shipment_creation_failed', $this->audit->entries[0]['action'] );
		self::assertSame( 'shipment_creation_retry_succeeded', $this->audit->entries[1]['action'] );
	}

	public function test_retry_remains_idempotent(): void {
		$order = $this->valid_order( 3003 );
		$first = $this->service->create_for_paid_order( $order, ShipmentEventSource::Retry );
		$again = $this->service->create_for_paid_order( $order, ShipmentEventSource::Retry );

		self::assertSame( ShipmentCreationOutcome::Created, $first->outcome );
		self::assertSame( ShipmentCreationOutcome::AlreadyExistsComplete, $again->outcome );
		self::assertSame( [], $this->issues->list() );
	}

	public function test_unauthorized_retry_is_rejected(): void {
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = false;
		$_POST['cetech_de_action'] = NeedsAttentionPage::ACTION_RETRY_SHIPMENT;
		$_POST['cetech_de_nonce']  = 'test-nonce-' . NeedsAttentionPage::ACTION_RETRY_SHIPMENT;
		$_POST['order_id']         = '3004';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cetech_de_test_redirect' );
		$this->action_handler()->verify_post(
			NeedsAttentionPage::ACTION_RETRY_SHIPMENT,
			NeedsAttentionPage::ACTION_RETRY_SHIPMENT,
			'manage_shipments',
			NeedsAttentionPage::SLUG
		);
	}

	public function test_invalid_nonce_is_rejected(): void {
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = true;
		$_POST['cetech_de_action'] = NeedsAttentionPage::ACTION_RETRY_SHIPMENT;
		$_POST['cetech_de_nonce']  = 'forged';
		$_POST['order_id']         = '3005';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cetech_de_test_redirect' );
		$this->action_handler()->verify_post(
			NeedsAttentionPage::ACTION_RETRY_SHIPMENT,
			NeedsAttentionPage::ACTION_RETRY_SHIPMENT,
			'manage_shipments',
			NeedsAttentionPage::SLUG
		);
	}

	public function test_error_messages_are_complete_sentences_without_raw_exceptions(): void {
		foreach ( ShipmentCreationErrorCode::cases() as $code ) {
			$message = ShipmentCreationErrorMessages::describe( $code );
			self::assertMatchesRegularExpression( '/^[A-Z].+\.$/', $message );
			self::assertStringNotContainsString( $code->value, $message );
			self::assertStringNotContainsString( 'Exception', $message );
			self::assertStringNotContainsString( 'wpdb', $message );
		}
	}

	private function action_handler(): AdminActionHandler {
		return new AdminActionHandler( new AdminNoticeService() );
	}

	private function malformed_order( int $order_id ): \WC_Order {
		$order = ShipmentCreationFixtures::paid_order( $order_id, [], [], null, true, 'processing' );
		$order->update_meta_data( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, '{broken' );
		$order->update_meta_data( \CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, '1' );
		$order->save();

		return $order;
	}

	private function valid_order( int $order_id ): \WC_Order {
		$group_id = 'international|delivery|12';
		$line     = ShipmentCreationFixtures::line( $group_id );

		return ShipmentCreationFixtures::paid_order(
			$order_id,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[ ShipmentCreationFixtures::wc_shipping_line( $group_id ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id ) ] )
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
					return "https://example.test/wp-admin/admin.php";
				} }'
			);
		}

		if ( ! function_exists( 'is_user_logged_in' ) ) {
			eval( 'namespace { function is_user_logged_in(): bool { return false; } }' );
		}
	}
}
