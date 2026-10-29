<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Frontend;

use WC_Order;

/**
 * Who may see an order's customer delivery surfaces (COR-034).
 *
 * The plugin no longer relies on the host template alone: on My Account → View Order
 * the viewer must hold `view_order` for that order (the owner, or staff WooCommerce
 * grants it to); on the order-received page the request must carry the order key
 * (the guest route) or the viewer must be the owner. Any other surface is refused.
 */
final class OrderSurfaceAuthority {

	private function __construct() {
	}

	public static function may_view( WC_Order $order ): bool {
		$order_id = (int) $order->get_id();

		if ( $order_id <= 0 ) {
			return false;
		}

		if ( function_exists( 'is_view_order_page' ) && is_view_order_page() ) {
			return self::is_owner_or_staff( $order_id );
		}

		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return self::request_carries_order_key( $order ) || self::is_owner_or_staff( $order_id );
		}

		return false;
	}

	public static function is_owner_or_staff( int $order_id ): bool {
		return $order_id > 0
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'view_order', $order_id );
	}

	public static function request_carries_order_key( WC_Order $order ): bool {
		if ( ! method_exists( $order, 'get_order_key' ) ) {
			return false;
		}

		$expected = trim( (string) $order->get_order_key() );

		if ( '' === $expected ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce's own guest order-key route.
		$raw = isset( $_GET['key'] ) ? (string) wp_unslash( (string) $_GET['key'] ) : '';
		$key = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : trim( $raw );

		return '' !== $key && hash_equals( $expected, $key );
	}
}
