<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Deterministic delivery-group identity for cart/package consolidation.
 *
 * Internal only — never expose the raw group id to customers.
 */
final class DeliveryGroupIdentity {

	public const PACKAGE_META_KEY = 'cetech_de';

	/**
	 * Build a stable group id from a captured cart-line selection intent.
	 *
	 * Compatibility rule (Stage 8A):
	 * Items may share a group only when fulfilment availability, fulfilment
	 * choice, and selected delivery offer (or shared store-pickup choice) match.
	 *
	 * This is not “same visible offer label only”: availability + choice are
	 * mandatory dimensions, so local vs international and pickup vs delivery
	 * never consolidate. Variation identity is respected because grouping uses
	 * the cart line’s selected variation intent, not the parent alone.
	 *
	 * Deferred (not inventing new dimensions for this release):
	 * - pickup location id (not present on V1 selection intent)
	 * - supplier/origin logistics optimizer (quote uses representative-line
	 *   dimensions within an already-compatible group)
	 *
	 * @param array<string, mixed> $intent Normalized selection intent.
	 */
	public static function fromIntent( array $intent ): ?string {
		$availability = sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) );
		$choice       = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );

		if ( '' === $availability || '' === $choice ) {
			return null;
		}

		$offer_segment = 'none';

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			$offer_segment = 'pickup';
		} else {
			$offer_id = isset( $intent['delivery_offer_id'] ) ? (int) $intent['delivery_offer_id'] : 0;

			if ( $offer_id <= 0 ) {
				return null;
			}

			$offer_segment = (string) $offer_id;
		}

		return self::compose( $availability, $choice, $offer_segment );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	public static function fromCartItem( array $cart_item ): ?string {
		$raw = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;
		$intent = CartDeliverySelectionSessionData::normalizeIntent( $raw );

		if ( null === $intent ) {
			return null;
		}

		return self::fromIntent( $intent );
	}

	public static function compose( string $availability, string $choice, string $offer_segment ): string {
		return sanitize_key( $availability ) . '|' . sanitize_key( $choice ) . '|' . sanitize_key( $offer_segment );
	}

	public static function is_pickup_group( string $group_id ): bool {
		$parts = explode( '|', $group_id );

		return 3 === count( $parts )
			&& FulfilmentChoice::StorePickup->value === $parts[1]
			&& 'pickup' === $parts[2];
	}

	/**
	 * @param array<string, mixed> $package WooCommerce shipping package.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function package_meta( array $package ): ?array {
		$meta = $package[ self::PACKAGE_META_KEY ] ?? null;

		return is_array( $meta ) ? $meta : null;
	}

	/**
	 * @param array<string, mixed> $package
	 */
	public static function is_managed_package( array $package ): bool {
		$meta = self::package_meta( $package );

		return is_array( $meta ) && ! empty( $meta['managed'] );
	}
}
