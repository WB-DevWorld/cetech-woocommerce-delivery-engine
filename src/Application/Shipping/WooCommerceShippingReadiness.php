<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;

/**
 * Business-language check that WooCommerce shipping can show Delivery Engine fees.
 */
final class WooCommerceShippingReadiness {

	public function __construct(
		private readonly Requirements $requirements
	) {
	}

	public function is_ready(): bool {
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'cetech_de_woocommerce_shipping_configured', null );
			if ( is_bool( $filtered ) ) {
				return $filtered;
			}
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return false;
		}

		return $this->method_is_assigned_to_a_zone();
	}

	public function settings_url(): string {
		if ( ! $this->requirements->is_woocommerce_active() ) {
			return admin_url( 'plugins.php' );
		}

		return admin_url( 'admin.php?page=wc-settings&tab=shipping' );
	}

	public function status_label(): string {
		return $this->is_ready()
			? 'Ready'
			: 'Action needed';
	}

	public function explanation(): string {
		if ( $this->is_ready() ) {
			return 'The CETECH Delivery shipping method is assigned to a WooCommerce shipping zone.';
		}

		if ( ! $this->requirements->is_woocommerce_active() ) {
			return 'WooCommerce must be active before delivery fees can appear at checkout.';
		}

		return 'Add the CETECH Delivery shipping method to a WooCommerce shipping zone so customers can see delivery fees at checkout. In Add shipping method, choose Delivery. It is listed there while WooCommerce and this plugin are active, including before checkout is activated.';
	}

	private function method_is_assigned_to_a_zone(): bool {
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}

		$method_id = SelectedOfferShippingMethod::METHOD_ID;

		if ( class_exists( 'WC_Shipping_Zones' ) && method_exists( 'WC_Shipping_Zones', 'get_zones' ) ) {
			$zones = \WC_Shipping_Zones::get_zones();
			if ( is_array( $zones ) ) {
				foreach ( $zones as $zone ) {
					if ( $this->zone_has_method( $zone['shipping_methods'] ?? [], $method_id ) ) {
						return true;
					}
				}
			}
		}

		if ( class_exists( 'WC_Shipping_Zone' ) ) {
			$rest_of_world = new \WC_Shipping_Zone( 0 );
			if ( method_exists( $rest_of_world, 'get_shipping_methods' ) && $this->zone_has_method( $rest_of_world->get_shipping_methods(), $method_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $methods
	 */
	private function zone_has_method( mixed $methods, string $method_id ): bool {
		if ( ! is_array( $methods ) ) {
			return false;
		}

		foreach ( $methods as $method ) {
			$id = is_object( $method ) ? (string) ( $method->id ?? '' ) : (string) ( $method['id'] ?? '' );
			$enabled = true;
			if ( is_object( $method ) && method_exists( $method, 'is_enabled' ) ) {
				$enabled = (bool) $method->is_enabled();
			} elseif ( is_object( $method ) && isset( $method->enabled ) ) {
				$enabled = 'yes' === (string) $method->enabled || true === $method->enabled;
			} elseif ( is_array( $method ) && isset( $method['enabled'] ) ) {
				$enabled = 'yes' === (string) $method['enabled'] || true === $method['enabled'];
			}

			if ( $method_id === $id && $enabled ) {
				return true;
			}
		}

		return false;
	}
}
