<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Maps delivery_offers storage to staff Estimated Delivery display.
 *
 * Internal service_level codes (standard/express) are not customer ETA text.
 * Does not mutate stored legacy rows.
 */
final class OfferEstimatedDeliveryDisplay {

	/**
	 * Known internal ServiceLevel codes that must never be shown as ETA.
	 *
	 * @var list<string>
	 */
	private const INTERNAL_SERVICE_LEVEL_CODES = [
		'standard',
		'express',
		'economy',
		'overnight',
		'same_day',
		'sameday',
		'next_day',
		'nextday',
	];

	/**
	 * @param array<string, mixed> $record
	 */
	public static function label( array $record ): string {
		$service = trim( (string) ( $record['service_level'] ?? '' ) );
		if ( self::looks_like_customer_eta( $service ) ) {
			return $service;
		}

		$min = (int) ( $record['default_processing_min'] ?? 0 ) + (int) ( $record['default_transit_min'] ?? 0 );
		$max = (int) ( $record['default_processing_max'] ?? 0 ) + (int) ( $record['default_transit_max'] ?? 0 );
		if ( $min <= 0 && $max <= 0 ) {
			return '—';
		}
		if ( $max <= 0 || $min === $max ) {
			return sprintf(
				/* translators: %d days */
				__( '%d business days', 'cetech-woocommerce-delivery-engine' ),
				$min
			);
		}

		return sprintf(
			/* translators: 1: min days, 2: max days */
			__( '%1$d–%2$d business days', 'cetech-woocommerce-delivery-engine' ),
			$min,
			$max
		);
	}

	public static function looks_like_customer_eta( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}

		$normalized = strtolower( str_replace( [ ' ', '-', '_' ], '', $value ) );
		if ( in_array( strtolower( $value ), self::INTERNAL_SERVICE_LEVEL_CODES, true )
			|| in_array( $normalized, self::INTERNAL_SERVICE_LEVEL_CODES, true )
		) {
			return false;
		}

		// Customer ETA usually mentions time units or numeric ranges.
		return (bool) preg_match( '/\d|day|hour|week|business|–|—|to\s+\d/i', $value );
	}
}
