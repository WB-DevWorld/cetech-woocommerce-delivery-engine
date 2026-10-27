<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetCurrentAuthority, FixedPromotionBudgetStore, FixedPromotionEligibilityBudgetGuard, FixedPromotionEligibilityGuard, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteProvider, QuoteCurrentEvidenceValidity};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedPromotionBudgetRequest, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteHeader, QuoteMoney, QuoteOwner, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Synthetic same-owner authoritative ports; not a durable campaign adapter certification. */
final class FixedPromotionBudgetCurrentAuthorityTest extends TestCase {
	private FixedPromotionQuoteProvider $provider;
	private QuoteHeader $header;
	private CurrentBudgetStoreFixture $store;
	private CurrentBudgetEligibilityFixture $eligibility;
	private CurrentBudgetSessionFixture $session;
	private string $account;
	private FixedPromotionBudgetRequest $request;
	private FixedPromotionBudgetCurrentAuthority $authority;

	protected function setUp(): void {
		$this->provider = F::provider(); $this->header = F::header( $this->provider ); $this->account = Q::digest( 'owned campaign pool' );
		$this->store = new CurrentBudgetStoreFixture(); $this->session = new CurrentBudgetSessionFixture(); $this->eligibility = new CurrentBudgetEligibilityFixture();
		$this->request = FixedPromotionBudgetRequest::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), F::key(), $this->account );
		$this->seed( [ $this->request ] ); $this->authority = $this->make();
	}
	private static function money( string $amount, string $currency = 'GHS', int $precision = 2 ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $currency, 'precision' => $precision ] ); }
	private function make( ?array $accounts = null ): FixedPromotionBudgetCurrentAuthority { return FixedPromotionBudgetCurrentAuthority::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), $accounts ?? [ [ 'component_key' => F::key(), 'account_key' => $this->account ] ], $this->store, $this->eligibility ); }
	private function seed( array $requests, string $limit = '10.00', int $expiry = 120 ): void {
		foreach ( $requests as $request ) {
			$key = $request->account_key(); $account = $this->store->accounts[$key] ?? FixedPromotionBudgetState::create( 1, $key, $request->funding_source(), self::money( $limit ) );
			$this->store->accounts[$key] = $account->reserve( $request->amount() ); $this->store->claims[$request->claim_key()] = FixedPromotionBudgetReservation::hold( $request, Q::time(), Q::time()->plus_seconds( $expiry ) );
		}
	}
	private function read( ?QuoteTime $at = null ): ?QuoteCurrentEvidenceValidity { return $this->authority->validity_at( $this->session, F::owner(), $this->header, $this->provider->context(), $this->provider->terms(), $at ?? Q::time()->plus_seconds( 10 ) ); }
	private function quiet(): void { self::assertSame( 0, $this->store->writes ); self::assertSame( 0, $this->session->ownership_calls ); }

	public function test_held_claim_remains_valid_when_no_free_budget_remains(): void {
		$this->store->accounts[$this->account] = FixedPromotionBudgetState::create( 1, $this->account, $this->request->funding_source(), self::money( '3.00' ) )->reserve( self::money( '3.00' ) );
		self::assertTrue( $this->store->accounts[$this->account]->remaining()->zero() ); $validity = $this->read(); self::assertNotNull( $validity );
		self::assertTrue( $validity->valid_at( Q::time()->plus_seconds( 119 ) ) ); self::assertFalse( $validity->valid_at( Q::time()->plus_seconds( 120 ) ) );
		self::assertSame( [ spl_object_id( $this->session ), spl_object_id( $this->session ) ], $this->store->owners );
		self::assertSame( spl_object_id( $this->session ), $this->eligibility->owner ); $this->quiet();
	}
	public static function deadlines(): array { return [ 'eligibility first' => [ 30, 120, 30 ], 'claim first' => [ 180, 60, 60 ], 'quote first' => [ 900, 600, 300 ] ]; }
	#[DataProvider( 'deadlines' )]
	public function test_validity_preserves_every_exclusive_deadline( int $eligible, int $claim, int $expected ): void {
		$this->eligibility->until = Q::time()->plus_seconds( $eligible ); $this->store->claims[$this->request->claim_key()] = FixedPromotionBudgetReservation::hold( $this->request, Q::time(), Q::time()->plus_seconds( $claim ) );
		$validity = $this->read(); self::assertNotNull( $validity ); self::assertTrue( $validity->valid_at( Q::time()->plus_seconds( $expected - 1 ) ) ); self::assertFalse( $validity->valid_at( Q::time()->plus_seconds( $expected ) ) );
		$this->quiet();
	}
	public function test_missing_claim_refuses_without_releasing_or_mutating_the_account(): void {
		$before = $this->store->accounts[$this->account]->to_private_json(); $this->store->claims = []; self::assertNull( $this->read() ); self::assertSame( $before, $this->store->accounts[$this->account]->to_private_json() ); $this->quiet();
	}
	public static function terminal_states(): array { return [ 'released' => [ 'release' ], 'committed' => [ 'commit' ] ]; }
	#[DataProvider( 'terminal_states' )]
	public function test_terminal_allocations_never_become_held_admission( string $method ): void {
		$this->store->claims[$this->request->claim_key()] = $this->store->claims[$this->request->claim_key()]->$method( Q::time()->plus_seconds( 5 ) ); self::assertNull( $this->read() ); $this->quiet();
	}
	public function test_expired_claim_is_not_released_by_a_current_read(): void {
		$claim = $this->store->claims[$this->request->claim_key()]; self::assertNull( $this->read( $claim->expires_at() ) ); self::assertSame( $claim, $this->store->claims[$this->request->claim_key()] ); self::assertSame( 'held', $claim->state() ); $this->quiet();
	}
	public function test_same_capture_and_money_do_not_allow_another_compiled_quote_to_borrow_a_claim(): void {
		$other = FixedPromotionBudgetRequest::from_captured( F::header( $this->provider ), $this->provider->context(), $this->provider->terms(), F::key(), $this->account );
		self::assertNotSame( $this->request->claim_key(), $other->claim_key() );
		$this->store->claims[$this->request->claim_key()] = FixedPromotionBudgetReservation::hold( $other, Q::time(), Q::time()->plus_seconds( 120 ) ); self::assertNull( $this->read() ); $this->quiet();
	}
	public static function invalid_scopes(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'header', 'context', 'terms', 'owner', 'site', 'retired', 'no transaction', 'before quote', 'quote expiry' ] ); }
	#[DataProvider( 'invalid_scopes' )]
	public function test_wrong_scope_refuses_before_eligibility_store_or_sql( string $case ): void {
		$header = $this->header; $context = $this->provider->context(); $terms = $this->provider->terms(); $owner = F::owner(); $at = Q::time()->plus_seconds( 10 );
		if ( 'header' === $case ) { $header = F::header( $this->provider ); }
		elseif ( 'context' === $case ) { $context = F::original_context(); }
		elseif ( 'terms' === $case ) { $terms = F::original_terms(); }
		elseif ( 'owner' === $case ) { $facts = $owner->facts(); $facts['principal_hash'] = Q::digest( 'another principal' ); $owner = QuoteOwner::from_array( $facts ); }
		elseif ( 'site' === $case ) { $this->session->site = 2; }
		elseif ( 'retired' === $case ) { $this->session->retired = true; }
		elseif ( 'no transaction' === $case ) { $this->session->owned = false; }
		elseif ( 'before quote' === $case ) { $at = Q::time()->plus_seconds( -1 ); }
		else { $at = $header->expires_at(); }
		self::assertNull( $this->authority->validity_at( $this->session, $owner, $header, $context, $terms, $at ) ); self::assertSame( 0, $this->eligibility->calls ); self::assertSame( 0, $this->store->metadata ); self::assertSame( [], $this->store->locks ); self::assertSame( [], $this->session->validated ); $this->quiet();
	}
	public static function wrong_accounts(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'missing', 'site', 'account', 'funding', 'currency', 'precision', 'held' ] ); }
	#[DataProvider( 'wrong_accounts' )]
	public function test_missing_or_incompatible_account_cannot_supply_budget( string $case ): void {
		if ( 'missing' === $case ) { $this->store->accounts = []; }
		else {
			$facts = $this->store->accounts[$this->account]->private_facts();
			if ( 'site' === $case ) { $facts['site_id'] = 2; }
			elseif ( 'account' === $case ) { $facts['account_key'] = Q::digest( 'foreign pool' ); }
			elseif ( 'funding' === $case ) { $facts['funding_source']['version']++; }
			elseif ( 'held' === $case ) { $facts['held'] = self::money( '2.99' )->facts(); }
			else { foreach ( [ 'limit', 'held', 'spent' ] as $field ) { $facts[$field] = self::money( $facts[$field]['amount'], 'currency' === $case ? 'USD' : 'GHS', 'precision' === $case ? 3 : 2 )->facts(); } }
			$this->store->accounts[$this->account] = FixedPromotionBudgetState::from_array( $facts );
		}
		self::assertNull( $this->read() ); self::assertCount( 1, $this->store->locks ); $this->quiet();
	}
	public static function ineligible(): array { return [ 'denied' => [ 'denied' ], 'exception' => [ 'exception' ], 'future observation' => [ 'future' ], 'ended' => [ 'ended' ] ]; }
	#[DataProvider( 'ineligible' )]
	public function test_current_eligibility_is_required_separately_from_a_held_budget( string $case ): void {
		$this->eligibility->mode = $case; self::assertNull( $this->read() ); self::assertSame( [], $this->store->locks ); $this->quiet();
	}
	public function test_declared_tables_are_finite_deduplicated_and_owned(): void {
		$this->eligibility->declared = [ 'wp_campaigns', 'wp_budget_claims' ];
		self::assertSame( [ 'wp_budget_accounts', 'wp_budget_claims', 'wp_campaigns' ], $this->authority->tables( $this->session ) ); self::assertNotNull( $this->read() ); self::assertCount( 1, $this->session->validated ); $this->quiet();
	}
	public static function bad_tables(): array { return [ 'foreign prefix' => [ [ 'foreign_campaigns' ] ], 'identifier' => [ [ 'wp_campaigns;DROP' ] ], 'empty' => [ [] ], 'associative' => [ [ 'a' => 'wp_campaigns' ] ], 'overflow' => [ array_map( static fn( int $id ): string => 'wp_table_' . $id, range( 1, 65 ) ) ] ]; }
	#[DataProvider( 'bad_tables' )]
	public function test_invalid_declared_tables_refuse_before_current_policy_or_ledger_reads( array $tables ): void {
		$this->eligibility->declared = $tables; self::assertNull( $this->read() ); self::assertSame( 0, $this->eligibility->calls ); self::assertSame( [], $this->store->locks ); $this->quiet();
	}
	public function test_table_validation_failure_does_not_read_current_policy_or_budget(): void {
		$this->session->tables_ok = false; self::assertNull( $this->read() ); self::assertSame( 0, $this->eligibility->calls ); self::assertSame( [], $this->store->locks ); $this->quiet();
	}
	public static function lost_owners(): array { return [ 'eligibility retires' => [ 'eligible', 'retired' ], 'eligibility loses transaction' => [ 'eligible', 'owned' ], 'account changes site' => [ 'account', 'site' ], 'last claim retires' => [ 'claim', 'retired' ], 'last claim loses transaction' => [ 'claim', 'owned' ], 'last claim changes prefix' => [ 'claim', 'prefix' ] ]; }
	#[DataProvider( 'lost_owners' )]
	public function test_owner_loss_during_a_port_callback_withholds_the_proof( string $phase, string $field ): void {
		$lose = static function( CurrentBudgetSessionFixture $session ) use ( $field ): void { $session->$field = match ( $field ) { 'retired' => true, 'owned' => false, 'site' => 2, default => 'foreign_' }; };
		if ( 'eligible' === $phase ) { $this->eligibility->callback = $lose; } else { $this->store->callback_phase = $phase; $this->store->callback = $lose; }
		self::assertNull( $this->read() ); $this->quiet();
	}
	public function test_mixed_unadjusted_component_requires_explicit_null_and_no_claim(): void {
		$this->provider = F::mixed_provider(); $this->header = F::header( $this->provider ); $this->request = FixedPromotionBudgetRequest::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), F::key(), $this->account ); $this->store->accounts = []; $this->store->claims = []; $this->seed( [ $this->request ] );
		$this->authority = $this->make( [ [ 'component_key' => F::second_key(), 'account_key' => null ], [ 'component_key' => F::key(), 'account_key' => $this->account ] ] ); self::assertNotNull( $this->read() ); self::assertCount( 2, $this->store->locks ); $this->quiet();
	}
	public static function invalid_mappings(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'missing', 'duplicate', 'unknown', 'unplanned pool', 'planned null', 'malformed account', 'extra field' ] ); }
	#[DataProvider( 'invalid_mappings' )]
	public function test_factory_requires_complete_unambiguous_explicit_component_accounts( string $case ): void {
		$this->provider = F::mixed_provider(); $this->header = F::header( $this->provider ); $rows = [ [ 'component_key' => F::key(), 'account_key' => $this->account ], [ 'component_key' => F::second_key(), 'account_key' => null ] ];
		if ( 'missing' === $case ) { array_pop( $rows ); } elseif ( 'duplicate' === $case ) { $rows[] = $rows[0]; } elseif ( 'unknown' === $case ) { $rows[1]['component_key'] = Q::digest( 'unknown component' ); } elseif ( 'unplanned pool' === $case ) { $rows[1]['account_key'] = $this->account; } elseif ( 'planned null' === $case ) { $rows[0]['account_key'] = null; } elseif ( 'malformed account' === $case ) { $rows[0]['account_key'] = 'not_a_digest'; } else { $rows[0]['surprise'] = true; }
		$this->expectException( \InvalidArgumentException::class ); $this->make( $rows );
	}
	public static function planned_pools(): array { return [ 'shared pool' => [ false ], 'distinct pools' => [ true ] ]; }
	#[DataProvider( 'planned_pools' )]
	public function test_multiple_planned_components_lock_accounts_then_claims_in_deterministic_order( bool $separate ): void {
		$this->provider = $this->two_planned(); $this->header = F::header( $this->provider ); $second = $separate ? Q::digest( 'second owned pool' ) : $this->account;
		$first_request = FixedPromotionBudgetRequest::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), F::key(), $this->account ); $second_request = FixedPromotionBudgetRequest::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), F::second_key(), $second );
		$this->store->accounts = []; $this->store->claims = []; $this->seed( [ $first_request, $second_request ], $separate ? '3.00' : '6.00' );
		$this->authority = $this->make( [ [ 'component_key' => F::second_key(), 'account_key' => $second ], [ 'component_key' => F::key(), 'account_key' => $this->account ] ] );
		self::assertNotNull( $this->read() ); $account_keys = array_values( array_unique( [ $this->account, $second ] ) ); sort( $account_keys, SORT_STRING ); $claim_keys = [ $first_request->claim_key(), $second_request->claim_key() ]; sort( $claim_keys, SORT_STRING );
		self::assertSame( [ ...array_map( static fn( string $key ): string => 'account:' . $key, $account_keys ), ...array_map( static fn( string $key ): string => 'claim:' . $key, $claim_keys ) ], $this->store->locks ); $this->quiet();
	}
	public function test_shared_pool_must_cover_sum_of_owned_claims_not_just_each_claim(): void {
		$this->provider = $this->two_planned(); $this->header = F::header( $this->provider ); $first = FixedPromotionBudgetRequest::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), F::key(), $this->account ); $second = FixedPromotionBudgetRequest::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), F::second_key(), $this->account );
		$this->store->accounts = []; $this->store->claims = []; $this->seed( [ $first, $second ] ); $this->store->accounts[$this->account] = FixedPromotionBudgetState::create( 1, $this->account, $first->funding_source(), self::money( '10.00' ) )->reserve( self::money( '3.00' ) );
		$this->authority = $this->make( [ [ 'component_key' => F::key(), 'account_key' => $this->account ], [ 'component_key' => F::second_key(), 'account_key' => $this->account ] ] ); self::assertNull( $this->read() ); self::assertCount( 1, $this->store->locks ); $this->quiet();
	}
	private function two_planned(): FixedPromotionQuoteProvider {
		$original = F::mixed_result()->original_requests(); $context = $original->original_context(); $terms = $original->original_terms(); $rule = F::rule();
		$evidence = F::plan( $terms )->private_facts()['evidence']; $evidence['component_key'] = F::second_key(); $second_plan = DeliveryPromotionPlan::from_evidence( $terms, F::second_key(), $rule, $evidence['provider']['code'], $evidence['provider']['version'], $evidence );
		$requests = FixedPromotionNativeRequests::from_captured( $original->header(), $context, $terms, [ [ 'component_key' => F::key(), 'plan' => F::plan( $terms ) ], [ 'component_key' => F::second_key(), 'plan' => $second_plan ] ], [ [ 'component_key' => F::key(), 'legacy_group_id' => 'in_store|delivery|20' ], [ 'component_key' => F::second_key(), 'legacy_group_id' => 'second|delivery|20' ] ] );
		$source = F::source(); $second_package = $source->data['packages'][0]; $second_package['group_id'] = 'second|delivery|20'; $second_package['members'][0]['line_key'] = 'line_two'; $second_package['members'][0]['product_id'] = 11; $source->data['packages'][] = $second_package; $source->data['cart_shipping_total'] = '19.00'; $source->data['cart_shipping_tax'] = '3.80';
		return F::provider( ( new FixedPromotionNativeCapture( $source ) )->capture( F::owner(), $requests ), [ [ 'component_key' => F::key(), 'rule' => $rule ], [ 'component_key' => F::second_key(), 'rule' => $rule ] ] );
	}
	public function test_authority_has_no_implicit_private_projection(): void {
		foreach ( [ static fn( object $value ) => json_encode( $value, JSON_THROW_ON_ERROR ), static fn( object $value ) => serialize( $value ) ] as $encode ) { try { $encode( $this->authority ); self::fail( 'Private authority was exposed.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
		ob_start(); var_dump( $this->authority ); $dump = ob_get_clean(); self::assertStringNotContainsString( 'private_fixed_funder', $dump ); self::assertStringContainsString( 'redacted', $dump ); $this->quiet();
	}
	public function test_request_aware_eligibility_receives_the_exact_frozen_quote_account_and_never_falls_back(): void {
		$guard = new CurrentBudgetBoundEligibilityFixture( $this->request );
		$authority = FixedPromotionBudgetCurrentAuthority::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), [ [ 'component_key' => F::key(), 'account_key' => $this->account ] ], $this->store, $guard );
		self::assertNotNull( $authority->validity_at( $this->session, F::owner(), $this->header, $this->provider->context(), $this->provider->terms(), Q::time()->plus_seconds( 10 ) ) );
		self::assertSame( 1, $guard->bound_calls ); self::assertSame( 0, $guard->legacy_calls ); self::assertSame( $this->request->to_private_json(), $guard->seen[0]->to_private_json() ); $this->quiet();
	}
	public function test_selected_merchant_pool_denial_stops_before_ledger_reads_without_a_legacy_retry(): void {
		$other = FixedPromotionBudgetRequest::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), F::key(), Q::digest( 'another merchant selected pool' ) );
		$guard = new CurrentBudgetBoundEligibilityFixture( $other );
		$authority = FixedPromotionBudgetCurrentAuthority::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), [ [ 'component_key' => F::key(), 'account_key' => $this->account ] ], $this->store, $guard );
		self::assertNull( $authority->validity_at( $this->session, F::owner(), $this->header, $this->provider->context(), $this->provider->terms(), Q::time()->plus_seconds( 10 ) ) );
		self::assertSame( 1, $guard->bound_calls ); self::assertSame( 0, $guard->legacy_calls ); self::assertSame( [], $this->store->locks ); $this->quiet();
	}
	public function test_current_authority_uses_the_account_aware_claim_read_when_available(): void {
		$store = new CurrentBudgetAccountStoreFixture( $this->store );
		$authority = FixedPromotionBudgetCurrentAuthority::from_captured( $this->header, $this->provider->context(), $this->provider->terms(), [ [ 'component_key' => F::key(), 'account_key' => $this->account ] ], $store, $this->eligibility );
		self::assertNotNull( $authority->validity_at( $this->session, F::owner(), $this->header, $this->provider->context(), $this->provider->terms(), Q::time()->plus_seconds( 10 ) ) );
		self::assertSame( [ [ $this->request->claim_key(), $this->account ] ], $store->bound_reads ); $this->quiet();
	}
}

