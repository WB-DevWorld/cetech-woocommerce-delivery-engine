<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\ServicePromise\Handoff;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteCurrentEvidenceGuard, QuoteCurrentEvidenceValidity, QuoteNativeTaxEvidenceGuard, QuoteNativeTaxSource, QuoteTimedCurrentEvidenceGuard};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteOwner, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\RuleLifecycle\RuleTime;

/** Composition retains the original native money/source guard and adds the required promise fence. */
final readonly class PromiseQuoteCurrentEvidenceGuard implements QuoteNativeTaxEvidenceGuard, QuoteTimedCurrentEvidenceGuard {
	public function __construct( private QuoteCurrentEvidenceGuard $native, private PromiseHandoffSourceFence $promise ) {}
	public function tax_source(): ?QuoteNativeTaxSource { return $this->native instanceof QuoteNativeTaxEvidenceGuard ? $this->native->tax_source() : null; }
	public function tables( OperationSession $session ): array { return array_values( array_unique( [ ...$this->native->tables( $session ), ...$this->promise->tables( $session ) ] ) ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { return 2 === $context->format_version() && $this->native->verify( $session, $owner, $context->base_context() ) && $this->promise->verify_context( $session, $owner, $context ); }
	/** The caller has already captured this boundary time outside its SQL ownership. */
	public function verify_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $at ): bool {
		return null !== $this->validity_at( $session, $owner, $context, $at );
	}
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		try { return 2 === $context->format_version() && $this->native->verify( $session, $owner, $context->base_context() ) ? $this->promise->at( RuleTime::parse( $at->sql() ) )->validity_context( $session, $owner, $context ) : null; } catch ( \Throwable ) { return null; }
	}
}
