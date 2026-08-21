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
}
