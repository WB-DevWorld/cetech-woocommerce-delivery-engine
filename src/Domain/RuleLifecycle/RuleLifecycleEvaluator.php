<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RuleLifecycle;

/** Pure, bounded evaluation. Existing rate/configuration/coverage authorities remain intact. */
final class RuleLifecycleEvaluator {
	public const MAX_CANDIDATES = 1000;

	public function evaluate( RuleFamilyProfile $profile, iterable $candidates, array $subject, RuleTime $at, int $family_revision, bool $complete = true ): RuleDecision {
		$subject = $profile->subject_schema()->validate( $subject );
		$pool = $private = [];
		try {
			RuleFamilyRegistry::declaration( $profile );
			$captured = $this->capture( $profile, $candidates );
			if ( ! $complete || $family_revision < 0 || ( 0 === $family_revision && [] !== $captured ) ) { throw new \InvalidArgumentException(); }
			foreach ( $captured as $candidate ) {
				if ( ! $candidate->version->eligible_at( $at ) ) { continue; }
				$match = $profile->match( $candidate->logical->scope, $subject );
				if ( $match->matched ) { $pool[] = [ $candidate, $match ]; $private[] = $candidate; }
			}
			if ( [] === $pool ) { return new RuleDecision( $profile, null, $subject, $at, max( 0, $family_revision ), true, $profile->unavailable_reason() ); }
			usort( $pool, fn( array $left, array $right ): int => $this->compare( $profile, $left[0], $left[1], $right[0], $right[1] ) );
			if ( count( $pool ) > 1 && 0 === $this->compare( $profile, $pool[0][0], $pool[0][1], $pool[1][0], $pool[1][1] ) ) {
				return new RuleDecision( $profile, null, $subject, $at, $family_revision, true, 'rule_conflict', $private );
			}
			return new RuleDecision( $profile, $pool[0][0], $subject, $at, $family_revision, true, 'rule_selected', $private );
		} catch ( \Throwable ) {
			return new RuleDecision( $profile, null, $subject, $at, max( 0, $family_revision ), false, 'rule_incomplete' );
		}
	}

	public function evaluate_snapshot( RuleSnapshot $snapshot, array $subject, RuleTime $at ): RuleDecision {
		return $this->evaluate( $snapshot->profile, $snapshot->candidates(), $subject, $at, $snapshot->guard?->revision ?? 0, $snapshot->complete );
	}

	public function conflicts( RuleFamilyProfile $profile, RuleCandidate $proposed, iterable $existing, ?int $allowed_predecessor = null, ?RuleTime $immediate_at = null, bool $complete = true ): RuleConflictResult {
		try {
			RuleFamilyRegistry::declaration( $profile );
			$this->assert_candidate( $profile, $proposed );
			$captured = $this->capture( $profile, $existing );
			if ( ! $complete ) { throw new \InvalidArgumentException(); }
			$window = $this->conflict_window( $proposed->version, $immediate_at );
			if ( null === $window ) { throw new \InvalidArgumentException(); }
			$allowed = $allowed_predecessor ?? $proposed->version->supersedes_version_id;
			if ( $allowed !== $proposed->version->supersedes_version_id ) { throw new \InvalidArgumentException(); }
			$predecessor_seen = null === $allowed;
			$conflicts = [];
			foreach ( $captured as $candidate ) {
				if ( $candidate->logical->site_id !== $proposed->logical->site_id || $candidate->logical->family_guard_id !== $proposed->logical->family_guard_id ) { throw new \InvalidArgumentException(); }
				if ( $candidate->version->id === $allowed ) {
					if ( $candidate->logical->id !== $proposed->logical->id || null === $candidate->version->published_at || $candidate->version->version_sequence >= $proposed->version->version_sequence ) { throw new \InvalidArgumentException(); }
					$predecessor_seen = true;
					continue;
				}
				if ( $candidate->version->id === $proposed->version->id ) {
					if ( $candidate->logical->id !== $proposed->logical->id || $candidate->version->version_uuid !== $proposed->version->version_uuid ) { throw new \InvalidArgumentException(); }
					continue;
				}
				if ( ! in_array( $candidate->version->state, [ RuleState::Published, RuleState::Scheduled ], true ) ) { continue; }
				$other_window = $this->conflict_window( $candidate->version, null );
				if ( null === $other_window ) { throw new \InvalidArgumentException(); }
				if ( ! $window->overlaps( $other_window ) ) { continue; }
				$overlap = $profile->overlaps( $proposed->logical->scope, $candidate->logical->scope );
				if ( null === $overlap ) { throw new \InvalidArgumentException(); }
				if ( ! $overlap ) { continue; }
				$left_match = $profile->match( $proposed->logical->scope, $proposed->logical->scope );
				$right_match = $profile->match( $candidate->logical->scope, $candidate->logical->scope );
				if ( ! $left_match->matched || ! $right_match->matched ) { throw new \InvalidArgumentException(); }
				if ( $candidate->logical->id === $proposed->logical->id || 0 === $this->compare( $profile, $proposed, $left_match, $candidate, $right_match ) ) { $conflicts[] = $candidate->version->id; }
			}
			if ( ! $predecessor_seen ) { throw new \InvalidArgumentException(); }
			return new RuleConflictResult( true, [] !== $conflicts, [] === $conflicts ? 'rule_clear' : 'rule_conflict', $conflicts );
		} catch ( \Throwable ) {
			return new RuleConflictResult( false, false, 'rule_incomplete' );
		}
	}

