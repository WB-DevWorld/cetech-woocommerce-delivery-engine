<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;

/** Current internal evidence; it neither changes the recorded body nor prices a quote. */
final readonly class QuoteCartCurrentEvidence implements \JsonSerializable {

	public function __construct( public QuoteContext $current_context, public QuoteCurrentEvidenceGuard $guard ) {}

	public function jsonSerialize(): never { throw new \LogicException( 'An authorized cart quote projection is required.' ); }
	public function __serialize(): never { throw new \LogicException( 'Cart quote evidence cannot be serialized generically.' ); }
}
