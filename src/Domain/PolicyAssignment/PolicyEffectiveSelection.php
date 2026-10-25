<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\PolicyAssignment;

use CetechDeliveryEngine\Domain\Operation\OperationJson;
use CetechDeliveryEngine\Domain\RefundPolicy\{RefundPolicyFamily, RefundPolicyVersion};
use CetechDeliveryEngine\Domain\ReturnPolicy\{ReturnPolicyFamily, ReturnPolicyVersion};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleLifecycleEvaluator, RuleRecordCodec, RuleSnapshot, RuleState, RuleTime};

/** Private captured observation, never a current grant, checkout admission or saved history. */
final readonly class PolicyEffectiveSelection implements \JsonSerializable {
	private function __construct(
		private string $code_family, private string $state, private ReturnPolicyVersion|RefundPolicyVersion|null $selected,
		private array $source, private string $material_hash, private RuleTime $at, private string $result_code
	) {}

	public static function assigned( string $family, RuleSnapshot $snapshot, string $policy_uuid, RuleTime $at, array $provenance, string $assignment_fingerprint ): self {
		self::definition( $family ); RuleRecordCodec::uuid( $policy_uuid );
		$profile = $snapshot->profile;
		if ( ! $snapshot->complete || null === $snapshot->guard || $snapshot->guard->revision < 1 || $snapshot->guard->updated_at->compare( $at ) > 0
			|| ( 'return_policy_v1' === $family && ! $profile instanceof ReturnPolicyFamily )
			|| ( 'refund_policy_v1' === $family && ! $profile instanceof RefundPolicyFamily )
			|| $profile->family() !== $family || ! hash_equals( self::definition( $family ), $profile->policy_hash() ) ) {
			self::invalid();
		}
		$decision = ( new RuleLifecycleEvaluator() )->evaluate_snapshot( $snapshot, [ 'policy_uuid' => $policy_uuid ], $at );
		if ( ! $decision->complete || 'rule_selected' !== $decision->reason || null === $decision->selected ) { self::invalid(); }
		$candidate = null;
		foreach ( $snapshot->candidates() as $possible ) {
			if ( $possible->version->id === $decision->selected->version_id && $possible->version->version_uuid === $decision->selected->version_uuid ) {
				if ( null !== $candidate ) { self::invalid(); }
				$candidate = $possible;
			}
		}
		if ( null === $candidate || $candidate->logical->logical_uuid !== $policy_uuid
			|| $candidate->logical->site_id !== $snapshot->guard->site_id || $candidate->logical->scope() !== [ 'policy_uuid' => $policy_uuid ]
			|| $candidate->logical->current_published_version_id !== $candidate->version->id
			|| RuleState::Published !== $candidate->version->state || $candidate->version->hypothetical
			|| 0 !== $candidate->version->priority || ! $candidate->version->eligible_at( $at ) ) { self::invalid(); }
		$version = 'return_policy_v1' === $family
			? ReturnPolicyVersion::from_candidate( $candidate, $profile )
			: RefundPolicyVersion::from_candidate( $candidate, $profile );
		return self::make( $family, 'assigned', $version, $at, $provenance, $assignment_fingerprint, 'policy_assigned', [
			'family_revision' => $snapshot->guard->revision, 'candidate_digest' => $snapshot->candidate_digest(),
			'reference' => $version->reference()->facts(), 'authored_content_digest' => $version->content()->digest(),
			'metadata' => $version->metadata(),
		] );
	}

	public static function disabled( string $family, RuleTime $at, array $provenance, string $assignment_fingerprint ): self {
		return self::make( $family, 'disabled', null, $at, $provenance, $assignment_fingerprint, 'policy_disabled' );
	}
	public static function not_assigned( string $family, RuleTime $at, array $provenance, string $assignment_fingerprint ): self {
		return self::make( $family, 'not_assigned', null, $at, $provenance, $assignment_fingerprint, 'policy_not_assigned' );
	}
	public static function unresolved( string $family, RuleTime $at, array $provenance, string $assignment_fingerprint, string $code = 'policy_unavailable' ): self {
		if ( ! in_array( $code, [ 'policy_unavailable', 'policy_conflict', 'policy_incomplete', 'policy_changed' ], true ) ) { self::invalid(); }
		return self::make( $family, 'unresolved', null, $at, $provenance, $assignment_fingerprint, $code );
	}
	public function family(): string { return $this->code_family; }
	public function disposition(): string { return $this->state; }
	public function version(): ReturnPolicyVersion|RefundPolicyVersion|null { return $this->selected; }
	public function provenance(): array { return $this->source; }
	public function fingerprint(): string { return $this->material_hash; }
	public function evaluated_at(): RuleTime { return $this->at; }
	public function code(): string { return $this->result_code; }
	public function jsonSerialize(): never { throw new \LogicException( 'Effective policies require an explicit authorized projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Effective policies are not a persistence contract.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Effective policies require a fresh captured source.' ); }
	public function __debugInfo(): array { return [ 'private_effective_policy_selection' => 'redacted' ]; }

	private static function make( string $family, string $disposition, ReturnPolicyVersion|RefundPolicyVersion|null $version, RuleTime $at, array $provenance, string $assignment_fingerprint, string $code, array $captured = [] ): self {
		$definition = self::definition( $family ); RuleRecordCodec::digest( $assignment_fingerprint );
		$provenance_json = OperationJson::encode( (object) [ 'provenance' => $provenance ] );
		$provenance = json_decode( $provenance_json, true, OperationJson::MAX_DEPTH + 2, JSON_THROW_ON_ERROR )['provenance'];
		$material = OperationJson::encode( (object) [
			'format' => 1, 'family' => $family, 'definition_hash' => $definition, 'disposition' => $disposition, 'code' => $code,
			'assignment_fingerprint' => $assignment_fingerprint, 'provenance' => $provenance, 'captured' => (object) $captured,
		] );
		return new self( $family, $disposition, $version, $provenance, hash( 'sha256', 'cetech-effective-policy-selection-v1:' . $material ), $at, $code );
	}
	private static function definition( string $family ): string {
		return match ( $family ) {
			'return_policy_v1' => ReturnPolicyFamily::definition_hash(), 'refund_policy_v1' => RefundPolicyFamily::definition_hash(),
			default => throw new \InvalidArgumentException( 'Unsupported policy family.' ),
		};
	}
	private static function invalid(): never { throw new \InvalidArgumentException( 'Current policy selection is unavailable.' ); }
}
