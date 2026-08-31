<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;

/**
 * Registers customer-safe Delivery Engine data on Store API cart / cart-item / checkout.
 */
final class BlocksStoreApiExtension {

	public const CART_ENDPOINT = 'cart';

	public const CART_ITEM_ENDPOINT = 'cart-item';

	public const CHECKOUT_ENDPOINT = 'checkout';

	public function __construct(
		private CartDeliverySelectionCapture $cart_capture,
		private CartDeliverySelectionRevalidator $cart_revalidator,
		private ShippingRateCalculationGate $shipping_gate
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		$this->register_endpoint(
			self::CART_ITEM_ENDPOINT,
			[ $this, 'cart_item_data' ],
			[ $this, 'cart_item_schema' ]
		);
		$this->register_endpoint(
			self::CART_ENDPOINT,
			[ $this, 'cart_data' ],
			[ $this, 'cart_schema' ]
		);
		$this->register_endpoint(
			self::CHECKOUT_ENDPOINT,
			[ $this, 'cart_data' ],
			[ $this, 'cart_schema' ]
		);
	}

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array<string, mixed>
	 */
	public function cart_item_data( array $cart_item = [] ): array {
		$key = is_string( $cart_item['key'] ?? null ) ? (string) $cart_item['key'] : '';

		return BlocksPublicPayload::cart_item(
			$cart_item,
			$this->cart_capture,
			$this->cart_revalidator,
			$key
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cart_item_schema(): array {
		return [
			'fulfilment_choice'       => [ 'description' => 'Public fulfilment choice.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'fulfilment_availability' => [ 'description' => 'Public fulfilment availability.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'delivery_option_label'   => [ 'description' => 'Public Delivery Option label.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'estimate_text'           => [ 'description' => 'Public ETA or readiness text.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'pickup_location_label'   => [ 'description' => 'Public pickup location name.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'pickup_address'          => [ 'description' => 'Public pickup address.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'pickup_instructions'     => [ 'description' => 'Public pickup instructions.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'is_pickup'               => [ 'description' => 'Whether this line is store pickup.', 'type' => 'boolean', 'readonly' => true ],
			'requires_selection'      => [ 'description' => 'Whether a Delivery Engine selection is required.', 'type' => 'boolean', 'readonly' => true ],
			'selection_valid'         => [ 'description' => 'Whether the captured selection is still valid.', 'type' => [ 'boolean', 'null' ], 'readonly' => true ],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cart_data(): array {
		$packages = [];
		$index    = 0;

		foreach ( $this->shipping_packages() as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}

			$packages[] = BlocksPublicPayload::package( $package, $index );
			++$index;
		}

		return BlocksPublicPayload::strip_forbidden(
			[
				'packages'             => $packages,
				'has_managed_packages' => $this->has_managed_package( $packages ),
				'runtime_active'       => $this->shipping_gate->is_runtime_active(),
			]
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function cart_schema(): array {
		return [
			'packages'             => [
				'description' => 'Customer-safe Delivery Engine package presentation.',
				'type'        => 'array',
				'readonly'    => true,
			],
			'has_managed_packages' => [
				'description' => 'Whether the cart contains Delivery Engine managed packages.',
				'type'        => 'boolean',
				'readonly'    => true,
			],
			'runtime_active'       => [
				'description' => 'Whether Delivery Engine shipping runtime is active.',
				'type'        => 'boolean',
				'readonly'    => true,
			],
		];
	}

	/**
	 * Fail-closed: a managed package must expose a Delivery Engine rate, never native fallback.
	 *
	 * @param array<string, mixed> $package
	 * @param array<string, mixed> $rates
	 */
	public function managed_package_has_delivery_engine_rate( array $package, array $rates ): bool {
		if ( ! DeliveryGroupIdentity::is_managed_package( $package ) ) {
			return true;
		}

		foreach ( $rates as $rate_id => $rate ) {
			$method_id = '';

			if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
				$method_id = (string) $rate->get_method_id();
			} elseif ( is_string( $rate_id ) && str_starts_with( $rate_id, SelectedOfferShippingMethod::METHOD_ID ) ) {
				$method_id = SelectedOfferShippingMethod::METHOD_ID;
			}

			if ( SelectedOfferShippingMethod::METHOD_ID === $method_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param callable(): array<string, mixed> $data
	 * @param callable(): array<string, mixed> $schema
	 */
	private function register_endpoint( string $endpoint, callable $data, callable $schema ): void {
		$args = [
			'endpoint'        => $endpoint,
			'namespace'       => BlocksCheckoutAdapter::NAMESPACE,
			'data_callback'   => $data,
			'schema_callback' => $schema,
			'schema_type'     => defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A',
		];

		woocommerce_store_api_register_endpoint_data( $args );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function shipping_packages(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}

		$packages = WC()->cart->get_shipping_packages();

		return is_array( $packages ) ? array_values( $packages ) : [];
	}

	/**
	 * @param list<array<string, mixed>> $packages
	 */
	private function has_managed_package( array $packages ): bool {
		foreach ( $packages as $package ) {
			if ( ! empty( $package['managed'] ) ) {
				return true;
			}
		}

		return false;
	}
}
