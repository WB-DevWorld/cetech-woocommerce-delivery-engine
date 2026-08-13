<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Catalog;

/**
 * WooCommerce-backed catalog index. Uses native product APIs only.
 */
final class WooCommerceCatalogIndex implements CatalogIndexInterface {

	public function count_published_products(): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$ids = wc_get_products(
			[
				'status' => 'publish',
				'type'   => [ 'simple', 'variable' ],
				'limit'  => -1,
				'return' => 'ids',
			]
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}

	public function published_product_ids( int $offset = 0, int $limit = 200 ): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$ids = wc_get_products(
			[
				'status' => 'publish',
				'type'   => [ 'simple', 'variable' ],
				'limit'  => $limit,
				'offset' => $offset,
				'return' => 'ids',
				'orderby' => 'ID',
				'order'   => 'ASC',
			]
		);

		if ( ! is_array( $ids ) ) {
			return [];
		}

		return array_map( 'intval', $ids );
	}

	public function product_label( int $product_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'Product #' . $product_id;
		}

		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
			return 'Product #' . $product_id;
		}

		$name = (string) $product->get_name();

		return '' !== $name ? $name : 'Product #' . $product_id;
	}

	public function product_edit_url( int $product_id ): string {
		if ( function_exists( 'get_edit_post_link' ) ) {
			$url = get_edit_post_link( $product_id, 'raw' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return admin_url( 'post.php?post=' . $product_id . '&action=edit' );
	}

	public function is_variable( int $product_id ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		return is_object( $product ) && method_exists( $product, 'is_type' ) && $product->is_type( 'variable' );
	}

	public function variation_ids( int $product_id ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return [];
		}

		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_children' ) ) {
			return [];
		}

		return array_map( 'intval', $product->get_children() );
	}

	public function variation_label( int $variation_id ): string {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'Variation #' . $variation_id;
		}

		$product = wc_get_product( $variation_id );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_name' ) ) {
			return 'Variation #' . $variation_id;
		}

		$name = (string) $product->get_name();

		return '' !== $name ? $name : 'Variation #' . $variation_id;
	}
}
