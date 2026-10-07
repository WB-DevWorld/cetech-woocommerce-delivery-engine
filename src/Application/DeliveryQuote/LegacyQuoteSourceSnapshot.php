<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Ephemeral private physical receipt. Only compact digests enter the quote body. */
final readonly class LegacyQuoteSourceSnapshot implements \JsonSerializable {
	private function __construct( private LegacyQuoteSourcePlan $plan, private QuoteContext $context, private array $rows, private array $cards, private QuoteTime $at, private string $digest, private ?LegacyQuoteSourceLocalBinding $local_binding = null ) {}
	public static function captured( LegacyQuoteSourcePlan $plan, QuoteContext $context, array $rows, array $cards, QuoteTime $at ): self {
		foreach ( $rows as &$values ) { foreach ( $values as &$row ) { $row = QuoteJson::detach( $row ); } unset( $row ); } unset( $values ); foreach ( $cards as &$values ) { foreach ( $values as &$row ) { $row = QuoteJson::detach( $row ); } unset( $row ); } unset( $values );
		$receipts = []; foreach ( $rows as $source => $values ) { $receipts[$source] = self::ordered_rows_digest( 'cetech-quote-source-rows-v1:', $values ); } ksort( $receipts, SORT_STRING );
		$range_receipts = []; foreach ( $plan->rate_ranges() as $range ) { $key = LegacyQuoteSourcePlan::range_key( $range ); $range_receipts[$key] = self::range_digest( $range, $cards[$key] ?? [] ); }
		$digest = hash( 'sha256', 'cetech-legacy-price-policy-v1:' . QuoteJson::encode( [ 'format_version' => 1, 'source_receipts' => $receipts, 'rate_ranges' => $range_receipts, 'effective' => self::effective_signature( $cards, $at ) ] ) );
		return new self( $plan, $context, $rows, $cards, $at, $digest );
	}
	public function plan(): LegacyQuoteSourcePlan { return $this->plan; }
	public function context(): QuoteContext { return $this->context; }
	public function member_proofs(): array { return $this->plan->member_proofs(); }
	public function captured_at(): QuoteTime { return $this->at; }
	public function rows_for( string $source ): array { return $this->rows[$source] ?? []; }
	public function candidate_count( int $offer, int $zone, string $currency ): int { return count( $this->cards[LegacyQuoteSourcePlan::range_key( [ 'delivery_offer_id' => $offer, 'destination_zone_id' => $zone, 'base_currency' => $currency ] )] ?? [] ); }
	public function candidate_digest( ?int $offer = null, ?int $zone = null, ?string $currency = null ): string {
		if ( null === $offer && null === $zone && null === $currency ) { $hashes = []; foreach ( $this->plan->rate_ranges() as $range ) { $key = LegacyQuoteSourcePlan::range_key( $range ); $hashes[$key] = self::range_digest( $range, $this->cards[$key] ?? [] ); } return hash( 'sha256', 'cetech-quote-candidates-v1:' . QuoteJson::encode( $hashes ) ); }
		if ( null === $offer || null === $zone || null === $currency ) { QuoteShape::invalid(); } $range = [ 'delivery_offer_id' => $offer, 'destination_zone_id' => $zone, 'base_currency' => $currency ]; $key = LegacyQuoteSourcePlan::range_key( $range ); if ( ! array_key_exists( $key, $this->cards ) ) { QuoteShape::invalid(); } return self::range_digest( $range, $this->cards[$key] );
	}
	public function policy_digest(): string { return $this->digest; }
	public function active_repository(): LegacyQuoteCapturedRateRepository { return new LegacyQuoteCapturedRateRepository( $this->cards ); }
	public function guard(): LegacyQuoteCurrentEvidenceGuard { return new LegacyQuoteCurrentEvidenceGuard( $this ); }
	public function applicable_at( QuoteTime $at ): bool { return self::effective_signature( $this->cards, $this->at ) === self::effective_signature( $this->cards, $at ); }
	public function bind_context( QuoteContext $context ): self {
		if ( ! $this->plan->matches_context( $context ) ) { QuoteShape::invalid(); } $facts = $context->private_facts();
		foreach ( $facts['groups'] as $group ) { if ( $group['policy_digest'] !== $this->policy_digest() || $group['candidate_digest'] !== $this->candidate_digest( $group['offer_id'], $group['destination_zone_id'], $facts['currency']['base'] ) || $group['candidate_count'] !== $this->candidate_count( $group['offer_id'], $group['destination_zone_id'], $facts['currency']['base'] ) ) { QuoteShape::invalid(); } }
		return new self( $this->plan, $context, $this->rows, $this->cards, $this->at, $this->digest, $this->local_binding );
	}
	public function with_local_binding( LegacyQuoteSourceLocalBinding $binding ): self { return new self( $this->plan, $this->context, $this->rows, $this->cards, $this->at, $this->digest, $binding ); }
	public function local_state_unchanged(): bool { return null === $this->local_binding || $this->local_binding->unchanged(); }
	public function matches( self $other ): bool { return $this->plan->owner()->equals( $other->plan->owner() ) && hash_equals( $this->digest, $other->digest ); }
	private static function range_digest( array $range, array $cards ): string { return hash( 'sha256', 'cetech-quote-candidate-range-v1:' . QuoteJson::encode( [ 'range' => $range, 'ordered_rows_digest' => self::ordered_rows_digest( 'cetech-quote-candidate-rows-v1:', $cards ) ] ) ); }
	/** Fixed-width digest framing avoids imposing the quote-body byte budget on the 1,000-row proof. */
	private static function ordered_rows_digest( string $domain, array $rows ): string { $hash = hash_init( 'sha256' ); hash_update( $hash, $domain . count( $rows ) . ':' ); foreach ( $rows as $row ) { hash_update( $hash, hex2bin( self::row_digest( $row ) ) ); } return hash_final( $hash ); }
	public static function row_digest( array $row ): string { return hash( 'sha256', 'cetech-quote-physical-row-v1:' . QuoteJson::encode( $row ) ); }
	private static function effective_signature( array $cards, QuoteTime $at ): array { $out = []; $now = substr( $at->sql(), 0, 19 ); foreach ( $cards as $key => $rows ) { $ids = []; foreach ( $rows as $row ) { if ( 'active' !== ( $row['status'] ?? null ) || ( null !== ( $row['effective_from'] ?? null ) && $now < $row['effective_from'] ) || ( null !== ( $row['effective_to'] ?? null ) && $now > $row['effective_to'] ) ) { continue; } $ids[] = (string) $row['id']; } $out[$key] = $ids; } return $out; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized source projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Physical source receipts cannot be serialized generically.' ); }
}
