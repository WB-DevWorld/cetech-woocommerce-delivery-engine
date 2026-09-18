<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroup;
use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Postcode field visibility from Woo locale plus active schema-6 coverage constraints.
 */
final class GeographyPostcodeRelevance {

	public function __construct(
		private ?CoverageGroupRepositoryInterface $groups = null,
		private ?CanonicalLocationRepositoryInterface $locations = null,
		private ?DestinationZoneRepositoryInterface $zones = null
	) {
	}

	public function is_visible( string $country_code, string $parent_key = '' ): bool {
		$country_code = strtoupper( trim( $country_code ) );
		if ( '' === $country_code ) {
			return false;
		}

		if ( $this->woocommerce_requires_postcode( $country_code ) ) {
			return true;
		}

		return $this->coverage_requires_postcode( $country_code, $parent_key );
	}

	public function woocommerce_requires_postcode( string $country_code ): bool {
		$country_code = strtoupper( trim( $country_code ) );
		$forced       = apply_filters( 'cetech_de_postcode_required_countries', [] );
		if ( is_array( $forced ) && in_array( $country_code, array_map( 'strtoupper', $forced ), true ) ) {
			return true;
		}

		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->countries ) ) {
			return false;
		}
		$countries = WC()->countries;
		if ( ! is_object( $countries ) || ! method_exists( $countries, 'get_country_locale' ) ) {
			return false;
		}
		$locale = $countries->get_country_locale();
		if ( ! is_array( $locale ) || ! isset( $locale[ $country_code ] ) ) {
			return false;
		}
		$row = $locale[ $country_code ];
		if ( ! empty( $row['postcode']['hidden'] ) ) {
			return false;
		}

		return ! empty( $row['postcode']['required'] );
	}

	private function coverage_requires_postcode( string $country_code, string $parent_key ): bool {
		if ( ! $this->groups instanceof CoverageGroupRepositoryInterface
			|| ! $this->zones instanceof DestinationZoneRepositoryInterface
			|| ! $this->locations instanceof CanonicalLocationRepositoryInterface
		) {
			return false;
		}

		$scope = '' !== $parent_key ? $this->locations->find_by_key( $parent_key ) : $this->locations->find_country( $country_code );
		if ( $scope instanceof CanonicalLocation && $scope->country_code !== $country_code ) {
			$scope = $this->locations->find_country( $country_code );
		}

		$after = 0;
		do {
			$page = $this->zones->page_after( $after, 100, [ 'status' => RecordStatus::Active->value ] );
			foreach ( $page as $zone ) {
				$zone_id = (int) ( $zone['id'] ?? 0 );
				$after   = max( $after, $zone_id );
				if ( $zone_id <= 0 ) {
					continue;
				}
				foreach ( $this->groups->list_by_zone( $zone_id ) as $group ) {
					if ( ! $group->isUsable() || ! $this->has_active_postcode( $group ) ) {
						continue;
					}
					$root = $this->locations->find_by_id( $group->root_location_id );
					if ( ! $root instanceof CanonicalLocation || ! $root->isActive() || $root->country_code !== $country_code ) {
						continue;
					}
					if ( $scope instanceof CanonicalLocation && $this->group_covers_scope( $group, $root, $scope ) ) {
						return true;
					}
					if ( ! $scope instanceof CanonicalLocation && $root->isCountry() ) {
						return true;
					}
				}
			}
		} while ( [] !== $page );

		return false;
	}

	private function has_active_postcode( CoverageGroup $group ): bool {
		foreach ( $group->postcodes as $postcode ) {
			if ( RecordStatus::Active === $postcode->status && '' !== $postcode->postcode_value ) {
				return true;
			}
		}

		return false;
	}

	private function group_covers_scope( CoverageGroup $group, CanonicalLocation $root, CanonicalLocation $scope ): bool {
		if ( CoverageMode::SelectedDescendants === $group->mode ) {
			foreach ( $group->members_of( CoverageMembership::Include ) as $member ) {
				$location = $this->locations->find_by_id( $member->location_id );
				if ( ! $location instanceof CanonicalLocation || ! $location->isActive() ) {
					continue;
				}
				if ( LocationAncestry::is_self_or_descendant( $scope, $location )
					|| LocationAncestry::is_self_or_descendant( $location, $scope ) ) {
					return true;
				}
			}

			return false;
		}

		$related = LocationAncestry::is_self_or_descendant( $scope, $root )
			|| LocationAncestry::is_self_or_descendant( $root, $scope );
		if ( ! $related ) {
			return false;
		}

		if ( CoverageMode::EntireExcept === $group->mode ) {
			foreach ( $group->members_of( CoverageMembership::Exclude ) as $member ) {
				$excluded = $this->locations->find_by_id( $member->location_id );
				if ( $excluded instanceof CanonicalLocation
					&& $excluded->isActive()
					&& LocationAncestry::is_self_or_descendant( $scope, $excluded ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
