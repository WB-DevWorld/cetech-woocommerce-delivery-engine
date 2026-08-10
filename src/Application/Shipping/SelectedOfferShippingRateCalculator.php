<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionFingerprint;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidationResult;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionSessionData;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;

/**
 * Calculates a WooCommerce package shipping rate from captured delivery selections.
 *
 * Does not write cart, session, order, or shipment data.
 */
final class SelectedOfferShippingRateCalculator {

	public const BLOCK_DESTINATION_UNRESOLVED = 'destination_unresolved';

	public const BLOCK_LINE_INVALID = 'line_invalid';

	public const BLOCK_LINE_MISSING = 'line_missing';

	public const BLOCK_LINE_UNAVAILABLE = 'line_unavailable';

	public const BLOCK_NO_QUOTABLE_LINES = 'no_quotable_lines';

	public const BLOCK_QUOTE_FAILED = 'quote_failed';

	public function __construct(
		private ShippingRateCalculationGate $gate,
		private PackageDestinationZoneResolver $destination_resolver,
		private CartDeliverySelectionCapture $cart_capture,
		private CartDeliverySelectionRevalidator $cart_revalidator,
		private RateQuoteEngine $quote_engine,
		private ProductDeliveryRuleRepositoryInterface $product_rule_repository,
		private Logger $logger,
		private ?ProductDeliveryConfigurationSourceInterface $configuration_source = null
	) {
	}

	public function is_runtime_active(): bool {
		return $this->gate->is_runtime_active();
	}

	/**
	 * @param array<string, mixed> $package WooCommerce shipping package.
	 */
	public function calculate_for_package( array $package ): SelectedOfferShippingRateResult {
		if ( ! $this->is_runtime_active() ) {
			return SelectedOfferShippingRateResult::blocked( 'runtime_inactive' );
		}

		$destination = is_array( $package['destination'] ?? null ) ? $package['destination'] : [];
		$zone_id     = $this->destination_resolver->resolve_zone_id( $destination );

		if ( null === $zone_id ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_DESTINATION_UNRESOLVED );
		}

		$contents = is_array( $package['contents'] ?? null ) ? $package['contents'] : [];

