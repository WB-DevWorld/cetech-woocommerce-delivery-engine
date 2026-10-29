<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Application\CustomerContext\LocationAwareDeliveryOptions;
use CetechDeliveryEngine\Application\CustomerContext\ProductPageQuoteContext;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Presentation\Shared\CustomerStorefrontCopy;

/**
 * Customer-facing quoted fee for one cart line's captured selection (D2 compact line).
 *
 * Re-runs the product-page quote for the line's product, quantity and committed
 * destination. Presentation only: the package rate WooCommerce charges still
 * comes from the shipping calculator, and nothing here is persisted.
 */
final class CartLineDeliveryPriceResolver {

	public function __construct(
		private CartDeliverySelectionCapture $capture,
		private ?LocationAwareDeliveryOptions $location_options = null
	) {
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public function price_text_for( array $cart_item ): string {
		if ( ! empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ) ) {
			return '';
		}

		$intent = CartDeliverySelectionSessionData::normalizeIntent(
			$cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);

		if ( null === $intent ) {
			return '';
		}

		$display_key = (string) ( $intent['display_key'] ?? '' );
		$is_pickup   = FulfilmentChoice::StorePickup->value === sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );

		if ( '' === $display_key ) {
			return '';
		}

		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		$quantity     = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		$options      = $this->capture->assess_product_selection( $product_id, $variation_id )['options'] ?? [];
		$available    = array_values(
			array_filter(
				is_array( $options ) ? $options : [],
				static fn ( $option ): bool => $option instanceof ProductDeliveryOption && $option->is_available
			)
		);

		$context  = CustomerCartContext::fromCartItem( $cart_item );
		$matching = $context instanceof CustomerCartContext ? $context->matching_location : null;
		$priced   = $available;

		if (
			[] !== $available
			&& $this->location_options instanceof LocationAwareDeliveryOptions
			&& ( $is_pickup || $matching instanceof MatchingLocation )
		) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
			$priced   = $this->location_options->filter(
				$available,
				$matching,
				$currency,
				ProductPageQuoteContext::from_request( $product_id, $variation_id, $quantity )
			);
		}

		foreach ( $priced as $option ) {
			if ( ! $option instanceof ProductDeliveryOption || $option->display_key !== $display_key ) {
				continue;
			}

			$text = trim( (string) ( $option->price_text ?? '' ) );

			if ( '' !== $text ) {
				return $text;
			}

			break;
		}

		return $is_pickup ? CustomerStorefrontCopy::free() : '';
	}

	/**
	 * @param array<string, array<string, mixed>> $contents Cart contents keyed by cart item key.
	 *
	 * @return array<string, string>
	 */
	public function price_texts_for_cart( array $contents ): array {
		$prices = [];

		foreach ( $contents as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$text = $this->price_text_for( $item );

			if ( '' !== $text ) {
				$prices[ (string) $key ] = $text;
			}
		}

		return $prices;
	}
}
