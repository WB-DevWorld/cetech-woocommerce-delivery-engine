<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryCommercial;

use CetechDeliveryEngine\Domain\DeliveryCurrency\CurrencyConversionReceipt;
use CetechDeliveryEngine\Domain\DeliveryEconomics\QuoteEconomicsFacts;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteJson, QuoteMoney, QuoteShape, QuoteTerms};

/** Private planning history. Recognition is not authentication, freshness, placement or payment authority. */
final readonly class QuoteCommercialEvidencePacket implements \JsonSerializable {
	public const FORMAT = 1;
	public const MAX_BYTES = 65536;
	private function __construct( private string $json ) {}

	/** Every component explicitly records promotion/currency evidence or not_recorded. */
	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteEconomicsFacts $economics, array $groups ): self {
		$rows = [];
		foreach ( QuoteShape::list( $groups, 200, 1 ) as $group ) {
			$group = QuoteShape::object( $group );
			QuoteShape::fields( $group, [ 'component_key', 'promotion', 'currency' ] );
			$promotion = QuoteShape::object( $group['promotion'] );
			if ( 'recorded' === ( $promotion['state'] ?? null ) ) {
				QuoteShape::fields( $promotion, [ 'state', 'rule', 'plan' ] );
				if ( ! $promotion['rule'] instanceof FixedDeliveryPromotion || ! $promotion['plan'] instanceof DeliveryPromotionPlan ) { QuoteShape::invalid(); }
				$promotion_facts = [ 'state' => 'recorded', 'rule' => $promotion['rule']->private_facts(), 'plan' => $promotion['plan']->private_facts() ];
			} else {
				QuoteShape::fields( $promotion, [ 'state' ] ); QuoteShape::choice( $promotion['state'], [ 'not_recorded' ] );
				$promotion_facts = [ 'state' => 'not_recorded' ];
			}
			$currency = QuoteShape::object( $group['currency'] );
			if ( 'recorded' === ( $currency['state'] ?? null ) ) {
				QuoteShape::fields( $currency, [ 'state', 'basis', 'receipt' ] );
				if ( ! $currency['receipt'] instanceof CurrencyConversionReceipt ) { QuoteShape::invalid(); }
				$currency_facts = [ 'state' => 'recorded', 'basis' => $currency['basis'], 'receipt' => $currency['receipt']->private_facts() ];
			} else {
				QuoteShape::fields( $currency, [ 'state' ] ); QuoteShape::choice( $currency['state'], [ 'not_recorded' ] );
				$currency_facts = [ 'state' => 'not_recorded' ];
			}
			$rows[] = [ 'component_key' => $group['component_key'], 'promotion' => $promotion_facts, 'currency' => $currency_facts ];
		}
		return self::from_array( [ 'format_version' => self::FORMAT, 'scope' => 'planning_only', 'header' => $header->private_facts(), 'context' => $context->private_facts(), 'terms' => $terms->private_facts(), 'economics' => $economics->private_facts(), 'groups' => $rows ] );
	}

	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json, self::MAX_BYTES ) ); }

	/** Replays only embedded frozen facts through their finite validators; no provider or live lookup. */
	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data, self::MAX_BYTES );
		QuoteShape::fields( $data, [ 'format_version', 'scope', 'header', 'context', 'terms', 'economics', 'groups' ] );
		if ( self::FORMAT !== $data['format_version'] || 'planning_only' !== $data['scope'] ) { QuoteShape::invalid(); }
		$header = QuoteHeader::from_array( QuoteShape::object( $data['header'] ) );
		$context = QuoteContext::from_array( QuoteShape::object( $data['context'] ) );
		$terms = QuoteTerms::from_array( QuoteShape::object( $data['terms'] ) );
		$header->assert_body( $context, $terms );
		$economics = QuoteEconomicsFacts::from_array( $terms, QuoteShape::object( $data['economics'] ) );
		$basis = []; foreach ( $terms->private_facts()['groups'] as $term ) { $basis[$term['component_key']] = $term; }
		$groups = QuoteShape::list( $data['groups'], 200, 1 ); $seen = [];
		foreach ( $groups as &$group ) {
			$group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'promotion', 'currency' ] );
			$key = QuoteShape::digest( $group['component_key'] );
			if ( isset( $seen[$key] ) || ! isset( $basis[$key] ) ) { QuoteShape::invalid(); } $seen[$key] = true;
			$promotion = QuoteShape::object( $group['promotion'] ); $plan = null;
			if ( 'not_recorded' === ( $promotion['state'] ?? null ) ) { QuoteShape::fields( $promotion, [ 'state' ] ); }
			else {
				QuoteShape::fields( $promotion, [ 'state', 'rule', 'plan' ] ); QuoteShape::choice( $promotion['state'], [ 'recorded' ] );
				$rule = FixedDeliveryPromotion::from_array( QuoteShape::object( $promotion['rule'] ) );
				$plan = DeliveryPromotionPlan::from_array( $terms, $rule, QuoteShape::object( $promotion['plan'] ) );
				if ( $plan->private_facts()['component_key'] !== $key ) { QuoteShape::invalid(); }
				$promotion['rule'] = $rule->private_facts(); $promotion['plan'] = $plan->private_facts();
			}
			$currency = QuoteShape::object( $group['currency'] );
			if ( 'not_recorded' === ( $currency['state'] ?? null ) ) { QuoteShape::fields( $currency, [ 'state' ] ); }
			else {
				QuoteShape::fields( $currency, [ 'state', 'basis', 'receipt' ] ); QuoteShape::choice( $currency['state'], [ 'recorded' ] );
				$selected = QuoteShape::choice( $currency['basis'], [ 'quote_final', 'planned_final' ] );
				$selected_plan = 'planned_final' === $selected ? $plan : null;
				$material = self::conversion_material_digest( $header, $context, $terms, $key, $selected, $selected_plan );
				$source = 'planned_final' === $selected ? QuoteMoney::from_array( $selected_plan->private_facts()['money']['final'] ) : QuoteMoney::from_array( $basis[$key]['final'] );
				$receipt = CurrencyConversionReceipt::from_array( QuoteShape::object( $currency['receipt'] ) ); $facts = $receipt->private_facts();
				if ( ! $source->equals( QuoteMoney::from_array( $facts['source'] ) ) || $facts['context']['base'] !== $context->private_facts()['currency']['base']
					|| ! hash_equals( $material, $facts['context']['material_digest'] ) || null !== CurrencyConversionReceipt::input_refusal( $source, $facts['context'] ) ) { QuoteShape::invalid(); }
				$currency['receipt'] = $facts;
			}
			$group['promotion'] = $promotion; $group['currency'] = $currency;
		} unset( $group );
		if ( count( $seen ) !== count( $basis ) ) { QuoteShape::invalid(); }
		usort( $groups, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) );
		$data['header'] = $header->private_facts(); $data['context'] = $context->private_facts(); $data['terms'] = $terms->private_facts(); $data['economics'] = $economics->private_facts(); $data['groups'] = $groups;
		return new self( QuoteJson::encode( $data, self::MAX_BYTES ) );
	}

	/** Explicit basis identity, not a choice of whether promotion occurs before conversion. */
	public static function conversion_material_digest( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, string $component_key, string $basis, ?DeliveryPromotionPlan $plan = null ): string {
		$header->assert_body( $context, $terms ); QuoteShape::digest( $component_key ); QuoteShape::choice( $basis, [ 'quote_final', 'planned_final' ] );
		if ( ! in_array( $component_key, array_column( $terms->private_facts()['groups'], 'component_key' ), true ) ) { QuoteShape::invalid(); }
		if ( 'planned_final' === $basis ) {
			if ( null === $plan || ! $plan->planned() || $plan->private_facts()['component_key'] !== $component_key || ! hash_equals( $terms->digest(), $plan->private_facts()['terms_digest'] ) ) { QuoteShape::invalid(); }
		} elseif ( null !== $plan ) { QuoteShape::invalid(); }
		return hash( 'sha256', 'cetech-commercial-conversion-material-v1:' . QuoteJson::encode( [ 'header_digest' => hash( 'sha256', $header->to_private_json() ), 'context_digest' => $context->digest(), 'terms_digest' => $terms->digest(), 'component_key' => $component_key, 'basis' => $basis, 'plan_digest' => $plan?->digest() ] ) );
	}

	/** Explicit private access requires caller authorization; no customer projection exists. */
	public function private_facts(): array { return QuoteJson::decode( $this->json, self::MAX_BYTES ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-commercial-evidence-packet-v1:' . $this->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Commercial planning history requires explicit private access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Commercial planning history cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Commercial planning history requires strict decoding.' ); }
	public function __debugInfo(): array { return [ 'private_commercial_evidence' => 'redacted' ]; }
}
