<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

/**
 * Stage 5/6 category-compatibility detection boundary.
 */
interface LegacyCategoryRuntimeCompatibilityGuardInterface {

	/**
	 * True when at least one chosen legacy rule for this product is category-derived.
	 */
	public function depends_on_legacy_category_rule( int $product_id ): bool;

	/**
	 * True when resolving the given target would still depend on a category winner
	 * because no more-specific product/variation rule controls it.
	 */
	public function depends_on_legacy_category_rule_for_target( string $target_type, int $target_id ): bool;
}
