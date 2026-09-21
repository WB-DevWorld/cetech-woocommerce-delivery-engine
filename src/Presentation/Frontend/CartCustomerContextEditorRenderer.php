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
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Shared\CartDeliveryUiAnchor;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

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
		add_action( 'woocommerce_after_cart', [ $this, 'render_deferred_forms' ], 5 );
		add_action( 'woocommerce_after_checkout_form', [ $this, 'render_deferred_forms' ], 5 );
		add_action( 'woocommerce_after_mini_cart', [ $this, 'render_deferred_forms' ], 5 );
	}

	public function render_deferred_forms(): void {
		echo CartExternalFormBuffer::drain(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer returns escaped HTML.
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

		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$assessment   = $this->cart_capture->assess_product_selection( $product_id, $variation_id );
		$options      = array_values(
			array_filter(
				$assessment['options'],
				static fn ( ProductDeliveryOption $option ): bool => $option->is_available
			)
		);

		return $this->render_editor( $cart_item_key, $cart_item, $context, $intent, $options, is_array( $summary ) ? $summary : [] );
	}

	/**
	 * @param array<string, mixed>              $cart_item
	 * @param array<string, mixed>              $intent
	 * @param list<ProductDeliveryOption>       $options
	 * @param array<string, string|null>        $summary
	 */
	public function render_editor(
		string $cart_item_key,
		array $cart_item,
		?CustomerCartContext $context,
		array $intent,
		array $options,
		array $summary = []
	): string {
		if ( [] === $options ) {
			return '';
		}

		$choice           = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );
		$is_pickup        = FulfilmentChoice::StorePickup->value === $choice;
		$address_complete = $context instanceof CustomerCartContext && $context->hasCompleteDeliveryAddress();
		$address_required = ! $is_pickup && ! $address_complete;
		$anchor           = CartDeliveryUiAnchor::for_cart_item_key( $cart_item_key );
		$action_label     = CustomerStorefrontCopy::editor_action_label( $choice, $address_complete );

		$form_id      = CartDeliveryUiAnchor::form_id_for_cart_item_key( $cart_item_key );
		$has_matching = $context instanceof CustomerCartContext && $context->hasMatchingLocation();
		$html         = '<div class="cetech-de-cart-context" id="' . esc_attr( $anchor ) . '" data-cetech-de-ui-anchor="' . esc_attr( $anchor ) . '" data-cetech-de-form-id="' . esc_attr( $form_id ) . '" data-cetech-de-has-matching-location="' . ( $has_matching ? '1' : '0' ) . '" data-cetech-de-address-complete="' . ( $address_complete ? '1' : '0' ) . '" data-cetech-de-address-required="' . ( $address_required ? '1' : '0' ) . '" data-cetech-de-fulfilment="' . esc_attr( $choice ) . '">';
		$html .= $this->render_summary( $choice, $summary, $context, $address_required );
		$html .= $this->render_form( $cart_item_key, $cart_item, $context, $intent, $options, $anchor, $action_label, $is_pickup );
		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, string|null> $summary
	 */
	private function render_summary( string $choice, array $summary, ?CustomerCartContext $context, bool $address_required ): string {
		$locality = $context instanceof CustomerCartContext ? $context->publicLocalityLabel() : '';
		$compact  = CustomerStorefrontCopy::cart_line_summary( $choice, $summary, $locality );

		$html = '<div class="cetech-de-cart-context__summary">';
		if ( $address_required ) {
			$html .= '<p class="cetech-de-cart-context__status" data-cetech-de-address-needed="1">'
				. esc_html( CustomerStorefrontCopy::address_needed() )
				. '</p>';
		}
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
	 * @param array<string, mixed>        $cart_item
	 * @param array<string, mixed>        $intent
	 * @param list<ProductDeliveryOption> $options
	 */
	private function render_form(
		string $cart_item_key,
		array $cart_item,
		?CustomerCartContext $context,
		array $intent,
		array $options,
		string $anchor,
		string $action_label,
		bool $is_pickup
	): string {
		$qty           = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		$selected_key  = (string) ( $intent['display_key'] ?? '' );
		$matching      = $context instanceof CustomerCartContext ? $context->matching_location : null;
		$address       = $context instanceof CustomerCartContext ? $context->delivery_address : null;
		$action        = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		$uid           = 'cetech-de-ctx-' . sanitize_html_class( $cart_item_key );
		$has_matching  = $context instanceof CustomerCartContext && $context->hasMatchingLocation();
		$pickup_hidden = $is_pickup ? ' hidden' : '';
		$form_id       = CartDeliveryUiAnchor::form_id_for_cart_item_key( $cart_item_key );
		$form_attr     = $this->form_owner_attr( $form_id );

		$this->queue_external_form( $form_id, $cart_item_key, (string) $action );

		$html  = '<details class="cetech-de-cart-context__editor" data-cetech-de-ui-anchor="' . esc_attr( $anchor ) . '">';
		$html .= '<summary class="cetech-de-cart-context__summary-action">' . esc_html( $action_label ) . '</summary>';
		$html .= $this->render_destination_section( $uid, $matching, $has_matching, $pickup_hidden, $form_id );
		$html .= $this->render_method_section( $uid, $options, $selected_key, $form_id );
		$html .= $this->render_address_section( $uid, $address, $pickup_hidden, $form_id );
		$html .= $this->render_recipient_section( $uid, $address, $pickup_hidden, $form_id );

		if ( $qty > 1 && FulfilmentChoice::Delivery->value === sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) ) {
			$html .= $this->render_quantity_section( $uid, $qty, $form_id );
		}

		$html .= '<p class="cetech-de-cart-context__actions">';
		$html .= '<button type="submit" class="button cetech-de-cart-context__save"' . $form_attr . '>'
			. esc_html( CustomerStorefrontCopy::save_delivery_details() )
			. '</button>';
		$html .= ' <button type="button" class="cetech-de-cart-context__cancel cetech-de-cart-context__action--secondary" data-cetech-de-cancel="1">'
			. esc_html( CustomerStorefrontCopy::cancel() )
			. '</button>';
		if ( FulfilmentChoice::Delivery->value === sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) ) {
			$html .= ' <button type="submit" class="cetech-de-cart-context__use-for-all cetech-de-cart-context__action--secondary"' . $form_attr . ' name="'
				. esc_attr( CartCustomerContextEditorService::POST_USE_FOR_ALL ) . '" value="1">';
			$html .= esc_html( CustomerStorefrontCopy::use_for_all_delivery_items() );
			$html .= '</button>';
		}
		$html .= '</p></details>';

		return $html;
	}

	private function queue_external_form( string $form_id, string $cart_item_key, string $action ): void {
		$html  = '<form id="' . esc_attr( $form_id ) . '" class="cetech-de-cart-context__form" method="post" action="' . esc_url( $action ) . '">';
		$html .= '<input type="hidden" name="' . esc_attr( CartCustomerContextEditorService::POST_ACTION ) . '" value="1" />';
		$html .= '<input type="hidden" name="' . esc_attr( CartCustomerContextEditorService::POST_CART_ITEM_KEY ) . '" value="' . esc_attr( $cart_item_key ) . '" />';
		$html .= wp_nonce_field( CartCustomerContextEditorService::NONCE_ACTION, '_wpnonce', true, false );
		$html .= '</form>';

		CartExternalFormBuffer::queue( $form_id, $html );
	}

	private function form_owner_attr( string $form_id ): string {
		return '' === $form_id ? '' : ' form="' . esc_attr( $form_id ) . '"';
	}

	private function render_destination_section( string $uid, ?MatchingLocation $matching, bool $has_matching, string $pickup_hidden, string $form_id ): string {
		$summary = $this->destination_summary( $matching );
		$html    = '<section class="cetech-de-cart-context__section cetech-de-cart-context__location" data-cetech-de-editor-location="1"' . $pickup_hidden . '>';
		$html   .= '<h3 class="cetech-de-cart-context__section-title">' . esc_html( CustomerStorefrontCopy::destination() ) . '</h3>';
		if ( '' !== $summary ) {
			$html .= '<p class="cetech-de-cart-context__destination-summary">' . esc_html( $summary ) . '</p>';
		}
		$html .= '<details class="cetech-de-cart-context__disclosure"' . ( $has_matching ? '' : ' open' ) . '>';
		$html .= '<summary>' . esc_html( CustomerStorefrontCopy::change_destination() ) . '</summary>';
		$html .= MatchingLocationFieldRenderer::render( $matching, $uid, false, false, true, false, $form_id );
		$html .= '</details></section>';

		return $html;
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 */
	private function render_method_section( string $uid, array $options, string $selected_key, string $form_id ): string {
		$form_attr = $this->form_owner_attr( $form_id );
		$html      = '<section class="cetech-de-cart-context__section cetech-de-cart-context__options">';
		$html     .= '<h3 class="cetech-de-cart-context__section-title">' . esc_html( CustomerStorefrontCopy::delivery_method() ) . '</h3>';

		if ( 1 === count( $options ) ) {
			$option = $options[0];
			$html  .= '<p class="cetech-de-cart-context__method-summary">' . esc_html( $this->option_label( $option ) ) . '</p>';
			$html  .= '<input type="hidden" name="' . esc_attr( CartDeliverySelectionCapture::POST_FIELD ) . '" value="'
				. esc_attr( $option->display_key ) . '" data-cetech-de-choice="' . esc_attr( $option->fulfilment_choice ) . '"' . $form_attr . ' />';
			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$addr = PickupLocationAddressFormatter::format( (string) ( $option->pickup_address ?? '' ) );
				if ( '' !== $addr ) {
					$html .= '<p class="cetech-de-cart-context__pickup-meta">' . esc_html( $addr ) . '</p>';
				}
			}
			$html .= '</section>';

			return $html;
		}

		$select_id = $uid . '-method';
		$html     .= '<label class="cetech-de-cart-context__field" for="' . esc_attr( $select_id ) . '">';
		$html     .= '<span class="screen-reader-text">' . esc_html( CustomerStorefrontCopy::delivery_method() ) . '</span>';
		$html     .= '<select id="' . esc_attr( $select_id ) . '" name="' . esc_attr( CartDeliverySelectionCapture::POST_FIELD ) . '"' . $form_attr . '>';
		foreach ( $options as $option ) {
			$selected = $option->display_key === $selected_key ? ' selected="selected"' : '';
			$html    .= '<option value="' . esc_attr( $option->display_key ) . '" data-cetech-de-choice="'
				. esc_attr( $option->fulfilment_choice ) . '"' . $selected . '>'
				. esc_html( $this->option_label( $option ) ) . '</option>';
		}
		$html .= '</select></label></section>';

		return $html;
	}

	/**
	 * @param mixed $address
	 */
	private function render_address_section( string $uid, $address, string $pickup_hidden, string $form_id ): string {
		$line1 = is_object( $address ) ? (string) ( $address->address_1 ?? '' ) : '';
		$line2 = is_object( $address ) ? (string) ( $address->address_2 ?? '' ) : '';

		$html  = '<div class="cetech-de-cart-context__address-wrap" data-cetech-de-editor-address="1"' . $pickup_hidden . '>';
		$html .= '<section class="cetech-de-cart-context__section cetech-de-cart-context__address">';
		$html .= '<h3 class="cetech-de-cart-context__section-title">' . esc_html( CustomerStorefrontCopy::delivery_address() ) . '</h3>';
		$html .= $this->text_field( $uid . '-address-1', 'cetech_de_address_1', CustomerStorefrontCopy::address_line_1(), $line1, true, $form_id );
		$html .= '<details class="cetech-de-cart-context__disclosure"' . ( '' !== trim( $line2 ) ? ' open' : '' ) . '>';
		$html .= '<summary>' . esc_html( CustomerStorefrontCopy::address_line_2_optional() ) . '</summary>';
		$html .= $this->text_field( $uid . '-address-2', 'cetech_de_address_2', CustomerStorefrontCopy::address_line_2_optional(), $line2, false, $form_id );
		$html .= '</details></section></div>';

		return $html;
	}

	/**
	 * @param mixed $address
	 */
	private function render_recipient_section( string $uid, $address, string $pickup_hidden, string $form_id ): string {
		$recipient = is_object( $address ) ? $address->recipient ?? null : null;
		$first     = is_object( $recipient ) ? (string) ( $recipient->first_name ?? '' ) : '';
		$last      = is_object( $recipient ) ? (string) ( $recipient->last_name ?? '' ) : '';
		$company   = is_object( $recipient ) ? (string) ( $recipient->company ?? '' ) : '';
		$phone     = is_object( $recipient ) ? (string) ( $recipient->phone ?? '' ) : '';
		$has_any   = is_object( $recipient ) && method_exists( $recipient, 'isEmpty' ) ? ! $recipient->isEmpty() : ( '' !== $first || '' !== $last || '' !== $company || '' !== $phone );

		$html  = '<div class="cetech-de-cart-context__recipient-wrap" data-cetech-de-editor-recipient="1"' . $pickup_hidden . '>';
		$html .= '<details class="cetech-de-cart-context__disclosure cetech-de-cart-context__recipient"' . ( $has_any ? ' open' : '' ) . '>';
		$html .= '<summary>' . esc_html( CustomerStorefrontCopy::recipient_details_optional() ) . '</summary>';
		$html .= '<div class="cetech-de-cart-context__grid">';
		$html .= $this->text_field( $uid . '-first', 'cetech_de_first_name', __( 'First name', 'cetech-woocommerce-delivery-engine' ), $first, false, $form_id );
		$html .= $this->text_field( $uid . '-last', 'cetech_de_last_name', __( 'Last name', 'cetech-woocommerce-delivery-engine' ), $last, false, $form_id );
		$html .= $this->text_field( $uid . '-phone', 'cetech_de_phone', __( 'Phone', 'cetech-woocommerce-delivery-engine' ), $phone, false, $form_id );
		$html .= $this->text_field( $uid . '-company', 'cetech_de_company', __( 'Company', 'cetech-woocommerce-delivery-engine' ), $company, false, $form_id );
		$html .= '</div></details></div>';

		return $html;
	}

	private function render_quantity_section( string $uid, int $qty, string $form_id ): string {
		$form_attr = $this->form_owner_attr( $form_id );
		$html      = '<details class="cetech-de-cart-context__disclosure cetech-de-cart-context__qty" data-cetech-de-qty-split="1">';
		$html     .= '<summary>' . esc_html( CustomerStorefrontCopy::apply_to_quantity() ) . '</summary>';
		$html     .= '<fieldset>';
		$html     .= '<legend class="screen-reader-text">' . esc_html( CustomerStorefrontCopy::apply_to_quantity() ) . '</legend>';
		$html     .= '<label for="' . esc_attr( $uid . '-apply-all' ) . '"><input type="radio" id="' . esc_attr( $uid . '-apply-all' ) . '" name="' . esc_attr( CartCustomerContextEditorService::POST_APPLY_MODE ) . '" value="all" checked="checked"' . $form_attr . ' /> ';
		$html     .= esc_html( CustomerStorefrontCopy::apply_to_all_n( $qty ) );
		$html     .= '</label>';
		$html     .= '<label for="' . esc_attr( $uid . '-apply-split' ) . '"><input type="radio" id="' . esc_attr( $uid . '-apply-split' ) . '" name="' . esc_attr( CartCustomerContextEditorService::POST_APPLY_MODE ) . '" value="split"' . $form_attr . ' /> ';
		$html     .= esc_html__( 'Move some quantity', 'cetech-woocommerce-delivery-engine' );
		$html     .= '</label>';
		$html     .= '<p class="cetech-de-cart-context__split-qty" hidden>';
		$html     .= '<label for="' . esc_attr( $uid . '-split-qty' ) . '">' . esc_html__( 'Quantity to move', 'cetech-woocommerce-delivery-engine' ) . '</label>';
		$html     .= '<input type="number" id="' . esc_attr( $uid . '-split-qty' ) . '" name="' . esc_attr( CartCustomerContextEditorService::POST_SPLIT_QTY ) . '" min="1" max="' . esc_attr( (string) $qty ) . '" value="1"' . $form_attr . ' />';
		$html     .= '</p></fieldset></details>';

		return $html;
	}

	private function option_label( ProductDeliveryOption $option ): string {
		$label = trim( (string) $option->delivery_offer_public_label );
		if ( '' === $label && FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
			$label = trim( (string) $option->pickup_location_label );
			$label = '' !== $label ? $label : CustomerStorefrontCopy::store_pickup();
		}

		return '' !== $label ? $label : CustomerStorefrontCopy::delivery();
	}

	private function destination_summary( ?MatchingLocation $matching ): string {
		if ( ! $matching instanceof MatchingLocation || ! $matching->isPresent() ) {
			return '';
		}

		$city  = trim( $matching->city );
		$state = trim( $matching->state );
		if ( '' !== $city && '' !== $state ) {
			return $city . ', ' . $state;
		}
		if ( '' !== $city ) {
			return $city;
		}
		if ( '' !== $state ) {
			return $state;
		}

		return trim( $matching->country );
	}

	private function text_field( string $id, string $name, string $label, string $value, bool $required_address = false, string $form_id = '' ): string {
		$extra = $required_address ? ' data-cetech-de-required-address="1" autocomplete="address-line1"' : '';

		return '<p class="cetech-de-cart-context__field">'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"'
			. $this->form_owner_attr( $form_id ) . $extra . ' />'
			. '</p>';
	}
}
