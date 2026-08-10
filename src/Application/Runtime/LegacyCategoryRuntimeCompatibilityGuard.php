<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;
use CetechDeliveryEngine\Application\ProductRule\ResolvedProductDeliveryRule;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;

/**
 * Stage 5 policy: when legacy winning configuration depends on an active category rule,
 * keep the product on the legacy runtime configuration source.
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
		if ( $product_id <= 0 ) {
			return false;
		}

		$result = $this->legacy_resolver->resolve( ProductTargetType::Product->value, $product_id );

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
