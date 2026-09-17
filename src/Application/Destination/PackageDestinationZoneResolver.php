<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

/**
 * Resolves destination zone IDs from a WooCommerce shipping package destination.
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
		$ids = $this->resolve_zone_ids( $destination );

		return $ids[0] ?? null;
	}

	/**
	 * @param array<string, mixed> $destination WooCommerce package destination.
	 *
	 * @return list<int>
	 */
	public function resolve_zone_ids( array $destination ): array {
		$zones = $this->zone_matcher->match_all(
			(string) ( $destination['country'] ?? '' ),
			(string) ( $destination['state'] ?? '' ),
			(string) ( $destination['city'] ?? '' ),
			(string) ( $destination['postcode'] ?? '' ),
			[
				'canonical_location_key' => (string) ( $destination['canonical_location_key'] ?? '' ),
				'state'                  => (string) ( $destination['state'] ?? '' ),
				'state_label'            => (string) ( $destination['state_label'] ?? $destination['state'] ?? '' ),
			]
		);

		$ids = [];

		foreach ( $zones as $zone ) {
			$zone_id = (int) ( $zone['id'] ?? 0 );

			if ( $zone_id > 0 ) {
				$ids[] = $zone_id;
			}
		}

		return $ids;
	}
}
