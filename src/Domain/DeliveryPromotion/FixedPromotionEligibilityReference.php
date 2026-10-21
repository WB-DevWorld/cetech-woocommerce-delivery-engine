<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteShape};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleCandidate, RuleState};

/** Stable private identity of the actual published merchant selection, not planning authority. */
final readonly class FixedPromotionEligibilityReference implements \JsonSerializable {
	private function __construct( private string $json, private FixedDeliveryPromotion $fixed_rule ) {}

	public static function from_candidate( RuleCandidate $candidate ): self {
		$logical = $candidate->logical; $version = $candidate->version;
		if ( FixedPromotionEligibilityFamily::CODE !== $logical->family_code || FixedPromotionEligibilityFamily::VERSION !== $logical->scope_format
			|| ! hash_equals( FixedPromotionEligibilityFamily::definition_hash(), $logical->policy_hash )
			|| FixedPromotionEligibilityFamily::VERSION !== $version->payload_format || $version->hypothetical
			|| RuleState::Published !== $version->state || $logical->current_published_version_id !== $version->id ) { QuoteShape::invalid(); }
		$scope = FixedPromotionEligibilityFamily::checked_scope( $logical->scope() );
		$payload = FixedPromotionEligibilityFamily::checked_payload( $version->payload() ); $rule = FixedDeliveryPromotion::from_array( $payload['rule'] );
		if ( $scope['promotion_code'] !== $rule->code() || $scope['currency'] !== $rule->discount()->facts()['currency'] ) { QuoteShape::invalid(); }
		return new self( QuoteJson::encode( [ 'format_version' => 1, 'site_id' => $logical->site_id,
			'family_code' => $logical->family_code, 'family_version' => $logical->scope_format, 'policy_hash' => $logical->policy_hash,
			'logical_uuid' => $logical->logical_uuid, 'version_uuid' => $version->version_uuid,
			'scope_hash' => $logical->scope_hash, 'content_hash' => $version->content_hash,
			'fixed_rule_digest' => $rule->digest(), 'budget_account_key' => $payload['budget_account_key'] ] ), $rule );
	}
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-fixed-promotion-eligibility-reference-v1:' . $this->json ); }
	public function account_key(): string { return $this->private_facts()['budget_account_key']; }
	public function rule(): FixedDeliveryPromotion { return $this->fixed_rule; }
	public function jsonSerialize(): never { throw new \LogicException( 'Private eligibility references require explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private eligibility references cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private eligibility references cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_eligibility_reference' => 'redacted' ]; }
}
