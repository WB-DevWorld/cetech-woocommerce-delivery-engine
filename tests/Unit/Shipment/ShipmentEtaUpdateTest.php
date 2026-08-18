<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentEtaService;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use CetechDeliveryEngine\Presentation\Frontend\CustomerShipmentRenderer;
use CetechDeliveryEngine\Core\Requirements;
use PHPUnit\Framework\TestCase;

final class ShipmentEtaUpdateTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private ShipmentEtaService $eta;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options'] = [
			'cetech_de_enable_shipment_records' => 1,
			'cetech_de_enable_customer_order_delivery_summary' => 1,
		];
		$GLOBALS['cetech_de_test_caps']       = [
			'manage_shipments'       => true,
			'update_shipment_status' => true,
		];
		$GLOBALS['cetech_de_test_is_admin']   = true;
		$GLOBALS['cetech_de_test_is_view_order'] = true;
		$GLOBALS['cetech_de_test_user_id']    = 9;
		$GLOBALS['cetech_de_test_transients'] = [];
		$GLOBALS['cetech_de_test_redirects']  = [];
		$_POST = [];
		$_GET  = [];

		$this->repository = ShipmentCreationFixtures::repository();
		$this->eta        = new ShipmentEtaService( $this->repository );
	}

	public function test_current_eta_can_change_while_original_stays_immutable(): void {
		$shipment = $this->store_shipment( 4301 );

		$result = $this->eta->update_current( $shipment->id, '8-10 business days', 'Weather delay', 9 );

		self::assertTrue( $result->ok );
		$saved = $this->repository->findById( $shipment->id );
		self::assertNotNull( $saved );
		self::assertSame( '5-7 business days', $saved->eta_original );
		self::assertSame( '8-10 business days', $saved->eta_current );
		self::assertSame( '25.00', $saved->customer_paid_shipping_amount );

		$events = $this->eta_events( $shipment->id );
		self::assertCount( 1, $events );
		self::assertSame( 'Weather delay', $events[0]->internal_note );
		self::assertSame( 9, $events[0]->actor_user_id );
	}

	public function test_reason_is_required_and_identical_save_creates_no_event(): void {
		$shipment = $this->store_shipment( 4302 );

		$missing = $this->eta->update_current( $shipment->id, '8-10 business days', '', 9 );
		self::assertFalse( $missing->ok );
		self::assertSame( 'reason_required', $missing->code );

		$first = $this->eta->update_current( $shipment->id, '8-10 business days', 'Delay', 9 );
		self::assertTrue( $first->ok );

		$repeat = $this->eta->update_current( $shipment->id, '8-10 business days', 'Delay again', 9 );
		self::assertTrue( $repeat->ok );
		self::assertTrue( $repeat->unchanged );
		self::assertCount( 1, $this->eta_events( $shipment->id ) );
	}

	public function test_long_eta_is_rejected_and_special_text_is_sanitized(): void {
		$shipment = $this->store_shipment( 4303 );

		$long = $this->eta->update_current( $shipment->id, str_repeat( 'a', 256 ), 'too long', 9 );
		self::assertFalse( $long->ok );
		self::assertSame( 'eta_too_long', $long->code );

		$ok = $this->eta->update_current( $shipment->id, '<b>next week</b>', 'Sanitize', 9 );
		self::assertTrue( $ok->ok );
		self::assertSame( 'next week', $this->repository->findById( $shipment->id )?->eta_current );
	}

	public function test_customer_sees_updated_estimate_without_internal_reason(): void {
		$shipment = $this->store_shipment( 4304 );
		$this->eta->update_current( $shipment->id, '10-12 business days', 'INTERNAL-ETA-REASON', 9 );

		$query = new CustomerShipmentQuery( new FeatureFlags(), $this->repository );
		$cards = $query->cards_for_order( new \WC_Order( [ 'id' => 4304 ] ) );
		self::assertTrue( $cards[0]->eta_was_updated );
		self::assertSame( '10-12 business days', $cards[0]->eta_text );

		$renderer = new CustomerShipmentRenderer( new FeatureFlags(), new Requirements(), $query );
		ob_start();
		$renderer->render( new \WC_Order( [ 'id' => 4304 ] ) );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Estimated delivery to your address', $html );
		self::assertStringContainsString( '10-12 business days', $html );
		self::assertStringContainsString( 'updated estimate', $html );
		self::assertStringNotContainsString( 'INTERNAL-ETA-REASON', $html );
		self::assertStringNotContainsString( '5-7 business days', $html );
	}

	public function test_eta_form_does_not_offer_original_eta_editing(): void {
		$shipment = $this->store_shipment( 4305 );
		$page     = new ShipmentsPage(
			new FeatureFlags(),
			new ShipmentWorkspaceQuery( $this->repository ),
			new AdminActionHandler( new AdminNoticeService() ),
			null,
			null,
			$this->eta
		);

		$_GET['shipment'] = (string) $shipment->id;
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'name="eta_current"', $html );
		self::assertStringNotContainsString( 'name="eta_original"', $html );
		self::assertStringContainsString( 'The original checkout estimate cannot be changed', $html );
	}

	public function test_eta_service_does_not_reference_resolver_or_rate_cards(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Shipment/ShipmentEtaService.php' );
		self::assertStringNotContainsString( 'EffectiveConfigurationResolver', $source );
		self::assertStringNotContainsString( 'RateCard', $source );
		self::assertStringNotContainsString( 'ProductException', $source );
		self::assertStringNotContainsString( 'wc_get_product', $source );
	}

	private function store_shipment( int $order_id ): Shipment {
		$created = $this->repository->create(
			Shipment::create(
				$order_id,
				'g-' . $order_id,
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
	private function eta_events( int $shipment_id ): array {
		return array_values(
			array_filter(
				$this->repository->findEvents( $shipment_id ),
				static fn ( ShipmentEvent $event ): bool => $event->event_type->is( ShipmentEventType::EtaUpdated )
			)
		);
	}
}
