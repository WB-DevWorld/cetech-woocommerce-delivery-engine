<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** A live one-use preparation owner. A reconstructed envelope never receives this capability. */
final class QuotePreparationAttempt implements \JsonSerializable {
	private string $phase = 'new';
	private ?QuotePreparationCommand $original = null;
	private ?string $final_intent = null;
	private function __construct( private readonly string $digest ) {}
	public static function generate(): self { return new self( hash( 'sha256', 'cetech-quote-preparation-attempt-v1:' . random_bytes( 32 ) ) ); }
	public function digest(): string { return $this->digest; }
	public function begin( QuotePreparationCommand $command ): bool { if ( 'new' !== $this->phase ) { return false; } $this->original = $command; $this->phase = 'acquiring'; return true; }
	public function matches( QuotePreparationCommand $command ): bool { return null !== $this->original && $this->original->owner()->equals( $command->owner() ) && hash_equals( $this->original->identity()->namespace_digest(), $command->identity()->namespace_digest() ) && hash_equals( $this->original->intent_digest(), $command->intent_digest() ); }
	public function uncertain(): void { if ( 'acquiring' === $this->phase ) { $this->phase = 'uncertain'; } }
	public function may_reconcile_preparation( QuotePreparationCommand $command ): bool { return 'uncertain' === $this->phase && $this->matches( $command ); }
	public function confirm(): bool { if ( ! in_array( $this->phase, [ 'acquiring', 'uncertain' ], true ) ) { return false; } $this->phase = 'confirmed'; return true; }
	public function deny(): void { if ( ! in_array( $this->phase, [ 'preparing', 'bound' ], true ) ) { $this->phase = 'closed'; } }
	public function claim_preparation(): bool { if ( 'confirmed' !== $this->phase ) { return false; } $this->phase = 'preparing'; return true; }
	public function preparation_started(): bool { return in_array( $this->phase, [ 'preparing', 'bound' ], true ); }
	/** Closing a known pre-handoff failure also withholds capability on uncertain termination. */
	public function begin_termination(): bool { if ( ! in_array( $this->phase, [ 'confirmed', 'preparing' ], true ) ) { return false; } $this->phase = 'closed'; return true; }
	/** The final material fingerprint is bound once locally, never written over the early intent. */
	public function bind_issue( QuoteIssueCommand $command ): QuoteAdmissionAttempt {
		if ( 'preparing' !== $this->phase || null === $this->original || ! $this->original->matches_issue( $command ) ) { throw new \InvalidArgumentException( 'Quote preparation handoff is unavailable.' ); }
		$this->final_intent = $command->intent_digest(); $this->phase = 'binding'; return QuoteAdmissionAttempt::from_preparation( $this, $command );
	}
	/** Consumed by the downstream factory; it cannot mint a second downstream capability. */
	public function consume_issue_binding( QuoteIssueCommand $command ): array {
		if ( 'binding' !== $this->phase || null === $this->original || ! $this->original->matches_issue( $command ) || $this->final_intent !== $command->intent_digest() ) { throw new \InvalidArgumentException( 'Quote preparation handoff is unavailable.' ); }
		$this->phase = 'bound'; return [ $this->digest, $this->original->intent_digest() ];
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Quote preparation capabilities have no generic projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'A live quote preparation capability cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'A live quote preparation capability cannot be reconstructed.' ); }
	public function __clone(): void { throw new \LogicException( 'A live quote preparation capability cannot be copied.' ); }
}
