<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Server-only live capability. A reconstructed request never inherits capture permission. */
final class QuoteAdmissionAttempt implements \JsonSerializable {
	private string $phase = 'new';
	private ?string $namespace = null;
	private ?string $intent = null;
	private ?string $owner = null;
	private function __construct( private readonly string $digest ) {}
	public static function generate(): self { return new self( hash( 'sha256', 'cetech-quote-attempt-v1:' . random_bytes( 32 ) ) ); }
	public function digest(): string { return $this->digest; }
	public function begin( QuoteIssueCommand $command ): bool {
		if ( 'new' !== $this->phase ) { return false; }
		$this->namespace = $command->identity()->namespace_digest(); $this->intent = $command->intent_digest(); $this->owner = $command->owner()->digest(); $this->phase = 'acquiring'; return true;
	}
	public function matches( QuoteIssueCommand $command ): bool { return $this->namespace === $command->identity()->namespace_digest() && $this->intent === $command->intent_digest() && $this->owner === $command->owner()->digest(); }
	public function uncertain(): void { if ( 'acquiring' === $this->phase ) { $this->phase = 'uncertain'; } }
	public function may_reconcile_capture( QuoteIssueCommand $command ): bool { return 'uncertain' === $this->phase && $this->matches( $command ); }
	public function confirm(): bool { if ( ! in_array( $this->phase, [ 'acquiring', 'uncertain' ], true ) ) { return false; } $this->phase = 'confirmed'; return true; }
	public function deny(): void { if ( 'capturing' !== $this->phase ) { $this->phase = 'closed'; } }
	public function claim_capture(): bool { if ( 'confirmed' !== $this->phase ) { return false; } $this->phase = 'capturing'; return true; }
	public function capture_started(): bool { return 'capturing' === $this->phase; }
	public function jsonSerialize(): never { throw new \LogicException( 'Quote admission capabilities have no generic projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'A live quote admission capability cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'A live quote admission capability cannot be reconstructed.' ); }
	public function __clone(): void { throw new \LogicException( 'A live quote admission capability cannot be copied.' ); }
}
