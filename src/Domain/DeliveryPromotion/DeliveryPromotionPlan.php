<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteJson, QuoteMoney, QuoteShape, QuoteTerms};

/** A hypothetical pre-tax plan, never native money, a budget reservation or quote admission. */
final readonly class DeliveryPromotionPlan implements \JsonSerializable {
	private const REASONS = [ 'component_unavailable', 'promotion_unavailable', 'promotion_already_applied', 'money_unit_mismatch', 'discount_exceeds_list', 'evidence_unavailable', 'evidence_invalid', 'eligibility_unavailable', 'ineligible', 'budget_unavailable', 'budget_exhausted' ];
	private function __construct( private string $json ) {}

	/** No provider call is necessary for an already unsupported input. */
	public static function preflight( QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule ): ?self {
		QuoteShape::digest( $component_key );
		$group = self::group( $terms, $component_key );
		if ( null === $group ) { return self::refused( $terms, $component_key, $rule, 'component_unavailable' ); }
		if ( 'unavailable' === $group['promotion']['state'] ) { return self::refused( $terms, $component_key, $rule, 'promotion_unavailable' ); }
		if ( 'none' !== $group['promotion']['state'] ) { return self::refused( $terms, $component_key, $rule, 'promotion_already_applied' ); }
		$list = QuoteMoney::from_array( $group['list'] );
		$discount = $rule->discount();
		if ( $list->currency() !== $discount->currency() || $list->precision() !== $discount->precision() ) {
			return self::refused( $terms, $component_key, $rule, 'money_unit_mismatch' );
		}
		if ( $discount->compare( $list ) > 0 ) { return self::refused( $terms, $component_key, $rule, 'discount_exceeds_list' ); }
		return null;
	}

	/** Only completely bound finite planning evidence can produce a calculated result. */
	public static function from_evidence( QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule, string $provider_code, int $provider_version, array $evidence ): self {
		$refusal = self::preflight( $terms, $component_key, $rule );
		if ( null !== $refusal ) { return $refusal; }
		try {
			QuoteShape::machine( $provider_code );
			QuoteShape::integer( $provider_version, 1, 1000000 );
			$evidence = QuoteJson::detach( $evidence );
			QuoteShape::fields( $evidence, [ 'format_version', 'provider', 'terms_digest', 'component_key', 'rule_digest', 'eligibility', 'budget' ] );
			if ( 1 !== $evidence['format_version'] ) { QuoteShape::invalid(); }
			$provider = QuoteShape::object( $evidence['provider'] );
			QuoteShape::fields( $provider, [ 'code', 'version' ] );
			if ( $provider['code'] !== $provider_code || $provider['version'] !== $provider_version
				|| ! hash_equals( $terms->digest(), QuoteShape::digest( $evidence['terms_digest'] ) )
				|| $component_key !== QuoteShape::digest( $evidence['component_key'] )
				|| ! hash_equals( $rule->digest(), QuoteShape::digest( $evidence['rule_digest'] ) ) ) { QuoteShape::invalid(); }
			self::decision( $evidence['eligibility'], [ 'eligible', 'ineligible' ] );
			self::decision( $evidence['budget'], [ 'available', 'exhausted' ] );
		} catch ( \Throwable ) {
			return self::refused( $terms, $component_key, $rule, 'evidence_invalid' );
		}
		$data = self::base( $terms, $component_key, $rule );
		$data['provider'] = $provider;
		$data['evidence'] = $evidence;
		$eligibility = $evidence['eligibility']['state'];
		$budget = $evidence['budget']['state'];
		$reason = 'unavailable' === $eligibility ? 'eligibility_unavailable' : ( 'ineligible' === $eligibility ? 'ineligible' : ( 'unavailable' === $budget ? 'budget_unavailable' : ( 'exhausted' === $budget ? 'budget_exhausted' : null ) ) );
		if ( null !== $reason ) {
			$data['state'] = 'refused';
			$data['reason_code'] = $reason;
			return new self( QuoteJson::encode( $data ) );
		}
		$list = QuoteMoney::from_array( self::group( $terms, $component_key )['list'] );
		$discount = $rule->discount();
		$data['state'] = 'planned';
		$data['reason_code'] = null;
		$data['funding_source'] = $rule->private_facts()['funding_source'];
		$data['money'] = [ 'list' => $list->facts(), 'discount' => $discount->facts(), 'final' => $list->subtract( $discount )->facts() ];
		return new self( QuoteJson::encode( $data ) );
	}

	public static function refused( QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule, string $reason ): self {
		QuoteShape::choice( $reason, self::REASONS );
		$data = self::base( $terms, $component_key, $rule );
		$data['state'] = 'refused';
		$data['reason_code'] = $reason;
		return new self( QuoteJson::encode( $data ) );
	}

	/**
	 * Replay frozen facts only. Representable refusal records do not authenticate their origin;
	 * neither successful decoding nor a digest grants eligibility, budget or admission authority.
	 */
	public static function from_array( QuoteTerms $terms, FixedDeliveryPromotion $rule, array $data ): self {
		$data = QuoteJson::detach( $data );
		$state = QuoteShape::choice( $data['state'] ?? null, [ 'planned', 'refused' ] );
		$fields = [ 'format_version', 'scope', 'terms_digest', 'component_key', 'rule_digest', 'rule', 'provider', 'evidence', 'state', 'reason_code' ];
		QuoteShape::fields( $data, 'planned' === $state ? [ ...$fields, 'funding_source', 'money' ] : $fields );
		if ( 1 !== $data['format_version'] || 'hypothetical_pre_tax' !== $data['scope'] ) { QuoteShape::invalid(); }
		$key = QuoteShape::digest( $data['component_key'] );
		if ( null === $data['provider'] && null === $data['evidence'] ) {
			if ( 'refused' !== $state ) { QuoteShape::invalid(); }
			$replayed = self::refused( $terms, $key, $rule, QuoteShape::choice( $data['reason_code'], self::REASONS ) );
		} else {
			$provider = QuoteShape::object( $data['provider'] );
			QuoteShape::fields( $provider, [ 'code', 'version' ] );
			$code = QuoteShape::machine( $provider['code'] );
			$version = QuoteShape::integer( $provider['version'], 1, 1000000 );
			$replayed = self::from_evidence( $terms, $key, $rule, $code, $version, QuoteShape::object( $data['evidence'] ) );
		}
		if ( ! hash_equals( $replayed->to_private_json(), QuoteJson::encode( $data ) ) ) { QuoteShape::invalid(); }
		return $replayed;
	}
	public static function from_json( QuoteTerms $terms, FixedDeliveryPromotion $rule, string $json ): self {
		return self::from_array( $terms, $rule, QuoteJson::decode( $json ) );
	}

	public function planned(): bool { return 'planned' === $this->private_facts()['state']; }
	public function reason_code(): ?string { return $this->private_facts()['reason_code']; }
	/** Explicit preview whitelist. Native tax, display totals and private funding never enter it. */
	public function preview_money(): ?array {
		$data = $this->private_facts();
		if ( 'planned' !== $data['state'] ) { return null; }
		return [ 'scope' => 'hypothetical_pre_tax', 'component_key' => $data['component_key'], 'list' => $data['money']['list'], 'discount' => $data['money']['discount'], 'final' => $data['money']['final'] ];
	}
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-delivery-promotion-plan-v1:' . $this->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Private promotion plans require an explicit authorized projection.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private promotion plans cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private promotion plans cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion' => 'redacted' ]; }

	private static function decision( mixed $value, array $known_states ): void {
		$data = QuoteShape::object( $value );
		if ( 'unavailable' === ( $data['state'] ?? null ) ) {
			QuoteShape::fields( $data, [ 'state', 'reason' ] );
			QuoteShape::choice( $data['reason'], [ 'not_recorded', 'source_unavailable', 'unsupported_context' ] );
		} else {
			QuoteShape::fields( $data, [ 'state', 'evidence_digest' ] );
			QuoteShape::choice( $data['state'], $known_states );
			QuoteShape::digest( $data['evidence_digest'] );
		}
	}
	private static function group( QuoteTerms $terms, string $component_key ): ?array {
		foreach ( $terms->private_facts()['groups'] as $group ) { if ( $group['component_key'] === $component_key ) { return $group; } }
		return null;
	}
	private static function base( QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule ): array {
		QuoteShape::digest( $component_key );
		return [ 'format_version' => 1, 'scope' => 'hypothetical_pre_tax', 'terms_digest' => $terms->digest(), 'component_key' => $component_key, 'rule_digest' => $rule->digest(), 'rule' => [ 'code' => $rule->code(), 'version' => $rule->version() ], 'provider' => null, 'evidence' => null ];
	}
}
