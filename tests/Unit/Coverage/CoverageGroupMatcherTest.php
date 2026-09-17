<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Coverage;

use CetechDeliveryEngine\Application\Coverage\CoverageGroupMatcher;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Tests\Support\GhanaGeographyFixture;
use CetechDeliveryEngine\Tests\Support\InMemoryCoverageGroupRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationRuleRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;
use PHPUnit\Framework\TestCase;

final class CoverageGroupMatcherTest extends TestCase {

	private GhanaGeographyFixture $geo;

	private InMemoryCoverageGroupRepository $groups;

	private CoverageGroupMatcher $coverage;

	private CanonicalLocationResolver $resolver;

	private DestinationZoneMatcher $matcher;

	private InMemoryDestinationZoneRepository $zones;

	protected function setUp(): void {
		$this->geo      = new GhanaGeographyFixture();
		$this->groups   = new InMemoryCoverageGroupRepository();
		$this->coverage = new CoverageGroupMatcher( $this->groups, $this->geo->locations );
		$this->resolver = new CanonicalLocationResolver( $this->geo->locations, $this->geo->locations );
		$this->zones    = new InMemoryDestinationZoneRepository();
		$rules          = new InMemoryDestinationRuleRepository();
		$this->matcher  = new DestinationZoneMatcher( $this->zones, $rules, null, $this->coverage, $this->resolver );
	}

