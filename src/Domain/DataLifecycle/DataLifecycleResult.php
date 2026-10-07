<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

use CetechDeliveryEngine\Domain\Contracts\ContractError;

/** SQL progress is distinct from advisory cache/scheduler publication. */
final readonly class DataLifecycleResult implements \JsonSerializable {
	public function __construct( public string $status, public ?DataLifecycleProgress $progress = null, public ?DataLifecycleContinuation $continuation = null, public ?ContractError $error = null, public bool $publication_pending = false, public int $eligible_candidates = 0, public ?string $reason = null ) {
		if ( ! in_array( $status, [ 'accepted', 'refused', 'outcome_unknown', 'preview' ], true ) || $eligible_candidates < 0 || $eligible_candidates > 200 || ( in_array( $status, [ 'accepted', 'preview' ], true ) && null === $progress ) || ( 'accepted' === $status && null !== $error ) || ( 'outcome_unknown' === $status && null !== $progress ) ) { throw new \InvalidArgumentException( 'Lifecycle result is invalid.' ); }
		if ( null !== $reason && ! in_array( $reason, [ 'no_checkpoint', 'checkpoint_conflict', 'storage_refused', 'invalid_input', 'policy_changed', 'not_authorized' ], true ) ) { throw new \InvalidArgumentException( 'Lifecycle reason is invalid.' ); }
	}
	public function safe(): array { return [ 'status' => $this->status, 'reason' => $this->reason, 'progress' => $this->progress?->safe(), 'publication_pending' => $this->publication_pending, 'eligible_candidates' => $this->eligible_candidates, 'error' => $this->error?->to_array() ]; }
	public function with_publication_pending(): self { return new self( $this->status, $this->progress, $this->continuation, $this->error, true, $this->eligible_candidates, $this->reason ); }
	public function jsonSerialize(): never { throw new \LogicException( 'A lifecycle result requires an explicit projection.' ); }
}
