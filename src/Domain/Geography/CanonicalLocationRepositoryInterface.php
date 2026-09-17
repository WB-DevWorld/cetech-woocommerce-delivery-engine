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
	public function find_exact_child( string $country_code, ?int $parent_id, string $normalized_name, ?GeographyLocationType $type = null ): ?CanonicalLocation;

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
}
