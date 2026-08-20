<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WooCommerce\Destination;

use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;

/**
 * Reads WooCommerce country state lists. Does not rewrite Delivery Area data.
 */
final class WooCommerceStateCatalog implements WooCommerceStateCatalogInterface {

	public function states_for_country( string $country_code ): array {
		$country_code = strtoupper( trim( $country_code ) );

		if ( '' === $country_code || ! function_exists( 'WC' ) ) {
			return [];
		}

		$woocommerce = WC();

		if ( ! is_object( $woocommerce ) || ! isset( $woocommerce->countries ) || ! is_object( $woocommerce->countries ) ) {
			return [];
		}

		if ( ! method_exists( $woocommerce->countries, 'get_states' ) ) {
			return [];
		}

		$states = $woocommerce->countries->get_states( $country_code );

		if ( ! is_array( $states ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $states as $code => $label ) {
			$code  = trim( (string) $code );
			$label = trim( (string) $label );

			if ( '' === $code ) {
				continue;
			}

			$normalized[ $code ] = $label;
		}

		return $normalized;
	}
}
