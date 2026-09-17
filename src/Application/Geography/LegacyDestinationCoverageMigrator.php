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
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Additive schema-5 destination_rules → schema-6 coverage groups.
 *
 * Zone / Rate Card IDs are preserved. Legacy rules are never deleted.
 * Unmapped text is flagged review_required rather than guessed.
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

			$payload = $this->convert_zone( $zone_id, $rules );
			if ( null === $payload ) {
				++$report['skipped'];
				continue;
			}

			if ( $force ) {
				$this->groups->delete_by_zone( $zone_id );
			}

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

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_KEY, $report, false );
		}

		return $report;
	}

	/**
	 * @param list<array<string, mixed>> $rules
	 *
	 * @return array<string, mixed>|null
	 */
	private function convert_zone( int $zone_id, array $rules ): ?array {
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

		$review   = false;
		$reason   = 'legacy_single_location';
		$legacy   = [
			'rules'     => $rules,
			'countries' => $countries,
			'regions'   => $regions,
			'cities'    => $cities,
		];

		if ( count( $countries ) > 1 || count( $regions ) > 1 ) {
			$review = true;
			$reason = 'ambiguous_cross_level_or_multi_root';
		}
		if ( count( $cities ) > 1 ) {
			$review = true;
			$reason = 'duplicate_legacy_same_level';
		}

		$country_code = $countries[0] ?? '';
		$country      = '' !== $country_code ? $this->locations->find_country( $country_code ) : null;
		$region_loc   = null;
		if ( $country instanceof CanonicalLocation && [] !== $regions ) {
			$region_loc = $this->resolve_named( $country_code, $country->id, $regions[0], GeographyLocationType::Administrative );
		}

		$city_locations = [];
		$unmapped_city  = [];
		$parent_for_city = $region_loc instanceof CanonicalLocation ? $region_loc : $country;
		foreach ( $cities as $city ) {
			$found = $parent_for_city instanceof CanonicalLocation
				? $this->resolve_named( $country_code, $parent_for_city->id, $city, GeographyLocationType::Locality )
				: null;
			if ( $found instanceof CanonicalLocation ) {
				$city_locations[] = $found;
			} else {
				$unmapped_city[] = $city;
			}
		}

		$root     = $region_loc ?? $country;
		$mode     = CoverageMode::EntireArea;
		$members  = [];
		$status   = RecordStatus::Active;

		if ( [] !== $city_locations ) {
			$mode = CoverageMode::SelectedDescendants;
			if ( $region_loc instanceof CanonicalLocation ) {
				$root = $region_loc;
			} elseif ( 1 === count( $city_locations ) ) {
				$root = $city_locations[0];
				$mode = CoverageMode::EntireArea;
			}
			if ( CoverageMode::SelectedDescendants === $mode ) {
				foreach ( $city_locations as $city_location ) {
					$members[] = [
						'location_id' => $city_location->id,
						'membership'  => 'include',
					];
				}
			}
		}

		if ( [] !== $unmapped_city ) {
			$review = true;
			$reason = 'unmapped_city';
			$status = RecordStatus::Inactive;
			$legacy['unmapped_cities'] = $unmapped_city;
			if ( ! $root instanceof CanonicalLocation ) {
				$root = $country;
			}
		}

		if ( ! $root instanceof CanonicalLocation ) {
			$review = true;
			$reason = 'unmapped_root';
			$status = RecordStatus::Inactive;
			$legacy['reason'] = $reason;

			return [
				'zone_id'           => $zone_id,
				'root_location_id'  => 0,
				'coverage_mode'     => CoverageMode::EntireArea->value,
				'sort_order'        => 10,
				'status'            => $status->value,
				'review_required'   => true,
				'legacy_migration'  => $legacy + [ 'reason' => $reason ],
				'members'           => [],
				'postcodes'         => $postcodes,
			];
		}

		$legacy['reason'] = $reason;

		return [
			'zone_id'          => $zone_id,
			'root_location_id' => $root->id,
			'coverage_mode'    => $mode->value,
			'sort_order'       => 10,
			'status'           => $status->value,
			'review_required'  => $review,
			'legacy_migration' => $legacy,
			'members'          => $members,
			'postcodes'        => $postcodes,
		];
	}

	private function resolve_named( string $country_code, int $parent_id, string $name, GeographyLocationType $type ): ?CanonicalLocation {
		return $this->resolver->exact_named_child( $country_code, $parent_id, $name, $type );
	}
}
