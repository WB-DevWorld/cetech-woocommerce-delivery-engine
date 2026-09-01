<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliveryValidationResult;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use WP_Error;

/**
 * Store API checkout validation using the same Classic business rules.
 */
final class BlocksCheckoutValidation {

	public function __construct(
		private CheckoutDeliverySelectionValidator $classic_validator,
		private BlocksStoreApiExtension $store_api,
		private ShippingRateCalculationGate $shipping_gate
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'validate_checkout_request' ], 5, 2 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', [ $this, 'after_customer_update' ], 20, 2 );
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'append_cart_errors' ], 10, 2 );
	}

	/**
	 * @param mixed $order
	 * @param mixed $request
	 */
	public function validate_checkout_request( $order, $request ): void {
		unset( $order, $request );

		if ( ! $this->classic_validator->is_active() ) {
			return;
		}

		$this->assert_cart_and_rates_valid();
	}

	/**
	 * Address changes must re-resolve. WooCommerce already recalculates shipping;
	 * this re-runs DE validation so a stale choice cannot silently survive Place Order.
	 *
	 * @param mixed $customer
	 * @param mixed $request
	 */
	public function after_customer_update( $customer, $request ): void {
		unset( $customer, $request );

		if ( ! $this->classic_validator->is_active() ) {
			return;
		}

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_shipping();
		}
	}

	/**
	 * @param mixed $errors
	 * @param mixed $cart
	 */
	public function append_cart_errors( $errors, $cart ): void {
		unset( $cart );

		if ( ! $errors instanceof WP_Error || ! $this->classic_validator->is_active() ) {
			return;
		}

		$result = $this->classic_validator->validate_cart();

		if ( ! $result->valid ) {
			foreach ( $result->messages as $message ) {
				$errors->add( 'cetech_de_delivery_selection', $message );
			}
		}

		if ( $this->shipping_gate->is_runtime_active() && ! $this->managed_packages_have_de_rates() ) {
			$errors->add( 'cetech_de_delivery_selection', $this->fail_closed_message() );
		}
	}

	public function assert_cart_and_rates_valid(): void {
		$this->enforce_selection_result( $this->classic_validator->validate_cart() );

		if ( $this->shipping_gate->is_runtime_active() && ! $this->managed_packages_have_de_rates() ) {
			$this->reject( $this->fail_closed_message() );
		}
	}

	public function enforce_selection_result( CheckoutDeliveryValidationResult $result ): void {
		if ( $result->valid ) {
			return;
		}

		$this->reject( $result->messages[0] ?? $this->stale_choice_message() );
	}

	public function managed_packages_have_de_rates(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return true;
		}

		$packages = WC()->cart->get_shipping_packages();

		if ( ! is_array( $packages ) ) {
			return true;
		}

		$calculated = $this->calculated_shipping_packages( $packages );

		$rows = is_array( $calculated ) && [] !== $calculated ? $calculated : $packages;

		foreach ( $rows as $index => $row ) {
			$package = is_array( $row ) ? $row : [];

			if ( [] === $package && is_array( $packages[ $index ] ?? null ) ) {
				$package = $packages[ $index ];
			}

			if ( ! DeliveryGroupIdentity::is_managed_package( $package ) ) {
				continue;
			}

			$rates = BlocksStoreApiExtension::rates_from_calculated_entry( $row, $package );

			if ( ! $this->store_api->managed_package_is_validly_quoted( $package, $rates ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Prefer already-calculated WC packages (array rows with `rates`) over a
	 * second calculate_shipping() pass. Do not require object-shaped rows.
	 *
	 * @param array<int|string, mixed> $packages
	 *
	 * @return array<int|string, mixed>
	 */
	private function calculated_shipping_packages( array $packages ): array {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return [];
		}

		$shipping = WC()->shipping();
		$existing = method_exists( $shipping, 'get_packages' ) ? $shipping->get_packages() : [];

		if ( is_array( $existing ) && $this->packages_include_rate_rows( $existing ) ) {
			return $existing;
		}

		if ( ! method_exists( $shipping, 'calculate_shipping' ) ) {
			return is_array( $existing ) ? $existing : [];
		}

		$calculated = $shipping->calculate_shipping( $packages );

		return is_array( $calculated ) ? $calculated : [];
	}

	/**
	 * @param array<int|string, mixed> $packages
	 */
	private function packages_include_rate_rows( array $packages ): bool {
		foreach ( $packages as $row ) {
			if ( is_array( $row ) && array_key_exists( 'rates', $row ) ) {
				return true;
			}

			if ( is_object( $row ) && isset( $row->rates ) ) {
				return true;
			}
		}

		return false;
	}

	private function reject( string $message ): void {
		$class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

		if ( class_exists( $class ) ) {
			throw new $class( 'cetech_de_delivery_selection', $message, 400 );
		}

		throw new \RuntimeException( $message );
	}

	private function stale_choice_message(): string {
		return __(
			'Delivery options for an item in your cart have changed. Please return to your cart and choose a delivery option. You do not need to remove the product.',
			'cetech-woocommerce-delivery-engine'
		);
	}

	private function fail_closed_message(): string {
		return __(
			'Delivery pricing is not available for one or more items in your cart. Native shipping methods cannot be used as a fallback. Please return to your cart or contact the store.',
			'cetech-woocommerce-delivery-engine'
		);
	}
}
