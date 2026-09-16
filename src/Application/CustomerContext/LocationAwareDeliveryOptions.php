<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Selector\CustomerVisibleDeliveryOptionGate;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Filters product delivery options for a customer matching location.
 *
 * Pickup does not require a delivery destination. Delivery options require a
 * matching location and an authoritative DE quote for that geography, product
 * and quantity. Unquoted delivery options fail closed and are omitted.
 */
final class LocationAwareDeliveryOptions {

	public function __construct(
		private LocationOfferQuoteProbe $quote_probe,
		private ?ProductPageDeliveryPriceQuote $price_quote = null
	) {
	}

	/**
	 * @param list<ProductDeliveryOption> $options
	 *
	 * @return list<ProductDeliveryOption>
	 */
	public function filter(
		array $options,
		?MatchingLocation $location,
		string $currency_code = '',
		?ProductPageQuoteContext $quote_context = null
	): array {
		if ( '' === $currency_code ) {
			$currency_code = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';
		}

		$filtered = [];

		foreach ( $options as $option ) {
			if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
				if ( $option->is_available && $this->price_quote instanceof ProductPageDeliveryPriceQuote && $quote_context instanceof ProductPageQuoteContext ) {
					$price = $this->price_quote->price_for_option( $option, $quote_context, $location, $currency_code );
					$filtered[] = $price instanceof CustomerFacingDeliveryPrice ? $option->withCustomerPrice( $price ) : $option;
					continue;
				}

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

			if ( $this->price_quote instanceof ProductPageDeliveryPriceQuote && $quote_context instanceof ProductPageQuoteContext ) {
				$price = $this->price_quote->price_for_option( $option, $quote_context, $location, $currency_code );
				if ( ! $price instanceof CustomerFacingDeliveryPrice ) {
					continue;
				}
				$priced = $option->withCustomerPrice( $price );
				if ( ! CustomerVisibleDeliveryOptionGate::is_selectable_pdp_card( $priced ) ) {
					continue;
				}
				$filtered[] = $priced;
				continue;
			}

			if ( $this->quote_probe->offer_quotes_for_location( $offer_id, $location, $currency_code ) ) {
				if ( ! CustomerVisibleDeliveryOptionGate::is_selectable_pdp_card( $option ) ) {
					continue;
				}
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