final class CurrentBudgetBoundEligibilityFixture implements FixedPromotionEligibilityBudgetGuard {
	public int $bound_calls = 0; public int $legacy_calls = 0; public array $seen = [];
	public function __construct( private FixedPromotionBudgetRequest $expected ) {}
	public function tables( OperationSession $session ): array { return [ 'wp_campaigns' ]; }
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { ++$this->legacy_calls; throw new \LogicException( 'A bound refusal cannot fall back to unbound eligibility.' ); }
	public function validity_for_requests_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		++$this->bound_calls; $this->seen = $requests;
		return 1 === count( $requests ) && $requests[0]->to_private_json() === $this->expected->to_private_json() ? QuoteCurrentEvidenceValidity::capture( $at, $header->expires_at() ) : null;
	}
}
final class CurrentBudgetAccountStoreFixture implements FixedPromotionBudgetAccountStore {
	public array $bound_reads = [];
	public function __construct( private FixedPromotionBudgetStore $store ) {}
	public function tables( OperationSession $session ): array { return $this->store->tables( $session ); }
	public function lock_account( OperationSession $session, string $key ): ?FixedPromotionBudgetState { return $this->store->lock_account( $session, $key ); }
	public function lock_reservation( OperationSession $session, string $key ): ?FixedPromotionBudgetReservation { throw new \LogicException( 'Use the known account for a current claim read.' ); }
	public function lock_reservation_for_account( OperationSession $session, string $claim_key, string $account_key ): ?FixedPromotionBudgetReservation { $this->bound_reads[] = [ $claim_key, $account_key ]; return $this->store->lock_reservation( $session, $claim_key ); }
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { return $this->store->replace_account( $session, $expected, $next ); }
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool { return $this->store->insert_reservation( $session, $reservation ); }
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { return $this->store->replace_reservation( $session, $expected, $next ); }
}

