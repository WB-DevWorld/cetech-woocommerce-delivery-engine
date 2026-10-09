<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteJson,QuoteMoney};

/** One guarded native matcher result; the stored native map is never rewritten or erased. */
final readonly class QuoteNativeShippingTaxProjection implements \JsonSerializable {
	private function __construct( private QuoteNativeTaxSource $original, private array $physical, private array $rates ) {}
	/** Trusted native prewarm supplies the complete matcher result after its source and hook fences. */
	public static function from_verified_native( QuoteNativeTaxSource $original, QuoteContext $context, array $physical, array $effective, array $rates ): self {
		if ( ! $original->matches_context( $context ) || ! $original->with_current( $physical, $effective )->matches_context( $context ) || count( $rates ) > 200 ) { self::fail(); }
		$physical = QuoteJson::detach( $physical ); $rows = [];
		foreach ( $physical['tax_rows'] as $row ) {
			$id = self::id( $row['tax_rate_id'] );
			if ( isset( $rows[$id] ) || $row['tax_rate_class'] !== $effective['tax_class'] || ! in_array( $row['tax_rate_shipping'], [ '0', '1' ], true ) || ! in_array( $row['tax_rate_compound'], [ '0', '1' ], true ) || 1 !== preg_match( '/\A[0-9]+(?:\.[0-9]+)?\z/D', $row['tax_rate'] ) || ! is_finite( (float) $row['tax_rate'] ) ) { self::fail(); }
			$rows[$id] = $row;
		}
		$matched = [];
		foreach ( $rates as $key => $rate ) {
			$id = self::id( $key ); $row = $rows[$id] ?? null;
			if ( ! is_array( $rate ) ) { self::fail(); } $keys = array_keys( $rate ); sort( $keys, SORT_STRING );
			if ( [ 'compound', 'label', 'rate', 'shipping' ] !== $keys || null === $row || isset( $matched[$id] ) || ! is_float( $rate['rate'] ) || ! is_finite( $rate['rate'] ) || $rate['rate'] < 0 || $rate['rate'] !== (float) $row['tax_rate'] || $rate['label'] !== $row['tax_rate_name'] || 'yes' !== $rate['shipping'] || '1' !== $row['tax_rate_shipping'] || $rate['compound'] !== ( '1' === $row['tax_rate_compound'] ? 'yes' : 'no' ) ) { self::fail(); }
			$matched[$id] = [ 'rate' => (float) $rate['rate'], 'label' => (string) $rate['label'], 'shipping' => (string) $rate['shipping'], 'compound' => (string) $rate['compound'] ];
		}
		return new self( $original, $physical, $matched );
	}
	public function physical(): array { return $this->physical; }
	public function native_rates(): array { return $this->rates; }
	/** Only exact, unrounded zero receipts are candidates for the native order-side representation. */
	public static function eligible( array $term ): bool {
		try {
			$receipt = $term['native_tax_receipt'] ?? null;
			return is_array( $receipt ) && 'recorded' === ( $receipt['state'] ?? null ) && 'woocommerce' === ( $receipt['source'] ?? null ) && false === ( $receipt['exempt'] ?? null ) && 'taxable' === ( $receipt['tax_status'] ?? null ) && [] === ( $receipt['rates'] ?? null ) && self::zero_money( $term['final'] ?? null ) && self::zero_money( $term['tax'] ?? null ) && self::zero_money( $receipt['rounded_tax'] ?? null );
		} catch ( \Throwable ) { return false; }
	}
	/** A nonzero or malformed stored map cannot cause native lookup work. */
	public static function is_native_zero_candidate( array $actual ): bool {
		try { if ( [] === $actual || count( $actual ) > 200 ) { return false; } foreach ( $actual as $id => $amount ) { self::id( $id ); if ( ! self::exact_zero( $amount ) ) { return false; } } return true; } catch ( \Throwable ) { return false; }
	}
	/** Complete native matched IDs, rather than every ID in the captured tax class. */
	public function zero_map( array $term ): ?array {
		try {
			if ( ! self::eligible( $term ) ) { return null; } $facts = $this->original->private_facts(); $receipt = $term['native_tax_receipt'];
			if ( ! $facts['tax_enabled'] || $facts['exempt'] || $receipt['context_digest'] !== $this->original->digest() || $receipt['tax_class'] !== $facts['tax_class'] || $receipt['location_digest'] !== $facts['location_digest'] || $receipt['rounding'] !== $facts['rounding'] || $receipt['display_precision'] !== $facts['precision'] || $receipt['rounded_tax']['precision'] !== $facts['precision'] ) { return null; }
			foreach ( [ $term['final'], $term['tax'], $receipt['rounded_tax'] ] as $money ) { if ( $money['currency'] !== $facts['currency'] ) { return null; } }
			$out = []; foreach ( $this->rates as $id => $_rate ) { $out[$id] = '0'; } ksort( $out, SORT_NUMERIC ); return $out;
		} catch ( \Throwable ) { return null; }
	}
	public function accepts_zero_map( array $term, array $actual ): bool {
		try {
			$expected = $this->zero_map( $term ); if ( null === $expected || count( $actual ) > 200 ) { return false; }
			$map = []; foreach ( $actual as $id => $amount ) { $key = self::id( $id ); if ( isset( $map[$key] ) || ! self::exact_zero( $amount ) ) { return false; } $map[$key] = '0'; } ksort( $map, SORT_NUMERIC );
			return [] === $map || $map === $expected;
		} catch ( \Throwable ) { return false; }
	}
	private static function zero_money( mixed $money ): bool { return is_array( $money ) && self::exact_zero( $money['amount'] ?? null ) && QuoteMoney::from_array( $money )->zero(); }
	private static function exact_zero( mixed $value ): bool { return is_string( $value ) ? 1 === preg_match( '/\A0(?:\.0+)?\z/D', $value ) : ( is_int( $value ) || is_float( $value ) ) && 0 == $value && ( ! is_float( $value ) || is_finite( $value ) ); }
	private static function id( mixed $id ): int { if ( ! is_int( $id ) && ( ! is_string( $id ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/D', $id ) || false === filter_var( $id, FILTER_VALIDATE_INT ) ) || (int) $id < 1 ) { self::fail(); } return (int) $id; }
	private static function fail(): never { throw new \UnexpectedValueException( 'Native shipping tax projection unavailable.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Native shipping tax projection requires an explicit private consumer.' ); }
	public function __serialize(): never { throw new \LogicException( 'Native shipping tax projection requires an explicit private consumer.' ); }
}
