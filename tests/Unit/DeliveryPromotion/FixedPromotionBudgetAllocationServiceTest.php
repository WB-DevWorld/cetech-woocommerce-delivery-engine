<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionBudgetAllocationService, FixedPromotionBudgetService, FixedPromotionBudgetWriteTrackingStore, FixedPromotionNativeCapture, FixedPromotionNativeRequests};
use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteProvider;
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{DeliveryPromotionPlan, FixedPromotionBudgetAllocationSet, FixedPromotionBudgetRequest, FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteContext, QuoteMoney, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, FixedPromotionQuoteFixtureSource};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';

/** Synthetic complete two-component quotes prove orchestration, not physical SQL or Woo authority. */
final class FixedPromotionBudgetAllocationServiceTest extends TestCase {
	private FixedPromotionBudgetAllocationSet $set;
	private AllocationTestStore $store;
	private AllocationTestSession $session;
	protected function setUp(): void { $this->set = AllocationTestFixture::set(); $this->store = new AllocationTestStore( $this->set ); $this->session = new AllocationTestSession( $this->store ); $this->session->begin(); }
	private function at( int $offset = 0 ): QuoteTime { return $this->set->header()->created_at()->plus_seconds( $offset ); }
	private function service( ?callable $authorize = null, ?FixedPromotionBudgetAccountStore $store = null ): FixedPromotionBudgetAllocationService { return new FixedPromotionBudgetAllocationService( $store ?? $this->store, $authorize ?? static fn(): bool => true ); }
	private function hold( ?FixedPromotionBudgetAllocationService $service = null ): array { return ( $service ?? $this->service() )->reserve_all( $this->session, $this->set, $this->at(), $this->at( 60 ) ); }
	private function refusal( callable $run ): void { try { $run(); self::fail( 'The batch must refuse before mutation.' ); } catch ( \DomainException $error ) { self::assertSame( 'The promotion allocation batch is unavailable.', $error->getMessage() ); } }
	private function uncertain( callable $run ): void { try { $run(); self::fail( 'Attempted writes require caller rollback.' ); } catch ( OperationStorageException ) { self::assertTrue( true ); } }

