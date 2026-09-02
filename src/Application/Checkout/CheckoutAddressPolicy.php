<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Checkout;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\CustomerContext\ApplyCustomerContextToEligibleLinesService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Classic checkout address policy for per-item Delivery Engine context.
 *
 * WooCommerce still owns billing and one checkout shipping address.
 * Delivery Engine owns per-item delivery addresses. Never silently copy.
 */
final class CheckoutAddressPolicy {

	public const POST_USE_CHECKOUT_ADDRESS = 'cetech_de_use_checkout_address';

	public const NONCE_ACTION = 'cetech_de_use_checkout_address';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ApplyCustomerContextToEligibleLinesService $apply_all,
		private CartCustomerContextMutationService $mutation
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'wp_loaded', [ $this, 'maybe_handle_use_checkout_address' ], 22 );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'render_notices' ], 8 );
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_use_checkout_address_action' ], 12 );
		add_filter( 'woocommerce_checkout_posted_data', [ $this, 'maybe_align_posted_shipping' ], 20 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' )
			&& $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function maybe_handle_use_checkout_address(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ self::POST_USE_CHECKOUT_ADDRESS ] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wc_add_notice( __( 'Your cart could not be updated. Please try again.', 'cetech-woocommerce-delivery-engine' ), 'error' );

			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$address = $this->read_checkout_shipping_address();
		$outcome = $this->apply_all->applyCheckoutAddressToIncomplete( WC()->cart->get_cart(), $address );
		$this->mutation->commitToCart( $outcome['contents'] );

		if ( [] !== $outcome['updated'] ) {
			$names = array_filter( array_map( static fn ( array $row ): string => (string) ( $row['name'] ?? '' ), $outcome['updated'] ) );
			wc_add_notice(
				sprintf(
					/* translators: %s: product names */
					__( 'Updated: %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ', ', $names )
				),
				'success'
			);
		}

		if ( [] !== $outcome['skipped'] ) {
			$lines = [];
			foreach ( $outcome['skipped'] as $row ) {
				$lines[] = trim( (string) ( $row['name'] ?? '' ) . ' — ' . (string) ( $row['reason'] ?? '' ) );
			}
			wc_add_notice(
				sprintf(
					/* translators: %s: skipped product reasons */
					__( 'Could not update: %s', 'cetech-woocommerce-delivery-engine' ),
					implode( ' ', $lines )
				),
				'notice'
			);
		}
	}

	public function render_notices(): void {
		if ( ! $this->is_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$summary = $this->summarize_cart( WC()->cart->get_cart() );

		if ( $summary['multi_destination'] ) {
			echo '<div class="woocommerce-info cetech-de-checkout-multi-destination" role="status">';
			echo esc_html__( 'Items in this order will be delivered to multiple destinations. Each item keeps its own delivery address.', 'cetech-woocommerce-delivery-engine' );
			echo '</div>';
		}

		if ( $summary['has_pickup'] && $summary['has_delivery'] ) {
			echo '<div class="woocommerce-info cetech-de-checkout-mixed-fulfilment" role="status">';
			echo esc_html__( 'This order includes Store Pickup and Delivery. Pickup items ignore the checkout shipping address.', 'cetech-woocommerce-delivery-engine' );
			echo '</div>';
		}

		if ( $summary['incomplete_delivery'] > 0 ) {
			echo '<div class="woocommerce-error cetech-de-checkout-incomplete-address" role="alert">';
			echo esc_html__( 'One or more items need a complete delivery address before you can place this order. Complete the address on those items, or use the checkout shipping address for incomplete delivery items.', 'cetech-woocommerce-delivery-engine' );
			echo '</div>';
		}
	}

	public function render_use_checkout_address_action(): void {
		if ( ! $this->is_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$summary = $this->summarize_cart( WC()->cart->get_cart() );
		if ( $summary['incomplete_delivery'] <= 0 ) {
			return;
		}

		$action = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		echo '<form class="cetech-de-use-checkout-address" method="post" action="' . esc_url( $action ) . '">';
		echo '<input type="hidden" name="' . esc_attr( self::POST_USE_CHECKOUT_ADDRESS ) . '" value="1" />';
		echo wp_nonce_field( self::NONCE_ACTION, '_wpnonce', true, false );
		echo '<button type="submit" class="button">';
		echo esc_html__( 'Use checkout shipping address for incomplete delivery items', 'cetech-woocommerce-delivery-engine' );
		echo '</button>';
		echo '</form>';
	}

	/**
	 * Case A: when every managed Delivery line shares one complete address,
	 * align WooCommerce shipping fields to that address. Never overwrite
	 * per-item Delivery Engine context.
	 *
	 * @param array<string, mixed> $data
	 *
	 * @return array<string, mixed>
	 */
	public function maybe_align_posted_shipping( array $data ): array {
		if ( ! $this->is_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $data;
		}

		$shared = $this->shared_complete_delivery_address( WC()->cart->get_cart() );
		if ( ! $shared instanceof DeliveryAddress ) {
			return $data;
		}

		$data['shipping_country']   = $shared->matching->country;
		$data['shipping_state']    = $shared->matching->state;
		$data['shipping_city']     = $shared->matching->city;
		$data['shipping_postcode'] = $shared->matching->postcode;
		$data['shipping_address_1'] = $shared->address_1;
		$data['shipping_address_2'] = $shared->address_2;

		if ( '' !== $shared->recipient->first_name ) {
			$data['shipping_first_name'] = $shared->recipient->first_name;
		}
		if ( '' !== $shared->recipient->last_name ) {
			$data['shipping_last_name'] = $shared->recipient->last_name;
		}
		if ( '' !== $shared->recipient->company ) {
			$data['shipping_company'] = $shared->recipient->company;
		}
		if ( '' !== $shared->recipient->phone ) {
			$data['shipping_phone'] = $shared->recipient->phone;
		}

		return $data;
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array{
	 *     multi_destination: bool,
	 *     has_pickup: bool,
	 *     has_delivery: bool,
	 *     incomplete_delivery: int,
	 *     complete_identities: list<string>
	 * }
	 */
	public function summarize_cart( array $contents ): array {
		$identities          = [];
		$has_pickup          = false;
		$has_delivery        = false;
		$incomplete_delivery = 0;

		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$intent = CartDeliverySelectionSessionData::normalizeIntent(
				$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);
			if ( null === $intent ) {
				continue;
			}

			$choice  = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );
			$context = CustomerCartContext::fromCartItem( $item );

			if ( FulfilmentChoice::StorePickup->value === $choice ) {
				$has_pickup = true;
				continue;
			}

			if ( FulfilmentChoice::Delivery->value !== $choice ) {
				continue;
			}

			$has_delivery = true;

			if ( ! $context instanceof CustomerCartContext || ! $context->hasCompleteDeliveryAddress() ) {
				++$incomplete_delivery;
				continue;
			}

			if ( is_string( $context->delivery_location_identity ) && '' !== $context->delivery_location_identity ) {
				$identities[] = $context->delivery_location_identity;
			}
		}

		$unique = array_values( array_unique( $identities ) );

		return [
			'multi_destination'    => count( $unique ) > 1,
			'has_pickup'           => $has_pickup,
			'has_delivery'         => $has_delivery,
			'incomplete_delivery'  => $incomplete_delivery,
			'complete_identities'  => $unique,
		];
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	public function shared_complete_delivery_address( array $contents ): ?DeliveryAddress {
		$summary = $this->summarize_cart( $contents );
		if ( $summary['incomplete_delivery'] > 0 || count( $summary['complete_identities'] ) !== 1 ) {
			return null;
		}

		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$context = CustomerCartContext::fromCartItem( $item );
			if ( $context instanceof CustomerCartContext && $context->hasCompleteDeliveryAddress() && $context->delivery_address instanceof DeliveryAddress ) {
				return $context->delivery_address;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function read_checkout_shipping_address(): array {
		$posted = $this->posted_shipping_fields();
		if ( '' !== (string) ( $posted['country'] ?? '' ) ) {
			return $posted;
		}

		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->customer ) || ! is_object( WC()->customer ) ) {
			return $posted;
		}

		$customer = WC()->customer;

		return [
			'country'    => method_exists( $customer, 'get_shipping_country' ) ? (string) $customer->get_shipping_country() : '',
			'state'      => method_exists( $customer, 'get_shipping_state' ) ? (string) $customer->get_shipping_state() : '',
			'city'       => method_exists( $customer, 'get_shipping_city' ) ? (string) $customer->get_shipping_city() : '',
			'postcode'   => method_exists( $customer, 'get_shipping_postcode' ) ? (string) $customer->get_shipping_postcode() : '',
			'address_1'  => method_exists( $customer, 'get_shipping_address_1' ) ? (string) $customer->get_shipping_address_1() : '',
			'address_2'  => method_exists( $customer, 'get_shipping_address_2' ) ? (string) $customer->get_shipping_address_2() : '',
			'first_name' => method_exists( $customer, 'get_shipping_first_name' ) ? (string) $customer->get_shipping_first_name() : '',
			'last_name'  => method_exists( $customer, 'get_shipping_last_name' ) ? (string) $customer->get_shipping_last_name() : '',
			'company'    => method_exists( $customer, 'get_shipping_company' ) ? (string) $customer->get_shipping_company() : '',
			'phone'      => method_exists( $customer, 'get_shipping_phone' ) ? (string) $customer->get_shipping_phone() : '',
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function posted_shipping_fields(): array {
		$use_shipping = ! empty( $_POST['ship_to_different_address'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$prefix       = $use_shipping || isset( $_POST['shipping_country'] ) ? 'shipping_' : 'billing_'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return [
			'country'    => $this->posted_field( $prefix . 'country' ),
			'state'      => $this->posted_field( $prefix . 'state' ),
			'city'       => $this->posted_field( $prefix . 'city' ),
			'postcode'   => $this->posted_field( $prefix . 'postcode' ),
			'address_1'  => $this->posted_field( $prefix . 'address_1' ),
			'address_2'  => $this->posted_field( $prefix . 'address_2' ),
			'first_name' => $this->posted_field( $prefix . 'first_name' ),
			'last_name'  => $this->posted_field( $prefix . 'last_name' ),
			'company'    => $this->posted_field( $prefix . 'company' ),
			'phone'      => $this->posted_field( $prefix . 'phone' ),
		];
	}

	private function posted_field( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}
}
