<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContext;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentLineContext;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\ShipmentPlan;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HistoricalShipmentPlannerTest extends TestCase {

	private HistoricalShipmentPlanner $planner;

	protected function setUp(): void {
		parent::setUp();
		$this->planner = new HistoricalShipmentPlanner();
	}

	public function test_one_delivery_group_produces_one_shipment_plan(): void {
		$group_id = 'international|delivery|12';
		$result   = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[ ShipmentCreationFixtures::line( $group_id ) ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id ) ] ),
				[ ShipmentCreationFixtures::shipping_line( $group_id ) ]
			)
		);

		self::assertTrue( $result->ok );
		self::assertCount( 1, $result->plans );
		self::assertSame( 0, $result->pickup_groups_skipped );
		$plan = $result->plans[0];
		self::assertInstanceOf( ShipmentPlan::class, $plan );
		self::assertSame( $group_id, $plan->delivery_group_id );
		self::assertSame( '1001-D1', $plan->shipment_number );
		self::assertSame( 'international', $plan->fulfilment_availability );
		self::assertSame( 'delivery', $plan->fulfilment_choice );
		self::assertSame( 12, $plan->delivery_offer_id );
		self::assertCount( 1, $plan->items );
		self::assertSame( 501, $plan->items[0]->order_item_id );
	}

	public function test_multiple_delivery_groups_produce_multiple_plans(): void {
		$air = 'international|delivery|12';
		$sea = 'international|delivery|13';
		$result = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[
					ShipmentCreationFixtures::line( $air, 10, 501, 'International Air', '5-7 business days' ),
					ShipmentCreationFixtures::line( $sea, 11, 502, 'International Sea', '30-45 business days' ),
				],
				ShipmentCreationFixtures::package(
					[
						ShipmentCreationFixtures::group( $air, '25.00', 1 ),
						ShipmentCreationFixtures::group( $sea, '18.00', 2 ),
					],
					'GBP',
					4,
					'43.00'
				),
				[
					ShipmentCreationFixtures::shipping_line( $air, '25.00' ),
					ShipmentCreationFixtures::shipping_line( $sea, '18.00' ),
				]
			)
		);

		self::assertTrue( $result->ok );
		self::assertCount( 2, $result->plans );
		self::assertSame( $air, $result->plans[0]->delivery_group_id );
		self::assertSame( $sea, $result->plans[1]->delivery_group_id );
		self::assertSame( '1001-D1', $result->plans[0]->shipment_number );
		self::assertSame( '1001-D2', $result->plans[1]->shipment_number );
	}

	public function test_air_and_sea_historical_groups_remain_separate(): void {
		$air = 'international|delivery|12';
		$sea = 'international|delivery|13';
		$result = $this->planner->plan(
			$this->two_group_context( $air, $sea )
		);

		self::assertCount( 2, $result->plans );
		self::assertNotSame( $result->plans[0]->delivery_group_id, $result->plans[1]->delivery_group_id );
		self::assertSame( 'International Air', $result->plans[0]->delivery_offer_public_label );
		self::assertSame( 'International Sea', $result->plans[1]->delivery_offer_public_label );
	}

	public function test_local_historical_group_is_planned(): void {
		$group_id = 'local|delivery|4';
		$result   = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[ ShipmentCreationFixtures::line( $group_id, 20, 601, 'Local Courier', '1-2 business days' ) ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id, '8.50' ) ] ),
				[ ShipmentCreationFixtures::shipping_line( $group_id, '8.50' ) ]
			)
		);

		self::assertTrue( $result->ok );
		self::assertCount( 1, $result->plans );
		self::assertSame( 'local', $result->plans[0]->fulfilment_availability );
		self::assertSame( '8.5000', $result->plans[0]->customer_paid_shipping_amount );
		self::assertSame( '1-2 business days', $result->plans[0]->eta_original );
	}

	public function test_pickup_group_is_skipped(): void {
		$delivery = 'local|delivery|4';
		$pickup   = 'local|store_pickup|pickup';
		$result   = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[
					ShipmentCreationFixtures::line( $delivery, 20, 601, 'Local Courier', '1-2 business days' ),
					ShipmentCreationFixtures::line( $pickup, 21, 602, 'Store Pickup', null, '0.00' ),
				],
				ShipmentCreationFixtures::package(
					[
						ShipmentCreationFixtures::group( $delivery, '8.50', 1 ),
						ShipmentCreationFixtures::group( $pickup, '0.00', 2, true ),
					]
				),
				[ ShipmentCreationFixtures::shipping_line( $delivery, '8.50' ) ]
			)
		);

		self::assertTrue( $result->ok );
		self::assertSame( 1, $result->pickup_groups_skipped );
		self::assertCount( 1, $result->plans );
		self::assertSame( $delivery, $result->plans[0]->delivery_group_id );
	}

	public function test_pickup_only_is_success_with_zero_plans(): void {
		$pickup = 'in_store|store_pickup|pickup';
		$result = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[ ShipmentCreationFixtures::line( $pickup, 21, 602, 'Store Pickup', null, '0.00' ) ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $pickup, '0.00', 1, true ) ], 'GBP', 4, '0.00' ),
				[]
			)
		);

		self::assertTrue( $result->ok );
		self::assertSame( [], $result->plans );
		self::assertSame( 1, $result->pickup_groups_skipped );
		self::assertTrue( $result->is_pickup_only() );
	}

	public function test_same_saved_snapshot_always_produces_same_plan(): void {
		$context = ShipmentCreationFixtures::context(
			[ ShipmentCreationFixtures::line( 'international|delivery|12' ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( 'international|delivery|12' ) ] ),
			[ ShipmentCreationFixtures::shipping_line( 'international|delivery|12' ) ]
		);

		$first  = $this->planner->plan( $context );
		$second = $this->planner->plan( $context );

		self::assertEquals( $first, $second );
	}

	public function test_planner_has_no_current_configuration_dependencies(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Application/Shipment/HistoricalShipmentPlanner.php'
		);

		self::assertStringNotContainsString( 'EffectiveConfigurationResolver', $source );
		self::assertStringNotContainsString( 'RateCardRepository', $source );
		self::assertStringNotContainsString( 'ProductException', $source );
		self::assertStringNotContainsString( 'SiteWideDefaults', $source );
		self::assertStringNotContainsString( 'DeliveryGroupIdentity::fromIntent', $source );
		self::assertStringNotContainsString( 'DeliveryGroupIdentity::fromCartItem', $source );

		$reflection = new ReflectionClass( HistoricalShipmentPlanner::class );
		self::assertCount( 0, $reflection->getConstructor()?->getParameters() ?? [] );
	}

	public function test_historical_public_label_eta_and_paid_amount_are_preserved(): void {
		$group_id = 'international|delivery|12';
		$result   = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[ ShipmentCreationFixtures::line( $group_id, 10, 501, 'Air — renamed later must not apply', '5-7 business days' ) ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id, '25.00' ) ] ),
				[ ShipmentCreationFixtures::shipping_line( $group_id, '25.00' ) ]
			)
		);

		$plan = $result->plans[0];
		self::assertSame( 'Air — renamed later must not apply', $plan->delivery_offer_public_label );
		self::assertSame( '5-7 business days', $plan->eta_original );
		self::assertSame( '25.0000', $plan->customer_paid_shipping_amount );
		self::assertSame( 'GBP', $plan->currency_code );
		self::assertSame( 4, $plan->destination_zone_id );
		self::assertSame( 12, $plan->delivery_offer_id );
		self::assertSame( 9, $plan->rate_card_id );
		self::assertSame( 'AIR-INT', $plan->rate_card_code );
	}

	public function test_malformed_snapshot_fails_safely(): void {
		$unreadable = $this->planner->plan(
			ShipmentCreationFixtures::context( [], null, [], 1001, '1001', true, true )
		);
		self::assertFalse( $unreadable->ok );
		self::assertSame( ShipmentCreationErrorCode::MalformedGroupSnapshot, $unreadable->error_code );

		$bad_group = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[
					new HistoricalShipmentLineContext(
						501,
						'Widget',
						true,
						false,
						new OrderDeliveryLineSnapshot(
							ProductDeliverySelectionIntent::CONTRACT_VERSION,
							OrderDeliverySnapshot::VERSION,
							10,
							null,
							'international',
							'delivery',
							12,
							'Air',
							null,
							'5 days',
							null,
							4,
							1,
							'GBP',
							'25.00',
							OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
							9,
							'AIR',
							'2026-08-18 12:00:00',
							'not-a-valid-group'
						)
					),
				]
			)
		);
		self::assertFalse( $bad_group->ok );
		self::assertSame( ShipmentCreationErrorCode::MalformedGroupSnapshot, $bad_group->error_code );
	}

	public function test_missing_order_item_fails_safely(): void {
		$group_id = 'international|delivery|12';
		$result   = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id ) ] )
			)
		);

		self::assertFalse( $result->ok );
		self::assertSame( ShipmentCreationErrorCode::MissingOrderItem, $result->error_code );
	}

	public function test_delivery_line_without_line_snapshot_fails_even_when_package_exists(): void {
		$group_id = 'in_warehouse|delivery|1';
		$result   = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[
					new HistoricalShipmentLineContext( 1, 'QA Warehouse Chair', false, false, null ),
				],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id, '15.00' ) ] ),
				[ ShipmentCreationFixtures::shipping_line( $group_id, '15.00' ) ]
			)
		);

		self::assertFalse( $result->ok );
		self::assertSame( ShipmentCreationErrorCode::MissingOrderItem, $result->error_code );
	}

	public function test_group_item_mismatch_fails_safely(): void {
		$group_id = 'international|delivery|12';
		$line     = ShipmentCreationFixtures::line( $group_id );
		$mismatch = new HistoricalShipmentLineContext(
			$line->order_item_id,
			$line->product_name,
			true,
			false,
			new OrderDeliveryLineSnapshot(
				$line->snapshot->contract_version,
				$line->snapshot->snapshot_version,
				$line->snapshot->product_id,
				$line->snapshot->variation_id,
				'local',
				$line->snapshot->fulfilment_choice,
				$line->snapshot->delivery_offer_id,
				$line->snapshot->delivery_offer_public_label,
				$line->snapshot->delivery_offer_public_description,
				$line->snapshot->estimate_text,
				$line->snapshot->rule_id,
				$line->snapshot->destination_zone_id,
				$line->snapshot->quantity,
				$line->snapshot->currency_code,
				$line->snapshot->quoted_amount,
				$line->snapshot->quote_status,
				$line->snapshot->rate_card_id,
				$line->snapshot->rate_card_code,
				$line->snapshot->snapshotted_at,
				$group_id
			)
		);

		$result = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[ $mismatch ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id ) ] ),
				[ ShipmentCreationFixtures::shipping_line( $group_id ) ]
			)
		);

		self::assertFalse( $result->ok );
		self::assertSame( ShipmentCreationErrorCode::GroupItemMismatch, $result->error_code );
	}

	public function test_disagreeing_historical_shipping_amounts_fail_closed(): void {
		$group_id = 'international|delivery|12';
		$result   = $this->planner->plan(
			ShipmentCreationFixtures::context(
				[ ShipmentCreationFixtures::line( $group_id ) ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id, '25.00' ) ] ),
				[ ShipmentCreationFixtures::shipping_line( $group_id, '18.00' ) ]
			)
		);

		self::assertFalse( $result->ok );
		self::assertSame( ShipmentCreationErrorCode::ShippingAmountMismatch, $result->error_code );
	}

	/**
	 * @param string $air
	 * @param string $sea
	 */
	private function two_group_context( string $air, string $sea ): HistoricalOrderShipmentContext {
		return ShipmentCreationFixtures::context(
			[
				ShipmentCreationFixtures::line( $air, 10, 501, 'International Air', '5-7 business days' ),
				ShipmentCreationFixtures::line( $sea, 11, 502, 'International Sea', '30-45 business days' ),
			],
			ShipmentCreationFixtures::package(
				[
					ShipmentCreationFixtures::group( $air, '25.00', 1 ),
					ShipmentCreationFixtures::group( $sea, '18.00', 2 ),
				],
				'GBP',
				4,
				'43.00'
			),
			[
				ShipmentCreationFixtures::shipping_line( $air, '25.00' ),
				ShipmentCreationFixtures::shipping_line( $sea, '18.00' ),
			]
		);
	}
}
