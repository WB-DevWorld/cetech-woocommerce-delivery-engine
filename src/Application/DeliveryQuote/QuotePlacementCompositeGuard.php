<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** All prewarmed placement prerequisites share the final owner and lock lifetime. */
final readonly class QuotePlacementCompositeGuard implements QuotePlacementSavedEvidenceGuard {
	public function __construct( private QuotePlacementSavedEvidenceGuard $native, private QuotePlacementSavedEvidenceGuard $policy ) {}
	public function tables( OperationSession $session ): array { return array_values( array_unique( [ ...$this->native->tables( $session ), ...$this->policy->tables( $session ) ] ) ); }
	public function verify( OperationSession $session, QuoteBinding $binding ): bool { return $this->policy->verify( $session, $binding ) && $this->native->verify( $session, $binding ); }
}
