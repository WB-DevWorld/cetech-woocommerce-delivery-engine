<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Integrations\Blocks\BlocksCheckoutAdapter;

/**
 * Applies a customer delivery reselection onto an existing cart line.
 *
 * Shared by Classic Cart POST and WooCommerce Blocks Store API update callback.
 */
final class CartDeliveryReselectionService {

	public const NONCE_ACTION = 'cetech_de_cart_reselect';

	public const POST_ACTION = 'cetech_de_cart_reselect';

	public const POST_CART_ITEM_KEY = 'cetech_de_cart_item_key';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CartDeliverySelectionCapture $cart_capture,
		private ProductDeliverySelectionValidator $selection_validator,
		private CartDeliverySelectionReconciler $reconciler
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'wp_loaded', [ $this, 'maybe_handle_classic_post' ], 20 );
		add_action( 'woocommerce_blocks_loaded', [ $this, 'register_store_api_callback' ], 5 );
		$this->register_store_api_callback();
	}

	public function register_store_api_callback(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}

		static $registered = false;

		if ( $registered ) {
			return;
		}

		$registered = true;

		woocommerce_store_api_register_update_callback(
			[
				'namespace' => BlocksCheckoutAdapter::NAMESPACE,
				'callback'  => [ $this, 'handle_store_api_update' ],
			]
		);
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function maybe_handle_classic_post(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		if ( ! isset( $_POST[ self::POST_ACTION ] ) ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		$cart_item_key = isset( $_POST[ self::POST_CART_ITEM_KEY ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::POST_CART_ITEM_KEY ] ) )
			: '';
		$display_key = isset( $_POST[ CartDeliverySelectionCapture::POST_FIELD ] )
			? ProductDeliveryOptionsBuilder::normalizeDisplayKey( wp_unslash( (string) $_POST[ CartDeliverySelectionCapture::POST_FIELD ] ) )
			: '';

		$result = $this->apply_selection( $cart_item_key, $display_key );

		if ( $result['success'] ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice(
					__( 'Delivery option updated.', 'cetech-woocommerce-delivery-engine' ),
					'success'
				);
			}
		} elseif ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				(string) ( $result['message'] ?? __( 'Please choose a delivery option for this product.', 'cetech-woocommerce-delivery-engine' ) ),
				'error'
			);
		}

		if ( function_exists( 'wc_get_cart_url' ) ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function handle_store_api_update( array $data ): void {
		$cart_item_key = isset( $data['cart_item_key'] ) ? sanitize_text_field( (string) $data['cart_item_key'] ) : '';
		$display_key   = isset( $data['display_key'] )
			? ProductDeliveryOptionsBuilder::normalizeDisplayKey( (string) $data['display_key'] )
			: '';

		$result = $this->apply_selection( $cart_item_key, $display_key );

		if ( ! $result['success'] ) {
			$class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';

			if ( class_exists( $class ) ) {
				throw new $class(
					'cetech_de_delivery_selection',
					(string) ( $result['message'] ?? __( 'Please choose a delivery option for this product.', 'cetech-woocommerce-delivery-engine' ) ),
					400
				);
			}

			throw new \RuntimeException(
				(string) ( $result['message'] ?? __( 'Please choose a delivery option for this product.', 'cetech-woocommerce-delivery-engine' ) )
			);
		}
	}

	/**
	 * @return array{success: bool, message?: string}
	 */
	public function apply_selection( string $cart_item_key, string $display_key ): array {
		if ( '' === $cart_item_key || '' === $display_key ) {
			return [
				'success' => false,
				'message' => __( 'Please choose a delivery option for this product.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [
				'success' => false,
				'message' => __( 'Your cart could not be updated. Please try again.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		$cart = WC()->cart;
		$item = $cart->get_cart_item( $cart_item_key );

		if ( ! is_array( $item ) ) {
			foreach ( $cart->get_cart() as $key => $candidate ) {
				if ( (string) $key === $cart_item_key && is_array( $candidate ) ) {
					$item = $candidate;
					break;
				}
			}
		}

		if ( ! is_array( $item ) ) {
			return [
				'success' => false,
				'message' => __( 'That cart item could not be found.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		$product_id   = (int) ( $item['product_id'] ?? 0 );
		$variation_id = (int) ( $item['variation_id'] ?? 0 );

		$validation = $this->selection_validator->validate(
			$product_id,
			$variation_id > 0 ? $variation_id : null,
			$display_key
		);

		if ( ! $validation->valid || ! is_array( $validation->intent ) || ! is_array( $validation->matched_option ) ) {
			return [
				'success' => false,
				'message' => __( 'The selected delivery option is no longer available. Please choose another option.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		$stored = CartDeliverySelectionSessionData::normalizeIntent(
			$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);
		$fresh  = is_array( $stored )
			? CartLineCustomerIdentity::overlayCustomerOwned( $validation->intent, $stored )
			: $validation->intent;

		$updated = CartDeliverySelectionReconciler::apply_refresh( $item, $fresh, $validation->matched_option );

		$contents = $cart->get_cart();
		$contents[ $cart_item_key ] = $updated;
		$contents = $this->reconciler->rekey_contents( $contents );
		$cart->cart_contents = $contents;

		if ( method_exists( $cart, 'set_session' ) ) {
			$cart->set_session();
		}

		if ( method_exists( $cart, 'calculate_totals' ) ) {
			$cart->calculate_totals();
		}

		return [ 'success' => true ];
	}
}
