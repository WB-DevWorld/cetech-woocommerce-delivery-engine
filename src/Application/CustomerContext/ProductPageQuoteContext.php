<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

/**
 * PDP quote identity: product, variation and current quantity.
 */
final class ProductPageQuoteContext {

	public function __construct(
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly int $quantity
	) {
	}

	public static function from_request( int $product_id, int $variation_id, int $quantity ): self {
		return new self(
			max( 0, $product_id ),
			max( 0, $variation_id ),
			$quantity > 0 ? $quantity : 1
		);
	}
}
