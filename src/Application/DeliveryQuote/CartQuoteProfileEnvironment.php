<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Trusted composition selects a closed profile before the original preparation lease. */
interface CartQuoteProfileEnvironment extends CartQuoteEnvironment {
	public function profile(): string;
	public function prepare_at( QuoteCartDraft $draft, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime $original_issued_at ): LegacyQuotePreparedCapture;
}
