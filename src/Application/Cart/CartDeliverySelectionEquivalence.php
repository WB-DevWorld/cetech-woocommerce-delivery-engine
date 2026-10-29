<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Cart;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Deterministic equivalent matching for a stored customer delivery choice.
 *
 * Does not silently switch Delivery ↔ Store Pickup or substitute another
 * Delivery Option merely to obtain a price. COR-024: a Store Pickup choice is
 * bound to its Pickup Location id, never to the reusable sole-pickup display
 * key, so a replaced or removed location requires explicit reselection while
 * the unchanged location may refresh its label, address and readiness.
 */
final class CartDeliverySelectionEquivalence {

	/**
	 * @param list<ProductDeliveryOption> $options
	 * @param array<string, mixed>        $stored_intent
	 * @param int|null                    $stored_pickup_location_id The line's committed Pickup endpoint (customer context) when the intent predates COR-024.
	 */
	public static function findEquivalentOption( array $options, array $stored_intent, ?int $stored_pickup_location_id = null ): ?ProductDeliveryOption {
		$choice = sanitize_key( (string) ( $stored_intent['fulfilment_choice'] ?? '' ) );

		if ( '' === $choice ) {
			return null;
		}

		$display_key = ProductDeliveryOptionsBuilder::normalizeDisplayKey(
			(string) ( $stored_intent['display_key'] ?? '' )
		);
		$offer_id = isset( $stored_intent['delivery_offer_id'] ) ? (int) $stored_intent['delivery_offer_id'] : 0;
		$endpoint = self::storedPickupEndpoint( $stored_intent, $stored_pickup_location_id );

		$same_choice = [];

		foreach ( $options as $option ) {
			if ( ! $option instanceof ProductDeliveryOption || ! $option->is_available ) {
				continue;
			}

			if ( $option->fulfilment_choice !== $choice ) {
				continue;
			}

			$same_choice[] = $option;
		}

		if ( [] === $same_choice ) {
			return null;
		}

		if ( FulfilmentChoice::StorePickup->value === $choice && $endpoint > 0 ) {
			foreach ( $same_choice as $option ) {
				if ( (int) ( $option->pickup_location_id ?? 0 ) === $endpoint ) {
					return $option;
				}
			}

			// The committed endpoint is gone or replaced: never substitute another location.
			return null;
		}

		if ( '' !== $display_key ) {
			foreach ( $same_choice as $option ) {
				if ( $option->display_key === $display_key ) {
					return $option;
				}
			}
		}

		if ( FulfilmentChoice::Delivery->value === $choice ) {
			if ( $offer_id <= 0 ) {
				return null;
			}

			$matches = [];

			foreach ( $same_choice as $option ) {
				if ( (int) $option->delivery_offer_id === $offer_id ) {
					$matches[] = $option;
				}
			}

			return 1 === count( $matches ) ? $matches[0] : null;
		}

		if ( FulfilmentChoice::StorePickup->value === $choice ) {
			// Legacy line without a committed endpoint id: the historical sole-pickup rule.
			return 1 === count( $same_choice ) ? $same_choice[0] : null;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $stored_intent
	 */
	public static function storedPickupEndpoint( array $stored_intent, ?int $stored_pickup_location_id = null ): int {
		$from_intent = isset( $stored_intent['pickup_location_id'] ) && is_scalar( $stored_intent['pickup_location_id'] )
			? (int) $stored_intent['pickup_location_id']
			: 0;

		if ( $from_intent > 0 ) {
			return $from_intent;
		}

		return ( $stored_pickup_location_id ?? 0 ) > 0 ? (int) $stored_pickup_location_id : 0;
	}
}
