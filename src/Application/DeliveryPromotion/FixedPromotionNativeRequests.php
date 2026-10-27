<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{LegacyFixedBaseQuoteProvider, QuoteNativeGroupRequest};
use CetechDeliveryEngine\Domain\DeliveryPromotion\DeliveryPromotionPlan;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteJson, QuoteMoney, QuoteShape, QuoteTerms};

/** Explicit expected native amounts. This neither applies a discount nor issues another quote. */
final readonly class FixedPromotionNativeRequests implements \JsonSerializable {
	private function __construct( private QuoteHeader $header, private QuoteContext $context, private QuoteTerms $terms, private array $slots, private array $requests ) {}

	/** Group mapping is trusted server capture, never posted group identifiers or amounts. */
	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $slots, array $mapping ): self {
		$header->assert_body( $context, $terms );
		// A fresh native money digest invalidates existing v2 promise captures. Never strip them.
		if ( 1 !== $header->format_version() || LegacyFixedBaseQuoteProvider::CODE !== $header->profile() || 1 !== $header->profile_version() || 'checkout' !== $header->purpose() ) { QuoteShape::invalid(); }
		new LegacyFixedBaseQuoteProvider( $header->owner(), $context, $terms );
		$groups = [];
		foreach ( $terms->private_facts()['groups'] as $group ) { $groups[$group['component_key']] = $group; }
		$selected = []; $planned = 0;
		foreach ( QuoteShape::list( $slots, 200, 1 ) as $slot ) {
			$slot = QuoteShape::object( $slot ); QuoteShape::fields( $slot, [ 'component_key', 'plan' ] );
			$key = QuoteShape::digest( $slot['component_key'] ); $plan = $slot['plan'];
			if ( isset( $selected[$key] ) || ! isset( $groups[$key] ) || ( null !== $plan && ! $plan instanceof DeliveryPromotionPlan ) ) { QuoteShape::invalid(); }
			if ( null !== $plan ) {
				$facts = $plan->private_facts();
				if ( ! $plan->planned() || $facts['component_key'] !== $key || ! hash_equals( $terms->digest(), $facts['terms_digest'] )
					|| ! QuoteMoney::from_array( $groups[$key]['list'] )->equals( QuoteMoney::from_array( $facts['money']['list'] ) ) ) { QuoteShape::invalid(); }
				++$planned;
			}
			$selected[$key] = [ 'component_key' => $key, 'plan' => $plan ];
		}
		if ( 0 === $planned || count( $selected ) !== count( $groups ) ) { QuoteShape::invalid(); }
		$requests = []; $seen = []; $legacy = [];
		foreach ( QuoteShape::list( QuoteJson::detach( [ 'mapping' => $mapping ] )['mapping'], 200, 1 ) as $entry ) {
			$entry = QuoteShape::object( $entry ); QuoteShape::fields( $entry, [ 'component_key', 'legacy_group_id' ] );
			$key = QuoteShape::digest( $entry['component_key'] );
			if ( isset( $seen[$key] ) || ! isset( $groups[$key] ) || ! is_string( $entry['legacy_group_id'] ) || isset( $legacy[$entry['legacy_group_id']] ) ) { QuoteShape::invalid(); }
			$seen[$key] = true; $legacy[$entry['legacy_group_id']] = true;
			$plan = $selected[$key]['plan'];
			$money = null === $plan ? $groups[$key]['final'] : $plan->private_facts()['money']['final'];
			$requests[$key] = new QuoteNativeGroupRequest( $key, $entry['legacy_group_id'], QuoteMoney::from_array( $money ) );
		}
		if ( count( $seen ) !== count( $groups ) ) { QuoteShape::invalid(); }
		ksort( $selected, SORT_STRING ); ksort( $requests, SORT_STRING );
		$result = new self( $header, $context, $terms, array_values( $selected ), array_values( $requests ) );
		QuoteJson::encode( $result->private_facts() ); // Refuse an over-budget identity before native capture.
		return $result;
	}

	public function header(): QuoteHeader { return $this->header; }
	public function original_context(): QuoteContext { return $this->context; }
	public function original_terms(): QuoteTerms { return $this->terms; }
	public function plans(): array { return $this->slots; }
	public function native_requests(): array { return $this->requests; }
	/** Explicit private provenance; no live source is consulted. */
	public function private_facts(): array {
		return [ 'format_version' => 1, 'scope' => 'unadmitted_native_capture', 'quote_id' => $this->header->id()->value(), 'body_digest' => $this->header->body_digest(),
			'header_digest' => hash( 'sha256', 'cetech-fixed-promotion-original-header-v1:' . $this->header->to_private_json() ),
			'material_digest' => $this->context->digest(), 'terms_digest' => $this->terms->digest(),
			'groups' => array_map( static fn( array $slot ): array => [ 'component_key' => $slot['component_key'], 'plan' => $slot['plan']?->private_facts() ], $this->slots ),
			'native_requests' => array_map( static fn( QuoteNativeGroupRequest $request ): array => [ 'component_key' => $request->component_key, 'legacy_group_id' => $request->legacy_group_id, 'expected_ex_tax' => $request->expected_ex_tax->facts() ], $this->requests ) ];
	}
	public function digest(): string { return hash( 'sha256', 'cetech-fixed-promotion-native-requests-v1:' . QuoteJson::encode( $this->private_facts() ) ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion native requests require explicit authorized access.' ); }
	public function __serialize(): array { throw new \LogicException( 'Promotion native requests cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): void { throw new \LogicException( 'Promotion native requests cannot be unserialized implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_native' => 'redacted' ]; }
}
