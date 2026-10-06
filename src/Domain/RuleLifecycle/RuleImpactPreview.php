<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Read-only hypothetical comparisons; no scheduled date claims acceptance. */
final readonly class RuleImpactPreview implements \JsonSerializable {
	public array $comparisons;
	public function __construct( public RuleSnapshot $snapshot, public RuleTime $at, array $comparisons, public bool $hypothetical_due, public ?RuleCandidate $proposed = null ) {
		if ( ! array_is_list( $comparisons ) || count( $comparisons ) > 100 || ( null !== $proposed && ( $proposed->logical->family_code !== $snapshot->profile->family() || $proposed->logical->site_id !== $snapshot->guard?->site_id ) ) ) {
			throw new \InvalidArgumentException( 'Invalid rule impact preview boundary.' );
		}
		$out = [];
		foreach ( $comparisons as $comparison ) {
			if ( ! is_array( $comparison ) || count( $comparison ) !== 3 || ! isset( $comparison['subject'], $comparison['baseline'], $comparison['proposed'] ) || ! is_array( $comparison['subject'] ) || ! $comparison['baseline'] instanceof RuleDecision || ! $comparison['proposed'] instanceof RuleDecision ) { throw new \InvalidArgumentException( 'Invalid rule preview comparison.' ); }
			foreach ( [ $comparison['baseline'], $comparison['proposed'] ] as $decision ) {
				if ( ! $decision->at->equals( $at ) || $decision->family !== $snapshot->profile->family() || $decision->family_revision !== $snapshot->guard?->revision || ( ! $snapshot->complete && ( $decision->complete || null !== $decision->selected ) ) ) { throw new \InvalidArgumentException( 'Rule preview mixes captured generations.' ); }
			}
			$out[] = [ 'subject' => $snapshot->profile->subject_schema()->validate( $comparison['subject'] ), 'baseline' => $comparison['baseline'], 'proposed' => $comparison['proposed'] ];
		}
		$this->comparisons = $out;
	}

	public function safe( RequestContext $context ): array {
		return [ 'format' => 1, 'status' => 'hypothetical_preview', 'hypothetical_due' => $this->hypothetical_due, 'complete' => $this->snapshot->complete && $this->complete(), 'comparisons' => array_map( static fn( array $row ): array => [ 'baseline' => $row['baseline']->safe( $context ), 'proposed' => $row['proposed']->safe( $context ) ], $this->comparisons ), 'correlation_id' => $context->correlation_id ];
	}

	public function admin( RuleFamilyProfile $profile, OperationIdentity $identity ): array {
		try {
			if ( ( [] === $this->comparisons && null === $this->proposed ) || ! hash_equals( RuleFamilyRegistry::declaration( $profile ), RuleFamilyRegistry::declaration( $this->snapshot->profile ) ) || ( null !== $this->snapshot->guard && $identity->site_id !== $this->snapshot->guard->site_id ) ) { throw new \InvalidArgumentException(); }
			$proposal = null;
			if ( null !== $this->proposed ) {
				if ( ! $profile->authorize( $identity, $this->proposed->logical->scope ) ) { throw new \InvalidArgumentException(); }
				$opened_logical = null; $opened_version = null;
				foreach ( $this->snapshot->logicals as $logical ) { if ( $logical->id === $this->proposed->logical->id ) { $opened_logical = $logical; } }
				foreach ( $this->snapshot->versions as $version ) { if ( $version->id === $this->proposed->version->id ) { $opened_version = $version; } }
				$proposal = [ 'reference' => ( new RuleVersionReference( $this->proposed ) )->facts(), 'opened_logical_revision' => $opened_logical?->revision, 'opened_version_revision' => $opened_version?->row_revision, 'opened_version_state' => $opened_version?->state->value, 'start_mode' => $this->proposed->version->start_mode->value, 'authored_from' => $this->proposed->version->effective_from?->sql(), 'authored_until' => $this->proposed->version->effective_until?->sql(), 'hypothetical_published_at' => $this->proposed->version->published_at?->sql(), 'supersedes_version_id' => $this->proposed->version->supersedes_version_id ];
			}
			$out = [];
			foreach ( $this->comparisons as $row ) {
				if ( ! $profile->authorize( $identity, $row['subject'] ) ) { throw new \InvalidArgumentException(); }
				$out[] = [ 'subject' => $row['subject'], 'baseline' => $row['baseline']->admin( $profile, $identity ), 'proposed' => $row['proposed']->admin( $profile, $identity ) ];
			}
			foreach ( $this->comparisons as $row ) { if ( ! $profile->authorize( $identity, $row['subject'] ) ) { throw new \InvalidArgumentException(); } }
			if ( null !== $this->proposed && ! $profile->authorize( $identity, $this->proposed->logical->scope ) ) { throw new \InvalidArgumentException(); }
			return [ 'format' => 1, 'status' => 'hypothetical_preview', 'family_revision' => $this->snapshot->guard?->revision, 'candidate_digest' => $this->snapshot->candidate_digest(), 'evaluated_at' => $this->at->sql(), 'hypothetical_due' => $this->hypothetical_due, 'proposed' => $proposal, 'complete' => $this->snapshot->complete && $this->complete(), 'comparisons' => $out ];
		} catch ( \Throwable ) { throw new \InvalidArgumentException( 'Rule preview disclosure is not authorized.' ); }
	}

	private function complete(): bool {
		foreach ( $this->comparisons as $row ) { if ( ! $row['baseline']->complete || ! $row['proposed']->complete ) { return false; } }
		return true;
	}
	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule preview requires an explicit projection.' ); }
}
