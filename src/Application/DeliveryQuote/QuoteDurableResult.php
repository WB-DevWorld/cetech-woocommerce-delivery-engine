<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationAttemptResult;
/** Privileged detached result; the original handle is on command only, never audit. */
final readonly class QuoteDurableResult implements \JsonSerializable {
	public function __construct( public OperationAttemptResult $attempt, public ?QuoteDurableCommand $command = null, public ?QuoteStoredRow $quote = null, public ?QuoteBinding $binding = null, public ?string $reason = null ) {
		if ( null !== $reason && ! in_array( $reason, [ 'quote_expired', 'quote_invalidated', 'quote_unavailable', 'checkout_suspended' ], true ) ) { throw new \InvalidArgumentException( 'Invalid quote result.' ); }
	}
	public function jsonSerialize(): never { throw new \LogicException( 'An explicit authorized quote result projection is required.' ); }
}
