<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;

/** Typed result with separate safe and currently authorized private projections. */
final readonly class RuleDecision implements \JsonSerializable {
	public ?RuleVersionReference $selected;
	public ?int $family_revision;
	public string $family;
	public bool $complete;
	public string $reason;
	public RuleTime $at;
	private array $subject;
	private array $private_candidates;
	private string $declaration;

	public function __construct( RuleFamilyProfile $profile, ?RuleCandidate $selected, array $subject, RuleTime $at, int $family_revision, bool $complete, string $reason, array $private_candidates = [] ) {
		$subject = $profile->subject_schema()->validate( $subject );
		if ( $family_revision < 0 || count( $private_candidates ) > 1000 || ! array_is_list( $private_candidates ) || ! in_array( $reason, [ 'rule_selected', 'rule_conflict', 'rule_incomplete', $profile->unavailable_reason() ], true ) || ( null !== $selected && ( ! $complete || 'rule_selected' !== $reason || $family_revision < 1 ) ) || ( null === $selected && 'rule_selected' === $reason ) ) {
			throw new \InvalidArgumentException( 'Invalid rule decision facts.' );
		}
		foreach ( $private_candidates as $candidate ) {
			if ( ! $candidate instanceof RuleCandidate || $candidate->logical->family_code !== $profile->family() ) { throw new \InvalidArgumentException( 'Invalid private rule decision evidence.' ); }
		}
		if ( null !== $selected ) {
			if ( $selected->logical->family_code !== $profile->family() || ! hash_equals( $selected->logical->policy_hash, $profile->policy_hash() ) || ! $selected->version->eligible_at( $at ) || ! $profile->match( $selected->logical->scope, $subject )->matched ) { throw new \InvalidArgumentException( 'Invalid selected rule evidence.' ); }
			$has_selected = false;
			foreach ( $private_candidates as $candidate ) { if ( $candidate === $selected ) { $has_selected = true; } }
			if ( ! $has_selected ) { $private_candidates[] = $selected; }
			if ( count( $private_candidates ) > 1000 ) { throw new \InvalidArgumentException( 'Rule decision evidence exceeds its budget.' ); }
		}
		$this->declaration = RuleFamilyRegistry::declaration( $profile );
		$this->subject = $subject;
		$this->selected = null === $selected ? null : new RuleVersionReference( $selected );
		$this->family_revision = 0 === $family_revision ? null : $family_revision;
		$this->family = $profile->family();
		$this->complete = $complete;
		$this->reason = $reason;
		$this->at = $at;
		$this->private_candidates = $private_candidates;
	}

	public function safe( RequestContext $context ): array {
		return [ 'format' => 1, 'status' => null === $this->selected ? 'unavailable' : 'matched', 'reason' => $this->reason, 'complete' => $this->complete, 'correlation_id' => $context->correlation_id ];
	}

	public function admin( RuleFamilyProfile $profile, OperationIdentity $identity ): array {
		try {
			if ( ! hash_equals( $this->declaration, RuleFamilyRegistry::declaration( $profile ) ) || ! $profile->authorize( $identity, $this->subject ) ) { throw new \InvalidArgumentException(); }
			$references = [];
			foreach ( $this->private_candidates as $candidate ) {
				if ( $identity->site_id !== $candidate->logical->site_id || ! $profile->authorize( $identity, $candidate->logical->scope ) ) { throw new \InvalidArgumentException(); }
				$references[] = ( new RuleVersionReference( $candidate ) )->facts();
			}
			if ( null !== $this->selected && $this->selected->site_id !== $identity->site_id ) { throw new \InvalidArgumentException(); }
			// Check again after preparing private facts; authority can be revoked mid-read.
			if ( ! $profile->authorize( $identity, $this->subject ) ) { throw new \InvalidArgumentException(); }
			foreach ( $this->private_candidates as $candidate ) { if ( ! $profile->authorize( $identity, $candidate->logical->scope ) ) { throw new \InvalidArgumentException(); } }
			return [ 'format' => 1, 'family' => $this->family, 'family_revision' => $this->family_revision, 'evaluated_at' => $this->at->sql(), 'subject' => $this->subject, 'complete' => $this->complete, 'reason' => $this->reason, 'selected' => $this->selected?->facts(), 'considered' => $references ];
		} catch ( \Throwable ) {
			throw new \InvalidArgumentException( 'Rule decision disclosure is not authorized.' );
		}
	}

	public function jsonSerialize(): mixed { throw new \InvalidArgumentException( 'Rule decision requires an explicit projection.' ); }
}
