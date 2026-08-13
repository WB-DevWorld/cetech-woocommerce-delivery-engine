<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

final class InMemoryCatalogIndex implements CatalogIndexInterface {

	/**
	 * @param array<int, array{label: string, type: string, variations?: array<int, string>}> $products
	 */
	public function __construct(
		private array $products = []
	) {
	}

	public function count_published_products(): int {
		return count( $this->products );
	}

	public function published_product_ids( int $offset = 0, int $limit = 200 ): array {
		return array_slice( array_map( 'intval', array_keys( $this->products ) ), $offset, $limit );
	}

	public function product_label( int $product_id ): string {
		return (string) ( $this->products[ $product_id ]['label'] ?? ( 'Product #' . $product_id ) );
	}

	public function product_edit_url( int $product_id ): string {
		return 'product:' . $product_id;
	}

	public function is_variable( int $product_id ): bool {
		return 'variable' === ( $this->products[ $product_id ]['type'] ?? 'simple' );
	}

	public function variation_ids( int $product_id ): array {
		$variations = $this->products[ $product_id ]['variations'] ?? [];

		return array_map( 'intval', array_keys( $variations ) );
	}

	public function variation_label( int $variation_id ): string {
		foreach ( $this->products as $product ) {
			$variations = $product['variations'] ?? [];
			if ( isset( $variations[ $variation_id ] ) ) {
				return (string) $variations[ $variation_id ];
			}
		}

		return 'Variation #' . $variation_id;
	}
}
