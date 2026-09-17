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

	/** @var array<int, list<array{alias:string,normalized:string,type:string,generation_token:string,status:string}>> */
	private array $aliases = [];

	/** @var array<string, array{location_id:int, generation_token:string, pack_id:?int, dataset_version:string}> */
	private array $mapping_rows = [];

	/** @var array<string, string> */
	private array $external_by_location = [];

	public bool $fail_next_ancestry = false;

	public bool $fail_next_staged_mapping_delete = false;

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

	public function find_exact_child( string $country_code, ?int $parent_id, string $normalized_name, ?GeographyLocationType $type = null, ?int $include_generation = null, string $include_token = '' ): ?CanonicalLocation {
		$country_code    = strtoupper( trim( $country_code ) );
		$normalized_name = GeographyNameNormalizer::normalize( $normalized_name );
		foreach ( $this->locations as $location ) {
			$visible = $location->isActive()
				|| ( '' !== $include_token && $location->generation_token === $include_token )
				|| ( null !== $include_generation && $include_generation > 0 && $location->generation === $include_generation && ( '' === $include_token || $location->generation_token === $include_token ) );
			if ( ! $visible || $location->country_code !== $country_code || $location->normalized_name !== $normalized_name ) {
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
			if ( '' === $search || str_starts_with( $location->normalized_name, $search ) ) {
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
			if ( '' !== $search && ! str_starts_with( $location->normalized_name, $search ) && ! str_starts_with( GeographyNameNormalizer::normalize( $location->ascii_name ), $search ) && ! $this->alias_contains( $location->id, $search ) ) {
				continue;
			}
			++$count;
		}

		return $count;
	}

	public function count_localities( string $country_code, ?int $parent_id, string $query = '' ): int {
		return count( $this->search_localities( $country_code, $parent_id, $query, 100000, 0 ) );
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
			if ( '' !== $query && ! str_starts_with( $location->normalized_name, $query ) && ! str_starts_with( GeographyNameNormalizer::normalize( $location->ascii_name ), $query ) && ! $this->alias_contains( $location->id, $query ) ) {
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
			$path,
			$location->generation,
			$location->draft_json,
			$location->generation_token,
			$location->draft_generation_token
		);
		$this->locations[ $id ] = $saved;

		return $saved;
	}

	public function update_ancestry_path( int $id, string $path ): void {
		if ( $this->fail_next_ancestry ) {
			$this->fail_next_ancestry = false;
			throw new \RuntimeException( 'Failed to update ancestry path for location ' . $id . '.' );
		}
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
				$path,
				$existing->generation,
				$existing->draft_json,
				$existing->generation_token,
				$existing->draft_generation_token
			);
		}
	}

	public function rebuild_descendant_ancestry( int $root_id, string $old_path, string $new_path, int $limit = 2000 ): int {
		if ( $root_id <= 0 || '' === $old_path || $old_path === $new_path ) {
			return 0;
		}

		$updated = 0;
		foreach ( $this->locations as $location ) {
			if ( $location->id === $root_id || ! str_starts_with( $location->ancestry_path, $old_path ) ) {
				continue;
			}
			$suffix = substr( $location->ancestry_path, strlen( $old_path ) );
			$this->update_ancestry_path( $location->id, $new_path . $suffix );
			++$updated;
		}

		return $updated;
	}

	public function promote_generation( string $generation_token, ?callable $finalize = null ): int {
		$generation_token = trim( $generation_token );
		if ( '' === $generation_token ) {
			throw new \RuntimeException( 'Promotion requires a generation token.' );
		}

		$snapshot_locations = $this->locations;
		$snapshot_aliases   = $this->aliases;
		$snapshot_mappings  = $this->mapping_rows;
		$snapshot_external  = $this->external_by_location;

		try {
			$activated = 0;
			foreach ( $this->locations as $location ) {
				if ( $location->draft_generation_token === $generation_token && '' !== $location->draft_json ) {
					$draft = json_decode( $location->draft_json, true );
					if ( is_array( $draft ) ) {
						$this->apply_draft_in_memory( $location, $draft );
						$location = $this->locations[ $location->id ] ?? $location;
					}
				}
				if ( $location->generation_token === $generation_token && RecordStatus::Inactive === $location->status ) {
					$this->locations[ $location->id ] = new CanonicalLocation(
						$location->id,
						$location->location_key,
						$location->country_code,
						$location->parent_location_id,
						$location->location_type,
						$location->administrative_level,
						$location->canonical_name,
						$location->normalized_name,
						$location->ascii_name,
						$location->latitude,
						$location->longitude,
						RecordStatus::Active,
						$location->ancestry_path,
						$location->generation,
						'',
						$generation_token,
						''
					);
					++$activated;
				}
			}
			$this->activate_staged_mappings( $generation_token );
			$this->activate_staged_aliases( $generation_token );
			if ( is_callable( $finalize ) ) {
				$finalize();
			}

			return $activated;
		} catch ( \Throwable $e ) {
			$this->locations            = $snapshot_locations;
			$this->aliases              = $snapshot_aliases;
			$this->mapping_rows         = $snapshot_mappings;
			$this->external_by_location = $snapshot_external;
			throw $e;
		}
	}

	public function abandon_generation( string $generation_token ): void {
		$generation_token = trim( $generation_token );
		if ( '' === $generation_token ) {
			return;
		}

		foreach ( $this->locations as $id => $location ) {
			if ( $location->generation_token === $generation_token && RecordStatus::Inactive === $location->status ) {
				unset( $this->locations[ $id ], $this->aliases[ $id ] );
				continue;
			}
			if ( $location->draft_generation_token === $generation_token && RecordStatus::Active === $location->status ) {
				$this->locations[ $id ] = new CanonicalLocation(
					$location->id,
					$location->location_key,
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
					$location->ancestry_path,
					$location->generation,
					'',
					$location->generation_token,
					''
				);
			}
		}
		foreach ( $this->mapping_rows as $key => $row ) {
			if ( $row['generation_token'] === $generation_token ) {
				unset( $this->mapping_rows[ $key ] );
			}
		}
		foreach ( $this->aliases as $location_id => $list ) {
			$kept = [];
			foreach ( $list as $alias ) {
				if ( ( $alias['generation_token'] ?? '' ) === $generation_token ) {
					continue;
				}
				$kept[] = $alias;
			}
			$this->aliases[ $location_id ] = $kept;
		}
	}

	/**
	 * @param array<string, mixed> $draft
	 */
	private function apply_draft_in_memory( CanonicalLocation $location, array $draft ): void {
		$name   = trim( (string) ( $draft['canonical_name'] ?? $location->canonical_name ) );
		$parent = isset( $draft['parent_location_id'] ) ? (int) $draft['parent_location_id'] : $location->parent_location_id;
		$old    = $location->ancestry_path;
		$this->save(
			new CanonicalLocation(
				$location->id,
				$location->location_key,
				$location->country_code,
				( $parent ?? 0 ) > 0 ? $parent : null,
				$location->location_type,
				$location->administrative_level,
				'' !== $name ? $name : $location->canonical_name,
				GeographyNameNormalizer::normalize( '' !== $name ? $name : $location->canonical_name ),
				(string) ( $draft['ascii_name'] ?? $location->ascii_name ),
				isset( $draft['latitude'] ) ? (float) $draft['latitude'] : $location->latitude,
				isset( $draft['longitude'] ) ? (float) $draft['longitude'] : $location->longitude,
				$location->status,
				$location->ancestry_path,
				$location->generation,
				'',
				$location->generation_token,
				''
			)
		);
		$former = trim( (string) ( $draft['former_name'] ?? '' ) );
		if ( '' !== $former ) {
			$this->add_alias( $location->id, $former, GeographyNameNormalizer::normalize( $former ), '', 'former_name', false );
		}
		foreach ( $draft['aliases'] ?? [] as $alias_row ) {
			if ( ! is_array( $alias_row ) ) {
				continue;
			}
			$alias = trim( (string) ( $alias_row['alias'] ?? '' ) );
			$norm  = trim( (string) ( $alias_row['normalized'] ?? GeographyNameNormalizer::normalize( $alias ) ) );
			if ( '' === $alias || '' === $norm ) {
				continue;
			}
			$this->add_alias( $location->id, $alias, $norm, '', (string) ( $alias_row['type'] ?? 'alternate' ), false );
		}
		foreach ( $draft['mappings'] ?? [] as $mapping ) {
			if ( ! is_array( $mapping ) ) {
				continue;
			}
			$provider = GeographyProvider::tryFrom( (string) ( $mapping['provider'] ?? '' ) );
			$external = trim( (string) ( $mapping['external_id'] ?? '' ) );
			if ( ! $provider instanceof GeographyProvider || '' === $external ) {
				continue;
			}
			$this->upsert(
				$location->id,
				$provider,
				$external,
				isset( $mapping['pack_id'] ) ? (int) $mapping['pack_id'] : null,
				(string) ( $mapping['dataset_version'] ?? '' ),
				(string) ( $mapping['provider_parent_reference'] ?? '' ),
				(string) ( $mapping['feature_class'] ?? '' ),
				(string) ( $mapping['feature_code'] ?? '' ),
				is_array( $mapping['metadata'] ?? null ) ? $mapping['metadata'] : [],
				''
			);
		}
		if ( ( $parent ?? 0 ) > 0 && $parent !== $location->parent_location_id ) {
			$parent_loc = $this->locations[ (int) $parent ] ?? null;
			if ( $parent_loc instanceof CanonicalLocation ) {
				$new_path = LocationAncestry::append_path( $parent_loc->ancestry_path, $location->id );
				$this->update_ancestry_path( $location->id, $new_path );
				if ( '' !== $old && $old !== $new_path ) {
					$this->rebuild_descendant_ancestry( $location->id, $old, $new_path );
				}
			}
		}
	}

	private function activate_staged_mappings( string $generation_token ): void {
		foreach ( $this->mapping_rows as $key => $row ) {
			if ( $row['generation_token'] !== $generation_token ) {
				continue;
			}
			if ( $this->fail_next_staged_mapping_delete ) {
				$this->fail_next_staged_mapping_delete = false;
				throw new \RuntimeException( 'Failed to delete staged mapping.' );
			}
			$provider_external = $this->provider_external_from_key( $key );
			$live              = $this->mapping_key( $provider_external['provider'], $provider_external['external_id'], '' );
			$this->mapping_rows[ $live ] = [
				'location_id'      => $row['location_id'],
				'generation_token' => '',
				'pack_id'          => $row['pack_id'],
				'dataset_version'  => $row['dataset_version'],
			];
			$this->external_by_location[ $row['location_id'] . ':' . $provider_external['provider'] ] = $provider_external['external_id'];
			if ( $key !== $live ) {
				unset( $this->mapping_rows[ $key ] );
			}
		}
	}

	private function activate_staged_aliases( string $generation_token ): void {
		foreach ( $this->aliases as $location_id => $list ) {
			$kept = [];
			foreach ( $list as $alias ) {
				if ( ( $alias['generation_token'] ?? '' ) !== $generation_token ) {
					$kept[] = $alias;
					continue;
				}
				$this->add_alias(
					$location_id,
					$alias['alias'],
					$alias['normalized'],
					'',
					(string) ( $alias['type'] ?? 'alternate' ),
					false,
					''
				);
			}
			$this->aliases[ $location_id ] = $this->aliases[ $location_id ] ?? $kept;
			$merged = [];
			foreach ( $this->aliases[ $location_id ] as $alias ) {
				if ( ( $alias['generation_token'] ?? '' ) === $generation_token ) {
					continue;
				}
				$merged[] = $alias;
			}
			$this->aliases[ $location_id ] = $merged;
		}
	}

	private function mapping_key( string $provider, string $external_id, string $token ): string {
		return $provider . "\0" . $external_id . "\0" . $token;
	}

	/**
	 * @return array{provider:string,external_id:string}
	 */
	private function provider_external_from_key( string $key ): array {
		$parts = explode( "\0", $key );

		return [
			'provider'    => (string) ( $parts[0] ?? '' ),
			'external_id' => (string) ( $parts[1] ?? '' ),
		];
	}

	private function external_from_mapping_key( string $key ): string {
		return $this->provider_external_from_key( $key )['external_id'];
	}

	public function find_unique_administrative_core( string $country_code, int $parent_id, string $name, ?int $level = null ): ?CanonicalLocation {
		$core = GeographyNameNormalizer::administrative_core( $name );
		if ( '' === $core || $parent_id <= 0 ) {
			return null;
		}
		$matches = [];
		foreach ( $this->locations as $location ) {
			if ( ! $location->isActive() || ! $location->isAdministrative() || $location->parent_location_id !== $parent_id ) {
				continue;
			}
			if ( null !== $level && $level > 0 && $location->administrative_level !== $level ) {
				continue;
			}
			if ( GeographyNameNormalizer::administrative_core( $location->canonical_name ) === $core
				|| GeographyNameNormalizer::administrative_core( $location->ascii_name ) === $core ) {
				$matches[ $location->id ] = $location;
			}
		}

		return 1 === count( $matches ) ? array_values( $matches )[0] : null;
	}

	public function display_breadcrumb( CanonicalLocation $location ): string {
		$ids = array_values(
			array_filter(
				array_map( 'intval', explode( '/', trim( $location->ancestry_path, '/' ) ) )
			)
		);
		$names = [];
		foreach ( array_reverse( $ids ) as $id ) {
			if ( $id === $location->id ) {
				continue;
			}
			$node = $this->locations[ $id ] ?? null;
			if ( ! $node instanceof CanonicalLocation || $node->isCountry() ) {
				continue;
			}
			$names[] = $node->canonical_name;
		}

		return implode( ', ', $names );
	}

	private function alias_contains( int $location_id, string $query ): bool {
		foreach ( $this->aliases[ $location_id ] ?? [] as $alias ) {
			if ( RecordStatus::Active->value !== ( $alias['status'] ?? RecordStatus::Active->value ) ) {
				continue;
			}
			if ( '' !== ( $alias['generation_token'] ?? '' ) ) {
				continue;
			}
			if ( str_starts_with( $alias['normalized'], $query ) ) {
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
				if ( RecordStatus::Active->value !== ( $alias['status'] ?? RecordStatus::Active->value ) ) {
					continue;
				}
				if ( '' !== ( $alias['generation_token'] ?? '' ) ) {
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
		$out = [];
		foreach ( $this->aliases[ $location_id ] ?? [] as $row ) {
			if ( RecordStatus::Active->value !== ( $row['status'] ?? RecordStatus::Active->value ) ) {
				continue;
			}
			if ( '' !== ( $row['generation_token'] ?? '' ) ) {
				continue;
			}
			$out[] = $row['alias'];
		}

		return $out;
	}

	public function add_alias( int $location_id, string $alias, string $normalized_alias, string $language_code = '', string $alias_type = 'alternate', bool $preferred = false, string $generation_token = '' ): void {
		unset( $language_code, $preferred );
		foreach ( $this->aliases[ $location_id ] ?? [] as $existing ) {
			if ( $existing['normalized'] === $normalized_alias && ( $existing['generation_token'] ?? '' ) === $generation_token ) {
				return;
			}
		}
		$this->aliases[ $location_id ][] = [
			'alias'            => $alias,
			'normalized'       => $normalized_alias,
			'type'             => $alias_type,
			'generation_token' => $generation_token,
			'status'           => RecordStatus::Active->value,
		];
	}

	public function find_location_id( GeographyProvider $provider, string $external_id, string $target_token = '' ): ?int {
		$live = $this->mapping_rows[ $this->mapping_key( $provider->value, $external_id, '' ) ] ?? null;
		if ( is_array( $live ) ) {
			return $live['location_id'];
		}
		if ( '' !== $target_token ) {
			$staged = $this->mapping_rows[ $this->mapping_key( $provider->value, $external_id, $target_token ) ] ?? null;
			if ( is_array( $staged ) ) {
				return $staged['location_id'];
			}
		}

		return null;
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
		array $metadata = [],
		string $generation_token = ''
	): void {
		unset( $provider_parent_reference, $feature_class, $feature_code, $metadata );
		$key = $this->mapping_key( $provider->value, $external_id, $generation_token );
		$this->mapping_rows[ $key ] = [
			'location_id'      => $location_id,
			'generation_token' => $generation_token,
			'pack_id'          => $pack_id,
			'dataset_version'  => $dataset_version,
		];
		if ( '' === $generation_token ) {
			$this->external_by_location[ $location_id . ':' . $provider->value ] = $external_id;
		}
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
