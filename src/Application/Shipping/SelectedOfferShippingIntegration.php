<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;

/**
 * Registers the selected-offer WooCommerce shipping method for zone assignment.
 *
 * Rate calculation and managed-package exclusivity remain behind
 * ShippingRateCalculationGate. This class is listed in WooCommerce's
 * shipping-method registry whenever WooCommerce and the Delivery Engine are
 * active so administrators can add Delivery to a zone before activating
 * storefront runtime. The method is never auto-inserted into a zone.
 */
final class SelectedOfferShippingIntegration {

	public function __construct(
		private ShippingRateCalculationGate $gate
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		add_filter( 'woocommerce_shipping_methods', [ $this, 'register_shipping_method' ] );
		add_filter( 'woocommerce_package_rates', [ $this, 'filter_managed_package_rates' ], 100, 2 );
	}

	/**
	 * @param array<string, class-string> $methods
	 *
	 * @return array<string, class-string>
	 */
	public function register_shipping_method( array $methods ): array {
		$methods[ SelectedOfferShippingMethod::METHOD_ID ] = SelectedOfferShippingMethod::class;

		return $methods;
	}

	/**
	 * Keep Delivery Engine rates exclusive on managed packages when a DE rate is present.
	 *
	 * @param array<string, mixed> $rates
	 * @param array<string, mixed> $package
	 *
	 * @return array<string, mixed>
	 */
	public function filter_managed_package_rates( array $rates, array $package ): array {
		if ( ! $this->gate->is_runtime_active() ) {
			return $rates;
		}

		if ( ! DeliveryGroupIdentity::is_managed_package( $package ) ) {
			return $rates;
		}

		$managed = [];

		foreach ( $rates as $rate_id => $rate ) {
			$method_id = '';

			if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
				$method_id = (string) $rate->get_method_id();
			} elseif ( is_string( $rate_id ) && str_starts_with( $rate_id, SelectedOfferShippingMethod::METHOD_ID ) ) {
				$method_id = SelectedOfferShippingMethod::METHOD_ID;
			}

			if ( SelectedOfferShippingMethod::METHOD_ID === $method_id ) {
				$managed[ $rate_id ] = $rate;
			}
		}

		// DE-managed packages must never fall back to native WooCommerce methods.
		// An empty result fails closed (no Flat Rate / Local Pickup / leftover methods).
		return $managed;
	}
}
