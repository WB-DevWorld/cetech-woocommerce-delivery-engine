<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryCommercial;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteJson, QuoteMoney, QuoteShape, QuoteTerms};

/** Frozen private provenance. Replay proves consistency, never funding or admission authority. */
final readonly class FixedPromotionQuoteCapture implements \JsonSerializable {

	public const PROFILE = 'fixed_promotion_native_v1';
	public const VERSION = 1;
	public const PROMOTION_PROVIDER = 'fixed_delivery_promotion_v1';
	private function __construct( private string $json ) {}

	/** Typed captured slots are complete, including explicit null/null unadjusted slots. */
	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $groups ): self {
		$captured = [];
		foreach ( QuoteShape::list( $groups, QuoteContext::MAX_GROUPS, 1 ) as $group ) {
			$group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'rule', 'plan' ] );
			if ( ( null !== $group['rule'] && ! $group['rule'] instanceof FixedDeliveryPromotion ) || ( null !== $group['plan'] && ! $group['plan'] instanceof DeliveryPromotionPlan ) ) { QuoteShape::invalid(); }
			$captured[] = [ 'component_key' => $group['component_key'], 'rule' => $group['rule']?->private_facts(), 'plan' => $group['plan']?->private_facts() ];
		}
		return self::from_array( [ 'format_version' => 1, 'original_header' => $header->private_facts(), 'original_context' => $context->private_facts(), 'original_terms' => $terms->private_facts(), 'groups' => $captured ] );
	}

	public static function from_array( array $data ): self {
		$data = QuoteJson::detach( $data );
		QuoteShape::fields( $data, [ 'format_version', 'original_header', 'original_context', 'original_terms', 'groups' ] );
		if ( 1 !== $data['format_version'] ) { QuoteShape::invalid(); }
		$header = QuoteHeader::from_array( QuoteShape::object( $data['original_header'] ) );
		$context = QuoteContext::from_array( QuoteShape::object( $data['original_context'] ) );
		$terms = QuoteTerms::from_array( QuoteShape::object( $data['original_terms'] ) );
		self::assert_original( $header, $context, $terms );
		$keys = array_column( $terms->private_facts()['groups'], 'component_key' ); $seen = []; $captured = []; $planned = 0;
		foreach ( QuoteShape::list( $data['groups'], QuoteContext::MAX_GROUPS, 1 ) as $group ) {
			$group = QuoteShape::object( $group ); QuoteShape::fields( $group, [ 'component_key', 'rule', 'plan' ] );
			$key = QuoteShape::digest( $group['component_key'] );
			if ( isset( $seen[$key] ) || ! in_array( $key, $keys, true ) || ( null === $group['rule'] ) !== ( null === $group['plan'] ) ) { QuoteShape::invalid(); }
			$seen[$key] = true; $rule = null; $plan = null;
			if ( null !== $group['rule'] ) {
				$rule = FixedDeliveryPromotion::from_array( QuoteShape::object( $group['rule'] ) );
				$plan = DeliveryPromotionPlan::from_array( $terms, $rule, QuoteShape::object( $group['plan'] ) );
				if ( ! $plan->planned() || $plan->private_facts()['component_key'] !== $key ) { QuoteShape::invalid(); }
				++$planned;
			}
			$captured[] = [ 'component_key' => $key, 'rule' => $rule?->private_facts(), 'plan' => $plan?->private_facts() ];
		}
		if ( 0 === $planned || count( $seen ) !== count( $keys ) ) { QuoteShape::invalid(); }
		usort( $captured, static fn( array $a, array $b ): int => strcmp( $a['component_key'], $b['component_key'] ) );
		return new self( QuoteJson::encode( [ 'format_version' => 1, 'original_header' => $header->private_facts(), 'original_context' => $context->private_facts(), 'original_terms' => $terms->private_facts(), 'groups' => $captured ] ) );
	}
	public static function from_json( string $json ): self { return self::from_array( QuoteJson::decode( $json ) ); }

	/** Only the fresh native tax/money evidence may differ from original material. */
	public function assert_context( QuoteContext $fresh_context_v1 ): void {
		if ( 1 !== $fresh_context_v1->format_version() || ! $fresh_context_v1->checkout_acceptable() ) { QuoteShape::invalid(); }
		$original = $this->original_context()->private_facts(); $fresh = $fresh_context_v1->private_facts();
		unset( $original['tax'], $fresh['tax'] );
		if ( QuoteJson::encode( $original ) !== QuoteJson::encode( $fresh ) ) { QuoteShape::invalid(); }
	}

	/** Verify exact original list and planned discount/final beside newly captured native receipts. */
	public function assert_terms( QuoteTerms $promoted_terms_v1 ): void {
		if ( 1 !== $promoted_terms_v1->format_version() || ! $promoted_terms_v1->checkout_acceptable() ) { QuoteShape::invalid(); }
		$original = []; $slots = [];
		foreach ( $this->original_terms()->private_facts()['groups'] as $group ) { $original[$group['component_key']] = $group; }
		foreach ( $this->private_facts()['groups'] as $slot ) { $slots[$slot['component_key']] = $slot; }
		$groups = $promoted_terms_v1->private_facts()['groups'];
		if ( count( $groups ) !== count( $original ) ) { QuoteShape::invalid(); }
		foreach ( $groups as $group ) {
			$key = $group['component_key']; $base = $original[$key] ?? null; $slot = $slots[$key] ?? null;
			if ( null === $base || null === $slot || $group['policy_digest'] !== $base['policy_digest'] || $group['provider'] !== [ 'code' => self::PROFILE, 'version' => self::VERSION ]
				|| $group['cost'] !== [ 'reason' => 'cost_provider_unavailable', 'state' => 'unavailable' ] || $group['route'] !== [ 'state' => 'not_recorded' ]
				|| 'recorded' !== $group['native_tax_receipt']['state'] || 'recorded' !== $group['native_money_receipt']['state'] ) { QuoteShape::invalid(); }
			$planned = null !== $slot['plan']; $money = $planned ? $slot['plan']['money'] : [ 'list' => $base['list'], 'discount' => $base['promotion']['amount'], 'final' => $base['final'] ];
			if ( $group['promotion']['state'] !== ( $planned ? 'applied' : 'none' ) || $group['promotion']['provider'] !== [ 'code' => $planned ? self::PROMOTION_PROVIDER : 'native_no_delivery_promotion_v1', 'version' => 1 ]
				|| ! self::same_money( $group['list'], $base['list'] ) || ! self::same_money( $group['list'], $money['list'] )
				|| ! self::same_money( $group['promotion']['amount'], $money['discount'] ) || ! self::same_money( $group['final'], $money['final'] ) ) { QuoteShape::invalid(); }
		}
	}
	public function original_header(): QuoteHeader { return QuoteHeader::from_array( $this->private_facts()['original_header'] ); }
	public function original_context(): QuoteContext { return QuoteContext::from_array( $this->private_facts()['original_context'] ); }
	public function original_terms(): QuoteTerms { return QuoteTerms::from_array( $this->private_facts()['original_terms'] ); }
	public function private_facts(): array { return QuoteJson::decode( $this->json ); }
	public function to_private_json(): string { return $this->json; }
	public function digest(): string { return hash( 'sha256', 'cetech-fixed-promotion-quote-capture-v1:' . $this->json ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Private promotion capture requires explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Private promotion capture cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Private promotion capture requires strict hydration.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_capture' => 'redacted' ]; }

	private static function same_money( array $a, array $b ): bool {
		// Padding only: native precision may exceed the unchanged source/rule scale.
		return QuoteMoney::from_array( array_replace( $a, [ 'precision' => 6 ] ) )->equals( QuoteMoney::from_array( array_replace( $b, [ 'precision' => 6 ] ) ) );
	}
	private static function assert_original( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms ): void {
		if ( 1 !== $header->format_version() || 1 !== $context->format_version() || 1 !== $terms->format_version() || 'legacy_fixed_base_v1' !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose()
			|| ! $context->checkout_acceptable() || ! $terms->checkout_acceptable() ) { QuoteShape::invalid(); }
		$header->assert_body( $context, $terms );
		foreach ( $context->private_facts()['groups'] as $group ) { if ( $group['offer_id'] !== $group['service_id'] ) { QuoteShape::invalid(); } }
		foreach ( $terms->private_facts()['groups'] as $group ) {
			if ( $group['provider'] !== [ 'code' => 'legacy_fixed_base_v1', 'version' => 1 ] || 'none' !== $group['promotion']['state'] || $group['promotion']['provider'] !== [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ]
				|| $group['cost'] !== [ 'reason' => 'cost_provider_unavailable', 'state' => 'unavailable' ] || $group['route'] !== [ 'state' => 'not_recorded' ]
				|| 'recorded' !== $group['native_tax_receipt']['state'] || 'recorded' !== $group['native_money_receipt']['state'] ) { QuoteShape::invalid(); }
		}
	}
}
