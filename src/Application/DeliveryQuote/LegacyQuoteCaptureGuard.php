<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Two typed, pure/current-SQL fences; no native capture is invoked under a lock. */
final readonly class LegacyQuoteCaptureGuard implements QuoteCurrentEvidenceGuard {
	public function __construct( private QuoteCurrentEvidenceGuard $source, private QuoteCurrentEvidenceGuard $native ) {}
	public function tables( OperationSession $session ): array { $tables = array_values( array_unique( [ ...$this->source->tables( $session ), ...$this->native->tables( $session ) ] ) ); sort( $tables, SORT_STRING ); return $tables; }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { return $this->source->verify( $session, $owner, $context ) && $this->native->verify( $session, $owner, $context ); }
}
