<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Selector;

use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * PDP completeness for customer-visible fulfilment cards.
 *
 * Delivery cards require a real configured estimate. Pickup is not gated on ETA.
 * Does not invent estimate copy from labels, prices, or rate-card names.
 */
final class CustomerVisibleDeliveryOptionGate {

	public static function is_selectable_pdp_card( ProductDeliveryOption $option ): bool {
		if ( ! $option->is_available ) {
			return false;
		}

		if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
			return true;
		}

		if ( FulfilmentChoice::Delivery->value !== $option->fulfilment_choice ) {
			return false;
		}

		return self::has_real_estimate( $option );
	}

	public static function has_real_estimate( ProductDeliveryOption $option ): bool {
		return null !== $option->estimate_text && '' !== trim( $option->estimate_text );
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return list<ProductDeliveryOption>
	 */
	public static function selectable_pdp_cards( array $options ): array {
		$kept = [];

		foreach ( $options as $option ) {
			if ( $option instanceof ProductDeliveryOption && self::is_selectable_pdp_card( $option ) ) {
				$kept[] = $option;
			}
		}

		return array_values( $kept );
	}
}
