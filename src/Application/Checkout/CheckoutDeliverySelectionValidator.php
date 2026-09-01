<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Checkout;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidationResult;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use WP_Error;

/**
 * Checkout preflight validation for captured cart delivery selections.
 *
 * Does not calculate shipping, alter totals, write order meta, or mutate cart.
 */
final class CheckoutDeliverySelectionValidator {

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CartDeliverySelectionCapture $cart_capture,
		private CartDeliverySelectionRevalidator $cart_revalidator
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 10, 2 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' )
			&& $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function is_flag_enabled(): bool {
		return $this->feature_flags->is_enabled( 'enable_checkout_delivery_selection_validation' );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function validate_checkout( array $data, WP_Error $errors ): void {
		unset( $data );

		if ( ! $this->is_active() ) {
			return;
		}

		$result = $this->validate_cart();

		if ( $result->valid ) {
			return;
		}

		foreach ( $result->messages as $message ) {
			$errors->add( 'cetech_de_delivery_selection', $message );
		}
	}

	public function validate_cart(): CheckoutDeliveryValidationResult {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return CheckoutDeliveryValidationResult::valid();
		}

		$messages              = [];
		$affected_keys           = [];
		$status_counts           = [];
		$customer_message_shown  = false;

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$key = (string) $cart_item_key;
			$line_result = $this->validate_cart_line( $key, $cart_item );

			if ( null === $line_result ) {
				continue;
			}

			$status = $line_result['status'];
			$status_counts[ $status ] = ( $status_counts[ $status ] ?? 0 ) + 1;

			if ( CartDeliverySelectionRevalidationResult::STATUS_VALID === $status ) {
				continue;
			}

			$affected_keys[] = $key;

			if ( ! $customer_message_shown ) {
				$messages[]             = $this->checkout_error_message( $status, $cart_item );
				$customer_message_shown = true;
			}
		}

		if ( [] === $affected_keys ) {
			return CheckoutDeliveryValidationResult::valid( [], [], $status_counts );
		}

		return CheckoutDeliveryValidationResult::invalid( $messages, $affected_keys, $status_counts );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array{status: string}|null Null when line is out of checkout validation scope.
	 */
	private function validate_cart_line( string $cart_item_key, array $cart_item ): ?array {
		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );

		if ( $product_id <= 0 ) {
			return null;
		}

		$has_selection = isset( $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] );

		if ( $has_selection ) {
			if ( $this->has_hash_mismatch( $cart_item ) ) {
				return [ 'status' => CartDeliverySelectionRevalidationResult::STATUS_INVALID ];
			}

			$revalidation = $this->cart_revalidator->revalidate_cart_item( $cart_item_key, $cart_item );

			return [ 'status' => $revalidation->status ];
		}

		if ( ! $this->cart_capture->should_apply_capture_to_line( $product_id, $variation_id ) ) {
			return null;
		}

		$assessment = $this->cart_capture->assess_product_selection( $product_id, $variation_id );

		if ( 'none' === $assessment['requirement'] ) {
			return null;
		}

		if ( 'blocked' === $assessment['requirement'] ) {
			return [ 'status' => CartDeliverySelectionRevalidationResult::STATUS_UNAVAILABLE ];
		}

		return [ 'status' => CartDeliverySelectionRevalidationResult::STATUS_MISSING ];
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function has_hash_mismatch( array $cart_item ): bool {
		$intent_raw = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;
		$hash_raw   = $cart_item[ CartDeliverySelectionCapture::CART_HASH_KEY ] ?? null;

		if ( null === $intent_raw || null === $hash_raw ) {
			return false;
		}

		$intent = CartDeliverySelectionSessionData::normalizeIntent( $intent_raw );
		$hash   = CartDeliverySelectionSessionData::normalizeHash( $hash_raw );

		if ( null === $intent || null === $hash ) {
			return true;
		}

		return ! hash_equals( CartDeliverySelectionFingerprint::fromIntent( $intent ), $hash );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function checkout_error_message( string $status, array $cart_item = [] ): string {
		if ( CartDeliverySelectionRevalidationResult::STATUS_MISSING === $status ) {
			return __(
				'Please select a delivery option for all products in your cart before checking out.',
				'cetech-woocommerce-delivery-engine'
			);
		}

		$name = '';
		$data = $cart_item['data'] ?? null;

		if ( is_object( $data ) && method_exists( $data, 'get_name' ) ) {
			$name = trim( (string) $data->get_name() );
		}

		if ( '' === $name && function_exists( 'wc_get_product' ) ) {
			$product_id = (int) ( $cart_item['product_id'] ?? 0 );
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;

			if ( is_object( $product ) && method_exists( $product, 'get_name' ) ) {
				$name = trim( (string) $product->get_name() );
			}
		}

		if ( '' !== $name ) {
			return sprintf(
				/* translators: %s: product name */
				__( 'Delivery options for “%s” have changed. Please return to your cart and choose a delivery option. You do not need to remove the product.', 'cetech-woocommerce-delivery-engine' ),
				$name
			);
		}

		return __(
			'Delivery options for an item in your cart have changed. Please return to your cart and choose a delivery option. You do not need to remove the product.',
			'cetech-woocommerce-delivery-engine'
		);
	}
}
