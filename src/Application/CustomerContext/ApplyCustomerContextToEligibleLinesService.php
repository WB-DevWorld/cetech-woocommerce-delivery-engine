<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Application\Cart\CartCustomerContextMutationService;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidatorInterface;
use CetechDeliveryEngine\Domain\CustomerContext\CustomerCartContext;
use CetechDeliveryEngine\Domain\CustomerContext\DeliveryAddress;
use CetechDeliveryEngine\Domain\CustomerContext\MatchingLocation;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;

/**
 * Transaction: copy a Delivery customer location onto compatible managed lines.
 *
 * Never converts Pickup ↔ Delivery or local ↔ International. Failed lines stay unchanged.
 */
final class ApplyCustomerContextToEligibleLinesService {

	public function __construct(
		private CartCustomerContextMutationService $mutation,
		private ProductDeliverySelectionValidatorInterface $selection_validator,
		private LocationOfferQuoteProbe $quote_probe
	) {
	}

	/**
	 * @param array<string, array<string, mixed>> $contents
	 *
	 * @return array{
	 *     contents: array<string, array<string, mixed>>,
	 *     updated: list<array{key: string, name: string}>,
	 *     skipped: list<array{key: string, name: string, reason: string}>
	 * }
	 */
	public function applyDeliveryLocation(
		array $contents,
		string $source_key,
		CustomerCartContext $requested
	): array {
		$updated = [];
		$skipped = [];
		$working = $contents;

		if ( ! $requested->isDelivery() ) {
			return [
				'contents' => $contents,
				'updated'  => [],
				'skipped'  => [
					[
						'key'    => $source_key,
						'name'   => $this->line_name( $contents[ $source_key ] ?? [] ),
						'reason' => __( 'Only a Delivery address can be applied to other delivery items.', 'cetech-woocommerce-delivery-engine' ),
					],
				],
			];
		}

		$location = $requested->delivery_address instanceof DeliveryAddress
			? $requested->delivery_address
			: $requested->matching_location;

		if ( ! $location instanceof DeliveryAddress && ! $location instanceof MatchingLocation ) {
			return [
				'contents' => $contents,
				'updated'  => [],
				'skipped'  => [],
			];
		}

		foreach ( array_keys( $contents ) as $key ) {
			$item = $working[ $key ] ?? null;
			if ( ! is_array( $item ) ) {
				continue;
			}

			$intent = CartDeliverySelectionSessionData::normalizeIntent(
				$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);

			if ( null === $intent ) {
				continue;
			}

			$choice        = sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) );
			$availability  = sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) );
			$source_intent = CartDeliverySelectionSessionData::normalizeIntent(
				( $contents[ $source_key ][ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null )
			);
			$source_avail  = is_array( $source_intent )
				? sanitize_key( (string) ( $source_intent['fulfilment_availability'] ?? '' ) )
				: '';

			$name = $this->line_name( $item );

			if ( FulfilmentChoice::StorePickup->value === $choice ) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'This item uses Store Pickup.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			if ( FulfilmentChoice::Delivery->value !== $choice ) {
				continue;
			}

			if (
				FulfilmentAvailability::InternationalFulfilment->value === $availability
				&& FulfilmentAvailability::InternationalFulfilment->value !== $source_avail
			) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'This item uses International Air/Sea fulfilment.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			if (
				FulfilmentAvailability::InternationalFulfilment->value !== $availability
				&& FulfilmentAvailability::InternationalFulfilment->value === $source_avail
			) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'This item uses local fulfilment and cannot take an international destination.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			$offer_id = isset( $intent['delivery_offer_id'] ) ? (int) $intent['delivery_offer_id'] : 0;
			$dest     = $location->toWcPackageDestination();
			$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'USD';

			if ( $offer_id <= 0 || ! $this->quote_probe->offer_quotes_for_destination( $offer_id, $dest, $currency ) ) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'This item cannot be delivered to that address.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			$display_key = (string) ( $intent['display_key'] ?? '' );
			$product_id   = (int) ( $item['product_id'] ?? 0 );
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$validated    = $this->selection_validator->validate(
				$product_id,
				$variation_id > 0 ? $variation_id : null,
				$display_key
			);

			if ( ! $validated->valid ) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'The current delivery option is no longer available for this item.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			$next = CustomerCartContext::delivery(
				$offer_id,
				$location instanceof DeliveryAddress ? $location->matching : $location,
				$location instanceof DeliveryAddress ? $location : null
			);

			$result = $this->mutation->updateWholeLine( $working, (string) $key, $next );
			if ( ! $result->ok ) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'This item could not be updated.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			$working   = $result->contents;
			$updated[] = [
				'key'  => $result->target_key,
				'name' => $name,
			];
		}

		return [
			'contents' => $working,
			'updated'  => $updated,
			'skipped'  => $skipped,
		];
	}

	/**
	 * Apply checkout shipping address to incomplete Delivery lines only.
	 *
	 * @param array<string, array<string, mixed>> $contents
	 * @param array<string, mixed>                 $checkout_address
	 *
	 * @return array{
	 *     contents: array<string, array<string, mixed>>,
	 *     updated: list<array{key: string, name: string}>,
	 *     skipped: list<array{key: string, name: string, reason: string}>
	 * }
	 */
	public function applyCheckoutAddressToIncomplete( array $contents, array $checkout_address ): array {
		$address = DeliveryAddress::fromInput( $checkout_address );
		if ( ! $address->isComplete() ) {
			return [
				'contents' => $contents,
				'updated'  => [],
				'skipped'  => [
					[
						'key'    => '',
						'name'   => '',
						'reason' => __( 'The checkout shipping address is incomplete.', 'cetech-woocommerce-delivery-engine' ),
					],
				],
			];
		}

		$updated = [];
		$skipped = [];
		$working = $contents;

		foreach ( array_keys( $contents ) as $key ) {
			$item = $working[ $key ] ?? null;
			if ( ! is_array( $item ) ) {
				continue;
			}

			$context = CustomerCartContext::fromCartItem( $item );
			$intent  = CartDeliverySelectionSessionData::normalizeIntent(
				$item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null
			);

			if ( null === $intent ) {
				continue;
			}

			$name = $this->line_name( $item );

			if ( FulfilmentChoice::StorePickup->value === (string) ( $intent['fulfilment_choice'] ?? '' ) ) {
				continue;
			}

			if ( $context instanceof CustomerCartContext && $context->hasCompleteDeliveryAddress() ) {
				continue;
			}

			if ( FulfilmentChoice::Delivery->value !== sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) ) {
				continue;
			}

			$offer_id = isset( $intent['delivery_offer_id'] ) ? (int) $intent['delivery_offer_id'] : 0;
			$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : 'GHS';

			if ( $offer_id <= 0 || ! $this->quote_probe->offer_quotes_for_destination( $offer_id, $address->toWcPackageDestination(), $currency ) ) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'This item cannot be delivered to the checkout shipping address.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			$next   = CustomerCartContext::delivery( $offer_id, $address->matching, $address );
			$result = $this->mutation->updateWholeLine( $working, (string) $key, $next );

			if ( ! $result->ok ) {
				$skipped[] = [
					'key'    => (string) $key,
					'name'   => $name,
					'reason' => __( 'This item could not be updated.', 'cetech-woocommerce-delivery-engine' ),
				];
				continue;
			}

			$working   = $result->contents;
			$updated[] = [
				'key'  => $result->target_key,
				'name' => $name,
			];
		}

		return [
			'contents' => $working,
			'updated'  => $updated,
			'skipped'  => $skipped,
		];
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function line_name( array $item ): string {
		$data = $item['data'] ?? null;
		if ( is_object( $data ) && method_exists( $data, 'get_name' ) ) {
			$name = trim( (string) $data->get_name() );
			if ( '' !== $name ) {
				return $name;
			}
		}

		return __( 'a product in your cart', 'cetech-woocommerce-delivery-engine' );
	}
}
