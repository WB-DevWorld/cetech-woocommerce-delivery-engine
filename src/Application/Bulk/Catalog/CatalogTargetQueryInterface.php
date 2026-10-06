<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Catalog;

interface CatalogTargetQueryInterface {

	/**
	 * Count matching targets without loading the catalog into memory.
	 */
	public function count( CatalogTargetDefinition $definition ): int;

	/**
	 * Keyset page: return targets with id > $after_id, ordered by id ASC.
	 *
	 * @return list<CatalogTarget>
	 */
	public function page_after( CatalogTargetDefinition $definition, int $after_id, int $limit ): array;

	public function sku_for( string $target_type, int $target_id ): string;

	public function parent_product_id( int $variation_id ): ?int;

	public function find_id_by_sku( string $sku, string $target_type ): ?int;

	/**
	 * Highest candidate identity fixed when preparation starts.
	 * Selected-ID requests use the maximum explicit ID. Filter and entire-catalog
	 * requests use the current maximum ID of the requested object type.
	 */
	public function catalog_ceiling( CatalogTargetDefinition $definition ): int;

	/**
	 * Identities with id greater than $after_id and at most $high_water.
	 * Rejected identities are scanned. They are not returned in accepted.
	 *
	 * @return array{accepted: list<CatalogTarget>, cursor: int, scanned: int, exhausted: bool}
	 */
	public function scan_page( CatalogTargetDefinition $definition, int $after_id, int $limit, int $high_water ): array;

	/**
	 * Current membership of one identity: accepted, rejected, or unavailable.
	 */
	public function membership( CatalogTargetDefinition $definition, int $target_id ): string;
}