final class CurrentBudgetEligibilityFixture implements FixedPromotionEligibilityGuard {
	public int $calls = 0; public ?int $owner = null; public string $mode = 'eligible'; public array $declared = [ 'wp_campaigns' ]; public ?\Closure $callback = null; public QuoteTime $until;
	public function __construct() { $this->until = Q::time()->plus_seconds( 180 ); }
	public function tables( OperationSession $session ): array { return $this->declared; }
	public function validity_at( OperationSession $session, QuoteOwner $owner, QuoteHeader $header, QuoteContext $context, QuoteTerms $terms, QuoteTime $at ): ?QuoteCurrentEvidenceValidity {
		++$this->calls; $this->owner = spl_object_id( $session ); if ( null !== $this->callback ) { ( $this->callback )( $session ); }
		if ( 'exception' === $this->mode ) { throw new \RuntimeException( 'Current eligibility unavailable.' ); } if ( 'denied' === $this->mode ) { return null; }
		return match ( $this->mode ) { 'future' => QuoteCurrentEvidenceValidity::capture( $at->plus_seconds( 1 ), $this->until ), 'ended' => QuoteCurrentEvidenceValidity::capture( $at->plus_seconds( -1 ), $at ), default => QuoteCurrentEvidenceValidity::capture( $at, $this->until ) };
	}
}
final class CurrentBudgetStoreFixture implements FixedPromotionBudgetStore {
	public array $accounts = []; public array $claims = []; public array $locks = []; public array $owners = []; public int $writes = 0; public int $metadata = 0; public string $callback_phase = ''; public ?\Closure $callback = null;
	public function tables( OperationSession $session ): array { ++$this->metadata; return [ 'wp_budget_claims', 'wp_budget_accounts' ]; }
	private function touched( OperationSession $session, string $phase, string $key ): void { $this->locks[] = $phase . ':' . $key; $this->owners[] = spl_object_id( $session ); if ( null !== $this->callback && $this->callback_phase === $phase ) { ( $this->callback )( $session ); } }
	public function lock_account( OperationSession $session, string $account_key ): ?FixedPromotionBudgetState { $this->touched( $session, 'account', $account_key ); return $this->accounts[$account_key] ?? null; }
	public function lock_reservation( OperationSession $session, string $claim_key ): ?FixedPromotionBudgetReservation { $this->touched( $session, 'claim', $claim_key ); return $this->claims[$claim_key] ?? null; }
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { ++$this->writes; throw new \LogicException( 'Current authority cannot mutate budget.' ); }
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool { ++$this->writes; throw new \LogicException( 'Current authority cannot mutate budget.' ); }
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { ++$this->writes; throw new \LogicException( 'Current authority cannot mutate budget.' ); }
}
final class CurrentBudgetSessionFixture implements OperationSession {
	public bool $owned = true; public bool $retired = false; public int $site = 1; public string $prefix = 'wp_'; public bool $tables_ok = true; public array $validated = []; public int $ownership_calls = 0;
	public function site_id(): int { return $this->site; } public function table_prefix(): string { return $this->prefix; } public function charset_collate(): string { return 'utf8mb4_unicode_520_ci'; }
	public function begin(): bool { ++$this->ownership_calls; throw new \LogicException(); } public function commit(): OperationCommitResult { ++$this->ownership_calls; throw new \LogicException(); } public function rollback(): bool { ++$this->ownership_calls; throw new \LogicException(); } public function retire(): bool { ++$this->ownership_calls; throw new \LogicException(); }
	public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return $this->owned; } public function validate_tables( array $tables ): bool { $this->validated[] = $tables; return $this->tables_ok; }
	public function query( string $sql ): int|false { throw new \LogicException( 'The authority must use its owned store port.' ); } public function get_row( string $sql ): array|null|false { throw new \LogicException(); } public function get_results( string $sql ): array|false { throw new \LogicException(); }
	public function prepare( string $sql, mixed ...$args ): string { throw new \LogicException(); } public function errno(): int { return 0; } public function insert_id(): int { return 0; }
}
