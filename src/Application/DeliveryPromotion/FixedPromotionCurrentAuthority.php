<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\QuoteCurrentEvidenceValidity;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Trusted same-owner current eligibility and HELD-budget proof; never payment or provider IO. */
interface FixedPromotionCurrentAuthority {
	/** Exact current InnoDB source tables, on the caller's existing owned session. */
	public function tables( OperationSession $session ): array;
	/** Recheck complete newly compiled quote identity, not merely its original legacy capture. */
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity;
}
