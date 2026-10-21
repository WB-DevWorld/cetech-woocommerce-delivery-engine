<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;

/** Privileged acknowledged issued history, without acceptance or current payment authority. */
final readonly class FixedPromotionQuoteIssueResult implements \JsonSerializable {

	public function __construct( public OperationAttemptResult $attempt, public ?FixedPromotionQuoteIssueCommand $command = null, public ?QuoteStoredRow $quote = null ) {
		if ( null !== $command?->lease() || ( 'accepted' === $attempt->outcome->state && null === $quote ) || ( null !== $quote && ( null === $command || 'accepted' !== $attempt->completion?->state || 'issued' !== $quote->state() || 1 !== $quote->revision() || $quote->header()->to_private_json() !== $command->header()->to_private_json() || $quote->context()?->to_private_json() !== $command->context()->to_private_json() || $quote->terms()?->to_private_json() !== $command->terms()->to_private_json() || ! $quote->header()->matches_reference( $command->reference() ) ) ) ) { throw new \InvalidArgumentException( 'Promotion issue result facts are inconsistent.' ); }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion issue results require explicit authorized access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion issue results cannot be persisted implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion issue results require acknowledged physical facts.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_issue_result' => 'redacted' ]; }
}
