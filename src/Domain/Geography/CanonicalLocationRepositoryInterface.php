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

	public function find_country( string $country_code, bool $include_inactive = false ): ?CanonicalLocation;

	/**
	 * Every canonical country root, including inactive rows. Parent is always NULL.
	 *
	 * @return list<CanonicalLocation>
	 */
	public function list_country_roots(): array;

	/**
	 * Exact normalized name under an optional parent. Never fuzzy.
	 */
	public function find_exact_child( string $country_code, ?int $parent_id, string $normalized_name, ?GeographyLocationType $type = null, ?int $include_generation = null, string $include_token = '' ): ?CanonicalLocation;

	/**
	 * Unique exact name/ascii match of $type anywhere beneath $ancestor_id.
	 * Null when zero or more than one Active candidate. Never fuzzy. Never first-row-wins.
	 */
	public function find_unique_exact_descendant( string $country_code, int $ancestor_id, string $normalized_name, ?GeographyLocationType $type = null ): ?CanonicalLocation;

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
	 * Count Active localities in a country, optionally constrained to a parent subtree.
	 */
	public function count_localities( string $country_code, ?int $parent_id, string $query = '' ): int;

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
	 * Bounded, shopper-invisible preparation for one staging token.
	 * Stages future ancestry/parent metadata and draft aliases/mappings.
	 * Must not mutate live Active parent_location_id, ancestry_path, names, coordinates, aliases, or mappings.
	 * Hierarchy-changing roots are prepared independently of ordinary metadata drafts via durable cursors.
	 *
	 * @param array{hierarchy_root_cursor?: int, hierarchy_descendant_cursor?: int, hierarchy_root_id?: int} $hierarchy
	 * @return array{processed: int, last_id: int, done: bool, hierarchy_root_cursor: int, hierarchy_descendant_cursor: int, hierarchy_root_id: int}
	 */
	public function prepare_generation( string $generation_token, int $limit = 200, int $after_id = 0, array $hierarchy = [] ): array;

	/**
	 * Small atomic activation: set-based promote of a prepared generation plus pack Ready callback.
	 * Must not load a complete national generation into PHP or UPDATE one row at a time.
	 *
	 * @param callable|null $finalize Invoked inside the same transaction after geography writes.
	 *
	 * @return int Number of rows activated from Inactive to Active.
	 */
	public function finalize_generation( string $generation_token, ?callable $finalize = null ): int;

	/**
	 * Activate Inactive rows and apply drafts for one immutable staging token.
	 * Prepare is bounded/resumable; final activation is a small atomic transaction.
	 * Throws on any persistence failure.
	 *
	 * @param callable|null $finalize Invoked inside the same transaction after geography writes.
	 *
	 * @return int Number of rows promoted.
	 */
	public function promote_generation( string $generation_token, ?callable $finalize = null ): int;

	/**
	 * Delete abandoned/superseded staging for one token. Never deletes Active business geography.
	 */
	public function abandon_generation( string $generation_token ): void;

	/**
	 * Exact administrative match using type-neutral core names. Null when zero or more than one candidate.
	 */
	public function find_unique_administrative_core( string $country_code, int $parent_id, string $name, ?int $level = null ): ?CanonicalLocation;

	/**
	 * Customer-safe breadcrumb excluding the country name.
	 */
	public function display_breadcrumb( CanonicalLocation $location ): string;
}
