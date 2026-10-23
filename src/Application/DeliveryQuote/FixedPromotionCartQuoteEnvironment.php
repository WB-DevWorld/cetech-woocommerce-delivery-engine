<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Explicit native promotion preparation; existing legacy and promise captures stay unchanged. */
interface FixedPromotionCartQuoteEnvironment extends CartQuoteProfileEnvironment {
	public function prepare_promotion_at( QuoteCartDraft $draft, QuoteTime $original_issued_at ): FixedPromotionNativeCartPreparedCapture;
	public function promotion_inputs( QuoteIssueCommand $original, QuoteCartDraft $draft ): ?FixedPromotionQuoteAcceptInputs;
	/** Known rejection only; uncertain staged commands retain their original recovery. */
	public function abort_promotion(): void;
}
