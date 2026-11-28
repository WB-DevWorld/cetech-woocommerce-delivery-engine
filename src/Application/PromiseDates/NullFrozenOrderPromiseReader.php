<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PromiseDates;

/**
 * The default until the order freeze is wired: no order has a frozen promise, so placed orders
 * keep showing what their delivery snapshot already holds.
 */
final class NullFrozenOrderPromiseReader implements FrozenOrderPromiseReader {

	public function for_order( int $order_id ): array {
		return [];
	}
}
