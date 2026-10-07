<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;

/** Trusted finite internal injection only; no provider is registered by default. */
interface QuoteProviderInterface {
	public function code(): string;
	public function version(): int;
	public function profile(): string;
	public function profile_version(): int;
	/** @return array<string,list<int>> Reviewed exact auxiliary provider versions. */
	public function evidence_providers(): array;
	public function capture( QuoteContext $context ): QuoteTerms;
}