	public function test_complete_allocation_binds_every_request_to_the_compiled_header(): void {
		self::assertCount( 2, $this->set->requests() ); self::assertCount( 1, $this->set->account_keys() );
		$keys = array_map( static fn( $r ) => $r->claim_key(), $this->set->requests() ); $sorted = $keys; sort( $sorted, SORT_STRING ); self::assertSame( $sorted, $keys );
		foreach ( $this->set->requests() as $r ) { self::assertSame( $this->set->header()->id()->value(), $r->private_facts()['quote_id'] ); self::assertSame( $this->set->header()->body_digest(), $r->private_facts()['body_digest'] ); self::assertSame( $this->set->owner()->digest(), $r->private_facts()['owner_digest'] ); }
		$reordered = FixedPromotionBudgetAllocationSet::from_captured( $this->set->header(), $this->set->context(), $this->set->terms(), array_reverse( $this->set->account_slots() ) ); self::assertSame( $this->set->account_slots(), $reordered->account_slots() ); self::assertSame( $keys, array_map( static fn( $r ) => $r->claim_key(), $reordered->requests() ) );
		$again = AllocationTestFixture::set(); self::assertNotSame( $keys, array_map( static fn( $r ) => $r->claim_key(), $again->requests() ), 'A new compiled header owns different claims.' );
	}
	public function test_caller_references_cannot_rewrite_a_frozen_account_map(): void {
		$slots = $this->set->account_slots(); $external = $slots[0]['account_key']; $slots[0]['account_key'] =& $external;
		$set = FixedPromotionBudgetAllocationSet::from_captured( $this->set->header(), $this->set->context(), $this->set->terms(), $slots ); $before = $set->account_slots(); $external = Q::digest( 'changed outside allocation' );
		self::assertSame( $before, $set->account_slots() ); $returned = $set->account_slots(); $returned[0]['account_key'] = $external; self::assertSame( $before, $set->account_slots() );
	}
	public function test_mixed_quote_keeps_unadjusted_component_explicit_without_fabricating_a_claim(): void {
		$p = F::mixed_provider(); $h = F::header( $p ); $slots = [ [ 'component_key' => F::second_key(), 'account_key' => null ], [ 'component_key' => F::key(), 'account_key' => Q::digest( 'shared' ) ] ];
		$s = FixedPromotionBudgetAllocationSet::from_captured( $h, $p->context(), $p->terms(), $slots ); self::assertCount( 2, $s->account_slots() ); self::assertCount( 1, $s->requests() ); self::assertSame( F::key(), $s->requests()[0]->private_facts()['component_key'] );
	}
	public static function invalid_slots(): array { return [ 'empty' => [ 'empty' ], 'missing component' => [ 'missing' ], 'duplicate component' => [ 'duplicate' ], 'foreign component' => [ 'foreign' ], 'null adjusted account' => [ 'null' ], 'invalid digest' => [ 'digest' ], 'extra field' => [ 'extra' ], 'not a list' => [ 'object' ] ]; }
	#[DataProvider( 'invalid_slots' )]
	public function test_incomplete_or_malformed_mapping_never_creates_an_allocation( string $fault ): void {
		$slots = $this->set->account_slots();
		match ( $fault ) { 'empty' => $slots = [], 'missing' => array_pop( $slots ), 'duplicate' => $slots[] = $slots[0], 'foreign' => $slots[0]['component_key'] = Q::digest( 'foreign' ), 'null' => $slots[0]['account_key'] = null, 'digest' => $slots[0]['account_key'] = 'broken', 'extra' => $slots[0]['extra'] = true, 'object' => $slots = [ 'bad' => $slots[0] ] };
		$this->expectException( \InvalidArgumentException::class ); FixedPromotionBudgetAllocationSet::from_captured( $this->set->header(), $this->set->context(), $this->set->terms(), $slots );
	}
	public function test_unadjusted_component_cannot_choose_an_account(): void {
		$p = F::mixed_provider(); $this->expectException( \InvalidArgumentException::class ); FixedPromotionBudgetAllocationSet::from_captured( F::header( $p ), $p->context(), $p->terms(), [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'one' ) ], [ 'component_key' => F::second_key(), 'account_key' => Q::digest( 'two' ) ] ] );
	}
	public function test_foreign_body_cannot_reuse_a_compiled_header(): void {
		$p = F::mixed_provider(); $this->expectException( \InvalidArgumentException::class ); FixedPromotionBudgetAllocationSet::from_captured( $this->set->header(), $p->context(), $p->terms(), $this->set->account_slots() );
	}
	public function test_all_accounts_then_all_claims_are_locked_before_any_mutation(): void {
		$this->set = AllocationTestFixture::set( false ); $this->store = new AllocationTestStore( $this->set ); $this->session = new AllocationTestSession( $this->store ); $this->session->begin(); $lifecycle = $this->session->lifecycle;
		$claims = $this->hold(); $first = array_slice( $this->store->events, 0, 4 ); $expected = array_map( static fn( $k ) => 'account:' . $k, $this->set->account_keys() ); foreach ( $this->set->requests() as $r ) { $expected[] = 'claim:' . $r->claim_key(); }
		self::assertSame( $expected, $first ); self::assertCount( 2, $claims ); self::assertSame( $lifecycle, $this->session->lifecycle ); self::assertTrue( $this->session->in_transaction() ); self::assertSame( 4, $this->store->writes );
		self::assertSame( array_map( static fn( $r ) => $r->claim_key(), $this->set->requests() ), array_map( static fn( $c ) => $c->request()->claim_key(), $claims ) );
	}
	public function test_explicit_zero_discounts_remain_two_real_zero_claims_on_a_zero_pool(): void {
		$this->set = AllocationTestFixture::set( true, true ); $this->store = new AllocationTestStore( $this->set ); $this->store->reset_accounts( '0.00' ); $this->session = new AllocationTestSession( $this->store ); $this->session->begin();
		$claims = $this->hold(); self::assertCount( 2, $claims ); self::assertSame( [ '0.00', '0.00' ], array_map( static fn( $c ) => $c->request()->amount()->amount(), $claims ) ); self::assertSame( 4, $this->store->writes ); self::assertSame( 3, current( $this->store->accounts )->revision() );
	}
	public function test_shared_pool_aggregate_exhaustion_refuses_before_the_first_write(): void {
		$this->store->reset_accounts( '3.00' ); $this->refusal( fn() => $this->hold() ); self::assertSame( 0, $this->store->writes ); self::assertSame( [], $this->store->claims ); self::assertTrue( current( $this->store->accounts )->held()->zero() );
	}
	public function test_later_account_identity_or_unit_failure_has_no_first_component_effect(): void {
		$this->set = AllocationTestFixture::set( false ); $this->store = new AllocationTestStore( $this->set ); $this->session = new AllocationTestSession( $this->store ); $this->session->begin(); $last = $this->set->requests()[1];
		$this->store->accounts[$last->account_key()] = FixedPromotionBudgetState::create( 1, $last->account_key(), $last->funding_source(), AllocationTestFixture::money( '6.00', 'USD' ) );
		$this->refusal( fn() => $this->hold() ); self::assertSame( 0, $this->store->writes );
	}
	public function test_whole_batch_revision_overflow_refuses_before_any_write(): void {
		$key = $this->set->account_keys()[0]; $facts = $this->store->accounts[$key]->private_facts(); $facts['revision'] = PHP_INT_MAX - 1; $this->store->accounts[$key] = FixedPromotionBudgetState::from_array( $facts );
		$this->refusal( fn() => $this->hold() ); self::assertSame( 0, $this->store->writes );
	}
	public function test_exact_batch_retry_has_no_mutation_and_no_extension(): void {
		$first = $this->hold(); $writes = $this->store->writes; $again = $this->service()->reserve_all( $this->session, $this->set, $this->at( 1 ), $this->at( 60 ) );
		self::assertSame( array_map( static fn( $c ) => $c->to_private_json(), $first ), array_map( static fn( $c ) => $c->to_private_json(), $again ) ); self::assertSame( $writes, $this->store->writes ); self::assertSame( '6.00', current( $this->store->accounts )->held()->amount() );
		$this->refusal( fn() => $this->service()->reserve_all( $this->session, $this->set, $this->at( 2 ), $this->at( 61 ) ) ); self::assertSame( $writes, $this->store->writes );
	}
	public function test_shared_pool_exact_held_member_plus_new_member_charges_only_new_demand(): void {
		$first = $this->set->requests()[0]; $existing = ( new FixedPromotionBudgetService( $this->store, static fn(): bool => true ) )->reserve( $this->session, $first, $this->at(), $this->at( 60 ) ); $writes = $this->store->writes;
		$all = $this->service()->reserve_all( $this->session, $this->set, $this->at( 1 ), $this->at( 60 ) ); self::assertSame( $existing->to_private_json(), $all[0]->to_private_json() ); self::assertSame( $writes + 2, $this->store->writes ); self::assertSame( '6.00', current( $this->store->accounts )->held()->amount() ); self::assertCount( 2, $this->store->claims );
	}
	public function test_later_conflicting_held_deadline_is_found_before_any_new_claim_write(): void {
		$last = $this->set->requests()[1]; $this->store->claims[$last->claim_key()] = FixedPromotionBudgetReservation::hold( $last, $this->at(), $this->at( 59 ) ); $key = $last->account_key(); $this->store->accounts[$key] = $this->store->accounts[$key]->reserve( $last->amount() );
		$this->refusal( fn() => $this->hold() ); self::assertSame( 0, $this->store->writes ); self::assertCount( 1, $this->store->claims );
	}
	public function test_aggregate_existing_hold_proof_cannot_reuse_one_held_amount_twice(): void {
		foreach ( $this->set->requests() as $r ) { $this->store->claims[$r->claim_key()] = FixedPromotionBudgetReservation::hold( $r, $this->at(), $this->at( 60 ) ); }
		$key = $this->set->account_keys()[0]; $this->store->accounts[$key] = $this->store->accounts[$key]->reserve( AllocationTestFixture::money( '3.00' ) );
		$this->refusal( fn() => $this->hold() ); self::assertSame( 0, $this->store->writes );
	}
	public function test_commit_terminal_retry_after_quote_expiry_allocates_only_once(): void {
		$this->hold(); $service = $this->service(); $done = $service->commit_all( $this->session, $this->set, $this->at( 1 ) ); $writes = $this->store->writes; $again = $service->commit_all( $this->session, $this->set, $this->at( 400 ) );
		self::assertSame( array_map( static fn( $c ) => $c->to_private_json(), $done ), array_map( static fn( $c ) => $c->to_private_json(), $again ) ); self::assertSame( $writes, $this->store->writes ); self::assertSame( [ '0.00', '6.00' ], [ current( $this->store->accounts )->held()->amount(), current( $this->store->accounts )->spent()->amount() ] );
		$this->refusal( fn() => $service->release_all( $this->session, $this->set, $this->at( 401 ) ) ); self::assertSame( $writes, $this->store->writes );
	}
	public function test_partial_committed_retry_finishes_remaining_exact_held_claim_once(): void {
		$this->hold(); $first = $this->set->requests()[0]; ( new FixedPromotionBudgetService( $this->store, static fn(): bool => true ) )->commit( $this->session, $first, $this->at( 1 ) ); $writes = $this->store->writes;
		$this->service()->commit_all( $this->session, $this->set, $this->at( 2 ) ); self::assertSame( $writes + 2, $this->store->writes ); self::assertSame( '6.00', current( $this->store->accounts )->spent()->amount() );
	}
	public function test_aggregate_terminal_spent_proof_cannot_reuse_one_spent_amount_twice(): void {
		foreach ( $this->set->requests() as $r ) { $this->store->claims[$r->claim_key()] = FixedPromotionBudgetReservation::hold( $r, $this->at(), $this->at( 60 ) )->commit( $this->at( 1 ) ); }
		$key = $this->set->account_keys()[0]; $this->store->accounts[$key] = $this->store->accounts[$key]->reserve( AllocationTestFixture::money( '3.00' ) )->commit( AllocationTestFixture::money( '3.00' ) );
		$this->refusal( fn() => $this->service()->commit_all( $this->session, $this->set, $this->at( 400 ) ) ); self::assertSame( 0, $this->store->writes );
	}
	public function test_expired_holds_stay_held_until_explicit_current_release_authorization(): void {
		$this->hold(); $writes = $this->store->writes; $this->refusal( fn() => $this->service()->commit_all( $this->session, $this->set, $this->at( 60 ) ) ); self::assertSame( $writes, $this->store->writes ); self::assertSame( '6.00', current( $this->store->accounts )->held()->amount() );
		$denied = $this->service( static fn(): bool => false ); $this->refusal( fn() => $denied->release_all( $this->session, $this->set, $this->at( 400 ) ) ); self::assertSame( $writes, $this->store->writes );
		$released = $this->service()->release_all( $this->session, $this->set, $this->at( 400 ) ); self::assertSame( [ 'released', 'released' ], array_map( static fn( $c ) => $c->state(), $released ) ); self::assertTrue( current( $this->store->accounts )->held()->zero() );
		$writes = $this->store->writes; $this->service()->release_all( $this->session, $this->set, $this->at( 401 ) ); self::assertSame( $writes, $this->store->writes );
	}
	public function test_partial_released_retry_releases_only_remaining_held_member_once(): void {
		$this->hold(); $first = $this->set->requests()[0]; $released = ( new FixedPromotionBudgetService( $this->store, static fn(): bool => true ) )->release( $this->session, $first, $this->at( 1 ) ); $writes = $this->store->writes;
		$all = $this->service()->release_all( $this->session, $this->set, $this->at( 400 ) ); self::assertSame( $released->to_private_json(), $all[0]->to_private_json() ); self::assertSame( $writes + 2, $this->store->writes ); self::assertTrue( current( $this->store->accounts )->held()->zero() ); self::assertTrue( current( $this->store->accounts )->spent()->zero() );
	}
	public function test_second_component_denial_precedes_store_reads_or_any_first_write(): void {
		$last = $this->set->requests()[1]->claim_key(); $this->refusal( fn() => $this->hold( $this->service( static fn( $s, $r ): bool => $r->claim_key() !== $last ) ) ); self::assertSame( [], $this->store->events ); self::assertSame( 0, $this->store->writes );
	}
	public function test_later_revocation_after_first_component_write_is_storage_uncertainty(): void {
		$service = $this->service( fn(): bool => 0 === $this->store->writes ); $this->uncertain( fn() => $this->hold( $service ) ); self::assertSame( 2, $this->store->writes ); self::assertCount( 1, $this->store->claims ); self::assertTrue( $this->session->in_transaction() ); $this->session->rollback(); self::assertSame( [], $this->store->claims ); self::assertTrue( current( $this->store->accounts )->held()->zero() );
	}
	public function test_second_account_write_failure_keeps_first_hold_only_until_caller_rollback(): void {
		$this->store->throw_write = 3; $this->uncertain( fn() => $this->hold() ); self::assertSame( 3, $this->store->writes ); self::assertCount( 1, $this->store->claims ); self::assertTrue( $this->session->in_transaction() ); $this->session->rollback(); self::assertSame( [], $this->store->claims ); self::assertTrue( current( $this->store->accounts )->held()->zero() );
	}
	public function test_failed_first_mutation_is_uncertain_even_when_store_reports_no_change(): void { $this->store->false_write = 1; $this->uncertain( fn() => $this->hold() ); self::assertSame( 1, $this->store->writes ); self::assertSame( [], $this->store->claims ); }
	public function test_outer_write_tracker_observes_nested_allocation_attempt_before_store_throws(): void {
		$outer = new FixedPromotionBudgetWriteTrackingStore( $this->store ); $this->store->throw_write = 1; $this->uncertain( fn() => $this->hold( $this->service( store: $outer ) ) ); self::assertTrue( $outer->write_attempted() );
	}
	public function test_read_only_exact_replay_does_not_mark_outer_write_tracker(): void {
		$this->hold(); $outer = new FixedPromotionBudgetWriteTrackingStore( $this->store ); $this->service( store: $outer )->reserve_all( $this->session, $this->set, $this->at( 1 ), $this->at( 60 ) ); self::assertFalse( $outer->write_attempted() );
	}
	public static function bad_owners(): array { return [ 'retired' => [ 'retired' ], 'not active' => [ 'transaction' ], 'other site' => [ 'site' ], 'empty prefix' => [ 'prefix' ] ]; }
	#[DataProvider( 'bad_owners' )]
	public function test_invalid_owner_has_no_authorizer_or_port_effect( string $fault ): void {
		match ( $fault ) { 'retired' => $this->session->retired = true, 'transaction' => $this->session->transaction = false, 'site' => $this->session->site = 2, default => $this->session->prefix = '' }; $calls = 0;
		$this->refusal( fn() => $this->hold( $this->service( static function() use ( &$calls ): bool { ++$calls; return true; } ) ) ); self::assertSame( 0, $calls ); self::assertSame( [], $this->store->events );
	}
	public function test_authorizer_cannot_switch_prefix_before_store_access(): void { $this->refusal( fn() => $this->hold( $this->service( function(): bool { $this->session->prefix = 'other_'; return true; } ) ) ); self::assertSame( [], $this->store->events ); }
	public function test_authorizer_cannot_switch_charset_before_store_access(): void { $this->refusal( fn() => $this->hold( $this->service( function(): bool { $this->session->charset = 'utf8mb4_bin'; return true; } ) ) ); self::assertSame( [], $this->store->events ); self::assertSame( 0, $this->store->writes ); }
	public function test_charset_change_after_account_read_stops_before_any_claim_or_mutation(): void { $this->store->after_account = function(): void { $this->session->charset = 'utf8mb4_bin'; }; $this->uncertain( fn() => $this->hold() ); self::assertCount( 1, $this->store->events ); self::assertSame( 0, $this->store->writes ); }
	public function test_charset_change_after_account_write_stops_before_claim_write(): void { $this->store->after_write = function(): void { $this->session->charset = 'utf8mb4_bin'; }; $this->uncertain( fn() => $this->hold() ); self::assertSame( 1, $this->store->writes ); self::assertSame( [], $this->store->claims ); }
	public function test_owner_loss_after_account_read_stops_claim_reads_without_a_write(): void { $this->store->after_account = function(): void { $this->session->transaction = false; }; $this->uncertain( fn() => $this->hold() ); self::assertCount( 1, $this->store->events ); self::assertSame( 0, $this->store->writes ); }
	public function test_owner_loss_after_first_account_mutation_stops_claim_mutation(): void { $this->store->after_write = function(): void { $this->session->prefix = 'other_'; }; $this->uncertain( fn() => $this->hold() ); self::assertSame( 1, $this->store->writes ); self::assertSame( [], $this->store->claims ); }
	public function test_services_and_allocation_sets_have_no_implicit_private_projection(): void {
		foreach ( [ $this->set, $this->service(), new FixedPromotionBudgetWriteTrackingStore( $this->store ) ] as $object ) { try { json_encode( $object, JSON_THROW_ON_ERROR ); self::fail( 'Implicit JSON must refuse.' ); } catch ( \LogicException ) { self::assertTrue( true ); } try { serialize( $object ); self::fail( 'Implicit serialization must refuse.' ); } catch ( \LogicException ) { self::assertTrue( true ); } self::assertSame( [ 'redacted' ], array_values( $object->__debugInfo() ) ); }
	}
}

