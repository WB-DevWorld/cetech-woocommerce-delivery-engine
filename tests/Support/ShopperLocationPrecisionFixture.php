<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Application\CustomerContext\ShopperDeliveryLocationPrecision;
use CetechDeliveryEngine\Application\Geography\CanonicalLocationResolver;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDestinationZoneRepository;

/**
 * In-memory Greater Accra / Accra coverage shapes for precision tests.
 */
final class ShopperLocationPrecisionFixture {

	public GhanaGeographyFixture $geo;

	public InMemoryCoverageGroupRepository $groups;

	public InMemoryDestinationZoneRepository $zones;

	public InMemoryGeographyPackRepository $packs;

	public CanonicalLocationResolver $resolver;

	public ShopperDeliveryLocationPrecision $precision;

	public function __construct() {
		$this->geo    = new GhanaGeographyFixture();
		$this->groups = new InMemoryCoverageGroupRepository();
		$this->zones  = new InMemoryDestinationZoneRepository();
		$this->packs  = new InMemoryGeographyPackRepository();
		$this->resolver = new CanonicalLocationResolver(
			$this->geo->locations,
			$this->geo->locations,
			$this->packs
		);
		$this->precision = new ShopperDeliveryLocationPrecision(
			$this->resolver,
			$this->zones,
			$this->groups,
			$this->geo->locations
		);
	}

	public function with_usable_gh_pack(): self {
		$this->packs->save(
			[
				'country_code'    => 'GH',
				'provider'        => GeographyProvider::GeoNames->value,
				'dataset_name'    => 'gazetteer',
				'status'          => GeographyPackStatus::Ready->value,
				'checksum'        => 'pack-checksum',
			]
		);

		return $this;
	}

	public function add_active_zone( int $id, string $name, string $status = 'active' ): int {
		return $this->zones->save(
			[
				'id'            => $id,
				'name'          => $name,
				'internal_code' => 'z' . $id,
				'status'        => $status,
			]
		);
	}

	public function add_greater_accra_entire_area( int $zone_id = 3 ): int {
		$this->add_active_zone( $zone_id, 'Greater Accra' );
		$this->groups->save_group(
			[
				'zone_id'          => $zone_id,
				'root_location_id' => $this->geo->greater_accra->id,
				'coverage_mode'    => CoverageMode::EntireArea->value,
				'status'           => RecordStatus::Active->value,
			]
		);

		return $zone_id;
	}

	public function add_accra_selected_descendants( int $zone_id = 1 ): int {
		$this->add_active_zone( $zone_id, 'Accra' );
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

		return $zone_id;
	}

	public function matching_country(): MatchingLocation {
		return MatchingLocation::fromInput( [ 'country' => 'GH' ] );
	}

	public function matching_greater_accra(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country' => 'GH',
				'state'   => 'AA',
			]
		);
	}

	public function matching_accra(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country'                => 'GH',
				'state'                  => 'AA',
				'city'                   => 'Accra',
				'canonical_location_key' => $this->geo->accra->location_key,
			]
		);
	}

	public function matching_tema(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country'                => 'GH',
				'state'                  => 'AA',
				'city'                   => 'Tema',
				'canonical_location_key' => $this->geo->tema->location_key,
			]
		);
	}

	public function matching_typed_accra_without_key(): MatchingLocation {
		return MatchingLocation::fromInput(
			[
				'country' => 'GH',
				'state'   => 'AA',
				'city'    => 'Accra',
			]
		);
	}
}
