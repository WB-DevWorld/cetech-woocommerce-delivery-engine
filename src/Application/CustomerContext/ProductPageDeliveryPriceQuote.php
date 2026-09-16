<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Selector\ProductDeliveryOption;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Authoritative PDP delivery prices using the same quote path as cart/checkout.
 */
final class ProductPageDeliveryPriceQuote {

	public function __construct(
		private SelectedOfferShippingRateCalculator $calculator,
		private ProductDeliverySelectionValidatorInterface $selection_validator
	) {
	}

	public function price_for_option(
		ProductDeliveryOption $option,
		ProductPageQuoteContext $context,
		?MatchingLocation $location,
		string $currency_code
	): ?CustomerFacingDeliveryPrice {
		if ( ! $option->is_available ) {
			return null;
		}

		if ( FulfilmentChoice::StorePickup->value === $option->fulfilment_choice ) {
			return CustomerFacingDeliveryPrice::free( $currency_code );
		}

		if ( ! $location instanceof MatchingLocation || ! $location->isPresent() ) {
			return null;
		}

		$validation = $this->selection_validator->validate(
			$context->product_id,
			$context->variation_id > 0 ? $context->variation_id : null,
			$option->display_key
		);

		if ( ! $validation->valid || ! is_array( $validation->intent ) ) {
			return null;
		}

		return $this->price_for_intent(
			$validation->intent,
			[
				'product_id'   => $context->product_id,
				'variation_id' => $context->variation_id,
				'quantity'     => $context->quantity,
			],
			$location->toWcPackageDestination(),
			$currency_code
		);
	}

	/**
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $cart_item
	 * @param array<string, mixed> $destination
	 */
	public function price_for_intent(
		array $intent,
		array $cart_item,
		array $destination,
		string $currency_code
	): ?CustomerFacingDeliveryPrice {
		if ( FulfilmentChoice::StorePickup->value === sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) ) {
			return CustomerFacingDeliveryPrice::free( $currency_code );
		}

		$result = $this->calculator->quote_for_selection( $cart_item, $intent, $destination, $currency_code );

		if ( ! $result->success || null === $result->total_amount || null === $result->currency ) {
			return null;
		}

		return CustomerFacingDeliveryPrice::from_quoted_amount(
			$result->total_amount,
			$result->currency,
			$result->charge_type
		);
	}
}
