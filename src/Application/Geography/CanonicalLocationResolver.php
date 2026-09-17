<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAliasRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Geography\ResolvedDestination;

/**
 * Exact canonical resolution. Never fuzzy.
 *
 * Accra may resolve through canonical/alias records. Acccra must not.
 */
final class CanonicalLocationResolver {

	public function __construct(
		private CanonicalLocationRepositoryInterface $locations,
		private LocationAliasRepositoryInterface $aliases,
		private ?GeographyPackRepositoryInterface $packs = null
	) {
	}

	public function resolve_from_matching( MatchingLocation $matching ): ResolvedDestination {
		return $this->resolve(
			$matching->country_identity,
			$matching->state_identity,
			$matching->state,
			$matching->city,
			$matching->postcode,
			$matching->canonical_location_key
		);
	}

	public function resolve(
		string $country_code,
		string $admin_code,
		string $admin_label,
		string $locality_label,
		string $postcode,
		string $canonical_key = ''
	): ResolvedDestination {
		$country_code  = strtoupper( trim( $country_code ) );
		$admin_code    = trim( $admin_code );
		$admin_label   = trim( $admin_label );
		$locality_label = trim( $locality_label );
		$postcode      = strtoupper( trim( $postcode ) );
		$canonical_key = trim( $canonical_key );

		$country = '' !== $country_code ? $this->locations->find_country( $country_code ) : null;
		$admin   = null;
		$locality = null;

		if ( '' !== $canonical_key ) {
			$from_key = $this->locations->find_by_key( $canonical_key );
			if ( $from_key instanceof CanonicalLocation && $from_key->isActive() ) {
				if ( '' !== $country_code && $from_key->country_code !== $country_code ) {
					return $this->unresolved( $country_code, $admin_code, $admin_label, $locality_label, $postcode, 'canonical_country_mismatch' );
				}
				if ( ! $this->ancestry_agrees( $from_key, $country, $admin_code, $admin_label ) ) {
					return $this->unresolved( $country_code, $admin_code, $admin_label, $locality_label, $postcode, 'canonical_ancestry_rejected' );
				}

				return $this->resolved( $from_key, $country_code, $admin_code, $admin_label, $locality_label, $postcode, 'canonical_key' );
			}

			return $this->unresolved( $country_code, $admin_code, $admin_label, $locality_label, $postcode, 'canonical_key_unknown' );
		}

		if ( $country instanceof CanonicalLocation && ( '' !== $admin_code || '' !== $admin_label ) ) {
			$admin = $this->exact_under_parent( $country_code, $country->id, $admin_code, GeographyLocationType::Administrative )
				?? $this->exact_under_parent( $country_code, $country->id, $admin_label, GeographyLocationType::Administrative );
		}

		$parent_for_locality = $admin instanceof CanonicalLocation ? $admin : $country;
		$pack_ready          = $this->country_has_ready_pack( $country_code );
		if ( $parent_for_locality instanceof CanonicalLocation && '' !== $locality_label && ( ! $pack_ready || '' !== $canonical_key ) ) {
			$locality = $this->exact_under_parent( $country_code, $parent_for_locality->id, $locality_label, GeographyLocationType::Locality );
		}

		$chosen = $locality ?? $admin ?? $country;
		if ( $chosen instanceof CanonicalLocation ) {
			$source = $locality instanceof CanonicalLocation ? 'exact_locality' : ( $admin instanceof CanonicalLocation ? 'exact_admin' : 'exact_country' );

			return $this->resolved( $chosen, $country_code, $admin_code, $admin_label, $locality_label, $postcode, $source );
		}

		return $this->unresolved( $country_code, $admin_code, $admin_label, $locality_label, $postcode, 'unresolved' );
	}

	public function exact_named_child( string $country_code, int $parent_id, string $name, GeographyLocationType $type ): ?CanonicalLocation {
		return $this->exact_under_parent( $country_code, $parent_id, $name, $type );
	}

	public function require_valid_key( string $canonical_key, string $country_code, ?int $expected_parent_id = null ): ?CanonicalLocation {
		$location = $this->locations->find_by_key( trim( $canonical_key ) );
		if ( ! $location instanceof CanonicalLocation || ! $location->isActive() ) {
			return null;
		}
		if ( '' !== $country_code && $location->country_code !== strtoupper( $country_code ) ) {
			return null;
		}
		if ( null !== $expected_parent_id && $expected_parent_id > 0 ) {
			$parent = $this->locations->find_by_id( $expected_parent_id );
			if ( ! $parent instanceof CanonicalLocation ) {
				return null;
			}
			if ( $location->id !== $parent->id && ! \CetechDeliveryEngine\Domain\Geography\LocationAncestry::is_self_or_descendant( $location, $parent ) ) {
				return null;
			}
		}

		return $location;
	}

