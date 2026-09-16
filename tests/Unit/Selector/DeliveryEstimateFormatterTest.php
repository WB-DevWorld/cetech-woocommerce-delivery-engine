<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Selector;

use CetechDeliveryEngine\Application\Selector\DeliveryEstimateFormatter;
use PHPUnit\Framework\TestCase;

final class DeliveryEstimateFormatterTest extends TestCase {

	public function test_missing_durations_return_null_without_invented_copy(): void {
		self::assertNull( DeliveryEstimateFormatter::from_offer( [ 'public_label' => 'Same Day Delivery' ] ) );
		self::assertNull(
			DeliveryEstimateFormatter::from_offer(
				[
					'public_label'           => 'Same Day Delivery',
					'default_processing_min' => 0,
					'default_processing_max' => 0,
				]
			)
		);
	}

	public function test_singular_business_day(): void {
		self::assertSame(
			'1 business day',
			DeliveryEstimateFormatter::from_offer(
				[
					'duration_unit'          => 'business_days',
					'default_processing_min' => 1,
					'default_processing_max' => 1,
				]
			)
		);
	}

	public function test_plural_business_days(): void {
		self::assertSame(
			'2 business days',
			DeliveryEstimateFormatter::from_offer(
				[
					'duration_unit'          => 'business_days',
					'default_processing_min' => 2,
					'default_processing_max' => 2,
				]
			)
		);
	}

	public function test_singular_and_plural_calendar_days(): void {
		self::assertSame(
			'1 day',
			DeliveryEstimateFormatter::from_offer(
				[
					'duration_unit'          => 'days',
					'default_transit_min'    => 1,
					'default_transit_max'    => 1,
				]
			)
		);
		self::assertSame(
			'2 days',
			DeliveryEstimateFormatter::from_offer(
				[
					'duration_unit'          => 'days',
					'default_transit_min'    => 2,
					'default_transit_max'    => 2,
				]
			)
		);
	}

	public function test_range_stays_plural(): void {
		self::assertSame(
			'2–3 business days',
			DeliveryEstimateFormatter::from_offer(
				[
					'duration_unit'          => 'business_days',
					'default_processing_min' => 2,
					'default_processing_max' => 3,
				]
			)
		);
	}
}
