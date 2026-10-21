<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard, FixedPromotionQuotePreparationService};
use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness, OperationCoordinator, OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteHeader, QuoteId, QuoteOwner, QuoteReference, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationPhaseObserver, OperationProfileRegistry, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, FixedPromotionBudgetOperationReadiness, OperationStoreSchema, TableNames};

/** Explicit internal issued-3 owner. Nothing mounts this service into native checkout. */
final readonly class FixedPromotionQuoteIssueService implements \JsonSerializable {

	private \Closure $authorizer;
	private OperationReadiness $readiness;
	private OperationReadiness $current_readiness;
	private EmergencyControlStore $control;
	private QuotePrivatePublication $publication;
	private FixedPromotionQuotePreparationService $preparation;

	public function __construct( private OperationConnectionFactory $factory, private QuoteProviderRegistry $providers, private FixedPromotionBudgetAccountStore $store, private FixedPromotionEligibilityBudgetGuard $eligibility, callable $authorize_owner, callable $authorize_budget, ?OperationReadiness $readiness = null, private ?OperationPhaseObserver $observer = null, ?EmergencyControlStore $control = null, ?QuotePrivatePublication $publication = null, ?OperationReadiness $current_readiness = null ) {
		$this->authorizer = \Closure::fromCallable( $authorize_owner ); $this->readiness = $readiness ?? new DatabaseOperationReadiness();
		$this->current_readiness = $current_readiness ?? new FixedPromotionBudgetOperationReadiness(); $this->control = $control ?? new EmergencyControlStore(); $this->publication = $publication ?? new QuoteAdvisoryPublication();
		$this->preparation = new FixedPromotionQuotePreparationService( $store, $eligibility, $this->current_readiness, $authorize_budget );
	}

	/** The exact original recovery envelope must be acknowledged before C03 opens an owner. */
	public function issue_admitted( QuoteIssueCommand $original, QuoteAdmissionLease $lease, FixedPromotionQuoteIssueInputs $inputs, RequestContext $request, callable $stage ): FixedPromotionQuoteIssueResult {
		if ( ! self::supported( $original ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		if ( ! $this->authorized( $original->owner() ) ) { return $this->reject( $request, 'not_authorized' ); }
		if ( ! $lease->matches( $original ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		$recovery = null;
		try {
			$this->providers->get( $original->provider_code(), $original->provider_version(), $original->profile(), $original->profile_version() );
			if ( ! $lease->claim_capture() ) { return new FixedPromotionQuoteIssueResult( new OperationAttemptResult( OperationOutcome::pending() ) ); }
			// Frozen native evidence is captured once before any C03 reservation or control lock.
			$terms = $this->providers->capture( $original->provider_code(), $original->provider_version(), $original->profile(), $original->profile_version(), $original->context() );
			$id = QuoteId::generate(); $reference = QuoteReference::generate( $id );
			$header = QuoteHeader::issue( $id, $original->owner(), $original->context(), $terms, $lease->created_at(), $original->namespace_hashes( $id ), $original->profile(), $original->profile_version(), $reference );
			$captured = FixedPromotionQuoteIssueCommand::captured( $original, $lease, DeliveryQuote::issue( $header, $original->context(), $terms ), $reference );
			$recovery = $captured->as_recovery();
			if ( ! $this->authorized( $original->owner() ) ) { return $this->unknown( $request, null ); }
			if ( true !== $stage( $recovery ) || ! $this->authorized( $original->owner() ) ) { return $this->unknown( $request, $this->authorized( $original->owner() ) ? $recovery : null ); }
			$attempt = $this->coordinator( $captured, $inputs )->attempt( $captured->identity, $captured, $request );
			return $this->finish( $attempt, $recovery, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $original->owner() ) ? $recovery : null ); }
	}

	/** Recovery only reconciles the original C03 receipt, without capture or allocation. */
	public function reconcile( FixedPromotionQuoteIssueCommand $recovery, RequestContext $request ): FixedPromotionQuoteIssueResult {
		if ( ! $this->authorized( $recovery->owner() ) ) { return $this->reject( $request, 'not_authorized' ); }
		try {
			$recovery = $recovery->as_recovery();
			$attempt = $this->coordinator( $recovery, null )->reconcile( $recovery->identity, $recovery, $request );
			return $this->finish( $attempt, $recovery, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $recovery->owner() ) ? $recovery : null ); }
	}

	private function coordinator( FixedPromotionQuoteIssueCommand $command, ?FixedPromotionQuoteIssueInputs $inputs ): OperationCoordinator {
		$profile = new FixedPromotionQuoteIssueProfile( bound: $command, authorize: $this->authorizer, preparation: $this->preparation, inputs: $inputs, store: $this->store, eligibility: $this->eligibility, control: $this->control, publication: $this->publication, current_readiness: $this->current_readiness );
		return new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $this->factory, $this->readiness, $this->observer );
	}

	private function finish( OperationAttemptResult $attempt, FixedPromotionQuoteIssueCommand $recovery, RequestContext $request ): FixedPromotionQuoteIssueResult {
		if ( ! $this->authorized( $recovery->owner() ) ) { return $this->unknown( $request, null ); }
		$quote = null;
		if ( 'accepted' === $attempt->completion?->state ) {
			$quote = $this->acknowledge( $recovery, $attempt );
			if ( ! $this->authorized( $recovery->owner() ) ) { return $this->unknown( $request, null ); }
			// Never retain an accepted attempt when exact physical acknowledgment failed.
			if ( null === $quote ) { return $this->unknown( $request, $recovery ); }
		}
		return new FixedPromotionQuoteIssueResult( $attempt, $recovery, $quote );
	}

	/** Exact detached issued history, deliberately independent of current expiry or held funds. */
	private function acknowledge( FixedPromotionQuoteIssueCommand $expected, OperationAttemptResult $attempt ): ?QuoteStoredRow {
		$session = null; $begun = false;
		try {
			if ( ! $this->authorized( $expected->owner() ) ) { return null; }
			$session = $this->factory->open();
			$binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			DeliveryQuoteSchema::tables( $binding[1] ); DeliveryQuoteSchema::charset_details( $binding[2] );
			$this->assert_reader_owned( $session, $expected->owner(), $binding, false );
			$this->readiness->assert_ready( $session ); $this->assert_reader_owned( $session, $expected->owner(), $binding, false );
			if ( ! $session->begin() ) { return null; } $begun = true; $this->assert_reader_owned( $session, $expected->owner(), $binding, true );
			$prefix = $binding[1]; $operation_prefix = $prefix . TableNames::PREFIX;
			$tables = [ ...DeliveryQuoteSchema::tables( $prefix ), ...array_map( static fn( string $suffix ): string => $operation_prefix . $suffix, OperationStoreSchema::SUFFIXES ) ];
			if ( ! $session->validate_tables( $tables ) ) { return null; } $this->assert_reader_owned( $session, $expected->owner(), $binding, true );
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock( $session, $expected->header(), null, [ 'issue' ] ); $this->assert_reader_owned( $session, $expected->owner(), $binding, true );
			$quote = ( new DeliveryQuoteRepository( $session ) )->find_quote( $expected->header()->id(), true ); $this->assert_reader_owned( $session, $expected->owner(), $binding, true );
			if ( null === $quote || 'issued' !== $quote->state() || 1 !== $quote->revision() || null !== $quote->accepted_at() || 3 !== $quote->header()->format_version() || ! $quote->header()->owner()->equals( $expected->owner() ) || ! $quote->header()->matches_reference( $expected->reference() ) || $quote->header()->to_private_json() !== $expected->header()->to_private_json() || $quote->context()?->to_private_json() !== $expected->context()->to_private_json() || $quote->terms()?->to_private_json() !== $expected->terms()->to_private_json()
				|| ! $verifier->promotion_issue_verified( $quote, $records, $expected ) || ( $records['issue'] ?? null )?->completion?->to_json() !== $attempt->completion?->to_json() ) { return null; }
			$this->assert_reader_owned( $session, $expected->owner(), $binding, true ); if ( ! $session->rollback() ) { return null; }
			$begun = false;
			$this->assert_reader_owned( $session, $expected->owner(), $binding, false ); if ( ! $session->retire() ) { return null; }
			$this->assert_reader_owned( $session, $expected->owner(), $binding, false, true ); if ( ! $this->authorized( $expected->owner() ) ) { return null; }
			return $quote;
		} catch ( \Throwable ) { return null; }
		finally {
			if ( null !== $session ) {
				if ( $begun && ! $session->is_retired() ) { try { $session->rollback(); } catch ( \Throwable ) {} }
				if ( ! $session->is_retired() ) { try { $session->retire(); } catch ( \Throwable ) {} }
			}
		}
	}
	private function assert_reader_owned( OperationSession $session, QuoteOwner $owner, array $binding, bool $active, bool $retired = false ): void {
		if ( $session->is_retired() !== $retired || $session->in_transaction() !== $active || $owner->site_id() !== $binding[0] || $binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] ) { throw new \RuntimeException( 'Promotion history owner is unavailable.' ); }
	}

	private static function supported( QuoteIssueCommand $original ): bool {
		return 2 === $original->identity()->operation_version && 'delivery_quote.issue' === $original->identity()->operation && FixedPromotionQuoteProvider::CODE === $original->provider_code() && FixedPromotionQuoteProvider::CODE === $original->profile() && 1 === $original->provider_version() && 1 === $original->profile_version() && 3 === $original->context()->format_version() && 'checkout' === $original->context()->private_facts()['kind'] && $original->context()->material_evidence_available() && null !== $original->promotion_account_slots() && null !== $original->promotion_hold_until();
	}
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorizer )( $owner, 'delivery_quote.issue' ); } catch ( \Throwable ) { return false; } }
	private function reject( RequestContext $request, string $code ): FixedPromotionQuoteIssueResult { return new FixedPromotionQuoteIssueResult( new OperationAttemptResult( OperationOutcome::rejected( new ContractError( $code, $request, 'not_authorized' === $code ? 'contact_support' : 'retry_original_request' ) ) ) ); }
	private function unknown( RequestContext $request, ?FixedPromotionQuoteIssueCommand $recovery ): FixedPromotionQuoteIssueResult { return new FixedPromotionQuoteIssueResult( new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ), $recovery ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion issue services require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion issue services cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion issue services require explicit trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_issue_service' => 'redacted' ]; }
}
