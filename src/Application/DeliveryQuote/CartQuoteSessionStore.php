<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;

interface CartQuoteSessionStore {
	public function load( QuoteOwner $owner ): ?CartQuoteSessionEnvelope;
	public function compare_and_swap( QuoteOwner $owner, ?CartQuoteSessionEnvelope $expected, CartQuoteSessionEnvelope $replacement ): bool;
	public function expires_at( QuoteOwner $owner ): int;
}
