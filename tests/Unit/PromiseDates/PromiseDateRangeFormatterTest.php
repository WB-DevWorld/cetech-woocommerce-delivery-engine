<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\PromiseDates;

use CetechDeliveryEngine\Application\PromiseDates\PromiseDateRangeFormatter;
use CetechDeliveryEngine\Application\PromiseDates\PromiseDisplay;
use PHPUnit\Framework\TestCase;

/**
 * Authorization decision 3: the default wording is a date range such as
 * "Delivery between Tue 2 Dec and Thu 4 Dec", in the site time zone, the same on every surface.
 */
final class PromiseDateRangeFormatterTest extends TestCase {

	private function formatter( string $now = '2026-11-28 10:00:00' ): PromiseDateRangeFormatter {
		return new PromiseDateRangeFormatter( static fn (): \DateTimeImmutable => new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) ) );
	}

	public function test_default_wording_is_a_date_range(): void {
		self::assertSame(
			'Delivery between Tue 1 Dec and Thu 3 Dec',
			$this->formatter()->format( PromiseDisplay::window( '2026-12-01', '2026-12-03', 'Africa/Accra', 'r:1' ) )
		);
	}

	public function test_one_day_window_reads_as_a_single_date(): void {
		self::assertSame( 'Delivery on Wed 2 Dec', $this->formatter()->format( PromiseDisplay::window( '2026-12-02', '2026-12-02', 'UTC', 'r:1' ) ) );
	}

	public function test_year_appears_only_when_the_window_leaves_the_current_year(): void {
		self::assertSame(
			'Delivery between Wed 30 Dec 2026 and Sat 2 Jan 2027',
			$this->formatter()->format( PromiseDisplay::window( '2026-12-30', '2027-01-02', 'UTC', 'r:1' ) )
		);
		self::assertSame(
			'Delivery between Mon 4 Jan 2027 and Wed 6 Jan 2027',
			$this->formatter()->format( PromiseDisplay::window( '2027-01-04', '2027-01-06', 'UTC', 'r:1' ) )
		);
	}

	public function test_calendar_days_never_shift_with_the_zone_offset(): void {
		foreach ( [ 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'Asia/Kolkata', 'UTC' ] as $zone ) {
			self::assertSame(
				'Delivery between Tue 1 Dec and Thu 3 Dec',
				$this->formatter()->format( PromiseDisplay::window( '2026-12-01', '2026-12-03', $zone, 'r:1' ) ),
				$zone
			);
		}
	}

	public function test_the_current_year_is_read_in_the_promise_zone(): void {
		// 31 Dec 23:30 UTC is already 1 Jan in Kiritimati (UTC+14): a January window there is "this year".
		self::assertSame(
			'Delivery between Mon 4 Jan and Wed 6 Jan',
			$this->formatter( '2026-12-31 23:30:00' )->format( PromiseDisplay::window( '2027-01-04', '2027-01-06', 'Pacific/Kiritimati', 'r:1' ) )
		);
	}
}
