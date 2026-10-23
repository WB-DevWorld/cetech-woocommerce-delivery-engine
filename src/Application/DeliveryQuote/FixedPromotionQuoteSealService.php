<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\Operation\{DatabaseOperationReadiness, OperationCoordinator, OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationConnectionFactory, OperationPhaseObserver, OperationProfileRegistry, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, FixedPromotionBudgetOperationReadiness, OperationStoreSchema, TableNames};

/** Explicit internal verified-binding seal. It neither spends held funds nor grants native payment admission. */
final readonly class FixedPromotionQuoteSealService implements \JsonSerializable {
	private \Closure $authorizer;
	private OperationReadiness $readiness;
	private OperationReadiness $current_readiness;
	private EmergencyControlStore $control;
	private QuotePrivatePublication $publication;

	public function __construct( private OperationConnectionFactory $factory, private FixedPromotionBudgetAccountStore $store, private FixedPromotionEligibilityBudgetGuard $eligibility, callable $authorize_owner, ?OperationReadiness $readiness = null, private ?OperationPhaseObserver $observer = null, ?EmergencyControlStore $control = null, ?QuotePrivatePublication $publication = null, ?OperationReadiness $current_readiness = null ) {
		$this->authorizer = \Closure::fromCallable( $authorize_owner ); $this->readiness = $readiness ?? new DatabaseOperationReadiness();
		$this->current_readiness = $current_readiness ?? new FixedPromotionBudgetOperationReadiness(); $this->control = $control ?? new EmergencyControlStore(); $this->publication = $publication ?? new QuoteAdvisoryPublication();
	}

	public function seal( FixedPromotionQuoteSealCommand $command, FixedPromotionQuoteVerifyInputs $inputs, RequestContext $request ): FixedPromotionQuoteSealResult {
		if ( ! self::supported( $command ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		if ( ! $this->authorized( $command->owner() ) ) { return $this->reject( $request, 'not_authorized' ); }
		try {
			$command = $command->as_recovery();
			$attempt = $this->coordinator( $command, $inputs )->attempt( $command->identity, $command, $request );
			return $this->finish( $attempt, $command, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $command->owner() ) ? $command : null ); }
	}

	/** Reconcile immutable completion without current source/order/saved/funding/control/clock ports. */
	public function reconcile( FixedPromotionQuoteSealCommand $command, RequestContext $request ): FixedPromotionQuoteSealResult {
		if ( ! self::supported( $command ) ) { return $this->reject( $request, 'temporarily_unavailable' ); }
		if ( ! $this->authorized( $command->owner() ) ) { return $this->reject( $request, 'not_authorized' ); }
		try {
			$command = $command->as_recovery();
			$attempt = $this->coordinator( $command, null )->reconcile( $command->identity, $command, $request );
			return $this->finish( $attempt, $command, $request );
		} catch ( \Throwable ) { return $this->unknown( $request, $this->authorized( $command->owner() ) ? $command : null ); }
	}

	private function coordinator( FixedPromotionQuoteSealCommand $command, ?FixedPromotionQuoteVerifyInputs $inputs ): OperationCoordinator {
		$profile = new FixedPromotionQuoteSealProfile( bound: $command, authorize: $this->authorizer, inputs: $inputs, store: $this->store, eligibility: $this->eligibility, control: $this->control, publication: $this->publication, current_readiness: $this->current_readiness );
		return new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $this->factory, $this->readiness, $this->observer );
	}

	private function finish( OperationAttemptResult $attempt, FixedPromotionQuoteSealCommand $command, RequestContext $request ): FixedPromotionQuoteSealResult {
		if ( ! $this->authorized( $command->owner() ) ) { return $this->unknown( $request, null ); }
		$facts = null;
		if ( 'accepted' === $attempt->completion?->state ) {
			$facts = $this->acknowledge( $command, $attempt );
			if ( null === $facts ) { return $this->unknown( $request, $this->authorized( $command->owner() ) ? $command : null ); }
			// The acknowledged success ends at its final pinned authorization check.
			// No callback may run after that check before these pure result values escape.
		}
		return new FixedPromotionQuoteSealResult( $attempt, $command, $facts[0] ?? null, $facts[1] ?? null );
	}

	/** Historical acknowledgement is independent of today's order, policy and held-budget availability. */
	private function acknowledge( FixedPromotionQuoteSealCommand $expected, OperationAttemptResult $attempt ): ?array {
		$session = null; $begun = false; $closed = false;
		try {
			if ( ! $this->authorized( $expected->owner() ) ) { return null; }
			$session = $this->factory->open(); $binding = [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ];
			DeliveryQuoteSchema::tables( $binding[1] ); DeliveryQuoteSchema::charset_details( $binding[2] );
			$this->assert_owned( $session, $expected->owner(), $binding, false );
			$this->readiness->assert_ready( $session ); $this->assert_owned( $session, $expected->owner(), $binding, false );
			if ( ! $session->begin() ) { return null; } $begun = true; $this->assert_owned( $session, $expected->owner(), $binding, true );
			$tables = [ ...DeliveryQuoteSchema::tables( $binding[1] ), ...array_map( static fn( string $suffix ): string => $binding[1] . TableNames::PREFIX . $suffix, OperationStoreSchema::SUFFIXES ) ];
			if ( ! $session->validate_tables( $tables ) ) { return null; } $this->assert_owned( $session, $expected->owner(), $binding, true );
			$verifier = new QuoteReceiptVerifier(); $records = $verifier->lock_promotion_seal( $session, $expected ); $this->assert_owned( $session, $expected->owner(), $binding, true );
			$repo = new DeliveryQuoteRepository( $session ); $quote = $repo->find_quote( $expected->header()->id(), true ); $this->assert_owned( $session, $expected->owner(), $binding, true );
			if ( null === $quote ) { return null; }
			$sealed = $repo->find_binding( $quote, true ); $this->assert_owned( $session, $expected->owner(), $binding, true );
			if ( null === $sealed || ! $verifier->promotion_seal_verified( $quote, $records, $sealed, $expected ) || ( $records['seal'] ?? null )?->completion?->to_json() !== $attempt->completion?->to_json() ) { return null; }
			if ( ! $session->rollback() ) { return null; } $begun = false; $this->assert_owned( $session, $expected->owner(), $binding, false );
			if ( ! $session->retire() ) { return null; } $this->assert_owned( $session, $expected->owner(), $binding, false, true );
			if ( ! $this->authorized( $expected->owner() ) ) { return null; } $this->assert_owned( $session, $expected->owner(), $binding, false, true );
			$closed = true; return [ $quote, $sealed ];
		} catch ( \Throwable ) { return null; }
		finally {
			if ( null !== $session && ! $closed ) {
				if ( $begun ) { try { $session->rollback(); } catch ( \Throwable ) {} }
				try { $session->retire(); } catch ( \Throwable ) {}
			}
		}
	}

	private function assert_owned( OperationSession $session, QuoteOwner $owner, array $binding, bool $active, bool $retired = false ): void {
		if ( $session->is_retired() !== $retired || $session->in_transaction() !== $active || $owner->site_id() !== $binding[0] || $binding !== [ $session->site_id(), $session->table_prefix(), $session->charset_collate() ] ) { throw new \RuntimeException( 'Promotion seal history owner unavailable.' ); }
	}
	private static function supported( FixedPromotionQuoteSealCommand $command ): bool {
		return 'delivery_quote.seal' === $command->identity->operation && 2 === $command->identity->operation_version && 3 === $command->header()->format_version() && FixedPromotionQuoteProvider::CODE === $command->header()->profile() && 1 === $command->header()->profile_version() && 'checkout' === $command->header()->purpose() && 2 === $command->verification()->binding_command()->acceptance()->issue()->identity->operation_version && null === $command->verification()->binding_command()->acceptance()->issue()->lease();
	}
	private function authorized( QuoteOwner $owner ): bool { try { return true === ( $this->authorizer )( $owner, 'delivery_quote.seal' ); } catch ( \Throwable ) { return false; } }
	private function reject( RequestContext $request, string $code ): FixedPromotionQuoteSealResult { return new FixedPromotionQuoteSealResult( new OperationAttemptResult( OperationOutcome::rejected( new ContractError( $code, $request, 'not_authorized' === $code ? 'contact_support' : 'retry_original_request' ) ) ) ); }
	private function unknown( RequestContext $request, ?FixedPromotionQuoteSealCommand $command ): FixedPromotionQuoteSealResult { return new FixedPromotionQuoteSealResult( new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', $request, 'reconcile_original_request' ) ) ), $command ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion seal services require explicit private access.' ); }
	public function __serialize(): never { throw new \LogicException( 'Promotion seal services cannot be persisted.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion seal services require explicit trusted ports.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_seal_service' => 'redacted' ]; }
}
