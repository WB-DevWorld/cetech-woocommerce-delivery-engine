<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult;

/**
 * Runtime configuration resolution with source diagnostics.
 *
 * Downstream offer construction continues to use {@see ProductRuleResolutionResult}.
 */
final class ProductDeliveryRuntimeResolution {

	/**
	 * @param array<string, array{
	 *     logistics_profile_id: int|null,
	 *     supplier_id: int|null,
	 *     origin_id: int|null
	 * }> $quote_dimensions_by_availability
	 */
	public function __construct(
		public readonly ProductRuleResolutionResult $result,
		public readonly string $source,
		public readonly ?string $configuration_fingerprint = null,
		public readonly array $quote_dimensions_by_availability = []
	) {
	}

	public function is_ecr(): bool {
		return RuntimeConfigurationSource::ECR === $this->source;
	}

	/**
	 * @return array{
	 *     logistics_profile_id: int|null,
	 *     supplier_id: int|null,
	 *     origin_id: int|null
	 * }
	 */
	public function quote_dimensions_for( string $fulfilment_availability ): array {
		$empty = [
			'logistics_profile_id' => null,
			'supplier_id'          => null,
			'origin_id'            => null,
		];

		return $this->quote_dimensions_by_availability[ $fulfilment_availability ] ?? $empty;
	}
}
