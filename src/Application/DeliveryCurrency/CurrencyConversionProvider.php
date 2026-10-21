<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryCurrency;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteMoney;

/** Trusted explicit adapter; the provider owns conversion, rounding, rate and observation time. */
interface CurrencyConversionProvider {
	public function code(): string;
	public function version(): int;
	/**
	 * Convert once for this call and echo the independently bound request/provider/source.
	 * Current provider context belongs to the adapter/caller; a timestamp is not an age guarantee.
	 * No adapter is registered by default and no native checkout authority is granted.
	 */
	public function convert( QuoteMoney $source, array $context, string $request_digest ): array;
}
