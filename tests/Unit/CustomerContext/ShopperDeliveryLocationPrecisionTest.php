<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\CustomerContext;

use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\CustomerContext\ShopperLocationPrecision;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Tests\Support\ShopperLocationPrecisionFixture;
use PHPUnit\Framework\TestCase;

final class ShopperDeliveryLocationPrecisionTest extends TestCase {

	public function test_country_only_coverage_is_sufficient(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_active_zone( 9, 'National' );
		$fx->groups->save_group(
			[
				'zone_id'          => 9,
				'root_location_id' => $fx->geo->ghana->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		$result = $fx->precision->evaluate( $fx->matching_country() );

		self::assertTrue( $result->sufficient );
		self::assertNull( $result->required_level );
	}

	public function test_country_with_region_coverage_requires_region(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();

		$result = $fx->precision->evaluate( $fx->matching_country() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_REGION, $result->required_level );
		self::assertSame( CustomerStorefrontCopy::select_region_for_exact_fee(), $result->public_message );
		self::assertSame(
			[
				'sufficient'     => false,
				'required_level' => ShopperLocationPrecision::LEVEL_REGION,
				'reason'         => ShopperLocationPrecision::REASON_NARROWER_COVERAGE,
				'message_key'    => ShopperLocationPrecision::MESSAGE_KEY_REGION,
			],
			$result->toArray()
		);
	}

	public function test_region_without_narrower_coverage_is_sufficient(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertTrue( $result->sufficient );
		self::assertNull( $result->required_level );
	}

	public function test_selected_descendants_nested_member_requires_locality(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_LOCALITY, $result->required_level );
		self::assertSame( ShopperLocationPrecision::REASON_SELECTED_DESCENDANT, $result->reason );
		self::assertSame( CustomerStorefrontCopy::select_city_town_for_exact_fee(), $result->public_message );
	}

	public function test_canonical_accra_locality_is_sufficient(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();

		$result = $fx->precision->evaluate( $fx->matching_accra() );

		self::assertTrue( $result->sufficient );
		self::assertNull( $result->required_level );
	}

	public function test_non_accra_canonical_locality_under_same_region_is_sufficient(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();

		$result = $fx->precision->evaluate( $fx->matching_tema() );

		self::assertTrue( $result->sufficient );
		self::assertNull( $result->required_level );
	}

	public function test_typed_accra_without_canonical_key_is_not_trusted(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_accra_selected_descendants();

		$result = $fx->precision->evaluate( $fx->matching_typed_accra_without_key() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_LOCALITY, $result->required_level );
	}

	public function test_inactive_nested_zone_is_ignored(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_active_zone( 1, 'Accra', RecordStatus::Inactive->value );
		$fx->groups->save_group(
			[
				'zone_id'          => 1,
				'root_location_id' => $fx->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $fx->geo->accra->id, 'membership' => 'include' ],
				],
			]
		);

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertTrue( $result->sufficient );
	}

	public function test_inactive_coverage_group_is_ignored(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_active_zone( 1, 'Accra' );
		$fx->groups->save_group(
			[
				'zone_id'          => 1,
				'root_location_id' => $fx->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Inactive->value,
				'members'          => [
					[ 'location_id' => $fx->geo->accra->id, 'membership' => 'include' ],
				],
			]
		);

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertTrue( $result->sufficient );
	}

	public function test_review_required_group_is_ignored(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_active_zone( 1, 'Accra' );
		$fx->groups->save_group(
			[
				'zone_id'          => 1,
				'root_location_id' => $fx->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'review_required'  => 1,
				'members'          => [
					[ 'location_id' => $fx->geo->accra->id, 'membership' => 'include' ],
				],
			]
		);

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertTrue( $result->sufficient );
	}

	public function test_entire_except_exclusion_fails_closed_for_broad_region(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_active_zone( 4, 'Greater Accra except Accra' );
		$fx->groups->save_group(
			[
				'zone_id'          => 4,
				'root_location_id' => $fx->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireExcept->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $fx->geo->accra->id, 'membership' => 'exclude' ],
				],
			]
		);

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_LOCALITY, $result->required_level );
		self::assertSame( ShopperLocationPrecision::REASON_ENTIRE_EXCEPT, $result->reason );
	}

	public function test_no_usable_pack_preserves_legacy_sufficiency(): void {
		$fx = new ShopperLocationPrecisionFixture();
		$fx->add_accra_selected_descendants();

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertTrue( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::REASON_LEGACY_NO_PACK, $result->reason );
	}

	public function test_does_not_walk_geography_tables(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();
		$fx->add_accra_selected_descendants();
		$fx->geo->locations->list_by_country_calls              = 0;
		$fx->geo->locations->search_localities_calls            = 0;
		$fx->geo->locations->count_descendants_calls            = 0;
		$fx->geo->locations->list_children_calls                = 0;
		$fx->geo->locations->find_unique_exact_descendant_calls = 0;

		$fx->precision->evaluate( $fx->matching_greater_accra() );
		$fx->precision->evaluate( $fx->matching_accra() );
		$fx->precision->evaluate( $fx->matching_country() );

		self::assertSame( 0, $fx->geo->locations->list_by_country_calls );
		self::assertSame( 0, $fx->geo->locations->search_localities_calls );
		self::assertSame( 0, $fx->geo->locations->count_descendants_calls );
		self::assertSame( 0, $fx->geo->locations->list_children_calls );
		self::assertSame( 0, $fx->geo->locations->find_unique_exact_descendant_calls );
	}

	public function test_missing_matching_location_is_sufficient_placeholder(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();

		self::assertTrue( $fx->precision->evaluate( null )->sufficient );
		self::assertTrue( $fx->precision->evaluate( MatchingLocation::fromInput( [] ) )->sufficient );
	}

	public function test_country_selected_descendant_locality_with_adm1_requires_region(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_country_root_selected_locality( $fx->geo->accra );

		$result = $fx->precision->evaluate( $fx->matching_country() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_REGION, $result->required_level );
		self::assertSame( ShopperLocationPrecision::REASON_SELECTED_DESCENDANT, $result->reason );
	}

	public function test_country_direct_locality_requires_locality(): void {
		$fx      = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$harbour = $fx->seed_direct_country_locality();
		$fx->add_country_root_selected_locality( $harbour, 12 );

		$result = $fx->precision->evaluate( $fx->matching_country() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_LOCALITY, $result->required_level );
	}

	public function test_country_descendant_administrative_root_requires_region(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_greater_accra_entire_area();

		$result = $fx->precision->evaluate( $fx->matching_country() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_REGION, $result->required_level );
	}

	public function test_region_nested_locality_requires_locality(): void {
		$fx = ( new ShopperLocationPrecisionFixture() )->with_usable_gh_pack();
		$fx->add_accra_selected_descendants();

		$result = $fx->precision->evaluate( $fx->matching_greater_accra() );

		self::assertFalse( $result->sufficient );
		self::assertSame( ShopperLocationPrecision::LEVEL_LOCALITY, $result->required_level );
	}
}
