<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin;

use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * Admin-only WooCommerce product/category target resolution and validation.
 */
final class ProductTargetResolver {

	public function __construct(
		private Requirements $requirements
	) {
	}

	public function is_woocommerce_available(): bool {
		return $this->requirements->is_woocommerce_active()
			&& function_exists( 'wc_get_product' );
	}

	/**
	 * @return string|null Error message when invalid; null when target exists.
	 */
	public function validate_target( string $target_type, int $target_id ): ?string {
		if ( $target_id <= 0 ) {
			return __( 'Enter a valid WooCommerce item ID.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ! $this->is_valid_target_type( $target_type ) ) {
			return __( 'Choose whether this check is for a product, product variation, or product category.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ! $this->is_woocommerce_available() ) {
			return __(
				'WooCommerce is not available. Product targets cannot be validated.',
				'cetech-woocommerce-delivery-engine'
			);
		}

		if ( ! $this->target_exists( $target_type, $target_id ) ) {
			return __(
				'The selected target does not exist in WooCommerce.',
				'cetech-woocommerce-delivery-engine'
			);
		}

		return null;
	}

	public function resolve_label( string $target_type, int $target_id ): ?string {
		if ( $target_id <= 0 || ! $this->is_woocommerce_available() ) {
			return null;
		}

		return match ( $target_type ) {
			ProductTargetType::Product->value,
			ProductTargetType::Variation->value => $this->resolve_product_label( $target_type, $target_id ),
			ProductTargetType::Category->value => $this->resolve_category_label( $target_id ),
			default => null,
		};
	}

	public function target_exists( string $target_type, int $target_id ): bool {
		if ( $target_id <= 0 || ! $this->is_woocommerce_available() ) {
			return false;
		}

		return match ( $target_type ) {
			ProductTargetType::Product->value => $this->product_target_exists( $target_id ),
			ProductTargetType::Variation->value => $this->variation_target_exists( $target_id ),
			ProductTargetType::Category->value => $this->category_target_exists( $target_id ),
			default => false,
		};
	}

	private function is_valid_target_type( string $target_type ): bool {
		foreach ( ProductTargetType::cases() as $case ) {
			if ( $case->value === $target_type ) {
				return true;
			}
		}

		return false;
	}

	private function product_target_exists( int $target_id ): bool {
		$product = wc_get_product( $target_id );

		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		return ! $product->is_type( 'variation' );
	}

	private function variation_target_exists( int $target_id ): bool {
		$product = wc_get_product( $target_id );

		return $product instanceof \WC_Product && $product->is_type( 'variation' );
	}

	private function category_target_exists( int $target_id ): bool {
		$term = get_term( $target_id, 'product_cat' );

		return $term instanceof \WP_Term && ! is_wp_error( $term );
	}

	private function resolve_product_label( string $target_type, int $target_id ): ?string {
		$product = wc_get_product( $target_id );

		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		if ( ProductTargetType::Variation->value === $target_type && ! $product->is_type( 'variation' ) ) {
			return null;
		}

		if ( ProductTargetType::Product->value === $target_type && $product->is_type( 'variation' ) ) {
			return null;
		}

		$name = $product->get_name();

		return '' !== $name ? $name : null;
	}

	private function resolve_category_label( int $target_id ): ?string {
		$term = get_term( $target_id, 'product_cat' );

		if ( ! $term instanceof \WP_Term || is_wp_error( $term ) ) {
			return null;
		}

		return '' !== $term->name ? $term->name : null;
	}
}
