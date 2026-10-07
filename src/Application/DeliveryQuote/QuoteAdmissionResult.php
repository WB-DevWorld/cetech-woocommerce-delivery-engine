<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;

/** Finite gate outcome; completion still requires the quote's original C03 record. */
final readonly class QuoteAdmissionResult implements \JsonSerializable {
	public function __construct( public string $status, public string $reason, public ?QuoteAdmissionLease $lease = null, public ?QuoteId $quote_id = null ) {
		if ( ! in_array( $status, [ 'capture_allowed', 'pending', 'completed', 'denied', 'unconfirmed' ], true )
			|| ! in_array( $reason, [ 'admitted', 'original_pending', 'original_completed', 'not_authorized', 'checkout_suspended', 'budget_exhausted', 'intent_conflict', 'lease_expired', 'lease_terminated', 'no_admission', 'storage_unavailable', 'commit_not_sent', 'outcome_unconfirmed' ], true )
			|| ( 'capture_allowed' === $status ) !== ( null !== $lease ) || ( 'completed' === $status ) !== ( null !== $quote_id ) ) { throw new \InvalidArgumentException( 'Invalid quote admission result.' ); }
	}
	public function safe(): array { return [ 'status' => $this->status, 'reason' => $this->reason ]; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote admission projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'An explicit authorized quote admission projection is required.' ); }
}
