<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteAcceptProfile, FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueProfile, QuoteCurrentEvidenceValidity, QuoteIssueCommand, QuoteOperationProfile, QuotePrivatePublication};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteContext, QuoteHeader, QuoteId, QuoteJson, QuoteMoney, QuoteOwner, QuoteReference, QuoteStoredRow, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationMaterialEvent, OperationRefusal, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteIssueMixedSession, PromotionQuoteIssueSqlPorts};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory, QuoteFixtures as Q};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteIssueMutationTest.php';
require_once __DIR__ . '/FixedPromotionQuoteIssueHistoryTest.php';

/** Real owned SQLite quote/C03 codecs; original/native/current policy and budget ports are synthetic. */
final class FixedPromotionQuoteAcceptProfileTest extends TestCase {

	private PromotionAcceptProfileFixture $f;
	protected function setUp(): void { $this->f = new PromotionAcceptProfileFixture(); }
	protected function tearDown(): void { if ( $this->f->owner->in_transaction() ) { $this->f->owner->rollback(); } }

	public function test_unbound_profile_never_authorizes_and_retains_the_exact_accept_v1_descriptor(): void {
		$p = new FixedPromotionQuoteAcceptProfile(); $old = new QuoteOperationProfile( 'delivery_quote.accept' );
		self::assertSame( $old->operation(), $p->operation() ); self::assertSame( $old->version(), $p->version() ); self::assertFalse( $p->authorize( $this->f->command->identity ) ); self::assertFalse( $p->rejects_are_terminal() );
		foreach ( [ 'result_schema', 'publication_schema', 'actor_schema', 'target_schema' ] as $method ) { self::assertEquals( $old->$method(), $p->$method() ); }
		self::assertSame( $old->reason_codes(), $p->reason_codes() ); self::assertSame( $old->changed_fields(), $p->changed_fields() );
	}
	public function test_history_only_participants_do_not_inspect_current_dependencies_or_reenable_mutation(): void {
		$control = $this->createMock( EmergencyControlStore::class ); $control->expects( $this->never() )->method( 'assert_ready' ); $control->expects( $this->never() )->method( 'current' );
		$ready = $this->createMock( OperationReadiness::class ); $ready->expects( $this->never() )->method( 'assert_ready' );
		$p = new FixedPromotionQuoteAcceptProfile( $this->f->command, static fn(): bool => true, control: $control, current_readiness: $ready );
		self::assertSame( DeliveryQuoteSchema::tables( 'durable_' ), $p->transactional_tables( $this->f->owner ) ); self::assertSame( [], $this->f->trace->events );
		$this->expectException( OperationStorageException::class ); $p->lock_target( $this->f->owner, $this->f->command->identity, $this->f->command );
	}
	public function test_bound_command_validation_rejects_changed_mapping_with_the_same_acceptance_namespace(): void {
		$c = $this->f->command; self::assertSame( $c, $this->f->profile->validate_command( $c->identity, $c ) );
		$data = $c->to_recovery_array(); $data['issue']['original_issue']['account_slots'][0]['account_key'] = Q::digest( 'another explicitly chosen account' ); $other = FixedPromotionQuoteAcceptCommand::from_recovery_array( $data );
		self::assertSame( $c->identity->namespace_digest(), $other->identity->namespace_digest() ); $this->expectException( \InvalidArgumentException::class ); $this->f->profile->validate_command( $other->identity, $other );
	}
	public function test_prelocks_use_nonlocking_issue_history_then_source_all_accounts_claims_and_quote(): void {
		$before = $this->f->budget_bytes(); $this->f->lock(); self::assertSame( $before, $this->f->budget_bytes() ); self::assertSame( 0, $this->f->budget->writes ); self::assertSame( 0, $this->f->owner->cas_attempts );
		$positions = [];
		foreach ( [ 'issue', 'control', 'publication', 'source', 'eligibility', 'account', 'claim', 'quote' ] as $event ) { $positions[] = array_search( $event, $this->f->trace->events, true ); }
		foreach ( $positions as $position ) { self::assertIsInt( $position ); }
		for ( $i = 1; $i < count( $positions ); ++$i ) { self::assertLessThan( $positions[$i], $positions[$i - 1] ); }
		foreach ( $this->f->owner->prerequisite_sql as $sql ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
		self::assertCount( 1, array_keys( $this->f->trace->events, 'account', true ) ); self::assertCount( 2, array_keys( $this->f->trace->events, 'claim', true ) );
	}
	public function test_two_component_acceptance_retains_a_fully_held_shared_pool_without_any_budget_or_admission_write(): void {
		$before = $this->f->budget_bytes(); self::assertTrue( array_values( $this->f->budget->accounts )[0]->remaining()->zero() );
		$mutation = $this->f->mutate( $this->f->lock() ); $row = $this->f->quote();
		self::assertTrue( $mutation->changed ); self::assertSame( 'accepted', $row->state() ); self::assertSame( 2, $row->revision() ); self::assertSame( Q::time()->sql(), $row->accepted_at()->sql() );
		self::assertSame( $before, $this->f->budget_bytes() ); self::assertSame( 0, $this->f->budget->writes ); self::assertSame( 0, $this->f->db->count( 'delivery_quote_budget_windows' ) ); self::assertSame( 1, $this->f->owner->cas_attempts );
		self::assertSame( [ 'quote' ], $mutation->event->changed_fields ); self::assertSame( 3, $this->f->owner->clocks ); self::assertSame( 1, $this->f->ready->calls );
		self::assertSame( $this->f->command->header()->to_private_json(), $row->header()->to_private_json() ); self::assertSame( $this->f->command->context()->to_private_json(), $row->context()->to_private_json() ); self::assertSame( $this->f->command->terms()->to_private_json(), $row->terms()->to_private_json() );
		$old = new QuoteOperationProfile( 'delivery_quote.accept' ); self::assertTrue( $old->validate_accepted_facts( $mutation->completion, $mutation->event ) ); self::assertSame( $mutation->completion->to_json(), OperationCompletion::from_json( $mutation->completion->to_json(), $old )->to_json() ); self::assertSame( $mutation->event->to_json(), OperationMaterialEvent::from_json( $mutation->event->to_json(), $old )->to_json() );
		// The profile stages the quote only. C03 appends the new event/completion.
		self::assertSame( 1, $this->f->db->count( 'operation_records' ) ); self::assertSame( 1, $this->f->db->count( 'operation_changes' ) );
	}
	public function test_distinct_accounts_are_all_locked_in_digest_order_before_any_sorted_claim(): void {
		$this->f->owner->rollback(); $this->f = new PromotionAcceptProfileFixture( true ); $this->f->lock();
		$accounts = array_keys( $this->f->budget->accounts ); sort( $accounts, SORT_STRING ); $claims = array_keys( $this->f->budget->claims ); sort( $claims, SORT_STRING );
		self::assertCount( 2, $accounts ); self::assertSame( $accounts, $this->f->budget->account_reads ); self::assertSame( $claims, $this->f->budget->claim_reads );
		self::assertLessThan( min( array_keys( $this->f->trace->events, 'claim', true ) ), max( array_keys( $this->f->trace->events, 'account', true ) ) );
		self::assertSame( 0, $this->f->budget->writes ); self::assertSame( 0, $this->f->owner->cas_attempts );
	}
	public static function invalid_holds(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'missing', 'released', 'committed', 'changed deadline', 'other request', 'aggregate shortage', 'funding mismatch' ] ); }
	#[DataProvider( 'invalid_holds' )]
	public function test_unusable_current_holds_refuse_without_quote_or_budget_writes( string $case ): void {
		$key = array_key_first( $this->f->budget->claims ); $claim = $this->f->budget->claims[$key]; $account_key = $claim->request()->account_key();
		if ( 'missing' === $case ) { unset( $this->f->budget->claims[$key] ); }
		elseif ( 'released' === $case ) { $this->f->budget->claims[$key] = $claim->release( Q::time() ); }
		elseif ( 'committed' === $case ) { $this->f->budget->claims[$key] = $claim->commit( Q::time() ); }
		elseif ( 'changed deadline' === $case ) { $this->f->budget->claims[$key] = FixedPromotionBudgetReservation::hold( $claim->request(), Q::time(), Q::time()->plus_seconds( 121 ) ); }
		elseif ( 'other request' === $case ) { $other = array_values( $this->f->budget->claims )[1]; $this->f->budget->claims[$key] = $other; }
		else { $data = $this->f->budget->accounts[$account_key]->private_facts(); if ( 'aggregate shortage' === $case ) { $data['held']['amount'] = '3.00'; } else { $data['funding_source']['code'] = 'foreign_funding'; } $this->f->budget->accounts[$account_key] = FixedPromotionBudgetState::from_array( $data ); }
		$before = $this->f->budget_bytes();
		try { $this->f->mutate( $this->f->lock() ); self::fail( 'Every exact live hold is required.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( 'issued', $this->f->quote()->state() ); self::assertSame( $before, $this->f->budget_bytes() ); self::assertSame( 0, $this->f->budget->writes ); }
	}
	public function test_changed_original_source_refuses_before_any_budget_or_quote_target_lock(): void {
		$this->f->owner->inner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01';
		try { $this->f->lock(); self::fail( 'Changed source must refuse.' ); } catch ( OperationRefusal ) { self::assertNotContains( 'account', $this->f->trace->events ); self::assertNotContains( 'quote', $this->f->trace->events ); self::assertSame( 0, $this->f->owner->cas_attempts ); }
	}
	public function test_corrupt_original_issue_intent_is_not_an_acceptance_grant(): void {
		$this->f->db->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET intent_hash='" . Q::digest( 'another issue intent' ) . "'" );
		try { $this->f->lock(); self::fail( 'The original immutable producer must authenticate.' ); } catch ( OperationStorageException ) { self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( 'issued', $this->f->quote()->state() ); }
	}
	public function test_a_target_wait_crossing_the_hold_deadline_refuses_before_the_full_proof_or_cas(): void {
		$target = $this->f->lock(); $this->f->owner->before_time = $this->f->command->hold_until();
		try { $this->f->mutate( $target ); self::fail( 'Target wait cannot extend the hold.' ); } catch ( OperationRefusal ) { self::assertSame( 1, $this->f->eligibility->calls ); self::assertSame( 0, $this->f->owner->cas_attempts ); }
	}
	public static function late_failures(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'hold expiry', 'header expiry', 'backward clock', 'initial proof expiry', 'final proof expiry', 'authorization', 'owner rebound' ] ); }
	#[DataProvider( 'late_failures' )]
	public function test_final_fences_after_the_full_current_proof_refuse_before_the_quote_write( string $case ): void {
		if ( 'initial proof expiry' === $case ) { $this->f->eligibility->seconds = 1; }
		$target = $this->f->lock();
		if ( 'hold expiry' === $case ) { $this->f->owner->final_time = $this->f->command->hold_until(); }
		elseif ( 'header expiry' === $case ) { $this->f->owner->final_time = $this->f->command->header()->expires_at(); }
		elseif ( 'backward clock' === $case ) { $this->f->owner->final_time = Q::time()->plus_seconds( -1 ); }
		elseif ( 'initial proof expiry' === $case ) { $this->f->eligibility->seconds = 300; $this->f->owner->final_time = Q::time()->plus_seconds( 1 ); }
		elseif ( 'final proof expiry' === $case ) { $this->f->eligibility->seconds = 1; $this->f->owner->final_time = Q::time()->plus_seconds( 1 ); }
		elseif ( 'authorization' === $case ) { $this->f->eligibility->on_proof = function(): void { $this->f->authorized = false; }; }
		elseif ( 'owner rebound' === $case ) { $this->f->eligibility->on_proof = function(): void { $this->f->owner->rebound = true; }; }
		$before = $this->f->budget_bytes();
		try { $this->f->mutate( $target ); self::fail( 'A late proof cannot authorize acceptance.' ); } catch ( OperationRefusal|OperationStorageException ) { self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( $before, $this->f->budget_bytes() ); self::assertSame( 'issued', $this->f->quote()->state() ); }
	}
	public function test_quote_cas_miss_requires_caller_rollback_and_preserves_all_holds(): void {
		$target = $this->f->lock(); $before = $this->f->budget_bytes(); $this->f->owner->reject_cas = true;
		try { $this->f->mutate( $target ); self::fail( 'CAS miss cannot report acceptance.' ); } catch ( OperationStorageException ) { self::assertSame( 1, $this->f->owner->cas_attempts ); self::assertTrue( $this->f->owner->in_transaction() ); }
		self::assertTrue( $this->f->owner->rollback() ); self::assertSame( 'issued', $this->f->quote()->state() ); self::assertSame( $before, $this->f->budget_bytes() );
	}
	public function test_owner_loss_after_a_staged_quote_update_requires_rollback_of_that_update(): void {
		$target = $this->f->lock(); $before = $this->f->budget_bytes(); $this->f->owner->rebind_after_cas = true;
		try { $this->f->mutate( $target ); self::fail( 'Post-write owner loss must be storage failure.' ); } catch ( OperationStorageException ) { self::assertSame( 1, $this->f->owner->cas_attempts ); self::assertSame( 'accepted', $this->f->quote()->state() ); }
		self::assertTrue( $this->f->owner->rollback() ); self::assertSame( 'issued', $this->f->quote()->state() ); self::assertSame( $before, $this->f->budget_bytes() ); self::assertSame( 1, $this->f->db->count( 'operation_records' ) );
	}
	public function test_recorded_publication_is_historical_and_requires_the_exact_bound_quote(): void {
		$mutation = $this->f->mutate( $this->f->lock() ); $probe = new PromotionAcceptPublicationProbe(); $p = new FixedPromotionQuoteAcceptProfile( $this->f->command, static fn(): bool => true, publication: $probe );
		self::assertTrue( $p->publish( $this->f->command->identity, $mutation->completion ) ); self::assertSame( [ $this->f->command->header()->id()->value() ], $probe->ids );
		$result = $mutation->completion->result; $result['body_digest'] = Q::digest( 'other original body' ); $wrong = OperationCompletion::accepted( $p, $result, $mutation->completion->publication ); self::assertFalse( $p->publish( $this->f->command->identity, $wrong ) ); self::assertCount( 1, $probe->ids );
	}
}

