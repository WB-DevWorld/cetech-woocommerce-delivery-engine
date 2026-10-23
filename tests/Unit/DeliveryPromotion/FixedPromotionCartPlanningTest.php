<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionCartPlanning};
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteMoney, QuoteOwner, QuoteTerms};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationConnectionFactory, OperationSession};
use CetechDeliveryEngine\Domain\RuleLifecycle\{RuleContent, RuleStartMode, RuleTime};
use CetechDeliveryEngine\Infrastructure\Persistence\{FixedPromotionBudgetSchema, RuleLifecycleSchema, SqlFixedPromotionRuleEligibilityGuard};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionEligibilityFixture as E, PromotionEligibilitySession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Owned SQL selection model only; this does not boot Woo or authorize merchant policy. */
final class FixedPromotionCartPlanningTest extends TestCase {
	private CartPlanningSession $session;
	private CartPlanningStore $store;
	private OperationConnectionFactory $factory;
	private int $authorizations = 0;
	protected function setUp(): void {
		$this->session = new CartPlanningSession(); $this->store = new CartPlanningStore();
		$this->store->accounts[E::account()] = $this->account();
		$this->factory = new class( $this->session ) implements OperationConnectionFactory {
			public int $opens = 0;
			public function __construct( private OperationSession $session ) {}
			public function open(): OperationSession { ++$this->opens; return $this->session; }
		};
	}
	private function account( string $limit = '20.00' ): FixedPromotionBudgetState { return FixedPromotionBudgetState::create( 1, E::account(), F::rule()->private_facts()['funding_source'], QuoteMoney::from_array( [ 'amount' => $limit, 'currency' => 'GHS', 'precision' => 2 ] ) ); }
	private function selector( ?callable $authorize = null ): FixedPromotionCartPlanning {
		return new FixedPromotionCartPlanning( $this->factory, new SqlFixedPromotionRuleEligibilityGuard( E::family() ), $this->store, $authorize ?? function( QuoteOwner $owner ): bool { ++$this->authorizations; self::assertFalse( $this->session->active ); return $owner->equals( F::owner() ); } );
	}
	private function capture( ?array $rules = null ): ?array { return $this->selector()->capture( F::owner(), F::original_context(), F::original_terms(), $rules ?? F::rules(), E::at() ); }
	public function test_published_policy_derives_existing_funded_pool_and_returns_only_after_cleanup(): void {
		$before = $this->store->accounts[E::account()]->to_private_json(); $result = $this->capture(); self::assertNotNull( $result );
		self::assertTrue( $result['plans'][0]['plan']->planned() ); self::assertSame( '9.50', $result['plans'][0]['plan']->private_facts()['money']['final']['amount'] );
		self::assertSame( [ [ 'component_key' => F::key(), 'account_key' => E::account() ] ], $result['account_slots'] ); self::assertSame( F::key(), $result['rules'][0]['component_key'] ); self::assertSame( F::rule()->digest(), $result['rules'][0]['rule']->digest() );
		self::assertSame( [ 'begin', 'rollback', 'retire' ], $this->session->events ); self::assertTrue( $this->session->retired ); self::assertFalse( $this->session->active );
		self::assertSame( 2, $this->authorizations ); self::assertSame( [ E::account() ], $this->store->locks ); self::assertSame( $before, $this->store->accounts[E::account()]->to_private_json() );
	}
	public function test_complete_null_slots_are_preserved_for_unpromoted_groups(): void {
		$base = F::mixed_result()->original_requests(); $rules = F::mixed_rules();
		$result = $this->selector()->capture( F::owner(), $base->original_context(), $base->original_terms(), $rules, E::at() ); self::assertNotNull( $result );
		$plans = array_column( $result['plans'], 'plan', 'component_key' ); $accounts = array_column( $result['account_slots'], 'account_key', 'component_key' );
		self::assertNull( $plans[F::second_key()] ); self::assertArrayHasKey( F::second_key(), $accounts ); self::assertNull( $accounts[F::second_key()] ); self::assertSame( E::account(), $accounts[F::key()] );
	}
	public function test_shared_pool_must_fund_the_sum_and_is_locked_once(): void {
		$base = F::mixed_result()->original_requests(); $scope = E::scope(); $scope['product_ids'] = [ 10, 11 ]; $family = E::family();
		$this->session->reader->rows[1][0]['scope_json'] = $family->scope_schema()->encode( $scope ); $this->session->reader->rows[1][0]['scope_hash'] = RuleContent::scope_hash( $family, $scope );
		$this->store->accounts[E::account()] = $this->account( '5.00' );
		$rules = [ ...F::rules(), [ 'component_key' => F::second_key(), 'rule' => F::rule() ] ];
		self::assertNull( $this->selector()->capture( F::owner(), $base->original_context(), $base->original_terms(), $rules, E::at() ) );
		self::assertSame( [ E::account() ], $this->store->locks ); self::assertSame( '0.00', $this->store->accounts[E::account()]->held()->amount() ); self::assertTrue( $this->session->retired );
	}
	public function test_distinct_published_pools_are_derived_and_locked_in_global_key_order(): void {
		$base = F::mixed_result()->original_requests(); $family = E::family(); $scope = E::scope(); $scope['product_ids'] = [ 11 ]; $second = Q::digest( 'second published pool' );
		$logical = $this->session->reader->rows[1][0]; $logical['id'] = 2; $logical['logical_uuid'] = E::uuid( 2 ); $logical['current_published_version_id'] = 2; $logical['scope_json'] = $family->scope_schema()->encode( $scope ); $logical['scope_hash'] = RuleContent::scope_hash( $family, $scope );
		$version = $this->session->reader->rows[2][0]; $version['id'] = 2; $version['logical_rule_id'] = 2; $version['version_uuid'] = E::uuid( 102 ); $payload = [ 'rule' => F::rule()->private_facts(), 'budget_account_key' => $second ];
		$version['payload_json'] = $family->payload_schema()->encode( $payload ); $version['content_hash'] = RuleContent::hash( $family, $payload, RuleStartMode::Immediate, RuleTime::parse( E::PUBLISHED ), null, 10, null );
		$this->session->reader->rows[1][] = $logical; $this->session->reader->rows[2][] = $version;
		$this->store->accounts[$second] = FixedPromotionBudgetState::create( 1, $second, F::rule()->private_facts()['funding_source'], QuoteMoney::from_array( [ 'amount' => '10.00', 'currency' => 'GHS', 'precision' => 2 ] ) );
		$rules = [ [ 'component_key' => F::second_key(), 'rule' => F::rule() ], ...F::rules() ];
		$found = $this->selector()->capture( F::owner(), $base->original_context(), $base->original_terms(), $rules, E::at() ); self::assertNotNull( $found );
		$keys = [ E::account(), $second ]; sort( $keys, SORT_STRING ); self::assertSame( $keys, $this->store->locks );
		$expected = [ F::key() => E::account(), F::second_key() => $second ]; ksort( $expected, SORT_STRING ); self::assertSame( $expected, array_column( $found['account_slots'], 'account_key', 'component_key' ) );
	}
	#[DataProvider('invalid_slots')]
	public function test_rule_selection_is_complete_typed_and_has_no_browser_account_override( array $rules ): void { self::assertNull( $this->capture( $rules ) ); self::assertSame( 0, $this->factory->opens ); }
	public static function invalid_slots(): array { return [
		[ [] ], [ [ [ 'component_key' => F::key(), 'rule' => null ] ] ], [ [ ...F::rules(), ...F::rules() ] ],
		[ [ [ 'component_key' => Q::digest( 'foreign' ), 'rule' => F::rule() ] ] ],
		[ [ [ 'component_key' => F::key(), 'rule' => F::rule()->private_facts() ] ] ],
		[ [ [ 'component_key' => F::key(), 'rule' => F::rule(), 'account_key' => E::account() ] ] ],
	]; }
	#[DataProvider('refusals')]
	public function test_policy_and_account_refusals_release_the_owned_reader_without_a_plan( string $change ): void {
		if ( 'retired' === $change ) { $this->session->reader->rows = E::retired_rows(); }
		elseif ( 'missing' === $change ) { $this->store->accounts = []; }
		elseif ( 'held' === $change ) { $this->store->accounts[E::account()] = $this->account()->reserve( QuoteMoney::from_array( [ 'amount' => '18.00', 'currency' => 'GHS', 'precision' => 2 ] ) ); }
		elseif ( 'foreign_funder' === $change ) { $data = $this->account()->private_facts(); $data['funding_source']['code'] = 'foreign_funder'; $this->store->accounts[E::account()] = FixedPromotionBudgetState::from_array( $data ); }
		elseif ( 'foreign_site' === $change ) { $data = $this->account()->private_facts(); $data['site_id'] = 2; $this->store->accounts[E::account()] = FixedPromotionBudgetState::from_array( $data ); }
		elseif ( 'foreign_precision' === $change ) { $data = $this->account()->private_facts(); foreach ( [ 'limit', 'held', 'spent' ] as $field ) { $data[$field]['precision'] = 6; } $this->store->accounts[E::account()] = FixedPromotionBudgetState::from_array( $data ); }
		else { $data = $this->account()->private_facts(); foreach ( [ 'limit', 'held', 'spent' ] as $field ) { $data[$field]['currency'] = 'USD'; } $this->store->accounts[E::account()] = FixedPromotionBudgetState::from_array( $data ); }
		self::assertNull( $this->capture() ); self::assertTrue( $this->session->retired ); self::assertFalse( $this->session->active );
	}
	public static function refusals(): array { return array_map( static fn( string $c ): array => [ $c ], [ 'retired', 'missing', 'held', 'foreign_funder', 'foreign_site', 'foreign_currency', 'foreign_precision' ] ); }
	#[DataProvider('cleanup_faults')]
	public function test_cleanup_and_owner_fences_do_not_expose_detached_plans( string $fault ): void {
		if ( 'rollback' === $fault ) { $this->session->rollback_ok = false; }
		elseif ( 'retire' === $fault ) { $this->session->retire_ok = false; }
		elseif ( 'retire_lie' === $fault ) { $this->session->retire_lie = true; }
		elseif ( 'read_owner' === $fault ) { $this->store->after = function(): void { $this->session->active = false; }; }
		elseif ( 'prefix' === $fault ) { $this->store->after = function(): void { $this->session->prefix = 'other_'; }; }
		else { $this->session->begin_ok = false; }
		self::assertNull( $this->capture() ); self::assertContains( 'retire', $this->session->events ); self::assertSame( 1, $this->authorizations );
	}
	public static function cleanup_faults(): array { return array_map( static fn( string $c ): array => [ $c ], [ 'rollback', 'retire', 'retire_lie', 'read_owner', 'prefix', 'begin' ] ); }
	public function test_authorization_denial_before_open_and_after_retirement_fails_closed(): void {
		self::assertNull( $this->selector( static fn(): bool => false )->capture( F::owner(), F::original_context(), F::original_terms(), F::rules(), E::at() ) ); self::assertSame( 0, $this->factory->opens );
		$calls = 0; $authorize = function() use ( &$calls ): bool { self::assertFalse( $this->session->active ); return 1 === ++$calls; };
		self::assertNull( $this->selector( $authorize )->capture( F::owner(), F::original_context(), F::original_terms(), F::rules(), E::at() ) ); self::assertTrue( $this->session->retired ); self::assertSame( 2, $calls );
	}
	public function test_true_final_authorization_cannot_hide_owner_metadata_drift_after_retirement(): void {
		$calls = 0; $authorize = function() use ( &$calls ): bool { self::assertFalse( $this->session->active ); if ( 2 === ++$calls ) { self::assertTrue( $this->session->retired ); $this->session->prefix = 'other_'; } return true; };
		self::assertNull( $this->selector( $authorize )->capture( F::owner(), F::original_context(), F::original_terms(), F::rules(), E::at() ) ); self::assertSame( 2, $calls );
	}
}

