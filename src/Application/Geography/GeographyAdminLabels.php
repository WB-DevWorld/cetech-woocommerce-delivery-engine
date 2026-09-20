<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Geography;

/**
 * Country-appropriate administrative-area labels. Internal type remains administrative.
 */
final class GeographyAdminLabels {

	public static function administrative_area_label( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		$from_woo     = self::woocommerce_state_label( $country_code );
		if ( '' !== $from_woo ) {
			return $from_woo;
		}

		return match ( $country_code ) {
			'US', 'MX', 'AU', 'IN', 'NG', 'BR', 'MY' => __( 'State', 'cetech-woocommerce-delivery-engine' ),
			'CA', 'CN', 'NL', 'AR', 'ZA' => __( 'Province', 'cetech-woocommerce-delivery-engine' ),
			'GB', 'IE' => __( 'County', 'cetech-woocommerce-delivery-engine' ),
			'JP' => __( 'Prefecture', 'cetech-woocommerce-delivery-engine' ),
			'DE', 'AT' => __( 'State', 'cetech-woocommerce-delivery-engine' ),
			'FR', 'IT' => __( 'Region', 'cetech-woocommerce-delivery-engine' ),
			'GH' => __( 'Region', 'cetech-woocommerce-delivery-engine' ),
			default => __( 'Region', 'cetech-woocommerce-delivery-engine' ),
		};
	}

	private static function woocommerce_state_label( string $country_code ): string {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->countries ) ) {
			return '';
		}
		$countries = WC()->countries;
		if ( ! is_object( $countries ) || ! method_exists( $countries, 'get_country_locale' ) ) {
			return '';
		}
		$locale = $countries->get_country_locale();
		if ( ! is_array( $locale ) ) {
			return '';
		}
		$label = $locale[ $country_code ]['state']['label'] ?? '';

		return is_string( $label ) ? $label : '';
	}
}
