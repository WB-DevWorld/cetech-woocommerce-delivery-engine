<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Trusted captured native tax evidence; its getter is consumed outside owned SQL. */
interface QuoteNativeTaxEvidenceGuard extends QuoteCurrentEvidenceGuard {
	public function tax_source(): ?QuoteNativeTaxSource;
}
