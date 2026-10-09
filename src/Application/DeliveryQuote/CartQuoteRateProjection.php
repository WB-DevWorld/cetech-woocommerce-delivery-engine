<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

/** Inert presentation of existing native rates; no calculation or placement authority. */
interface CartQuoteRateProjection {
	public function project_loaded_rates(): void;
}
