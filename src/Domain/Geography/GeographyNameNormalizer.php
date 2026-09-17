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
