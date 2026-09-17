<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Additive schema-5 destination_rules → schema-6 coverage groups.
 *
 * Zone / Rate Card IDs are preserved. Legacy rules are never deleted.
 * Impossible AND combinations are never silently converted into a different
 * active commercial area except the documented same-parent locality OR case.
 */
final class LegacyDestinationCoverageMigrator {

	public const OPTION_KEY = 'cetech_de_coverage_migration_report';

	public function __construct(
		private DestinationZoneRepositoryInterface $zones,
		private DestinationRuleRepositoryInterface $rules,
		private CoverageGroupRepositoryInterface $groups,
		private CanonicalLocationRepositoryInterface $locations,
		private CanonicalLocationResolver $resolver
	) {
	}

	/**
	 * @return array{
	 *     zones:int,
	 *     converted:int,
	 *     skipped:int,
	 *     review_required:int,
	 *     warnings:list<array<string, mixed>>
	 * }
	 */
	public function migrate( bool $force = false ): array {
		$report = [
			'zones'           => 0,
			'converted'       => 0,
			'skipped'         => 0,
			'review_required' => 0,
			'warnings'        => [],
		];

		foreach ( $this->zones->list( [ 'limit' => 5000 ] ) as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );
			if ( $zone_id <= 0 ) {
				continue;
			}
			++$report['zones'];

			$existing = $this->groups->list_by_zone( $zone_id );
			if ( [] !== $existing && ! $force ) {
				++$report['skipped'];
				continue;
			}

			$rules = $this->rules->listByZoneId( $zone_id );
			if ( [] === $rules ) {
				++$report['skipped'];
				continue;
			}

			$payloads = $this->convert_zone( $zone_id, $rules );
			if ( [] === $payloads ) {
				++$report['skipped'];
				continue;
			}

			if ( $force ) {
				$this->groups->delete_by_zone( $zone_id );
			}

			foreach ( $payloads as $payload ) {
				$saved = $this->groups->save_group( $payload );
				++$report['converted'];
				if ( $saved->review_required ) {
					++$report['review_required'];
					$report['warnings'][] = [
						'zone_id' => $zone_id,
						'reason'  => (string) ( $saved->legacy_migration['reason'] ?? 'review_required' ),
						'legacy'  => $saved->legacy_migration,
					];
				}
			}
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $report, false );
		}

		return $report;
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 *
	 * @return list<array<string, mixed>>
	 */
	private function convert_zone( int $zone_id, array $rules ): array {
		$countries = [];
		$regions   = [];
		$cities    = [];
		$postcodes = [];

		foreach ( $rules as $rule ) {
			$type  = (string) ( $rule['rule_type'] ?? '' );
			$value = trim( (string) ( $rule['rule_value'] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$mode = (string) ( $rule['match_mode'] ?? DestinationRuleMatchMode::Exact->value );
			if ( DestinationRuleType::Country->value === $type ) {
				$countries[] = strtoupper( $value );
			} elseif ( DestinationRuleType::Region->value === $type ) {
				$regions[] = $value;
			} elseif ( DestinationRuleType::City->value === $type ) {
				$cities[] = $value;
			} elseif ( DestinationRuleType::Postcode->value === $type ) {
				$postcodes[] = [
					'postcode_value' => strtoupper( $value ),
					'match_mode'     => $mode,
					'priority'       => (int) ( $rule['priority'] ?? 100 ),
					'status'         => RecordStatus::Active->value,
				];
			}
		}

		$countries = array_values( array_unique( $countries ) );
		$regions   = array_values( array_unique( $regions ) );
		$cities    = array_values( array_unique( $cities ) );
		$legacy    = [
			'rules'     => $rules,
			'countries' => $countries,
			'regions'   => $regions,
			'cities'    => $cities,
		];

		if ( count( $countries ) > 1 ) {
			return [
				$this->inactive_review_group( $zone_id, 0, $legacy, 'ambiguous_multi_country', $postcodes ),
			];
		}

		$country_code = $countries[0] ?? '';
		$country      = '' !== $country_code ? $this->locations->find_country( $country_code ) : null;
		if ( ! $country instanceof CanonicalLocation ) {
			return [
				$this->inactive_review_group( $zone_id, 0, $legacy, 'unmapped_root', $postcodes ),
			];
		}

		$region_locations = [];
		$unmapped_regions = [];
		foreach ( $regions as $region_name ) {
			$found = $this->resolve_named( $country_code, $country->id, $region_name, GeographyLocationType::Administrative );
			if ( $found instanceof CanonicalLocation ) {
				$region_locations[] = $found;
			} else {
				$unmapped_regions[] = $region_name;
			}
		}

		if ( [] !== $unmapped_regions ) {
			$legacy['unmapped_regions'] = $unmapped_regions;

			return [
				$this->inactive_review_group( $zone_id, $country->id, $legacy, 'unmapped_region', $postcodes ),
			];
		}

		if ( count( $region_locations ) > 1 && [] !== $cities ) {
			$partitioned = $this->partition_cities( $country_code, $region_locations, $cities );
			if ( null === $partitioned ) {
				return [
					$this->inactive_review_group( $zone_id, $country->id, $legacy, 'ambiguous_cross_level_or_multi_root', $postcodes ),
				];
			}

			$groups = [];
			$order  = 10;
			foreach ( $partitioned as $bucket ) {
				$groups[] = $this->selected_group(
					$zone_id,
					$bucket['root'],
					$bucket['cities'],
					$legacy,
					'duplicate_legacy_same_level',
					true,
					RecordStatus::Inactive,
					$postcodes,
					$order
				);
				$order += 10;
			}

			return $groups;
		}

		if ( count( $region_locations ) > 1 && [] === $cities ) {
			$groups = [];
			$order  = 10;
			foreach ( $region_locations as $region_location ) {
				$groups[] = $this->entire_group(
					$zone_id,
					$region_location,
					$legacy,
					'ambiguous_cross_level_or_multi_root',
					true,
					RecordStatus::Inactive,
					$postcodes,
					$order
				);
				$order += 10;
			}

			return $groups;
		}

		$region_loc      = $region_locations[0] ?? null;
		$parent_for_city = $region_loc instanceof CanonicalLocation ? $region_loc : $country;
		$city_locations  = [];
		$unmapped_city   = [];
		foreach ( $cities as $city ) {
			$found = $this->resolve_named( $country_code, $parent_for_city->id, $city, GeographyLocationType::Locality );
			if ( ! $found instanceof CanonicalLocation ) {
				$found = $this->ensure_named_locality( $country_code, $parent_for_city, $city );
			}
			if ( $found instanceof CanonicalLocation ) {
				$city_locations[] = $found;
			} else {
				$unmapped_city[] = $city;
			}
		}

		if ( [] !== $unmapped_city ) {
			$legacy['unmapped_cities'] = $unmapped_city;

			return [
				$this->inactive_review_group(
					$zone_id,
					$parent_for_city->id,
					$legacy,
					'unmapped_city',
					$postcodes
				),
			];
		}

		if ( [] !== $city_locations ) {
			$root = $region_loc instanceof CanonicalLocation ? $region_loc : $country;
			$review = count( $city_locations ) > 1;
			$reason = $review ? 'duplicate_legacy_same_level' : 'legacy_single_location';
			if ( 1 === count( $city_locations ) && ! $region_loc instanceof CanonicalLocation ) {
				return [
					$this->entire_group(
						$zone_id,
						$city_locations[0],
						$legacy,
						$reason,
						false,
						RecordStatus::Active,
						$postcodes,
						10
					),
				];
			}

			return [
				$this->selected_group(
					$zone_id,
					$root,
					$city_locations,
					$legacy,
					$reason,
					$review,
					RecordStatus::Active,
					$postcodes,
					10
				),
			];
		}

		$root = $region_loc instanceof CanonicalLocation ? $region_loc : $country;

		return [
			$this->entire_group(
				$zone_id,
				$root,
				$legacy,
				'legacy_single_location',
				false,
				RecordStatus::Active,
				$postcodes,
				10
			),
		];
	}

	/**
	 * @param list<CanonicalLocation> $regions
	 * @param list<string>            $cities
	 *
	 * @return list<array{root:CanonicalLocation,cities:list<CanonicalLocation>}>|null
	 */
	private function partition_cities( string $country_code, array $regions, array $cities ): ?array {
		$buckets = [];
		foreach ( $regions as $region ) {
			$buckets[ $region->id ] = [
				'root'   => $region,
				'cities' => [],
			];
		}

		foreach ( $cities as $city ) {
			$matched = null;
			foreach ( $regions as $region ) {
				$found = $this->resolve_named( $country_code, $region->id, $city, GeographyLocationType::Locality );
				if ( $found instanceof CanonicalLocation ) {
					if ( $matched instanceof CanonicalLocation ) {
						return null;
					}
					$matched = $found;
					$buckets[ $region->id ]['cities'][] = $found;
				}
			}
			if ( ! $matched instanceof CanonicalLocation ) {
				return null;
			}
		}

		return array_values(
			array_filter(
				$buckets,
				static fn ( array $bucket ): bool => [] !== $bucket['cities']
			)
		);
	}

	/**
	 * @param list<CanonicalLocation> $cities
	 * @param array<string, mixed>    $legacy
	 * @param list<array<string, mixed>> $postcodes
	 *
	 * @return array<string, mixed>
	 */
	private function selected_group(
		int $zone_id,
		CanonicalLocation $root,
		array $cities,
		array $legacy,
		string $reason,
		bool $review,
		RecordStatus $status,
		array $postcodes,
		int $sort
	): array {
		$members = [];
		foreach ( $cities as $city ) {
			$members[] = [
				'location_id' => $city->id,
				'membership'  => 'include',
			];
		}

		return [
			'zone_id'          => $zone_id,
			'root_location_id' => $root->id,
			'coverage_mode'    => CoverageMode::SelectedDescendants->value,
			'sort_order'       => $sort,
			'status'           => $status->value,
			'review_required'  => $review,
			'legacy_migration' => $legacy + [ 'reason' => $reason ],
			'members'          => $members,
			'postcodes'        => $postcodes,
		];
	}

	/**
	 * @param array<string, mixed>       $legacy
	 * @param list<array<string, mixed>> $postcodes
	 *
	 * @return array<string, mixed>
	 */
	private function entire_group(
		int $zone_id,
		CanonicalLocation $root,
		array $legacy,
		string $reason,
		bool $review,
		RecordStatus $status,
		array $postcodes,
		int $sort
	): array {
		return [
			'zone_id'          => $zone_id,
			'root_location_id' => $root->id,
			'coverage_mode'    => CoverageMode::EntireArea->value,
			'sort_order'       => $sort,
			'status'           => $status->value,
			'review_required'  => $review,
			'legacy_migration' => $legacy + [ 'reason' => $reason ],
			'members'          => [],
			'postcodes'        => $postcodes,
		];
	}

	/**
	 * @param array<string, mixed>       $legacy
	 * @param list<array<string, mixed>> $postcodes
	 *
	 * @return array<string, mixed>
	 */
	private function inactive_review_group(
		int $zone_id,
		int $root_id,
		array $legacy,
		string $reason,
		array $postcodes
	): array {
		return [
			'zone_id'          => $zone_id,
			'root_location_id' => $root_id,
			'coverage_mode'    => CoverageMode::EntireArea->value,
			'sort_order'       => 10,
			'status'           => RecordStatus::Inactive->value,
			'review_required'  => true,
			'legacy_migration' => $legacy + [ 'reason' => $reason ],
			'members'          => [],
			'postcodes'        => $postcodes,
		];
	}

	private function resolve_named( string $country_code, int $parent_id, string $name, GeographyLocationType $type ): ?CanonicalLocation {
		return $this->resolver->exact_named_child( $country_code, $parent_id, $name, $type );
	}

	private function ensure_named_locality( string $country_code, CanonicalLocation $parent, string $name ): ?CanonicalLocation {
		$name = trim( $name );
		if ( '' === $name ) {
			return null;
		}

		$existing = $this->resolve_named( $country_code, $parent->id, $name, GeographyLocationType::Locality );
		if ( $existing instanceof CanonicalLocation ) {
			return $existing;
		}

		$saved = $this->locations->save(
			new CanonicalLocation(
				0,
				GeographyNameNormalizer::new_location_key(),
				$country_code,
				$parent->id,
				GeographyLocationType::Locality,
				null,
				$name,
				GeographyNameNormalizer::normalize( $name ),
				GeographyNameNormalizer::fold_ascii( $name ),
				null,
				null,
				RecordStatus::Active,
				LocationAncestry::append_path( $parent->ancestry_path, 0 )
			)
		);
		$this->locations->update_ancestry_path(
			$saved->id,
			LocationAncestry::append_path( $parent->ancestry_path, $saved->id )
		);

		return $this->locations->find_by_id( $saved->id ) ?? $saved;
	}
}
