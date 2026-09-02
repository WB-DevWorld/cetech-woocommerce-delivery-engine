<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

/**
 * Validates a customer-facing display_key against live product options.
 */
interface ProductDeliverySelectionValidatorInterface {

	public function validate( int $product_id, ?int $variation_id, string $display_key ): ProductDeliverySelectionValidationResult;
}
