<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionNativeResult;
use CetechDeliveryEngine\Domain\DeliveryCommercial\FixedPromotionQuoteCapture;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedDeliveryPromotion;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteMoney, QuoteShape, QuoteTerms};

/** Compiled captured profile only. Neither replay nor registration grants campaign/payment authority. */
final readonly class FixedPromotionQuoteProvider implements QuoteProviderInterface, \JsonSerializable {
	public const CODE = 'fixed_promotion_native_v1';
	public const VERSION = 1;
	public const PROMOTION_PROVIDER = 'fixed_delivery_promotion_v1';
	private function __construct( private FixedPromotionNativeResult $native, private QuoteContext $context, private QuoteTerms $terms ) {}

	/** Every component supplies its exact frozen rule or explicit null; rules are never discovered. */
	public static function from_native_result( FixedPromotionNativeResult $native, array $rules ): self {
		if ( ! $native->unchanged() ) { throw new \RuntimeException( 'Fixed promotion capture unavailable.' ); }
		$requests = $native->original_requests(); $original = $requests->original_terms();
		$plans = []; foreach ( $requests->plans() as $slot ) { $plans[$slot['component_key']] = $slot['plan']; }
		$rules_by_key = [];
		foreach ( QuoteShape::list( $rules, 200, 1 ) as $slot ) {
			$slot = QuoteShape::object( $slot ); QuoteShape::fields( $slot, [ 'component_key', 'rule' ] );
			$key = QuoteShape::digest( $slot['component_key'] );
			if ( array_key_exists( $key, $rules_by_key ) || ! array_key_exists( $key, $plans ) || ( null !== $slot['rule'] && ! $slot['rule'] instanceof FixedDeliveryPromotion )
				|| ( null === $slot['rule'] ) !== ( null === $plans[$key] ) ) { QuoteShape::invalid(); }
			$rules_by_key[$key] = $slot['rule'];
		}
		if ( count( $rules_by_key ) !== count( $plans ) ) { QuoteShape::invalid(); }
		$proof_groups = [];
		foreach ( $plans as $key => $plan ) { $proof_groups[] = [ 'component_key' => $key, 'rule' => $rules_by_key[$key], 'plan' => $plan ]; }
		$proof = FixedPromotionQuoteCapture::from_captured( $requests->header(), $requests->original_context(), $original, $proof_groups );
		$context = QuoteContext::from_base_fixed_promotion( $native->native_context(), $proof );
		$groups = [];
		foreach ( $original->private_facts()['groups'] as $group ) {
			$key = $group['component_key']; $fresh = $native->native_receipt()->group( $key );
			$final = $fresh->final_money(); $precision = $final->precision();
			$list = self::pad( QuoteMoney::from_array( $group['list'] ), $precision );
			$plan = $plans[$key];
			$discount = null === $plan ? QuoteMoney::from_array( [ 'amount' => '0', 'currency' => $final->currency(), 'precision' => $precision ] ) : self::pad( $rules_by_key[$key]->discount(), $precision );
			$groups[] = [ 'component_key' => $key, 'customer_label' => $fresh->customer_label(), 'provider' => [ 'code' => self::CODE, 'version' => self::VERSION ], 'policy_digest' => $group['policy_digest'],
				'list' => $list->facts(), 'final' => $final->facts(), 'tax' => $fresh->tax_money()->facts(), 'total' => $fresh->total_money()->facts(),
				'promotion' => [ 'state' => null === $plan ? 'none' : 'applied', 'amount' => $discount->facts(), 'provider' => [ 'code' => null === $plan ? LegacyFixedBaseQuoteProvider::PROMOTION_PROVIDER : self::PROMOTION_PROVIDER, 'version' => 1 ] ],
				'cost' => [ 'state' => 'unavailable', 'reason' => 'cost_provider_unavailable' ], 'route' => [ 'state' => 'not_recorded' ],
				'native_tax_receipt' => $fresh->native_tax_receipt(), 'native_money_receipt' => $fresh->native_money_receipt() ];
		}
		$terms = QuoteTerms::from_base_fixed_promotion( QuoteTerms::from_array( [ 'format_version' => 1, 'groups' => $groups ] ), $proof );
		if ( ! $native->unchanged() ) { throw new \RuntimeException( 'Fixed promotion capture unavailable.' ); }
		return new self( $native, $context, $terms );
	}

	public function code(): string { return self::CODE; }
	public function version(): int { return self::VERSION; }
	public function profile(): string { return self::CODE; }
	public function profile_version(): int { return self::VERSION; }
	public function evidence_providers(): array { return [ self::PROMOTION_PROVIDER => [ 1 ], LegacyFixedBaseQuoteProvider::PROMOTION_PROVIDER => [ 1 ] ]; }
	public function context(): QuoteContext { return $this->context; }
	public function terms(): QuoteTerms { return $this->terms; }
	/** Exact frozen replay requires the captured native source to remain unchanged. */
	public function capture( QuoteContext $context ): QuoteTerms {
		if ( ! $this->native->unchanged() || ! hash_equals( $this->context->digest(), $context->digest() ) ) { throw new \RuntimeException( 'Fixed promotion capture unavailable.' ); }
		return QuoteTerms::from_json( $this->terms->to_private_json() );
	}
	private static function pad( QuoteMoney $money, int $precision ): QuoteMoney {
		if ( $money->precision() > $precision ) { QuoteShape::invalid(); }
		return QuoteMoney::from_array( [ 'amount' => $money->amount(), 'currency' => $money->currency(), 'precision' => $precision ] );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Fixed promotion profiles require explicit authorized projection.' ); }
	public function __serialize(): array { throw new \LogicException( 'Fixed promotion profiles cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Fixed promotion profiles cannot be reconstructed implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_fixed_promotion_profile' => 'redacted' ]; }
}
