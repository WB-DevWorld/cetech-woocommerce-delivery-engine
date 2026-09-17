<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocation;
use CetechDeliveryEngine\Domain\Geography\CanonicalLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\GeographyNameNormalizer;
use CetechDeliveryEngine\Domain\Geography\LocationAliasRepositoryInterface;
use CetechDeliveryEngine\Domain\Geography\LocationAncestry;
use CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface;

final class InMemoryCanonicalLocationRepository implements CanonicalLocationRepositoryInterface, LocationAliasRepositoryInterface, ProviderMappingRepositoryInterface {

	/** @var array<int, CanonicalLocation> */
	private array $locations = [];

	/** @var array<int, list<array{alias:string,normalized:string}>> */
	private array $aliases = [];

	/** @var array<string, int> */
	private array $mappings = [];

	/** @var array<string, string> */
	private array $external_by_location = [];

	private int $next_id = 1;

	public function seed(
		string $country,
		GeographyLocationType $type,
		string $name,
		?int $parent_id = null,
		?int $level = null,
		?string $key = null
	): CanonicalLocation {
		$id   = $this->next_id++;
		$path = LocationAncestry::append_path(
			null !== $parent_id && isset( $this->locations[ $parent_id ] ) ? $this->locations[ $parent_id ]->ancestry_path : '',
			$id
		);

		$location = new CanonicalLocation(
			$id,
			$key ?? GeographyNameNormalizer::new_location_key(),
			strtoupper( $country ),
			$parent_id,
			$type,
			$level,
			$name,
			GeographyNameNormalizer::normalize( $name ),
			GeographyNameNormalizer::fold_ascii( $name ),
			null,
			null,
			RecordStatus::Active,
			$path
		);
		$this->locations[ $id ] = $location;

		return $location;
	}

	public function find_by_id( int $id ): ?CanonicalLocation {
		return $this->locations[ $id ] ?? null;
	}

	public function find_by_key( string $location_key ): ?CanonicalLocation {
		foreach ( $this->locations as $location ) {
			if ( $location->location_key === $location_key ) {
				return $location;
			}
		}

		return null;
	}

	public function find_by_ids( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			if ( isset( $this->locations[ (int) $id ] ) ) {
				$out[] = $this->locations[ (int) $id ];
			}
		}

