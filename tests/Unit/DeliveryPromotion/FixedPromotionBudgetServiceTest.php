<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetService, FixedPromotionBudgetStore};
use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteProvider;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetRequest, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteMoney, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Synthetic owner/store proves orchestration and caller rollback, not durable SQL concurrency. */
final class FixedPromotionBudgetServiceTest extends TestCase {
	private static FixedPromotionQuoteProvider $provider;
	private FixedPromotionBudgetRequest $request;
	private SyntheticPromotionBudgetStore $store;
	private SyntheticPromotionBudgetSession $session;
	private array $authority = [];
	private bool $allowed = true;
	public static function setUpBeforeClass(): void { self::$provider = F::provider(); }
	protected function setUp(): void {
		$this->request = $this->request();
		$this->store = new SyntheticPromotionBudgetStore( FixedPromotionBudgetState::create( $this->request->site_id(), $this->request->account_key(), $this->request->funding_source(), self::money( '6.00' ) ) );
		$this->session = new SyntheticPromotionBudgetSession( $this->store ); $this->session->begin();
	}
	private static function money( string $amount, string $currency = 'GHS', int $precision = 2 ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $currency, 'precision' => $precision ] ); }
	private function request(): FixedPromotionBudgetRequest { return FixedPromotionBudgetRequest::from_captured( F::header( self::$provider ), self::$provider->context(), self::$provider->terms(), F::key(), QuoteFixtures::digest( 'explicit shared promotion account' ) ); }
	private function at( int $seconds = 0 ): QuoteTime { return $this->request->quote_created_at()->plus_seconds( $seconds ); }
	private function service( ?callable $authorize = null ): FixedPromotionBudgetService {
		return new FixedPromotionBudgetService( $this->store, $authorize ?? function( OperationSession $session, FixedPromotionBudgetRequest $request, string $action, QuoteTime $at ): bool {
			$this->authority[] = [ $session, $request->digest(), $action, $at->sql() ]; return $this->allowed;
		} );
	}
	private function hold( ?FixedPromotionBudgetService $service = null, int $until = 60 ): FixedPromotionBudgetReservation { return ( $service ?? $this->service() )->reserve( $this->session, $this->request, $this->at(), $this->at( $until ) ); }
	private function refusal( callable $run ): void { try { $run(); self::fail( 'The operation should refuse.' ); } catch ( \DomainException|\InvalidArgumentException ) { self::assertTrue( true ); } }
	private function storage_failure( callable $run ): void { try { $run(); self::fail( 'A failed write must escape as storage failure.' ); } catch ( OperationStorageException $e ) { self::assertSame( 'The operation store could not complete its database statement.', $e->getMessage() ); } }

	public function test_reserve_uses_account_before_claim_and_preserves_transaction_ownership(): void {
		$before = $this->session->lifecycle; $claim = $this->hold();
		self::assertSame( [ 'account', 'claim' ], $this->store->locks ); self::assertSame( 'held', $claim->state() );
		self::assertSame( '3.00', $this->store->account->held()->amount() ); self::assertSame( '3.00', $this->store->account->remaining()->amount() );
		self::assertSame( $before, $this->session->lifecycle ); self::assertTrue( $this->session->in_transaction() );
		self::assertSame( [ 'wp_promotion_accounts', 'wp_promotion_claims' ], $this->session->validated );
		self::assertSame( $this->session, $this->authority[0][0] ); self::assertSame( $this->request->digest(), $this->authority[0][1] ); self::assertSame( 'reserve', $this->authority[0][2] );
	}
	public function test_exact_held_retry_does_not_charge_or_extend_the_claim(): void {
		$service = $this->service(); $first = $this->hold( $service ); $writes = $this->store->writes;
		$again = $service->reserve( $this->session, $this->request, $this->at( 1 ), $this->at( 60 ) );
		self::assertSame( $first, $again ); self::assertSame( $writes, $this->store->writes ); self::assertSame( 2, $this->store->account->revision() ); self::assertSame( 1, $again->revision() );
	}
	public function test_retry_cannot_change_the_hold_deadline(): void {
		$service = $this->service(); $this->hold( $service ); $writes = $this->store->writes;
		$this->refusal( fn() => $service->reserve( $this->session, $this->request, $this->at( 1 ), $this->at( 61 ) ) ); self::assertSame( $writes, $this->store->writes );
	}
	public function test_sequential_claims_share_a_limit_and_exhaustion_has_no_write(): void {
		$service = $this->service(); $this->hold( $service ); $second = $this->request();
		$service->reserve( $this->session, $second, $this->at( 1 ), $this->at( 60 ) ); $writes = $this->store->writes;
		$this->refusal( fn() => $service->reserve( $this->session, $this->request(), $this->at( 2 ), $this->at( 60 ) ) );
		self::assertSame( $writes, $this->store->writes ); self::assertCount( 2, $this->store->claims ); self::assertSame( '6.00', $this->store->account->held()->amount() ); self::assertTrue( $this->store->account->remaining()->zero() );
	}
	public function test_commit_allocates_once_and_terminal_replay_does_not_charge_after_quote_expiry(): void {
		$service = $this->service(); $this->hold( $service ); $committed = $service->commit( $this->session, $this->request, $this->at( 1 ) ); $writes = $this->store->writes;
		self::assertSame( 'committed', $committed->state() ); self::assertSame( '0.00', $this->store->account->held()->amount() ); self::assertSame( '3.00', $this->store->account->spent()->amount() );
		self::assertSame( $committed, $service->commit( $this->session, $this->request, $this->at( 400 ) ) ); self::assertSame( $writes, $this->store->writes );
		$this->refusal( fn() => $service->release( $this->session, $this->request, $this->at( 401 ) ) ); self::assertSame( $writes, $this->store->writes );
	}
	public function test_expiry_does_not_release_and_explicit_authorized_release_can_finish_later(): void {
		$service = $this->service(); $held = $this->hold( $service ); $writes = $this->store->writes;
		self::assertFalse( $held->is_held_at( $this->at( 60 ) ) ); self::assertSame( '3.00', $this->store->account->held()->amount() ); self::assertSame( $writes, $this->store->writes );
		$released = $service->release( $this->session, $this->request, $this->at( 400 ) );
		self::assertSame( 'released', $released->state() ); self::assertSame( '0.00', $this->store->account->held()->amount() ); self::assertSame( '0.00', $this->store->account->spent()->amount() );
		$writes = $this->store->writes; self::assertSame( $released, $service->release( $this->session, $this->request, $this->at( 401 ) ) ); self::assertSame( $writes, $this->store->writes );
		self::assertSame( 'release', $this->authority[array_key_last( $this->authority )][2] );
	}
	public static function expiry_shapes(): array { return [ 'own hold expired' => [ 60, 60 ], 'quote expired but hold remains' => [ 900, 300 ] ]; }
	#[DataProvider( 'expiry_shapes' )]
	public function test_first_commit_requires_both_quote_and_claim_live( int $until, int $at ): void {
		$service = $this->service(); $this->hold( $service, $until ); $writes = $this->store->writes;
		$this->refusal( fn() => $service->commit( $this->session, $this->request, $this->at( $at ) ) ); self::assertSame( $writes, $this->store->writes ); self::assertSame( '3.00', $this->store->account->held()->amount() );
	}
	public function test_terminal_claim_cannot_be_reserved_again_or_replayed_backwards(): void {
		$service = $this->service(); $this->hold( $service ); $service->release( $this->session, $this->request, $this->at( 2 ) ); $writes = $this->store->writes;
		$this->refusal( fn() => $service->reserve( $this->session, $this->request, $this->at( 3 ), $this->at( 60 ) ) );
		$this->refusal( fn() => $service->release( $this->session, $this->request, $this->at( 1 ) ) ); self::assertSame( $writes, $this->store->writes );
	}
	public static function invalid_sessions(): array { return [ 'retired' => [ 'retired' ], 'no transaction' => [ 'transaction' ], 'foreign site' => [ 'site' ], 'empty prefix' => [ 'prefix' ] ]; }
	#[DataProvider( 'invalid_sessions' )]
	public function test_invalid_owner_refuses_before_authorizer_or_store( string $fault ): void {
		match ( $fault ) { 'retired' => $this->session->retired = true, 'transaction' => $this->session->transaction = false, 'site' => $this->session->site = 2, default => $this->session->prefix = '' };
		$this->refusal( fn() => $this->hold() ); self::assertSame( [], $this->authority ); self::assertSame( 0, $this->store->reads ); self::assertSame( 0, $this->store->writes );
	}
	public static function actions(): array { return [ 'reserve' => [ 'reserve' ], 'commit' => [ 'commit' ], 'release' => [ 'release' ] ]; }
	#[DataProvider( 'actions' )]
	public function test_denied_authority_never_calls_the_store( string $action ): void {
		$this->allowed = false; $service = $this->service();
		$this->refusal( fn() => 'reserve' === $action ? $this->hold( $service ) : $service->$action( $this->session, $this->request, $this->at() ) );
		self::assertCount( 1, $this->authority ); self::assertSame( 0, $this->store->reads ); self::assertSame( 0, $this->store->writes );
	}
	public function test_authorizer_failure_is_a_fixed_refusal_before_store_access(): void {
		$this->refusal( fn() => $this->hold( $this->service( static fn() => throw new \RuntimeException( 'private policy failure' ) ) ) ); self::assertSame( 0, $this->store->reads );
	}
	public function test_revoked_authority_after_locks_prevents_first_write(): void {
		$calls = 0; $service = $this->service( static function() use ( &$calls ): bool { return 1 === ++$calls; } );
		$this->refusal( fn() => $this->hold( $service ) ); self::assertSame( [ 'account', 'claim' ], $this->store->locks ); self::assertSame( 0, $this->store->writes );
	}
	public function test_authorizer_cannot_end_the_owned_unit_and_then_allow_store_access(): void {
		$service = $this->service( function(): bool { $this->session->transaction = false; return true; } );
		$this->refusal( fn() => $this->hold( $service ) ); self::assertSame( 0, $this->store->reads ); self::assertSame( 0, $this->store->writes );
	}
	public function test_authorizer_cannot_switch_table_scope_before_store_access(): void {
		$service = $this->service( function(): bool { $this->session->prefix = 'other_'; return true; } );
		$this->refusal( fn() => $this->hold( $service ) ); self::assertSame( 0, $this->store->reads ); self::assertSame( 0, $this->store->writes );
	}
	public static function read_owner_losses(): array { return [ 'tables retire' => [ 'tables_retire', [] ], 'validation ends unit' => [ 'validate_end', [] ], 'account lock ends unit' => [ 'lock_end', [ 'account' ] ] ]; }
	#[DataProvider( 'read_owner_losses' )]
	public function test_owner_loss_during_port_reads_stops_the_next_port_call( string $fault, array $expected_locks ): void {
		$this->store->fault = $fault; $this->session->end_on_validation = 'validate_end' === $fault;
		$this->storage_failure( fn() => $this->hold() ); self::assertSame( $expected_locks, $this->store->locks ); self::assertSame( 0, $this->store->writes );
	}
	public static function write_owner_losses(): array { return [ 'reserve' => [ 'reserve' ], 'commit' => [ 'commit' ], 'release' => [ 'release' ] ]; }
	#[DataProvider( 'write_owner_losses' )]
	public function test_account_cas_owner_loss_never_attempts_the_claim_write( string $action ): void {
		$service = $this->service(); if ( 'reserve' !== $action ) { $this->hold( $service ); }
		$writes = $this->store->writes; $this->store->fault = 'account_retire';
		$this->storage_failure( fn() => 'reserve' === $action ? $this->hold( $service ) : $service->$action( $this->session, $this->request, $this->at( 1 ) ) );
		self::assertSame( $writes + 1, $this->store->writes ); self::assertTrue( $this->session->is_retired() );
		if ( 'reserve' === $action ) { self::assertSame( [], $this->store->claims ); }
		else { self::assertSame( 'held', $this->store->claims[$this->request->claim_key()]->state() ); }
	}
	public function test_account_cas_scope_change_requires_rollback_before_any_claim_insert(): void {
		$this->store->fault = 'account_scope'; $this->storage_failure( fn() => $this->hold() );
		self::assertSame( 1, $this->store->writes ); self::assertSame( [], $this->store->claims ); self::assertSame( '3.00', $this->store->account->held()->amount() );
		$this->session->rollback(); self::assertSame( '0.00', $this->store->account->held()->amount() );
	}
	public static function claim_scope_losses(): array { return [ 'insert' => [ 'insert_scope' ], 'transition' => [ 'replace_scope' ] ]; }
	#[DataProvider( 'claim_scope_losses' )]
	public function test_claim_write_scope_change_escapes_as_uncertain_and_caller_rolls_back( string $fault ): void {
		$service = $this->service(); if ( 'replace_scope' === $fault ) { $this->hold( $service ); $this->session->commit(); $this->session->begin(); }
		$this->store->fault = $fault;
		$this->storage_failure( fn() => 'insert_scope' === $fault ? $this->hold( $service ) : $service->commit( $this->session, $this->request, $this->at( 1 ) ) );
		self::assertSame( 'other_', $this->session->prefix ); self::assertTrue( $this->session->in_transaction() ); $this->session->rollback();
		self::assertSame( '0.00', $this->store->account->spent()->amount() ); self::assertSame( 'replace_scope' === $fault ? '3.00' : '0.00', $this->store->account->held()->amount() );
	}
	public static function invalid_tables(): array { return [ 'empty' => [ [] ], 'foreign' => [ [ 'foreign_accounts' ] ], 'expression' => [ [ 'wp_accounts FOR UPDATE' ] ], 'associative' => [ [ 'accounts' => 'wp_accounts' ] ], 'too many' => [ array_fill( 0, 33, 'wp_accounts' ) ] ]; }
	#[DataProvider( 'invalid_tables' )]
	public function test_declared_storage_is_finite_and_same_owner( array $tables ): void {
		$this->store->declared = $tables; $this->storage_failure( fn() => $this->hold() ); self::assertSame( [], $this->store->locks ); self::assertSame( 0, $this->store->writes );
	}
	public function test_failed_physical_table_validation_never_locks_or_writes(): void {
		$this->session->valid_tables = false; $this->storage_failure( fn() => $this->hold() ); self::assertSame( [], $this->store->locks ); self::assertSame( 0, $this->store->writes );
	}
	public static function bad_accounts(): array { return [ 'missing' => [ 'missing' ], 'site' => [ 'site' ], 'pool' => [ 'pool' ], 'funding' => [ 'funding' ], 'currency' => [ 'currency' ], 'precision' => [ 'precision' ] ]; }
	#[DataProvider( 'bad_accounts' )]
	public function test_missing_or_foreign_account_is_not_zero_or_available( string $fault ): void {
		if ( 'missing' === $fault ) { $this->store->account = null; }
		else { $this->store->account = FixedPromotionBudgetState::create( 'site' === $fault ? 2 : $this->request->site_id(), 'pool' === $fault ? QuoteFixtures::digest( 'foreign pool' ) : $this->request->account_key(), 'funding' === $fault ? [ 'code' => 'foreign_funder', 'version' => 2 ] : $this->request->funding_source(), self::money( '6', 'currency' === $fault ? 'USD' : 'GHS', 'precision' === $fault ? 3 : 2 ) ); }
		$this->refusal( fn() => $this->hold() ); self::assertSame( 0, $this->store->writes );
	}
	public function test_conflicting_loaded_claim_does_not_charge_or_change_either_claim(): void {
		$this->store->claim_override = FixedPromotionBudgetReservation::hold( $this->request(), $this->at(), $this->at( 60 ) );
		$this->refusal( fn() => $this->hold() ); self::assertSame( 0, $this->store->writes ); self::assertSame( '0.00', $this->store->account->held()->amount() );
	}
	public static function reserve_faults(): array { return [ 'account CAS false' => [ 'account_false', '0.00' ], 'claim insert false' => [ 'insert_false', '3.00' ], 'claim insert throws' => [ 'insert_throw', '3.00' ] ]; }
	#[DataProvider( 'reserve_faults' )]
	public function test_reserve_fault_escapes_and_caller_rolls_back_partial_state( string $fault, string $pending_held ): void {
		$this->store->fault = $fault; $this->storage_failure( fn() => $this->hold() );
		self::assertTrue( $this->session->in_transaction() ); self::assertSame( $pending_held, $this->store->account->held()->amount() ); self::assertSame( [ 'begin' ], $this->session->lifecycle );
		self::assertTrue( $this->session->rollback() ); self::assertSame( '0.00', $this->store->account->held()->amount() ); self::assertSame( [], $this->store->claims );
	}
	public static function transition_faults(): array { return [ 'claim CAS false' => [ 'replace_false' ], 'claim CAS throws' => [ 'replace_throw' ] ]; }
	#[DataProvider( 'transition_faults' )]
	public function test_transition_fault_does_not_report_success_and_caller_restores_hold( string $fault ): void {
		$service = $this->service(); $this->hold( $service ); $this->session->commit(); $this->session->begin(); $before = $this->session->lifecycle; $this->store->fault = $fault;
		$this->storage_failure( fn() => $service->commit( $this->session, $this->request, $this->at( 1 ) ) );
		self::assertSame( '3.00', $this->store->account->spent()->amount() ); self::assertSame( 'held', $this->store->claims[$this->request->claim_key()]->state() ); self::assertSame( $before, $this->session->lifecycle );
		$this->session->rollback(); self::assertSame( '3.00', $this->store->account->held()->amount() ); self::assertSame( '0.00', $this->store->account->spent()->amount() );
	}
	public function test_account_aware_store_is_used_for_reserve_replay_and_terminal_transition(): void {
		$store = new class( $this->store ) implements FixedPromotionBudgetAccountStore {
			public array $reads = [];
			public function __construct( private FixedPromotionBudgetStore $delegate ) {}
			public function tables( OperationSession $session ): array { return $this->delegate->tables( $session ); }
			public function lock_account( OperationSession $session, string $key ): ?FixedPromotionBudgetState { return $this->delegate->lock_account( $session, $key ); }
			public function lock_reservation( OperationSession $session, string $key ): ?FixedPromotionBudgetReservation { throw new \LogicException( 'A known account must not use stale discovery.' ); }
			public function lock_reservation_for_account( OperationSession $session, string $claim, string $account ): ?FixedPromotionBudgetReservation { $this->reads[] = [ $claim, $account ]; return $this->delegate->lock_reservation( $session, $claim ); }
			public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { return $this->delegate->replace_account( $session, $expected, $next ); }
			public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool { return $this->delegate->insert_reservation( $session, $reservation ); }
			public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { return $this->delegate->replace_reservation( $session, $expected, $next ); }
		};
		$service = new FixedPromotionBudgetService( $store, static fn(): bool => true );
		$first = $service->reserve( $this->session, $this->request, $this->at(), $this->at( 60 ) );
		self::assertSame( $first, $service->reserve( $this->session, $this->request, $this->at( 1 ), $this->at( 60 ) ) );
		self::assertSame( 'committed', $service->commit( $this->session, $this->request, $this->at( 2 ) )->state() );
		self::assertSame( array_fill( 0, 3, [ $this->request->claim_key(), $this->request->account_key() ] ), $store->reads );
		self::assertSame( '3.00', $this->store->account->spent()->amount() ); self::assertSame( '0.00', $this->store->account->held()->amount() );
	}
}

