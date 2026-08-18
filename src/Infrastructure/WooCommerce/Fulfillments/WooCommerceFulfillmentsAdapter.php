<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WooCommerce\Fulfillments;

/**
 * Reserved adapter for a future native WooCommerce Fulfillments mapping.
 *
 * V1 does not dual-write. This class is not bound in the service container
 * and must not be used as a persistence path.
 */
final class WooCommerceFulfillmentsAdapter {

	public function is_available(): bool {
		return false;
	}

	public function persist(): never {
		throw new \BadMethodCallException(
			'WooCommerce Fulfillments adapter is reserved and unimplemented. V1 does not dual-write.'
		);
	}
}
