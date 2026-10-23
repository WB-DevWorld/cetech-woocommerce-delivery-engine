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

	/** Current review of the exact original issued1 producer, without accepting or reserving again. */
	public function current_issued( FixedPromotionQuoteIssueCommand $command, FixedPromotionQuoteAcceptInputs $inputs, RequestContext $request ): ?FixedPromotionQuoteIssuedEvidence {
		$session = null; $closed = false;
		try {
			$command = $command->as_recovery();
			if ( ! $this->authorized( $command->owner() ) ) { return null; }
			$guard = FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $command->header(), $command->context(), $command->terms(), $inputs->original(), $inputs->fresh_native(), FixedPromotionBudgetCurrentAuthority::from_captured( $command->header(), $command->context(), $command->terms(), $command->account_slots(), $this->store, $this->eligibility ) );
			$session = $this->factory->open(); $pin = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			DeliveryQuoteSchema::tables( $pin[1] ); DeliveryQuoteSchema::charset_details( $pin[2] );
			$this->assert_owned( $session, $command->owner(), $pin, false );
			$this->readiness->assert_ready( $session ); $this->assert_owned( $session, $command->owner(), $pin, false );
			if ( ! $session->begin() ) { return null; } $this->assert_owned( $session, $command->owner(), $pin, true );
			$history = [ ...DeliveryQuoteSchema::tables( $pin[1] ), ...array_map( static fn( string $suffix ): string => $pin[1] . TableNames::PREFIX . $suffix, OperationStoreSchema::SUFFIXES ) ];
			$this->sealed_tables( $session, [ $history ], $pin[1] ); $this->assert_owned( $session, $command->owner(), $pin, true );
			// Authenticate provenance without a quote lock: acceptance locks current dependencies first.
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock( $session, $command->header(), null, [ 'issue' ] ); $this->assert_owned( $session, $command->owner(), $pin, true );
			$repository = new DeliveryQuoteRepository( $session ); $quote = $repository->find_quote( $command->header()->id(), false ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( null === $quote || ! $verifier->promotion_issue_verified( $quote, $records, $command ) ) { return null; }
			$issued_at = QuoteTime::parse( $records['issue']->completed_at );
			$this->control->assert_ready( $session, $command->owner()->site_id() ); $this->assert_owned( $session, $command->owner(), $pin, true );
			$guard_tables = $guard->tables( $session ); $this->assert_owned( $session, $command->owner(), $pin, true );
			$options = $this->control->options_table( $session ); $this->assert_owned( $session, $command->owner(), $pin, true );
			$this->sealed_tables( $session, [ $history, [ $options ], $guard_tables ], $pin[1] ); $this->assert_owned( $session, $command->owner(), $pin, true );
			$control = $this->control->current( $session ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( ! $control->enabled() || $control->site_id !== $command->owner()->site_id() ) { return null; }
			$this->current_readiness->assert_ready( $session ); $this->assert_owned( $session, $command->owner(), $pin, true );
			$at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( ! $this->issued_deadlines( $command, $at, $issued_at, $control->changed_at_epoch ) ) { return null; }
			$source = $guard->source_validity_at( $session, $command->owner(), $command->context(), $at ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( null === $source ) { return null; }
			$eligible = $this->eligibility->validity_for_requests_at( $session, $command->owner(), $command->header(), $command->context(), $command->terms(), $command->allocation_set()->requests(), $at ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( null === $eligible ) { return null; }
			$initial = QuoteCurrentEvidenceValidity::intersection( $at, $source, $eligible, QuoteCurrentEvidenceValidity::capture( $at, $command->header()->expires_at() ), QuoteCurrentEvidenceValidity::capture( $at, $command->hold_until() ) );
			if ( null === $initial || ! $this->issued_claims( $session, $command, $pin, $at ) ) { return null; }
			$proof_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( $proof_at->compare( $at ) < 0 || ! $initial->valid_at( $proof_at ) || ! $this->issued_deadlines( $command, $proof_at, $issued_at, $control->changed_at_epoch ) ) { return null; }
			$proof = $guard->validity_at( $session, $command->owner(), $command->context(), $proof_at ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( null === $proof || ! $this->issued_claims( $session, $command, $pin, $proof_at ) ) { return null; }
			$actual = $repository->find_quote( $command->header()->id(), true ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( null === $actual || $actual->row() !== $quote->row() || ! $verifier->promotion_issue_verified( $actual, $records, $command ) ) { return null; }
			$final_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( $final_at->compare( $proof_at ) < 0 || ! $this->issued_deadlines( $command, $final_at, $issued_at, $control->changed_at_epoch ) ) { return null; }
			$validity = QuoteCurrentEvidenceValidity::intersection( $final_at, $initial, $proof ); if ( null === $validity ) { return null; }
			if ( ! $session->rollback() ) { return null; } $this->assert_owned( $session, $command->owner(), $pin, false );
			if ( ! $session->retire() ) { return null; } $this->assert_owned( $session, $command->owner(), $pin, false, true );
			if ( ! $this->authorized( $command->owner() ) ) { return null; } $this->assert_owned( $session, $command->owner(), $pin, false, true );
			$evidence = new FixedPromotionQuoteIssuedEvidence( $command, $actual, $final_at, $validity );
			$closed = true; return $evidence;
		} catch ( \Throwable ) { return null; }
		finally {
			if ( null !== $session && ! $closed ) { try { $session->rollback(); } catch ( \Throwable ) {} try { $session->retire(); } catch ( \Throwable ) {} }
		}
	}
	private function issued_deadlines( FixedPromotionQuoteIssueCommand $command, QuoteTime $at, QuoteTime $issued_at, ?int $control_epoch ): bool { return $at->compare( $issued_at ) >= 0 && $command->header()->valid_at( $at ) && $at->compare( $command->hold_until() ) < 0 && $at->epoch_microseconds() >= ( $control_epoch ?? 0 ) * 1000000; }
	private function issued_claims( OperationSession $session, FixedPromotionQuoteIssueCommand $command, array $pin, QuoteTime $at ): bool {
		foreach ( $command->allocation_set()->account_keys() as $key ) {
			$account = $this->store->lock_account( $session, $key ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( null === $account || $account->site_id() !== $command->owner()->site_id() || $account->account_key() !== $key ) { return false; }
		}
		foreach ( $command->allocation_set()->requests() as $request ) {
			$claim = $this->store->lock_reservation_for_account( $session, $request->claim_key(), $request->account_key() ); $this->assert_owned( $session, $command->owner(), $pin, true );
			if ( null === $claim || ! $claim->matches( $request ) || 'held' !== $claim->state() || ! $claim->expires_at()->equals( $command->hold_until() ) || ! $claim->is_held_at( $at ) ) { return false; }
		}
		return true;
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


	/** Current private observations of the exact original seal; no native or payment admission. */
	public function current_sealed( FixedPromotionQuoteSealCommand $original, FixedPromotionQuoteVerifyInputs $current, int $expected_current_control_revision, RequestContext $request ): ?FixedPromotionQuoteSealedEvidence {
		$session = null; $closed = false;
		try {
			if ( $expected_current_control_revision < 1 ) { return null; }
			$original = $original->as_recovery();
			if ( ! $this->authorized( $original->owner() ) ) { return null; } $this->sealed_digest( $original, $current );
			$session = $this->factory->open(); $pin = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			DeliveryQuoteSchema::tables( $pin[1] ); DeliveryQuoteSchema::charset_details( $pin[2] );
			$this->sealed_owned( $session, $original, $current, $pin, false );
			$this->readiness->assert_ready( $session ); $this->sealed_owned( $session, $original, $current, $pin, false );
			// Begin may fail after touching the physical owner; cleanup still attempts rollback.
			if ( ! $session->begin() ) { return null; } $this->sealed_owned( $session, $original, $current, $pin, true );
			$history_tables = [ ...DeliveryQuoteSchema::tables( $pin[1] ), ...array_map( static fn( string $suffix ): string => $pin[1] . TableNames::PREFIX . $suffix, OperationStoreSchema::SUFFIXES ) ];
			$this->sealed_tables( $session, [ $history_tables ], $pin[1] ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock_promotion_seal( $session, $original ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$quote = $original->verification()->binding_command()->quote_record();
			$seal_record = $records['seal'] ?? null;
			if ( ! $seal_record instanceof \CetechDeliveryEngine\Domain\Operation\OperationRecord || 'accepted' !== $seal_record->state || null === $seal_record->completion || 'accepted' !== $seal_record->completion->state || null === $seal_record->completion->result || ! isset( $seal_record->completion->result['completed_at'] ) || ! is_int( $seal_record->completion->result['completed_at'] ) ) { return null; }
			$sealed_at = QuoteTime::from_epoch_microseconds( $seal_record->completion->result['completed_at'] );
			$sealed = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBinding::from_row( array_replace( $original->binding()->row(), [ 'sealed_at' => $sealed_at->sql() ] ), $quote );
			if ( ! $verifier->promotion_seal_verified( $quote, $records, $sealed, $original ) ) { return null; }
			// Authenticate all five immutable producers before asking current dependencies for proof.
			$this->control->assert_ready( $session, $original->owner()->site_id() ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$sources = $current->current()->sources();
			$guard = FixedPromotionQuoteCurrentEvidenceGuard::from_captured( $original->header(), $original->context(), $original->terms(), $sources->original(), $sources->fresh_native(), FixedPromotionBudgetCurrentAuthority::from_captured( $original->header(), $original->context(), $original->terms(), $original->account_slots(), $this->store, $this->eligibility ) );
			$guard_tables = $guard->tables( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$order_tables = $current->current()->order_evidence()->tables( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$saved_tables = $current->saved_evidence()->tables( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( count( $order_tables ) > 35 || count( $saved_tables ) > 35 ) { return null; }
			$options = $this->control->options_table( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$this->sealed_tables( $session, [ $history_tables, [ $options ], $guard_tables, $order_tables, $saved_tables ], $pin[1] ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$control = $this->control->current( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( ! $control->enabled() || $control->site_id !== $original->owner()->site_id() || $control->revision !== $expected_current_control_revision ) { return null; }
			$this->current_readiness->assert_ready( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			$at = QuoteOperationProfile::time( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( ! $this->sealed_deadlines( $original, $at, $sealed_at, $control->changed_at_epoch ) ) { return null; }
			$source = $guard->source_validity_at( $session, $original->owner(), $original->context(), $at ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( null === $source ) { return null; }
			$eligible = $this->eligibility->validity_for_requests_at( $session, $original->owner(), $original->header(), $original->context(), $original->terms(), $original->allocation_set()->requests(), $at ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( null === $eligible ) { return null; }
			$initial = QuoteCurrentEvidenceValidity::intersection( $at, $source, $eligible, QuoteCurrentEvidenceValidity::capture( $at, $original->header()->expires_at() ), QuoteCurrentEvidenceValidity::capture( $at, $original->hold_until() ) );
			if ( null === $initial || ! $this->sealed_claims( $session, $original, $current, $pin, $at ) || ! $this->sealed_order_saved( $session, $original, $current, $pin ) ) { return null; }
			$repository = new DeliveryQuoteRepository( $session ); $actual_quote = $repository->find_quote( $original->header()->id(), true ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( null === $actual_quote || $actual_quote->row() !== $quote->row() ) { return null; }
			$actual_binding = $repository->find_binding( $actual_quote, true ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( null === $actual_binding || $actual_binding->row() !== $sealed->row() || ! $verifier->promotion_seal_verified( $actual_quote, $records, $actual_binding, $original ) ) { return null; }
			$proof_at = QuoteOperationProfile::time( $session ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( $proof_at->compare( $at ) < 0 || ! $initial->valid_at( $proof_at ) || ! $this->sealed_deadlines( $original, $proof_at, $sealed_at, $control->changed_at_epoch ) ) { return null; }
			$proof = $guard->validity_at( $session, $original->owner(), $original->context(), $proof_at ); $this->sealed_owned( $session, $original, $current, $pin, true );
			if ( null === $proof || ! $this->sealed_claims( $session, $original, $current, $pin, $proof_at ) || ! $this->sealed_order_saved( $session, $original, $current, $pin ) || ! $this->authorized( $original->owner() ) ) { return null; }
			$this->sealed_owned( $session, $original, $current, $pin, true );
			$final_at = QuoteOperationProfile::time( $session ); $this->assert_owned( $session, $original->owner(), $pin, true );
			if ( $final_at->compare( $proof_at ) < 0 || ! $this->sealed_deadlines( $original, $final_at, $sealed_at, $control->changed_at_epoch ) ) { return null; }
			$validity = QuoteCurrentEvidenceValidity::intersection( $final_at, $initial, $proof ); if ( null === $validity ) { return null; }
			$evidence = new FixedPromotionQuoteSealedEvidence( $original, $actual_quote, $actual_binding, $control->revision, $final_at, $validity );
			if ( ! $session->rollback() ) { return null; } $this->assert_owned( $session, $original->owner(), $pin, false );
			if ( ! $session->retire() ) { return null; } $this->assert_owned( $session, $original->owner(), $pin, false, true );
			if ( ! $this->authorized( $original->owner() ) ) { return null; }
			$this->assert_owned( $session, $original->owner(), $pin, false, true );
			$closed = true; return $evidence;
		} catch ( \Throwable ) { return null; }
		finally {
			if ( null !== $session && ! $closed ) {
				try { $session->rollback(); } catch ( \Throwable ) {}
				try { $session->retire(); } catch ( \Throwable ) {}
			}
		}
	}
	private function sealed_digest( FixedPromotionQuoteSealCommand $command, FixedPromotionQuoteVerifyInputs $inputs ): void {
		if ( ! hash_equals( $command->verification()->binding_command()->order_evidence_digest(), $inputs->current()->order_evidence()->evidence_digest() ) ) { throw new \RuntimeException( 'Promotion sealed order evidence unavailable.' ); }
	}
	private function sealed_owned( OperationSession $session, FixedPromotionQuoteSealCommand $command, FixedPromotionQuoteVerifyInputs $inputs, array $pin, bool $active, bool $retired = false ): void {
		$this->assert_owned( $session, $command->owner(), $pin, $active, $retired ); $this->sealed_digest( $command, $inputs ); $this->assert_owned( $session, $command->owner(), $pin, $active, $retired );
	}
	private function sealed_tables( OperationSession $session, array $parts, string $prefix ): void {
		$tables = [];
		foreach ( $parts as $part ) {
			if ( ! array_is_list( $part ) || count( $part ) > 64 ) { throw new \RuntimeException( 'Promotion sealed tables unavailable.' ); }
			foreach ( $part as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $prefix ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new \RuntimeException( 'Promotion sealed tables unavailable.' ); } $tables[$table] = true; }
		}
		$names = array_keys( $tables ); sort( $names, SORT_STRING ); if ( count( $names ) > 64 || ! $session->validate_tables( $names ) ) { throw new \RuntimeException( 'Promotion sealed tables unavailable.' ); }
	}
	private function sealed_claims( OperationSession $session, FixedPromotionQuoteSealCommand $command, FixedPromotionQuoteVerifyInputs $inputs, array $pin, QuoteTime $at ): bool {
		foreach ( $command->allocation_set()->account_keys() as $key ) {
			$account = $this->store->lock_account( $session, $key ); $this->sealed_owned( $session, $command, $inputs, $pin, true );
			if ( null === $account || $account->site_id() !== $command->owner()->site_id() || $account->account_key() !== $key ) { return false; }
		}
		foreach ( $command->allocation_set()->requests() as $request ) {
			$claim = $this->store->lock_reservation_for_account( $session, $request->claim_key(), $request->account_key() ); $this->sealed_owned( $session, $command, $inputs, $pin, true );
			if ( null === $claim || ! $claim->matches( $request ) || 'held' !== $claim->state() || ! $claim->expires_at()->equals( $command->hold_until() ) || ! $claim->is_held_at( $at ) ) { return false; }
		}
		return true;
	}
	private function sealed_order_saved( OperationSession $session, FixedPromotionQuoteSealCommand $command, FixedPromotionQuoteVerifyInputs $inputs, array $pin ): bool {
		$valid = $inputs->current()->order_evidence()->verify( $session, $command->verification()->binding_command()->quote_record(), $command->verification()->prepared_binding() ); $this->sealed_owned( $session, $command, $inputs, $pin, true );
		if ( true !== $valid ) { return false; }
		$valid = $inputs->saved_evidence()->verify( $session, $command->verified_binding() ); $this->sealed_owned( $session, $command, $inputs, $pin, true ); return true === $valid;
	}
	private function sealed_deadlines( FixedPromotionQuoteSealCommand $command, QuoteTime $at, QuoteTime $sealed_at, ?int $control_epoch ): bool { return $at->compare( $sealed_at ) >= 0 && $command->header()->valid_at( $at ) && $at->compare( $command->hold_until() ) < 0 && $at->epoch_microseconds() >= ( $control_epoch ?? 0 ) * 1000000; }

	private function within_deadlines( FixedPromotionQuoteAcceptCommand $command, QuoteTime $at, QuoteTime $accepted_at, ?int $control_epoch ): bool { return $at->compare( $accepted_at ) >= 0 && $command->header()->valid_at( $at ) && $at->compare( $command->hold_until() ) < 0 && $at->epoch_microseconds() >= ( $control_epoch ?? 0 ) * 1000000; }
	private function assert_owned( OperationSession $session, QuoteOwner $owner, array $binding, bool $active, bool $retired = false ): void { if ( $session->is_retired() !== $retired || $session->in_transaction() !== $active || $owner->site_id() !== $binding[0] || $binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] ) { throw new \RuntimeException( 'Promotion placement evidence owner unavailable.' ); } }
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorizer )( $owner, 'delivery_quote.read' ); } catch ( \Throwable ) { return false; } }
	private function unavailable( string $reason = 'temporarily_unavailable' ): FixedPromotionQuotePlacementReadResult { return new FixedPromotionQuotePlacementReadResult( 'unavailable', $reason ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion placement readers require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion placement readers cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion placement readers require fresh trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_placement_reader' => 'redacted' ]; }
}
