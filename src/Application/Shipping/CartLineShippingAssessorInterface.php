<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

/**
 * Assesses whether a cart line can be quoted for selected-offer shipping.
 *
 * @phpstan-type LineAssessment array{action: string, reason?: string, intent?: array<string, mixed>}
 */
interface CartLineShippingAssessorInterface {

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array{action: string, reason?: string, intent?: array<string, mixed>}
	 */
	public function assess_line( string $cart_item_key, array $cart_item ): array;
}
