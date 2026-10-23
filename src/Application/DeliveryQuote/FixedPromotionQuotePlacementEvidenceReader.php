<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetCurrentAuthority, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness, OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteOwner, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, FixedPromotionBudgetOperationReadiness, OperationStoreSchema, TableNames};

/** Observes accepted history and current holds. Returned facts never grant placement or payment. */
final readonly class FixedPromotionQuotePlacementEvidenceReader implements \JsonSerializable {

	private \Closure $authorizer;
	private OperationReadiness $readiness;
	private EmergencyControlStore $control;
	private OperationReadiness $current_readiness;

	public function __construct( private OperationConnectionFactory $factory, private FixedPromotionBudgetAccountStore $store, private FixedPromotionEligibilityBudgetGuard $eligibility, callable $authorize_owner, ?OperationReadiness $readiness = null, ?EmergencyControlStore $control = null, ?OperationReadiness $current_readiness = null ) {
		$this->authorizer = \Closure::fromCallable( $authorize_owner ); $this->readiness = $readiness ?? new DatabaseOperationReadiness();
		$this->control = $control ?? new EmergencyControlStore(); $this->current_readiness = $current_readiness ?? new FixedPromotionBudgetOperationReadiness();
	}

	/** A new owner performs each read; no transaction, lock or authority escapes its cleanup. */
	public function current( FixedPromotionQuoteAcceptCommand $command, FixedPromotionQuoteAcceptInputs $inputs, RequestContext $request ): FixedPromotionQuotePlacementReadResult {
		$session = null; $begun = false; $closed = false;
		try {
			if ( ! $this->authorized( $command->owner() ) ) { return $this->unavailable( 'not_authorized' ); }
			$command = $command->as_recovery();
			$guard = FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $command->header(), $command->context(), $command->terms(), $inputs->original(), $inputs->fresh_native(), FixedPromotionBudgetCurrentAuthority::from_captured( $command->header(), $command->context(), $command->terms(), $command->account_slots(), $this->store, $this->eligibility ) );
			$session = $this->factory->open(); $binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			DeliveryQuoteSchema::tables( $binding[1] ); DeliveryQuoteSchema::charset_details( $binding[2] );
			$this->assert_owned( $session, $command->owner(), $binding, false );
			$this->readiness->assert_ready( $session ); $this->assert_owned( $session, $command->owner(), $binding, false );
			if ( ! $session->begin() ) { return $this->unavailable(); } $begun = true; $this->assert_owned( $session, $command->owner(), $binding, true );
			$this->control->assert_ready( $session, $command->owner()->site_id() ); $this->assert_owned( $session, $command->owner(), $binding, true );
			$operation_prefix = $binding[1] . TableNames::PREFIX;
			$tables = [ $this->control->options_table( $session ), ...DeliveryQuoteSchema::tables( $binding[1] ), ...array_map( static fn( string $suffix ): string => $operation_prefix . $suffix, OperationStoreSchema::SUFFIXES ), ...$guard->tables( $session ) ];
			$this->assert_owned( $session, $command->owner(), $binding, true ); $tables = array_values( array_unique( $tables ) );
			if ( count( $tables ) > 64 ) { return $this->unavailable(); }
			foreach ( $tables as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $binding[1] ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { return $this->unavailable(); } }
			sort( $tables, SORT_STRING ); if ( ! $session->validate_tables( $tables ) ) { return $this->unavailable(); } $this->assert_owned( $session, $command->owner(), $binding, true );
			// All immutable operation namespaces precede current authority and quote locks.
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock( $session, $command->header(), null, [ 'issue', 'accept' ] ); $this->assert_owned( $session, $command->owner(), $binding, true );
			$control = $this->control->current( $session ); $this->assert_owned( $session, $command->owner(), $binding, true );
			if ( ! $control->enabled() || $control->site_id !== $command->owner()->site_id() ) { return $this->unavailable(); }
			$this->current_readiness->assert_ready( $session ); $this->assert_owned( $session, $command->owner(), $binding, true );
			$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session, $command->owner(), $binding, true );
			$source = $guard->source_validity_at( $session, $command->owner(), $command->context(), $at ); $this->assert_owned( $session, $command->owner(), $binding, true );
			if ( null === $source ) { return $this->unavailable(); }
			$eligible = $this->eligibility->validity_for_requests_at( $session, $command->owner(), $command->header(), $command->context(), $command->terms(), $command->allocation_set()->requests(), $at ); $this->assert_owned( $session, $command->owner(), $binding, true );
			if ( null === $eligible ) { return $this->unavailable(); }
			$initial = QuoteCurrentEvidenceValidity::intersection( $at, $source, $eligible, QuoteCurrentEvidenceValidity::capture( $at, $command->header()->expires_at() ), QuoteCurrentEvidenceValidity::capture( $at, $command->hold_until() ) );
			if ( null === $initial ) { return $this->unavailable(); }
			// Exact original claims are required, including original deadlines; current
			// aggregate/funding proof below does not permit an extended replacement.
			foreach ( $command->allocation_set()->account_keys() as $key ) {
				$account = $this->store->lock_account( $session, $key ); $this->assert_owned( $session, $command->owner(), $binding, true );
				if ( null === $account || $account->site_id() !== $command->owner()->site_id() || $account->account_key() !== $key ) { return $this->unavailable(); }
			}
			foreach ( $command->allocation_set()->requests() as $claim_request ) {
				$claim = $this->store->lock_reservation_for_account( $session, $claim_request->claim_key(), $claim_request->account_key() ); $this->assert_owned( $session, $command->owner(), $binding, true );
				if ( null === $claim || ! $claim->matches( $claim_request ) || 'held' !== $claim->state() || ! $claim->expires_at()->equals( $command->hold_until() ) || ! $claim->is_held_at( $at ) ) { return $this->unavailable(); }
			}
			$quote = ( new DeliveryQuoteRepository( $session ) )->find_quote( $command->header()->id(), true ); $this->assert_owned( $session, $command->owner(), $binding, true );
			if ( null === $quote || ! $verifier->promotion_acceptance_verified( $quote, $records, $command ) || null === $quote->accepted_at() ) { return $this->unavailable(); }
			$proof_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session, $command->owner(), $binding, true );
			if ( $proof_at->compare( $at ) < 0 || ! $initial->valid_at( $proof_at ) || ! $this->within_deadlines( $command, $proof_at, $quote->accepted_at(), $control->changed_at_epoch ) ) { return $this->unavailable(); }
			$proof = $guard->validity_at( $session, $command->owner(), $command->context(), $proof_at ); $this->assert_owned( $session, $command->owner(), $binding, true );
			if ( null === $proof || ! $this->authorized( $command->owner() ) ) { return $this->unavailable(); } $this->assert_owned( $session, $command->owner(), $binding, true );
			$final_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session, $command->owner(), $binding, true );
			if ( $final_at->compare( $proof_at ) < 0 || ! $this->within_deadlines( $command, $final_at, $quote->accepted_at(), $control->changed_at_epoch ) ) { return $this->unavailable(); }
			$validity = QuoteCurrentEvidenceValidity::intersection( $final_at, $initial, $proof ); if ( null === $validity ) { return $this->unavailable(); }
			$evidence = new FixedPromotionQuotePlacementEvidence( $command, $quote, $final_at, $validity, $guard );
			if ( ! $session->rollback() ) { return $this->unavailable(); } $begun = false; $this->assert_owned( $session, $command->owner(), $binding, false );
			if ( ! $session->retire() ) { return $this->unavailable(); } $this->assert_owned( $session, $command->owner(), $binding, false, true );
			if ( ! $this->authorized( $command->owner() ) ) { return $this->unavailable( 'not_authorized' ); }
			$this->assert_owned( $session, $command->owner(), $binding, false, true );
			$closed = true;
			return new FixedPromotionQuotePlacementReadResult( 'ready', null, $evidence );
		} catch ( \Throwable ) { return $this->unavailable(); }
		finally {
			if ( null !== $session && ! $closed ) {
				// A failed metadata probe must not prevent attempting physical cleanup.
				if ( $begun ) { try { $session->rollback(); } catch ( \Throwable ) {} }
				try { $session->retire(); } catch ( \Throwable ) {}
			}
		}
	}

	private function within_deadlines( FixedPromotionQuoteAcceptCommand $command, QuoteTime $at, QuoteTime $accepted_at, ?int $control_epoch ): bool { return $at->compare( $accepted_at ) >= 0 && $command->header()->valid_at( $at ) && $at->compare( $command->hold_until() ) < 0 && $at->epoch_microseconds() >= ( $control_epoch ?? 0 ) * 1000000; }
	private function assert_owned( OperationSession $session, QuoteOwner $owner, array $binding, bool $active, bool $retired = false ): void { if ( $session->is_retired() !== $retired || $session->in_transaction() !== $active || $owner->site_id() !== $binding[0] || $binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] ) { throw new \RuntimeException( 'Promotion placement evidence owner unavailable.' ); } }
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorizer )( $owner, 'delivery_quote.read' ); } catch ( \Throwable ) { return false; } }
	private function unavailable( string $reason = 'temporarily_unavailable' ): FixedPromotionQuotePlacementReadResult { return new FixedPromotionQuotePlacementReadResult( 'unavailable', $reason ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion placement readers require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion placement readers cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion placement readers require fresh trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_placement_reader' => 'redacted' ]; }
}
