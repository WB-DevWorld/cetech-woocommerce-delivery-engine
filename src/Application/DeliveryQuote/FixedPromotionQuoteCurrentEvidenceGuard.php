<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionCurrentAuthority, FixedPromotionNativeResult};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedDeliveryPromotion;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteShape, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Header-bound current proof; legacy sources and fresh native money use distinct contexts. */
final readonly class FixedPromotionQuoteCurrentEvidenceGuard implements QuoteNativeTaxEvidenceGuard, QuoteTimedCurrentEvidenceGuard, \JsonSerializable {
	private function __construct( private QuoteHeader $header, private QuoteContext $context, private QuoteTerms $terms, private LegacyQuoteSourceSnapshot $source, private FixedPromotionNativeResult $native, private FixedPromotionCurrentAuthority $campaign ) {}

	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, LegacyQuoteSourceSnapshot $source, FixedPromotionNativeResult $native, FixedPromotionCurrentAuthority $campaign ): self {
		$header->assert_body( $context, $terms );
		$capture = $context->fixed_promotion_capture();
		if ( 3 !== $header->format_version() || 3 !== $context->format_version() || 3 !== $terms->format_version() || null === $capture || ! $context->checkout_acceptable() || ! $terms->checkout_acceptable()
			|| ! $source->plan()->owner()->equals( $header->owner() ) || $source->context()->to_private_json() !== $capture->original_context()->to_private_json()
			|| $native->original_requests()->header()->to_private_json() !== $capture->original_header()->to_private_json() || $native->native_context()->to_private_json() !== $context->base_context()->to_private_json() || ! $native->unchanged() ) { QuoteShape::invalid(); }
		// Validate the actual source policy/range slots, rather than just a supplied context digest.
		$source->bind_context( $capture->original_context() );
		$rules = [];
		foreach ( $capture->private_facts()['groups'] as $group ) { $rules[] = [ 'component_key' => $group['component_key'], 'rule' => null === $group['rule'] ? null : FixedDeliveryPromotion::from_array( $group['rule'] ) ]; }
		$actual = FixedPromotionQuoteProvider::from_native_result( $native, $rules );
		if ( $actual->context()->to_private_json() !== $context->to_private_json() || $actual->terms()->to_private_json() !== $terms->to_private_json() ) { QuoteShape::invalid(); }
		return new self( $header, $context, $terms, $source, $native, $campaign );
	}

	public function tax_source(): ?QuoteNativeTaxSource { return $this->native->native_receipt()->tax_source(); }
	public function tables( OperationSession $session ): array {
		if ( ! $this->owned( $session ) ) { throw new \RuntimeException( 'Promotion source owner unavailable.' ); }
		$tables = []; $parts = [];
		$parts[] = $this->source->guard()->tables( $session );
		if ( ! $this->owned( $session ) ) { throw new \RuntimeException( 'Promotion source owner unavailable.' ); }
		$parts[] = $this->native->native_receipt()->guard()->tables( $session );
		if ( ! $this->owned( $session ) ) { throw new \RuntimeException( 'Promotion source owner unavailable.' ); }
		$parts[] = $this->campaign->tables( $session );
		if ( ! $this->owned( $session ) ) { throw new \RuntimeException( 'Promotion source owner unavailable.' ); }
		foreach ( $parts as $part ) {
			if ( ! array_is_list( $part ) || count( $part ) > 35 ) { throw new \RuntimeException( 'Promotion source tables unavailable.' ); }
			foreach ( $part as $table ) {
				if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $session->table_prefix() ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new \RuntimeException( 'Promotion source tables unavailable.' ); }
				$tables[$table] = $table;
			}
		}
		if ( count( $tables ) > 35 ) { throw new \RuntimeException( 'Promotion source tables unavailable.' ); }
		if ( ! $this->owned( $session ) ) { throw new \RuntimeException( 'Promotion source owner unavailable.' ); }
		sort( $tables, SORT_STRING ); return array_values( $tables );
	}
	/** Ordinary proof obtains one boundary from this owner; timed callers supply theirs. */
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool {
		try { return $this->matches( $session, $owner, $context ) && $this->verify_at( $session, $owner, $context, QuoteOperationProfile::time( $session ) ); } catch ( \Throwable ) { return false; }
	}
	public function verify_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $at ): bool { return null !== $this->validity_at( $session, $owner, $context, $at ); }
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		try {
			if ( ! $this->matches( $session, $owner, $context ) || ! $this->header->valid_at( $at ) ) { return null; }
			$source_validity = FixedPromotionSourceValidity::validity_at( $this->source, $at, $this->header->expires_at() );
			if ( null === $source_validity || ! $session->validate_tables( $this->tables( $session ) ) || ! $this->matches( $session, $owner, $context ) ) { return null; }
			$capture = $context->fixed_promotion_capture();
			if ( null === $capture || ! $this->source->guard()->verify_at( $session, $owner, $capture->original_context(), $at ) || ! $this->matches( $session, $owner, $context ) ) { return null; }
			if ( ! $this->native->native_receipt()->guard()->verify( $session, $owner, $context->base_context() ) || ! $this->matches( $session, $owner, $context ) ) { return null; }
			$campaign = $this->campaign->validity_at( $session, $owner, $this->header, $this->context, $this->terms, $at );
			if ( null === $campaign || ! $this->matches( $session, $owner, $context ) ) { return null; }
			return QuoteCurrentEvidenceValidity::intersection( $at, $source_validity, $campaign );
		} catch ( \Throwable ) { return null; }
	}
	private function owned( OperationSession $session ): bool { return ! $session->is_retired() && $session->in_transaction() && $session->site_id() === $this->header->owner()->site_id() && $session->table_prefix() === $this->native->native_receipt()->source_facts()['selectors']['table_prefix']; }
	private function matches( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { return $this->owned( $session ) && $owner->equals( $this->header->owner() ) && hash_equals( $context->digest(), $this->context->digest() ) && $this->native->unchanged() && $this->source->local_state_unchanged() && $this->owned( $session ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Current promotion proof requires explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Current promotion proof cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Current promotion proof cannot be hydrated implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_current_guard' => 'redacted' ]; }
}