	public function test_legacy_single_location_parity(): void {
		$zone_id = $this->save_zone( 10, 'Accra area' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $this->geo->accra->id, 'membership' => 'include' ],
				],
			]
		);

		self::assertSame( [ 10 ], $this->match_ids( 'loc-accra' ) );
		self::assertSame( [], $this->match_ids( 'loc-tema' ) );
	}

	public function test_same_level_or(): void {
		$zone_id = $this->save_zone( 11, 'Selected Accra towns' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $this->geo->accra->id, 'membership' => 'include' ],
					[ 'location_id' => $this->geo->tema->id, 'membership' => 'include' ],
					[ 'location_id' => $this->geo->madina->id, 'membership' => 'include' ],
					[ 'location_id' => $this->geo->adenta->id, 'membership' => 'include' ],
				],
			]
		);

		foreach ( [ 'loc-accra', 'loc-tema', 'loc-madina', 'loc-adenta' ] as $key ) {
			self::assertSame( [ 11 ], $this->match_ids( $key ), $key );
		}
		self::assertSame( [], $this->match_ids( 'loc-pram' ) );
	}

	public function test_parent_child_rejection_does_not_flatten_regions(): void {
		$zone_id = $this->save_zone( 12, 'Bad flatten would allow Ashanti+Tema' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->ashanti->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $this->geo->kumasi->id, 'membership' => 'include' ],
				],
			]
		);
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $this->geo->tema->id, 'membership' => 'include' ],
				],
			]
		);

		self::assertSame( [ 12 ], $this->match_ids( 'loc-tema' ) );
		self::assertSame( [ 12 ], $this->match_ids( 'loc-kumasi' ) );
		self::assertSame( [], $this->match_ids( 'loc-accra' ), 'Accra is not Tema and not Kumasi' );
	}

	public function test_group_or_from_different_regions(): void {
		$zone_id = $this->save_zone( 13, 'Two regions' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $this->geo->accra->id, 'membership' => 'include' ],
					[ 'location_id' => $this->geo->tema->id, 'membership' => 'include' ],
				],
			]
		);
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->ashanti->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $this->geo->kumasi->id, 'membership' => 'include' ],
					[ 'location_id' => $this->geo->ejisu->id, 'membership' => 'include' ],
				],
			]
		);

		self::assertSame( [ 13 ], $this->match_ids( 'loc-accra' ) );
		self::assertSame( [ 13 ], $this->match_ids( 'loc-kumasi' ) );
		self::assertSame( [], $this->match_ids( 'loc-pram' ) );
	}

	public function test_entire_area(): void {
		$zone_id = $this->save_zone( 14, 'All Greater Accra' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		self::assertSame( [ 14 ], $this->match_ids( 'loc-accra' ) );
		self::assertSame( [ 14 ], $this->match_ids( 'loc-pram' ) );
		self::assertSame( [ 14 ], $this->match_ids( 'loc-ga' ) );
		self::assertSame( [], $this->match_ids( 'loc-kumasi' ) );
	}

	public function test_entire_except_exclusions_win(): void {
		$zone_id = $this->save_zone( 15, 'Greater Accra except coastal' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireExcept->value,
				'status'           => RecordStatus::Active->value,
				'members'          => [
					[ 'location_id' => $this->geo->ada_foah->id, 'membership' => 'exclude' ],
					[ 'location_id' => $this->geo->prampram->id, 'membership' => 'exclude' ],
				],
			]
		);

		self::assertSame( [ 15 ], $this->match_ids( 'loc-accra' ) );
		self::assertSame( [], $this->match_ids( 'loc-ada' ) );
		self::assertSame( [], $this->match_ids( 'loc-pram' ) );
	}

	public function test_country_only(): void {
		$zone_id = $this->save_zone( 16, 'Ghana' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->ghana->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		self::assertSame( [ 16 ], $this->match_ids( 'loc-gh' ) );
		self::assertSame( [ 16 ], $this->match_ids( 'loc-accra' ) );
		self::assertSame( [ 16 ], $this->match_ids( 'loc-kumasi' ) );
	}

	public function test_overlap_priority_and_fail_closed(): void {
		$this->save_zone( 20, 'Broad', 200 );
		$this->groups->save_group(
			[
				'zone_id'          => 20,
				'root_location_id' => $this->geo->ghana->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$this->save_zone( 21, 'Specific', 10 );
		$this->groups->save_group(
			[
				'zone_id'          => 21,
				'root_location_id' => $this->geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		$ids = $this->match_ids( 'loc-accra' );
		self::assertSame( [ 21, 20 ], $ids );

		self::assertSame( [], $this->matcher->match_all( 'NG', '', 'Lagos', '' ) );
	}

	public function test_postcode_exact_and_prefix(): void {
		$zone_id = $this->save_zone( 30, 'Postcode' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
				'postcodes'        => [
					[
						'postcode_value' => 'GA-123',
						'match_mode'     => 'exact',
						'status'         => RecordStatus::Active->value,
					],
					[
						'postcode_value' => 'GA-',
						'match_mode'     => 'prefix',
						'status'         => RecordStatus::Active->value,
					],
				],
			]
		);

		$exact = $this->resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', 'GA-123', 'loc-accra' );
		$miss  = $this->resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra', 'AK-000', 'loc-accra' );
		self::assertTrue( $this->coverage->match_zone( $zone_id, $exact )['matched'] );
		self::assertFalse( $this->coverage->match_zone( $zone_id, $miss )['matched'] );
	}

	public function test_canonical_id_tamper_is_rejected(): void {
		$zone_id = $this->save_zone( 40, 'Accra only' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		$tampered = MatchingLocation::fromInput(
			[
				'country'                => 'GH',
				'state'                  => 'AH',
				'city'                   => 'Kumasi',
				'canonical_location_key' => 'loc-accra',
			]
		);
		$resolved = $this->resolver->resolve_from_matching( $tampered );
		self::assertFalse( $resolved->hasCanonicalLocation() );
		self::assertSame( 'canonical_ancestry_rejected', $resolved->resolution_source );
		self::assertSame( [], $this->matcher->match_all( 'GH', 'AH', 'Kumasi', '', [ 'canonical_location_key' => 'loc-accra', 'state' => 'AH', 'state_label' => 'Ashanti' ] ) );
	}

	public function test_fuzzy_typo_is_not_authoritative(): void {
		$resolved = $this->resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Acccra', '', '' );
		self::assertNotSame( $this->geo->accra->id, $resolved->location_id() );
		self::assertNotSame( 'exact_locality', $resolved->resolution_source );
		self::assertNotSame( 'loc-accra', $resolved->location_key() );
	}

	public function test_alias_accra_metropolitan_resolves(): void {
		$resolved = $this->resolver->resolve( 'GH', 'AA', 'Greater Accra', 'Accra Metropolitan', '', '' );
		self::assertTrue( $resolved->hasCanonicalLocation() );
		self::assertSame( $this->geo->accra->id, $resolved->location_id() );
	}

	public function test_diagnostics_are_internal_only(): void {
		$zone_id = $this->save_zone( 50, 'Accra' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);
		$this->match_ids( 'loc-accra' );
		$diag = $this->matcher->last_diagnostics();
		self::assertNotEmpty( $diag );
		$payload = $diag[0]->toArray();
		self::assertArrayHasKey( 'inclusion_reason', $payload );
		self::assertArrayHasKey( 'coverage_group_id', $payload );
		self::assertArrayNotHasKey( 'rate_card_id', $payload );
		self::assertArrayNotHasKey( 'supplier_id', $payload );
	}

	public function test_twenty_member_zone_shares_one_area(): void {
		$zone_id = $this->save_zone( 60, 'Greater Accra 20' );
		$members = [];
		foreach ( $this->geo->twenty_greater_accra_localities() as $locality ) {
			$members[] = [ 'location_id' => $locality->id, 'membership' => 'include' ];
		}
		self::assertGreaterThanOrEqual( 20, count( $members ) );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::SelectedDescendants->value,
				'status'           => RecordStatus::Active->value,
				'members'          => $members,
			]
		);

		self::assertSame( [ 60 ], $this->match_ids( 'loc-accra' ) );
		self::assertSame( [ 60 ], $this->match_ids( 'loc-tema' ) );
		self::assertSame( [ 60 ], $this->match_ids( 'loc-madina' ) );
	}

	public function test_country_only_matching_location_is_present(): void {
		$location = MatchingLocation::fromInput( [ 'country' => 'GH' ] );
		self::assertTrue( $location->isPresent() );
		self::assertSame( '', $location->city );
	}

	/**
	 * @return list<int>
	 */
	private function match_ids( string $key ): array {
		$location = $this->geo->locations->find_by_key( $key );
		self::assertNotNull( $location );
		$matches  = $this->matcher->match_all(
			$location->country_code,
			'',
			$location->canonical_name,
			'',
			[
				'canonical_location_key' => $key,
			]
		);

		return array_map( static fn ( array $zone ): int => (int) $zone['id'], $matches );
	}

	private function save_zone( int $id, string $name, int $priority = 100 ): int {
		$this->zones->save(
			[
				'id'            => $id,
				'internal_name' => $name,
				'internal_code' => 'z' . $id,
				'status'        => RecordStatus::Active->value,
				'priority'      => $priority,
				'is_fallback'   => 0,
			]
		);

		return $id;
	}
}
