<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext,QuoteOwner,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Current source eligibility at an already captured event time; no clock or new SQL owner. */
interface QuoteTimedCurrentEvidenceGuard extends QuoteCurrentEvidenceGuard {
	public function verify_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $captured_at ): bool;
	/** Same-owner source proof, including every earliest exclusive lifecycle/selection boundary. */
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $captured_at ): ?QuoteCurrentEvidenceValidity;
}
