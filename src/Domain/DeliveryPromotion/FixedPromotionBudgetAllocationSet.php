<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteJson, QuoteOwner, QuoteShape, QuoteTerms};

/** Complete compiled-quote allocation identity; construction is not funding or write authority. */
final readonly class FixedPromotionBudgetAllocationSet implements \JsonSerializable {

	private function __construct( private QuoteHeader $header, private QuoteContext $context, private QuoteTerms $terms, private array $slots, private array $requests ) {}

	/** Every captured component chooses an explicit account, or null only when unadjusted. */
	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $account_slots ): self {
		$header->assert_body( $context, $terms ); $account_slots = QuoteJson::detach( [ 'slots' => $account_slots ] )['slots'];
		if ( 3 !== $header->format_version() || 3 !== $context->format_version() || 3 !== $terms->format_version() || 'fixed_promotion_native_v1' !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose() ) { QuoteShape::invalid(); }
		$capture = $context->fixed_promotion_capture(); if ( null === $capture ) { QuoteShape::invalid(); }
		$groups = [];
		foreach ( $capture->private_facts()['groups'] as $group ) { $groups[$group['component_key']] = null !== $group['plan']; }
		$slots = []; $requests = [];
		foreach ( QuoteShape::list( $account_slots, QuoteContext::MAX_GROUPS, 1 ) as $slot ) {
			$slot = QuoteShape::object( $slot ); QuoteShape::fields( $slot, [ 'component_key', 'account_key' ] );
			$key = QuoteShape::digest( $slot['component_key'] );
			if ( isset( $slots[$key] ) || ! array_key_exists( $key, $groups ) || $groups[$key] !== ( null !== $slot['account_key'] ) ) { QuoteShape::invalid(); }
			$account = null === $slot['account_key'] ? null : QuoteShape::digest( $slot['account_key'] );
			$slots[$key] = [ 'component_key' => $key, 'account_key' => $account ];
			if ( null !== $account ) { $requests[] = FixedPromotionBudgetRequest::from_captured( $header, $context, $terms, $key, $account ); }
		}
		if ( count( $slots ) !== count( $groups ) || [] === $requests ) { QuoteShape::invalid(); }
		ksort( $slots, SORT_STRING );
		usort( $requests, static fn( FixedPromotionBudgetRequest $a, FixedPromotionBudgetRequest $b ): int => strcmp( $a->claim_key(), $b->claim_key() ) );
		return new self( $header, $context, $terms, array_values( $slots ), $requests );
	}

	public function header(): QuoteHeader { return $this->header; }
	public function context(): QuoteContext { return $this->context; }
	public function terms(): QuoteTerms { return $this->terms; }
	public function owner(): QuoteOwner { return $this->header->owner(); }
	/** @return list<FixedPromotionBudgetRequest> */
	public function requests(): array { return $this->requests; }
	public function account_slots(): array { return $this->slots; }
	public function account_keys(): array { $keys = []; foreach ( $this->requests as $request ) { $keys[$request->account_key()] = true; } $keys = array_keys( $keys ); sort( $keys, SORT_STRING ); return $keys; }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion allocations require explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Promotion allocations cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion allocations require captured construction.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_budget_allocation_set' => 'redacted' ]; }
}