final class AllocationTestFixture {
	private static array $providers = [];
	public static function money( string $amount, string $currency = 'GHS' ): QuoteMoney { return QuoteMoney::from_array( [ 'amount' => $amount, 'currency' => $currency, 'precision' => 2 ] ); }
	public static function set( bool $shared = true, bool $zero = false ): FixedPromotionBudgetAllocationSet {
		$p = self::provider( $zero ); $slots = [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'pool alpha' ) ], [ 'component_key' => F::second_key(), 'account_key' => Q::digest( $shared ? 'pool alpha' : 'pool bravo' ) ] ]; return FixedPromotionBudgetAllocationSet::from_captured( F::header( $p ), $p->context(), $p->terms(), array_reverse( $slots ) );
	}
	private static function provider( bool $zero ): FixedPromotionQuoteProvider {
		if ( isset( self::$providers[(int) $zero] ) ) { return self::$providers[(int) $zero]; } $cd = F::original_context()->private_facts(); $td = F::original_terms()->private_facts(); $line = $cd['lines'][0]; $line['line_key'] = 'line_two'; $line['product_id'] = 11; $line['component_key'] = F::second_key(); $cd['lines'][] = $line;
		$group = $cd['groups'][0]; $group['component_key'] = F::second_key(); $group['line_keys'] = [ 'line_two' ]; $cd['groups'][] = $group; $group = $td['groups'][0]; $group['component_key'] = F::second_key(); $td['groups'][] = $group; $context = QuoteContext::from_array( $cd ); $terms = QuoteTerms::from_array( $td ); $rule = F::rule( $zero ? '0.00' : '3.00' ); $plans = []; $rules = []; $mapping = [];
		foreach ( [ F::key() => 'in_store|delivery|20', F::second_key() => 'second|delivery|20' ] as $key => $legacy ) { $plans[] = [ 'component_key' => $key, 'plan' => DeliveryPromotionPlan::from_evidence( $terms, $key, $rule, 'allocation_fixture', 1, [ 'format_version' => 1, 'provider' => [ 'code' => 'allocation_fixture', 'version' => 1 ], 'terms_digest' => $terms->digest(), 'component_key' => $key, 'rule_digest' => $rule->digest(), 'eligibility' => [ 'state' => 'eligible', 'evidence_digest' => Q::digest( 'explicit eligibility' ) ], 'budget' => [ 'state' => 'available', 'evidence_digest' => Q::digest( 'explicit budget' ) ] ] ) ]; $rules[] = [ 'component_key' => $key, 'rule' => $rule ]; $mapping[] = [ 'component_key' => $key, 'legacy_group_id' => $legacy ]; }
		$requests = FixedPromotionNativeRequests::from_captured( F::original_header( $context, $terms ), $context, $terms, $plans, $mapping ); $facts = F::facts(); if ( $zero ) { $facts['packages'][0]['cost'] = '12.50'; $facts['packages'][0]['taxes'][0]['amount'] = '2.50'; $facts['packages'][0]['fresh_taxes'][0]['amount'] = '2.50'; $facts['packages'][0]['rounded_tax'] = '2.50'; $facts['packages'][0]['display_total'] = '15.00'; } $second = $facts['packages'][0]; $second['group_id'] = 'second|delivery|20'; $second['members'][0]['line_key'] = 'line_two'; $second['members'][0]['product_id'] = 11; $facts['packages'][] = $second; $facts['cart_shipping_total'] = $zero ? '25.00' : '19.00'; $facts['cart_shipping_tax'] = $zero ? '5.00' : '3.80';
		$native = ( new FixedPromotionNativeCapture( new FixedPromotionQuoteFixtureSource( $facts ) ) )->capture( F::owner(), $requests ); return self::$providers[(int) $zero] = FixedPromotionQuoteProvider::from_native_result( $native, $rules );
	}
}

