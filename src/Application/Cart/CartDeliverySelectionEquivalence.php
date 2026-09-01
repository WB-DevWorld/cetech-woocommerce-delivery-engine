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
 * Delivery Option merely to obtain a price.
 */
final class CartDeliverySelectionEquivalence {

	/**
	 * @param list<ProductDeliveryOption> $options
	 * @param array<string, mixed>        $stored_intent
	 */
	public static function findEquivalentOption( array $options, array $stored_intent ): ?ProductDeliveryOption {
		$choice = sanitize_key( (string) ( $stored_intent['fulfilment_choice'] ?? '' ) );

		if ( '' === $choice ) {
			return null;
		}

		$display_key = ProductDeliveryOptionsBuilder::normalizeDisplayKey(
			(string) ( $stored_intent['display_key'] ?? '' )
		);
		$offer_id = isset( $stored_intent['delivery_offer_id'] ) ? (int) $stored_intent['delivery_offer_id'] : 0;

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
			return 1 === count( $same_choice ) ? $same_choice[0] : null;
		}

		return null;
	}
}
