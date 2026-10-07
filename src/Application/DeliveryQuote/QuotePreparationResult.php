<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;

/** Finite private outcome; a recorded ID is not current quote or placement authority. */
final readonly class QuotePreparationResult implements \JsonSerializable {
	public function __construct( public string $status, public string $reason, public ?QuotePreparationLease $lease = null, public ?QuoteId $quote_id = null ) {
		if ( ! in_array( $status, [ 'preparation_allowed', 'pending', 'completed', 'denied', 'unconfirmed' ], true ) || ! in_array( $reason, [ 'admitted', 'original_pending', 'original_completed', 'not_authorized', 'checkout_suspended', 'budget_exhausted', 'intent_conflict', 'lease_expired', 'lease_terminated', 'no_admission', 'storage_unavailable', 'commit_not_sent', 'outcome_unconfirmed' ], true ) || ( 'preparation_allowed' === $status ) !== ( null !== $lease ) || ( 'completed' === $status ) !== ( null !== $quote_id ) ) { throw new \InvalidArgumentException( 'Invalid quote preparation result.' ); }
	}
	public function completed_id(): ?QuoteId { return $this->quote_id; }
	public function safe(): array { return [ 'status' => $this->status, 'reason' => $this->reason ]; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote preparation projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit authorized quote preparation projection is required.' ); }
}
