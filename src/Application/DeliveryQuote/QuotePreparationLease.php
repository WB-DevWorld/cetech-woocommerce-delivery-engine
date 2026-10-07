<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** A confirmed original slot and its live preparation capability. */
final readonly class QuotePreparationLease implements \JsonSerializable {
	private function __construct( private QuoteBudgetSlot $slot, private QuotePreparationCommand $command, private QuotePreparationAttempt $attempt ) {}
	public static function confirmed( QuoteBudgetSlot $slot, QuotePreparationCommand $command, QuotePreparationAttempt $attempt ): self {
		$row = $slot->row();
		if ( 'admission' !== $slot->kind() || 'granted' !== $row['lease_state'] || $slot->site_id() !== $command->owner()->site_id() || ! $attempt->matches( $command ) || $row['admission_namespace_hash'] !== $command->identity()->namespace_digest() || $row['admission_intent_digest'] !== $command->intent_digest() || $row['server_attempt_digest'] !== $attempt->digest() || $row['principal_hash'] !== $command->owner()->facts()['principal_hash'] ) { throw new \InvalidArgumentException( 'Quote preparation facts are inconsistent.' ); }
		return new self( $slot, $command, $attempt );
	}
	public function slot(): QuoteBudgetSlot { return $this->slot; }
	public function namespace_hash(): string { return $this->command->identity()->namespace_digest(); }
	public function intent_digest(): string { return $this->command->intent_digest(); }
	public function server_attempt_digest(): string { return $this->attempt->digest(); }
	public function owner_digest(): string { return $this->command->owner()->digest(); }
	public function created_at(): QuoteTime { return QuoteTime::parse( $this->slot->row()['created_at'] ); }
	public function expires_at(): QuoteTime { return QuoteTime::parse( $this->slot->row()['lease_expires_at'] ); }
	public function claim_preparation(): bool { return $this->attempt->claim_preparation(); }
	public function preparation_started(): bool { return $this->attempt->preparation_started(); }
	public function claim_termination( QuotePreparationCommand $command ): bool { return $this->attempt->matches( $command ) && $this->attempt->begin_termination(); }
	public function bind_issue( QuoteIssueCommand $command ): QuoteAdmissionLease {
		if ( ! $this->command->matches_issue( $command ) ) { throw new \InvalidArgumentException( 'Quote preparation handoff is inconsistent.' ); }
		return QuoteAdmissionLease::from_preparation( $this->slot, $command, $this->attempt->bind_issue( $command ), $this->command );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote preparation projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'A preparation capability cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'A preparation capability cannot be reconstructed.' ); }
}
