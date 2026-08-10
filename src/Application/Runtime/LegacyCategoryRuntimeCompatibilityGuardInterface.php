<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

/**
 * Stage 5 category-compatibility detection boundary.
 */
interface LegacyCategoryRuntimeCompatibilityGuardInterface {

	/**
	 * True when at least one chosen legacy rule for this product is category-derived.
	 */
	public function depends_on_legacy_category_rule( int $product_id ): bool;
}
