<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Support\Logger;

/**
 * Calculates a WooCommerce package shipping rate from captured delivery selections.
 *
 * Managed packages quote once per delivery group (respecting rate-card charge types).
 * Does not write cart, session, order, or shipment data.
 */
final class SelectedOfferShippingRateCalculator {

	public const BLOCK_DESTINATION_UNRESOLVED = 'destination_unresolved';

	public const BLOCK_LINE_INVALID = 'line_invalid';

	public const BLOCK_LINE_MISSING = 'line_missing';

	public const BLOCK_LINE_UNAVAILABLE = 'line_unavailable';

	public const BLOCK_NO_QUOTABLE_LINES = 'no_quotable_lines';

	public const BLOCK_QUOTE_FAILED = 'quote_failed';

	public const BLOCK_GROUP_MISMATCH = 'group_mismatch';

	public function __construct(
		private ShippingRateCalculationGate $gate,
		private PackageDestinationZoneResolverInterface $destination_resolver,
		private CartLineShippingAssessorInterface $line_assessor,
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

		$meta = DeliveryGroupIdentity::package_meta( $package );

		if ( is_array( $meta ) && ! empty( $meta['managed'] ) && ! empty( $meta['is_pickup'] ) ) {
			$currency_code = $this->currency_code();

			if ( null === $currency_code ) {
				return SelectedOfferShippingRateResult::blocked( self::BLOCK_QUOTE_FAILED );
			}

			$contents = is_array( $package['contents'] ?? null ) ? $package['contents'] : [];

			foreach ( $contents as $cart_item_key => $cart_item ) {
				if ( ! is_array( $cart_item ) ) {
					continue;
				}

				$line_outcome = $this->line_assessor->assess_line( (string) $cart_item_key, $cart_item );

				if ( 'skip' === $line_outcome['action'] ) {
					continue;
				}

				if ( 'block' === $line_outcome['action'] ) {
					return SelectedOfferShippingRateResult::blocked( (string) $line_outcome['reason'] );
				}
			}

			// Pickup is not a delivery charge; explicit zero keeps WC package valid.
			return SelectedOfferShippingRateResult::quoted( '0.0000', $currency_code );
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

		$currency_code = $this->currency_code();

		if ( null === $currency_code ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_QUOTE_FAILED );
		}

		if ( is_array( $meta ) && ! empty( $meta['managed'] ) ) {
			return $this->calculate_managed_group( $contents, $meta, $zone_id, $currency_code );
		}

		return $this->calculate_legacy_sum( $contents, $zone_id, $currency_code );
	}

	/**
	 * @param array<string, mixed> $contents
	 * @param array<string, mixed> $meta
	 */
	private function calculate_managed_group(
		array $contents,
		array $meta,
		int $zone_id,
		string $currency_code
	): SelectedOfferShippingRateResult {
		$expected_group = isset( $meta['group_id'] ) ? (string) $meta['group_id'] : '';
		$quotable       = [];
		$total_quantity = 0;

		foreach ( $contents as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$line_outcome = $this->line_assessor->assess_line( (string) $cart_item_key, $cart_item );

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

			$group_id = DeliveryGroupIdentity::fromIntent( $intent );

			if ( null === $group_id || ( '' !== $expected_group && ! hash_equals( $expected_group, $group_id ) ) ) {
				return SelectedOfferShippingRateResult::blocked( self::BLOCK_GROUP_MISMATCH );
			}

			if ( FulfilmentChoice::StorePickup->value === sanitize_key( (string) ( $intent['fulfilment_choice'] ?? '' ) ) ) {
				continue;
			}

			$quantity = (int) ( $cart_item['quantity'] ?? 0 );

			if ( $quantity <= 0 ) {
				return SelectedOfferShippingRateResult::blocked( self::BLOCK_LINE_INVALID );
			}

			$quotable[]      = [
				'cart_item' => $cart_item,
				'intent'    => $intent,
			];
			$total_quantity += $quantity;
		}

		if ( [] === $quotable || $total_quantity <= 0 ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_NO_QUOTABLE_LINES );
		}

		$representative = $quotable[0];
		$request_item   = $representative['cart_item'];
		$request_item['quantity'] = $total_quantity;

		$request = $this->build_quote_request(
			$request_item,
			$representative['intent'],
			$zone_id,
			$currency_code
		);

		if ( null === $request ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_NO_QUOTABLE_LINES );
		}

		$quote_result = $this->quote_engine->quote( $request );

		if ( ! $quote_result->success || null === $quote_result->amount ) {
			$this->logger->info(
				'Selected-offer shipping quote blocked for delivery group.',
				[
					'block_reason' => self::BLOCK_QUOTE_FAILED,
					'error_code'   => $quote_result->error_code,
				]
			);

			return SelectedOfferShippingRateResult::blocked( self::BLOCK_QUOTE_FAILED );
		}

		return SelectedOfferShippingRateResult::quoted( $quote_result->amount->amount(), $currency_code );
	}

	/**
	 * @param array<string, mixed> $contents
	 */
	private function calculate_legacy_sum(
		array $contents,
		int $zone_id,
		string $currency_code
	): SelectedOfferShippingRateResult {
		$total_amount = '0.0000';
		$quoted_lines = 0;

		foreach ( $contents as $cart_item_key => $cart_item ) {
			if ( ! is_array( $cart_item ) ) {
				continue;
			}

			$line_outcome = $this->line_assessor->assess_line( (string) $cart_item_key, $cart_item );

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

	private function currency_code(): ?string {
		$currency_code = function_exists( 'get_woocommerce_currency' )
			? strtoupper( (string) get_woocommerce_currency() )
			: '';

		return '' !== $currency_code ? $currency_code : null;
	}

	private function add_amounts( string $left, string $right ): string {
		if ( function_exists( 'bcadd' ) ) {
			return bcadd( $left, $right, 4 );
		}

		return number_format( (float) $left + (float) $right, 4, '.', '' );
	}
}
