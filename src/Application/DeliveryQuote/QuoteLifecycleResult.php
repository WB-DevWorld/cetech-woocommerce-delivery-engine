<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;

/** In-memory outcome only; not a commit, reservation, payment or placement receipt. */
final readonly class QuoteLifecycleResult implements \JsonSerializable {
	private function __construct( private bool $successful, private ?DeliveryQuote $value, private ?string $reason ) {}
	public static function complete( DeliveryQuote $quote, ?string $reason = null ): self { self::validate_reason( $reason ); return new self( true, $quote, $reason ); }
	public static function refuse( ?DeliveryQuote $quote = null, string $reason = 'quote_unavailable' ): self { self::validate_reason( $reason ); return new self( false, $quote, $reason ); }
	public function completed(): bool { return $this->successful; }
	public function quote(): ?DeliveryQuote { return $this->value; }
	public function reason_code(): ?string { return $this->reason; }
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized delivery quote outcome projection is required.' ); }
	private static function validate_reason( ?string $reason ): void {
		if ( null !== $reason && ! in_array( $reason, [ 'quote_expired', 'quote_invalidated', 'quote_unavailable' ], true ) ) { throw new \InvalidArgumentException( 'Invalid delivery quote outcome.' ); }
	}
}
