<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Presentation\Admin\ShipmentPresentation;
use CetechDeliveryEngine\Presentation\Frontend\CustomerShipmentRenderer;
use PHPUnit\Framework\TestCase;

final class ShipmentHistoryActorPresentationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_users'] = [];
	}

	public function test_staff_event_resolves_wordpress_display_name(): void {
		$GLOBALS['cetech_de_test_users'][3] = (object) [
			'display_name' => 'Jane Love',
			'user_email'   => 'secret@example.test',
			'user_login'   => 'jlove',
		];

		$label = ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, 3 ) );

		self::assertSame( 'Jane Love (Staff)', $label );
		self::assertStringNotContainsString( 'secret@example.test', $label );
		self::assertStringNotContainsString( 'jlove', $label );
	}

	public function test_system_event_is_labelled_system(): void {
		self::assertSame(
			'System',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::System, null ) )
		);
	}

	public function test_missing_staff_account_degrades_safely(): void {
		self::assertSame(
			'Former or unknown staff account (Staff)',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, 99 ) )
		);
	}

	public function test_staff_event_without_actor_stays_staff(): void {
		self::assertSame(
			'Staff',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, null ) )
		);
	}

	public function test_customer_renderer_does_not_reference_actor_identity(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CustomerShipmentRenderer.php'
		);

		self::assertStringNotContainsString( 'history_actor_label', $source );
		self::assertStringNotContainsString( 'actor_user_id', $source );
		self::assertStringNotContainsString( 'get_userdata', $source );
	}

	private function event( ShipmentEventSource $source, ?int $actor_user_id ): ShipmentEvent {
		return ShipmentEvent::create(
			1,
			ShipmentEventType::StatusChanged,
			$source,
			ShipmentStatus::AwaitingFulfilment,
			ShipmentStatus::Processing,
			null,
			null,
			$actor_user_id
		);
	}
}
