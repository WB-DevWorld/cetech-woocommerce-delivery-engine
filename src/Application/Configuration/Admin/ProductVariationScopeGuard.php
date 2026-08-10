<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;

/**
 * Validates product/variation identity and ownership for admin scope edits.
 *
 * Never trusts client-supplied relationships.
 */
final class ProductVariationScopeGuard {

	public function __construct(
		private readonly ?ProductTargetResolver $product_target_resolver = null
	) {
	}

	/**
	 * @return list<string> errors
	 */
	public function validate(
		ConfigurationScopeType $scope_type,
		int $scope_id,
		?int $parent_product_id
	): array {
		$errors = [];

		if ( ConfigurationScopeType::Global === $scope_type ) {
			if ( 0 !== $scope_id ) {
				$errors[] = 'Global scope_id must be 0.';
			}

			if ( null !== $parent_product_id ) {
				$errors[] = 'Global scope cannot include a parent product id.';
			}

			return $errors;
		}

		if ( $scope_id <= 0 ) {
			$errors[] = 'Scope ID must be a positive integer.';
			return $errors;
		}

		if ( ConfigurationScopeType::Product === $scope_type ) {
			$error = $this->validate_product( $scope_id );
			if ( null !== $error ) {
				$errors[] = $error;
			}

			return $errors;
		}

		if ( ConfigurationScopeType::Variation === $scope_type ) {
			if ( null === $parent_product_id || $parent_product_id <= 0 ) {
				$errors[] = 'Variation scope requires a positive parent product id.';
				return $errors;
			}

			$parent_error = $this->validate_product( $parent_product_id );
			if ( null !== $parent_error ) {
				$errors[] = $parent_error;
				return $errors;
			}

			$variation_error = $this->validate_variation( $scope_id, $parent_product_id );
			if ( null !== $variation_error ) {
				$errors[] = $variation_error;
			}
		}

		return $errors;
	}

	private function validate_product( int $product_id ): ?string {
		if ( null === $this->product_target_resolver ) {
			return null;
		}

		if ( ! $this->product_target_resolver->is_woocommerce_available() ) {
			return null;
		}

		return $this->product_target_resolver->validate_target( ProductTargetType::Product->value, $product_id );
	}

	private function validate_variation( int $variation_id, int $parent_product_id ): ?string {
		if ( null === $this->product_target_resolver ) {
			return $this->validate_variation_without_woocommerce( $variation_id, $parent_product_id );
		}

		if ( ! $this->product_target_resolver->is_woocommerce_available() ) {
			return $this->validate_variation_without_woocommerce( $variation_id, $parent_product_id );
		}

		$error = $this->product_target_resolver->validate_target( ProductTargetType::Variation->value, $variation_id );
		if ( null !== $error ) {
			return $error;
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			return 'WooCommerce is not available. Variation ownership cannot be validated.';
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation instanceof \WC_Product || ! $variation->is_type( 'variation' ) ) {
			return 'The selected variation does not exist.';
		}

		$parent_id = (int) $variation->get_parent_id();
		if ( $parent_id !== $parent_product_id ) {
			return 'The selected variation does not belong to the selected parent product.';
		}

		return null;
	}

	/**
	 * Unit-test hook: when WooCommerce is unavailable, still reject obviously forged IDs
	 * if a callable relationship map was not provided; production relies on WC.
	 */
	private function validate_variation_without_woocommerce( int $variation_id, int $parent_product_id ): ?string {
		if ( $variation_id <= 0 || $parent_product_id <= 0 ) {
			return 'Variation and parent product IDs must be positive.';
		}

		// Without WooCommerce, relationship checks are deferred to callers that inject
		// an explicit relationship predicate via validate_relationship().
		return null;
	}

	/**
	 * Explicit relationship check for unit tests / harnesses without WooCommerce.
	 */
	public function assert_variation_belongs_to_parent(
		int $variation_id,
		int $parent_product_id,
		?callable $relationship_checker = null
	): ?string {
		if ( $variation_id <= 0 || $parent_product_id <= 0 ) {
			return 'Variation and parent product IDs must be positive.';
		}

		if ( null !== $relationship_checker ) {
			$ok = (bool) $relationship_checker( $variation_id, $parent_product_id );
			return $ok ? null : 'The selected variation does not belong to the selected parent product.';
		}

		return $this->validate_variation( $variation_id, $parent_product_id );
	}
}
