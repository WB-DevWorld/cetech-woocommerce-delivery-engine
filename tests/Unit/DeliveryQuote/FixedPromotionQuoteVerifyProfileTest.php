<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteVerifyCommand, FixedPromotionQuoteVerifyInputs, FixedPromotionQuoteVerifyProfile, QuoteOperationProfile, QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetReservation;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationMaterialEvent, OperationRefusal, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteSchema, EmergencyControlStore, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionQuoteIssueMixedSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteBindProfileTest.php';

/** SQLite persistence/receipt codecs with explicitly synthetic source, order, saved and funding ports. */
final class FixedPromotionQuoteVerifyProfileTest extends TestCase {
	private PromotionVerifyProfileFixture $f;
	protected function setUp(): void { $this->f = new PromotionVerifyProfileFixture(); }
	protected function tearDown(): void { if ( $this->f->owner->in_transaction() ) { $this->f->owner->rollback(); } }

	public function test_unbound_descriptor_and_history_profile_never_require_current_saved_or_funding_ports(): void {
		$p = new FixedPromotionQuoteVerifyProfile(); $descriptor = new QuoteOperationProfile( 'delivery_quote.verify_binding' );
		self::assertFalse( $p->authorize( $this->f->command->identity ) ); self::assertFalse( $p->rejects_are_terminal() ); self::assertSame( 1, $p->version() );
		foreach ( [ 'result_schema', 'publication_schema', 'actor_schema', 'target_schema' ] as $method ) { self::assertEquals( $descriptor->$method(), $p->$method() ); }
		self::assertSame( $descriptor->reason_codes(), $p->reason_codes() ); self::assertSame( $descriptor->changed_fields(), $p->changed_fields() );
		$control = $this->createMock( EmergencyControlStore::class ); $control->expects( $this->never() )->method( 'assert_ready' ); $control->expects( $this->never() )->method( 'current' );
		$ready = $this->createMock( OperationReadiness::class ); $ready->expects( $this->never() )->method( 'assert_ready' );
		$history = new FixedPromotionQuoteVerifyProfile( $this->f->command, static fn(): bool => true, control: $control, current_readiness: $ready );
		self::assertSame( DeliveryQuoteSchema::tables( 'durable_' ), $history->transactional_tables( $this->f->owner ) ); self::assertSame( [], $this->f->trace()->events );
		$this->expectException( OperationStorageException::class ); $history->lock_target( $this->f->owner, $this->f->command->identity, $this->f->command );
	}
	public function test_bound_command_identity_cannot_replace_saved_digests_at_the_same_namespace(): void {
		$c = $this->f->command; self::assertSame( $c, $this->f->profile->validate_command( $c->identity, $c ) );
		$other = FixedPromotionQuoteVerifyCommand::create( $c->binding_command(), $c->prepared_binding(), Q::digest( 'different saved snapshot' ), $c->context_digest() );
		self::assertSame( $c->identity->namespace_digest(), $other->identity->namespace_digest() );
		$this->expectException( \InvalidArgumentException::class ); $this->f->profile->validate_command( $other->identity, $other );
	}
	public function test_nonlocking_original_history_precedes_current_funding_order_saved_and_target_locks(): void {
		$this->f->lock(); $events = $this->f->trace()->events;
		foreach ( [ 'history', 'control', 'publication', 'source', 'eligibility', 'account', 'claim', 'order', 'saved', 'quote', 'binding' ] as $event ) { self::assertContains( $event, $events ); }
		self::assertLessThan( array_search( 'control', $events, true ), max( array_keys( $events, 'history', true ) ) );
		foreach ( [ [ 'control', 'publication' ], [ 'publication', 'source' ], [ 'source', 'eligibility' ], [ 'eligibility', 'account' ], [ 'account', 'claim' ], [ 'claim', 'order' ], [ 'order', 'saved' ], [ 'saved', 'quote' ], [ 'quote', 'binding' ] ] as [ $a, $b ] ) { self::assertLessThan( array_search( $b, $events, true ), array_search( $a, $events, true ) ); }
		self::assertCount( 3, $this->f->owner->inner->history_sql ); foreach ( $this->f->owner->inner->history_sql as $sql ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
		self::assertSame( [ $this->f->owner ], $this->f->saved->owners ); self::assertSame( 0, $this->f->owner->cas_attempts );
	}
	public function test_verification_cas_only_records_saved_proof_with_final_sql_clock_and_ordinary_receipt(): void {
		$before = $this->f->protected_bytes(); $original = $this->f->command->prepared_binding()->row(); self::assertTrue( array_values( $this->f->base->base->budget->accounts )[0]->remaining()->zero() );
		$mutation = $this->f->mutate( $this->f->lock() ); $actual = $this->f->binding()->row();
		self::assertTrue( $mutation->changed ); self::assertSame( 'prepared', $actual['state'] ); self::assertSame( 2, $actual['revision'] ); self::assertNull( $actual['sealed_at'] );
		self::assertSame( Q::time()->plus_seconds( 4 )->sql(), $actual['verified_at'] ); self::assertSame( $original['created_at'], $actual['created_at'] ); self::assertNotSame( $this->f->command->binding()->row()['verified_at'], $actual['verified_at'] );
		foreach ( array_diff( QuoteBinding::FIELDS, [ 'revision', 'snapshot_digest', 'context_digest', 'verified_at' ] ) as $field ) { self::assertSame( $original[$field], $actual[$field], $field ); }
		self::assertSame( $this->f->command->snapshot_digest(), $actual['snapshot_digest'] ); self::assertSame( $this->f->command->context_digest(), $actual['context_digest'] );
		self::assertSame( $before, $this->f->protected_bytes() ); self::assertSame( 0, $this->f->base->base->budget->writes ); self::assertSame( 1, $this->f->owner->cas_attempts ); self::assertSame( 3, $this->f->owner->clocks ); self::assertSame( 2, $this->f->saved->calls ); self::assertSame( 2, $this->f->base->order->calls );
		self::assertSame( [ 'binding' ], $mutation->event->changed_fields ); self::assertSame( 'quote_binding_verified', $mutation->event->reason_code ); self::assertSame( 2, $mutation->completion->result['binding_revision'] );
		$old = new QuoteOperationProfile( 'delivery_quote.verify_binding' ); self::assertTrue( $old->validate_accepted_facts( $mutation->completion, $mutation->event ) );
		self::assertSame( $mutation->completion->to_json(), OperationCompletion::from_json( $mutation->completion->to_json(), $old )->to_json() ); self::assertSame( $mutation->event->to_json(), OperationMaterialEvent::from_json( $mutation->event->to_json(), $old )->to_json() );
		self::assertSame( 3, $this->f->base->base->db->count( 'operation_records' ) ); self::assertSame( 3, $this->f->base->base->db->count( 'operation_changes' ) );
	}
	public function test_distinct_accounts_are_sorted_before_all_claims_and_saved_proof(): void {
		$this->f->owner->rollback(); $this->f = new PromotionVerifyProfileFixture( true ); $this->f->lock();
		$budget = $this->f->base->base->budget; $accounts = array_keys( $budget->accounts ); sort( $accounts, SORT_STRING ); $claims = array_keys( $budget->claims ); sort( $claims, SORT_STRING );
		self::assertCount( 2, $accounts ); self::assertSame( $accounts, $budget->account_reads ); self::assertSame( $claims, $budget->claim_reads );
		$events = $this->f->trace()->events; self::assertLessThan( min( array_keys( $events, 'claim', true ) ), max( array_keys( $events, 'account', true ) ) ); self::assertLessThan( array_search( 'saved', $events, true ), max( array_keys( $events, 'claim', true ) ) );
	}
	public static function invalid_saved(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'snapshot', 'context', 'order', 'mapping', 'native money', 'unavailable' ] ); }
	#[DataProvider( 'invalid_saved' )]
	public function test_exact_saved_membership_snapshot_context_and_money_are_required_before_target_locks( string $kind ): void {
		if ( 'unavailable' === $kind ) { $this->f->saved->allow = false; }
		else { $field = [ 'snapshot' => 'snapshot_digest', 'context' => 'context_digest', 'order' => 'order_id', 'mapping' => 'mapping_json', 'native money' => 'native_money_digest' ][$kind]; $this->f->saved->packet[$field] = 'order_id' === $field ? 502 : Q::digest( 'changed saved evidence' ); }
		$before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'Saved evidence must be exact.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertNotContains( 'quote', $this->f->trace()->events ); self::assertSame( 1, $this->f->binding()->revision() ); self::assertSame( $before, $this->f->protected_bytes() ); }
	}
	public static function invalid_holds(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'missing', 'released', 'committed', 'shorter', 'extended' ] ); }
	#[DataProvider( 'invalid_holds' )]
	public function test_saved_verification_requires_every_original_exact_held_claim( string $kind ): void {
		$budget = $this->f->base->base->budget; $key = array_key_first( $budget->claims ); $claim = $budget->claims[$key];
		if ( 'missing' === $kind ) { unset( $budget->claims[$key] ); }
		elseif ( 'released' === $kind ) { $budget->claims[$key] = $claim->release( Q::time()->plus_seconds( 4 ) ); }
		elseif ( 'committed' === $kind ) { $budget->claims[$key] = $claim->commit( Q::time()->plus_seconds( 4 ) ); }
		else { $budget->claims[$key] = FixedPromotionBudgetReservation::hold( $claim->request(), Q::time(), Q::time()->plus_seconds( 'shorter' === $kind ? 119 : 121 ) ); }
		$before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'Every original held claim remains mandatory.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->f->saved->calls ); self::assertSame( 0, $this->f->base->order->calls ); self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( $before, $this->f->protected_bytes() ); }
	}
	public static function broken_history(): array { return [ [ 'issue' ], [ 'accept' ], [ 'bind' ], [ 'bind chronology' ], [ 'bind missing' ] ]; }
	#[DataProvider( 'broken_history' )]
	public function test_corrupt_original_history_is_refused_before_current_authority_or_saved_proof( string $kind ): void {
		$db = $this->f->base->base->db->pdo;
		if ( 'bind chronology' === $kind ) { $st = $db->prepare( 'UPDATE durable_delivery_engine_operation_changes SET created_at=? WHERE operation_id=3' ); $st->execute( [ Q::time()->plus_seconds( 2 )->sql() ] ); }
		elseif ( 'bind missing' === $kind ) { $db->exec( 'DELETE FROM durable_delivery_engine_operation_records WHERE id=3' ); }
		else { $id = [ 'issue' => 1, 'accept' => 2, 'bind' => 3 ][$kind]; $st = $db->prepare( 'UPDATE durable_delivery_engine_operation_records SET intent_hash=? WHERE id=?' ); $st->execute( [ Q::digest( 'foreign original history' ), $id ] ); }
		$before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'Original history must authenticate before current ports.' ); } catch ( OperationStorageException ) { self::assertNotContains( 'control', $this->f->trace()->events ); self::assertSame( 0, $this->f->saved->calls ); self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( $before, $this->f->protected_bytes() ); }
	}
	public function test_changed_actual_prepared_membership_refuses_without_rewriting_the_saved_order(): void {
		$db = $this->f->base->base->db->pdo; $db->exec( 'UPDATE durable_delivery_engine_delivery_quote_bindings SET order_id=999' ); $before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'Captured physical membership cannot be replaced.' ); } catch ( OperationRefusal $e ) { self::assertSame( 'stale_revision', $e->error_code ); self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( $before, $this->f->protected_bytes() ); self::assertSame( 999, $this->f->binding()->row()['order_id'] ); }
	}
	public function test_already_verified_binding_refuses_the_mutation_path_without_reverification(): void {
		$this->f->mutate( $this->f->lock() ); $this->f->reset_profile(); $before = $this->f->binding()->row(); $this->f->owner->cas_attempts = 0;
		try { $this->f->lock(); self::fail( 'A prepared2 target cannot be verified a second time.' ); } catch ( OperationRefusal $e ) { self::assertSame( 'stale_revision', $e->error_code ); self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( $before, $this->f->binding()->row() ); }
	}
	public static function late_fences(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'hold expiry', 'header expiry', 'backward clock', 'before prepared creation', 'initial policy expiry', 'late policy expiry', 'authorization', 'owner', 'saved changed', 'saved wait', 'order changed', 'order capture replaced' ] ); }
	#[DataProvider( 'late_fences' )]
	public function test_late_source_order_saved_and_authorization_proofs_cannot_extend_any_fence( string $kind ): void {
		if ( 'initial policy expiry' === $kind ) { $this->f->base->base->eligibility->seconds = 1; }
		$target = $this->f->lock();
		if ( 'hold expiry' === $kind ) { $this->f->owner->final_time = $this->f->command->hold_until(); }
		elseif ( 'header expiry' === $kind ) { $this->f->owner->final_time = $this->f->command->header()->expires_at(); }
		elseif ( 'backward clock' === $kind ) { $this->f->owner->final_time = Q::time()->plus_seconds( 3 ); }
		elseif ( 'before prepared creation' === $kind ) { $this->f->owner->before_time = Q::time()->plus_seconds( 2 ); }
		elseif ( 'initial policy expiry' === $kind ) { $this->f->base->base->eligibility->seconds = 300; $this->f->owner->final_time = Q::time()->plus_seconds( 5 ); }
		elseif ( 'late policy expiry' === $kind ) { $this->f->base->base->eligibility->seconds = 1; $this->f->owner->final_time = Q::time()->plus_seconds( 5 ); }
		elseif ( 'authorization' === $kind ) { $this->f->saved->on_proof = function(): void { $this->f->authorized = false; }; }
		elseif ( 'owner' === $kind ) { $this->f->saved->on_proof = function(): void { $this->f->owner->rebound = true; }; }
		elseif ( 'saved wait' === $kind ) { $this->f->saved->on_proof = function(): void { $this->f->owner->final_time = $this->f->command->hold_until(); }; }
		elseif ( 'saved changed' === $kind ) { $this->f->saved->allow = false; }
		elseif ( 'order changed' === $kind ) { $this->f->base->order->allow = false; }
		else { $this->f->saved->on_proof = function(): void { $this->f->base->order->digest = Q::digest( 'capture replaced after saved proof' ); }; }
		$before = $this->f->protected_bytes();
		try { $this->f->mutate( $target ); self::fail( 'Late proof cannot authorize saved verification.' ); } catch ( OperationRefusal|OperationStorageException ) { self::assertSame( 0, $this->f->owner->cas_attempts ); self::assertSame( 1, $this->f->binding()->revision() ); self::assertSame( $before, $this->f->protected_bytes() ); }
	}
	public static function cas_faults(): array { return [ [ 'rejected' ], [ 'owner loss' ], [ 'lost acknowledgment' ] ]; }
	#[DataProvider( 'cas_faults' )]
	public function test_every_failed_cas_attempt_requires_caller_rollback_of_the_entire_effect( string $kind ): void {
		$target = $this->f->lock(); $before = $this->f->protected_bytes(); $prepared = $this->f->binding()->row(); $this->f->owner->reject_cas = 'rejected' === $kind; $this->f->owner->rebind_after_cas = 'owner loss' === $kind; $this->f->owner->throw_after_cas = 'lost acknowledgment' === $kind;
		try { $this->f->mutate( $target ); self::fail( 'A failed replacement acknowledgement cannot escape.' ); } catch ( OperationStorageException ) { self::assertSame( 1, $this->f->owner->cas_attempts ); self::assertTrue( $this->f->owner->in_transaction() ); }
		self::assertSame( 'rejected' === $kind ? 1 : 2, $this->f->binding()->revision() ); self::assertTrue( $this->f->owner->rollback() ); self::assertSame( $prepared, $this->f->binding()->row() ); self::assertSame( $before, $this->f->protected_bytes() );
	}
	public function test_historical_publication_uses_only_original_verified_receipt_facts_and_exact_saved_digests(): void {
		$mutation = $this->f->mutate( $this->f->lock() ); $probe = new PromotionAcceptPublicationProbe(); $p = new FixedPromotionQuoteVerifyProfile( $this->f->command, static fn(): bool => true, publication: $probe );
		self::assertTrue( $p->publish( $this->f->command->identity, $mutation->completion ) ); self::assertCount( 1, $probe->ids );
		$r = $mutation->completion->result; $r['snapshot_digest'] = Q::digest( 'foreign saved snapshot' ); $wrong = OperationCompletion::accepted( $p, $r, $mutation->completion->publication ); self::assertFalse( $p->publish( $this->f->command->identity, $wrong ) ); self::assertCount( 1, $probe->ids );
	}
}

