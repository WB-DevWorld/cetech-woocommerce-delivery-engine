<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\LocationIdentity;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Canonical delivery-group identity for cart, packages, snapshots, and history.
 *
 * v1 (RC.9): availability|choice|offer_or_pickup
 * v2:        availability|choice|offer_or_pickup|destination
 *
 * Runtime-only `|reselect` is never persisted onto a successful historical order.
 * Destination segments are truncated SHA-256 hashes — never raw addresses.
 */
final class DeliveryGroupIdentity {

	public const PACKAGE_META_KEY = 'cetech_de';

	public const RESELECT_SUFFIX = 'reselect';

	public const PICKUP_DESTINATION = 'pickup';

	public const COLUMN_LENGTH = 191;

	public const IDEMPOTENCY_KEY_LENGTH = 255;

	/**
	 * v1 three-part identity from admin-derived selection intent only.
	 *
	 * @param array<string, mixed> $intent Normalized selection intent.
	 */
	public static function fromIntent( array $intent ): ?string {
		return self::composeSelection( $intent );
	}

	/**
	 * Customer-context-aware identity used by package builder, rate calculator,
	 * cartstate reconciliation, and snapshots. Same inputs → same id.
	 *
	 * @param array<string, mixed> $cart_item
	 */
	public static function fromCartItem( array $cart_item ): ?string {
		$raw    = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;
		$intent = CartDeliverySelectionSessionData::normalizeIntent( $raw );

		if ( null === $intent && is_array( $raw ) ) {
			$availability = sanitize_key( (string) ( $raw['fulfilment_availability'] ?? '' ) );
			$choice       = sanitize_key( (string) ( $raw['fulfilment_choice'] ?? '' ) );
			$intent       = ( '' !== $availability && '' !== $choice ) ? $raw : null;
		}

		if ( null === $intent ) {
			return null;
		}

		$context = CustomerCartContext::fromCartItem( $cart_item );
		$base    = self::fromIntentAndContext( $intent, $context, false );

		if ( null === $base ) {
			return null;
		}

		if ( ! empty( $cart_item[ CartDeliverySelectionCapture::CART_NEEDS_RESELECTION_KEY ] ) ) {
			return $base . '|' . self::RESELECT_SUFFIX;
		}

		return $base;
	}

	/**
	 * Historical paid-order identity. Delivery requires a complete delivery-location
	 * identity. Never includes `|reselect`.
	 *
	 * @param array<string, mixed> $intent
	 */
	public static function forHistorical( array $intent, ?CustomerCartContext $context ): ?string {
		$id = self::fromIntentAndContext( $intent, $context, true );

		return self::stripRuntimeSuffix( $id );
	}

	/**
	 * @param array<string, mixed> $intent
	 */
	public static function fromIntentAndContext(
		array $intent,
		?CustomerCartContext $context,
		bool $historical
	): ?string {
		$base = self::composeSelection( $intent, $context );

		if ( null === $base ) {
			return null;
		}

		if ( ! $context instanceof CustomerCartContext ) {
			return $base;
		}

		$destination = self::destinationSegment( $intent, $context, $historical );

		if ( null === $destination ) {
			return null;
		}

		return $base . '|' . $destination;
	}

