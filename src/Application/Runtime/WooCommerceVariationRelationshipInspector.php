<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use WC_Product;

/**
 * WooCommerce-backed variation relationship inspector.
 */
final class WooCommerceVariationRelationshipInspector implements VariationRelationshipInspectorInterface {

	public function inspect( int $variation_id ): array {
		if ( $variation_id <= 0 ) {
			return [
				'ok'     => false,
				'reason' => 'invalid_variation_id',
			];
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return [
				'ok'     => false,
				'reason' => 'woocommerce_unavailable',
			];
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
			return [
				'ok'     => false,
				'reason' => 'variation_not_found',
			];
		}

		$parent_id = (int) $variation->get_parent_id();

		if ( $parent_id <= 0 ) {
			return [
				'ok'     => false,
				'reason' => 'missing_parent',
			];
		}

		$parent = wc_get_product( $parent_id );

		if ( ! $parent instanceof WC_Product || ! $parent->is_type( 'variable' ) ) {
			return [
				'ok'     => false,
				'reason' => 'invalid_parent',
			];
		}

		return [
			'ok'        => true,
			'parent_id' => $parent_id,
		];
	}

	public function belongs_to_parent( int $variation_id, int $parent_product_id ): bool {
		if ( $parent_product_id <= 0 ) {
			return false;
		}

		$inspection = $this->inspect( $variation_id );

		return ! empty( $inspection['ok'] )
			&& (int) ( $inspection['parent_id'] ?? 0 ) === $parent_product_id;
	}
}
