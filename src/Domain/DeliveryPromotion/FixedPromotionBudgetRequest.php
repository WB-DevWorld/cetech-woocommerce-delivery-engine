<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryCommercial\FixedPromotionQuoteCapture;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteMoney, QuoteShape, QuoteTerms, QuoteTime};

/** Exact compiled-quote allocation identity. Decoding does not authenticate or reserve funding. */
final readonly class FixedPromotionBudgetRequest implements \JsonSerializable {

	private function __construct( private string $json ) {}

	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, string $component_key, string $account_key ): self {
		if ( 3 !== $header->format_version() || 3 !== $context->format_version() || 3 !== $terms->format_version() || FixedPromotionQuoteCapture::PROFILE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose() ) { QuoteShape::invalid(); }
		$header->assert_body( $context, $terms ); QuoteShape::digest( $component_key ); QuoteShape::digest( $account_key );
		$capture = $context->fixed_promotion_capture(); if ( null === $capture ) { QuoteShape::invalid(); }
		$selected = null;
		foreach ( $capture->private_facts()['groups'] as $group ) { if ( $group['component_key'] === $component_key ) { $selected = $group; break; } }
		if ( null === $selected || null === $selected['rule'] || null === $selected['plan'] ) { QuoteShape::invalid(); }
		$rule = FixedDeliveryPromotion::from_array( $selected['rule'] ); $plan = DeliveryPromotionPlan::from_array( $capture->original_terms(), $rule, $selected['plan'] );
		if ( ! $plan->planned() ) { QuoteShape::invalid(); }
		return self::from_array( [ 'format_version' => 1, 'site_id' => $header->owner()->site_id(), 'account_key' => $account_key, 'owner_digest' => $header->owner()->digest(), 'quote_id' => $header->id()->value(),
			'header_digest' => hash( 'sha256', 'cetech-fixed-promotion-budget-header-v1:' . $header->to_private_json() ), 'body_digest' => $header->body_digest(), 'material_digest' => $header->material_digest(), 'terms_digest' => $terms->digest(),
			'capture_digest' => $capture->digest(), 'component_key' => $component_key, 'rule_digest' => $rule->digest(), 'plan_digest' => $plan->digest(), 'funding_source' => $rule->private_facts()['funding_source'], 'amount' => $rule->discount()->facts(),
			'quote_created_at' => $header->created_at()->sql(), 'quote_expires_at' => $header->expires_at()->sql() ] );
	}

	/** Strict frozen-fact replay only; current policy and ledger ownership are separate proofs. */
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data );
		QuoteShape::fields( $data, [ 'format_version', 'site_id', 'account_key', 'owner_digest', 'quote_id', 'header_digest', 'body_digest', 'material_digest', 'terms_digest', 'capture_digest', 'component_key', 'rule_digest', 'plan_digest', 'funding_source', 'amount', 'quote_created_at', 'quote_expires_at' ] );
		if ( 1 !== $data['format_version'] ) { QuoteShape::invalid(); } QuoteShape::integer( $data['site_id'] );
		foreach ( [ 'account_key', 'owner_digest', 'header_digest', 'body_digest', 'material_digest', 'terms_digest', 'capture_digest', 'component_key', 'rule_digest', 'plan_digest' ] as $field ) { QuoteShape::digest( $data[$field] ); }
		if ( ! is_string( $data['quote_id'] ) || ! is_string( $data['quote_created_at'] ) || ! is_string( $data['quote_expires_at'] ) ) { QuoteShape::invalid(); } QuoteId::from_string( $data['quote_id'] );
		$created = QuoteTime::parse( $data['quote_created_at'] ); $expires = QuoteTime::parse( $data['quote_expires_at'] );
		if ( ! $created->plus_seconds( QuoteHeader::TTL_SECONDS )->equals( $expires ) ) { QuoteShape::invalid(); }
		$funding = QuoteShape::object( $data['funding_source'] ); QuoteShape::fields( $funding, [ 'code', 'version' ] ); QuoteShape::machine( $funding['code'] ); QuoteShape::integer( $funding['version'], 1, 1000000 );
		$data['amount'] = QuoteMoney::from_array( QuoteShape::object( $data['amount'] ) )->facts();
		return new self( QuoteJson::encode( $data ) );
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-fixed-promotion-budget-request-v1:' . $this->json ); }
	public function claim_key(): string { return hash( 'sha256', 'cetech-fixed-promotion-budget-claim-v1:' . $this->json ); }
	public function site_id(): int { return $this->private_facts()['site_id']; }
	public function account_key(): string { return $this->private_facts()['account_key']; }
	public function funding_source(): array { return $this->private_facts()['funding_source']; }
	public function amount(): QuoteMoney { return QuoteMoney::from_array( $this->private_facts()['amount'] ); }
	public function quote_created_at(): QuoteTime { return QuoteTime::parse( $this->private_facts()['quote_created_at'] ); }
	public function quote_expires_at(): QuoteTime { return QuoteTime::parse( $this->private_facts()['quote_expires_at'] ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Budget requests require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Budget requests cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Budget requests require strict hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_budget_request' => 'redacted' ]; }
}
