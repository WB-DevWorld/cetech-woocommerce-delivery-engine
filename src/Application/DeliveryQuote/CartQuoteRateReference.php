<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteShape;

/** Opaque component mapping. It grants no read, quote acceptance or placement authority. */
final readonly class CartQuoteRateReference implements \JsonSerializable {
	private function __construct( private array $facts ) {}
	public static function generate( QuoteHeader $header, string $component_key, int $generation ): self { return self::from_private_array( [ 'quote_id' => $header->id()->value(), 'component_key' => $component_key, 'component_handle' => bin2hex( random_bytes( 16 ) ), 'generation' => $generation ] ); }
	public static function from_private_array( array $facts ): self {
		QuoteShape::fields( $facts, [ 'quote_id', 'component_key', 'component_handle', 'generation' ] );
		if ( ! is_string( $facts['quote_id'] ) ) { QuoteShape::invalid(); } QuoteId::from_string( $facts['quote_id'] ); QuoteShape::digest( $facts['component_key'] ); QuoteShape::integer( $facts['generation'], 1, CartQuoteSessionEnvelope::MAX_GENERATION );
		if ( ! is_string( $facts['component_handle'] ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/D', $facts['component_handle'] ) ) { QuoteShape::invalid(); }
		return new self( $facts );
	}
	public function matches( QuoteHeader $header, string $component_key, int $generation ): bool { return $this->facts['quote_id'] === $header->id()->value() && $this->facts['component_key'] === $component_key && $this->facts['generation'] === $generation; }
	public function component_key(): string { return $this->facts['component_key']; }
	public function to_private_array(): array { return $this->facts; }
	/** Only an already-authorized consumer projects this inert identifier. */
	public function public_fields(): array { return [ 'quote_id' => $this->facts['quote_id'], 'component_handle' => $this->facts['component_handle'], 'generation' => $this->facts['generation'] ]; }
	public function jsonSerialize(): never { throw new \LogicException( 'Rate references require an explicit authorized projection.' ); }
	public function __serialize(): never { throw new \LogicException( 'Rate references use strict private session storage.' ); }
}