/** New fixture reuses closed E13 setup and commits its ordinary bind receipt before E14. */
final class PromotionVerifyProfileFixture {
	public PromotionBindProfileFixture $base;
	public PromotionVerifyMutationOwner $owner;
	public PromotionVerifySavedPort $saved;
	public FixedPromotionQuoteVerifyCommand $command;
	public FixedPromotionQuoteVerifyInputs $inputs;
	public FixedPromotionQuoteVerifyProfile $profile;
	public bool $authorized = true;
	public function __construct( bool $separate_accounts = false ) {
		$this->base = new PromotionBindProfileFixture( $separate_accounts ); $mutation = $this->base->mutate( $this->base->lock() ); $c = $this->base->command; $at = Q::time()->plus_seconds( 3 );
		$this->base->base->insert( 'operation_records', [ 'id' => 3, 'site_id' => 1, 'namespace_hash' => $c->identity->namespace_digest(), 'intent_hash' => $c->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.bind', 'operation_version' => 1, 'target_hash' => WpdbOperationRecordRepository::target_hash( $c->identity ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $mutation->completion->to_json(), 'audit_id' => 3, 'row_version' => 3, 'created_at' => $at->sql(), 'updated_at' => $at->sql(), 'completed_at' => $at->sql() ] );
		$this->base->base->insert( 'operation_changes', [ 'id' => 3, 'site_id' => 1, 'operation_id' => 3, 'event_format' => 1, 'event_json' => $mutation->event->to_json(), 'created_at' => $at->sql() ] );
		$prepared = $this->base->binding(); $this->base->base->db->pdo->commit(); $this->base->owner->retire();
		$this->command = FixedPromotionQuoteVerifyCommand::create( $c, $prepared, Q::digest( 'E14 exact saved snapshot' ), Q::digest( 'E14 exact saved context' ) );
		$trace = $this->trace(); $this->owner = new PromotionVerifyMutationOwner( new PromotionBindMutationOwner( new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->base->base->db->open() ), $trace ), $trace ) ); $this->owner->begin();
		$this->saved = new PromotionVerifySavedPort( $this->command->binding(), $trace ); $this->inputs = FixedPromotionQuoteVerifyInputs::from_sources( $this->base->inputs, $this->saved );
		$this->base->order->calls = 0; $this->base->order->owners = []; $this->base->base->budget->account_reads = []; $this->base->base->budget->claim_reads = [];
		$this->reset_profile();
	}
	public function reset_profile(): void { $this->profile = new FixedPromotionQuoteVerifyProfile( $this->command, fn(): bool => $this->authorized, $this->inputs, $this->base->base->budget, $this->base->base->eligibility, new PromotionMutationControl( $this->trace() ), current_readiness: new PromotionMutationReadiness( $this->trace() ) ); $this->profile->transactional_tables( $this->owner ); $this->trace()->events = []; }
	public function trace(): PromotionMutationTrace { return $this->base->base->trace; }
	public function lock() { return $this->profile->lock_target( $this->owner, $this->command->identity, $this->command ); }
	public function mutate( $target ) { return $this->profile->mutate( $this->owner, $this->command->identity, $this->command, $target, RequestContext::create() ); }
	public function binding(): QuoteBinding { $r = $this->base->base->db->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetch( \PDO::FETCH_ASSOC ); return QuoteBinding::from_row( $r, $this->base->base->quote() ); }
	public function protected_bytes(): array { return [ $this->base->protected_bytes(), $this->base->order->saved, $this->saved->packet ]; }
}

