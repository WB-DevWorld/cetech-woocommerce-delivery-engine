<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedDeliveryPromotion;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTerms;

/** Trusted finite internal injection only; planning evidence is not a budget reservation. */
interface DeliveryPromotionEvidenceProvider {
	public function code(): string;
	public function version(): int;
	/**
	 * Observe current planning eligibility/budget state and bind it to these exact inputs.
	 * Digest binding does not attest age or reserve budget; freshness belongs to this provider.
	 * No provider is mounted or selected by default.
	 */
	public function capture( QuoteTerms $terms, string $component_key, FixedDeliveryPromotion $rule ): array;
}
