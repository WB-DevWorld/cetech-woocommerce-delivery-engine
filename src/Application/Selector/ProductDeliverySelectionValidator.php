<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use WC_Product;

/**
 * Validates a display_key against live product rule resolution (admin/future cart use).
 *
 * Does not persist selections or hook add-to-cart.
 */
final class ProductDeliverySelectionValidator {

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ProductDeliveryConfigurationSourceInterface $configuration_source,
		private ProductDeliveryOptionsBuilder $options_builder
	) {
	}

	public function validate( int $product_id, ?int $variation_id, string $display_key ): ProductDeliverySelectionValidationResult {
		$display_key = ProductDeliveryOptionsBuilder::normalizeDisplayKey( $display_key );
		$warnings    = [];

		if ( '' === $display_key ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'invalid_display_key',
				__( 'Display key is required and must use the format availability:choice:suffix.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( $product_id <= 0 ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'invalid_product_id',
				__( 'Product ID must be a positive integer.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( ! $this->requirements->is_woocommerce_active() || ! function_exists( 'wc_get_product' ) ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'woocommerce_unavailable',
				__( 'WooCommerce is not available.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( ! $this->feature_flags->is_enabled( 'enable_product_delivery_selector' ) ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'selector_disabled',
				__( 'The product delivery selector feature flag is disabled.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( ! ProductDeliverySelectionIntent::isCompatibleWithOptionContract( ProductDeliveryOption::CONTRACT_VERSION ) ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'incompatible_contract',
				__( 'Selection intent contract is incompatible with the product delivery option contract version.', 'cetech-woocommerce-delivery-engine' ),
				$warnings
			);
		}

		$context = $this->resolve_product_context( $product_id, $variation_id );

		if ( null !== ( $context['error_code'] ?? null ) ) {
			return ProductDeliverySelectionValidationResult::invalid(
				(string) $context['error_code'],
				(string) ( $context['error_message'] ?? '' ),
				$warnings
			);
		}

		/** @var WC_Product $product */
		$product     = $context['product'];
		$target_type = (string) $context['target_type'];
		$target_id   = (int) $context['target_id'];

		$runtime = $this->configuration_source->resolve( $target_type, $target_id );
		$result  = $runtime->result;

		if ( ! $result->success ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'resolver_failed',
				(string) ( $result->error ?? __( 'Product rule resolution failed.', 'cetech-woocommerce-delivery-engine' ) ),
				$warnings
			);
		}

		if ( [] === $result->chosen_rules ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'resolver_no_match',
				__( 'No active product delivery rules matched this product.', 'cetech-woocommerce-delivery-engine' ),
				$warnings
			);
		}

		$options = $this->options_builder->buildFromResolution( $result );
		$matched = $this->find_option_by_display_key( $options, $display_key );

		if ( null === $matched ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'option_not_found',
				__( 'No matching delivery option was found for the supplied display key.', 'cetech-woocommerce-delivery-engine' ),
				$warnings
			);
		}

		if ( ! $matched->is_available ) {
			return ProductDeliverySelectionValidationResult::invalid(
				'option_unavailable',
				(string) ( $matched->unavailable_reason ?? __( 'The matched delivery option is not available.', 'cetech-woocommerce-delivery-engine' ) ),
				$warnings
			);
		}

		$rule_id = $this->find_rule_id( $result, $matched );

		if ( null === $rule_id && ! $runtime->is_ecr() ) {
			$warnings[] = __(
				'Matched option could not be linked to a resolved product rule ID.',
				'cetech-woocommerce-delivery-engine'
			);
		}

		$normalized_variation_id = $variation_id > 0 ? $variation_id : null;
		$intent                = ProductDeliverySelectionIntent::fromValidatedOption(
			$product_id,
			$normalized_variation_id,
			$target_type,
			$target_id,
			$matched,
			$rule_id,
			$runtime->configuration_fingerprint
		);

		return ProductDeliverySelectionValidationResult::valid( $matched, $intent, $warnings );
	}

	/**
	 * @return array{
	 *     product?: WC_Product,
	 *     target_type?: string,
	 *     target_id?: int,
	 *     error_code: string|null,
	 *     error_message: string|null
	 * }
	 */
	private function resolve_product_context( int $product_id, ?int $variation_id ): array {
		if ( null !== $variation_id && $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
				return [
					'error_code'    => 'product_not_found',
					'error_message' => __( 'Variation product was not found.', 'cetech-woocommerce-delivery-engine' ),
				];
			}

			$parent_id = (int) $variation->get_parent_id();

			if ( $parent_id > 0 && $parent_id !== $product_id ) {
				return [
					'error_code'    => 'invalid_product_context',
					'error_message' => __( 'Variation does not belong to the supplied product ID.', 'cetech-woocommerce-delivery-engine' ),
				];
			}

			return [
				'product'        => $variation,
				'target_type'    => ProductTargetType::Variation->value,
				'target_id'      => $variation_id,
				'error_code'     => null,
				'error_message'  => null,
			];
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return [
				'error_code'    => 'product_not_found',
				'error_message' => __( 'Product was not found.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		if ( $product->is_type( 'variable' ) ) {
			return [
				'error_code'    => 'invalid_product_context',
				'error_message' => __( 'Variable products require a variation ID for selection validation.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variation' ) ) {
			return [
				'error_code'    => 'invalid_product_context',
				'error_message' => __( 'This product type is not supported for delivery selection validation.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		$target_type = $product->is_type( 'variation' )
			? ProductTargetType::Variation->value
			: ProductTargetType::Product->value;

		return [
			'product'       => $product,
			'target_type'   => $target_type,
			'target_id'     => (int) $product->get_id(),
			'error_code'    => null,
			'error_message' => null,
		];
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	private function find_option_by_display_key( array $options, string $display_key ): ?ProductDeliveryOption {
		foreach ( $options as $option ) {
			if ( $option->display_key === $display_key ) {
				return $option;
			}
		}

		return null;
	}

	private function find_rule_id( ProductRuleResolutionResult $result, ProductDeliveryOption $option ): ?int {
		$rule = $result->chosen_rules[ $option->fulfilment_availability ] ?? null;

		if ( ! $rule instanceof ResolvedProductDeliveryRule ) {
			return null;
		}

		if ( $rule->fulfilment_choice !== $option->fulfilment_choice ) {
			return null;
		}

		return $rule->rule_id > 0 ? $rule->rule_id : null;
	}
}
