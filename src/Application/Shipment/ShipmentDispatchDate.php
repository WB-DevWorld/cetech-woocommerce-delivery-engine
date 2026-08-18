<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Dispatch date is stored as a UTC datetime. Stage 14E does not change shipment status.
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

		$local = $date . ' 00:00:00';

		if ( function_exists( 'get_gmt_from_date' ) ) {
			$utc = get_gmt_from_date( $local );

			if ( is_string( $utc ) && '' !== $utc ) {
				return $utc;
			}
		}

		return $local;
	}

	public static function to_staff_date( ?string $stored ): string {
		$stored = trim( (string) $stored );

		if ( '' === $stored ) {
			return '';
		}

		if ( function_exists( 'get_date_from_gmt' ) ) {
			$local = get_date_from_gmt( $stored, 'Y-m-d' );

			if ( is_string( $local ) && '' !== $local ) {
				return $local;
			}
		}

		return substr( $stored, 0, 10 );
	}

	public static function to_display( ?string $stored ): string {
		$stored = trim( (string) $stored );

		if ( '' === $stored ) {
			return '';
		}

		$local = $stored;

		if ( function_exists( 'get_date_from_gmt' ) ) {
			$converted = get_date_from_gmt( $stored );

			if ( is_string( $converted ) && '' !== $converted ) {
				$local = $converted;
			}
		}

		$timestamp = strtotime( $local );

		if ( false === $timestamp ) {
			return substr( $stored, 0, 10 );
		}

		if ( function_exists( 'date_i18n' ) ) {
			$format = function_exists( 'get_option' ) ? (string) get_option( 'date_format', 'Y-m-d' ) : 'Y-m-d';

			return date_i18n( $format, $timestamp );
		}

		return date( 'Y-m-d', $timestamp );
	}
}
