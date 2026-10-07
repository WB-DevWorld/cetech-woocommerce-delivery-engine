<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Exact nonnegative decimal, at most twelve integral and six fractional digits. */
final readonly class QuoteMoney implements \JsonSerializable {
	private function __construct( private string $value, private string $code, private int $scale, private int $units ) {}
	public static function from_array( array $data ): self {
		QuoteShape::fields( $data, [ 'amount', 'currency', 'precision' ] );
		$precision = QuoteShape::integer( $data['precision'], 0, 6 ); $currency = QuoteShape::currency( $data['currency'] ); $amount = $data['amount'];
		if ( ! is_string( $amount ) || 1 !== preg_match( '/\A(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,6}))?\z/D', $amount, $parts ) || strlen( $parts[2] ?? '' ) > $precision ) { QuoteShape::invalid(); }
		$fraction = str_pad( $parts[2] ?? '', $precision, '0' );
		return new self( $parts[1] . ( $precision > 0 ? '.' . $fraction : '' ), $currency, $precision, (int) ( $parts[1] . $fraction ) );
	}
	public function amount(): string { return $this->value; }
	public function currency(): string { return $this->code; }
	public function precision(): int { return $this->scale; }
	public function facts(): array { return [ 'amount' => $this->value, 'currency' => $this->code, 'precision' => $this->scale ]; }
	public function equals( self $other ): bool { return $this->code === $other->code && $this->scale === $other->scale && $this->units === $other->units; }
	public function zero(): bool { return 0 === $this->units; }
	public function compare( self $other ): int { $this->same_units( $other ); return $this->units <=> $other->units; }
	public function add( self $other ): self { $this->same_units( $other ); return $this->with_units( $this->units + $other->units ); }
	public function subtract( self $other ): self { $this->same_units( $other ); return $this->with_units( $this->units - $other->units ); }
	private function same_units( self $other ): void { if ( $this->code !== $other->code || $this->scale !== $other->scale ) { QuoteShape::invalid(); } }
	private function with_units( int $units ): self {
		if ( $units < 0 ) { QuoteShape::invalid(); }
		$digits = str_pad( (string) $units, $this->scale + 1, '0', STR_PAD_LEFT );
		$amount = 0 === $this->scale ? $digits : substr( $digits, 0, -$this->scale ) . '.' . substr( $digits, -$this->scale );
		return self::from_array( [ 'amount' => $amount, 'currency' => $this->code, 'precision' => $this->scale ] );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit delivery quote money projection is required.' ); }
}
