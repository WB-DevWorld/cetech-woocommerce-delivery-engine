<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RefundPolicy;

use CetechDeliveryEngine\Domain\RuleLifecycle\RuleCandidate;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleContent;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyProfile;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleFamilyRegistry;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersion;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleVersionReference;

/** Detached authored content and C01 metadata, never current publication or admission authority. */
final readonly class RefundPolicyVersion implements \JsonSerializable {

	private function __construct( private RefundPolicyContent $content, private RuleVersionReference $reference, private array $metadata ) {}

	/** The original trusted declaration validates detached bytes; it supplies no current authorization. */
	public static function from_candidate( RuleCandidate $candidate, RuleFamilyProfile $original_profile ): self {
		$declaration = RuleFamilyRegistry::declaration( $original_profile );
		$logical = $candidate->logical;
		if ( RefundPolicyContent::FAMILY !== $logical->family_code || RefundPolicyContent::FORMAT !== $logical->scope_format || RefundPolicyContent::FORMAT !== $candidate->version->payload_format || RefundPolicyContent::FAMILY !== $original_profile->family() || RefundPolicyContent::FORMAT !== $original_profile->version() || ! hash_equals( $logical->policy_hash, $original_profile->policy_hash() ) || ! hash_equals( RefundPolicyContent::payload_schema()->fingerprint(), $original_profile->payload_schema()->fingerprint() ) ) {
			throw new \InvalidArgumentException( 'Unsupported refund policy family or format.' );
		}
		if ( ! hash_equals( RuleContent::scope_hash( $original_profile, $logical->scope() ), $logical->scope_hash ) || $original_profile->scope_schema()->encode( $logical->scope(), 4096 ) !== $logical->scope_json ) {
			throw new \InvalidArgumentException( 'Refund policy scope does not match its original declaration.' );
		}
		// RuleCandidate checks joined IDs, so validate the version again against this exact logical policy.
		$row = $candidate->version->row();
		$version = $candidate->version->hypothetical ? RuleVersion::hypothetical( $row, $logical, $original_profile ) : RuleVersion::from_row( $row, $logical, $original_profile );
		if ( $row !== $version->row() || ! hash_equals( $declaration, RuleFamilyRegistry::declaration( $original_profile ) ) ) {
			throw new \InvalidArgumentException( 'Refund policy original declaration changed.' );
		}
		$content = RefundPolicyContent::from_rule_payload( $version->payload() );
		$reference = new RuleVersionReference( $candidate );
		$metadata = [ ...$reference->facts(), 'state' => $version->state->value, 'effective_from' => $version->effective_from?->sql(), 'effective_until' => $version->effective_until?->sql(), 'author_user_id' => $version->author_user_id, 'change_reason' => $version->change_reason, 'created_at' => $version->created_at->sql(), 'updated_at' => $version->updated_at->sql() ];
		return new self( $content, $reference, $metadata );
	}

	public function content(): RefundPolicyContent { return $this->content; }
	public function reference(): RuleVersionReference { return $this->reference; }
	/** Internal authored facts only; a caller must authorize any external projection. */
	public function metadata(): array { return $this->metadata; }

	public function jsonSerialize(): never { throw new \LogicException( 'Refund policy version requires an explicit authorized projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Refund policy version requires an explicit authorized projection.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Refund policy version requires validated C01 content.' ); }
	public function __debugInfo(): array { return [ 'private_refund_policy_version' => 'redacted' ]; }
}
