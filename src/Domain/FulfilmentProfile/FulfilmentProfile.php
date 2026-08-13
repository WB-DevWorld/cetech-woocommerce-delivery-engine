<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\FulfilmentProfile;

/**
 * Registered fulfilment profile used for site-wide defaults and product classification.
 *
 * This is not a customer-facing delivery offer.
 */
final class FulfilmentProfile {

	/**
	 * @param list<string> $allowed_routes
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $short_label,
		public readonly string $availability,
		public readonly bool $delivery_allowed,
		public readonly bool $pickup_allowed,
		public readonly bool $air_sea_allowed,
		public readonly string $customer_fulfilment_label,
		public readonly string $description,
		public readonly array $allowed_routes = []
	) {
		if ( '' === $this->key || ! preg_match( '/^[a-z][a-z0-9_\-]{0,63}$/', $this->key ) ) {
			throw new \InvalidArgumentException( 'Fulfilment profile key is invalid.' );
		}
	}
}
