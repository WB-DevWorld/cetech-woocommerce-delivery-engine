<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Integrations\Blocks;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Checkout\CheckoutAddressPolicy;
use CetechDeliveryEngine\Application\Shipping\DeliveryGroupIdentity;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Shipping\SelectedOfferShippingMethod;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

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
		private ShippingRateCalculationGate $shipping_gate,
		private ?CheckoutAddressPolicy $address_policy = null
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
			'needs_reselection'       => [ 'description' => 'Whether the customer must choose a new delivery option.', 'type' => 'boolean', 'readonly' => true ],
			'reselection_message'     => [ 'description' => 'Customer-safe reselection message.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'reselection_options'     => [ 'description' => 'Public delivery options available for reselection.', 'type' => 'array', 'readonly' => true ],
			'product_name'            => [ 'description' => 'Public product name for the affected cart line.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'cart_item_key'           => [ 'description' => 'WooCommerce cart item key.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'has_customer_context'    => [ 'description' => 'Whether this line has a Delivery Engine customer context.', 'type' => 'boolean', 'readonly' => true ],
			'address_complete'        => [ 'description' => 'Whether the Delivery address is complete.', 'type' => 'boolean', 'readonly' => true ],
			'can_edit_context'       => [ 'description' => 'Whether the customer can edit this line context.', 'type' => 'boolean', 'readonly' => true ],
			'can_split'             => [ 'description' => 'Whether quantity can be split onto another destination.', 'type' => 'boolean', 'readonly' => true ],
			'quantity'               => [ 'description' => 'Cart line quantity.', 'type' => 'integer', 'readonly' => true ],
			'locality'              => [ 'description' => 'Customer-safe destination locality.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'estimate_line'         => [ 'description' => 'Formatted estimated delivery line.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'summary_kicker'        => [ 'description' => 'Compact summary kicker, such as Store Pickup.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'summary_title'         => [ 'description' => 'Compact summary title.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'summary_meta'          => [ 'description' => 'Compact summary meta line.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'matching_location'     => [ 'description' => 'Customer matching location for editing this cart line.', 'type' => [ 'object', 'null' ], 'readonly' => true ],
			'delivery_address'      => [ 'description' => 'Customer delivery address for editing this cart line.', 'type' => [ 'object', 'null' ], 'readonly' => true ],
			'available_options'     => [ 'description' => 'Public delivery options available for this line.', 'type' => 'array', 'readonly' => true ],
			'ui_anchor'             => [ 'description' => 'Customer-safe cart editor DOM anchor.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'address_needed'        => [ 'description' => 'Whether this Delivery line still needs an address.', 'type' => 'boolean', 'readonly' => true ],
			'address_action_label'  => [ 'description' => 'Customer-facing editor action label.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
			'has_matching_location' => [ 'description' => 'Whether a matching destination is already selected.', 'type' => 'boolean', 'readonly' => true ],
			'destination_summary'   => [ 'description' => 'Compact destination summary for the cart editor.', 'type' => [ 'string', 'null' ], 'readonly' => true ],
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

		$notices = $this->checkout_notices();
		$mutation = $GLOBALS[ BlocksCartContextCommandHandler::RESULT_GLOBAL ] ?? null;
		$plan = [];
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$plan = CustomerStorefrontCopy::delivery_plan( WC()->cart->get_cart() );
		}

		return BlocksPublicPayload::strip_forbidden(
			[
				'packages'             => $packages,
				'has_managed_packages' => $this->has_managed_package( $packages ),
				'runtime_active'       => $this->shipping_gate->is_runtime_active(),
				'multi_destination'    => (bool) ( $notices['multi_destination'] ?? false ),
				'mixed_fulfilment'     => (bool) ( $notices['mixed_fulfilment'] ?? false ),
				'incomplete_delivery'  => (int) ( $notices['incomplete_delivery'] ?? 0 ),
				'notices'              => $notices['messages'] ?? [],
				'can_apply_checkout_address' => ! empty( $notices['can_apply_checkout_address'] ),
				'first_incomplete_anchor' => (string) ( $notices['first_incomplete_anchor'] ?? '' ),
				'keep_address_note'    => (bool) ( $notices['keep_address_note'] ?? false )
					? CustomerStorefrontCopy::items_keep_own_address()
					: null,
				'delivery_plan'        => $plan,
				'mutation_result'      => is_array( $mutation ) ? $mutation : null,
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
			'multi_destination'    => [
				'description' => 'Whether managed Delivery lines have more than one destination.',
				'type'        => 'boolean',
				'readonly'    => true,
			],
			'mixed_fulfilment'     => [
				'description' => 'Whether the cart mixes Store Pickup and Delivery.',
				'type'        => 'boolean',
				'readonly'    => true,
			],
			'incomplete_delivery'  => [
				'description' => 'Count of Delivery lines missing a complete address.',
				'type'        => 'integer',
				'readonly'    => true,
			],
			'notices'              => [
				'description' => 'Customer-safe checkout notices.',
				'type'        => 'array',
				'readonly'    => true,
			],
			'can_apply_checkout_address' => [
				'description' => 'Whether the explicit checkout-address action is available.',
				'type'        => 'boolean',
				'readonly'    => true,
			],
			'first_incomplete_anchor' => [
				'description' => 'Customer-safe cart fragment for the first incomplete Delivery line.',
				'type'        => 'string',
				'readonly'    => true,
			],
			'keep_address_note'    => [
				'description' => 'Short note that per-item addresses are kept.',
				'type'        => [ 'string', 'null' ],
				'readonly'    => true,
			],
			'delivery_plan'        => [
				'description' => 'Compact checkout confirmation grouped by destination.',
				'type'        => 'array',
				'readonly'    => true,
			],
			'mutation_result'      => [
				'description' => 'Per-line outcome of the last customer-context mutation.',
				'type'        => [ 'object', 'null' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * @return array{
	 *     multi_destination: bool,
	 *     mixed_fulfilment: bool,
	 *     incomplete_delivery: int,
	 *     can_apply_checkout_address: bool,
	 *     first_incomplete_anchor: string,
	 *     keep_address_note: bool,
	 *     messages: list<array{code: string, message: string}>
	 * }
	 */
	private function checkout_notices(): array {
		$empty = [
			'multi_destination'          => false,
			'mixed_fulfilment'           => false,
			'incomplete_delivery'        => 0,
			'can_apply_checkout_address' => false,
			'first_incomplete_anchor'    => '',
			'keep_address_note'          => false,
			'messages'                   => [],
		];

		if ( ! $this->address_policy instanceof CheckoutAddressPolicy || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $empty;
		}

		$summary = $this->address_policy->summarize_cart( WC()->cart->get_cart() );
		$messages = [];

		if ( $summary['multi_destination'] ) {
			$messages[] = [
				'code'    => 'multi_destination',
				'message' => CustomerStorefrontCopy::multi_destination(),
			];
		}

		if ( $summary['has_pickup'] && $summary['has_delivery'] ) {
			$messages[] = [
				'code'    => 'mixed_fulfilment',
				'message' => CustomerStorefrontCopy::mixed_fulfilment(),
			];
		}

		if ( $summary['incomplete_delivery'] > 0 ) {
			$messages[] = [
				'code'    => 'incomplete_delivery',
				'message' => CustomerStorefrontCopy::incomplete_address( $summary['incomplete_delivery'] ),
			];
		}

		if ( ! empty( $summary['heterogeneous_incomplete_destinations'] ) ) {
			$messages[] = [
				'code'    => 'heterogeneous_destinations',
				'message' => CustomerStorefrontCopy::heterogeneous_incomplete_destinations(),
			];
		}

		return [
			'multi_destination'          => $summary['multi_destination'],
			'mixed_fulfilment'           => $summary['has_pickup'] && $summary['has_delivery'],
			'incomplete_delivery'        => $summary['incomplete_delivery'],
			'can_apply_checkout_address' => ! empty( $summary['can_apply_checkout_address'] ),
			'first_incomplete_anchor'    => (string) ( $summary['first_incomplete_anchor'] ?? '' ),
			'keep_address_note'          => $summary['has_delivery'] && [] !== $summary['complete_identities'],
			'messages'                   => $messages,
		];
	}

	/**
	 * WooCommerce {@see WC_Shipping::calculate_shipping()} returns package arrays
	 * with a `rates` key, not objects with `->rates`. Reading the wrong shape
	 * makes a valid GHS 0 Pickup look like a managed package with no DE quote.
	 *
	 * @param mixed                $calculated_entry Package row from WC shipping, or null.
	 * @param array<string, mixed> $fallback_package Package from get_shipping_packages().
	 *
	 * @return array<string, mixed>
	 */
	public static function rates_from_calculated_entry( mixed $calculated_entry, array $fallback_package = [] ): array {
		if ( is_array( $calculated_entry ) && isset( $calculated_entry['rates'] ) && is_array( $calculated_entry['rates'] ) ) {
			return $calculated_entry['rates'];
		}

		if ( is_object( $calculated_entry ) && isset( $calculated_entry->rates ) ) {
			$object_rates = $calculated_entry->rates;

			if ( is_array( $object_rates ) ) {
				return $object_rates;
			}
		}

		if ( is_array( $fallback_package['rates'] ?? null ) ) {
			return $fallback_package['rates'];
		}

		return [];
	}

	/**
	 * @param list<array{package?: array<string, mixed>, rates?: array<string, mixed>}> $rows
	 */
	public function managed_packages_are_validly_quoted( array $rows ): bool {
		foreach ( $rows as $row ) {
			$package = is_array( $row['package'] ?? null ) ? $row['package'] : [];
			$rates   = is_array( $row['rates'] ?? null ) ? $row['rates'] : [];

			if ( ! $this->managed_package_is_validly_quoted( $package, $rates ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A valid Store Pickup quote is GHS 0 on the Delivery Engine method.
	 * Native WooCommerce rates on a managed package still fail closed.
	 * Unmanaged packages are not judged here.
	 *
	 * @param array<string, mixed> $package
	 * @param array<string, mixed> $rates
	 */
	public function managed_package_is_validly_quoted( array $package, array $rates ): bool {
		if ( ! DeliveryGroupIdentity::is_managed_package( $package ) ) {
			return true;
		}

		if ( $this->has_native_shipping_fallback( $rates ) ) {
			return false;
		}

		return $this->managed_package_has_delivery_engine_rate( $package, $rates );
	}

	/**
	 * Fail-closed: a managed package must expose a Delivery Engine rate, never native fallback.
	 * A Pickup DE rate with cost 0 is a valid priced fulfilment path.
	 *
	 * @param array<string, mixed> $package
	 * @param array<string, mixed> $rates
	 */
	public function managed_package_has_delivery_engine_rate( array $package, array $rates ): bool {
		if ( ! DeliveryGroupIdentity::is_managed_package( $package ) ) {
			return true;
		}

		foreach ( $rates as $rate_id => $rate ) {
			if ( SelectedOfferShippingMethod::METHOD_ID === $this->rate_method_id( $rate_id, $rate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $rates
	 */
	public function has_native_shipping_fallback( array $rates ): bool {
		foreach ( $rates as $rate_id => $rate ) {
			$method_id = $this->rate_method_id( $rate_id, $rate );

			if ( '' !== $method_id && SelectedOfferShippingMethod::METHOD_ID !== $method_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed>|int|string $rate_id
	 * @param mixed                           $rate
	 */
	public function rate_method_id( mixed $rate_id, mixed $rate ): string {
		if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
			return (string) $rate->get_method_id();
		}

		if ( is_string( $rate_id ) && str_starts_with( $rate_id, SelectedOfferShippingMethod::METHOD_ID ) ) {
			return SelectedOfferShippingMethod::METHOD_ID;
		}

		return '';
	}

	/**
	 * Cost of a Delivery Engine rate, or null when the rate is not a DE method.
	 *
	 * @param mixed $rate
	 * @param mixed $rate_id
	 */
	public function delivery_engine_rate_cost( mixed $rate, mixed $rate_id = '' ): ?float {
		if ( SelectedOfferShippingMethod::METHOD_ID !== $this->rate_method_id( $rate_id, $rate ) ) {
			return null;
		}

		if ( is_object( $rate ) && method_exists( $rate, 'get_cost' ) ) {
			return (float) $rate->get_cost();
		}

		if ( is_array( $rate ) && isset( $rate['cost'] ) && is_numeric( $rate['cost'] ) ) {
			return (float) $rate['cost'];
		}

		return 0.0;
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
