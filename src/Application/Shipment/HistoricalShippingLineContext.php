<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Matching WooCommerce shipping line captured at order time.
 */
final class HistoricalShippingLineContext {

	public function __construct(
		public readonly string $group_id,
		public readonly string $total,
		public readonly string $method_id
	) {
	}
}