		if ( [] === $contents ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_NO_QUOTABLE_LINES );
		}

		$currency_code = function_exists( 'get_woocommerce_currency' )
			? strtoupper( (string) get_woocommerce_currency() )
			: '';

		if ( '' === $currency_code ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_QUOTE_FAILED );
		}

		$total_amount = '0.0000';
		$quoted_lines = 0;

		foreach ( $contents as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$line_outcome = $this->assess_line( (string) $cart_item_key, $cart_item );

			if ( 'skip' === $line_outcome['action'] ) {
				continue;
			}

			if ( 'block' === $line_outcome['action'] ) {
				return SelectedOfferShippingRateResult::blocked( (string) $line_outcome['reason'] );
			}

			$intent = $line_outcome['intent'] ?? null;

			if ( ! is_array( $intent ) ) {
				return SelectedOfferShippingRateResult::blocked( self::BLOCK_LINE_INVALID );
			}

			$request = $this->build_quote_request( $cart_item, $intent, $zone_id, $currency_code );

			if ( null === $request ) {
				continue;
			}

			$quote_result = $this->quote_engine->quote( $request );

			if ( ! $quote_result->success || null === $quote_result->amount ) {
				$this->logger->info(
					'Selected-offer shipping quote blocked for package line.',
					[
						'block_reason' => self::BLOCK_QUOTE_FAILED,
						'error_code'   => $quote_result->error_code,
					]
				);

				return SelectedOfferShippingRateResult::blocked( self::BLOCK_QUOTE_FAILED );
			}

			$total_amount = $this->add_amounts( $total_amount, $quote_result->amount->amount() );
			++$quoted_lines;
		}

		if ( $quoted_lines <= 0 ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_NO_QUOTABLE_LINES );
		}

		return SelectedOfferShippingRateResult::quoted( $total_amount, $currency_code );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 *
	 * @return array{action: string, reason?: string, intent?: array<string, mixed>}
	 */
	private function assess_line( string $cart_item_key, array $cart_item ): array {
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
					'reason' => self::BLOCK_LINE_INVALID,
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
					'reason' => self::BLOCK_LINE_INVALID,
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
				'reason' => self::BLOCK_LINE_UNAVAILABLE,
			];
		}

		return [
			'action' => 'block',
			'reason' => self::BLOCK_LINE_MISSING,
		];
	}

	/**
	 * @param array<string, mixed> $cart_item
	 * @param array<string, mixed> $intent
	 */
	public function build_quote_request(
		array $cart_item,
		array $intent,
		int $destination_zone_id,
		string $currency_code
	): ?RateQuoteRequest {
		$delivery_offer_id = isset( $intent['delivery_offer_id'] ) ? (int) $intent['delivery_offer_id'] : 0;

		if ( $delivery_offer_id <= 0 ) {
			return null;
		}

		$quantity = (int) ( $cart_item['quantity'] ?? 0 );

		if ( $quantity <= 0 ) {
			return null;
		}

		$rule_dimensions = $this->rule_dimensions( $intent );

		try {
			return RateQuoteRequest::fromArray(
				[
					'delivery_offer_id'       => $delivery_offer_id,
					'destination_zone_id'     => $destination_zone_id,
					'quantity'                => $quantity,
					'currency_code'           => $currency_code,
					'product_id'              => (int) ( $cart_item['product_id'] ?? $intent['product_id'] ?? 0 ),
					'variation_id'            => (int) ( $cart_item['variation_id'] ?? 0 ) > 0
						? (int) $cart_item['variation_id']
						: ( $intent['variation_id'] ?? null ),
					'rule_id'                 => $intent['rule_id'] ?? null,
					'logistics_profile_id'    => $rule_dimensions['logistics_profile_id'],
					'supplier_id'             => $rule_dimensions['supplier_id'],
					'origin_id'               => $rule_dimensions['origin_id'],
					'fulfilment_availability' => $intent['fulfilment_availability'] ?? null,
					'fulfilment_choice'       => $intent['fulfilment_choice'] ?? null,
				]
			);
		} catch ( \InvalidArgumentException ) {
			return null;
		}
	}

	/**
	 * @param array<string, mixed> $intent
	 *
	 * @return array{
	 *     logistics_profile_id: int|null,
	 *     supplier_id: int|null,
	 *     origin_id: int|null
	 * }
	 */
	private function rule_dimensions( array $intent ): array {
		$empty = [
			'logistics_profile_id' => null,
			'supplier_id'          => null,
			'origin_id'            => null,
		];

		$availability = sanitize_key( (string) ( $intent['fulfilment_availability'] ?? '' ) );
		$target_type  = sanitize_key( (string) ( $intent['target_type'] ?? '' ) );
		$target_id    = (int) ( $intent['target_id'] ?? 0 );

		if ( null !== $this->configuration_source && '' !== $target_type && $target_id > 0 ) {
			$runtime = $this->configuration_source->resolve( $target_type, $target_id );

			if ( RuntimeConfigurationSource::ECR === $runtime->source && '' !== $availability ) {
				return $runtime->quote_dimensions_for( $availability );
			}
		}

		$rule_id = isset( $intent['rule_id'] ) ? (int) $intent['rule_id'] : 0;

		if ( $rule_id <= 0 ) {
			return $empty;
		}

		$rule = $this->product_rule_repository->findById( $rule_id );

		if ( null === $rule ) {
			return $empty;
		}

		return [
			'logistics_profile_id' => $this->positive_int_or_null( $rule['logistics_profile_id'] ?? null ),
			'supplier_id'          => $this->positive_int_or_null( $rule['supplier_id'] ?? null ),
			'origin_id'            => $this->positive_int_or_null( $rule['origin_id'] ?? null ),
		];
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
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
			CartDeliverySelectionRevalidationResult::STATUS_MISSING => self::BLOCK_LINE_MISSING,
			CartDeliverySelectionRevalidationResult::STATUS_UNAVAILABLE => self::BLOCK_LINE_UNAVAILABLE,
			default => self::BLOCK_LINE_INVALID,
		};
	}

	private function add_amounts( string $left, string $right ): string {
		if ( function_exists( 'bcadd' ) ) {
			return bcadd( $left, $right, 4 );
		}

		return number_format( (float) $left + (float) $right, 4, '.', '' );
	}
}
