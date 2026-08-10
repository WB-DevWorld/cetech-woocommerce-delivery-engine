<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * Deterministic Stage 5A runtime configuration source router.
 *
 * Decision order:
 * 1. ECR cutover flag OFF → LEGACY
 * 2. Non-simple / variation / variable → LEGACY (Stage 6 owns variation cutover)
 * 3. Category-dependent legacy winner → LEGACY_CATEGORY_COMPATIBILITY
 * 4. Otherwise → ECR (fail-closed; no silent legacy fallback on ECR errors)
 */
final class ProductDeliveryRuntimeConfigurationRouter implements ProductDeliveryConfigurationSourceInterface {

	public const CUTOVER_FLAG = 'enable_effective_configuration_runtime';

	public function __construct(
		private FeatureFlags $feature_flags,
		private ProductDeliveryConfigurationSourceInterface $legacy_source,
		private ProductDeliveryConfigurationSourceInterface $ecr_source,
		private LegacyCategoryRuntimeCompatibilityGuardInterface $category_guard,
		private ProductTypeInspectorInterface $product_type_inspector,
		private ProductDeliveryConfigurationSourceInterface $legacy_category_source
	) {
	}

	public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution {
		$decision = $this->decide( $target_type, $target_id );

		return match ( $decision ) {
			RuntimeConfigurationSource::ECR => $this->ecr_source->resolve( $target_type, $target_id ),
			RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY => $this->legacy_category_source->resolve( $target_type, $target_id ),
			default => $this->legacy_source->resolve( $target_type, $target_id ),
		};
	}

	/**
	 * Internal/admin diagnostic: which source would be used without resolving configuration.
	 */
	public function decide( string $target_type, int $target_id ): string {
		$target_type = sanitize_key( $target_type );

		if ( ! $this->feature_flags->is_enabled( self::CUTOVER_FLAG ) ) {
			return RuntimeConfigurationSource::LEGACY;
		}

		if ( ProductTargetType::Variation->value === $target_type ) {
			return RuntimeConfigurationSource::LEGACY;
		}

		if ( ProductTargetType::Category->value === $target_type ) {
			return RuntimeConfigurationSource::LEGACY;
		}

		if ( ProductTargetType::Product->value !== $target_type || $target_id <= 0 ) {
			return RuntimeConfigurationSource::LEGACY;
		}

		$product_type = $this->product_type_inspector->inspect( $target_id );

		if ( 'simple' !== $product_type ) {
			return RuntimeConfigurationSource::LEGACY;
		}

		if ( $this->category_guard->depends_on_legacy_category_rule( $target_id ) ) {
			return RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY;
		}

		return RuntimeConfigurationSource::ECR;
	}
}
