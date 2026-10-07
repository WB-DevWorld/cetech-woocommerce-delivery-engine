<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
/** A read cannot accept or invalidate a quote, including a historically accepted one. */
final readonly class QuoteCurrentReadResult implements \JsonSerializable {
	private function __construct( public string $status, public ?QuoteStoredRow $quote, public ?string $reason ) {}
	public static function ready( QuoteStoredRow $quote, ?string $reason = null ): self { if ( null !== $reason && ! in_array( $reason, [ 'quote_expired', 'quote_invalidated', 'quote_unavailable', 'checkout_suspended' ], true ) ) { throw new \InvalidArgumentException( 'Invalid quote read.' ); } return new self( 'ready', $quote, $reason ); }
	public static function unavailable(): self { return new self( 'unavailable', null, 'quote_unavailable' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote read projection is required.' ); }
}
