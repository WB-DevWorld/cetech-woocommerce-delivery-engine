<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\ServicePromise\Configuration\PromiseStaffDraft;
use PHPUnit\Framework\TestCase;

final class PromiseStaffDraftTest extends TestCase {

	public function test_elapsed_store_rule_uses_the_offer_name_without_hours_or_a_typed_identifier(): void {
		$draft = PromiseStaffDraft::assemble(
			[
				'unit' => 'elapsed_minutes',
				'processing_min' => '30',
				'processing_max' => '90',
				'delivery_min' => '60',
				'delivery_max' => '180',
				'estimate_required' => 'no',
				'scope' => 'global',
			],
			[ 'public_label' => 'Local van', 'internal_code' => 'local-van', 'route' => 'local_delivery' ]
		);

		self::assertSame( [], $draft['missing'] );
		self::assertNull( $draft['calendar'] );
		$facts = $draft['policy']->private_facts();
		self::assertSame( 'Local van', $facts['service']['customer_label'] );
		self::assertSame( 'global', $facts['scope']['kind'] );
		self::assertNull( $facts['scope']['target_id'] );
		self::assertFalse( $facts['promise_required'] );
		self::assertSame( 'none', $facts['capacity_mode'] );
	}

	public function test_business_days_without_entered_hours_are_not_invented(): void {
		$draft = PromiseStaffDraft::assemble(
			[
				'unit' => 'business_days',
				'processing_min' => '1',
				'processing_max' => '2',
				'delivery_min' => '1',
				'delivery_max' => '3',
				'estimate_required' => 'yes',
				'scope' => 'global',
				'timezone' => 'Africa/Douala',
			],
			[ 'public_label' => 'Local van', 'internal_code' => 'local-van', 'route' => 'local_delivery' ]
		);

		self::assertNotEmpty( $draft['missing'] );
		self::assertNull( $draft['policy'] );
		self::assertNull( $draft['calendar'] );
	}

	public function test_blank_weekdays_stay_closed_when_one_day_is_entered(): void {
		$draft = PromiseStaffDraft::assemble(
			[
				'unit' => 'business_days',
				'processing_min' => '0',
				'processing_max' => '1',
				'delivery_min' => '1',
				'delivery_max' => '2',
				'estimate_required' => 'no',
				'scope' => 'product',
				'target_id' => '42',
				'timezone' => 'Africa/Douala',
				'mon_open' => '09:00',
				'mon_close' => '17:00',
			],
			[ 'public_label' => 'Store pickup', 'internal_code' => 'counter', 'route' => 'store_pickup' ]
		);

		self::assertSame( [], $draft['missing'] );
		$calendar = $draft['calendar']->private_facts();
		self::assertSame( '09:00', $calendar['weekly_openings']['mon'][0]['open'] );
		self::assertSame( [], $calendar['weekly_openings']['sun'] );
		self::assertSame( [], $calendar['closed_dates'] );
		self::assertSame( 'pickup', $draft['policy']->private_facts()['endpoint_kind'] );
		self::assertSame( 42, $draft['policy']->private_facts()['scope']['target_id'] );
	}

	public function test_air_or_sea_option_is_not_rewritten_into_a_local_rule(): void {
		$draft = PromiseStaffDraft::assemble(
			[
				'unit' => 'elapsed_minutes',
				'processing_min' => '1',
				'processing_max' => '1',
				'delivery_min' => '1',
				'delivery_max' => '1',
				'estimate_required' => 'no',
				'scope' => 'global',
			],
			[ 'public_label' => 'Air freight', 'internal_code' => 'air-freight', 'route' => 'air' ]
		);

		self::assertNotEmpty( $draft['missing'] );
		self::assertNull( $draft['policy'] );
	}
}
