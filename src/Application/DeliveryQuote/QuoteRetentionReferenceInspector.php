<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteStoredRow;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
/** Trusted exact structured reference contract; absence must include all registered producers. */
interface QuoteRetentionReferenceInspector {
 public function policy_digest(): string;
 public function transactional_tables(OperationSession $session): array;
 public function authorize_owner(QuoteOwner $owner): bool;
 /** Current reads/locks only; unknown adapters, links or malformed evidence return unknown. */
 public function inspect(OperationSession $session, QuoteStoredRow $quote): string;
}
