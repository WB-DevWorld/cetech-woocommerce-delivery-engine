<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Customer-safe fulfilment capabilities derived from unfiltered available options.
 *
 * Does not include supplier, origin, rate-card, or other private identifiers.
 */
final class ProductDeliveryFulfilmentCapabilities {

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return array{has_delivery: bool, has_pickup: bool, available_choices: list<string>}
	 */
	public static function from_options( array $options ): array {
		$has_delivery = false;
		$has_pickup   = false;

		foreach ( $options as $option ) {
			if ( ! $option instanceof ProductDeliveryOption || ! $option->is_available ) {
				continue;
			}

			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$has_pickup = true;
				continue;
			}

			if ( FulfilmentChoice::Delivery->value === $option->fulfilment_choice ) {
				$has_delivery = true;
			}
		}

		$choices = [];
		if ( $has_delivery ) {
			$choices[] = FulfilmentChoice::Delivery->value;
		}
		if ( $has_pickup ) {
			$choices[] = FulfilmentChoice::StorePickup->value;
		}

		return [
			'has_delivery'       => $has_delivery,
			'has_pickup'         => $has_pickup,
			'available_choices'  => $choices,
		];
	}

	public static function has_switch( array $capabilities ): bool {
		return ! empty( $capabilities['has_delivery'] ) && ! empty( $capabilities['has_pickup'] );
	}
}
