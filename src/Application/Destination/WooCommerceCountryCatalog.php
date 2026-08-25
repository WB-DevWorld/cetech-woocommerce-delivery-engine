<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Destination;

/**
 * Canonical WooCommerce country list for Delivery Area matching.
 *
 * Stores and matches ISO-2 country codes. Does not expose continents,
 * "Everywhere", or WooCommerce shipping-zone groupings.
 */
final class WooCommerceCountryCatalog {

	/** @var array<string, string>|null */
	private static ?array $override_for_tests = null;

	/**
	 * @param array<string, string>|null $countries
	 */
	public static function override_for_tests( ?array $countries ): void {
		self::$override_for_tests = $countries;
	}

	/**
	 * @return array<string, string> ISO-2 code => human-readable name
	 */
	public static function options(): array {
		$raw = self::$override_for_tests;

		if ( null === $raw ) {
			$raw = [];
			if ( function_exists( 'WC' ) ) {
				$wc = WC();
				if ( is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) && method_exists( $wc->countries, 'get_countries' ) ) {
					$loaded = $wc->countries->get_countries();
					$raw    = is_array( $loaded ) ? $loaded : [];
				}
			}
		}

		return self::normalize( $raw );
	}

	public static function label( string $code ): string {
		$code      = strtoupper( trim( $code ) );
		$countries = self::options();

		return $countries[ $code ] ?? $code;
	}

	public static function has( string $code ): bool {
		$code = strtoupper( trim( $code ) );

		return isset( self::options()[ $code ] );
	}

	/**
	 * Turn a posted country control into a canonical ISO-2 code.
	 *
	 * Accepts ISO-2 (`DE`, `de`) or a WooCommerce country label (`Germany`,
	 * `United Kingdom`). Does not invent a second catalogue.
	 */
	public static function canonical_iso2( string $raw ): string {
		$trimmed = trim( $raw );
		if ( '' === $trimmed ) {
			return '';
		}

		$upper = strtoupper( $trimmed );
		if ( 1 === preg_match( '/^[A-Z]{2}$/', $upper ) ) {
			return $upper;
		}

		foreach ( self::options() as $code => $label ) {
			if ( 0 === strcasecmp( $label, $trimmed ) ) {
				return $code;
			}

			$plain = trim( (string) preg_replace( '/\s*\([^)]*\)\s*/', '', $label ) );
			if ( '' !== $plain && 0 === strcasecmp( $plain, $trimmed ) ) {
				return $code;
			}
		}

		return $trimmed;
	}

	/**
	 * @param array<mixed, mixed> $raw
	 *
	 * @return array<string, string>
	 */
	private static function normalize( array $raw ): array {
		$out = [];

		foreach ( $raw as $code => $label ) {
			$code  = strtoupper( trim( (string) $code ) );
			$label = trim( (string) $label );

			if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) || '' === $label ) {
				continue;
			}

			$out[ $code ] = $label;
		}

		return $out;
	}
}
