<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;

/** Detached finite shopper DTO; private session envelopes are never serialized here. */
final readonly class CartQuoteResult implements \JsonSerializable {
	public const STATUSES = [ 'no_quote', 'review_required', 'confirmed', 'expired', 'changed', 'unconfirmed', 'unavailable' ];
	private function __construct( private array $facts ) {}
	public static function create( string $status, int $generation, RequestContext $request, ?QuoteProjectionResult $quote = null, bool $can_refresh = false, bool $can_confirm = false, bool $can_retry = false ): self {
		QuoteShape::choice( $status, self::STATUSES ); QuoteShape::integer( $generation, 0, 9007199254740991 );
		if ( null !== $quote && 'shopper' !== $quote->purpose() ) { QuoteShape::invalid(); }
		if ( $can_confirm && ( 'review_required' !== $status || null === $quote || ! $quote->fields()['currently_applicable'] ) ) { QuoteShape::invalid(); }
		if ( $can_retry && 'unconfirmed' !== $status ) { QuoteShape::invalid(); }
		if ( 'unconfirmed' === $status && $can_refresh ) { QuoteShape::invalid(); }
		if ( in_array( $status, [ 'no_quote', 'unconfirmed', 'unavailable' ], true ) && null !== $quote ) { QuoteShape::invalid(); }
		return new self( [ 'contract_version' => 1, 'status' => $status, 'generation' => $generation, 'quote' => $quote?->fields(), 'can_refresh' => $can_refresh, 'can_confirm' => $can_confirm, 'can_retry' => $can_retry, 'message_code' => $status, 'correlation_id' => $request->correlation_id ] );
	}
	public function shopper_facts(): array { return $this->facts; }
	public function jsonSerialize(): array { return $this->shopper_facts(); }
}
