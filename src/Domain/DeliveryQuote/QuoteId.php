<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** An identifier is never authorization. */
final readonly class QuoteId implements \JsonSerializable {
	private function __construct( private string $id ) {}
	public static function generate(): self {
		$bytes = random_bytes( 16 ); $bytes[6] = chr( ( ord( $bytes[6] ) & 15 ) | 64 ); $bytes[8] = chr( ( ord( $bytes[8] ) & 63 ) | 128 );
		$hex = bin2hex( $bytes );
		return self::from_string( substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 ) );
	}
	public static function from_string( string $value ): self {
		if ( 1 !== preg_match( '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D', $value ) ) { QuoteShape::invalid(); }
		return new self( $value );
	}
	public function value(): string { return $this->id; }
	public function equals( self $other ): bool { return hash_equals( $this->id, $other->id ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit delivery quote projection is required.' ); }
}
