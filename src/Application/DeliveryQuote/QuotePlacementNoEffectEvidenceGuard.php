<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Prewarmed native coordinates/facts only; the owned verifier cannot call Woo or source callbacks. */
interface QuotePlacementNoEffectEvidenceGuard {
	public function site_id(): int;
	public function order_id(): int;
	public function tables( OperationSession $session ): array;
	/** Compare the exact physical original order; a missing/prepared-one binding is never synthesized. */
	public function verify( OperationSession $session, QuoteStoredRow $original, ?QuoteBinding $binding ): bool;
	/** Repeat the captured raw native fence before and after the acknowledged read unit. */
	public function unchanged(): bool;
}
