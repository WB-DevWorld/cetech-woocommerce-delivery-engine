<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;

/**
 * Legacy runtime configuration source wrapping verified ProductDeliveryRuleResolver semantics.
 */
final class LegacyProductDeliveryConfigurationSource implements ProductDeliveryConfigurationSourceInterface {

	public function __construct(
		private ProductDeliveryRuleResolver $rule_resolver,
		private string $source_label = RuntimeConfigurationSource::LEGACY
	) {
	}

	public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
		$result = $this->rule_resolver->resolve( $target_type, $target_id );

		return new ProductDeliveryRuntimeResolution(
			$result,
			$this->source_label,
			null,
			$this->dimensions_from_result( $result )
		);
	}

	/**
	 * @return array<string, array{
	 *     logistics_profile_id: int|null,
	 *     supplier_id: int|null,
	 *     origin_id: int|null
	 * }>
	 */
	private function dimensions_from_result( \CetechDeliveryEngine\Application\ProductRule\ProductRuleResolutionResult $result ): array {
		$dimensions = [];

		foreach ( $result->chosen_rules as $availability => $rule ) {
			$dimensions[ (string) $availability ] = [
				'logistics_profile_id' => $rule->logistics_profile_id,
				'supplier_id'          => $rule->supplier_id,
				'origin_id'            => $rule->origin_id,
			];
		}

		return $dimensions;
	}
}
