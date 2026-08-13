<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * Bounded catalog listing for inheritance previews. Not a product editor.
 */
interface CatalogIndexInterface {

	public function count_published_products(): int;

	/**
	 * @return list<int>
	 */
	public function published_product_ids( int $offset = 0, int $limit = 200 ): array;

	public function product_label( int $product_id ): string;

	public function product_edit_url( int $product_id ): string;

	public function is_variable( int $product_id ): bool;

	/**
	 * @return list<int>
	 */
	public function variation_ids( int $product_id ): array;

	public function variation_label( int $variation_id ): string;
}
