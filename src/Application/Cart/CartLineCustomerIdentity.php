<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Customer-owned cart-line identity used for WooCommerce cart IDs and merging.
 *
 * Admin-derived configuration versions, labels, ETA, fingerprints and hashes
 * must not permanently force duplicate cart lines. Different customer-owned
 * fulfilment contexts must remain separate.
 */
final class CartLineCustomerIdentity {

	/** @var list<string> */
	public const CUSTOMER_LOCATION_INTENT_KEYS = [
		'customer_location',
		'customer_location_id',
		'location_id',
		'per_item_location',
	];

	public const CART_LOCATION_KEY = 'cetech_de_customer_location';

	/**
	 * @param array<string, mixed> $cart_item_data
	 */
	public static function isDeliveryEngineKey( string $key ): bool {
		return str_starts_with( $key, 'cetech_de_' );
	}

	/**
	 * Stable identity string: fulfilment choice + offer/pickup + per-item location.
	 *
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $cart_item_data
	 */
	public static function identityString( array $intent, array $cart_item_data = [] ): string {
		$choice = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );

		if ( '' === $choice ) {
			return '';
		}

		$offer_segment = 'none';

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			$offer_segment = 'pickup';
		} else {
			$offer_id = isset( $intent['delivery_offer_id'] ) ? (int) $intent['delivery_offer_id'] : 0;
			$offer_segment = $offer_id > 0 ? (string) $offer_id : 'none';
		}

		return $choice . '|' . $offer_segment . '|' . self::locationToken( $intent, $cart_item_data );
	}

	/**
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $cart_item_data
	 */
	public static function locationToken( array $intent, array $cart_item_data = [] ): string {
		$raw = $cart_item_data[ self::CART_LOCATION_KEY ] ?? null;

		if ( is_array( $raw ) || is_object( $raw ) ) {
			$encoded = wp_json_encode( $raw );
			$token   = is_string( $encoded ) ? $encoded : '';
		} elseif ( null !== $raw && '' !== $raw ) {
			$token = sanitize_text_field( (string) $raw );
		} else {
			$token = '';
		}

		if ( '' === $token ) {
			foreach ( self::CUSTOMER_LOCATION_INTENT_KEYS as $key ) {
				if ( ! isset( $intent[ $key ] ) || '' === $intent[ $key ] ) {
					continue;
				}

				if ( is_array( $intent[ $key ] ) || is_object( $intent[ $key ] ) ) {
					$encoded = wp_json_encode( $intent[ $key ] );
					$token   = is_string( $encoded ) ? $encoded : '';
				} else {
					$token = sanitize_text_field( (string) $intent[ $key ] );
				}

				if ( '' !== $token ) {
					break;
				}
			}
		}

		return '' === $token ? '' : strtolower( $token );
	}

	/**
	 * Copy customer-owned location fields from a stored intent onto a refreshed intent.
	 *
	 * @param array<string, mixed> $fresh_intent
	 * @param array<string, mixed> $stored_intent
	 *
	 * @return array<string, mixed>
	 */
	public static function overlayCustomerOwned( array $fresh_intent, array $stored_intent ): array {
		$issued_at = sanitize_text_field( (string) ( $stored_intent['issued_at'] ?? '' ) );

		if ( '' !== $issued_at ) {
			$fresh_intent['issued_at'] = $issued_at;
		}

		foreach ( self::CUSTOMER_LOCATION_INTENT_KEYS as $key ) {
			if ( ! array_key_exists( $key, $stored_intent ) ) {
				continue;
			}

			$value = $stored_intent[ $key ];

			if ( null === $value || '' === $value ) {
				continue;
			}

			$fresh_intent[ $key ] = is_scalar( $value )
				? sanitize_text_field( (string) $value )
				: $value;
		}

		return $fresh_intent;
	}

	/**
	 * WooCommerce cart ID using customer-owned DE context instead of admin fingerprints.
	 *
	 * @param array<string, mixed> $variation
	 * @param array<string, mixed> $cart_item_data
	 */
	public static function generateCartId( int $product_id, int $variation_id, array $variation, array $cart_item_data ): string {
		$id_parts = [ (string) $product_id ];

		if ( 0 !== $variation_id ) {
			$id_parts[] = (string) $variation_id;
		}

		if ( [] !== $variation ) {
			$variation_key = '';

			foreach ( $variation as $key => $value ) {
				$variation_key .= trim( (string) $key ) . trim( (string) $value );
			}

			$id_parts[] = $variation_key;
		}

		$intent = [];

		if ( isset( $cart_item_data[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) && is_array( $cart_item_data[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ) ) {
			$normalized = CartDeliverySelectionSessionData::normalizeIntent(
				$cart_item_data[ CartDeliverySelectionCapture::CART_SELECTION_KEY ]
			);
			$intent = is_array( $normalized ) ? $normalized : $cart_item_data[ CartDeliverySelectionCapture::CART_SELECTION_KEY ];
		}

		$identity = self::identityString( $intent, $cart_item_data );

		if ( '' !== $identity ) {
			$id_parts[] = $identity;
		}

		$other = '';

		foreach ( $cart_item_data as $key => $value ) {
			if ( self::isDeliveryEngineKey( (string) $key ) ) {
				continue;
			}

			if ( is_array( $value ) || is_object( $value ) ) {
				$value = http_build_query( (array) $value );
			}

			$other .= trim( (string) $key ) . trim( (string) $value );
		}

		if ( '' !== $other ) {
			$id_parts[] = $other;
		}

		return md5( implode( '_', $id_parts ) );
	}

	/**
	 * Extra cart-item data keys that WooCommerce includes in generate_cart_id().
	 *
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array<string, mixed>
	 */
	public static function extraItemData( array $cart_item ): array {
		$skip = [
			'key',
			'product_id',
			'variation_id',
			'variation',
			'quantity',
			'data',
			'data_hash',
			'line_tax_data',
			'line_subtotal',
			'line_subtotal_tax',
			'line_total',
			'line_tax',
		];

		$extra = [];

		foreach ( $cart_item as $key => $value ) {
			if ( in_array( (string) $key, $skip, true ) ) {
				continue;
			}

			if ( is_object( $value ) ) {
				continue;
			}

			$extra[ (string) $key ] = $value;
		}

		return $extra;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public static function cartIdFromItem( array $cart_item ): string {
		return self::generateCartId(
			(int) ( $cart_item['product_id'] ?? 0 ),
			(int) ( $cart_item['variation_id'] ?? 0 ),
			is_array( $cart_item['variation'] ?? null ) ? $cart_item['variation'] : [],
			self::extraItemData( $cart_item )
		);
	}
}
