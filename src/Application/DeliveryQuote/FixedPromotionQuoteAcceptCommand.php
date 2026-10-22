<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetAllocationSet;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteReference, QuoteShape, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Exact internal acceptance request. Its recovery contains no live capture or source capability. */
final readonly class FixedPromotionQuoteAcceptCommand implements OperationCommand, \JsonSerializable {

	public const MAX_RECOVERY_BYTES = 262144;

	private function __construct( public OperationIdentity $identity, private CanonicalIntent $original_intent, private FixedPromotionQuoteIssueCommand $original_issue ) {}

	public static function from_issue( FixedPromotionQuoteIssueCommand $issue ): self {
		$issue = $issue->as_recovery(); $header = $issue->header(); $owner = $issue->owner();
		$identity = new OperationIdentity( $owner->site_id(), 'delivery_quote_customer', $owner->digest(), 'delivery_quote.accept', 1, 'quote:' . $header->id()->value(), $header->namespace_hashes()['issue'] );
		if ( 3 !== $header->format_version() || 'fixed_promotion_native_v1' !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose() || ! hash_equals( $header->namespace_hashes()['accept'], $identity->namespace_digest() ) ) { QuoteShape::invalid(); }
		$intent = CanonicalIntent::from_command( $identity,
			[ 'owner_digest' => $owner->digest(), 'quote_id' => $header->id()->value() ],
			[ 'header_revision' => $header->revision(), 'body_digest' => $header->body_digest(), 'expires_at' => $header->expires_at()->sql() ],
			[ 'action' => 'accept', 'original_issue_intent' => $issue->intent()->fingerprint(), 'allocation_digest' => $issue->allocation_digest(), 'hold_until' => $issue->hold_until()->sql() ]
		);
		return new self( $identity, $intent, $issue );
	}

	public function as_recovery(): self { return $this; }
	public function to_recovery_array(): array {
		$data = [ 'format_version' => 1, 'issue' => $this->original_issue->to_recovery_array() ]; self::bounded( $data ); return $data;
	}
	public static function from_recovery_array( array $data ): self {
		self::bounded( $data ); QuoteShape::fields( $data, [ 'format_version', 'issue' ] );
		if ( 1 !== $data['format_version'] || ! is_array( $data['issue'] ) ) { QuoteShape::invalid(); }
		return self::from_issue( FixedPromotionQuoteIssueCommand::from_recovery_array( $data['issue'] ) );
	}
	private static function bounded( array $data ): void {
		$nodes = 0; self::scalar_graph( $data, 0, $nodes );
		try { if ( strlen( json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 28 ) ) > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } } catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	private static function scalar_graph( mixed $value, int $depth, int &$nodes ): void {
		if ( $depth > 24 || ++$nodes > 10000 ) { QuoteShape::invalid(); }
		if ( is_array( $value ) ) { foreach ( $value as $item ) { self::scalar_graph( $item, $depth + 1, $nodes ); } return; }
		if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) && ! is_bool( $value ) ) { QuoteShape::invalid(); }
	}
	public function intent(): CanonicalIntent { return $this->original_intent; }
	public function issue(): FixedPromotionQuoteIssueCommand { return $this->original_issue; }
	public function owner(): QuoteOwner { return $this->original_issue->owner(); }
	public function header(): QuoteHeader { return $this->original_issue->header(); }
	public function context(): QuoteContext { return $this->original_issue->context(); }
	public function terms(): QuoteTerms { return $this->original_issue->terms(); }
	public function reference(): QuoteReference { return $this->original_issue->reference(); }
	public function allocation_set(): FixedPromotionBudgetAllocationSet { return $this->original_issue->allocation_set(); }
	public function account_slots(): array { return $this->original_issue->account_slots(); }
	public function hold_until(): QuoteTime { return $this->original_issue->hold_until(); }
	public function allocation_digest(): string { return $this->original_issue->allocation_digest(); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion acceptance commands require explicit private recovery access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion acceptance commands cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion acceptance commands require strict recovery hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_quote_accept_command' => 'redacted' ]; }
}
