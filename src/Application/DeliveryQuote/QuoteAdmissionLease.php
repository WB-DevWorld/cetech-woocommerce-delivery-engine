<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** A confirmed grant plus its original live, single-use capture capability. */
final readonly class QuoteAdmissionLease implements \JsonSerializable {
	private function __construct( private QuoteBudgetSlot $slot, private string $owner, private QuoteAdmissionAttempt $attempt ) {}
	public static function confirmed( QuoteBudgetSlot $slot, QuoteIssueCommand $command, QuoteAdmissionAttempt $attempt ): self {
		$row = $slot->row();
		if ( 'admission' !== $slot->kind() || 'granted' !== $row['lease_state'] || $slot->site_id() !== $command->owner()->site_id() || ! $attempt->matches( $command )
			|| $row['admission_namespace_hash'] !== $command->identity()->namespace_digest() || $row['admission_intent_digest'] !== $command->intent_digest()
			|| $row['server_attempt_digest'] !== $attempt->digest() || $row['principal_hash'] !== $command->owner()->facts()['principal_hash'] ) { throw new \InvalidArgumentException( 'Quote admission facts are inconsistent.' ); }
		return new self( $slot, $command->owner()->digest(), $attempt );
	}
	public function slot(): QuoteBudgetSlot { return $this->slot; }
	public function namespace_hash(): string { return $this->slot->row()['admission_namespace_hash']; }
	public function intent_digest(): string { return $this->slot->row()['admission_intent_digest']; }
	public function server_attempt_digest(): string { return $this->slot->row()['server_attempt_digest']; }
	public function owner_digest(): string { return $this->owner; }
	public function created_at(): QuoteTime { return QuoteTime::parse( $this->slot->row()['created_at'] ); }
	public function expires_at(): QuoteTime { return QuoteTime::parse( $this->slot->row()['lease_expires_at'] ); }
	public function claim_capture(): bool { return $this->attempt->claim_capture(); }
	public function capture_started(): bool { return $this->attempt->capture_started(); }
	public function matches( QuoteIssueCommand $command ): bool { return $this->attempt->matches( $command ) && $this->owner === $command->owner()->digest(); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote admission projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'A capture capability cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'A capture capability cannot be reconstructed.' ); }
}
