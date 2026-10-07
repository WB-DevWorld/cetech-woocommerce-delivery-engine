<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
/** Post-commit invalidate-only publication: never writes or replaces a cart selection. */
interface QuotePrivatePublication { public function invalidate( int $site_id, string $owner_digest, QuoteId $quote_id ): bool; }
