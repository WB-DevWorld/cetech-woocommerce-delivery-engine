<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
/** A trusted internal adapter. Synthetic implementations qualify only their fixtures. */
interface QuoteNativeCaptureSource {
 public function current_owner(): QuoteOwner;
 public function capture(): QuoteNativeState;
 /** Pure prewarmed state comparison: no getters, filters, SQL, provider or network. */
 public function unchanged(): bool;
}