	/**
	 * @param array<string, mixed> $intent
	 */
	public static function composeSelection( array $intent, ?CustomerCartContext $context = null ): ?string {
		$availability = sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) );
		$choice       = sanitize_key(
			(string) ( $context?->fulfilment_choice ?? $intent['fulfilment_choice'] ?? '' )
		);

		if ( '' === $availability || '' === $choice ) {
			return null;
		}

		$offer_segment = 'none';

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			if ( $context instanceof CustomerCartContext ) {
				if ( ! $context->isPickupComplete() ) {
					return null;
				}

				$offer_segment = 'p' . (string) $context->pickup_location_id;
			} else {
				$offer_segment = 'pickup';
			}
		} else {
			$offer_id = $context?->delivery_offer_id;
			if ( ! is_int( $offer_id ) || $offer_id <= 0 ) {
				$offer_id = isset( $intent['delivery_offer_id'] ) ? (int) $intent['delivery_offer_id'] : 0;
			}

			if ( $offer_id <= 0 ) {
				return null;
			}

			$offer_segment = (string) $offer_id;
		}

		return self::compose( $availability, $choice, $offer_segment );
	}

	/**
	 * @param array<string, mixed> $intent
	 */
	public static function destinationSegment(
		array $intent,
		?CustomerCartContext $context,
		bool $historical
	): ?string {
		$choice = sanitize_key(
			(string) ( $context?->fulfilment_choice ?? $intent['fulfilment_choice'] ?? '' )
		);

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			if ( $context instanceof CustomerCartContext && ! $context->isPickupComplete() ) {
				return null;
			}

			return self::PICKUP_DESTINATION;
		}

		if ( $historical ) {
			if ( ! $context instanceof CustomerCartContext || ! $context->hasCompleteDeliveryAddress() ) {
				return null;
			}

			return LocationIdentity::destinationSegment( (string) $context->delivery_location_identity );
		}

		if ( ! $context instanceof CustomerCartContext ) {
			return 'none';
		}

		if ( $context->hasCompleteDeliveryAddress() && is_string( $context->delivery_location_identity ) ) {
			return LocationIdentity::destinationSegment( $context->delivery_location_identity );
		}

		if ( $context->hasMatchingLocation() && is_string( $context->matching_identity ) ) {
			return LocationIdentity::destinationSegment( $context->matching_identity, 'm' );
		}

		return 'none';
	}

	public static function compose( string $availability, string $choice, string $offer_segment ): string {
		return sanitize_key( $availability ) . '|' . sanitize_key( $choice ) . '|' . sanitize_key( $offer_segment );
	}

	public static function is_pickup_group( string $group_id ): bool {
		$parsed = self::parse( $group_id );

		return is_array( $parsed )
			&& FulfilmentChoice::StorePickup->value === $parsed['choice'];
	}

	public static function has_runtime_reselect( string $group_id ): bool {
		$parsed = self::parse( $group_id );

		return is_array( $parsed ) && $parsed['reselect'];
	}

	public static function stripRuntimeSuffix( ?string $group_id ): ?string {
		if ( null === $group_id || '' === $group_id ) {
			return $group_id;
		}

		$parsed = self::parse( $group_id );

		if ( ! is_array( $parsed ) ) {
			return $group_id;
		}

		$base = $parsed['availability'] . '|' . $parsed['choice'] . '|' . $parsed['offer_segment'];

		if ( '' !== $parsed['destination'] ) {
			$base .= '|' . $parsed['destination'];
		}

		return $base;
	}

	/**
	 * @return array{availability: string, choice: string, offer_segment: string, destination: string, reselect: bool, version: int}|null
	 */
	public static function parse( string $group_id ): ?array {
		if ( '' === $group_id ) {
			return null;
		}

		$parts = explode( '|', $group_id );

		if ( count( $parts ) < 3 ) {
			return null;
		}

		$reselect = false;

		if ( self::RESELECT_SUFFIX === $parts[ count( $parts ) - 1 ] ) {
			$reselect = true;
			array_pop( $parts );
		}

		if ( count( $parts ) < 3 || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		$destination = '';
		$version     = 1;

		if ( 4 === count( $parts ) ) {
			$destination = $parts[3];
			$version     = 2;
		} elseif ( 3 !== count( $parts ) ) {
			return null;
		}

		return [
			'availability'  => $parts[0],
			'choice'        => $parts[1],
			'offer_segment' => $parts[2],
			'destination'   => $destination,
			'reselect'      => $reselect,
			'version'       => $version,
		];
	}

	/**
	 * Worst-case encoded length using current enums, pickup bigint ids, and
	 * destination-hash segments. Runtime `|reselect` is included for cart only.
	 */
	public static function worstCaseLength( bool $include_reselect = false ): int {
		$availability = 0;

		foreach ( FulfilmentAvailability::cases() as $case ) {
			$availability = max( $availability, strlen( $case->value ) );
		}

		$choice = 0;

		foreach ( FulfilmentChoice::cases() as $case ) {
			$choice = max( $choice, strlen( $case->value ) );
		}

		$offer       = 1 + 20;
		$destination = 1 + 1 + LocationIdentity::DESTINATION_SEGMENT_LENGTH;
		$separators  = 3;
		$reselect    = $include_reselect ? 1 + strlen( self::RESELECT_SUFFIX ) : 0;

		return $availability + $choice + $offer + $destination + $separators + $reselect;
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
