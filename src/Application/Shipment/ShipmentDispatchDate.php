<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Dispatch date is stored as a UTC datetime. The staff calendar date must
 * round-trip in the site timezone and must not shift a day because storage is UTC.
 *
 * Marking a shipment dispatched does not invent this date.
 */
final class ShipmentDispatchDate {

	private function __construct() {
	}

	public static function from_staff_date( string $raw ): ?string {
		$date = trim( $raw );

		if ( '' === $date ) {
			return null;
		}

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches ) ) {
			throw new \InvalidArgumentException( 'dispatch_date_invalid' );
		}

		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];

		if ( ! checkdate( $month, $day, $year ) ) {
			throw new \InvalidArgumentException( 'dispatch_date_invalid' );
		}

		try {
			$local = new \DateTimeImmutable( $date . ' 00:00:00', self::site_timezone() );
		} catch ( \Exception ) {
			throw new \InvalidArgumentException( 'dispatch_date_invalid' );
		}

		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	public static function to_staff_date( ?string $stored ): string {
		$utc = self::as_utc( $stored );

		if ( null === $utc ) {
			return '';
		}

		return $utc->setTimezone( self::site_timezone() )->format( 'Y-m-d' );
	}

	public static function to_display( ?string $stored ): string {
		$utc = self::as_utc( $stored );

		if ( null === $utc ) {
			return '';
		}

		$format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'Y-m-d' ) : 'Y-m-d';

		if ( '' === $format ) {
			$format = 'Y-m-d';
		}

		if ( function_exists( 'wp_date' ) ) {
			$display = wp_date( $format, $utc->getTimestamp(), self::site_timezone() );

			if ( is_string( $display ) && '' !== $display ) {
				return $display;
			}
		}

		$local = $utc->setTimezone( self::site_timezone() );

		if ( function_exists( 'date_i18n' ) ) {
			return date_i18n( $format, $local->getTimestamp() );
		}

		return $local->format( 'Y-m-d' );
	}

	private static function as_utc( ?string $stored ): ?\DateTimeImmutable {
		$stored = trim( (string) $stored );

		if ( '' === $stored ) {
			return null;
		}

		try {
			return new \DateTimeImmutable( $stored, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return null;
		}
	}

	private static function site_timezone(): \DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			$timezone = wp_timezone();

			if ( $timezone instanceof \DateTimeZone ) {
				return $timezone;
			}
		}

		$string = function_exists( 'get_option' ) ? (string) get_option( 'timezone_string', '' ) : '';

		if ( '' !== $string ) {
			try {
				return new \DateTimeZone( $string );
			} catch ( \Exception ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		$offset  = function_exists( 'get_option' ) ? (float) get_option( 'gmt_offset', 0 ) : 0.0;
		$hours   = (int) $offset;
		$minutes = (int) round( abs( $offset - $hours ) * 60 );
		$sign    = $offset < 0 ? '-' : '+';

		try {
			return new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes ) );
		} catch ( \Exception ) {
			return new \DateTimeZone( 'UTC' );
		}
	}
}