final class CartPlanningStore implements FixedPromotionBudgetAccountStore {
	public array $accounts = []; public array $locks = []; public ?\Closure $after = null;
	public function tables( OperationSession $session ): array { return FixedPromotionBudgetSchema::tables( $session->table_prefix() ); }
	public function lock_account( OperationSession $session, string $account_key ): ?FixedPromotionBudgetState { $this->locks[] = $account_key; if ( null !== $this->after ) { ( $this->after )(); } return $this->accounts[$account_key] ?? null; }
	public function lock_reservation( OperationSession $session, string $claim_key ): ?FixedPromotionBudgetReservation { throw new \LogicException( 'Planning must not read reservations.' ); }
	public function lock_reservation_for_account( OperationSession $session, string $claim_key, string $account_key ): ?FixedPromotionBudgetReservation { throw new \LogicException( 'Planning must not read reservations.' ); }
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { throw new \LogicException( 'Planning must not write.' ); }
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool { throw new \LogicException( 'Planning must not write.' ); }
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { throw new \LogicException( 'Planning must not write.' ); }
}

final class CartPlanningSession implements OperationSession {
	public PromotionEligibilitySession $reader; public bool $active = false; public bool $retired = false; public string $prefix = 'proof_'; public array $events = [];
	public bool $begin_ok = true; public bool $rollback_ok = true; public bool $retire_ok = true; public bool $retire_lie = false;
	public function __construct() { $this->reader = new PromotionEligibilitySession(); }
	public function site_id(): int { return 1; } public function table_prefix(): string { return $this->prefix; } public function charset_collate(): string { return $this->reader->charset_collate(); }
	public function begin(): bool { $this->events[] = 'begin'; $this->active = $this->begin_ok; return $this->begin_ok; }
	public function commit(): OperationCommitResult { throw new \LogicException( 'Planning must not commit.' ); }
	public function rollback(): bool { $this->events[] = 'rollback'; if ( $this->rollback_ok ) { $this->active = false; } return $this->rollback_ok; }
	public function retire(): bool { $this->events[] = 'retire'; if ( $this->retire_ok && ! $this->retire_lie ) { $this->retired = true; $this->active = false; } return $this->retire_ok; }
	public function is_retired(): bool { return $this->retired; } public function in_transaction(): bool { return $this->active; }
	public function validate_tables( array $table_names ): bool { $all = array_merge( RuleLifecycleSchema::tables( $this->prefix ), FixedPromotionBudgetSchema::tables( $this->prefix ) ); return [] !== $table_names && [] === array_diff( $table_names, $all ); }
	public function query( string $sql ): int|false { throw new \LogicException( 'Planning must not write SQL.' ); }
	public function get_row( string $sql ): array|null|false { return $this->reader->get_row( $sql ); }
	public function get_results( string $sql ): array|false { return $this->reader->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->reader->prepare( $sql, ...$args ); }
	public function errno(): int { return $this->reader->errno(); } public function insert_id(): int { return 0; }
}
