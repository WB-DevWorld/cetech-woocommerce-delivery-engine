<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * ECR-backed runtime configuration source for Stage 5A simple-product cutover.
 *
 * Fail-closed on invalid/unresolved effective configuration. Never falls back to legacy.
 */
final class EcrProductDeliveryConfigurationSource implements ProductDeliveryConfigurationSourceInterface {

	public function __construct(
		private EffectiveConfigurationResolver $resolver,
		private EcrToRuntimeConfigurationAdapter $adapter
	) {
	}

	public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
		$target_type = sanitize_key( $target_type );

		if ( ProductTargetType::Product->value !== $target_type || $target_id <= 0 ) {
			return new ProductDeliveryRuntimeResolution(
				ProductRuleResolutionResult::failure(
					$target_type,
					$target_id,
					__(
						'Effective configuration runtime supports simple product targets only.',
						'cetech-woocommerce-delivery-engine'
					)
				),
				RuntimeConfigurationSource::ECR
			);
		}

		$set    = $this->resolver->resolveAll( $target_id, null );
		$mapped = $this->adapter->adapt( $target_id, $set, $target_type );

		return new ProductDeliveryRuntimeResolution(
			$mapped['result'],
			RuntimeConfigurationSource::ECR,
			$mapped['configuration_fingerprint'],
			$mapped['quote_dimensions_by_availability']
		);
	}
}
