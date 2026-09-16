<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;

/**
 * Revalidates captured cart delivery selections against live product rules.
 *
 * Does not mutate cart, calculate shipping, or touch checkout/orders.
 */
final class CartDeliverySelectionRevalidator {

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private ProductDeliverySelectionValidator $selection_validator
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'woocommerce_before_cart', [ $this, 'maybe_show_cart_warnings' ], 10 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	/**
	 * @return list<CartDeliverySelectionRevalidationResult>
	 */
	public function revalidate_cart(): array {
		$results = [];

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $results;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$results[] = $this->revalidate_cart_item( (string) $cart_item_key, $cart_item );
		}

		return $results;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function revalidate_cart_item( string $cart_item_key, array $cart_item ): CartDeliverySelectionRevalidationResult {
		if ( ! empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ) ) {
			$stored_intent = CartDeliverySelectionSessionData::normalizeIntent(
				$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);

			return new CartDeliverySelectionRevalidationResult(
				$cart_item_key,
				CartDeliverySelectionRevalidationResult::STATUS_NEEDS_RESELECTION,
				$this->customer_warning_message( $cart_item ),
				$stored_intent,
				null
			);
		}

		$stored_raw = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;

		if ( null === $stored_raw ) {
			return new CartDeliverySelectionRevalidationResult(
				$cart_item_key,
				CartDeliverySelectionRevalidationResult::STATUS_MISSING,
				'',
				null,
				null
			);
		}

		$stored_intent = CartDeliverySelectionSessionData::normalizeIntent( $stored_raw );

		if ( null === $stored_intent ) {
			return new CartDeliverySelectionRevalidationResult(
				$cart_item_key,
				CartDeliverySelectionRevalidationResult::STATUS_INVALID,
				$this->customer_warning_message( $cart_item ),
				null,
				null
			);
		}

		$product_id   = (int) ( $cart_item['product_id'] ?? $stored_intent['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$display_key  = (string) ( $stored_intent['display_key'] ?? '' );

		if ( $product_id <= 0 || '' === $display_key ) {
			return new CartDeliverySelectionRevalidationResult(
				$cart_item_key,
				CartDeliverySelectionRevalidationResult::STATUS_INVALID,
				$this->customer_warning_message( $cart_item ),
				$stored_intent,
				null
			);
		}

		$result = $this->selection_validator->validate(
			$product_id,
			$variation_id > 0 ? $variation_id : null,
			$display_key
		);

		if ( ! $result->valid ) {
			$status = $this->status_from_error_code( (string) ( $result->error_code ?? '' ) );

			return new CartDeliverySelectionRevalidationResult(
				$cart_item_key,
				$status,
				$this->customer_warning_message( $cart_item ),
				$stored_intent,
				null
			);
		}

		if ( ! is_array( $result->intent ) ) {
			return new CartDeliverySelectionRevalidationResult(
				$cart_item_key,
				CartDeliverySelectionRevalidationResult::STATUS_INVALID,
				$this->customer_warning_message( $cart_item ),
				$stored_intent,
				null
			);
		}

		if ( ! CartDeliverySelectionFingerprint::matches( $stored_intent, $result->intent ) ) {
			return new CartDeliverySelectionRevalidationResult(
				$cart_item_key,
				CartDeliverySelectionRevalidationResult::STATUS_STALE,
				$this->customer_warning_message( $cart_item ),
				$stored_intent,
				$result->intent
			);
		}

		return new CartDeliverySelectionRevalidationResult(
			$cart_item_key,
			CartDeliverySelectionRevalidationResult::STATUS_VALID,
			'',
			$stored_intent,
			$result->intent
		);
	}

	public function maybe_show_cart_warnings(): void {
		if ( ! $this->is_active() || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		$shown = false;

		foreach ( $this->revalidate_cart() as $result ) {
			if ( ! $result->isActionable() ) {
				continue;
			}

			if ( CartDeliverySelectionRevalidationResult::STATUS_NEEDS_RESELECTION === $result->status ) {
				continue;
			}

			if ( $shown ) {
				break;
			}

			wc_add_notice( $result->message, 'notice' );
			$shown = true;
		}
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function customer_warning_message( array $cart_item = [] ): string {
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
				__( 'Delivery options for “%s” have changed. Please choose a delivery option in your cart. You do not need to remove the product.', 'cetech-woocommerce-delivery-engine' ),
				$name
			);
		}

		return __(
			'Delivery options for an item in your cart have changed. Please choose a delivery option. You do not need to remove the product.',
			'cetech-woocommerce-delivery-engine'
		);
	}

	private function status_from_error_code( string $error_code ): string {
		if ( 'option_unavailable' === $error_code ) {
			return CartDeliverySelectionRevalidationResult::STATUS_UNAVAILABLE;
		}

		if ( 'option_not_found' === $error_code ) {
			return CartDeliverySelectionRevalidationResult::STATUS_STALE;
		}

		return CartDeliverySelectionRevalidationResult::STATUS_INVALID;
	}
}
