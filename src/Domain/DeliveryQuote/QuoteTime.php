<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Canonical UTC microseconds; no implicit local timezone conversion. */
final readonly class QuoteTime implements \JsonSerializable {
	private function __construct( private string $value, private int $microseconds ) {}
	public static function parse( string $value ): self {
		if ( 1 !== preg_match( '/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/D', $value ) ) { QuoteShape::invalid(); }
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s.u', $value, new \DateTimeZone( 'UTC' ) ); $errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( false !== $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d H:i:s.u' ) !== $value ) { QuoteShape::invalid(); }
		return new self( $value, (int) $date->format( 'U' ) * 1000000 + (int) $date->format( 'u' ) );
	}
	public static function from_epoch_microseconds( int $value ): self {
		$seconds = intdiv( $value, 1000000 ); $fraction = $value % 1000000;
		if ( $fraction < 0 ) { --$seconds; $fraction += 1000000; }
		try { return self::parse( ( new \DateTimeImmutable( '@' . $seconds ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) . '.' . str_pad( (string) $fraction, 6, '0', STR_PAD_LEFT ) ); } catch ( \Throwable ) { QuoteShape::invalid(); }
	}
	public static function now(): self { return self::parse( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s.u' ) ); }
	public function sql(): string { return $this->value; }
	public function iso_utc(): string { return str_replace( ' ', 'T', $this->value ) . 'Z'; }
	public function epoch_microseconds(): int { return $this->microseconds; }
	public function compare( self $other ): int { return $this->microseconds <=> $other->microseconds; }
	public function equals( self $other ): bool { return 0 === $this->compare( $other ); }
	public function plus_seconds( int $seconds ): self {
		if ( abs( $seconds ) > 86400000 ) { QuoteShape::invalid(); }
		return self::from_epoch_microseconds( $this->microseconds + $seconds * 1000000 );
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit delivery quote projection is required.' ); }
}
