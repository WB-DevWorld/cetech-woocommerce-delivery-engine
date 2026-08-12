<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidationResult;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;

/**
 * Default cart-line assessor for selected-offer shipping calculation.
 */
final class DefaultCartLineShippingAssessor implements CartLineShippingAssessorInterface {

	public function __construct(
		private CartDeliverySelectionCapture $cart_capture,
		private CartDeliverySelectionRevalidator $cart_revalidator
	) {
	}

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array{action: string, reason?: string, intent?: array<string, mixed>}
	 */
	public function assess_line( string $cart_item_key, array $cart_item ): array {
		$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );

		if ( $product_id <= 0 ) {
			return [ 'action' => 'skip' ];
		}

		$has_selection = isset( $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] );

		if ( $has_selection ) {
			if ( $this->has_hash_mismatch( $cart_item ) ) {
				return [
					'action' => 'block',
					'reason' => SelectedOfferShippingRateCalculator::BLOCK_LINE_INVALID,
				];
			}

			$revalidation = $this->cart_revalidator->revalidate_cart_item( $cart_item_key, $cart_item );

			if ( CartDeliverySelectionRevalidationResult::STATUS_VALID !== $revalidation->status ) {
				return [
					'action' => 'block',
					'reason' => $this->block_reason_from_status( $revalidation->status ),
				];
			}

			$intent = $revalidation->stored_intent;

			if ( ! is_array( $intent ) ) {
				return [
					'action' => 'block',
					'reason' => SelectedOfferShippingRateCalculator::BLOCK_LINE_INVALID,
				];
			}

			return [
				'action' => 'quote',
				'intent' => $intent,
			];
		}

		if ( ! $this->cart_capture->should_apply_capture_to_line( $product_id, $variation_id ) ) {
			return [ 'action' => 'skip' ];
		}

		$assessment = $this->cart_capture->assess_product_selection( $product_id, $variation_id );

		if ( 'none' === $assessment['requirement'] ) {
			return [ 'action' => 'skip' ];
		}

		if ( 'blocked' === $assessment['requirement'] ) {
			return [
				'action' => 'block',
				'reason' => SelectedOfferShippingRateCalculator::BLOCK_LINE_UNAVAILABLE,
			];
		}

		return [
			'action' => 'block',
			'reason' => SelectedOfferShippingRateCalculator::BLOCK_LINE_MISSING,
		];
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private function has_hash_mismatch( array $cart_item ): bool {
		$intent_raw = $cart_item[ CartDeliverySelectionCapture::CART_SELECTION_KEY ] ?? null;
		$hash_raw   = $cart_item[ CartDeliverySelectionCapture::CART_HASH_KEY ] ?? null;

		if ( null === $intent_raw || null === $hash_raw ) {
			return false;
		}

		$intent = CartDeliverySelectionSessionData::normalizeIntent( $intent_raw );
		$hash   = CartDeliverySelectionSessionData::normalizeHash( $hash_raw );

		if ( null === $intent || null === $hash ) {
			return true;
		}

		return ! hash_equals( CartDeliverySelectionFingerprint::fromIntent( $intent ), $hash );
	}

	private function block_reason_from_status( string $status ): string {
		return match ( $status ) {
			CartDeliverySelectionRevalidationResult::STATUS_MISSING => SelectedOfferShippingRateCalculator::BLOCK_LINE_MISSING,
			CartDeliverySelectionRevalidationResult::STATUS_UNAVAILABLE => SelectedOfferShippingRateCalculator::BLOCK_LINE_UNAVAILABLE,
			default => SelectedOfferShippingRateCalculator::BLOCK_LINE_INVALID,
		};
	}
}
