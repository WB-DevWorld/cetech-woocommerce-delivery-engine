<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Pickup;

use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;

/**
 * Turns stored Pickup Location address data into customer-facing copy.
 *
 * Structured JSON remains the persistence format. Customers never see it.
 */
final class PickupLocationAddressFormatter {

	private function __construct() {
	}

	public static function format( ?string $stored ): string {
		$stored = trim( (string) $stored );

		if ( '' === $stored ) {
			return '';
		}

		$decoded = self::decode_structured( $stored );

		if ( null !== $decoded ) {
			return self::from_parts( $decoded );
		}

		if ( self::looks_like_json( $stored ) ) {
			return '';
		}

		return $stored;
	}

	/**
	 * @param array<string, mixed> $parts
	 */
	public static function from_parts( array $parts ): string {
		$line1   = self::text( $parts['line1'] ?? $parts['address_line_1'] ?? '' );
		$line2   = self::text( $parts['line2'] ?? $parts['address_line_2'] ?? '' );
		$city    = self::text( $parts['city'] ?? '' );
		$region  = self::text( $parts['region'] ?? $parts['state'] ?? '' );
		$postcode = self::text( $parts['postcode'] ?? $parts['post_code'] ?? '' );
		$country = self::country_label(
			self::text( $parts['country_code'] ?? $parts['country'] ?? '' )
		);

		$chunks = array_values(
			array_filter(
				[ $line1, $line2, $city, $region, $postcode, $country ],
				static fn ( string $part ): bool => '' !== $part
			)
		);

		return implode( ', ', $chunks );
	}

	public static function looks_like_json( string $value ): bool {
		$trimmed = ltrim( $value );

		return str_starts_with( $trimmed, '{' ) || str_starts_with( $trimmed, '[' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decode_structured( string $stored ): ?array {
		if ( ! self::looks_like_json( $stored ) ) {
			return null;
		}

		$decoded = json_decode( $stored, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	private static function country_label( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}

		$code = WooCommerceCountryCatalog::canonical_iso2( $raw );
		if ( '' === $code || 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
			return $raw;
		}

		$label = trim( WooCommerceCountryCatalog::label( $code ) );
		if ( '' === $label || $code === $label ) {
			return '';
		}

		$plain = trim( (string) preg_replace( '/\s*\([^)]*\)\s*/', '', $label ) );

		return '' !== $plain ? $plain : $label;
	}

	private static function text( mixed $value ): string {
		return trim( (string) $value );
	}
}
