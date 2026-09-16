<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Cart\CartDeliveryReselectionService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Classic Cart inline delivery reselection for lines whose options changed.
 *
 * Reuses ProductDeliveryOptionsBuilder output from Capture::assess_product_selection.
 */
final class CartDeliveryReselectionRenderer {

	public const STYLE_HANDLE = 'cetech-de-cart-delivery-reselection';

	public function __construct(
		private FeatureFlags $feature_flags,
		private Requirements $requirements,
		private CartDeliverySelectionCapture $cart_capture
	) {
	}

	public function register(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'woocommerce_cart_item_name', [ $this, 'append_reselection_form' ], 20, 3 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function enqueue_assets(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '0';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base . 'assets/frontend/cart-delivery-reselection.css',
			[],
			$version
		);
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function append_reselection_form( string $name, array $cart_item, $cart_item_key ): string {
		if ( empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ) ) {
			return $name;
		}

		$form = $this->render_form( (string) $cart_item_key, $cart_item );

		return '' === $form ? $name : $name . $form;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function render_form( string $cart_item_key, array $cart_item ): string {
		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$assessment   = $this->cart_capture->assess_product_selection( $product_id, $variation_id );
		$options      = array_values(
			array_filter(
				$assessment['options'],
				static fn ( ProductDeliveryOption $option ): bool => $option->is_available
			)
		);

		if ( [] === $options ) {
			return '<div class="cetech-de-cart-reselection" role="status"><p>'
				. esc_html__( 'Delivery is currently unavailable for this product. Please contact the store.', 'cetech-woocommerce-delivery-engine' )
				. '</p></div>';
		}

		$action = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		$html   = '<div class="cetech-de-cart-reselection">';
		$html  .= '<p class="cetech-de-cart-reselection__message">'
			. esc_html__( 'Delivery options for this item have changed. Choose an option to continue.', 'cetech-woocommerce-delivery-engine' )
			. '</p>';
		$html  .= '<form class="cetech-de-cart-reselection__form" method="post" action="' . esc_url( $action ) . '">';
		$html  .= '<input type="hidden" name="' . esc_attr( CartDeliveryReselectionService::POST_ACTION ) . '" value="1" />';
		$html  .= '<input type="hidden" name="' . esc_attr( CartDeliveryReselectionService::POST_CART_ITEM_KEY ) . '" value="' . esc_attr( $cart_item_key ) . '" />';
		$html  .= wp_nonce_field( CartDeliveryReselectionService::NONCE_ACTION, '_wpnonce', true, false );

		foreach ( $options as $option ) {
			$id    = 'cetech-de-cart-reselect-' . $cart_item_key . '-' . md5( $option->display_key );
			$label = $this->option_label( $option );
			$html .= '<label class="cetech-de-cart-reselection__option" for="' . esc_attr( $id ) . '">';
			$html .= '<input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( CartDeliverySelectionCapture::POST_FIELD ) . '" value="' . esc_attr( $option->display_key ) . '" required />';
			$html .= '<span>' . esc_html( $label ) . '</span>';
			$html .= '</label>';
		}

		$html .= '<button type="submit" class="button">'
			. esc_html__( 'Update delivery option', 'cetech-woocommerce-delivery-engine' )
			. '</button>';
		$html .= '</form></div>';

		return $html;
	}

	private function option_label( ProductDeliveryOption $option ): string {
		$label = trim( (string) $option->delivery_offer_public_label );

		if ( '' === $label && FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
			$label = trim( (string) $option->pickup_location_label );
			$label = '' !== $label ? $label : __( 'Store pickup', 'cetech-woocommerce-delivery-engine' );
		}

		if ( '' === $label ) {
			$label = __( 'Delivery option', 'cetech-woocommerce-delivery-engine' );
		}

		$estimate = trim( (string) $option->estimate_text );

		if ( '' !== $estimate ) {
			$label .= ' — ' . $estimate;
		}

		return $label;
	}

	private function should_enqueue(): bool {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		return false;
	}
}
