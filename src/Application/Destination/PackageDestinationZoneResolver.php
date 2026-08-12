<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

/**
 * Resolves a destination zone ID from a WooCommerce shipping package destination.
 */
final class PackageDestinationZoneResolver implements PackageDestinationZoneResolverInterface {

	public function __construct(
		private DestinationZoneMatcher $zone_matcher
	) {
	}

	/**
	 * @param array<string, mixed> $destination WooCommerce package destination.
	 */
	public function resolve_zone_id( array $destination ): ?int {
		$zone = $this->zone_matcher->match(
			(string) ( $destination['country'] ?? '' ),
			(string) ( $destination['state'] ?? '' ),
			(string) ( $destination['city'] ?? '' ),
			(string) ( $destination['postcode'] ?? '' )
		);

		if ( null === $zone ) {
			return null;
		}

		$zone_id = (int) ( $zone['id'] ?? 0 );

		return $zone_id > 0 ? $zone_id : null;
	}
}
