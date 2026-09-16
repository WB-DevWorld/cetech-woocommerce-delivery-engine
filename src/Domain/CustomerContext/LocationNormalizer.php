<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerContext;

/**
 * Deterministic geography/address normalization for identity hashing.
 *
 * Human-facing display values are sanitized without identity case-folding.
 * Identity inputs are separately case-normalized. This class never mutates
 * a customer-visible form value merely to produce a hash.
 */
final class LocationNormalizer {

	public static function country( string $raw ): string {
		$trimmed = strtoupper( trim( $raw ) );

		if ( 1 === preg_match( '/^[A-Z]{2}$/', $trimmed ) ) {
			return $trimmed;
		}

		return $trimmed;
	}

	public static function countryDisplay( string $raw ): string {
		return self::country( $raw );
	}

	public static function state( string $country, string $raw ): string {
		$trimmed = self::collapse_whitespace( $raw );

		if ( '' === $trimmed ) {
			return '';
		}

		$normalized_country = self::country( $country );
		$mapped             = self::map_state_via_woocommerce( $normalized_country, $trimmed );

		if ( '' !== $mapped ) {
			return $mapped;
		}

		return strtoupper( $trimmed );
	}

	public static function stateDisplay( string $country, string $raw ): string {
		$trimmed = self::collapse_whitespace( $raw );

		if ( '' === $trimmed ) {
			return '';
		}

		$mapped = self::map_state_via_woocommerce( self::country( $country ), $trimmed );

		return '' !== $mapped ? $mapped : $trimmed;
	}

	public static function cityDisplay( string $raw ): string {
		return self::collapse_whitespace( $raw );
	}

	public static function cityIdentity( string $raw ): string {
		return self::fold_case( self::collapse_whitespace( $raw ) );
	}

	public static function postcode( string $raw ): string {
		return strtoupper( self::collapse_whitespace( $raw ) );
	}

	public static function addressDisplay( string $raw ): string {
		return self::collapse_whitespace( $raw );
	}

	public static function addressIdentity( string $raw ): string {
		return self::fold_case( self::collapse_whitespace( $raw ) );
	}

	public static function collapse_whitespace( string $raw ): string {
		$trimmed = trim( $raw );

		if ( '' === $trimmed ) {
			return '';
		}

		$collapsed = preg_replace( '/\s+/u', ' ', $trimmed );

		return is_string( $collapsed ) ? $collapsed : trim( $raw );
	}

	public static function fold_case( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $value, 'UTF-8' );
		}

		return strtolower( $value );
	}

	private static function map_state_via_woocommerce( string $country, string $state ): string {
		if ( '' === $country || '' === $state || ! function_exists( 'WC' ) ) {
			return '';
		}

		$wc = WC();

		if ( ! is_object( $wc ) || ! isset( $wc->countries ) || ! is_object( $wc->countries ) ) {
			return '';
		}

		if ( ! method_exists( $wc->countries, 'get_states' ) ) {
			return '';
		}

		$states = $wc->countries->get_states( $country );

		if ( ! is_array( $states ) || [] === $states ) {
			return '';
		}

		$upper = strtoupper( $state );

		foreach ( $states as $code => $label ) {
			if ( strtoupper( (string) $code ) === $upper ) {
				return strtoupper( (string) $code );
			}
		}

		foreach ( $states as $code => $label ) {
			if ( strtoupper( self::collapse_whitespace( (string) $label ) ) === $upper ) {
				return strtoupper( (string) $code );
			}
		}

		return '';
	}
}
