<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

use CetechDeliveryEngine\Application\Selector\DeliveryEstimateFormatter;

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
		// Staff see exactly what customers see for this offer (ETA precedence, M2): the stored
		// durations in the offer's unit. Free-text service levels are only a fallback for offers
		// that carry no durations.
		$from_durations = DeliveryEstimateFormatter::from_offer( $record );
		if ( null !== $from_durations ) {
			return $from_durations;
		}

		$service = trim( (string) ( $record['service_level'] ?? '' ) );
		if ( self::looks_like_customer_eta( $service ) ) {
			return $service;
		}

		return '—';
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
