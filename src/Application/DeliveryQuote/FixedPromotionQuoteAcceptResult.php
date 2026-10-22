<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;

/** Privileged acknowledged accepted history, never current funding or payment authority. */
final readonly class FixedPromotionQuoteAcceptResult implements \JsonSerializable {

	public function __construct( public OperationAttemptResult $attempt, public ?FixedPromotionQuoteAcceptCommand $command = null, public ?QuoteStoredRow $quote = null ) {
		if ( ( 'accepted' === $attempt->outcome->state && null === $quote ) || ( null !== $quote && ( null === $command || 'accepted' !== $attempt->completion?->state || 'accepted' !== $quote->state() || 2 !== $quote->revision() || null === $quote->accepted_at() || $quote->header()->to_private_json() !== $command->header()->to_private_json() || $quote->context()?->to_private_json() !== $command->context()->to_private_json() || $quote->terms()?->to_private_json() !== $command->terms()->to_private_json() || ! $quote->header()->matches_reference( $command->reference() ) ) ) ) { throw new \InvalidArgumentException( 'Promotion acceptance result facts are inconsistent.' ); }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion acceptance results require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion acceptance results cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion acceptance results require acknowledged physical facts.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_accept_result' => 'redacted' ]; }
}
