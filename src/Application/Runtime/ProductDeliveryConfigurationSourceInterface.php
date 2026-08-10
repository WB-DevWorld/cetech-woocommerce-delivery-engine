<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Runtime;

/**
 * Authoritative configuration source for simple-product (and legacy) runtime resolution.
 *
 * Storage-agnostic: callers must not depend on product_delivery_rules vs scoped v3 rows.
 */
interface ProductDeliveryConfigurationSourceInterface {

	public function resolve( string $target_type, int $target_id ): ProductDeliveryRuntimeResolution;
}