final class SyntheticPromotionBudgetStore implements FixedPromotionBudgetStore {
	public array $claims = [];
	public array $locks = [];
	public array $declared = [ 'wp_promotion_accounts', 'wp_promotion_claims' ];
	public int $reads = 0;
	public int $writes = 0;
	public string $fault = '';
	public ?FixedPromotionBudgetReservation $claim_override = null;
	public function __construct( public ?FixedPromotionBudgetState $account ) {}
	public function tables( OperationSession $session ): array { ++$this->reads; if ( 'tables_retire' === $this->fault && $session instanceof SyntheticPromotionBudgetSession ) { $session->retired = true; } return $this->declared; }
	public function lock_account( OperationSession $session, string $account_key ): ?FixedPromotionBudgetState { ++$this->reads; $this->locks[] = 'account'; if ( 'lock_end' === $this->fault && $session instanceof SyntheticPromotionBudgetSession ) { $session->transaction = false; } return $this->account; }
	public function lock_reservation( OperationSession $session, string $claim_key ): ?FixedPromotionBudgetReservation { ++$this->reads; $this->locks[] = 'claim'; return $this->claim_override ?? $this->claims[$claim_key] ?? null; }
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool {
		++$this->writes; if ( 'account_false' === $this->fault || $this->account !== $expected ) { return false; } $this->account = $next;
		if ( $session instanceof SyntheticPromotionBudgetSession ) { if ( 'account_retire' === $this->fault ) { $session->retired = true; } if ( 'account_scope' === $this->fault ) { $session->prefix = 'other_'; } } return true;
	}
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool {
		++$this->writes; if ( 'insert_throw' === $this->fault ) { throw new \RuntimeException( 'private SQL diagnostics' ); }
		$key = $reservation->request()->claim_key(); if ( 'insert_false' === $this->fault || isset( $this->claims[$key] ) ) { return false; } $this->claims[$key] = $reservation;
		if ( 'insert_scope' === $this->fault && $session instanceof SyntheticPromotionBudgetSession ) { $session->prefix = 'other_'; } return true;
	}
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool {
		++$this->writes; if ( 'replace_throw' === $this->fault ) { throw new \RuntimeException( 'private SQL diagnostics' ); }
		$key = $expected->request()->claim_key(); if ( 'replace_false' === $this->fault || ( $this->claims[$key] ?? null ) !== $expected ) { return false; } $this->claims[$key] = $next;
		if ( 'replace_scope' === $this->fault && $session instanceof SyntheticPromotionBudgetSession ) { $session->prefix = 'other_'; } return true;
	}
}

