<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;

/** Trusted native adapter. Preparation runs only after a claimed admission. */
interface CartQuoteEnvironment {
	public function draft(): ?QuoteCartDraft;
	public function authorize( QuoteOwner $owner, string $operation ): bool;
	public function prepare( QuoteCartDraft $draft ): LegacyQuotePreparedCapture;
	public function evidence( QuoteIssueCommand $original, QuoteHeader $header, QuoteCartDraft $draft ): ?QuoteCartCurrentEvidence;
}