	public function conflicts_snapshot( RuleSnapshot $snapshot, RuleCandidate $proposed, ?RuleTime $immediate_at = null ): RuleConflictResult {
		return $this->conflicts( $snapshot->profile, $proposed, $snapshot->candidates(), $proposed->version->supersedes_version_id, $immediate_at, $snapshot->complete );
	}

	private function capture( RuleFamilyProfile $profile, iterable $candidates ): array {
		$out = $ids = $uuids = [];
		$site = $family_guard = null;
		foreach ( $candidates as $candidate ) {
			if ( count( $out ) >= self::MAX_CANDIDATES || ! $candidate instanceof RuleCandidate ) { throw new \InvalidArgumentException(); }
			$this->assert_candidate( $profile, $candidate );
			if ( isset( $ids[ $candidate->version->id ] ) || isset( $uuids[ $candidate->version->version_uuid ] ) || ( null !== $site && ( $site !== $candidate->logical->site_id || $family_guard !== $candidate->logical->family_guard_id ) ) ) { throw new \InvalidArgumentException(); }
			$site = $candidate->logical->site_id; $family_guard = $candidate->logical->family_guard_id;
			$ids[ $candidate->version->id ] = true; $uuids[ $candidate->version->version_uuid ] = true; $out[] = $candidate;
		}
		return $out;
	}

	private function assert_candidate( RuleFamilyProfile $profile, RuleCandidate $candidate ): void {
		if ( $candidate->logical->family_code !== $profile->family() || ! hash_equals( $candidate->logical->policy_hash, $profile->policy_hash() ) ) { throw new \InvalidArgumentException(); }
	}

	private function compare( RuleFamilyProfile $profile, RuleCandidate $left, RuleMatch $left_match, RuleCandidate $right, RuleMatch $right_match ): int {
		foreach ( RuleFamilyRegistry::precedence( $profile ) as $rank ) {
			$comparison = match ( $rank['field'] ) {
				'specificity' => $left_match->specificity <=> $right_match->specificity,
				'priority' => $left->version->priority <=> $right->version->priority,
				'logical_uuid' => strcmp( $left->logical->logical_uuid, $right->logical->logical_uuid ),
			};
			if ( 0 !== $comparison ) { return 'asc' === $rank['direction'] ? $comparison : -$comparison; }
		}
		return 0;
	}

	private function conflict_window( RuleVersion $version, ?RuleTime $immediate_at ): ?RuleInterval {
		$from = $version->effective_from ?? $immediate_at;
		if ( RuleState::Published === $version->state && null !== $version->published_at && ( null === $from || $version->published_at->compare( $from ) > 0 ) ) { $from = $version->published_at; }
		return null === $from ? null : new RuleInterval( $from, $version->effective_until );
	}
}
