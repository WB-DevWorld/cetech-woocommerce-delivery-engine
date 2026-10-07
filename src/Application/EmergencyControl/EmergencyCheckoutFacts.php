<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\EmergencyControl;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshot;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;

/** Bounded internal fingerprints. These hashes are never authority supplied by a client. */
final class EmergencyCheckoutFacts {
	public const MAX_LINES = 200;
	public const MAX_PACKAGES = 200;

	public static function positive_int( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && preg_match( '/\A[1-9][0-9]{0,18}\z/D', $value ) === 1
			&& ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || ( strlen( $value ) === strlen( (string) PHP_INT_MAX ) && strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ) ) {
			return (int) $value;
		}
		return null;
	}

	public static function quantity( mixed $value ): ?int {
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && $value <= 2147483647 ) {
			$value = (int) $value;
		}
		$id = self::positive_int( $value );
		return null !== $id && $id <= 2147483647 ? $id : null;
	}

	public static function has_line_evidence( array $line ): bool {
		foreach ( [ CartDeliverySelectionCapture::CART_SELECTION_KEY, CartDeliverySelectionCapture::CART_SUMMARY_KEY, CartDeliverySelectionCapture::CART_HASH_KEY, CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY, CustomerCartContext::CART_KEY ] as $key ) {
			if ( array_key_exists( $key, $line ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{product_id:int,variation_id:?int,quantity:int}|null */
	public static function line_identity( array $line ): ?array {
		$product_id = self::positive_int( $line['product_id'] ?? null );
		$variation = $line['variation_id'] ?? null;
		$variation = in_array( $variation, [ null, 0, '0', '' ], true ) ? null : self::positive_int( $variation );
		$quantity = self::quantity( $line['quantity'] ?? null );
		if ( null === $product_id || null === $quantity || ( null === $variation && ! in_array( $line['variation_id'] ?? null, [ null, 0, '0', '' ], true ) ) ) {
			return null;
		}
		return [ 'product_id' => $product_id, 'variation_id' => $variation, 'quantity' => $quantity ];
	}

	public static function bounded_hash( mixed $value ): ?string {
		$nodes = 0;
		$check = static function ( mixed $node, int $depth ) use ( &$check, &$nodes ): bool {
			if ( ++$nodes > 12000 || $depth > 16 || is_object( $node ) || is_resource( $node ) || ( is_float( $node ) && ! is_finite( $node ) ) ) {
				return false;
			}
			if ( is_array( $node ) ) {
				foreach ( $node as $key => $child ) {
					if ( ( is_string( $key ) && strlen( $key ) > 256 ) || ! $check( $child, $depth + 1 ) ) {
						return false;
					}
				}
			}
			return true;
		};
		if ( ! $check( $value, 0 ) ) {
			return null;
		}
		try {
			$json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION );
			return strlen( $json ) <= 1048576 ? hash( 'sha256', $json ) : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	public static function order_fingerprint( \WC_Order $order ): ?string {
		try {
			$items = $order->get_items( 'line_item' );
			$shipping = $order->get_items( 'shipping' );
			if ( count( $items ) > self::MAX_LINES || count( $shipping ) > self::MAX_PACKAGES ) {
				return null;
			}
			$facts = [ 'site' => get_current_blog_id(), 'order' => $order->get_id(), 'paid' => $order->is_paid(), 'status' => $order->get_status(), 'lines' => [], 'shipping' => [] ];
			foreach ( [ 'get_currency', 'get_customer_id', 'get_total', 'get_total_tax', 'get_shipping_total', 'get_shipping_tax', 'get_shipping_country', 'get_shipping_state', 'get_shipping_city', 'get_shipping_postcode', 'get_shipping_address_1', 'get_shipping_address_2' ] as $method ) {
				if ( ! method_exists( $order, $method ) ) {
					return null;
				}
				$facts[ $method ] = $order->$method( 'edit' );
			}
			$facts['locale'] = function_exists( 'get_locale' ) ? get_locale() : '';
			$facts['package'] = $order->get_meta( OrderDeliverySnapshot::META_ORDER_QUOTE_SNAPSHOT, true );
			$facts['package_version'] = $order->get_meta( OrderDeliverySnapshot::META_ORDER_SNAPSHOT_VERSION, true );
			foreach ( $items as $key => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product ) {
					return null;
				}
				$facts['lines'][ $key ] = [ $item->get_id(), $item->get_product_id(), $item->get_variation_id(), $item->get_quantity(), $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT, true ), $item->get_meta( OrderDeliverySnapshot::META_LINE_SNAPSHOT_VERSION, true ) ];
			}
			foreach ( $shipping as $key => $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_total_tax' ) || ! method_exists( $item, 'get_taxes' ) ) {
					return null;
				}
				$facts['shipping'][ $key ] = [ $item->get_method_id(), $item->get_total( 'edit' ), $item->get_total_tax( 'edit' ), $item->get_taxes( 'edit' ), $item->get_meta( 'cetech_de_group_id', true ) ];
			}
			return self::bounded_hash( $facts );
		} catch ( \Throwable ) {
			return null;
		}
	}
}
