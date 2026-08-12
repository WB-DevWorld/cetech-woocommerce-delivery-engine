<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

/**
 * Resolves a destination zone ID from a WooCommerce shipping package destination.
 */
interface PackageDestinationZoneResolverInterface {

	/**
	 * @param array<string, mixed> $destination WooCommerce package destination.
	 */
	public function resolve_zone_id( array $destination ): ?int;
}
