<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetAllocationSet, FixedPromotionBudgetRequest, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteMoney, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Whole-quote allocation lifecycle on one caller owner. It never completes the transaction. */
final readonly class FixedPromotionBudgetAllocationService implements \JsonSerializable {
	private const MAX_TABLES = 32;
	private \Closure $authorize;
	public function __construct( private FixedPromotionBudgetAccountStore $store, callable $authorize ) { $this->authorize = \Closure::fromCallable( $authorize ); }

	/** @return list<FixedPromotionBudgetReservation> */
	public function reserve_all( OperationSession $session, FixedPromotionBudgetAllocationSet $set, QuoteTime $at, QuoteTime $until ): array { return $this->apply( $session, $set, $at, 'reserve', $until ); }
	/** Allocation/redemption only; no saved-order, payment or settlement claim. */
	public function commit_all( OperationSession $session, FixedPromotionBudgetAllocationSet $set, QuoteTime $at ): array { return $this->apply( $session, $set, $at, 'commit' ); }
	/** Explicit current authorization only; expiry and quote retirement never call release. */
	public function release_all( OperationSession $session, FixedPromotionBudgetAllocationSet $set, QuoteTime $at ): array { return $this->apply( $session, $set, $at, 'release' ); }

	private function apply( OperationSession $session, FixedPromotionBudgetAllocationSet $set, QuoteTime $at, string $action, ?QuoteTime $until = null ): array {
		$prefix = $session->table_prefix(); $charset = $session->charset_collate();
		$tracked = new FixedPromotionBudgetWriteTrackingStore( $this->store, fn() => $this->owned( $session, $set, $prefix, $charset ) );
		try {
			$this->owned( $session, $set, $prefix, $charset ); $requests = $set->requests();
			foreach ( $requests as $request ) { $this->authorized( $session, $set, $request, $action, $at, $prefix, $charset ); }
			$this->tables( $session, $set, $prefix, $charset, $tracked );
			$accounts = [];
			// Acquire every account before reading any claim, including absent claim keys.
			foreach ( $set->account_keys() as $key ) { $accounts[$key] = $this->read( $session, $set, $prefix, $charset, fn() => $tracked->lock_account( $session, $key ) ); }
			foreach ( $accounts as $key => $account ) { if ( null === $account || $account->account_key() !== $key || $account->site_id() !== $set->owner()->site_id() ) { self::refuse(); } }
			$claims = [];
			foreach ( $requests as $request ) { $claims[$request->claim_key()] = $this->read( $session, $set, $prefix, $charset, fn() => $tracked->lock_reservation_for_account( $session, $request->claim_key(), $request->account_key() ) ); }
			$this->preflight( $requests, $accounts, $claims, $action, $at, $until );
			// Recheck the complete action set before the first write, not only each later member.
			foreach ( $requests as $request ) { $this->authorized( $session, $set, $request, $action, $at, $prefix, $charset ); }
			$service = new FixedPromotionBudgetService( $tracked, $this->authorize ); $result = [];
			foreach ( $requests as $request ) {
				$this->owned( $session, $set, $prefix, $charset );
				$result[] = match ( $action ) { 'reserve' => $service->reserve( $session, $request, $at, $until ), 'commit' => $service->commit( $session, $request, $at ), default => $service->release( $session, $request, $at ) };
				$this->owned( $session, $set, $prefix, $charset );
			}
			return $result;
		} catch ( \Throwable $error ) {
			if ( $tracked->write_attempted() || $error instanceof OperationStorageException ) { throw new OperationStorageException(); }
			self::refuse();
		}
	}

	/** Simulate every account transition and every claim's legal state before mutating one row. */
	private function preflight( array $requests, array $accounts, array $claims, string $action, QuoteTime $at, ?QuoteTime $until ): void {
		$projected = $accounts; $held = []; $spent = [];
		foreach ( $requests as $request ) {
			$key = $request->account_key(); $account = $accounts[$key]; $claim = $claims[$request->claim_key()]; $amount = $request->amount();
			if ( $account->funding_source() !== $request->funding_source() || $account->limit()->currency() !== $amount->currency() || $account->limit()->precision() !== $amount->precision() ) { self::refuse(); }
			if ( null !== $claim && ( ! $claim->matches( $request ) || $at->compare( $claim->changed_at() ) < 0 ) ) { self::refuse(); }
			if ( 'reserve' === $action ) {
				self::quote_time( $request, $at );
				if ( null === $until ) { self::refuse(); }
				if ( null === $claim ) { FixedPromotionBudgetReservation::hold( $request, $at, $until ); $projected[$key] = $projected[$key]->reserve( $amount ); }
				else { if ( ! $claim->is_held_at( $at ) || ! $claim->expires_at()->equals( $until ) ) { self::refuse(); } $held[$key] = self::sum( $held[$key] ?? null, $amount ); }
			} else {
				if ( null === $claim ) { self::refuse(); }
				$terminal = 'commit' === $action ? 'committed' : 'released';
				if ( $terminal === $claim->state() ) { if ( 'committed' === $terminal ) { $spent[$key] = self::sum( $spent[$key] ?? null, $amount ); } continue; }
				if ( 'held' !== $claim->state() ) { self::refuse(); }
				$held[$key] = self::sum( $held[$key] ?? null, $amount );
				if ( 'commit' === $action ) { self::quote_time( $request, $at ); $claim->commit( $at ); $projected[$key] = $projected[$key]->commit( $amount ); }
				else { $claim->release( $at ); $projected[$key] = $projected[$key]->release( $amount ); }
			}
		}
		foreach ( $held as $key => $amount ) { if ( $accounts[$key]->held()->compare( $amount ) < 0 ) { self::refuse(); } }
		foreach ( $spent as $key => $amount ) { if ( $accounts[$key]->spent()->compare( $amount ) < 0 ) { self::refuse(); } }
	}
	private static function sum( ?QuoteMoney $sum, QuoteMoney $amount ): QuoteMoney { return null === $sum ? $amount : $sum->add( $amount ); }
	private static function quote_time( FixedPromotionBudgetRequest $request, QuoteTime $at ): void { if ( $at->compare( $request->quote_created_at() ) < 0 || $at->compare( $request->quote_expires_at() ) >= 0 ) { self::refuse(); } }
	private function owned( OperationSession $session, FixedPromotionBudgetAllocationSet $set, string $prefix, string $charset ): void { if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $set->owner()->site_id() || $session->table_prefix() !== $prefix || $session->charset_collate() !== $charset || strlen( $prefix ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $prefix ) ) { self::refuse(); } }
	private function authorized( OperationSession $session, FixedPromotionBudgetAllocationSet $set, FixedPromotionBudgetRequest $request, string $action, QuoteTime $at, string $prefix, string $charset ): void { try { $allowed = true === ( $this->authorize )( $session, $request, $action, $at ); } catch ( \Throwable ) { $allowed = false; } if ( ! $allowed ) { self::refuse(); } $this->owned( $session, $set, $prefix, $charset ); }
	private function read( OperationSession $session, FixedPromotionBudgetAllocationSet $set, string $prefix, string $charset, callable $read ): mixed { try { $result = $read(); $this->owned( $session, $set, $prefix, $charset ); return $result; } catch ( \Throwable ) { throw new OperationStorageException(); } }
	private function tables( OperationSession $session, FixedPromotionBudgetAllocationSet $set, string $prefix, string $charset, FixedPromotionBudgetWriteTrackingStore $tracked ): void {
		$this->read( $session, $set, $prefix, $charset, function() use ( $session, $set, $prefix, $charset, $tracked ): void {
			$tables = $tracked->tables( $session ); $this->owned( $session, $set, $prefix, $charset );
			if ( ! array_is_list( $tables ) || [] === $tables || count( $tables ) > self::MAX_TABLES ) { throw new OperationStorageException(); }
			foreach ( $tables as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || ! str_starts_with( $table, $prefix ) || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) ) { throw new OperationStorageException(); } }
			$tables = array_values( array_unique( $tables ) ); sort( $tables, SORT_STRING ); if ( ! $session->validate_tables( $tables ) ) { throw new OperationStorageException(); }
		} );
	}
	private static function refuse(): never { throw new \DomainException( 'The promotion allocation batch is unavailable.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Promotion allocation services are private.' ); }
	public function __serialize(): array { throw new \LogicException( 'Promotion allocation services cannot be serialized implicitly.' ); }
	public function __unserialize( array $data ): never { throw new \LogicException( 'Promotion allocation services require their owned store.' ); }
	public function __debugInfo(): array { return [ 'private_promotion_budget_allocation_service' => 'redacted' ]; }
}
