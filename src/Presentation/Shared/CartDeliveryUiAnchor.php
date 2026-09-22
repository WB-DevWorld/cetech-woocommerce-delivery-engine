<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Shared;

/**
 * Customer-safe cart-line DOM / URL fragment for Classic and Blocks.
 *
 * Derived only from the WooCommerce cart item key. Never includes address,
 * name, phone, email, or other customer PII.
 */
final class CartDeliveryUiAnchor {

	public const PREFIX = 'cetech-de-delivery-';

	private function __construct() {
	}

	public static function for_cart_item_key( string $cart_item_key ): string {
		$digest = hash( 'sha256', 'cetech-de-delivery-ui|' . $cart_item_key );

		return self::PREFIX . substr( $digest, 0, 16 );
	}

	public static function form_id_for_cart_item_key( string $cart_item_key ): string {
		return self::for_cart_item_key( $cart_item_key ) . '-form';
	}

	public static function reselection_form_id_for_cart_item_key( string $cart_item_key ): string {
		return self::for_cart_item_key( $cart_item_key ) . '-reselect';
	}

	public static function is_valid( string $anchor ): bool {
		return 1 === preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '[a-f0-9]{16}$/', $anchor );
	}

	public static function cart_url( string $cart_url, string $anchor ): string {
		$base = explode( '#', $cart_url, 2 )[0];

		if ( '' === $anchor || ! self::is_valid( $anchor ) ) {
			return $base;
		}

		return $base . '#' . $anchor;
	}
}
