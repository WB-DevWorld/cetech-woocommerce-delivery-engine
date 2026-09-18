<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

use CetechDeliveryEngine\Domain\CustomerContext\LocationNormalizer;

/**
 * Deterministic geography name folding for exact canonical/alias lookup.
 *
 * Never fuzzy. Acccra must not match Accra.
 */
final class GeographyNameNormalizer {

	public static function normalize( string $raw ): string {
		$collapsed = LocationNormalizer::collapse_whitespace( $raw );
		$folded    = LocationNormalizer::fold_case( $collapsed );

		return self::fold_ascii( $folded );
	}

	/**
	 * Country-neutral administrative core name. Strips trailing type tokens
	 * such as region/state/province so "Greater Accra Region" and
	 * "Greater Accra" can reconcile when the remainder is unique.
	 */
	public static function administrative_core( string $raw ): string {
		$normalized = self::normalize( $raw );
		$tokens     = preg_split( '/\s+/', $normalized ) ?: [];
		$suffixes   = [
			'region',
			'state',
			'province',
			'prefecture',
			'district',
			'county',
			'municipality',
			'territory',
			'division',
			'oblast',
			'governorate',
			'department',
			'parish',
			'canton',
			'voivodeship',
			'emirate',
			'krai',
			'area',
			'zone',
			'borough',
			'township',
			'commune',
		];
		while ( count( $tokens ) > 1 && in_array( (string) end( $tokens ), $suffixes, true ) ) {
			array_pop( $tokens );
		}

		return implode( ' ', $tokens );
	}

	public static function fold_ascii( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( function_exists( 'iconv' ) ) {
			$ascii = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $value );
			if ( is_string( $ascii ) && '' !== $ascii ) {
				return LocationNormalizer::fold_case( $ascii );
			}
		}

		return $value;
	}

	public static function new_location_key(): string {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
