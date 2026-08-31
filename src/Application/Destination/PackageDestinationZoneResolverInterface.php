<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

/**
 * Resolves destination zone IDs from a WooCommerce shipping package destination.
 */
interface PackageDestinationZoneResolverInterface {

	/**
	 * Primary matching active zone, or the configured fallback.
	 *
	 * @param array<string, mixed> $destination WooCommerce package destination.
	 */
	public function resolve_zone_id( array $destination ): ?int;

	/**
	 * Ordered matching active zone IDs for selected-offer pricing fallback.
	 *
	 * @param array<string, mixed> $destination WooCommerce package destination.
	 *
	 * @return list<int>
	 */
	public function resolve_zone_ids( array $destination ): array;
}
