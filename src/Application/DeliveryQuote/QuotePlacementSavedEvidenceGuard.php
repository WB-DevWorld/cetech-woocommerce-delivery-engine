<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Prewarmed native saved facts; verification uses only this owned SQL session. */
interface QuotePlacementSavedEvidenceGuard {
	public function tables( OperationSession $session ): array;
	public function verify( OperationSession $session, QuoteBinding $binding ): bool;
}
