<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Immutable finite current-source interval observed on the same owned read, never a new admission. */
final readonly class QuoteCurrentEvidenceValidity implements \JsonSerializable {
	private function __construct( private QuoteTime $observed_at, private QuoteTime $exclusive_until ) {}
	public static function capture( QuoteTime $observed_at, QuoteTime $exclusive_until ): self {
		if ( $observed_at->compare( $exclusive_until ) >= 0 ) { throw new \InvalidArgumentException( 'Current source validity has elapsed.' ); }
		return new self( $observed_at, $exclusive_until );
	}
	public function valid_at( QuoteTime $at ): bool { return $at->compare( $this->observed_at ) >= 0 && $at->compare( $this->exclusive_until ) < 0; }
	public function jsonSerialize(): never { throw new \LogicException( 'Current source validity is private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Current source validity cannot be serialized generically.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Current source validity cannot be hydrated generically.' ); }
}
