<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\ShipmentDispatchDate;
use PHPUnit\Framework\TestCase;

final class ShipmentDispatchDateTimezoneTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [
			'date_format' => 'Y-m-d',
		];
	}

	protected function tearDown(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		parent::tearDown();
	}

	public function test_negative_utc_offset_preserves_entered_calendar_date(): void {
		$GLOBALS['cetech_de_test_options']['timezone_string'] = 'America/New_York';
		$GLOBALS['cetech_de_test_options']['gmt_offset']      = -5;

		$stored = ShipmentDispatchDate::from_staff_date( '2026-08-20' );

		self::assertNotNull( $stored );
		self::assertSame( '2026-08-20', ShipmentDispatchDate::to_staff_date( $stored ) );
		self::assertStringStartsWith( '2026-08-20', ShipmentDispatchDate::to_display( $stored ) );
		self::assertSame( '2026-08-20 04:00:00', $stored );
	}

	public function test_positive_utc_offset_preserves_entered_calendar_date(): void {
		$GLOBALS['cetech_de_test_options']['timezone_string'] = 'Pacific/Auckland';
		$GLOBALS['cetech_de_test_options']['gmt_offset']      = 12;

		$stored = ShipmentDispatchDate::from_staff_date( '2026-08-20' );

		self::assertNotNull( $stored );
		self::assertSame( '2026-08-20', ShipmentDispatchDate::to_staff_date( $stored ) );
		self::assertStringStartsWith( '2026-08-20', ShipmentDispatchDate::to_display( $stored ) );
		self::assertSame( '2026-08-19 12:00:00', $stored );
	}

	public function test_numeric_gmt_offset_without_timezone_string_preserves_date(): void {
		$GLOBALS['cetech_de_test_options']['timezone_string'] = '';
		$GLOBALS['cetech_de_test_options']['gmt_offset']      = 5.5;

		$stored = ShipmentDispatchDate::from_staff_date( '2026-08-20' );

		self::assertNotNull( $stored );
		self::assertSame( '2026-08-20', ShipmentDispatchDate::to_staff_date( $stored ) );
		self::assertSame( '2026-08-19 18:30:00', $stored );
	}

	public function test_empty_and_invalid_dates(): void {
		self::assertNull( ShipmentDispatchDate::from_staff_date( '' ) );
		$this->expectException( \InvalidArgumentException::class );
		ShipmentDispatchDate::from_staff_date( '20-08-2026' );
	}
}
