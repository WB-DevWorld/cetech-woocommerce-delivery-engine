<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use WC_Product;

/**
 * WooCommerce-backed product type inspector.
 */
final class WooCommerceProductTypeInspector implements ProductTypeInspectorInterface {

	public function inspect( int $product_id ): ?string {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		if ( $product->is_type( 'simple' ) ) {
			return 'simple';
		}

		if ( $product->is_type( 'variable' ) ) {
			return 'variable';
		}

		if ( $product->is_type( 'variation' ) ) {
			return 'variation';
		}

		return 'other';
	}
}
