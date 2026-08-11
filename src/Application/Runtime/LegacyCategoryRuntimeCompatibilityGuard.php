<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * Stage 5/6 policy: when legacy winning configuration depends on an active category rule,
 * keep the product/variation on the legacy runtime configuration source.
 */
final class LegacyCategoryRuntimeCompatibilityGuard implements LegacyCategoryRuntimeCompatibilityGuardInterface {

	public function __construct(
		private ProductDeliveryRuleResolver $legacy_resolver
	) {
	}

	/**
	 * True when at least one chosen legacy rule for this product is category-derived.
	 */
	public function depends_on_legacy_category_rule( int $product_id ): bool {
		return $this->depends_on_legacy_category_rule_for_target( ProductTargetType::Product->value, $product_id );
	}

	public function depends_on_legacy_category_rule_for_target( string $target_type, int $target_id ): bool {
		$target_type = sanitize_key( $target_type );

		if ( $target_id <= 0 ) {
			return false;
		}

		if (
			ProductTargetType::Product->value !== $target_type
			&& ProductTargetType::Variation->value !== $target_type
		) {
			return false;
		}

		$result = $this->legacy_resolver->resolve( $target_type, $target_id );

		if ( ! $result->success || [] === $result->chosen_rules ) {
			return false;
		}

		foreach ( $result->chosen_rules as $rule ) {
			if ( ! $rule instanceof ResolvedProductDeliveryRule ) {
				continue;
			}

			if (
				ProductTargetType::Category->value === $rule->target_type
				|| 1 === $rule->target_specificity
			) {
				return true;
			}
		}

		return false;
	}
}
