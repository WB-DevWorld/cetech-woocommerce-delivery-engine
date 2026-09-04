<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextEditorService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Pickup\PickupLocationAddressFormatter;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;
use CetechDeliveryEngine\Presentation\Shared\DeliveryPresentationLabels;

/**
 * Classic cart per-line delivery/pickup editor.
 *
 * Mutates cart only through CartCustomerContextEditorService.
 */
final class CartCustomerContextEditorRenderer {

	public const STYLE_HANDLE = 'cetech-de-cart-customer-context-editor';

	public const SCRIPT_HANDLE = 'cetech-de-cart-customer-context-editor';

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
		add_action( 'woocommerce_after_cart_item_name', [ $this, 'render_after_name' ], 20, 2 );
	}

	public function is_active(): bool {
		return $this->feature_flags->is_enabled( 'enable_cart_delivery_selection_capture' )
			&& $this->feature_flags->is_enabled( 'enable_product_delivery_selector' )
			&& $this->requirements->is_woocommerce_active();
	}

	public function enqueue_assets(): void {
		if ( ( ! function_exists( 'is_cart' ) || ! is_cart() ) && ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) ) {
			return;
		}

		$version = defined( 'CETECH_DE_VERSION' ) ? CETECH_DE_VERSION : '0';
		$base    = defined( 'CETECH_DE_URL' ) ? CETECH_DE_URL : '';

		wp_enqueue_style(
			self::STYLE_HANDLE,
			$base . 'assets/frontend/cart-customer-context-editor.css',
			[],
			$version
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$base . 'assets/frontend/cart-customer-context-editor.js',
			[],
			$version,
			true
		);
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function render_after_name( array $cart_item, $cart_item_key ): void {
		if ( ! empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ) ) {
			return;
		}

		if ( ! isset( $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) ) {
			return;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() && ( ! function_exists( 'is_cart' ) || ! is_cart() ) ) {
			return;
		}

		echo $this->render_block( (string) $cart_item_key, $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns escaped HTML.
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function render_block( string $cart_item_key, array $cart_item ): string {
		$context = CustomerCartContext::fromCartItem( $cart_item );
		$intent  = CartDeliverySelectionSessionData::normalizeIntent(
			$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);
		$summary = CartDeliverySelectionSessionData::normalizeSummary(
			$cart_item[ CartDeliverySelectionCapture::CART_SUMMARY_KEY ] ?? null
		);

		if ( null === $intent ) {
			return '';
		}

		$choice = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );
		$html   = '<div class="cetech-de-cart-context">';
		$html  .= $this->render_summary( $choice, is_array( $summary ) ? $summary : [], $context );
		$html  .= $this->render_form( $cart_item_key, $cart_item, $context, $intent );
		$html  .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, string|null> $summary
	 */
	private function render_summary( string $choice, array $summary, ?CustomerCartContext $context ): string {
		$locality = $context instanceof CustomerCartContext ? $context->publicLocalityLabel() : '';
		$compact  = CustomerStorefrontCopy::cart_line_summary( $choice, $summary, $locality );

		$html  = '<div class="cetech-de-cart-context__summary">';
		if ( $compact['is_pickup'] && '' !== $compact['kicker'] ) {
			$html .= '<p class="cetech-de-cart-context__kicker">' . esc_html( $compact['kicker'] ) . '</p>';
		}
		if ( '' !== $compact['title'] && $compact['title'] !== $compact['kicker'] ) {
			$html .= '<p class="cetech-de-cart-context__offer">' . esc_html( $compact['title'] ) . '</p>';
		} elseif ( '' !== $compact['title'] && ! $compact['is_pickup'] ) {
			$html .= '<p class="cetech-de-cart-context__offer">' . esc_html( $compact['title'] ) . '</p>';
		}
		if ( '' !== $compact['meta'] ) {
			$html .= '<p class="cetech-de-cart-context__meta">' . esc_html( $compact['meta'] ) . '</p>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 * @param array<string, mixed> $intent
	 */
	private function render_form( string $cart_item_key, array $cart_item, ?CustomerCartContext $context, array $intent ): string {
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
			return '';
		}

		$qty          = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		$selected_key = (string) ( $intent['display_key'] ?? '' );
		$choice       = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );
		$matching     = $context instanceof CustomerCartContext ? $context->matching_location : null;
		$address      = $context instanceof CustomerCartContext ? $context->delivery_address : null;
		$action       = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		$uid          = 'cetech-de-ctx-' . sanitize_html_class( $cart_item_key );

		$html  = '<details class="cetech-de-cart-context__editor">';
		$html .= '<summary>' . esc_html( CustomerStorefrontCopy::change() ) . '</summary>';
		$html .= '<form class="cetech-de-cart-context__form" method="post" action="' . esc_url( $action ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( CartCustomerContextEditorService::POST_ACTION ) . '" value="1" />';
		$html .= '<input type="hidden" name="' . esc_attr( CartCustomerContextEditorService::POST_CART_ITEM_KEY ) . '" value="' . esc_attr( $cart_item_key ) . '" />';
		$html .= wp_nonce_field( CartCustomerContextEditorService::NONCE_ACTION, '_wpnonce', true, false );

		$html .= '<div class="cetech-de-cart-context__location" data-cetech-de-editor-location="1"' . ( FulfilmentChoice::StorePickup->value === $choice ? ' hidden' : '' ) . '>';
		$html .= MatchingLocationFieldRenderer::render( $matching, $uid, false );
		$html .= '</div>';

		$html .= '<fieldset class="cetech-de-cart-context__options"><legend>'
			. esc_html( CustomerStorefrontCopy::delivery_choices() )
			. '</legend>';

		foreach ( $options as $option ) {
			$id      = $uid . '-' . md5( $option->display_key );
			$checked = $option->display_key === $selected_key ? ' checked="checked"' : '';
			$label   = trim( (string) $option->delivery_offer_public_label );
			if ( '' === $label && FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$label = trim( (string) $option->pickup_location_label );
				$label = '' !== $label ? $label : CustomerStorefrontCopy::store_pickup();
			}
			$html .= '<label class="cetech-de-cart-context__option" for="' . esc_attr( $id ) . '">';
			$html .= '<input type="radio" id="' . esc_attr( $id ) . '" name="' . esc_attr( CartDeliverySelectionCapture::POST_FIELD ) . '" value="' . esc_attr( $option->display_key ) . '" data-cetech-de-choice="' . esc_attr( $option->fulfilment_choice ) . '"' . $checked . ' />';
			$html .= '<span>' . esc_html( $label ) . '</span>';
			$html .= '</label>';
			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$addr = PickupLocationAddressFormatter::format( (string) ( $option->pickup_address ?? '' ) );
				if ( '' !== $addr ) {
					$html .= '<p class="cetech-de-cart-context__pickup-meta">' . esc_html( $addr ) . '</p>';
				}
			}
		}
		$html .= '</fieldset>';

		$html .= '<div class="cetech-de-cart-context__address-wrap" data-cetech-de-editor-address="1"' . ( FulfilmentChoice::StorePickup->value === $choice ? ' hidden' : '' ) . '>';
		$html .= '<fieldset class="cetech-de-cart-context__address"><legend>'
			. esc_html__( 'Delivery address', 'cetech-woocommerce-delivery-engine' )
			. '</legend>';
			$html .= $this->text_field( $uid . '-address-1', 'cetech_de_address_1', __( 'Address line 1', 'cetech-woocommerce-delivery-engine' ), $address?->address_1 ?? '' );
			$html .= $this->text_field( $uid . '-address-2', 'cetech_de_address_2', __( 'Address line 2', 'cetech-woocommerce-delivery-engine' ), $address?->address_2 ?? '' );
			$html .= $this->text_field( $uid . '-first', 'cetech_de_first_name', __( 'First name', 'cetech-woocommerce-delivery-engine' ), $address?->recipient->first_name ?? '' );
			$html .= $this->text_field( $uid . '-last', 'cetech_de_last_name', __( 'Last name', 'cetech-woocommerce-delivery-engine' ), $address?->recipient->last_name ?? '' );
			$html .= $this->text_field( $uid . '-company', 'cetech_de_company', __( 'Company', 'cetech-woocommerce-delivery-engine' ), $address?->recipient->company ?? '' );
			$html .= $this->text_field( $uid . '-phone', 'cetech_de_phone', __( 'Phone', 'cetech-woocommerce-delivery-engine' ), $address?->recipient->phone ?? '' );
			$html .= '</fieldset></div>';

		if ( $qty > 1 && FulfilmentChoice::Delivery->value === $choice ) {
			$html .= '<fieldset class="cetech-de-cart-context__qty" data-cetech-de-qty-split="1">';
			$html .= '<legend>' . esc_html__( 'Apply this change', 'cetech-woocommerce-delivery-engine' ) . '</legend>';
			$html .= '<label for="' . esc_attr( $uid . '-apply-all' ) . '"><input type="radio" id="' . esc_attr( $uid . '-apply-all' ) . '" name="' . esc_attr( CartCustomerContextEditorService::POST_APPLY_MODE ) . '" value="all" checked="checked" /> ';
			$html .= esc_html(
				sprintf(
					/* translators: %d: quantity */
					__( 'Apply to all %d items', 'cetech-woocommerce-delivery-engine' ),
					$qty
				)
			);
			$html .= '</label>';
			$html .= '<label for="' . esc_attr( $uid . '-apply-split' ) . '"><input type="radio" id="' . esc_attr( $uid . '-apply-split' ) . '" name="' . esc_attr( CartCustomerContextEditorService::POST_APPLY_MODE ) . '" value="split" /> ';
			$html .= esc_html__( 'Move some quantity', 'cetech-woocommerce-delivery-engine' );
			$html .= '</label>';
			$html .= '<p class="cetech-de-cart-context__split-qty" hidden>';
			$html .= '<label for="' . esc_attr( $uid . '-split-qty' ) . '">' . esc_html__( 'Quantity to move', 'cetech-woocommerce-delivery-engine' ) . '</label>';
			$html .= '<input type="number" id="' . esc_attr( $uid . '-split-qty' ) . '" name="' . esc_attr( CartCustomerContextEditorService::POST_SPLIT_QTY ) . '" min="1" max="' . esc_attr( (string) $qty ) . '" value="1" />';
			$html .= '</p></fieldset>';
		}

		$html .= '<p class="cetech-de-cart-context__actions">';
		$html .= '<button type="submit" class="button">' . esc_html__( 'Update', 'cetech-woocommerce-delivery-engine' ) . '</button>';
		if ( FulfilmentChoice::Delivery->value === $choice ) {
			$html .= ' <button type="submit" class="button" name="' . esc_attr( CartCustomerContextEditorService::POST_USE_FOR_ALL ) . '" value="1">';
			$html .= esc_html( CustomerStorefrontCopy::use_for_all_delivery_items() );
			$html .= '</button>';
		}
		$html .= '</p></form></details>';

		return $html;
	}

	private function text_field( string $id, string $name, string $label, string $value ): string {
		return '<p class="cetech-de-cart-context__field">'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />'
			. '</p>';
	}
}
