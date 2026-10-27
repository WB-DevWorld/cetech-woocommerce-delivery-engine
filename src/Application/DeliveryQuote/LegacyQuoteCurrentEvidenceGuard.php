<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;

/** Current SQL only. No resolver, Woo, tax filter, provider or external effects under locks. */
final readonly class LegacyQuoteCurrentEvidenceGuard implements QuoteCurrentEvidenceGuard {
	public function __construct( private LegacyQuoteSourceSnapshot $snapshot, private LegacyQuoteSourceSnapshotReader $reader = new LegacyQuoteSourceSnapshotReader() ) {}
	public function tables( OperationSession $session ): array { return $this->snapshot->plan()->tables( $session ); }
	public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool {
		try { if ( ! $owner->equals( $this->snapshot->plan()->owner() ) || ! hash_equals( $context->digest(), $this->snapshot->context()->digest() ) || ! $this->snapshot->local_state_unchanged() ) { return false; } return $this->snapshot->matches( $this->reader->capture( $session, $owner, $context, $this->snapshot->plan() ) ) && $this->snapshot->local_state_unchanged(); } catch ( \Throwable ) { return false; }
	}
	/** Trusted explicit event time; retain the caller's clock and the same physical SQL owner. */
	public function verify_at( OperationSession $session, QuoteOwner $owner, QuoteContext $context, QuoteTime $at ): bool {
		try {
			if ( $at->compare( $this->snapshot->captured_at() ) < 0 || ! $this->snapshot->applicable_at( $at ) || ! $owner->equals( $this->snapshot->plan()->owner() ) || ! hash_equals( $context->digest(), $this->snapshot->context()->digest() ) || ! $this->snapshot->local_state_unchanged() ) { return false; }
			return $this->snapshot->matches( $this->reader->capture( $session, $owner, $context, $this->snapshot->plan(), $at ) ) && $this->snapshot->local_state_unchanged();
		} catch ( \Throwable ) { return false; }
	}
}
