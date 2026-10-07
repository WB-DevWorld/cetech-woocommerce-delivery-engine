<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\DeliveryQuote;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteHeader;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteId;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteReference;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;

/** Pure internal orchestration. No registration, IO, replay store or native writer. */
final class QuoteLifecycle {
	public function __construct( private readonly QuoteProviderRegistry $providers = new QuoteProviderRegistry() ) {}

	/** Caller retains the minted reference. This method makes no token replay claim. */
	public function issue( QuoteIssueCommand $command, QuoteId $id, QuoteReference $reference, ?QuoteTime $at ): QuoteLifecycleResult {
		if ( null === $at || ! $reference->id()->equals( $id ) ) { return QuoteLifecycleResult::refuse(); }
		try {
			$terms = $this->providers->capture( $command->provider_code(), $command->provider_version(), $command->profile(), $command->profile_version(), $command->context() );
			$header = QuoteHeader::issue( $id, $command->owner(), $command->context(), $terms, $at, $command->namespace_hashes( $id ), $command->profile(), $command->profile_version(), $reference );
			$quote = DeliveryQuote::issue( $header, $command->context(), $terms );
			return QuoteLifecycleResult::complete( $quote, $quote->reason_at( $at ) );
		} catch ( \Throwable ) { return QuoteLifecycleResult::refuse(); }
	}

	/** Unknown current evidence is never treated as a confirmed material change. */
	public function current( DeliveryQuote $quote, ?QuoteOwner $owner, ?QuoteContext $current_context, ?QuoteTime $at, ?QuoteTime $previous_observed_at = null ): QuoteLifecycleResult {
		if ( ! $this->known_current( $quote, $owner, $current_context, $at, $previous_observed_at ) ) { return QuoteLifecycleResult::refuse( $quote ); }
		if ( ! hash_equals( $quote->header()->material_digest(), $current_context->digest() ) ) { return QuoteLifecycleResult::refuse( $quote, 'quote_invalidated' ); }
		$reason = $quote->reason_at( $at );
		return null === $reason ? QuoteLifecycleResult::complete( $quote ) : QuoteLifecycleResult::refuse( $quote, $reason );
	}

	public function accept( DeliveryQuote $quote, ?QuoteOwner $owner, QuoteReference $reference, ?QuoteContext $current_context, ?QuoteTime $at, int $opened_revision, string $body_digest, QuoteTime $original_expiry ): QuoteLifecycleResult {
		if ( ! $this->known_current( $quote, $owner, $current_context, $at ) ) { return QuoteLifecycleResult::refuse( $quote ); }
		try {
			$accepted = $quote->accept( $owner, $reference, $current_context, $at, $opened_revision, $body_digest, $original_expiry );
			return QuoteLifecycleResult::complete( $accepted, $accepted->reason_at( $at ) );
		} catch ( \Throwable ) { return QuoteLifecycleResult::refuse( $quote, $this->refusal_reason( $quote, $current_context, $at ) ); }
	}

	public function invalidate( DeliveryQuote $quote, ?QuoteOwner $owner, QuoteReference $reference, ?QuoteContext $current_context, ?QuoteTime $at, int $opened_revision, string $body_digest, QuoteTime $original_expiry ): QuoteLifecycleResult {
		if ( ! $this->known_current( $quote, $owner, $current_context, $at ) ) { return QuoteLifecycleResult::refuse( $quote ); }
		try { return QuoteLifecycleResult::complete( $quote->invalidate( $owner, $reference, $current_context, $at, $opened_revision, $body_digest, $original_expiry ), 'quote_invalidated' ); }
		catch ( \Throwable ) { return QuoteLifecycleResult::refuse( $quote ); }
	}

	/** Model exercise only. A durable retention adopter requires independent proofs. */
	public function strip_for_model( DeliveryQuote $quote, ?QuoteOwner $owner, QuoteReference $reference, ?QuoteTime $at, int $opened_revision, string $body_digest, QuoteTime $original_expiry ): QuoteLifecycleResult {
		if ( null === $owner || null === $at || ! $quote->header()->owner()->equals( $owner ) ) { return QuoteLifecycleResult::refuse( $quote ); }
		try { return QuoteLifecycleResult::complete( $quote->strip_for_model( $owner, $reference, $at, $opened_revision, $body_digest, $original_expiry ), 'quote_unavailable' ); }
		catch ( \Throwable ) { return QuoteLifecycleResult::refuse( $quote ); }
	}

	private function known_current( DeliveryQuote $quote, ?QuoteOwner $owner, ?QuoteContext $context, ?QuoteTime $at, ?QuoteTime $previous = null ): bool {
		return null !== $owner && null !== $context && $context->material_evidence_available() && null !== $at && $quote->header()->owner()->equals( $owner ) && ( null === $previous || $at->compare( $previous ) >= 0 );
	}
	private function refusal_reason( DeliveryQuote $quote, QuoteContext $context, QuoteTime $at ): string {
		return $quote->reason_at( $at ) ?? ( hash_equals( $quote->header()->material_digest(), $context->digest() ) ? 'quote_unavailable' : 'quote_invalidated' );
	}
}
