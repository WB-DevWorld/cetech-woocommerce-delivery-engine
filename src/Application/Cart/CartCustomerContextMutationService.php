<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;

/**
 * Centralized cart rekey / split / consolidate for customer destination context.
 *
 * Do not mutate WooCommerce cart-array keys from scattered handlers.
 */
final class CartCustomerContextMutationService {

	public function __construct(
		private ?CartDeliverySelectionReconciler $reconciler = null
	) {
	}

	/**
	 * Move all quantity on a line onto a new customer context.
	 *
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return CartMutationResult
	 */
	public function updateWholeLine( array $contents, string $source_key, CustomerCartContext $context ): CartMutationResult {
		$item = $contents[ $source_key ] ?? null;

		if ( ! is_array( $item ) ) {
			return CartMutationResult::fail( CartMutationResult::CODE_MISSING_LINE, $contents );
		}

		return $this->moveQuantity( $contents, $source_key, (int) ( $item['quantity'] ?? 0 ), $context );
	}

	/**
	 * Move N units from an existing line onto a new customer context.
	 *
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return CartMutationResult
	 */
	public function splitQuantity( array $contents, string $source_key, int $quantity, CustomerCartContext $context ): CartMutationResult {
		return $this->moveQuantity( $contents, $source_key, $quantity, $context );
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 */
	public function moveQuantity(
		array $contents,
		string $source_key,
		int $quantity,
		CustomerCartContext $context
	): CartMutationResult {
		$source = $contents[ $source_key ] ?? null;

		if ( ! is_array( $source ) ) {
			return CartMutationResult::fail( CartMutationResult::CODE_MISSING_LINE, $contents );
		}

		$existing_qty = (int) ( $source['quantity'] ?? 0 );

		if ( $quantity <= 0 || $quantity > $existing_qty ) {
			return CartMutationResult::fail( CartMutationResult::CODE_INVALID_QUANTITY, $contents );
		}

		$working = $contents;
		$moved   = $this->clone_line_with_context( $source, $quantity, $context );
		$moved   = $this->refresh_admin_derived( $moved );
		$new_key = CartLineCustomerIdentity::cartIdFromItem( $moved );

		if ( '' === $new_key ) {
			return CartMutationResult::fail( CartMutationResult::CODE_INVALID_CONTEXT, $contents );
		}

		$moved['key'] = $new_key;

		if ( $quantity === $existing_qty ) {
			unset( $working[ $source_key ] );
		} else {
			$remainder             = $source;
			$remainder['quantity'] = $existing_qty - $quantity;
			$remainder             = $this->refresh_admin_derived( $remainder );
			$remainder_key        = CartLineCustomerIdentity::cartIdFromItem( $remainder );
			$remainder['key']      = '' !== $remainder_key ? $remainder_key : $source_key;
			unset( $working[ $source_key ] );

			if ( isset( $working[ $remainder['key'] ] ) && $working[ $remainder['key'] ] !== $remainder ) {
				$working[ $remainder['key'] ]['quantity'] = (int) ( $working[ $remainder['key'] ]['quantity'] ?? 0 )
					+ (int) $remainder['quantity'];
			} else {
				$working[ $remainder['key'] ] = $remainder;
			}

			$source_key = $remainder['key'];
		}

		if ( isset( $working[ $new_key ] ) ) {
			$working[ $new_key ]['quantity'] = (int) ( $working[ $new_key ]['quantity'] ?? 0 ) + $quantity;
			$working[ $new_key ]           = $context->applyToCartItem( $working[ $new_key ] );
			$working[ $new_key ]           = $this->refresh_admin_derived( $working[ $new_key ] );
		} else {
			$working[ $new_key ] = $moved;
		}

		return CartMutationResult::ok( $working, $source_key, $new_key );
	}

	/**
	 * Apply a successful mutation onto the live WooCommerce cart and invalidate shipping.
	 *
	 * @param array<string, array<string, mixed>> $contents
	 */
	public function commitToCart( array $contents ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$cart                 = WC()->cart;
		$cart->cart_contents  = $contents;

		if ( method_exists( $cart, 'set_session' ) ) {
			$cart->set_session();
		}

		if ( function_exists( 'WC' ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'set' ) ) {
			WC()->session->set( 'shipping_for_package_0', null );
		}

		if ( method_exists( $cart, 'calculate_totals' ) ) {
			$cart->calculate_totals();
		}
	}

	/**
	 * @param array<string, mixed> $source
	 *
	 * @return array<string, mixed>
	 */
	private function clone_line_with_context( array $source, int $quantity, CustomerCartContext $context ): array {
		$clone             = $source;
		$clone['quantity']  = $quantity;
		$clone             = $context->applyToCartItem( $clone );
		unset( $clone[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] );

		$intent = CartDeliverySelectionSessionData::normalizeIntent(
			$clone[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
		);

		if ( is_array( $intent ) ) {
			$intent['fulfilment_choice'] = $context->fulfilment_choice;

			if ( $context->isPickup() ) {
				$intent['delivery_offer_id'] = null;
			} elseif ( null !== $context->delivery_offer_id ) {
				$intent['delivery_offer_id'] = $context->delivery_offer_id;
			}

			$clone[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] = $intent;
		}

		return $clone;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array<string, mixed>
	 */
	private function refresh_admin_derived( array $cart_item ): array {
		if ( ! $this->reconciler instanceof CartDeliverySelectionReconciler ) {
			return $cart_item;
		}

		$outcome = $this->reconciler->reconcile_cart_item(
			(string) ( $cart_item['key'] ?? '' ),
			$cart_item
		);

		return $outcome->cart_item;
	}
}