final class AllocationTestStore implements FixedPromotionBudgetAccountStore {
	public array $accounts = []; public array $claims = []; public array $events = []; public int $writes = 0; public int $throw_write = 0; public int $false_write = 0; public ?\Closure $after_account = null; public ?\Closure $after_write = null;
	public function __construct( private FixedPromotionBudgetAllocationSet $set ) { $this->reset_accounts(); }
	public function reset_accounts( string $limit = '6.00' ): void { $this->accounts = []; foreach ( $this->set->requests() as $r ) { $this->accounts[$r->account_key()] = FixedPromotionBudgetState::create( $r->site_id(), $r->account_key(), $r->funding_source(), AllocationTestFixture::money( $limit ) ); } }
	public function tables( OperationSession $session ): array { return [ 'wp_promotion_accounts', 'wp_promotion_claims' ]; }
	public function lock_account( OperationSession $session, string $account_key ): ?FixedPromotionBudgetState { $this->events[] = 'account:' . $account_key; if ( null !== $this->after_account ) { ( $this->after_account )(); } return $this->accounts[$account_key] ?? null; }
	public function lock_reservation( OperationSession $session, string $claim_key ): ?FixedPromotionBudgetReservation { $this->events[] = 'claim:' . $claim_key; return $this->claims[$claim_key] ?? null; }
	public function lock_reservation_for_account( OperationSession $session, string $claim_key, string $account_key ): ?FixedPromotionBudgetReservation { return $this->lock_reservation( $session, $claim_key ); }
	private function writing(): bool { ++$this->writes; $this->events[] = 'write'; if ( $this->writes === $this->throw_write ) { throw new \RuntimeException( 'private write diagnostics' ); } return $this->writes !== $this->false_write; }
	public function replace_account( OperationSession $session, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { if ( ! $this->writing() || ( $this->accounts[$expected->account_key()] ?? null )?->to_private_json() !== $expected->to_private_json() ) { return false; } $this->accounts[$expected->account_key()] = $next; if ( null !== $this->after_write ) { ( $this->after_write )(); } return true; }
	public function insert_reservation( OperationSession $session, FixedPromotionBudgetReservation $reservation ): bool { if ( ! $this->writing() || isset( $this->claims[$reservation->request()->claim_key()] ) ) { return false; } $this->claims[$reservation->request()->claim_key()] = $reservation; if ( null !== $this->after_write ) { ( $this->after_write )(); } return true; }
	public function replace_reservation( OperationSession $session, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { if ( ! $this->writing() || ( $this->claims[$expected->request()->claim_key()] ?? null )?->to_private_json() !== $expected->to_private_json() ) { return false; } $this->claims[$expected->request()->claim_key()] = $next; if ( null !== $this->after_write ) { ( $this->after_write )(); } return true; }
}

final class AllocationTestSession implements OperationSession {
	public bool $transaction = false; public bool $retired = false; public int $site = 1; public string $prefix = 'wp_'; public string $charset = 'utf8mb4_unicode_ci'; public array $lifecycle = []; private array $snapshot = [];
	public function __construct( private AllocationTestStore $store ) {}
	public function site_id(): int { return $this->site; } public function table_prefix(): string { return $this->prefix; } public function charset_collate(): string { return $this->charset; } public function in_transaction(): bool { return $this->transaction; } public function is_retired(): bool { return $this->retired; }
	public function begin(): bool { $this->snapshot = [ $this->store->accounts, $this->store->claims ]; $this->lifecycle[] = 'begin'; return $this->transaction = true; } public function rollback(): bool { [ $this->store->accounts, $this->store->claims ] = $this->snapshot; $this->lifecycle[] = 'rollback'; $this->transaction = false; return true; } public function commit(): OperationCommitResult { $this->lifecycle[] = 'commit'; $this->transaction = false; return OperationCommitResult::Acknowledged; } public function retire(): bool { $this->lifecycle[] = 'retire'; return $this->retired = true; }
	public function validate_tables( array $table_names ): bool { return true; } public function errno(): int { return 0; } public function insert_id(): int { return 0; }
	public function query( string $sql ): int|false { throw new \LogicException( 'The allocation uses its explicit store.' ); } public function get_row( string $sql ): array|null|false { throw new \LogicException( 'The allocation uses its explicit store.' ); } public function get_results( string $sql ): array|false { throw new \LogicException( 'The allocation uses its explicit store.' ); } public function prepare( string $sql, mixed ...$args ): string { throw new \LogicException( 'The allocation uses its explicit store.' ); }
}
