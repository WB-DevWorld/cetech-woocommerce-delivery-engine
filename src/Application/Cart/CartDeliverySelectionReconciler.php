<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidationResult;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use WC_Product;

/**
 * Reconciles live administrative Delivery Engine configuration into existing cart lines.
 *
 * Cart is live customer state. Orders remain historical immutable snapshots.
 * Does not silently switch Delivery ↔ Store Pickup or substitute another offer for a price.
 */
final class CartDeliverySelectionReconciler {

	private bool $running = false;

	private bool $notices_shown = false;

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CartDeliverySelectionCapture $cart_capture,
		private ProductDeliverySelectionValidator $selection_validator
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_filter( 'woocommerce_cart_id', [ $this, 'filter_cart_id' ], 10, 5 );
		add_action( 'woocommerce_cart_loaded_from_session', [ $this, 'reconcile_cart' ], 5 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'reconcile_cart' ], 5 );
		add_action( 'woocommerce_before_cart', [ $this, 'maybe_show_cart_notices' ], 5 );
		add_action( 'woocommerce_before_checkout_form', [ $this, 'maybe_show_cart_notices' ], 5 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	/**
	 * @param array<string, mixed> $variation
	 * @param array<string, mixed> $cart_item_data
	 */
	public function filter_cart_id( string $cart_id, $product_id, $variation_id = 0, $variation = [], $cart_item_data = [] ): string {
		unset( $cart_id );

		return CartLineCustomerIdentity::generateCartId(
			(int) $product_id,
			(int) $variation_id,
			is_array( $variation ) ? $variation : [],
			is_array( $cart_item_data ) ? $cart_item_data : []
		);
	}

	public function reconcile_cart(): void {
		if ( ! $this->is_active() || $this->running ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$this->running = true;

		try {
			$cart      = WC()->cart;
			$contents  = $cart->get_cart();
			$updated   = [];
			$changed   = false;

			foreach ( $contents as $cart_item_key => $cart_item ) {
				if ( ! is_array( $cart_item ) ) {
					continue;
				}

				$outcome = $this->reconcile_cart_item( (string) $cart_item_key, $cart_item );
				$updated[ (string) $cart_item_key ] = $outcome->cart_item;

				if ( $outcome->changed ) {
					$changed = true;
				}
			}

			$rekeyed = $this->rekey_contents( $updated );

			if ( $changed || array_keys( $rekeyed ) !== array_keys( $contents ) ) {
				$cart->cart_contents = $rekeyed;

				if ( method_exists( $cart, 'set_session' ) ) {
					$cart->set_session();
				}
			}
		} finally {
			$this->running = false;
		}
	}

	public function maybe_show_cart_notices(): void {
		if ( ! $this->is_active() || $this->notices_shown || ! function_exists( 'wc_add_notice' ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$this->reconcile_cart();
		$this->notices_shown = true;

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) || empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ) ) {
				continue;
			}

			$name = $this->product_name( $cart_item );

			if ( '' === $name ) {
				$name = __( 'a product in your cart', 'cetech-woocommerce-delivery-engine' );
			}

			wc_add_notice(
				sprintf(
					/* translators: %s: product name */
					__( 'Delivery options for “%s” have changed. Please choose a delivery option below. You do not need to remove the product.', 'cetech-woocommerce-delivery-engine' ),
					$name
				),
				'notice'
			);
		}
	}

	/**
	 * Testable per-line reconciliation against live options and a display-key validator.
	 *
	 * @param array<string, mixed>                                                    $cart_item
	 * @param list<ProductDeliveryOption>                                             $live_options
	 * @param callable(string): ProductDeliverySelectionValidationResult              $validate
	 */
	public function reconcile_line(
		string $cart_item_key,
		array $cart_item,
		array $live_options,
		string $requirement,
		callable $validate
	): CartReconciliationOutcome {
		$stored_raw = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;

		if ( null === $stored_raw ) {
			return new CartReconciliationOutcome(
				$cart_item_key,
				CartReconciliationOutcome::ACTION_UNMANAGED,
				$cart_item,
				false,
				CartDeliverySelectionRevalidationResult::STATUS_MISSING,
				''
			);
		}

		$stored_intent = CartDeliverySelectionSessionData::normalizeIntent( $stored_raw );

		if ( null === $stored_intent ) {
			return $this->needs_reselection_outcome(
				$cart_item_key,
				$cart_item,
				CartDeliverySelectionRevalidationResult::STATUS_INVALID
			);
		}

		$product_name = $this->product_name( $cart_item );

		if ( 'required' !== $requirement ) {
			return $this->needs_reselection_outcome(
				$cart_item_key,
				$cart_item,
				'blocked' === $requirement
					? CartDeliverySelectionRevalidationResult::STATUS_UNAVAILABLE
					: CartDeliverySelectionRevalidationResult::STATUS_INVALID,
				$product_name
			);
		}

		$equivalent = CartDeliverySelectionEquivalence::findEquivalentOption( $live_options, $stored_intent );

		if ( null === $equivalent ) {
			return $this->needs_reselection_outcome(
				$cart_item_key,
				$cart_item,
				CartDeliverySelectionRevalidationResult::STATUS_NEEDS_RESELECTION,
				$product_name
			);
		}

		$validation = $validate( $equivalent->display_key );

		if ( ! $validation instanceof ProductDeliverySelectionValidationResult || ! $validation->valid || ! is_array( $validation->intent ) || ! is_array( $validation->matched_option ) ) {
			return $this->needs_reselection_outcome(
				$cart_item_key,
				$cart_item,
				CartDeliverySelectionRevalidationResult::STATUS_NEEDS_RESELECTION,
				$product_name
			);
		}

		$fresh_intent = CartLineCustomerIdentity::overlayCustomerOwned( $validation->intent, $stored_intent );
		$refreshed    = self::apply_refresh( $cart_item, $fresh_intent, $validation->matched_option );
		$refreshed    = CartLineCustomerIdentity::overlayCustomerContext( $refreshed, $cart_item );
		$changed      = $refreshed !== $cart_item
			|| ! CartDeliverySelectionFingerprint::matches( $stored_intent, $fresh_intent );

		return new CartReconciliationOutcome(
			$cart_item_key,
			$changed ? CartReconciliationOutcome::ACTION_REFRESHED : CartReconciliationOutcome::ACTION_UNCHANGED,
			$refreshed,
			$changed,
			CartDeliverySelectionRevalidationResult::STATUS_VALID,
			'',
			$product_name
		);
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function reconcile_cart_item( string $cart_item_key, array $cart_item ): CartReconciliationOutcome {
		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );

		if ( $product_id <= 0 || ! $this->cart_capture->should_apply_capture_to_line( $product_id, $variation_id ) ) {
			return new CartReconciliationOutcome(
				$cart_item_key,
				CartReconciliationOutcome::ACTION_UNMANAGED,
				$cart_item,
				false,
				CartDeliverySelectionRevalidationResult::STATUS_MISSING,
				''
			);
		}

		if ( ! isset( $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) ) {
			return new CartReconciliationOutcome(
				$cart_item_key,
				CartReconciliationOutcome::ACTION_UNMANAGED,
				$cart_item,
				false,
				CartDeliverySelectionRevalidationResult::STATUS_MISSING,
				''
			);
		}

		$assessment = $this->cart_capture->assess_product_selection( $product_id, $variation_id );

		return $this->reconcile_line(
			$cart_item_key,
			$cart_item,
			$assessment['options'],
			(string) $assessment['requirement'],
			fn ( string $display_key ): ProductDeliverySelectionValidationResult => $this->selection_validator->validate(
				$product_id,
				$variation_id > 0 ? $variation_id : null,
				$display_key
			)
		);
	}

	/**
	 * @param array<string, mixed> $matched_option
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_refresh( array $cart_item, array $intent, array $matched_option ): array {
		$summary = CartDeliverySelectionCapture::buildPublicSummary( $matched_option );

		$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] = $intent;
		$cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ]   = $summary;
		$cart_item[ CartDeliverySelectionCapture::CART_HASH_KEY ]      = CartDeliverySelectionFingerprint::fromIntent( $intent );
		unset( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] );

		return $cart_item;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_needs_reselection( array $cart_item ): array {
		$cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] = true;
		unset( $cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] );

		$intent = CartDeliverySelectionSessionData::normalizeIntent(
			$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);

		if ( is_array( $intent ) ) {
			$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] = $intent;
			$cart_item[ CartDeliverySelectionCapture::CART_HASH_KEY ]      = CartDeliverySelectionFingerprint::fromIntent( $intent );
		}

		return $cart_item;
	}

	/**
	 * Re-key cart contents to customer-owned identity and consolidate matching lines.
	 *
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function rekey_contents( array $contents ): array {
		$out = [];

		foreach ( $contents as $old_key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$new_key = CartLineCustomerIdentity::cartIdFromItem( $item );

			if ( '' === $new_key ) {
				$new_key = (string) $old_key;
			}

			$item['key'] = $new_key;

			if ( isset( $out[ $new_key ] ) ) {
				$out[ $new_key ]['quantity'] = (int) ( $out[ $new_key ]['quantity'] ?? 0 ) + (int) ( $item['quantity'] ?? 0 );
				continue;
			}

			$out[ $new_key ] = $item;
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function needs_reselection_outcome(
		string $cart_item_key,
		array $cart_item,
		string $status,
		string $product_name = ''
	): CartReconciliationOutcome {
		$marked = self::apply_needs_reselection( $cart_item );
		$marked = CartLineCustomerIdentity::overlayCustomerContext( $marked, $cart_item );

		return new CartReconciliationOutcome(
			$cart_item_key,
			CartReconciliationOutcome::ACTION_NEEDS_RESELECTION,
			$marked,
			$marked !== $cart_item || empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ),
			$status,
			$this->reselection_message( $product_name ),
			$product_name
		);
	}

	private function reselection_message( string $product_name ): string {
		if ( '' === $product_name ) {
			return __(
				'Delivery options for an item in your cart have changed. Please choose a delivery option. You do not need to remove the product.',
				'cetech-woocommerce-delivery-engine'
			);
		}

		return sprintf(
			/* translators: %s: product name */
			__( 'Delivery options for “%s” have changed. Please choose a delivery option. You do not need to remove the product.', 'cetech-woocommerce-delivery-engine' ),
			$product_name
		);
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function product_name( array $cart_item ): string {
		$data = $cart_item['data'] ?? null;

		if ( $data instanceof WC_Product ) {
			$name = trim( $data->get_name() );

			if ( '' !== $name ) {
				return $name;
			}
		}

		$product_id = (int) ( $cart_item['product_id'] ?? 0 );

		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $product_id );

		return $product instanceof WC_Product ? trim( $product->get_name() ) : '';
	}
}
