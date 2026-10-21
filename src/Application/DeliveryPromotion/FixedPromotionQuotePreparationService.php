<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteCurrentEvidenceGuard, LegacyQuoteSourceSnapshot, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetAllocationSet;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** One explicit, caller-owned preparation. It never issues a quote or admits a payment. */
final readonly class FixedPromotionQuotePreparationService implements \JsonSerializable {

	private \Closure $authorize;

	/** Every dependency is an explicitly trusted port; this service is not registered by default. */
	public function __construct( private FixedPromotionBudgetAccountStore $store, private FixedPromotionEligibilityBudgetGuard $eligibility, private OperationReadiness $publication_readiness, callable $authorize ) {
		$this->authorize = \Closure::fromCallable( $authorize );
	}

	public function prepare( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, LegacyQuoteSourceSnapshot $original, FixedPromotionNativeResult $fresh_native, array $complete_account_slots, QuoteTime $at, QuoteTime $hold_until ): FixedPromotionQuotePreparation {
		$tracked = null;
		try {
			$prefix = $session->table_prefix(); $charset = $session->charset_collate(); $this->assert_owned( $session, $owner, $prefix, $charset );
			if ( ! $owner->equals( $header->owner() ) || ! $header->valid_at( $at ) || $at->compare( $hold_until ) >= 0 || $hold_until->compare( $header->expires_at() ) > 0 ) { self::refuse(); }
			$set = FixedPromotionBudgetAllocationSet::from_captured( $header, $context, $terms, $complete_account_slots );
			$this->publication_readiness->assert_ready( $session ); $this->assert_owned( $session, $owner, $prefix, $charset );
			$tracked = new FixedPromotionBudgetWriteTrackingStore( $this->store );
			$campaign = FixedPromotionBudgetCurrentAuthority::from_captured( $header, $context, $terms, $set->account_slots(), $tracked, $this->eligibility );
			$guard = FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $header, $context, $terms, $original, $fresh_native, $campaign );
			$tables = $guard->tables( $session ); $this->assert_owned( $session, $owner, $prefix, $charset );
			if ( ! $session->validate_tables( $tables ) ) { self::refuse(); } $this->assert_owned( $session, $owner, $prefix, $charset );
			$source = $guard->source_validity_at( $session, $owner, $context, $at ); $this->assert_owned( $session, $owner, $prefix, $charset );
			if ( null === $source ) { self::refuse(); }
			$eligible = $this->eligibility->validity_for_requests_at( $session, $owner, $header, $context, $terms, $set->requests(), $at ); $this->assert_owned( $session, $owner, $prefix, $charset );
			if ( null === $eligible ) { self::refuse(); }
			$before = QuoteCurrentEvidenceValidity::intersection( $at, $source, $eligible, QuoteCurrentEvidenceValidity::capture( $at, $hold_until ) );
			if ( null === $before ) { self::refuse(); }
			$reservations = ( new FixedPromotionBudgetAllocationService( $tracked, $this->authorize ) )->reserve_all( $session, $set, $at, $hold_until );
			$this->assert_owned( $session, $owner, $prefix, $charset );
			$this->publication_readiness->assert_ready( $session ); $this->assert_owned( $session, $owner, $prefix, $charset );
			$after = $guard->validity_at( $session, $owner, $context, $at ); $this->assert_owned( $session, $owner, $prefix, $charset );
			if ( null === $after ) { self::refuse(); }
			$validity = QuoteCurrentEvidenceValidity::intersection( $at, $before, $after );
			if ( null === $validity ) { self::refuse(); }
			return FixedPromotionQuotePreparation::from_prepared( $set, $reservations, $guard, $validity, $at, $hold_until );
		} catch ( \Throwable $error ) {
			// Even a known later refusal is uncertain as a whole once any write was attempted.
			if ( ( null !== $tracked && $tracked->write_attempted() ) || $error instanceof OperationStorageException ) { throw new OperationStorageException(); }
			self::refuse();
		}
	}

	private function assert_owned( OperationSession $session, QuoteOwner $owner, string $prefix, string $charset ): void {
		if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $owner->site_id() || $session->table_prefix() !== $prefix || $session->charset_collate() !== $charset || '' === $charset || '' === $prefix || strlen( $prefix ) > 30 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { self::refuse(); }
	}
	private static function refuse(): never { throw new \DomainException( 'Promotion quote preparation is unavailable.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion preparation requires explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion preparation cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion preparation cannot be reconstructed implicitly.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_preparation_service' => 'redacted' ]; }
}
