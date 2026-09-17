<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Persistence;

use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class WpdbCoverageGroupRepositoryTest extends TestCase {

	private WpdbCoverageGroupRepository $repository;

	private FakeWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new FakeWpdb();
		$this->wpdb->create_table( 'wp_delivery_engine_destination_coverage_groups' );
		$this->wpdb->create_table( 'wp_delivery_engine_destination_coverage_members' );
		$this->wpdb->create_table( 'wp_delivery_engine_destination_coverage_postcodes' );
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->repository = new WpdbCoverageGroupRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_edit_existing_group_keeps_stable_id_and_new_values(): void {
		$original = $this->repository->save_group(
			[
				'zone_id'          => 11,
				'root_location_id' => 5,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'postcodes'        => [
					[ 'postcode_value' => 'GA-1', 'match_mode' => 'exact' ],
				],
			]
		);
		self::assertSame( 1, $original->id );

		$saved = $this->repository->replace_for_zone(
			11,
			[
				[
					'id'               => $original->id,
					'root_location_id' => 8,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Active->value,
					'postcodes'        => [
						[ 'postcode_value' => 'GA-9', 'match_mode' => 'exact' ],
					],
				],
			]
		);

		self::assertCount( 1, $saved );
		self::assertSame( $original->id, $saved[0]->id );
		$again = $this->repository->list_by_zone( 11 );
		self::assertCount( 1, $again );
		self::assertSame( $original->id, $again[0]->id );
		self::assertSame( 8, $again[0]->root_location_id );
		self::assertSame( 'GA-9', $again[0]->postcodes[0]->postcode_value );
	}

	public function test_add_second_group_and_delete_obsolete(): void {
		$first = $this->repository->save_group(
			[
				'zone_id'          => 12,
				'root_location_id' => 5,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$obsolete = $this->repository->save_group(
			[
				'zone_id'          => 12,
				'root_location_id' => 6,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		$saved = $this->repository->replace_for_zone(
			12,
			[
				[
					'id'               => $first->id,
					'root_location_id' => 5,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Active->value,
				],
				[
					'root_location_id' => 9,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Active->value,
				],
			]
		);

		self::assertCount( 2, $saved );
		self::assertSame( $first->id, $saved[0]->id );
		self::assertGreaterThan( $first->id, $saved[1]->id );
		$ids = array_map( static fn ( $group ): int => $group->id, $this->repository->list_by_zone( 12 ) );
		self::assertContains( $first->id, $ids );
		self::assertNotContains( $obsolete->id, $ids );
	}

	public function test_unknown_cross_zone_group_id_is_rejected(): void {
		$zone_a = $this->repository->save_group(
			[
				'zone_id'          => 21,
				'root_location_id' => 1,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$zone_b = $this->repository->save_group(
			[
				'zone_id'          => 22,
				'root_location_id' => 2,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->repository->replace_for_zone(
			21,
			[
				[
					'id'               => $zone_b->id,
					'root_location_id' => 3,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Active->value,
				],
			]
		);
		unset( $zone_a );
	}

	public function test_mid_replacement_failure_rolls_back_previous_coverage(): void {
		$original = $this->repository->save_group(
			[
				'zone_id'          => 31,
				'root_location_id' => 4,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'review_required'  => true,
				'legacy_migration' => [ 'reason' => 'unmapped_city' ],
				'postcodes'        => [
					[ 'postcode_value' => 'KEEP', 'match_mode' => 'exact' ],
				],
			]
		);
		$this->wpdb->fail_next_insert_table = 'wp_delivery_engine_destination_coverage_groups';

		$saved = $this->repository->replace_for_zone(
			31,
			[
				[
					'id'               => $original->id,
					'root_location_id' => 4,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Active->value,
					'review_required'  => true,
					'legacy_migration' => [ 'reason' => 'unmapped_city' ],
					'postcodes'        => [
						[ 'postcode_value' => 'KEEP', 'match_mode' => 'exact' ],
					],
				],
				[
					'root_location_id' => 99,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Active->value,
				],
			]
		);

		self::assertSame( [], $saved );
		$kept = $this->repository->list_by_zone( 31 );
		self::assertCount( 1, $kept );
		self::assertSame( $original->id, $kept[0]->id );
		self::assertSame( 4, $kept[0]->root_location_id );
		self::assertTrue( $kept[0]->review_required );
		self::assertSame( 'KEEP', $kept[0]->postcodes[0]->postcode_value );
	}

	public function test_review_status_is_retained_until_explicit_resolution_payload(): void {
		$original = $this->repository->save_group(
			[
				'zone_id'          => 41,
				'root_location_id' => 7,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Inactive->value,
				'review_required'  => true,
				'legacy_migration' => [ 'reason' => 'unmapped_city' ],
			]
		);

		$this->repository->replace_for_zone(
			41,
			[
				[
					'id'               => $original->id,
					'root_location_id' => 7,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Inactive->value,
					'review_required'  => true,
					'legacy_migration' => [ 'reason' => 'unmapped_city' ],
				],
			]
		);
		$kept = $this->repository->list_by_zone( 41 )[0];
		self::assertTrue( $kept->review_required );
		self::assertSame( RecordStatus::Inactive, $kept->status );

		$this->repository->replace_for_zone(
			41,
			[
				[
					'id'               => $original->id,
					'root_location_id' => 7,
					'coverage_mode'    => CoverageMode::EntireArea->value,
					'status'           => RecordStatus::Active->value,
					'review_required'  => false,
					'legacy_migration' => [ 'reason' => 'unmapped_city' ],
				],
			]
		);
		$resolved = $this->repository->list_by_zone( 41 )[0];
		self::assertFalse( $resolved->review_required );
		self::assertSame( RecordStatus::Active, $resolved->status );
		self::assertSame( $original->id, $resolved->id );
	}
}
