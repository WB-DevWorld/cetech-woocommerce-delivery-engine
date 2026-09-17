<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
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

		foreach ( $this->zones->list( [ 'status' => 'active', 'limit' => 500 ] ) as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );
			if ( $zone_id <= 0 ) {
				continue;
			}
			foreach ( $this->groups->list_by_zone( $zone_id ) as $group ) {
				if ( ! $group->isUsable() || [] === $group->postcodes ) {
					continue;
				}
				$root = $this->locations->find_by_id( $group->root_location_id );
				if ( ! $root instanceof CanonicalLocation || $root->country_code !== $country_code ) {
					continue;
				}
				if ( $scope instanceof CanonicalLocation
					&& ! LocationAncestry::is_self_or_descendant( $scope, $root )
					&& ! LocationAncestry::is_self_or_descendant( $root, $scope )
				) {
					continue;
				}

				return true;
			}
		}

		return false;
	}
}