final class PromotionAcceptProfileFixture {
	public QuoteDurableFixtureFactory $db;
	public PromotionAcceptMutationOwner $owner;
	public PromotionMutationTrace $trace;
	public PromotionAcceptMutationBudget $budget;
	public PromotionAcceptMutationEligibility $eligibility;
	public PromotionMutationReadiness $ready;
	public FixedPromotionQuoteAcceptCommand $command;
	public FixedPromotionQuoteAcceptProfile $profile;
	public bool $authorized = true;
	public function __construct( bool $separate_accounts = false ) {
		$this->db = new QuoteDurableFixtureFactory(); $this->trace = new PromotionMutationTrace(); $this->owner = new PromotionAcceptMutationOwner( new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->db->open() ), $this->trace ), $this->trace ); $this->owner->begin();
		$inputs = PromotionQuoteIssueSqlPorts::capture( $this->owner ); if ( $separate_accounts ) { $inputs['slots'][1]['account_key'] = Q::digest( 'E11 distinct second pool' ); } $provider = $inputs['provider']->inner; $original = QuoteIssueCommand::fixed_promotion( F::owner(), $provider->context(), QuoteId::generate()->value(), $inputs['slots'], Q::time()->plus_seconds( 120 ) ); $id = QuoteId::generate(); $ref = QuoteReference::generate( $id );
		$header = QuoteHeader::issue( $id, F::owner(), $provider->context(), $provider->terms(), Q::time(), $original->namespace_hashes( $id ), $original->profile(), 1, $ref ); $issue = FixedPromotionQuoteIssueCommand::captured( $original, PromotionIssueHistoryFactory::lease( $original ), DeliveryQuote::issue( $header, $provider->context(), $provider->terms() ), $ref ); $this->command = FixedPromotionQuoteAcceptCommand::from_issue( $issue );
		$row = [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $id->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'profile_version' => 1, 'purpose' => $header->purpose(), 'principal_hash' => F::owner()->facts()['principal_hash'], 'owner_digest' => F::owner()->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $provider->context()->private_facts(), 'terms' => $provider->terms()->private_facts() ] ), 'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'], 'state' => 'issued', 'revision' => 1, 'retention_revision' => 1, 'created_at' => Q::time()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => null, 'transition_at' => null ];
		( new DeliveryQuoteRepository( $this->owner ) )->insert_quote( QuoteStoredRow::from_row( $row ) ); $this->seed_issue_receipt( $issue );
		// Establish only the owned fixture baseline; the profile never controls it.
		$this->db->pdo->commit(); $this->owner->begin();
		$this->budget = new PromotionAcceptMutationBudget( $this->trace );
		foreach ( $issue->allocation_set()->requests() as $request ) {
			$key = $request->account_key(); $account = $this->budget->accounts[$key] ?? FixedPromotionBudgetState::create( 1, $key, $request->funding_source(), QuoteMoney::from_array( [ 'amount' => '6.00', 'currency' => 'GHS', 'precision' => 2 ] ) ); $this->budget->accounts[$key] = $account->reserve( $request->amount() ); $this->budget->claims[$request->claim_key()] = FixedPromotionBudgetReservation::hold( $request, Q::time(), $issue->hold_until() );
		}
		$this->eligibility = new PromotionAcceptMutationEligibility( $this->trace ); $this->ready = new PromotionMutationReadiness( $this->trace ); $this->profile = new FixedPromotionQuoteAcceptProfile( $this->command, fn(): bool => $this->authorized, FixedPromotionQuoteAcceptInputs::from_sources( $inputs['original'], $inputs['fresh'] ), $this->budget, $this->eligibility, new PromotionMutationControl( $this->trace ), current_readiness: $this->ready );
		$this->trace->events = []; $this->owner->clocks = 0; $this->owner->prerequisite_sql = []; $this->profile->transactional_tables( $this->owner );
	}
	private function seed_issue_receipt( FixedPromotionQuoteIssueCommand $c ): void {
		$p = new FixedPromotionQuoteIssueProfile(); $i = $c->identity; $h = $c->header(); $allocation = [ 'allocation_digest' => $c->allocation_digest(), 'allocation_count' => count( $c->allocation_set()->requests() ), 'hold_until' => $c->hold_until()->epoch_microseconds() ];
		$result = [ 'quote_id' => $h->id()->value(), 'quote_revision' => 1, 'state' => 'issued', 'body_digest' => $h->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $i->namespace_digest(), 'completed_at' => Q::time()->epoch_microseconds(), 'control_revision' => 1, ...$allocation ]; $completion = OperationCompletion::accepted( $p, $result, [ 'site_id' => 1, 'owner_digest' => $c->owner()->digest(), 'quote_id' => $h->id()->value(), 'purpose' => 'private_quote_invalidation' ] );
		$event = OperationMaterialEvent::from_mutation( $p, $i, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $i->authority ), 'principal_hash' => hash( 'sha256', $i->principal ) ], [ 'quote_id' => $h->id()->value(), 'body_digest' => $h->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $i->namespace_digest(), 'quote_revision' => 1, 'binding_revision' => 0, ...$allocation ], 'quote_issued', 1, 2, [ 'quote', 'admission', 'promotion_budget' ] );
		$this->insert( 'operation_records', [ 'id' => 1, 'site_id' => 1, 'namespace_hash' => $i->namespace_digest(), 'intent_hash' => $c->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => $i->operation, 'operation_version' => 2, 'target_hash' => WpdbOperationRecordRepository::target_hash( $i ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => 1, 'row_version' => 3, 'created_at' => Q::time()->sql(), 'updated_at' => Q::time()->sql(), 'completed_at' => Q::time()->sql() ] );
		$this->insert( 'operation_changes', [ 'id' => 1, 'site_id' => 1, 'operation_id' => 1, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => Q::time()->sql() ] );
	}
	private function insert( string $suffix, array $row ): void { $columns = implode( ',', array_keys( $row ) ); $placeholders = implode( ',', array_fill( 0, count( $row ), '?' ) ); $statement = $this->db->pdo->prepare( "INSERT INTO durable_delivery_engine_{$suffix} ({$columns}) VALUES ({$placeholders})" ); $statement->execute( array_values( $row ) ); }
	public function lock() { return $this->profile->lock_target( $this->owner, $this->command->identity, $this->command ); }
	public function mutate( $target ) { return $this->profile->mutate( $this->owner, $this->command->identity, $this->command, $target, RequestContext::create() ); }
	public function quote(): QuoteStoredRow { return QuoteStoredRow::from_row( $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quotes' )->fetch( \PDO::FETCH_ASSOC ) ); }
	public function budget_bytes(): array { return [ array_map( static fn( $v ): string => $v->to_private_json(), $this->budget->accounts ), array_map( static fn( $v ): string => $v->to_private_json(), $this->budget->claims ) ]; }
}

final class PromotionAcceptMutationOwner implements OperationSession {
	public int $clocks = 0; public int $cas_attempts = 0; public ?QuoteTime $before_time = null; public ?QuoteTime $final_time = null; public bool $rebound = false; public bool $reject_cas = false; public bool $rebind_after_cas = false; public array $prerequisite_sql = [];
	public function __construct( public readonly PromotionMutationOwner $inner, private PromotionMutationTrace $trace ) {}
	public function site_id(): int { return $this->inner->site_id(); } public function table_prefix(): string { return $this->inner->table_prefix(); } public function charset_collate(): string { return $this->rebound ? 'utf8mb4_bin' : $this->inner->charset_collate(); }
	public function begin(): bool { return $this->inner->begin(); } public function commit(): OperationCommitResult { throw new \LogicException( 'Acceptance profile cannot commit.' ); } public function rollback(): bool { return $this->inner->rollback(); } public function retire(): bool { return $this->inner->retire(); } public function is_retired(): bool { return $this->inner->is_retired(); } public function in_transaction(): bool { return $this->inner->in_transaction(); } public function validate_tables( array $tables ): bool { return $this->inner->validate_tables( $tables ); }
	public function query( string $sql ): int|false {
		if ( str_starts_with( $sql, 'UPDATE `durable_delivery_engine_delivery_quotes`' ) ) { ++$this->cas_attempts; if ( $this->reject_cas ) { return false; } $result = $this->inner->query( $sql ); if ( $this->rebind_after_cas ) { $this->rebound = true; } return $result; } return $this->inner->query( $sql );
	}
	public function get_row( string $sql ): array|null|false { if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { ++$this->clocks; return [ 'utc' => ( 3 === $this->clocks ? $this->final_time : ( 2 === $this->clocks ? $this->before_time : null ) )?->sql() ?? Q::time()->sql() ]; } return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false { if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_operation_records`' ) ) { $this->trace->events[] = 'issue'; $this->prerequisite_sql[] = $sql; } return $this->inner->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); }
}
final class PromotionAcceptMutationEligibility implements FixedPromotionEligibilityBudgetGuard {
	public int $calls = 0; public int $seconds = 300; public ?\Closure $on_proof = null;
	public function __construct( private PromotionMutationTrace $trace ) {}
	public function tables( OperationSession $s ): array { return [ $s->table_prefix() . 'synthetic_policies' ]; }
	public function validity_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { throw new \LogicException( 'Acceptance requires account-aware eligibility.' ); }
	public function validity_for_requests_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { ++$this->calls; $this->trace->events[] = 'eligibility'; if ( null !== $this->on_proof ) { ( $this->on_proof )(); } return QuoteCurrentEvidenceValidity::capture( $at, $at->plus_seconds( $this->seconds ) ); }
}
final class PromotionAcceptMutationBudget implements FixedPromotionBudgetAccountStore {
	public array $accounts = []; public array $claims = []; public array $account_reads = []; public array $claim_reads = []; public int $writes = 0;
	public function __construct( private PromotionMutationTrace $trace ) {}
	public function tables( OperationSession $s ): array { return [ $s->table_prefix() . 'synthetic_accounts', $s->table_prefix() . 'synthetic_claims' ]; }
	public function lock_account( OperationSession $s, string $key ): ?FixedPromotionBudgetState { $this->trace->events[] = 'account'; $this->account_reads[] = $key; return $this->accounts[$key] ?? null; }
	public function lock_reservation( OperationSession $s, string $key ): ?FixedPromotionBudgetReservation { throw new \LogicException( 'Acceptance must scope the exact account.' ); }
	public function lock_reservation_for_account( OperationSession $s, string $key, string $account ): ?FixedPromotionBudgetReservation { $this->trace->events[] = 'claim'; $this->claim_reads[] = $key; return $this->claims[$key] ?? null; }
	public function replace_account( OperationSession $s, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { ++$this->writes; throw new \LogicException( 'Acceptance cannot change accounts.' ); }
	public function insert_reservation( OperationSession $s, FixedPromotionBudgetReservation $claim ): bool { ++$this->writes; throw new \LogicException( 'Acceptance cannot reserve funds.' ); }
	public function replace_reservation( OperationSession $s, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { ++$this->writes; throw new \LogicException( 'Acceptance cannot extend, commit or release holds.' ); }
}
final class PromotionAcceptPublicationProbe implements QuotePrivatePublication { public array $ids = []; public function invalidate( int $site_id, string $owner_digest, QuoteId $quote_id ): bool { $this->ids[] = $quote_id->value(); return true; } }
