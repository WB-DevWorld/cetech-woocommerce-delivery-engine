<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Trusted current merchant/rule/funding authority; eligibility is not a held budget. */
interface FixedPromotionEligibilityGuard {
	/** Finite same-owner InnoDB source tables; no external provider or WordPress calls. */
	public function tables( OperationSession $session ): array;
	/**
	 * Establish current eligibility for every applied component of this exact compiled quote.
	 * Use locking reads on the supplied live transaction, with the caller's captured time.
	 * No new owner, clock, transaction, reservation, payment or post-commit authority is granted.
	 */
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity;
}
