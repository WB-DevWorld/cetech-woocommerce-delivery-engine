<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/**
 * Trusted same-owner candidate-order proof, never a browser assertion or payment grant.
 *
 * An adapter must lock and prove the exact persisted order/site/owner, complete item
 * mapping (products, variations and quantities), and native money against its immutable
 * evidence identity. It must retain those locks on the caller's transaction, preserve
 * its owner binding, and perform no mutation or Woo side effect. Synthetic implementations
 * prove only the core contract, not native Woo order certification.
 */
interface FixedPromotionQuoteOrderEvidenceGuard {

	/** Exact immutable digest captured by the original bind command. */
	public function evidence_digest(): string;
	/** Complete bounded same-owner InnoDB participants for the current proof. */
	public function tables( OperationSession $session ): array;
	/** Lock and verify the full candidate without changing or ending the owned unit. */
	public function verify( OperationSession $session, QuoteStoredRow $accepted, QuoteBinding $candidate ): bool;
}
