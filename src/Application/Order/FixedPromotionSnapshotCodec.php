<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedDeliveryPromotion};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteMoney, QuoteShape, QuoteTerms, QuoteTime};

/** Reader-only commercial history. Private capture bodies never enter this envelope. */
final class FixedPromotionSnapshotCodec {
	public const PROFILE = [ 'code' => 'fixed_promotion_native_v1', 'version' => 1 ];
	public const APPLIED_PROVIDER = [ 'code' => 'fixed_delivery_promotion_v1', 'version' => 1 ];
	public const NONE_PROVIDER = [ 'code' => 'native_no_delivery_promotion_v1', 'version' => 1 ];

	/** Validate exact persisted references and native money; this grants no placement authority. */
	public static function from_array( array $data ): array {
		$data = QuoteJson::detach( $data, DeliveryQuoteSnapshotEnvelope::MAX_BYTES );
		QuoteShape::fields( $data, [ 'format', 'quote_id', 'profile', 'issued_at', 'expires_at', 'accepted_at', 'body_digest', 'material_digest', 'placement_id', 'context_digest', 'money_receipt', 'provenance_receipt', 'promotion_capture_digest', 'source_quote' ] );
		if ( 3 !== $data['format'] || QuoteShape::object( $data['profile'] ) !== self::PROFILE ) { QuoteShape::invalid(); }
		foreach ( [ 'quote_id', 'placement_id' ] as $field ) { if ( ! is_string( $data[$field] ) ) { QuoteShape::invalid(); } QuoteId::from_string( $data[$field] ); }
		foreach ( [ 'body_digest', 'material_digest', 'context_digest', 'promotion_capture_digest' ] as $field ) { QuoteShape::digest( $data[$field] ); }
		$source_quote = QuoteShape::object( $data['source_quote'] ); QuoteShape::fields( $source_quote, [ 'quote_id', 'body_digest', 'material_digest' ] );
		if ( ! is_string( $source_quote['quote_id'] ) ) { QuoteShape::invalid(); } QuoteId::from_string( $source_quote['quote_id'] );
		QuoteShape::digest( $source_quote['body_digest'] ); QuoteShape::digest( $source_quote['material_digest'] );
		foreach ( [ 'issued_at', 'expires_at', 'accepted_at' ] as $field ) { if ( ! is_string( $data[$field] ) ) { QuoteShape::invalid(); } }
		$issued = QuoteTime::parse( $data['issued_at'] ); $expires = QuoteTime::parse( $data['expires_at'] ); $accepted = QuoteTime::parse( $data['accepted_at'] );
		if ( ! $issued->plus_seconds( QuoteHeader::TTL_SECONDS )->equals( $expires ) || $accepted->compare( $issued ) < 0 || $accepted->compare( $expires ) >= 0 ) { QuoteShape::invalid(); }
		$money = QuoteShape::object( $data['money_receipt'] ); $provenance = QuoteShape::object( $data['provenance_receipt'] );
		foreach ( [ $money, $provenance ] as $receipt ) { QuoteShape::fields( $receipt, [ 'format', 'groups' ] ); if ( 1 !== $receipt['format'] ) { QuoteShape::invalid(); } }
		$sources = [];
		foreach ( QuoteShape::list( $provenance['groups'], 200, 1 ) as $row ) {
			$row = QuoteShape::object( $row ); QuoteShape::fields( $row, [ 'component_key', 'provider', 'policy_digest', 'candidate_digest', 'native_tax_receipt', 'native_money_receipt', 'promotion_provider', 'cost', 'route', 'promotion_reference' ] );
			$key = QuoteShape::digest( $row['component_key'] ); QuoteShape::digest( $row['policy_digest'] ); QuoteShape::digest( $row['candidate_digest'] );
			if ( isset( $sources[$key] ) || QuoteShape::object( $row['provider'] ) !== self::PROFILE || QuoteShape::object( $row['cost'] ) !== [ 'reason' => 'cost_provider_unavailable', 'state' => 'unavailable' ] || QuoteShape::object( $row['route'] ) !== [ 'state' => 'not_recorded' ] ) { QuoteShape::invalid(); }
			$ref = QuoteShape::object( $row['promotion_reference'] );
			if ( 'none' === ( $ref['state'] ?? null ) ) { QuoteShape::fields( $ref, [ 'state' ] ); }
			else {
				QuoteShape::fields( $ref, [ 'state', 'rule_code', 'rule_version', 'rule_digest', 'plan_digest' ] );
				if ( 'recorded' !== $ref['state'] ) { QuoteShape::invalid(); } QuoteShape::machine( $ref['rule_code'] ); QuoteShape::integer( $ref['rule_version'], 1, 1000000 ); QuoteShape::digest( $ref['rule_digest'] ); QuoteShape::digest( $ref['plan_digest'] );
			}
			$sources[$key] = $row;
		}
		$seen = []; $currency = null; $tax_digest = null; $money_digest = null; $applied = 0;
		foreach ( QuoteShape::list( $money['groups'], 200, 1 ) as $price ) {
			$price = QuoteShape::object( $price ); QuoteShape::fields( $price, [ 'component_key', 'customer_label', 'list', 'final', 'tax', 'total', 'promotion', 'rounded_tax', 'display_total' ] );
			$key = QuoteShape::digest( $price['component_key'] ); $source = $sources[$key] ?? null;
			if ( isset( $seen[$key] ) || null === $source ) { QuoteShape::invalid(); } $seen[$key] = true;
			$promotion = QuoteShape::object( $price['promotion'] ); QuoteShape::fields( $promotion, [ 'state', 'amount' ] );
			$state = QuoteShape::choice( $promotion['state'], [ 'none', 'applied' ] );
			if ( QuoteShape::object( $source['promotion_provider'] ) !== ( 'applied' === $state ? self::APPLIED_PROVIDER : self::NONE_PROVIDER ) || $source['promotion_reference']['state'] !== ( 'applied' === $state ? 'recorded' : 'none' ) ) { QuoteShape::invalid(); }
			if ( 'applied' === $state ) { ++$applied; }
			$term = [ 'component_key' => $key, 'customer_label' => $price['customer_label'], 'provider' => $source['provider'], 'policy_digest' => $source['policy_digest'], 'list' => $price['list'], 'final' => $price['final'], 'tax' => $price['tax'], 'total' => $price['total'], 'promotion' => $promotion + [ 'provider' => $source['promotion_provider'] ], 'cost' => $source['cost'], 'route' => $source['route'], 'native_tax_receipt' => $source['native_tax_receipt'], 'native_money_receipt' => $source['native_money_receipt'] ];
			$terms = QuoteTerms::from_array( [ 'format_version' => 1, 'groups' => [ $term ] ] ); if ( ! $terms->checkout_acceptable() ) { QuoteShape::invalid(); } $term = $terms->private_facts()['groups'][0];
			$final = QuoteMoney::from_array( $term['final'] );
			if ( ! QuoteMoney::from_array( QuoteShape::object( $price['rounded_tax'] ) )->equals( QuoteMoney::from_array( $term['native_tax_receipt']['rounded_tax'] ) ) || ! QuoteMoney::from_array( QuoteShape::object( $price['display_total'] ) )->equals( QuoteMoney::from_array( $term['native_money_receipt']['display_total'] ) ) || ( null !== $currency && $currency !== $final->currency() ) || ( null !== $tax_digest && $tax_digest !== $term['native_tax_receipt']['context_digest'] ) || ( null !== $money_digest && $money_digest !== $term['native_money_receipt']['evidence_digest'] ) ) { QuoteShape::invalid(); }
			$currency = $final->currency(); $tax_digest = $term['native_tax_receipt']['context_digest']; $money_digest = $term['native_money_receipt']['evidence_digest'];
		}
		if ( 0 === $applied || count( $seen ) !== count( $sources ) ) { QuoteShape::invalid(); }
		return $data;
	}