	private function exact_under_parent( string $country_code, int $parent_id, string $name, GeographyLocationType $type ): ?CanonicalLocation {
		$by_key = $this->locations->find_by_key( $name );
		if ( $by_key instanceof CanonicalLocation && $by_key->isActive() && $by_key->country_code === $country_code ) {
			$parent = $this->locations->find_by_id( $parent_id );
			if ( $parent instanceof CanonicalLocation && LocationAncestry::is_self_or_descendant( $by_key, $parent ) ) {
				if ( $by_key->location_type === $type || ( GeographyLocationType::Administrative === $type && $by_key->isAdministrative() ) ) {
					return $by_key;
				}
			}
		}

		$normalized = GeographyNameNormalizer::normalize( $name );
		if ( '' === $normalized ) {
			return null;
		}

		$exact = $this->locations->find_exact_child( $country_code, $parent_id, $normalized, $type );
		if ( $exact instanceof CanonicalLocation ) {
			return $exact;
		}

		$by_code = $this->locations->find_exact_child( $country_code, $parent_id, strtolower( $name ), $type );
		if ( $by_code instanceof CanonicalLocation ) {
			return $by_code;
		}

		return $this->aliases->find_exact( $country_code, $normalized, $parent_id );
	}

	public function country_has_ready_pack( string $country_code ): bool {
		if ( ! $this->packs instanceof GeographyPackRepositoryInterface ) {
			return false;
		}
		foreach ( $this->packs->list_all() as $pack ) {
			if ( $pack->country_code === strtoupper( $country_code ) && 'ready' === $pack->status->value ) {
				return true;
			}
		}

		return false;
	}

	private function ancestry_agrees( CanonicalLocation $location, ?CanonicalLocation $country, string $admin_code, string $admin_label ): bool {
		if ( $country instanceof CanonicalLocation && $location->country_code !== $country->country_code ) {
			return false;
		}

		if ( '' === $admin_code && '' === $admin_label ) {
			return true;
		}

		if ( $location->isCountry() ) {
			return true;
		}

		$admin_normalized = GeographyNameNormalizer::normalize( '' !== $admin_label ? $admin_label : $admin_code );
		$parent           = null !== $location->parent_location_id ? $this->locations->find_by_id( $location->parent_location_id ) : null;
		while ( $parent instanceof CanonicalLocation ) {
			if ( $this->admin_matches_location( $parent, $admin_code, $admin_label, $admin_normalized ) ) {
				return true;
			}
			$parent = null !== $parent->parent_location_id ? $this->locations->find_by_id( $parent->parent_location_id ) : null;
		}

		return $location->isAdministrative() && $this->admin_matches_location( $location, $admin_code, $admin_label, $admin_normalized );
	}

	private function admin_matches_location(
		CanonicalLocation $candidate,
		string $admin_code,
		string $admin_label,
		string $admin_normalized
	): bool {
		if ( $candidate->location_key === $admin_code || $candidate->location_key === $admin_label ) {
			return true;
		}
		if ( $candidate->normalized_name === $admin_normalized || strtoupper( $candidate->canonical_name ) === strtoupper( $admin_code ) ) {
			return true;
		}

		foreach ( $this->aliases->list_for_location( $candidate->id ) as $alias ) {
			if ( strtoupper( $alias ) === strtoupper( $admin_code ) || GeographyNameNormalizer::normalize( $alias ) === $admin_normalized ) {
				return true;
			}
		}

		return false;
	}

	private function resolved(
		CanonicalLocation $location,
		string $country_code,
		string $admin_code,
		string $admin_label,
		string $locality_label,
		string $postcode,
		string $source
	): ResolvedDestination {
		return new ResolvedDestination(
			$location,
			$country_code,
			$admin_code,
			$admin_label,
			'' !== $locality_label ? $locality_label : $location->canonical_name,
			$postcode,
			true,
			$source,
			$this->ancestry_chain( $location )
		);
	}

	private function unresolved(
		string $country_code,
		string $admin_code,
		string $admin_label,
		string $locality_label,
		string $postcode,
		string $source
	): ResolvedDestination {
		return new ResolvedDestination(
			null,
			$country_code,
			$admin_code,
			$admin_label,
			$locality_label,
			$postcode,
			false,
			$source,
			[]
		);
	}

	/**
	 * @return list<CanonicalLocation>
	 */
	private function ancestry_chain( CanonicalLocation $location ): array {
		$chain   = [ $location ];
		$current = $location;
		$guard   = 0;
		while ( null !== $current->parent_location_id && $guard < 16 ) {
			$parent = $this->locations->find_by_id( $current->parent_location_id );
			if ( ! $parent instanceof CanonicalLocation ) {
				break;
			}
			array_unshift( $chain, $parent );
			$current = $parent;
			++$guard;
		}

		return $chain;
	}
}
