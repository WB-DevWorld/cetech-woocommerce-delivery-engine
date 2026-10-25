<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\PolicyAssignment;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyAssignmentMap;
use CetechDeliveryEngine\Domain\PolicyAssignment\PolicyEffectiveSelection;
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyFamily, RefundPolicyVersion};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyFamily, ReturnPolicyVersion};
use CetechDeliveryEngine\Domain\RuleLifecycle\{LogicalRule, RuleCandidate, RuleFamilyGuard, RuleFamilyProfile, RuleFamilyRegistry, RuleLifecycleEvaluator, RuleRecordCodec, RuleSnapshot, RuleTime};
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbRuleLifecycleRepository;

/** Selected policies only. Captured profiles grant access; their hashes never do. */
final class PolicyCurrentCatalog {

	private RuleFamilyRegistry $families;
	private ?OperationSession $owner = null;
	private ?array $binding = null;
	private ?OperationSession $review_owner = null;
	private ?string $review_hash = null;
	private ?RuleTime $review_time = null;
	private array $review_snapshots = [];

	public function __construct( ReturnPolicyFamily $return_family, RefundPolicyFamily $refund_family, private readonly OperationIdentity $identity ) {
		$this->families = new RuleFamilyRegistry( [ $return_family, $refund_family ] );
	}

	/** The dedicated assignment guard precedes this fixed source-family order. */
	public function lock_families( OperationSession $session ): void {
		try {
			$this->clear_review();
			if ( null !== $this->owner && $this->owner !== $session && ( ! $this->owner->is_retired() || $this->owner->in_transaction() ) ) { self::refuse(); }
			if ( $session->site_id() !== $this->identity->site_id || $session->is_retired() || ! $session->in_transaction() ) { self::refuse(); }
			$this->owner = $session;
			$this->binding = [ $session->site_id(), $session->table_prefix() ];
			$repository = new WpdbRuleLifecycleRepository( $session );
			foreach ( [ RefundPolicyFamily::CODE, ReturnPolicyFamily::CODE ] as $family ) {
				$profile = $this->families->get( $family );
				$row = $repository->lock_family( $family, $profile->policy_hash(), false );
				if ( null !== $row && RuleFamilyGuard::from_row( $row, $profile )->site_id !== $this->identity->site_id ) { self::refuse(); }
			}
			$this->assert_owner( $session );
		} catch ( \InvalidArgumentException|\UnexpectedValueException ) { self::refuse(); }
		catch ( \Throwable ) { throw new OperationStorageException(); }
	}

	/** Complete bounded history of one selected logical object, not a family scan. */
	public function snapshot( OperationSession $session, string $family, string $logical_uuid ): RuleSnapshot {
		try {
			$this->assert_owner( $session );
			$profile = $this->families->get( $family );
			$uuid = RuleRecordCodec::uuid( $logical_uuid );
			$scope = [ 'policy_uuid' => $uuid ];
			$this->assert_grant( $profile, $scope );
			$repository = new WpdbRuleLifecycleRepository( $session );
			// Refresh the held generation too: late checks never reuse an old row image.
			$guard_row = $repository->lock_family( $family, $profile->policy_hash(), false );
			if ( null === $guard_row ) {
				$result = new RuleSnapshot( $profile, null, [], [], true, true );
			} else {
				$guard = RuleFamilyGuard::from_row( $guard_row, $profile );
				if ( $guard->site_id !== $this->identity->site_id ) { self::refuse(); }
				$logical_row = $repository->lock_logical( $uuid );
				if ( null === $logical_row ) {
					$result = new RuleSnapshot( $profile, $guard, [], [], true, true );
				} else {
					$logical = LogicalRule::from_row( $logical_row, $guard, $profile );
					if ( $logical->site_id !== $this->identity->site_id || $logical->logical_uuid !== $uuid || $logical->scope()['policy_uuid'] !== $uuid ) { self::refuse(); }
					$rows = $repository->lock_versions_for_logical( $logical->id, 1001 );
					if ( count( $rows ) >= 1001 ) { self::refuse(); }
					$result = RuleSnapshot::from_history_rows( $profile, $guard_row, [ $logical_row ], $rows );
					foreach ( $result->versions as $version ) {
						if ( 0 !== $version->priority ) { self::refuse(); }
						$candidate = new RuleCandidate( $logical, $version );
						if ( ReturnPolicyFamily::CODE === $family ) { ReturnPolicyVersion::from_candidate( $candidate, $profile ); }
						else { RefundPolicyVersion::from_candidate( $candidate, $profile ); }
					}
				}
			}
			$this->assert_grant( $profile, $scope );
			$this->assert_owner( $session );
			return $result;
		} catch ( \InvalidArgumentException|\UnexpectedValueException ) { self::refuse(); }
		catch ( \Throwable ) { throw new OperationStorageException(); }
	}

