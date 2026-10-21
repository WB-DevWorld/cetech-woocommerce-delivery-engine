<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Application\DeliveryPromotion;

use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationSession;

/** Explicit allocation lifecycle in a caller-owned transaction, never payment authority. */
final readonly class FixedPromotionBudgetService {
	private const MAX_TABLES = 32;
	private \Closure $authorize;
	/** The trusted callback verifies current same-owner authority and eligibility for this action. */
	public function __construct( private FixedPromotionBudgetStore $store, callable $authorize ) { $this->authorize = \Closure::fromCallable( $authorize ); }

	public function reserve( OperationSession $session, FixedPromotionBudgetRequest $request, QuoteTime $at, QuoteTime $until ): FixedPromotionBudgetReservation {
		$prefix = $session->table_prefix(); $this->assert_session( $session, $request, $prefix ); self::quote_time( $request, $at );
		$this->authorize( $session, $request, 'reserve', $at, $prefix ); $this->tables( $session, $request, $prefix );
		[ $account, $claim ] = $this->locked( $session, $request, $prefix );
		if ( null !== $claim ) {
			if ( ! $claim->matches( $request ) || 'held' !== $claim->state() || ! $claim->expires_at()->equals( $until ) || ! $claim->is_held_at( $at ) || $at->compare( $claim->changed_at() ) < 0 || $account->held()->compare( $request->amount() ) < 0 ) { self::refuse(); }
			return $claim;
		}
		$claim = FixedPromotionBudgetReservation::hold( $request, $at, $until ); $next = $account->reserve( $request->amount() );
		$this->authorize( $session, $request, 'reserve', $at, $prefix );
		$this->write( $session, $request, $prefix, fn(): bool => $this->store->replace_account( $session, $account, $next ), fn(): bool => $this->store->insert_reservation( $session, $claim ) );
		return $claim;
	}

	/** Commit allocates campaign budget; it does not assert payment, settlement or a saved order. */
	public function commit( OperationSession $session, FixedPromotionBudgetRequest $request, QuoteTime $at ): FixedPromotionBudgetReservation { return $this->transition( $session, $request, $at, 'commit' ); }
	/** Expiry does not call this method. A trusted explicit release decision is always required. */
	public function release( OperationSession $session, FixedPromotionBudgetRequest $request, QuoteTime $at ): FixedPromotionBudgetReservation { return $this->transition( $session, $request, $at, 'release' ); }

	private function transition( OperationSession $session, FixedPromotionBudgetRequest $request, QuoteTime $at, string $action ): FixedPromotionBudgetReservation {
		$prefix = $session->table_prefix(); $this->assert_session( $session, $request, $prefix ); $this->authorize( $session, $request, $action, $at, $prefix ); $this->tables( $session, $request, $prefix );
		[ $account, $claim ] = $this->locked( $session, $request, $prefix );
		if ( null === $claim || ! $claim->matches( $request ) || $at->compare( $claim->changed_at() ) < 0 ) { self::refuse(); }
		$terminal = 'commit' === $action ? 'committed' : 'released';
		if ( $terminal === $claim->state() ) {
			if ( 'committed' === $terminal && $account->spent()->compare( $request->amount() ) < 0 ) { self::refuse(); }
			return $claim;
		}
		if ( 'held' !== $claim->state() || $account->held()->compare( $request->amount() ) < 0 ) { self::refuse(); }
		if ( 'commit' === $action ) { self::quote_time( $request, $at ); if ( ! $claim->is_held_at( $at ) ) { self::refuse(); } }
		$next_claim = 'commit' === $action ? $claim->commit( $at ) : $claim->release( $at );
		$next_account = 'commit' === $action ? $account->commit( $request->amount() ) : $account->release( $request->amount() );
		$this->authorize( $session, $request, $action, $at, $prefix );
		$this->write( $session, $request, $prefix, fn(): bool => $this->store->replace_account( $session, $account, $next_account ), fn(): bool => $this->store->replace_reservation( $session, $claim, $next_claim ) );
		return $next_claim;
	}

	private function locked( OperationSession $session, FixedPromotionBudgetRequest $request, string $prefix ): array {
		try {
			$account = $this->store->lock_account( $session, $request->account_key() ); $this->assert_session( $session, $request, $prefix );
			$claim = null === $account ? null : $this->store->lock_reservation( $session, $request->claim_key() ); $this->assert_session( $session, $request, $prefix );
		}
		catch ( \Throwable ) { throw new OperationStorageException(); }
		if ( null === $account || $account->site_id() !== $request->site_id() || $account->account_key() !== $request->account_key() || $account->funding_source() !== $request->funding_source()
			|| $account->limit()->currency() !== $request->amount()->currency() || $account->limit()->precision() !== $request->amount()->precision() ) { self::refuse(); }
		return [ $account, $claim ];
	}
	private function tables( OperationSession $session, FixedPromotionBudgetRequest $request, string $prefix ): void {
		$this->assert_session( $session, $request, $prefix );
		try {
			$tables = $this->store->tables( $session ); $this->assert_session( $session, $request, $prefix );
			if ( ! array_is_list( $tables ) || [] === $tables || count( $tables ) > self::MAX_TABLES ) { throw new OperationStorageException(); }
			foreach ( $tables as $table ) { if ( ! is_string( $table ) || strlen( $table ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $table ) || ! str_starts_with( $table, $session->table_prefix() ) ) { throw new OperationStorageException(); } }
			$tables = array_values( array_unique( $tables ) ); sort( $tables, SORT_STRING );
			if ( ! $session->validate_tables( $tables ) ) { throw new OperationStorageException(); } $this->assert_session( $session, $request, $prefix );
		} catch ( \Throwable ) { throw new OperationStorageException(); }
	}
	/** Once either write is attempted, no failure can be treated as a clean business refusal. */
	private function write( OperationSession $session, FixedPromotionBudgetRequest $request, string $prefix, callable $first, callable $second ): void {
		$this->assert_session( $session, $request, $prefix );
		try {
			if ( true !== $first() ) { throw new OperationStorageException(); } $this->assert_session( $session, $request, $prefix );
			if ( true !== $second() ) { throw new OperationStorageException(); } $this->assert_session( $session, $request, $prefix );
		}
		catch ( \Throwable ) { throw new OperationStorageException(); }
	}
	private function authorize( OperationSession $session, FixedPromotionBudgetRequest $request, string $action, QuoteTime $at, string $prefix ): void {
		try { $authorized = true === ( $this->authorize )( $session, $request, $action, $at ); } catch ( \Throwable ) { $authorized = false; }
		if ( ! $authorized ) { self::refuse(); } $this->assert_session( $session, $request, $prefix );
	}
	private function assert_session( OperationSession $session, FixedPromotionBudgetRequest $request, string $prefix ): void { if ( $session->is_retired() || ! $session->in_transaction() || $session->site_id() !== $request->site_id() || $session->table_prefix() !== $prefix || strlen( $session->table_prefix() ) > 64 || 1 !== preg_match( '/\A[a-zA-Z0-9_]+\z/D', $session->table_prefix() ) ) { self::refuse(); } }
	private static function quote_time( FixedPromotionBudgetRequest $request, QuoteTime $at ): void { if ( $at->compare( $request->quote_created_at() ) < 0 || $at->compare( $request->quote_expires_at() ) >= 0 ) { self::refuse(); } }
	private static function refuse(): never { throw new \DomainException( 'The promotion budget operation is unavailable.' ); }
}
