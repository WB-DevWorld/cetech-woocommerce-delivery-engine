<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

interface CanonicalLocationRepositoryInterface {

	public function find_by_id( int $id ): ?CanonicalLocation;

	public function find_by_key( string $location_key ): ?CanonicalLocation;

	/**
	 * @return list<CanonicalLocation>
	 */
	public function find_by_ids( array $ids ): array;

	public function find_country( string $country_code ): ?CanonicalLocation;

	/**
	 * Exact normalized name under an optional parent. Never fuzzy.
	 */
	public function find_exact_child( string $country_code, ?int $parent_id, string $normalized_name, ?GeographyLocationType $type = null, ?int $include_generation = null ): ?CanonicalLocation;

	/**
	 * @return list<CanonicalLocation>
	 */
	public function list_children( int $parent_id, ?GeographyLocationType $type = null, int $limit = 50, int $offset = 0 ): array;

	public function count_children( int $parent_id, ?GeographyLocationType $type = null, string $search = '' ): int;

	/**
	 * Count self-excluded descendants (direct and nested) under a parent.
	 */
	public function count_descendants( int $parent_id, ?GeographyLocationType $type = null, string $search = '' ): int;

	/**
	 * Server-side locality search. Bounded. Exact prefix / contains of normalized names and aliases.
	 *
	 * @return list<CanonicalLocation>
	 */
	public function search_localities( string $country_code, ?int $parent_id, string $query, int $limit = 25, int $offset = 0 ): array;

	/**
	 * @return list<CanonicalLocation>
	 */
	public function list_by_country( string $country_code, ?GeographyLocationType $type = null, int $limit = 500 ): array;

	public function save( CanonicalLocation $location ): CanonicalLocation;

	public function update_ancestry_path( int $id, string $path ): void;

	/**
	 * Rewrite descendant ancestry after a node is re-parented.
	 *
	 * @return int Number of descendant rows updated (excluding $root_id).
	 */
	public function rebuild_descendant_ancestry( int $root_id, string $old_path, string $new_path, int $limit = 2000 ): int;

	/**
	 * Activate Inactive rows for a completed pack generation and apply drafts.
	 *
	 * @return int Number of rows promoted.
	 */
	public function promote_generation( int $generation ): int;

	/**
	 * Exact administrative match using type-neutral core names. Null when zero or more than one candidate.
	 */
	public function find_unique_administrative_core( string $country_code, int $parent_id, string $name, ?int $level = null ): ?CanonicalLocation;

	/**
	 * Customer-safe breadcrumb excluding the country name.
	 */
	public function display_breadcrumb( CanonicalLocation $location ): string;
}
