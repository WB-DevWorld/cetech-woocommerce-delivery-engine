<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Configuration\Admin;

/**
 * Shared staff-facing money display. Preserves explicit-zero safety; does not invent free shipping.
 */
final class AdminMoneyFormatter {

	public static function display( string $amount, string $currency = '' ): string {
		$amount   = trim( $amount );
		$currency = strtoupper( trim( $currency ) );

		if ( '' === $amount ) {
			return '—';
		}

		if ( ! is_numeric( $amount ) ) {
			return '' === $currency ? $amount : sprintf( '%s %s', $currency, $amount );
		}

		if ( function_exists( 'wc_price' ) ) {
			$args = [];
			if ( '' !== $currency ) {
				$args['currency'] = $currency;
			}
			$html = (string) wc_price( (float) $amount, $args );
			$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
			$text = preg_replace( '/\s+/u', ' ', trim( $text ) ) ?? trim( $text );

			return '' !== $text ? $text : self::fallback( (float) $amount, $currency );
		}

		return self::fallback( (float) $amount, $currency );
	}

	/**
	 * Editor input precision for staff forms (WooCommerce store decimals when available).
	 */
	public static function input_amount( string $amount ): string {
		$amount = trim( $amount );
		if ( '' === $amount || ! is_numeric( $amount ) ) {
			return $amount;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return number_format( (float) $amount, max( 0, $decimals ), '.', '' );
	}

	private static function fallback( float $amount, string $currency ): string {
		$formatted = number_format( $amount, 2, '.', '' );

		return '' === $currency ? $formatted : sprintf( '%s %s', $currency, $formatted );
	}
}