/** Explicit synthetic saved-order proof. No native WooCommerce acceptance claim. */
final class PromotionVerifySavedPort implements QuotePlacementSavedEvidenceGuard {
	public array $packet; public array $owners = []; public int $calls = 0; public bool $allow = true; public ?\Closure $on_proof = null;
	public function __construct( QuoteBinding $proposed, private PromotionMutationTrace $trace ) { $this->packet = $proposed->row(); }
	public function tables( OperationSession $s ): array { return [ $s->table_prefix() . 'synthetic_saved_orders' ]; }
	public function verify( OperationSession $s, QuoteBinding $binding ): bool { ++$this->calls; $this->trace->events[] = 'saved'; $this->owners[] = $s; if ( null !== $this->on_proof ) { ( $this->on_proof )( $s, $this->calls ); } return $this->allow && $s->in_transaction() && ! $s->is_retired() && $this->packet === $binding->row(); }
}

final class PromotionVerifyMutationOwner implements OperationSession {
	public int $clocks = 0; public ?QuoteTime $before_time = null; public ?QuoteTime $final_time = null; public bool $rebound = false; public int $cas_attempts = 0; public bool $reject_cas = false; public bool $rebind_after_cas = false; public bool $throw_after_cas = false;
	public function __construct( public readonly PromotionBindMutationOwner $inner ) {}
	public function site_id(): int { return $this->inner->site_id(); } public function table_prefix(): string { return $this->inner->table_prefix(); } public function charset_collate(): string { return $this->rebound ? 'utf8mb4_bin' : $this->inner->charset_collate(); }
	public function begin(): bool { return $this->inner->begin(); } public function commit(): OperationCommitResult { throw new \LogicException( 'Profile cannot commit.' ); } public function rollback(): bool { return $this->inner->rollback(); } public function retire(): bool { return $this->inner->retire(); } public function is_retired(): bool { return $this->inner->is_retired(); } public function in_transaction(): bool { return $this->inner->in_transaction(); } public function validate_tables( array $tables ): bool { return $this->inner->validate_tables( $tables ); }
	public function query( string $sql ): int|false { if ( str_starts_with( $sql, 'UPDATE `durable_delivery_engine_delivery_quote_bindings`' ) ) { ++$this->cas_attempts; if ( $this->reject_cas ) { return false; } $r = $this->inner->query( $sql ); if ( $this->rebind_after_cas ) { $this->rebound = true; } if ( $this->throw_after_cas ) { throw new \RuntimeException( 'Synthetic CAS acknowledgement lost.' ); } return $r; } return $this->inner->query( $sql ); }
	public function get_row( string $sql ): array|null|false { if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { ++$this->clocks; return [ 'utc' => ( 3 === $this->clocks ? $this->final_time : ( 2 === $this->clocks ? $this->before_time : null ) )?->sql() ?? Q::time()->plus_seconds( 4 )->sql() ]; } return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false { return $this->inner->get_results( $sql ); } public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); }
}
