<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Operation;

use CetechDeliveryEngine\Domain\Contracts\OperationOutcome;

/** One attempt's safe outcome, separate from the immutable durable completion. */
final readonly class OperationAttemptResult {
	public function __construct(
		public OperationOutcome $outcome,
		public ?OperationCompletion $completion = null,
		public bool $replayed = false
	) {
		if ( 'accepted' === $outcome->state && ( null === $completion || 'accepted' !== $completion->state )
			|| 'not_applicable' === $outcome->state && ( null === $completion || 'not_applicable' !== $completion->state )
			|| 'rejected' === $outcome->state && null !== $completion && 'rejected' !== $completion->state
			|| 'pending' === $outcome->state && null !== $completion
			|| 'unconfirmed' === $outcome->state && null !== $completion && ( true !== $outcome->mutation_accepted || 'accepted' !== $completion->state || ! $outcome->publication_pending ) ) {
			throw new \InvalidArgumentException( 'Attempt outcome contradicts its completion facts.' );
		}
	}
}
