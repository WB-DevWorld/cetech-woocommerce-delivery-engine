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

	/**
	 * Customer-readable name of a region: WooCommerce's name for the code ("AH" => "Ashanti
	 * Region"), the stored text itself when WooCommerce does not list it, '' when there is none.
	 */
	public static function stateLabel( string $country, string $state ): string {
		$state = self::collapse_whitespace( $state );
		if ( '' === $state ) {
			return '';
		}

		$countries = self::woocommerce_countries();
		if ( null !== $countries && method_exists( $countries, 'get_states' ) ) {
			$states = $countries->get_states( self::country( $country ) );
			if ( is_array( $states ) ) {
				foreach ( $states as $code => $label ) {
					if ( strtoupper( (string) $code ) === strtoupper( $state ) ) {
						$name = self::plain_label( (string) $label );

						return '' !== $name ? $name : $state;
					}
				}
			}
		}

		return $state;
	}

	/**
	 * Customer-readable name of a country code ("GH" => "Ghana"), '' when WooCommerce cannot name it.
	 */
	public static function countryLabel( string $country ): string {
		$code      = self::country( $country );
		$countries = self::woocommerce_countries();
		if ( '' === $code || null === $countries || ! method_exists( $countries, 'get_countries' ) ) {
			return '';
		}

		$all = $countries->get_countries();
		if ( ! is_array( $all ) || ! isset( $all[ $code ] ) ) {
			return '';
		}

		$name = self::plain_label( (string) $all[ $code ] );
		// "United States (US)" reads as "United States" in a sentence such as "Delivery to …".
		$bare = trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', $name ) );

		return '' !== $bare ? $bare : $name;
	}

	private static function woocommerce_countries(): ?object {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();

		return is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) ? $wc->countries : null;
	}

	/**
	 * WooCommerce stores some names with HTML entities; callers escape on output, so decode once here.
	 */
	private static function plain_label( string $label ): string {
		return self::collapse_whitespace( html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
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
