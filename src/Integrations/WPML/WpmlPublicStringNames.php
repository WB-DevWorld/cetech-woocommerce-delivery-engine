<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\WPML;

/**
 * Deterministic WPML String Translation names for Delivery Engine public copy.
 *
 * Stable identity is the persistent database entity ID, not internal_code.
 * Administrators may rename internal_code; WPML names must not break.
 * Deleted entities may leave leftover WPML strings; the frontend always reads
 * canonical DE rows first and never fatals on an orphan WPML entry.
 */
final class WpmlPublicStringNames {

	public const CONTEXT = 'CETECH Delivery Engine';

	public const OFFER_PUBLIC_LABEL = 'public_label';

	public const OFFER_PUBLIC_DESCRIPTION = 'public_description';

	public const PICKUP_LOCATION_NAME = 'location_name';

	public const PICKUP_OPENING_HOURS = 'opening_hours';

	public const PICKUP_INSTRUCTIONS = 'pickup_instructions';

	public const PICKUP_READINESS = 'readiness_estimate';

	public const ZONE_PUBLIC_LABEL = 'public_label';

	private function __construct() {
	}

	public static function delivery_offer( int $id, string $field ): string {
		return 'delivery_offer.' . max( 0, $id ) . '.' . $field;
	}

	public static function pickup_location( int $id, string $field ): string {
		return 'pickup_location.' . max( 0, $id ) . '.' . $field;
	}

	public static function destination_zone( int $id, string $field ): string {
		return 'destination_zone.' . max( 0, $id ) . '.' . $field;
	}
}
