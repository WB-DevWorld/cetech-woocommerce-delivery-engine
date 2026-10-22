<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness, OperationCoordinator, OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteOwner, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationPhaseObserver, OperationProfileRegistry, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, FixedPromotionBudgetOperationReadiness, OperationStoreSchema, TableNames};

/** Explicit internal acceptance. Existing budget holds remain unchanged; no native or payment mount. */
final readonly class FixedPromotionQuoteAcceptService implements \JsonSerializable {

	private \Closure $authorizer;
	private OperationReadiness $readiness;
	private OperationReadiness $current_readiness;
	private EmergencyControlStore $control;
	private QuotePrivatePublication $publication;

	public function __construct( private OperationConnectionFactory $factory, private FixedPromotionBudgetAccountStore $store, private FixedPromotionEligibilityBudgetGuard $eligibility, callable $authorize_owner, ?OperationReadiness $readiness = null, private ?OperationPhaseObserver $observer = null, ?EmergencyControlStore $control = null, ?QuotePrivatePublication $publication = null, ?OperationReadiness $current_readiness = null ) {
		$this->authorizer = \Closure::fromCallable( $authorize_owner ); $this->readiness = $readiness ?? new DatabaseOperationReadiness();
		$this->current_readiness = $current_readiness ?? new FixedPromotionBudgetOperationReadiness(); $this->control = $control ?? new EmergencyControlStore(); $this->publication = $publication ?? new QuoteAdvisoryPublication();
	}

	/** The deterministic command retains the original staged issue; no new capture or budget action occurs. */
	public function accept( FixedPromotionQuoteAcceptCommand $command, FixedPromotionQuoteAcceptInputs $inputs, RequestContext $request ): FixedPromotionQuoteAcceptResult {
		if ( ! self::supported( $command ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		if ( ! $this->authorized( $command->owner() ) ) { return $this->reject( $request, 'not_authorized' ); }
		try {
			$command = $command->as_recovery();
			$attempt = $this->coordinator( $command, $inputs )->attempt( $command->identity, $command, $request );
			return $this->finish( $attempt, $command, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $command->owner() ) ? $command : null ); }
	}

	/** Only recorded facts and their publication are reconciled; current proof and mutation are unavailable. */
	public function reconcile( FixedPromotionQuoteAcceptCommand $command, RequestContext $request ): FixedPromotionQuoteAcceptResult {
		if ( ! self::supported( $command ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		if ( ! $this->authorized( $command->owner() ) ) { return $this->reject( $request, 'not_authorized' ); }
		try {
			$command = $command->as_recovery();
			$attempt = $this->coordinator( $command, null )->reconcile( $command->identity, $command, $request );
			return $this->finish( $attempt, $command, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $command->owner() ) ? $command : null ); }
	}

	private function coordinator( FixedPromotionQuoteAcceptCommand $command, ?FixedPromotionQuoteAcceptInputs $inputs ): OperationCoordinator {
		$profile = new FixedPromotionQuoteAcceptProfile( bound: $command, authorize: $this->authorizer, inputs: $inputs, store: $this->store, eligibility: $this->eligibility, control: $this->control, publication: $this->publication, current_readiness: $this->current_readiness );
		return new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $this->factory, $this->readiness, $this->observer );
	}

	private function finish( OperationAttemptResult $attempt, FixedPromotionQuoteAcceptCommand $command, RequestContext $request ): FixedPromotionQuoteAcceptResult {
		if ( ! $this->authorized( $command->owner() ) ) { return $this->unknown( $request, null ); }
		$quote = null;
		if ( 'accepted' === $attempt->completion?->state ) {
			$quote = $this->acknowledge( $command, $attempt );
			if ( ! $this->authorized( $command->owner() ) ) { return $this->unknown( $request, null ); }
			// Accepted history is returned only after its exact physical row and both receipts agree.
			if ( null === $quote ) { return $this->unknown( $request, $command ); }
		}
		return new FixedPromotionQuoteAcceptResult( $attempt, $command, $quote );
	}

	/** Historical acknowledgement requires the original issue and acceptance, never current funding. */
	private function acknowledge( FixedPromotionQuoteAcceptCommand $expected, OperationAttemptResult $attempt ): ?QuoteStoredRow {
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
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock( $session, $expected->header(), null, [ 'issue', 'accept' ] ); $this->assert_reader_owned( $session, $expected->owner(), $binding, true );
			$quote = ( new DeliveryQuoteRepository( $session ) )->find_quote( $expected->header()->id(), true ); $this->assert_reader_owned( $session, $expected->owner(), $binding, true );
			if ( null === $quote || 'accepted' !== $quote->state() || 2 !== $quote->revision() || null === $quote->accepted_at() || 3 !== $quote->header()->format_version() || ! $quote->header()->owner()->equals( $expected->owner() ) || ! $quote->header()->matches_reference( $expected->reference() ) || $quote->header()->to_private_json() !== $expected->header()->to_private_json() || $quote->context()?->to_private_json() !== $expected->context()->to_private_json() || $quote->terms()?->to_private_json() !== $expected->terms()->to_private_json()
				|| ! $verifier->promotion_acceptance_verified( $quote, $records, $expected ) || ( $records['accept'] ?? null )?->completion?->to_json() !== $attempt->completion?->to_json() ) { return null; }
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
		if ( $session->is_retired() !== $retired || $session->in_transaction() !== $active || $owner->site_id() !== $binding[0] || $binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] ) { throw new \RuntimeException( 'Promotion acceptance history owner is unavailable.' ); }
	}
	private static function supported( FixedPromotionQuoteAcceptCommand $command ): bool {
		return 'delivery_quote.accept' === $command->identity->operation && 1 === $command->identity->operation_version && 3 === $command->header()->format_version() && FixedPromotionQuoteProvider::CODE === $command->header()->profile() && 1 === $command->header()->profile_version() && 'checkout' === $command->header()->purpose() && 2 === $command->issue()->identity->operation_version && null === $command->issue()->lease();
	}
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorizer )( $owner, 'delivery_quote.accept' ); } catch ( \Throwable ) { return false; } }
	private function reject( RequestContext $request, string $code ): FixedPromotionQuoteAcceptResult { return new FixedPromotionQuoteAcceptResult( new OperationAttemptResult( OperationOutcome::rejected( new ContractError( $code, $request, 'not_authorized' === $code ? 'contact_support' : 'retry_original_request' ) ) ) ); }
	private function unknown( RequestContext $request, ?FixedPromotionQuoteAcceptCommand $command ): FixedPromotionQuoteAcceptResult { return new FixedPromotionQuoteAcceptResult( new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ), $command ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion acceptance services require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion acceptance services cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion acceptance services require explicit trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_accept_service' => 'redacted' ]; }
}
