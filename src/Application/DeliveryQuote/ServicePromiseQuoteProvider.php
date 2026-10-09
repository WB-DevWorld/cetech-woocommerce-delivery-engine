<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteOwner,QuoteShape,QuoteTerms};

/** Prepared promise facts wrap the retained native money provider; capture never recalculates. */
final readonly class ServicePromiseQuoteProvider implements QuoteProviderInterface, \JsonSerializable {
	public const PROFILE = 'service_promise_v1';
	private QuoteContext $context;
	private QuoteTerms $terms;
	public function __construct( QuoteOwner $owner, QuoteContext $context, QuoteTerms $terms ) {
		if ( 2 !== $context->format_version() || 2 !== $terms->format_version() || ! $context->checkout_acceptable() || ! $terms->checkout_acceptable() ) { QuoteShape::invalid(); }
		// The original provider identity and every monetary/tax/promotion receipt stay unchanged.
		new LegacyFixedBaseQuoteProvider( $owner, $context->base_context(), $terms->base_terms() );
		$this->context = QuoteContext::from_json( $context->to_private_json() );
		$this->terms = QuoteTerms::from_json( $terms->to_private_json() );
	}
	public function code(): string { return LegacyFixedBaseQuoteProvider::CODE; }
	public function version(): int { return 1; }
	public function profile(): string { return self::PROFILE; }
	public function profile_version(): int { return 1; }
	public function evidence_providers(): array { return [ LegacyFixedBaseQuoteProvider::PROMOTION_PROVIDER => [ 1 ] ]; }
	public function capture( QuoteContext $context ): QuoteTerms {
		if ( ! hash_equals( $context->digest(), $this->context->digest() ) ) { QuoteShape::invalid(); }
		return QuoteTerms::from_json( $this->terms->to_private_json() );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promise provider facts require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promise providers cannot be serialized generically.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promise providers cannot be hydrated generically.' ); }
}