		return $out;
	}

	public function find_country( string $country_code ): ?CanonicalLocation {
		$country_code = strtoupper( trim( $country_code ) );
		foreach ( $this->locations as $location ) {
			if ( $location->isCountry() && $location->country_code === $country_code ) {
				return $location;
			}
		}

		return null;
	}

	public function find_exact_child( string $country_code, ?int $parent_id, string $normalized_name, ?GeographyLocationType $type = null ): ?CanonicalLocation {
		$country_code    = strtoupper( trim( $country_code ) );
		$normalized_name = GeographyNameNormalizer::normalize( $normalized_name );
		foreach ( $this->locations as $location ) {
			if ( ! $location->isActive() || $location->country_code !== $country_code || $location->normalized_name !== $normalized_name ) {
				continue;
			}
			$parent = $parent_id ?? 0;
			if ( $parent > 0 && $location->parent_location_id !== $parent ) {
				continue;
			}
			if ( $parent <= 0 && null !== $location->parent_location_id ) {
				continue;
			}
			if ( $type instanceof GeographyLocationType && $location->location_type !== $type ) {
				continue;
			}

			return $location;
		}

		return null;
	}

	public function list_children( int $parent_id, ?GeographyLocationType $type = null, int $limit = 50, int $offset = 0 ): array {
		$out = [];
		foreach ( $this->locations as $location ) {
			if ( $location->parent_location_id !== $parent_id || ! $location->isActive() ) {
				continue;
			}
			if ( $type instanceof GeographyLocationType && $location->location_type !== $type ) {
				continue;
			}
			$out[] = $location;
		}
		usort( $out, static fn ( CanonicalLocation $a, CanonicalLocation $b ): int => strnatcasecmp( $a->canonical_name, $b->canonical_name ) );

		return array_values( array_slice( $out, $offset, $limit ) );
	}

	public function count_children( int $parent_id, ?GeographyLocationType $type = null, string $search = '' ): int {
		$search = GeographyNameNormalizer::normalize( $search );
		$count  = 0;
		foreach ( $this->list_children( $parent_id, $type, 10000, 0 ) as $location ) {
			if ( '' === $search || str_contains( $location->normalized_name, $search ) ) {
				++$count;
			}
		}

		return $count;
	}

	public function count_descendants( int $parent_id, ?GeographyLocationType $type = null, string $search = '' ): int {
		$search = GeographyNameNormalizer::normalize( $search );
		$parent = $this->locations[ $parent_id ] ?? null;
		if ( ! $parent instanceof CanonicalLocation ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->locations as $location ) {
			if ( $location->id === $parent_id || ! $location->isActive() ) {
				continue;
			}
			if ( ! LocationAncestry::is_self_or_descendant( $location, $parent ) ) {
				continue;
			}
			if ( $location->id === $parent->id ) {
				continue;
			}
			if ( $type instanceof GeographyLocationType && $location->location_type !== $type ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( $location->normalized_name, $search ) && ! str_contains( GeographyNameNormalizer::normalize( $location->ascii_name ), $search ) ) {
				continue;
			}
			++$count;
		}

		return $count;
	}

	public function search_localities( string $country_code, ?int $parent_id, string $query, int $limit = 25, int $offset = 0 ): array {
		$country_code = strtoupper( trim( $country_code ) );
		$query        = GeographyNameNormalizer::normalize( $query );
		$parent       = ( null !== $parent_id && $parent_id > 0 ) ? ( $this->locations[ $parent_id ] ?? null ) : null;
		$out          = [];
		foreach ( $this->locations as $location ) {
			if ( ! $location->isLocality() || ! $location->isActive() || $location->country_code !== $country_code ) {
				continue;
			}
			if ( $parent instanceof CanonicalLocation && ! LocationAncestry::is_self_or_descendant( $location, $parent ) ) {
				continue;
			}
			if ( '' !== $query && ! str_contains( $location->normalized_name, $query ) && ! str_contains( GeographyNameNormalizer::normalize( $location->ascii_name ), $query ) && ! $this->alias_contains( $location->id, $query ) ) {
				continue;
			}
			$out[] = $location;
		}
		usort( $out, static fn ( CanonicalLocation $a, CanonicalLocation $b ): int => strnatcasecmp( $a->canonical_name, $b->canonical_name ) );

		return array_values( array_slice( $out, $offset, $limit ) );
	}

	public function list_by_country( string $country_code, ?GeographyLocationType $type = null, int $limit = 500 ): array {
		$out = [];
		foreach ( $this->locations as $location ) {
			if ( $location->country_code !== strtoupper( $country_code ) || ! $location->isActive() ) {
				continue;
			}
			if ( $type instanceof GeographyLocationType && $location->location_type !== $type ) {
				continue;
			}
			$out[] = $location;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	public function save( CanonicalLocation $location ): CanonicalLocation {
		$id = $location->id > 0 ? $location->id : $this->next_id++;
		if ( $id >= $this->next_id ) {
			$this->next_id = $id + 1;
		}
		$parent_path = '';
		if ( null !== $location->parent_location_id && isset( $this->locations[ $location->parent_location_id ] ) ) {
			$parent_path = $this->locations[ $location->parent_location_id ]->ancestry_path;
		}
		$path  = '' !== $location->ancestry_path ? $location->ancestry_path : LocationAncestry::append_path( $parent_path, $id );
		$saved = new CanonicalLocation(
			$id,
			'' !== $location->location_key ? $location->location_key : GeographyNameNormalizer::new_location_key(),
			$location->country_code,
			$location->parent_location_id,
			$location->location_type,
			$location->administrative_level,
			$location->canonical_name,
			$location->normalized_name,
			$location->ascii_name,
			$location->latitude,
			$location->longitude,
			$location->status,
			$path
		);
		$this->locations[ $id ] = $saved;

		return $saved;
	}

	public function update_ancestry_path( int $id, string $path ): void {
		$existing = $this->locations[ $id ] ?? null;
		if ( $existing instanceof CanonicalLocation ) {
			$this->locations[ $id ] = new CanonicalLocation(
				$existing->id,
				$existing->location_key,
				$existing->country_code,
				$existing->parent_location_id,
				$existing->location_type,
				$existing->administrative_level,
				$existing->canonical_name,
				$existing->normalized_name,
				$existing->ascii_name,
				$existing->latitude,
				$existing->longitude,
				$existing->status,
				$path
			);
		}
	}

	private function alias_contains( int $location_id, string $query ): bool {
		foreach ( $this->aliases[ $location_id ] ?? [] as $alias ) {
			if ( str_contains( $alias['normalized'], $query ) ) {
				return true;
			}
		}

		return false;
	}

	public function find_exact( string $country_code, string $normalized_alias, ?int $parent_id = null ): ?CanonicalLocation {
		foreach ( $this->aliases as $location_id => $list ) {
			foreach ( $list as $alias ) {
				if ( $alias['normalized'] !== $normalized_alias ) {
					continue;
				}
				$location = $this->locations[ $location_id ] ?? null;
				if ( ! $location instanceof CanonicalLocation || $location->country_code !== strtoupper( $country_code ) ) {
					continue;
				}
				if ( null !== $parent_id && $parent_id > 0 && $location->parent_location_id !== $parent_id && ! LocationAncestry::path_contains( $location->ancestry_path, $parent_id ) ) {
					continue;
				}

				return $location;
			}
		}

		return null;
	}

	public function list_for_location( int $location_id ): array {
		return array_map( static fn ( array $row ): string => $row['alias'], $this->aliases[ $location_id ] ?? [] );
	}

	public function add_alias( int $location_id, string $alias, string $normalized_alias, string $language_code = '', string $alias_type = 'alternate', bool $preferred = false ): void {
		foreach ( $this->aliases[ $location_id ] ?? [] as $existing ) {
			if ( $existing['normalized'] === $normalized_alias ) {
				return;
			}
		}
		$this->aliases[ $location_id ][] = [
			'alias'      => $alias,
			'normalized' => $normalized_alias,
		];
	}

	public function find_location_id( GeographyProvider $provider, string $external_id ): ?int {
		return $this->mappings[ $provider->value . ':' . $external_id ] ?? null;
	}

	public function find_external_id( int $location_id, GeographyProvider $provider ): ?string {
		return $this->external_by_location[ $location_id . ':' . $provider->value ] ?? null;
	}

	public function upsert(
		int $location_id,
		GeographyProvider $provider,
		string $external_id,
		?int $pack_id,
		string $dataset_version,
		string $provider_parent_reference = '',
		string $feature_class = '',
		string $feature_code = '',
		array $metadata = []
	): void {
		$this->mappings[ $provider->value . ':' . $external_id ] = $location_id;
		$this->external_by_location[ $location_id . ':' . $provider->value ] = $external_id;
	}

	public function find_mapping( GeographyProvider $provider, string $external_id ): ?array {
		$id = $this->find_location_id( $provider, $external_id );

		return null === $id ? null : [
			'location_id' => $id,
			'provider'    => $provider->value,
			'external_id' => $external_id,
		];
	}
}