	/** Pure synthetic forward history, never a saved-order or payment acceptance receipt. */
	public static function from_captured( QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $accepted_at, QuoteId $placement_id, string $context_digest ): array {
		$header->assert_body( $context, $terms );
		if ( 3 !== $context->format_version() || 3 !== $terms->format_version() || ! $context->checkout_acceptable() || ! $terms->checkout_acceptable() || 'checkout' !== $header->purpose() || self::PROFILE !== [ 'code' => $header->profile(), 'version' => $header->profile_version() ] ) { QuoteShape::invalid(); }
		$capture = $context->fixed_promotion_capture(); if ( null === $capture ) { QuoteShape::invalid(); }
		$capture->assert_context( $context->base_context() ); $capture->assert_terms( $terms->base_terms() );
		$components = []; foreach ( $context->private_facts()['groups'] as $group ) { if ( $group['service_id'] !== $group['offer_id'] ) { QuoteShape::invalid(); } $components[$group['component_key']] = $group; }
		$references = [];
		foreach ( $capture->private_facts()['groups'] as $group ) {
			$ref = [ 'state' => 'none' ];
			if ( null !== $group['rule'] ) {
				$rule = FixedDeliveryPromotion::from_array( $group['rule'] ); $plan = DeliveryPromotionPlan::from_array( $capture->original_terms(), $rule, $group['plan'] );
				$ref = [ 'state' => 'recorded', 'rule_code' => $rule->code(), 'rule_version' => $rule->version(), 'rule_digest' => $rule->digest(), 'plan_digest' => $plan->digest() ];
			}
			$references[$group['component_key']] = $ref;
		}
		$money = []; $provenance = [];
		foreach ( $terms->private_facts()['groups'] as $term ) {
			$group = $components[$term['component_key']] ?? null; $ref = $references[$term['component_key']] ?? null; if ( null === $group || null === $ref ) { QuoteShape::invalid(); }
			$money[] = [ 'component_key' => $term['component_key'], 'customer_label' => $term['customer_label'], 'list' => $term['list'], 'final' => $term['final'], 'tax' => $term['tax'], 'total' => $term['total'], 'promotion' => [ 'state' => $term['promotion']['state'], 'amount' => $term['promotion']['amount'] ?? null ], 'rounded_tax' => $term['native_tax_receipt']['rounded_tax'] ?? null, 'display_total' => $term['native_money_receipt']['display_total'] ?? null ];
			$provenance[] = [ 'component_key' => $term['component_key'], 'provider' => $term['provider'], 'policy_digest' => $term['policy_digest'], 'candidate_digest' => $group['candidate_digest'], 'native_tax_receipt' => $term['native_tax_receipt'], 'native_money_receipt' => $term['native_money_receipt'], 'promotion_provider' => $term['promotion']['provider'] ?? null, 'cost' => $term['cost'], 'route' => $term['route'], 'promotion_reference' => $ref ];
		}
		$original = $capture->original_header();
		return self::from_array( [ 'format' => 3, 'quote_id' => $header->id()->value(), 'profile' => self::PROFILE, 'issued_at' => $header->created_at()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => $accepted_at->sql(), 'body_digest' => $header->body_digest(), 'material_digest' => $header->material_digest(), 'placement_id' => $placement_id->value(), 'context_digest' => $context_digest, 'money_receipt' => [ 'format' => 1, 'groups' => $money ], 'provenance_receipt' => [ 'format' => 1, 'groups' => $provenance ], 'promotion_capture_digest' => $capture->digest(), 'source_quote' => [ 'quote_id' => $original->id()->value(), 'body_digest' => $original->body_digest(), 'material_digest' => $original->material_digest() ] ] );
	}
}
