<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipping;

use CetechDeliveryEngine\Integrations\EmergencyControl\EmergencyControlRuntime;

use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolverInterface;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteRequest;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Cart\{CartDeliverySelectionCapture, CartDeliverySelectionFingerprint};
use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionNativeRequests;
use CetechDeliveryEngine\Application\DeliveryQuote\{NativeCartQuotePreparation, QuoteNativeContextIdentity};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney};
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

	public const BLOCK_RUNTIME_INACTIVE = 'runtime_inactive';

	public const BLOCK_PROMOTION_UNAVAILABLE = 'promotion_unavailable';

	private ?FixedPromotionNativeRequests $promotion_requests = null;
	private ?\Closure $promotion_unchanged = null;
	private array $promotion_observed = [];
	private array $promotion_selections = [];
	private bool $promotion_failed = false;

	public function __construct(
		private ShippingRateCalculationGate $gate,
		private PackageDestinationZoneResolverInterface $destination_resolver,
		private CartLineShippingAssessorInterface $line_assessor,
		private RateQuoteEngine $quote_engine,
		private ProductDeliveryRuleRepositoryInterface $product_rule_repository,
		private Logger $logger,
		private ?ProductDeliveryConfigurationSourceInterface $configuration_source = null,
		private ?EmergencyControlRuntime $emergency_control = null
	) {
	}

	public function is_runtime_active(): bool {
		return $this->gate->is_runtime_active();
	}

	/**
	 * An explicit admitted calculation only. The caller owns native cache reset and
	 * session publication; this scope neither selects policy nor reserves funding.
	 * Every expected package must actually pass the retained list calculation.
	 */
	public function with_promotion( FixedPromotionNativeRequests $requests, callable $unchanged, callable $work ): mixed {
		if ( null !== $this->promotion_requests ) { $this->promotion_failed = true; self::promotion_fail(); }
		$current = \Closure::fromCallable( $unchanged );
		$this->promotion_requests = $requests; $this->promotion_unchanged = $current;
		$this->promotion_observed = []; $this->promotion_selections = []; $this->promotion_failed = false;
		try {
			if ( ! $this->promotion_current() ) { self::promotion_fail(); }
			$result = $work();
			if ( $this->promotion_failed || ! $this->promotion_current() || count( $this->promotion_observed ) !== count( $requests->native_requests() ) ) { self::promotion_fail(); }
			ksort( $this->promotion_selections, SORT_STRING );
			if ( ! hash_equals( $requests->original_context()->private_facts()['selection_digest'], hash( 'sha256', QuoteJson::encode( $this->promotion_selections ) ) ) ) { self::promotion_fail(); }
			return $result;
		} finally {
			$this->promotion_requests = null; $this->promotion_unchanged = null; $this->promotion_observed = []; $this->promotion_selections = []; $this->promotion_failed = false;
		}
	}

	/**
	 * @param array<string, mixed> $package WooCommerce shipping package.
	 */
	public function calculate_for_package( array $package ): SelectedOfferShippingRateResult {
		if ( null === $this->promotion_requests ) { return $this->calculate_list_for_package( $package ); }
		try {
			if ( $this->promotion_failed || ! $this->promotion_current() ) { self::promotion_fail(); }
			$base = $this->calculate_list_for_package( $package );
			if ( ! $this->promotion_current() ) { self::promotion_fail(); }
			return $this->promotion_rate( $package, $base );
		} catch ( \Throwable ) {
			$this->promotion_failed = true;
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_PROMOTION_UNAVAILABLE );
		}
	}

	/** Retained default calculation, before any explicitly scoped final amount. */
	private function calculate_list_for_package( array $package ): SelectedOfferShippingRateResult {
		$decision = $this->emergency_control?->package_decision( $package );
		if ( null !== $decision && ! $decision->allowed ) { return SelectedOfferShippingRateResult::blocked( $decision->code ); }
		if ( ! $this->is_runtime_active() ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_RUNTIME_INACTIVE );
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
		$zone_ids    = $this->destination_resolver->resolve_zone_ids( $destination );

		if ( [] === $zone_ids ) {
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
			return $this->calculate_managed_group( $contents, $meta, $zone_ids, $currency_code );
		}

		return $this->calculate_legacy_sum( $contents, $zone_ids, $currency_code );
	}

	private function promotion_rate( array $package, SelectedOfferShippingRateResult $base ): SelectedOfferShippingRateResult {
		$requests = $this->promotion_requests; $meta = DeliveryGroupIdentity::package_meta( $package );
		if ( null === $requests || ! $base->success || null === $base->total_amount || null === $base->currency || true !== ( $meta['managed'] ?? null ) || ! empty( $meta['is_pickup'] ) || ! is_string( $meta['group_id'] ?? null ) ) { self::promotion_fail(); }
		$group_id = $meta['group_id']; $component = NativeCartQuotePreparation::component_key( $group_id ); $request = null;
		foreach ( $requests->native_requests() as $candidate ) { if ( $candidate->component_key === $component && $candidate->legacy_group_id === $group_id ) { $request = $candidate; break; } }
		if ( null === $request ) { self::promotion_fail(); }
		$context = $requests->original_context()->private_facts(); $group = null; $term = null;
		foreach ( $context['groups'] as $candidate ) { if ( $candidate['component_key'] === $component ) { $group = $candidate; break; } }
		foreach ( $requests->original_terms()->private_facts()['groups'] as $candidate ) { if ( $candidate['component_key'] === $component ) { $term = $candidate; break; } }
		if ( null === $group || null === $term || 'delivery' !== $group['choice'] || $base->currency !== $context['currency']['charged'] ) { self::promotion_fail(); }
		$identity = QuoteNativeContextIdentity::from_server(); $destination = $package['destination'] ?? null;
		if ( ! is_array( $destination ) ) { self::promotion_fail(); }
		$digest = $identity->destination_digest( $requests->header()->owner()->site_id(), $destination );
		if ( ! hash_equals( $context['destination']['digest'], $digest ) || ! hash_equals( $group['endpoint_digest'], $digest ) ) { self::promotion_fail(); }
		$contents = $package['contents'] ?? null;
		if ( ! is_array( $contents ) || [] === $contents || count( $contents ) > 200 ) { self::promotion_fail(); }
		$keys = array_keys( $contents ); sort( $keys, SORT_STRING ); $expected = $group['line_keys']; sort( $expected, SORT_STRING );
		if ( $keys !== $expected ) { self::promotion_fail(); }
		$lines = []; foreach ( $context['lines'] as $line ) { if ( $line['component_key'] === $component ) { $lines[$line['line_key']] = $line; } }
		foreach ( $contents as $key => $item ) {
			$line = $lines[$key] ?? null;
			if ( ! is_array( $item ) || null === $line || ! is_int( $item['product_id'] ?? null ) || $item['product_id'] !== $line['product_id'] || ! is_int( $item['variation_id'] ?? null ) || ( $item['variation_id'] ?: null ) !== $line['variation_id'] || ! is_int( $item['quantity'] ?? null ) || (string) $item['quantity'] !== $line['quantity'] ) { self::promotion_fail(); }
			$selection = $item[CartDeliverySelectionCapture::CART_SELECTION_KEY] ?? null; $hash = $item[CartDeliverySelectionCapture::CART_HASH_KEY] ?? null;
			if ( ! is_array( $selection ) || ! is_string( $hash ) || ! hash_equals( CartDeliverySelectionFingerprint::fromIntent( $selection ), $hash ) || ( $selection['delivery_offer_id'] ?? null ) !== $group['offer_id'] || 'delivery' !== ( $selection['fulfilment_choice'] ?? null ) || DeliveryGroupIdentity::fromCartItem( $item ) !== $group_id || ( isset( $this->promotion_selections[$key] ) && $this->promotion_selections[$key] !== $hash ) ) { self::promotion_fail(); }
			$this->promotion_selections[$key] = $hash;
		}
		$list = QuoteMoney::from_array( $term['list'] );
		// Only discard zero padding. Never round a changed list into the captured one.
		$amount = str_contains( $base->total_amount, '.' ) ? rtrim( rtrim( $base->total_amount, '0' ), '.' ) : $base->total_amount;
		$observed = QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $base->currency, 'precision' => $list->precision() ] );
		if ( ! $list->equals( $observed ) || $request->expected_ex_tax->currency() !== $base->currency ) { self::promotion_fail(); }
		$this->promotion_observed[$component] = true;
		// Requests contain the absolute final, including explicit unchanged null slots.
		// Woo receives this pre-tax cost and performs its ordinary native tax/rounding.
		return SelectedOfferShippingRateResult::quoted( $request->expected_ex_tax->amount(), $base->currency, $base->charge_type );
	}

	private function promotion_current(): bool {
		try {
			return null !== $this->promotion_requests && null !== $this->promotion_unchanged && true === ( $this->promotion_unchanged )()
				&& QuoteNativeContextIdentity::from_server()->key_epoch() === $this->promotion_requests->header()->owner()->key_epoch();
		} catch ( \Throwable ) { return false; }
	}

	private static function promotion_fail(): never { throw new \RuntimeException( 'Native promotion shipping unavailable.' ); }

	/**
	 * @param array<string, mixed> $contents
	 * @param array<string, mixed> $meta
	 * @param list<int>            $zone_ids
	 */
	private function calculate_managed_group(
		array $contents,
		array $meta,
		array $zone_ids,
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

			$group_id = DeliveryGroupIdentity::fromCartItem( $cart_item );

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

		return $this->quote_selected_offer_against_matched_zones(
			$request_item,
			$representative['intent'],
			$zone_ids,
			$currency_code,
			'Selected-offer shipping quote blocked for delivery group.'
		);
	}

	/**
	 * @param array<string, mixed> $contents
	 * @param list<int>            $zone_ids
	 */
	private function calculate_legacy_sum(
		array $contents,
		array $zone_ids,
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

			$line_quote = $this->quote_selected_offer_against_matched_zones(
				$cart_item,
				$intent,
				$zone_ids,
				$currency_code,
				'Selected-offer shipping quote blocked for package line.'
			);

			if ( ! $line_quote->success || null === $line_quote->total_amount ) {
				if ( self::BLOCK_NO_QUOTABLE_LINES === $line_quote->block_reason ) {
					continue;
				}

				return $line_quote;
			}

			$total_amount = $this->add_amounts( $total_amount, $line_quote->total_amount );
			++$quoted_lines;
		}

		if ( $quoted_lines <= 0 ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_NO_QUOTABLE_LINES );
		}

		return SelectedOfferShippingRateResult::quoted( $total_amount, $currency_code );
	}

	/**
	 * Quote the same selected offer against ordered matched areas.
	 * Continues to a broader area only when the current area has no matching
	 * rate card. Any other quote error remains fail-closed.
	 *
	 * @param array<string, mixed> $cart_item
	 * @param array<string, mixed> $intent
	 * @param list<int>            $zone_ids
	 */
	private function quote_selected_offer_against_matched_zones(
		array $cart_item,
		array $intent,
		array $zone_ids,
		string $currency_code,
		string $log_message
	): SelectedOfferShippingRateResult {
		$last_error = RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD;
		$attempted  = false;

		foreach ( $zone_ids as $zone_id ) {
			$request = $this->build_quote_request( $cart_item, $intent, $zone_id, $currency_code );

			if ( null === $request ) {
				continue;
			}

			$attempted    = true;
			$quote_result = $this->quote_engine->quote( $request );

			if ( $quote_result->success && null !== $quote_result->amount ) {
				return SelectedOfferShippingRateResult::quoted(
					$quote_result->amount->amount(),
					$currency_code,
					$quote_result->charge_type
				);
			}

			$last_error = (string) ( $quote_result->error_code ?? self::BLOCK_QUOTE_FAILED );

			if ( RateQuoteEngine::ERROR_NO_MATCHING_RATE_CARD !== $last_error ) {
				$this->logger->info(
					$log_message,
					[
						'block_reason'        => self::BLOCK_QUOTE_FAILED,
						'error_code'          => $last_error,
						'destination_zone_id' => $zone_id,
						'delivery_offer_id'   => $request->delivery_offer_id,
					]
				);

				return SelectedOfferShippingRateResult::blocked( self::BLOCK_QUOTE_FAILED );
			}
		}

		if ( ! $attempted ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_NO_QUOTABLE_LINES );
		}

		$this->logger->info(
			$log_message,
			[
				'block_reason' => self::BLOCK_QUOTE_FAILED,
				'error_code'   => $last_error,
			]
		);

		return SelectedOfferShippingRateResult::blocked( self::BLOCK_QUOTE_FAILED );
	}

	/**
	 * Quote one selected offer for a WooCommerce destination using cart/checkout semantics.
	 *
	 * @param array<string, mixed> $cart_item
	 * @param array<string, mixed> $intent
	 * @param array<string, mixed> $destination
	 */
	public function quote_for_selection(
		array $cart_item,
		array $intent,
		array $destination,
		string $currency_code
	): SelectedOfferShippingRateResult {
		$decision = $this->emergency_control?->line_decision( 'quote', $cart_item );
		if ( null !== $decision && ! $decision->allowed ) { return SelectedOfferShippingRateResult::blocked( $decision->code ); }
		if ( ! $this->is_runtime_active() ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_RUNTIME_INACTIVE );
		}

		$zone_ids = $this->destination_resolver->resolve_zone_ids( $destination );

		if ( [] === $zone_ids ) {
			return SelectedOfferShippingRateResult::blocked( self::BLOCK_DESTINATION_UNRESOLVED );
		}

		return $this->quote_selected_offer_against_matched_zones(
			$cart_item,
			$intent,
			$zone_ids,
			$currency_code,
			'Selected-offer shipping quote blocked for PDP selection.'
		);
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
