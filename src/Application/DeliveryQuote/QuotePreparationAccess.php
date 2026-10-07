<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;

/** Owner/control access only. This is no budget grant or durable issue receipt. */
interface QuotePreparationAccess {
	/** An acknowledged current enabled revision, or unavailable/denied. */
	public function observe( QuoteOwner $owner ): ?int;
	public function confirm( QuoteOwner $owner, int $opened_revision ): bool;
}
