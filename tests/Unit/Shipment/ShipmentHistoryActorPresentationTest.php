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
		$GLOBALS['cetech_de_test_users']      = [];
		$GLOBALS['cetech_de_test_user_meta']  = [];
		$GLOBALS['cetech_de_test_edit_users'] = [];
		$GLOBALS['cetech_de_test_caps']       = [];
	}

	public function test_staff_event_prefers_full_name_and_user_id(): void {
		$GLOBALS['cetech_de_test_users'][3] = (object) [
			'display_name' => 'Jane',
			'user_email'   => 'secret@example.test',
			'user_login'   => 'jlove',
		];
		$GLOBALS['cetech_de_test_user_meta'][3] = [
			'first_name' => 'Jane',
			'last_name'  => 'Love',
		];

		$label = ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, 3 ) );

		self::assertSame( 'Jane Love (Staff · User #3)', $label );
		self::assertStringNotContainsString( 'secret@example.test', $label );
		self::assertStringNotContainsString( 'jlove', $label );
	}

	public function test_staff_event_falls_back_to_display_name(): void {
		$GLOBALS['cetech_de_test_users'][4] = (object) [
			'display_name' => 'Ops Desk',
		];

		self::assertSame(
			'Ops Desk (Staff · User #4)',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, 4 ) )
		);
	}

	public function test_duplicate_first_names_remain_distinguishable_by_user_id(): void {
		$GLOBALS['cetech_de_test_users'][3] = (object) [ 'display_name' => 'Jane' ];
		$GLOBALS['cetech_de_test_users'][8] = (object) [ 'display_name' => 'Jane' ];

		self::assertSame(
			'Jane (Staff · User #3)',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, 3 ) )
		);
		self::assertSame(
			'Jane (Staff · User #8)',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, 8 ) )
		);
	}

	public function test_system_event_is_labelled_system(): void {
		self::assertSame(
			'System',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::System, null ) )
		);
	}

	public function test_missing_staff_account_includes_user_id(): void {
		self::assertSame(
			'Former or unknown staff account (User #99)',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, 99 ) )
		);
	}

	public function test_staff_event_without_actor_stays_staff(): void {
		self::assertSame(
			'Staff',
			ShipmentPresentation::history_actor_label( $this->event( ShipmentEventSource::Staff, null ) )
		);
	}

	public function test_history_html_links_name_only_when_user_can_be_edited(): void {
		$GLOBALS['cetech_de_test_users'][3] = (object) [
			'display_name' => 'Jane Love',
		];
		$GLOBALS['cetech_de_test_user_meta'][3] = [
			'first_name' => 'Jane',
			'last_name'  => 'Love',
		];
		$event = $this->event( ShipmentEventSource::Staff, 3 );

		$GLOBALS['cetech_de_test_edit_users'][3] = false;
		$plain = ShipmentPresentation::history_actor_html( $event );
		self::assertSame( 'Jane Love (Staff · User #3)', $plain );
		self::assertStringNotContainsString( '<a ', $plain );
		self::assertStringNotContainsString( 'author.php', $plain );

		$GLOBALS['cetech_de_test_edit_users'][3] = true;
		$html = ShipmentPresentation::history_actor_html( $event );
		self::assertStringContainsString( 'user-edit.php?user_id=3', $html );
		self::assertStringContainsString( '>Jane Love</a>', $html );
		self::assertStringContainsString( '(Staff · User #3)', $html );
		self::assertStringNotContainsString( 'author.php', $html );
	}

	public function test_customer_renderer_does_not_reference_actor_identity(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Presentation/Frontend/CustomerShipmentRenderer.php'
		);

		self::assertStringNotContainsString( 'history_actor_label', $source );
		self::assertStringNotContainsString( 'history_actor_html', $source );
		self::assertStringNotContainsString( 'actor_user_id', $source );
		self::assertStringNotContainsString( 'get_userdata', $source );
		self::assertStringNotContainsString( 'User #', $source );
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
