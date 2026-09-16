<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Order;

use CetechDeliveryEngine\Application\Order\OrderDeliveryLineSnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionIntent;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentLineContext;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Tests\Unit\CustomerContext\PerItemContextFixtures;
use CetechDeliveryEngine\Tests\Unit\Shipment\ShipmentCreationFixtures;
use PHPUnit\Framework\TestCase;
use WC_Order_Item_Product;

final class OrderDeliverySnapshotV2Test extends TestCase {

	public function test_v1_snapshot_is_still_readable(): void {
		$v1 = new OrderDeliveryLineSnapshot(
			ProductDeliverySelectionIntent::CONTRACT_VERSION,
			OrderDeliverySnapshot::VERSION,
			16,
			null,
			'in_warehouse',
			'delivery',
			1,
			'QA Local Standard',
			null,
			'3 days',
			null,
			1,
			1,
			'GHS',
			'15.0000',
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			null,
			null,
			'2026-09-01T00:00:00+00:00',
			'in_warehouse|delivery|1'
		);

		$item = new WC_Order_Item_Product(
			[
				'id'   => 1,
				'meta' => [
					OrderDeliverySnapshot::META_LINE_SNAPSHOT         => wp_json_encode( $v1->toArray() ),
					OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => OrderDeliverySnapshot::VERSION,
				],
			]
		);

		$result = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( '', $result->error );
		self::assertNotNull( $result->snapshot );
		self::assertSame( '1', $result->snapshot->snapshot_version );
		self::assertSame( 'in_warehouse|delivery|1', $result->snapshot->delivery_group_id );
		self::assertNull( $result->snapshot->delivery_location_identity );
	}

	public function test_v2_snapshot_round_trip(): void {
		$ctx      = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
		$group_id = DeliveryGroupIdentity::forHistorical(
			[ 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 1 ],
			$ctx
		);
		$snapshot = $this->v2_line( $ctx, (string) $group_id );

		$item = new WC_Order_Item_Product(
			[
				'id'   => 9,
				'meta' => [
					OrderDeliverySnapshot::META_LINE_SNAPSHOT         => wp_json_encode( $snapshot->toArray() ),
					OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION => OrderDeliverySnapshot::VERSION_V2,
				],
			]
		);

		$result = ( new OrderDeliverySnapshotReader() )->read_line( $item );
		self::assertSame( '', $result->error );
		self::assertNotNull( $result->snapshot );
		self::assertSame( '2', $result->snapshot->snapshot_version );
		self::assertSame( $ctx->delivery_location_identity, $result->snapshot->delivery_location_identity );
		self::assertSame( '12 Boundary Rd', $result->snapshot->delivery_address['address_1'] ?? null );
		self::assertSame( 'Ama', $result->snapshot->delivery_address['recipient']['first_name'] ?? null );
		self::assertSame( $group_id, $result->snapshot->delivery_group_id );
		self::assertDoesNotMatchRegularExpression( '/Boundary|Ama|0244/i', (string) $result->snapshot->delivery_group_id );
	}

	public function test_v2_freezes_per_line_destination(): void {
		$east    = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
		$spintex = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliverySpintex() );
		$intent  = [ 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 1 ];

		$east_id    = DeliveryGroupIdentity::forHistorical( $intent, $east );
		$spintex_id = DeliveryGroupIdentity::forHistorical( $intent, $spintex );

