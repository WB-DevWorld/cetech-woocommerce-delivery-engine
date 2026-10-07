<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
/** Trusted internal adapters only: native SQL/pure verification, no Woo/provider IO. */
interface QuoteCurrentEvidenceGuard {
	/** Exact additional InnoDB source tables in the same owned session. */
	public function tables( OperationSession $session ): array;
	/** Current locking SQL fences must establish the captured source facts. */
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool;
}