	/** Replays do not call this: only a fresh effect validates its reviewed heads. */
	public function assert_reviewed( OperationSession $session, array $reviewed, RuleTime $at ): void {
		try {
			$this->clear_review();
			$snapshots = [];
			$this->assert_owner( $session );
			if ( count( $reviewed ) !== 2 || [] !== array_diff( PolicyAssignmentMap::FAMILIES, array_keys( $reviewed ) ) ) { self::refuse(); }
			foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
				$witness = $reviewed[$family];
				if ( null === $witness ) { continue; }
				$keys = [ 'policy_uuid', 'version_uuid', 'logical_revision', 'version_revision', 'content_hash', 'guard_revision' ];
				if ( ! is_array( $witness ) || count( $witness ) !== count( $keys ) || [] !== array_diff( $keys, array_keys( $witness ) ) ) { self::refuse(); }
				foreach ( [ 'logical_revision', 'version_revision', 'guard_revision' ] as $key ) { if ( ! is_int( $witness[$key] ) || $witness[$key] < 1 ) { self::refuse(); } }
				if ( ! is_string( $witness['policy_uuid'] ) || ! is_string( $witness['version_uuid'] ) || ! is_string( $witness['content_hash'] ) ) { self::refuse(); }
				RuleRecordCodec::uuid( $witness['version_uuid'] ); RuleRecordCodec::digest( $witness['content_hash'] );
				$snapshot = $this->snapshot( $session, $family, $witness['policy_uuid'] );
				$snapshots[$family] = $snapshot;
				$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, [ 'policy_uuid' => $witness['policy_uuid'] ], $at );
				$selected = $decision->selected;
				if ( ! $decision->complete || null === $selected || null === $snapshot->guard || $selected->hypothetical || $snapshot->guard->updated_at->compare( $at ) > 0 ) { self::refuse(); }
				$expected = [ 'policy_uuid' => $selected->logical_uuid, 'version_uuid' => $selected->version_uuid, 'logical_revision' => $selected->logical_revision, 'version_revision' => $selected->version_revision, 'content_hash' => $selected->content_hash, 'guard_revision' => $snapshot->guard->revision ];
				foreach ( $expected as $key => $value ) { if ( $witness[$key] !== $value ) { self::refuse(); } }
			}
			$this->assert_owner( $session );
			$this->review_hash = self::reviewed_hash( $reviewed );
			$this->review_owner = $session;
			$this->review_time = $at;
			$this->review_snapshots = $snapshots;
		} catch ( \InvalidArgumentException|\UnexpectedValueException ) { $this->clear_review(); self::refuse(); }
		catch ( \Throwable ) { $this->clear_review(); throw new OperationStorageException(); }
	}

	/** Pure last-clock check of the exact source snapshots still held by the caller. */
	public function assert_reviewed_at( OperationSession $session, array $reviewed, RuleTime $final_at ): void {
		try {
			if ( $this->owner !== $session || $this->review_owner !== $session || null === $this->review_hash || null === $this->review_time
				|| ! hash_equals( $this->review_hash, self::reviewed_hash( $reviewed ) ) || $final_at->compare( $this->review_time ) < 0 ) { self::refuse(); }
			$this->assert_owner( $session );
			foreach ( $this->review_snapshots as $family => $snapshot ) {
				$witness = $reviewed[$family];
				$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, [ 'policy_uuid' => $witness['policy_uuid'] ], $final_at );
				if ( ! $decision->complete || null === $decision->selected || null === $snapshot->guard || $snapshot->guard->updated_at->compare( $final_at ) > 0 || $decision->selected->version_uuid !== $witness['version_uuid']
					|| $decision->selected->content_hash !== $witness['content_hash'] ) { self::refuse(); }
			}
			$this->assert_owner( $session );
		} catch ( \InvalidArgumentException|\UnexpectedValueException ) { $this->clear_review(); self::refuse(); }
		catch ( \Throwable ) { $this->clear_review(); throw new OperationStorageException(); }
	}

	private function clear_review(): void { $this->review_owner = null; $this->review_hash = null; $this->review_time = null; $this->review_snapshots = []; }

	private static function reviewed_hash( array $reviewed ): string {
		if ( count( $reviewed ) !== 2 || [] !== array_diff( PolicyAssignmentMap::FAMILIES, array_keys( $reviewed ) ) ) { self::refuse(); }
		$canonical = [];
		foreach ( PolicyAssignmentMap::FAMILIES as $family ) {
			if ( null === $reviewed[$family] ) { $canonical[$family] = null; continue; }
			$witness = $reviewed[$family];
			$keys = [ 'policy_uuid', 'version_uuid', 'logical_revision', 'version_revision', 'content_hash', 'guard_revision' ];
			if ( ! is_array( $witness ) || count( $witness ) !== count( $keys ) || [] !== array_diff( $keys, array_keys( $witness ) ) ) { self::refuse(); }
			$canonical[$family] = [];
			foreach ( $keys as $key ) {
				$value = $witness[$key];
				if ( in_array( $key, [ 'logical_revision', 'version_revision', 'guard_revision' ], true ) ? ! is_int( $value ) || $value < 1 : ! is_string( $value ) ) { self::refuse(); }
				$canonical[$family][$key] = $value;
			}
		}
		return hash( 'sha256', 'cetech-policy-reviewed-heads-v1:' . json_encode( $canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	public function selection( OperationSession $session, string $family, array $instruction, array $provenance, string $assignment_fingerprint, RuleTime $at ): PolicyEffectiveSelection {
		$this->assert_owner( $session );
		$this->families->get( $family );
		try {
			$checked = PolicyAssignmentMap::empty( $this->identity->site_id, $family )->with_instruction( 0, $instruction )->instruction( 1 )['instruction'];
			if ( 'inherit' === $checked['mode'] ) { return PolicyEffectiveSelection::not_assigned( $family, $at, $provenance, $assignment_fingerprint ); }
			if ( 'disabled' === $checked['mode'] ) { return PolicyEffectiveSelection::disabled( $family, $at, $provenance, $assignment_fingerprint ); }
			$snapshot = $this->snapshot( $session, $family, $checked['policy_uuid'] );
			return PolicyEffectiveSelection::assigned( $family, $snapshot, $checked['policy_uuid'], $at, $provenance, $assignment_fingerprint );
		} catch ( \InvalidArgumentException|\UnexpectedValueException ) {
			return PolicyEffectiveSelection::unresolved( $family, $at, $provenance, $assignment_fingerprint, 'policy_unavailable' );
		}
	}

	private function assert_grant( RuleFamilyProfile $profile, array $scope ): void {
		if ( $this->families->get( $profile->family() ) !== $profile || true !== $profile->authorize( $this->identity, $scope ) ) { self::refuse(); }
	}

	private function assert_owner( OperationSession $session ): void {
		try {
			if ( $this->owner !== $session || null === $this->binding || $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $this->identity->site_id || [ $session->site_id(), $session->table_prefix() ] !== $this->binding ) { throw new OperationStorageException(); }
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}

	private static function refuse(): never { throw new \UnexpectedValueException( 'Policy publication is unavailable.' ); }
}
