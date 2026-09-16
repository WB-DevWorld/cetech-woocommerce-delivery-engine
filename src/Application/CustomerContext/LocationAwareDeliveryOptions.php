<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Filters product delivery options for a customer matching location.
 *
 * Pickup does not require a delivery destination. Delivery options require a
 * matching location and a valid DE quote for that geography.
 */
final class LocationAwareDeliveryOptions {

	public function __construct(
		private LocationOfferQuoteProbe $quote_probe
	) {
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return list<ProductDeliveryOption>
	 */
	public function filter( array $options, ?MatchingLocation $location, string $currency_code = '' ): array {
		if ( '' === $currency_code ) {
			$currency_code = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
		}

		$filtered = [];

		foreach ( $options as $option ) {
			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				$filtered[] = $option;
				continue;
			}

			if ( ! $option->is_available ) {
				continue;
			}

			if ( ! $location instanceof MatchingLocation || ! $location->isPresent() ) {
				continue;
			}

			$offer_id = $option->delivery_offer_id;
			if ( ! is_int( $offer_id ) || $offer_id <= 0 ) {
				continue;
			}

			if ( $this->quote_probe->offer_quotes_for_location( $offer_id, $location, $currency_code ) ) {
				$filtered[] = $option;
			}
		}

		return array_values( $filtered );
	}

	public function delivery_requires_matching_location( array $options ): bool {
		foreach ( $options as $option ) {
			if ( $option instanceof ProductDeliveryOption && FulfilmentChoice::Delivery->value === $option->fulfilment_choice && $option->is_available ) {
				return true;
			}
		}

		return false;
	}
}
