<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\Contracts\{CanonicalIntent, OperationIdentity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetAllocationSet;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteContext, QuoteHeader, QuoteJson, QuoteOwner, QuoteReference, QuoteShape, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationCommand;

/** Private exact issued-3 command. Recovery contains facts, never a live admission capability. */
final readonly class FixedPromotionQuoteIssueCommand implements OperationCommand, \JsonSerializable {

	public const MAX_RECOVERY_BYTES = 196608;
	private function __construct( public OperationIdentity $identity, private QuoteIssueCommand $original, private QuoteHeader $opened, private QuoteTerms $captured_terms, private QuoteReference $original_reference, private FixedPromotionBudgetAllocationSet $allocations, private QuoteTime $deadline, private ?QuoteAdmissionLease $capture_lease ) {}

	public static function captured( QuoteIssueCommand $original, QuoteAdmissionLease $lease, DeliveryQuote $captured, QuoteReference $reference ): self {
		if ( ! $lease->capture_started() || ! $lease->matches( $original ) || 'issued' !== $captured->state() || 1 !== $captured->revision() || null === $captured->context() || null === $captured->terms() || $captured->context()->to_private_json() !== $original->context()->to_private_json() ) { QuoteShape::invalid(); }
		return self::make( $original, $captured->header(), $captured->terms(), $reference, $lease );
	}

	private static function make( QuoteIssueCommand $original, QuoteHeader $header, QuoteTerms $terms, QuoteReference $reference, ?QuoteAdmissionLease $lease ): self {
		$slots = $original->promotion_account_slots(); $until = $original->promotion_hold_until();
		if ( 'delivery_quote.issue' !== $original->identity()->operation || 2 !== $original->identity()->operation_version || null === $slots || null === $until || ! $header->owner()->equals( $original->owner() ) || ! $header->matches_reference( $reference ) || $header->namespace_hashes() != $original->namespace_hashes( $header->id() ) || $header->material_digest() !== $original->context()->digest() || $header->profile() !== $original->profile() || $header->profile_version() !== $original->profile_version() || $until->compare( $header->created_at() ) <= 0 || $until->compare( $header->expires_at() ) > 0 ) { QuoteShape::invalid(); }
		$set = FixedPromotionBudgetAllocationSet::from_captured( $header, $original->context(), $terms, $slots );
		return new self( $original->identity(), $original, $header, $terms, $reference, $set, $until, $lease );
	}

	public function as_recovery(): self { return self::make( $this->original, $this->opened, $this->captured_terms, $this->original_reference, null ); }
	/** Explicit private recovery export; the caller must durably acknowledge it before mutation. */
	public function to_recovery_array(): array {
		$data = [ 'format_version' => 1, 'original_issue' => $this->original->to_promotion_recovery_array(), 'header_json' => $this->opened->to_private_json(), 'terms_json' => $this->captured_terms->to_private_json(), 'reference' => $this->original_reference->public_fields() ];
		self::bounded( $data ); return $data;
	}
	public static function from_recovery_array( array $data ): self {
		self::bounded( $data ); QuoteShape::fields( $data, [ 'format_version', 'original_issue', 'header_json', 'terms_json', 'reference' ] );
		if ( 1 !== $data['format_version'] || ! is_array( $data['original_issue'] ) || ! is_string( $data['header_json'] ) || ! is_string( $data['terms_json'] ) || ! is_array( $data['reference'] ) ) { QuoteShape::invalid(); }
		return self::make( QuoteIssueCommand::from_promotion_recovery_array( $data['original_issue'] ), QuoteHeader::from_json( $data['header_json'] ), QuoteTerms::from_json( $data['terms_json'] ), QuoteReference::from_array( $data['reference'] ), null );
	}
	private static function bounded( array $data ): void {
		$nodes = 0; self::scalar_graph( $data, 0, $nodes );
		try { if ( strlen( json_encode( $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, 24 ) ) > self::MAX_RECOVERY_BYTES ) { QuoteShape::invalid(); } } catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	private static function scalar_graph( mixed $value, int $depth, int &$nodes ): void {
		if ( $depth > 20 || ++$nodes > 8192 ) { QuoteShape::invalid(); }
		if ( is_array( $value ) ) { foreach ( $value as $item ) { self::scalar_graph( $item, $depth + 1, $nodes ); } return; }
		if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) && ! is_bool( $value ) ) { QuoteShape::invalid(); }
	}
	public function intent(): CanonicalIntent { return $this->original->intent(); }
	public function owner(): QuoteOwner { return $this->original->owner(); }
	public function original_issue(): QuoteIssueCommand { return $this->original; }
	public function header(): QuoteHeader { return $this->opened; }
	public function context(): QuoteContext { return $this->original->context(); }
	public function terms(): QuoteTerms { return $this->captured_terms; }
	public function reference(): QuoteReference { return $this->original_reference; }
	public function lease(): ?QuoteAdmissionLease { return $this->capture_lease; }
	public function allocation_set(): FixedPromotionBudgetAllocationSet { return $this->allocations; }
	public function account_slots(): array { return $this->allocations->account_slots(); }
	public function hold_until(): QuoteTime { return $this->deadline; }
	public function allocation_digest(): string {
		$claims = []; foreach ( $this->allocations->requests() as $request ) { $claims[] = $request->claim_key(); }
		return hash( 'sha256', 'cetech-fixed-promotion-quote-issue-allocation-v1:' . QuoteJson::encode( [ 'header_digest' => hash( 'sha256', $this->opened->to_private_json() ), 'account_slots' => $this->account_slots(), 'claim_keys' => $claims, 'hold_until' => $this->deadline->sql() ] ) );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion issue commands require explicit private recovery access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion issue commands cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion issue commands require strict recovery hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_quote_issue_command' => 'redacted' ]; }
}
