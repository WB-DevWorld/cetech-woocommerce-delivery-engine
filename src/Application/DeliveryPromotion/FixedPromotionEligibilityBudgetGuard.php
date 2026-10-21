<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Current merchant eligibility also binds the explicitly selected funding accounts. */
interface FixedPromotionEligibilityBudgetGuard extends FixedPromotionEligibilityGuard {
	/** @param list<\CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetRequest> $requests */
	public function validity_for_requests_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity;
}
