<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryPromotion;

use CetechDeliveryEngine\Domain\Contracts\OperationIdentity;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteJson, QuoteShape, QuoteTerms};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleFamilyProfile, RuleMatch, RuleSchema};

/** Explicit merchant policy over captured facts; never registered or granted by default. */
final readonly class FixedPromotionEligibilityFamily implements RuleFamilyProfile {
	public const CODE = 'fixed_promotion_eligibility_v1';
	public const VERSION = 1;
	/** Leaves structural headroom inside the lifecycle codec's 128-node budget. */
	public const MAX_PRODUCTS = 120;
	private \Closure $administrator;
	private \Closure $author;

	public function __construct( callable $authorize, callable $authorize_author ) {
		$this->administrator = \Closure::fromCallable( $authorize );
		$this->author = \Closure::fromCallable( $authorize_author );
	}
	public function family(): string { return self::CODE; }
	public function version(): int { return self::VERSION; }
	public function policy_hash(): string { return self::definition_hash(); }
	public static function definition_hash(): string {
		return hash( 'sha256', 'cetech-fixed-promotion-eligibility-v1:exact-scalars:coherent-native-money-unit:scale-at-least-captured-display-up-to-six:all-captured-product-ids:ascending-unique-nonempty:strict-fixed-rule:explicit-account:priority-desc:specificity-zero:equal-refuse:' . self::predicate_schema()->fingerprint() . ':' . self::rule_payload_schema()->fingerprint() );
	}
	public function scope_schema(): RuleSchema { return self::predicate_schema(); }
	public function subject_schema(): RuleSchema { return self::predicate_schema(); }
	public function payload_schema(): RuleSchema { return self::rule_payload_schema(); }
	public function authorize( OperationIdentity $identity, array $scope ): bool {
		try { return true === ( $this->administrator )( $identity, self::checked_scope( $scope ) ); }
		catch ( \Throwable ) { return false; }
	}
	public function authorize_author( int $site_id, int $author_user_id, array $scope ): bool {
		try { return $site_id > 0 && $author_user_id > 0 && true === ( $this->author )( $site_id, $author_user_id, self::checked_scope( $scope ) ); }
		catch ( \Throwable ) { return false; }
	}
	public function match( array $scope, array $subject ): RuleMatch {
		$scope = self::checked_scope( $scope ); $subject = self::checked_scope( $subject );
		$matched = self::same_dimensions( $scope, $subject ) && [] === array_diff( $subject['product_ids'], $scope['product_ids'] );
		return new RuleMatch( $matched, 0 );
	}
	public function overlaps( array $left_scope, array $right_scope ): ?bool {
		try {
			$left = self::checked_scope( $left_scope ); $right = self::checked_scope( $right_scope );
			return self::same_dimensions( $left, $right ) && [] !== array_intersect( $left['product_ids'], $right['product_ids'] );
		} catch ( \Throwable ) { return null; }
	}
	public function precedence(): array { return [ [ 'field' => 'priority', 'direction' => 'desc' ] ]; }
	public function equal_rank_policy(): string { return 'refuse'; }
	public function unavailable_reason(): string { return 'fixed_promotion_eligibility_unavailable'; }

	/** Lists are explicit sets with one canonical order, never an implicit all-products scope. */
	public static function checked_scope( array $scope ): array {
		$scope = self::predicate_schema()->validate( $scope );
		QuoteShape::machine( $scope['promotion_code'] ); QuoteShape::currency( $scope['currency'] );
		$ids = QuoteShape::list( $scope['product_ids'], self::MAX_PRODUCTS, 1 ); $previous = 0;
		foreach ( $ids as $id ) { QuoteShape::integer( $id ); if ( $id <= $previous ) { QuoteShape::invalid(); } $previous = $id; }
		return $scope;
	}

	/** The structural lifecycle schema is followed by the strict owned rule codec. */
	public static function checked_payload( array $payload ): array {
		$payload = self::rule_payload_schema()->validate( $payload );
		$rule = FixedDeliveryPromotion::from_array( $payload['rule'] );
		if ( QuoteJson::encode( $payload['rule'] ) !== $rule->to_private_json() ) { QuoteShape::invalid(); }
		return $payload;
	}

	/** Pure projection from the original captured component; no Woo lookup or current defaults. */
	public static function subject_for_component( QuoteContext $original_context, QuoteTerms $original_terms, string $component_key, string $promotion_code ): array {
		QuoteShape::digest( $component_key ); QuoteShape::machine( $promotion_code );
		if ( 1 !== $original_context->format_version() || 1 !== $original_terms->format_version() ) { QuoteShape::invalid(); }
		$context = $original_context->private_facts(); $terms = $original_terms->private_facts();
		if ( array_column( $context['groups'], 'component_key' ) !== array_column( $terms['groups'], 'component_key' ) ) { QuoteShape::invalid(); }
		$currency = $context['currency']['charged'];
		if ( $context['currency']['base'] !== $currency || $context['currency']['presentment'] !== $currency ) { QuoteShape::invalid(); }
		$component = null;
		foreach ( $context['groups'] as $group ) { if ( $group['component_key'] === $component_key ) { $component = $group; break; } }
		if ( null === $component ) { QuoteShape::invalid(); }
		foreach ( $terms['groups'] as $group ) {
			if ( $group['component_key'] !== $component_key ) { continue; }
			// Context precision is native display precision. Retained money may carry
			// a finer native calculation unit; preserve it without rounding or padding.
			$precision = QuoteShape::integer( $group['list']['precision'], $context['currency']['precision'], 6 );
			foreach ( [ 'list', 'final', 'tax', 'total' ] as $field ) {
				if ( $group[$field]['currency'] !== $currency || $group[$field]['precision'] !== $precision ) { QuoteShape::invalid(); }
			}
		}
		$products = [];
		foreach ( $context['lines'] as $line ) { if ( $line['component_key'] === $component_key ) { $products[$line['product_id']] = $line['product_id']; } }
		$products = array_values( $products ); sort( $products, SORT_NUMERIC );
		return self::checked_scope( [ 'promotion_code' => $promotion_code, 'offer_id' => $component['offer_id'], 'destination_zone_id' => $component['destination_zone_id'], 'currency' => $currency, 'product_ids' => $products ] );
	}
	private static function same_dimensions( array $left, array $right ): bool {
		foreach ( [ 'promotion_code', 'offer_id', 'destination_zone_id', 'currency' ] as $field ) { if ( $left[$field] !== $right[$field] ) { return false; } }
		return true;
	}
	private static function predicate_schema(): RuleSchema {
		return new RuleSchema( [ 'promotion_code' => [ 'string' => 64 ], 'offer_id' => 'positive_int', 'destination_zone_id' => 'positive_int', 'currency' => [ 'string' => 3 ], 'product_ids' => [ 'list' => [ 'item' => 'positive_int', 'max' => self::MAX_PRODUCTS ] ] ] );
	}
	private static function rule_payload_schema(): RuleSchema {
		return new RuleSchema( [ 'rule' => [ 'object' => [ 'format_version' => 'positive_int', 'code' => [ 'string' => 64 ], 'version' => 'positive_int', 'discount' => [ 'object' => [ 'amount' => [ 'string' => 32 ], 'currency' => [ 'string' => 3 ], 'precision' => 'nonnegative_int' ] ], 'funding_source' => [ 'object' => [ 'code' => [ 'string' => 64 ], 'version' => 'positive_int' ] ] ] ], 'budget_account_key' => 'sha256' ] );
	}
}
