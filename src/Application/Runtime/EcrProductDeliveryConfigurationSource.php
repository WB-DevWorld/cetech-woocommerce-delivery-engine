<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * ECR-backed runtime configuration source for Stage 5 simple + Stage 6 variation cutover.
 *
 * Fail-closed on invalid/unresolved effective configuration. Never falls back to legacy.
 */
final class EcrProductDeliveryConfigurationSource implements ProductDeliveryConfigurationSourceInterface {

	public function __construct(
		private EffectiveConfigurationResolver $resolver,
		private EcrToRuntimeConfigurationAdapter $adapter,
		private ?VariationRelationshipInspectorInterface $variation_inspector = null
	) {
	}

	public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
		$target_type = sanitize_key( $target_type );

		if ( ProductTargetType::Product->value === $target_type && $target_id > 0 ) {
			$set    = $this->resolver->resolveAll( $target_id, null );
			$mapped = $this->adapter->adapt( $target_id, $set, $target_type );

			return new ProductDeliveryRuntimeResolution(
				$mapped['result'],
				RuntimeConfigurationSource::ECR,
				$mapped['configuration_fingerprint'],
				$mapped['quote_dimensions_by_availability']
			);
		}

		if ( ProductTargetType::Variation->value === $target_type && $target_id > 0 ) {
			return $this->resolve_variation( $target_id );
		}

		return new ProductDeliveryRuntimeResolution(
			ProductRuleResolutionResult::failure(
				$target_type,
				$target_id,
				__(
					'Effective configuration runtime supports simple product and selected variation targets only.',
					'cetech-woocommerce-delivery-engine'
				)
			),
			RuntimeConfigurationSource::ECR
		);
	}

	private function resolve_variation( int $variation_id ): ProductDeliveryRuntimeResolution {
		if ( null === $this->variation_inspector ) {
			return new ProductDeliveryRuntimeResolution(
				ProductRuleResolutionResult::failure(
					ProductTargetType::Variation->value,
					$variation_id,
					__(
						'Variation relationship validation is unavailable.',
						'cetech-woocommerce-delivery-engine'
					)
				),
				RuntimeConfigurationSource::ECR
			);
		}

		$inspection = $this->variation_inspector->inspect( $variation_id );

		if ( empty( $inspection['ok'] ) ) {
			return new ProductDeliveryRuntimeResolution(
				ProductRuleResolutionResult::failure(
					ProductTargetType::Variation->value,
					$variation_id,
					__(
						'The selected variation is not valid for delivery configuration.',
						'cetech-woocommerce-delivery-engine'
					)
				),
				RuntimeConfigurationSource::ECR
			);
		}

		$parent_id = (int) $inspection['parent_id'];
		$set       = $this->resolver->resolveAll( $parent_id, $variation_id );
		$mapped    = $this->adapter->adapt(
			$parent_id,
			$set,
			ProductTargetType::Variation->value,
			$variation_id
		);

		return new ProductDeliveryRuntimeResolution(
			$mapped['result'],
			RuntimeConfigurationSource::ECR,
			$mapped['configuration_fingerprint'],
			$mapped['quote_dimensions_by_availability']
		);
	}
}
