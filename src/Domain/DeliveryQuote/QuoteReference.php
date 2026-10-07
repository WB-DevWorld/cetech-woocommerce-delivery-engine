<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DeliveryQuote;

/** Explicit public retry fields; the handle does not confer authority. */
final readonly class QuoteReference implements \JsonSerializable {
	private function __construct( private QuoteId $quote_id, private string $acceptance_handle ) {}
	public static function generate( QuoteId $id ): self { return new self( $id, bin2hex( random_bytes( 32 ) ) ); }
	public static function from_array( array $data ): self {
		QuoteShape::fields( $data, [ 'quote_id', 'acceptance_handle' ] ); if ( ! is_string( $data['quote_id'] ) ) { QuoteShape::invalid(); }
		return new self( QuoteId::from_string( $data['quote_id'] ), QuoteShape::digest( $data['acceptance_handle'] ) );
	}
	public function id(): QuoteId { return $this->quote_id; }
	public function handle(): string { return $this->acceptance_handle; }
	public function public_fields(): array { return [ 'quote_id' => $this->quote_id->value(), 'acceptance_handle' => $this->acceptance_handle ]; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit delivery quote reference projection is required.' ); }
}
