<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

/**
 * Resolves WooCommerce product type for Stage 5A ECR routing (simple only).
 */
interface ProductTypeInspectorInterface {

	/**
	 * @return 'simple'|'variable'|'variation'|'other'|null
	 */
	public function inspect( int $product_id ): ?string;
}