final class SyntheticPromotionBudgetSession implements OperationSession {
	public bool $transaction = false;
	public bool $retired = false;
	public bool $valid_tables = true;
	public bool $end_on_validation = false;
	public int $site = 1;
	public string $prefix = 'wp_';
	public array $lifecycle = [];
	public array $validated = [];
	private array $snapshot = [];
	public function __construct( private SyntheticPromotionBudgetStore $store ) {}
	public function site_id(): int { return $this->site; }
	public function table_prefix(): string { return $this->prefix; }
	public function charset_collate(): string { return 'utf8mb4_unicode_ci'; }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->transaction; }
	public function begin(): bool { $this->lifecycle[] = 'begin'; $this->snapshot = [ $this->store->account, $this->store->claims ]; return $this->transaction = true; }
	public function commit(): OperationCommitResult { $this->lifecycle[] = 'commit'; $this->transaction = false; return OperationCommitResult::Acknowledged; }
	public function rollback(): bool { $this->lifecycle[] = 'rollback'; [ $this->store->account, $this->store->claims ] = $this->snapshot; $this->transaction = false; return true; }
	public function retire(): bool { $this->lifecycle[] = 'retire'; return $this->retired = true; }
	public function validate_tables( array $table_names ): bool { $this->validated = $table_names; if ( $this->end_on_validation ) { $this->transaction = false; } return $this->valid_tables; }
	public function query( string $sql ): int|false { throw new \LogicException( 'Service must use the owned store port.' ); }
	public function get_row( string $sql ): array|null|false { throw new \LogicException( 'Service must use the owned store port.' ); }
	public function get_results( string $sql ): array|false { throw new \LogicException( 'Service must use the owned store port.' ); }
	public function prepare( string $sql, mixed ...$args ): string { throw new \LogicException( 'Service must use the owned store port.' ); }
	public function errno(): int { return 0; }
	public function insert_id(): int { return 0; }
}