		self::assertNotSame( $east_id, $spintex_id );
		self::assertSame( $east->delivery_location_identity, $this->v2_line( $east, (string) $east_id )->delivery_location_identity );
		self::assertNotSame(
			$this->v2_line( $east, (string) $east_id )->delivery_location_identity,
			$this->v2_line( $spintex, (string) $spintex_id )->delivery_location_identity
		);
	}

	public function test_v2_pickup_freezes_pickup_location_id(): void {
		$ctx      = CustomerCartContext::pickup( 4 );
		$group_id = DeliveryGroupIdentity::forHistorical(
			[ 'fulfilment_availability' => 'in_store', 'fulfilment_choice' => 'store_pickup' ],
			$ctx
		);
		$snapshot = $this->v2_pickup( $ctx, (string) $group_id );

		self::assertSame( 4, $snapshot->pickup_location_id );
		self::assertSame( 'in_store|store_pickup|p4|pickup', $group_id );
		self::assertTrue( DeliveryGroupIdentity::is_pickup_group( (string) $group_id ) );
	}

	public function test_historical_group_identity_is_non_pii(): void {
		$ctx = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
		$id  = (string) DeliveryGroupIdentity::forHistorical(
			[ 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 1 ],
			$ctx
		);

		self::assertDoesNotMatchRegularExpression( '/Boundary|East Legon|Ama|Mensah|0244|CETECH/i', $id );
		self::assertMatchesRegularExpression( '/^in_warehouse\|delivery\|1\|[a-f0-9]{16}$/', $id );
	}

	public function test_v2_planner_does_not_depend_on_current_cart(): void {
		$ctx      = PerItemContextFixtures::deliveryContext( 1, PerItemContextFixtures::deliveryEastLegon() );
		$group_id = (string) DeliveryGroupIdentity::forHistorical(
			[ 'fulfilment_availability' => 'in_warehouse', 'fulfilment_choice' => 'delivery', 'delivery_offer_id' => 1 ],
			$ctx
		);
		$snapshot = $this->v2_line( $ctx, $group_id );
		$planner  = new HistoricalShipmentPlanner();
		unset( $GLOBALS['cetech_de_test_wc'] );

		$result = $planner->plan(
			ShipmentCreationFixtures::context(
				[ new HistoricalShipmentLineContext( 501, 'Chair', true, false, $snapshot ) ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id, '15.00' ) ], 'GHS', 1, '15.00' ),
				[ ShipmentCreationFixtures::shipping_line( $group_id, '15.00' ) ],
				91,
				'91'
			)
		);

		self::assertTrue( $result->ok );
		self::assertSame( $group_id, $result->plans[0]->delivery_group_id );
		self::assertSame( 501, $result->plans[0]->items[0]->order_item_id );
	}

	public function test_reselect_group_is_not_a_historical_paid_group(): void {
		$result = ( new HistoricalShipmentPlanner() )->plan(
			ShipmentCreationFixtures::context(
				[ ShipmentCreationFixtures::line( 'in_warehouse|delivery|1|reselect' ) ],
				ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( 'in_warehouse|delivery|1|reselect' ) ] ),
				[ ShipmentCreationFixtures::shipping_line( 'in_warehouse|delivery|1|reselect' ) ]
			)
		);

		self::assertFalse( $result->ok );
		self::assertSame( ShipmentCreationErrorCode::MalformedGroupSnapshot, $result->error_code );
	}

	private function v2_line( CustomerCartContext $ctx, string $group_id ): OrderDeliveryLineSnapshot {
		return new OrderDeliveryLineSnapshot(
			ProductDeliverySelectionIntent::CONTRACT_VERSION,
			OrderDeliverySnapshot::VERSION_V2,
			16,
			null,
			'in_warehouse',
			'delivery',
			1,
			'QA Local Standard',
			null,
			'3 days',
			null,
			1,
			1,
			'GHS',
			'15.0000',
			OrderDeliverySnapshot::QUOTE_STATUS_QUOTED,
			null,
			null,
			'2026-09-02T00:00:00+00:00',
			$group_id,
			null,
			null,
			null,
			$ctx->contract_version,
			$ctx->matching_location?->toArray(),
			$ctx->delivery_address?->toArray(),
			$ctx->matching_identity,
			$ctx->delivery_location_identity,
			null
		);
	}

	private function v2_pickup( CustomerCartContext $ctx, string $group_id ): OrderDeliveryLineSnapshot {
		return new OrderDeliveryLineSnapshot(
			ProductDeliverySelectionIntent::CONTRACT_VERSION,
			OrderDeliverySnapshot::VERSION_V2,
			18,
			null,
			'in_store',
			'store_pickup',
			null,
			null,
			null,
			null,
			null,
			null,
			1,
			'GHS',
			null,
			OrderDeliverySnapshot::QUOTE_STATUS_SELECTION_ONLY,
			null,
			null,
			'2026-09-02T00:00:00+00:00',
			$group_id,
			'QA Showroom',
			'Accra',
			null,
			$ctx->contract_version,
			null,
			null,
			null,
			null,
			$ctx->pickup_location_id
		);
	}
}
