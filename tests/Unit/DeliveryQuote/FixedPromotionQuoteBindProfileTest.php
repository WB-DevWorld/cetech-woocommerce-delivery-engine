<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteBindCommand, FixedPromotionQuoteBindInputs, FixedPromotionQuoteBindProfile, FixedPromotionQuoteOrderEvidenceGuard, QuoteOperationProfile};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetReservation;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteJson, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationMaterialEvent, OperationRefusal, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\PromotionQuoteIssueMixedSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuotePlacementReaderTest.php';

/** Actual SQLite quote/receipt codecs; source, native candidate-order, policy and funding ports are synthetic. */
final class FixedPromotionQuoteBindProfileTest extends TestCase {

	private PromotionBindProfileFixture $f;
	protected function setUp(): void { $this->f = new PromotionBindProfileFixture(); }
	protected function tearDown(): void { if ( $this->f->owner->in_transaction() ) { $this->f->owner->rollback(); } }

	public function test_unbound_and_history_only_profiles_preserve_descriptor_without_current_ports(): void {
		$p = new FixedPromotionQuoteBindProfile(); $old = new QuoteOperationProfile( 'delivery_quote.bind' );
		self::assertFalse( $p->authorize( $this->f->command->identity ) ); self::assertFalse( $p->rejects_are_terminal() ); self::assertSame( 1, $p->version() );
		foreach ( [ 'result_schema', 'publication_schema', 'actor_schema', 'target_schema' ] as $method ) { self::assertEquals( $old->$method(), $p->$method() ); }
		self::assertSame( $old->reason_codes(), $p->reason_codes() ); self::assertSame( $old->changed_fields(), $p->changed_fields() );
		$control = $this->createMock( EmergencyControlStore::class ); $control->expects( $this->never() )->method( 'assert_ready' ); $control->expects( $this->never() )->method( 'current' );
		$ready = $this->createMock( OperationReadiness::class ); $ready->expects( $this->never() )->method( 'assert_ready' );
		$history = new FixedPromotionQuoteBindProfile( $this->f->command, static fn(): bool => true, control: $control, current_readiness: $ready );
		self::assertSame( DeliveryQuoteSchema::tables( 'durable_' ), $history->transactional_tables( $this->f->owner ) ); self::assertSame( [], $this->f->base->trace->events );
		$this->expectException( OperationStorageException::class ); $history->lock_target( $this->f->owner, $this->f->command->identity, $this->f->command );
	}
	public function test_command_validation_binds_order_identity_even_with_the_same_operation_namespace(): void {
		$c = $this->f->command; self::assertSame( $c, $this->f->profile->validate_command( $c->identity, $c ) );
		$other = FixedPromotionQuoteBindCommand::create( $c->acceptance(), $c->quote_record(), 502, QuoteId::from_string( $c->binding()->row()['placement_uuid'] ), $c->binding()->mapping(), $c->order_evidence_digest() );
		self::assertSame( $c->identity->namespace_digest(), $other->identity->namespace_digest() );
		$this->expectException( \InvalidArgumentException::class ); $this->f->profile->validate_command( $other->identity, $other );
	}
	public function test_nonlocking_history_precedes_control_source_funding_native_order_and_targets(): void {
		$this->f->lock(); $events = $this->f->base->trace->events;
		foreach ( [ 'history', 'control', 'publication', 'source', 'eligibility', 'account', 'claim', 'order', 'quote', 'binding' ] as $event ) { self::assertContains( $event, $events ); }
		self::assertLessThan( array_search( 'control', $events, true ), max( array_keys( $events, 'history', true ) ) );
		foreach ( [ [ 'control', 'publication' ], [ 'publication', 'source' ], [ 'source', 'eligibility' ], [ 'eligibility', 'account' ], [ 'account', 'claim' ], [ 'claim', 'order' ], [ 'order', 'quote' ], [ 'quote', 'binding' ] ] as [ $a, $b ] ) { self::assertLessThan( array_search( $b, $events, true ), array_search( $a, $events, true ) ); }
		foreach ( $this->f->owner->history_sql as $sql ) { self::assertStringNotContainsString( 'FOR UPDATE', $sql ); }
		self::assertSame( [ $this->f->owner ], $this->f->order->owners ); self::assertSame( 0, $this->f->owner->insert_attempts );
	}
	public function test_binding_stages_only_prepared_one_and_retains_the_fully_held_pool_quote_and_admissions(): void {
		$before = $this->f->protected_bytes(); self::assertTrue( array_values( $this->f->base->budget->accounts )[0]->remaining()->zero() );
		$mutation = $this->f->mutate( $this->f->lock() ); $row = $this->f->binding()->row();
		self::assertTrue( $mutation->changed ); self::assertSame( 'prepared', $row['state'] ); self::assertSame( 1, $row['revision'] ); self::assertSame( Q::time()->plus_seconds( 3 )->sql(), $row['created_at'] );
		foreach ( [ 'verified_at', 'sealed_at', 'snapshot_digest', 'context_digest' ] as $field ) { self::assertNull( $row[$field] ); }
		self::assertSame( $this->f->command->binding()->mapping(), $this->f->binding()->mapping() ); self::assertSame( $before, $this->f->protected_bytes() );
		self::assertSame( 0, $this->f->base->budget->writes ); self::assertSame( 1, $this->f->owner->insert_attempts ); self::assertSame( 3, $this->f->owner->clocks ); self::assertSame( 2, $this->f->order->calls );
		self::assertSame( [ 'binding' ], $mutation->event->changed_fields ); self::assertSame( 1, $mutation->completion->result['binding_revision'] );
		$old = new QuoteOperationProfile( 'delivery_quote.bind' ); self::assertTrue( $old->validate_accepted_facts( $mutation->completion, $mutation->event ) );
		self::assertSame( $mutation->completion->to_json(), OperationCompletion::from_json( $mutation->completion->to_json(), $old )->to_json() ); self::assertSame( $mutation->event->to_json(), OperationMaterialEvent::from_json( $mutation->event->to_json(), $old )->to_json() );
		self::assertSame( 2, $this->f->base->db->count( 'operation_records' ) ); self::assertSame( 2, $this->f->base->db->count( 'operation_changes' ) );
	}
	public function test_all_distinct_accounts_are_sorted_before_all_claims_and_native_order_locks(): void {
		$this->f->owner->rollback(); $this->f = new PromotionBindProfileFixture( true ); $this->f->lock();
		$accounts = array_keys( $this->f->base->budget->accounts ); sort( $accounts, SORT_STRING ); $claims = array_keys( $this->f->base->budget->claims ); sort( $claims, SORT_STRING );
		self::assertCount( 2, $accounts ); self::assertSame( $accounts, $this->f->base->budget->account_reads ); self::assertSame( $claims, $this->f->base->budget->claim_reads );
		$events = $this->f->base->trace->events; self::assertLessThan( min( array_keys( $events, 'claim', true ) ), max( array_keys( $events, 'account', true ) ) ); self::assertLessThan( array_search( 'order', $events, true ), max( array_keys( $events, 'claim', true ) ) );
	}
	public static function invalid_candidate(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'order', 'site', 'owner', 'placement', 'product', 'variation', 'quantity', 'item', 'native money', 'unavailable' ] ); }
	#[DataProvider( 'invalid_candidate' )]
	public function test_synthetic_candidate_order_mismatches_refuse_before_quote_or_binding_target( string $kind ): void {
		if ( 'order' === $kind ) { ++$this->f->order->saved['order_id']; }
		elseif ( 'site' === $kind ) { $this->f->order->saved['site_id'] = 2; }
		elseif ( 'owner' === $kind ) { $this->f->order->saved['owner_digest'] = Q::digest( 'foreign customer' ); }
		elseif ( 'placement' === $kind ) { $this->f->order->saved['placement_uuid'] = QuoteId::generate()->value(); }
		elseif ( 'native money' === $kind ) { $this->f->order->saved['native_money_digest'] = Q::digest( 'different native totals' ); }
		elseif ( 'unavailable' === $kind ) { $this->f->order->allow = false; }
		else { $field = [ 'product' => 'product_id', 'variation' => 'variation_id', 'quantity' => 'quantity', 'item' => 'item_id' ][$kind]; $this->f->order->saved['mapping']['groups'][0]['lines'][0][$field] = 'quantity' === $field ? '9' : 999; }
		$before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'Candidate-order evidence must match exactly.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->f->owner->insert_attempts ); self::assertSame( $before, $this->f->protected_bytes() ); self::assertNotContains( 'quote', $this->f->base->trace->events ); }
	}
	public static function invalid_holds(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'missing', 'released', 'committed', 'shorter', 'extended' ] ); }
	#[DataProvider( 'invalid_holds' )]
	public function test_every_original_claim_must_still_be_held_with_its_exact_original_deadline( string $kind ): void {
		$key = array_key_first( $this->f->base->budget->claims ); $claim = $this->f->base->budget->claims[$key];
		if ( 'missing' === $kind ) { unset( $this->f->base->budget->claims[$key] ); }
		elseif ( 'released' === $kind ) { $this->f->base->budget->claims[$key] = $claim->release( Q::time()->plus_seconds( 3 ) ); }
		elseif ( 'committed' === $kind ) { $this->f->base->budget->claims[$key] = $claim->commit( Q::time()->plus_seconds( 3 ) ); }
		else { $this->f->base->budget->claims[$key] = FixedPromotionBudgetReservation::hold( $claim->request(), Q::time(), Q::time()->plus_seconds( 'shorter' === $kind ? 119 : 121 ) ); }
		$before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'Original holds are mandatory.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->f->order->calls ); self::assertSame( $before, $this->f->protected_bytes() ); self::assertSame( 0, $this->f->owner->insert_attempts ); }
	}
	public static function digest_drift(): array { return [ [ 'entry' ], [ 'first proof' ], [ 'late proof' ] ]; }
	#[DataProvider( 'digest_drift' )]
	public function test_order_evidence_identity_cannot_be_replaced_at_entry_or_after_a_proof( string $phase ): void {
		if ( 'entry' === $phase ) { $this->f->order->digest = Q::digest( 'another native capture' ); }
		else { $this->f->order->on_proof = function( PromotionBindMutationOwner $s, int $call ) use ( $phase ): void { if ( ( 'first proof' === $phase ? 1 : 2 ) === $call ) { $this->f->order->digest = Q::digest( 'replaced native capture' ); } }; }
		try { $this->f->mutate( $this->f->lock() ); self::fail( 'The bound capture identity must remain exact.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->f->owner->insert_attempts ); self::assertSame( 0, $this->f->base->db->count( 'delivery_quote_bindings' ) ); }
	}
	public function test_corrupt_original_receipt_refuses_before_any_current_authority_read(): void {
		$this->f->base->db->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET intent_hash='" . Q::digest( 'foreign acceptance intent' ) . "' WHERE id=2" );
		try { $this->f->lock(); self::fail( 'Immutable acceptance must authenticate.' ); } catch ( OperationStorageException ) { self::assertNotContains( 'control', $this->f->base->trace->events ); self::assertSame( 0, $this->f->order->calls ); self::assertSame( 0, $this->f->owner->insert_attempts ); }
	}
	public function test_original_event_sql_timestamp_before_its_effect_refuses_before_current_ports_or_insertion(): void {
		$statement = $this->f->base->db->pdo->prepare( 'UPDATE durable_delivery_engine_operation_changes SET created_at=? WHERE operation_id=2' ); $statement->execute( [ Q::time()->plus_seconds( 1 )->sql() ] );
		$before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'An original event cannot persist before its own effect.' ); } catch ( OperationStorageException ) {
			self::assertNotContains( 'control', $this->f->base->trace->events ); self::assertSame( 0, $this->f->order->calls ); self::assertSame( 0, $this->f->owner->insert_attempts ); self::assertSame( $before, $this->f->protected_bytes() ); self::assertSame( 0, $this->f->base->db->count( 'delivery_quote_bindings' ) );
		}
	}
	public function test_changed_original_source_refuses_before_funding_or_native_order(): void {
		$this->f->owner->inner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01';
		try { $this->f->lock(); self::fail( 'Original source changed.' ); } catch ( OperationRefusal ) { self::assertNotContains( 'account', $this->f->base->trace->events ); self::assertSame( 0, $this->f->order->calls ); }
	}
	public function test_existing_prepared_binding_refuses_without_reassignment_or_funding_change(): void {
		( new DeliveryQuoteRepository( $this->f->owner ) )->insert_binding( $this->f->command->binding() ); $this->f->owner->insert_attempts = 0; $before = $this->f->protected_bytes();
		try { $this->f->lock(); self::fail( 'One quote cannot be reassigned.' ); } catch ( OperationRefusal $e ) { self::assertSame( 'stale_revision', $e->error_code ); self::assertSame( 0, $this->f->owner->insert_attempts ); self::assertSame( 1, $this->f->base->db->count( 'delivery_quote_bindings' ) ); self::assertSame( $before, $this->f->protected_bytes() ); }
	}
	public static function late_fences(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'hold expiry', 'header expiry', 'backward clock', 'initial policy expiry', 'final policy expiry', 'authorization', 'owner', 'order changed', 'order proof waits' ] ); }
	#[DataProvider( 'late_fences' )]
	public function test_late_order_and_current_proofs_cannot_extend_any_fence_or_authorize_a_write( string $kind ): void {
		if ( 'initial policy expiry' === $kind ) { $this->f->base->eligibility->seconds = 1; }
		$target = $this->f->lock();
		if ( 'hold expiry' === $kind ) { $this->f->owner->final_time = $this->f->command->hold_until(); }
		elseif ( 'header expiry' === $kind ) { $this->f->owner->final_time = $this->f->command->header()->expires_at(); }
		elseif ( 'backward clock' === $kind ) { $this->f->owner->final_time = Q::time()->plus_seconds( 2 ); }
		elseif ( 'initial policy expiry' === $kind ) { $this->f->base->eligibility->seconds = 300; $this->f->owner->final_time = Q::time()->plus_seconds( 4 ); }
		elseif ( 'final policy expiry' === $kind ) { $this->f->base->eligibility->seconds = 1; $this->f->owner->final_time = Q::time()->plus_seconds( 4 ); }
		elseif ( 'authorization' === $kind ) { $this->f->order->on_proof = function(): void { $this->f->authorized = false; }; }
		elseif ( 'owner' === $kind ) { $this->f->order->on_proof = function(): void { $this->f->owner->rebound = true; }; }
		elseif ( 'order proof waits' === $kind ) { $this->f->order->on_proof = function(): void { $this->f->owner->final_time = $this->f->command->hold_until(); }; }
		else { $this->f->order->allow = false; }
		$before = $this->f->protected_bytes();
		try { $this->f->mutate( $target ); self::fail( 'Late proof cannot authorize the binding.' ); } catch ( OperationRefusal|OperationStorageException ) { self::assertSame( $before, $this->f->protected_bytes() ); self::assertSame( 0, $this->f->owner->insert_attempts ); self::assertSame( 0, $this->f->base->db->count( 'delivery_quote_bindings' ) ); }
	}
	public static function insert_faults(): array { return [ [ 'rejected' ], [ 'owner loss' ], [ 'lost insertion acknowledgment' ] ]; }
	#[DataProvider( 'insert_faults' )]
	public function test_any_insert_attempt_failure_requires_caller_rollback_without_changing_protected_rows( string $kind ): void {
		$target = $this->f->lock(); $before = $this->f->protected_bytes(); $this->f->owner->reject_insert = 'rejected' === $kind; $this->f->owner->rebind_after_insert = 'owner loss' === $kind; $this->f->owner->throw_after_insert = 'lost insertion acknowledgment' === $kind;
		try { $this->f->mutate( $target ); self::fail( 'A staged insertion cannot escape failed.' ); } catch ( OperationStorageException ) { self::assertSame( 1, $this->f->owner->insert_attempts ); self::assertTrue( $this->f->owner->in_transaction() ); }
		self::assertSame( 'rejected' === $kind ? 0 : 1, $this->f->base->db->count( 'delivery_quote_bindings' ) ); self::assertTrue( $this->f->owner->rollback() ); self::assertSame( 0, $this->f->base->db->count( 'delivery_quote_bindings' ) ); self::assertSame( $before, $this->f->protected_bytes() );
	}
	public function test_publication_replays_recorded_binding_without_current_ports_and_refuses_other_order_facts(): void {
		$mutation = $this->f->mutate( $this->f->lock() ); $probe = new PromotionAcceptPublicationProbe(); $p = new FixedPromotionQuoteBindProfile( $this->f->command, static fn(): bool => true, publication: $probe );
		self::assertTrue( $p->publish( $this->f->command->identity, $mutation->completion ) ); self::assertCount( 1, $probe->ids );
		$r = $mutation->completion->result; ++$r['order_id']; $wrong = OperationCompletion::accepted( $p, $r, $mutation->completion->publication ); self::assertFalse( $p->publish( $this->f->command->identity, $wrong ) ); self::assertCount( 1, $probe->ids );
	}
}

/** New shared fixture; inherited source/history fixtures remain untouched. */
final class PromotionBindProfileFixture {
	public PromotionPlacementReaderFixture $base;
	public PromotionBindMutationOwner $owner;
	public PromotionBindOrderPort $order;
	public FixedPromotionQuoteBindCommand $command;
	public FixedPromotionQuoteBindInputs $inputs;
	public FixedPromotionQuoteBindProfile $profile;
	public bool $authorized = true;
	public function __construct( bool $separate_accounts = false ) {
		$this->base = new PromotionPlacementReaderFixture( $separate_accounts ); $trace = $this->base->trace;
		$this->owner = new PromotionBindMutationOwner( new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->base->db->open() ), $trace ), $trace ); $this->owner->begin();
		$mapping = []; $items = 100;
		$context = $this->base->command->context()->private_facts(); $lines = []; foreach ( $context['lines'] as $line ) { $lines[$line['line_key']] = $line; }
		foreach ( $context['groups'] as $group ) { $members = []; foreach ( $group['line_keys'] as $key ) { $line = $lines[$key]; $members[] = [ 'line_key' => $key, 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'], 'parent_id' => $line['parent_id'], 'quantity' => $line['quantity'], 'item_id' => ++$items ]; } $mapping[] = [ 'component_key' => $group['component_key'], 'lines' => $members ]; }
		$digest = Q::digest( 'E13 synthetic captured candidate-order identity' ); $this->command = FixedPromotionQuoteBindCommand::create( $this->base->command, $this->base->quote(), 501, QuoteId::generate(), [ 'format_version' => 1, 'groups' => $mapping ], $digest );
		$this->order = new PromotionBindOrderPort( $this->command, $trace ); $this->inputs = FixedPromotionQuoteBindInputs::from_sources( $this->base->inputs, $this->order );
		$this->profile = new FixedPromotionQuoteBindProfile( $this->command, fn(): bool => $this->authorized, $this->inputs, $this->base->budget, $this->base->eligibility, new PromotionMutationControl( $trace ), current_readiness: new PromotionMutationReadiness( $trace ) );
		$this->profile->transactional_tables( $this->owner ); $trace->events = [];
	}
	public function lock() { return $this->profile->lock_target( $this->owner, $this->command->identity, $this->command ); }
	public function mutate( $target ) { return $this->profile->mutate( $this->owner, $this->command->identity, $this->command, $target, RequestContext::create() ); }
	public function binding(): ?QuoteBinding { $row = $this->base->db->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetch( \PDO::FETCH_ASSOC ); return false === $row ? null : QuoteBinding::from_row( $row, $this->base->quote() ); }
	public function protected_bytes(): array { $data = $this->base->bytes(); $data[] = $this->base->db->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_budget_windows ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); return $data; }
}

/** Explicit synthetic order adapter; no native Woo ownership claim. */
final class PromotionBindOrderPort implements FixedPromotionQuoteOrderEvidenceGuard {
	public string $digest;
	public array $saved;
	public array $owners = [];
	public int $calls = 0;
	public bool $allow = true;
	public ?\Closure $on_proof = null;
	public function __construct( private FixedPromotionQuoteBindCommand $command, private PromotionMutationTrace $trace ) {
		$b = $command->binding()->row(); $this->digest = $command->order_evidence_digest(); $this->saved = [ 'site_id' => $command->owner()->site_id(), 'owner_digest' => $command->owner()->digest(), 'order_id' => $b['order_id'], 'placement_uuid' => $b['placement_uuid'], 'mapping' => $command->binding()->mapping(), 'native_money_digest' => $b['native_money_digest'] ];
	}
	public function evidence_digest(): string { return $this->digest; }
	public function tables( OperationSession $s ): array { return [ $s->table_prefix() . 'synthetic_candidate_orders' ]; }
	public function verify( OperationSession $s, QuoteStoredRow $accepted, QuoteBinding $candidate ): bool {
		++$this->calls; $this->trace->events[] = 'order'; $this->owners[] = $s; if ( null !== $this->on_proof ) { ( $this->on_proof )( $s, $this->calls ); }
		$b = $candidate->row(); $expected = [ 'site_id' => $s->site_id(), 'owner_digest' => $accepted->header()->owner()->digest(), 'order_id' => $b['order_id'], 'placement_uuid' => $b['placement_uuid'], 'mapping' => $candidate->mapping(), 'native_money_digest' => $accepted->context()->private_facts()['tax']['native_money_digest'] ];
		return $this->allow && $s->in_transaction() && ! $s->is_retired() && $this->saved === $expected;
	}
}

final class PromotionBindMutationOwner implements OperationSession {
	public int $clocks = 0; public ?QuoteTime $before_time = null; public ?QuoteTime $final_time = null; public bool $rebound = false; public int $insert_attempts = 0; public bool $reject_insert = false; public bool $rebind_after_insert = false; public bool $throw_after_insert = false; public array $history_sql = [];
	public function __construct( public readonly PromotionMutationOwner $inner, private PromotionMutationTrace $trace ) {}
	public function site_id(): int { return $this->inner->site_id(); } public function table_prefix(): string { return $this->inner->table_prefix(); } public function charset_collate(): string { return $this->rebound ? 'utf8mb4_bin' : $this->inner->charset_collate(); }
	public function begin(): bool { return $this->inner->begin(); } public function commit(): OperationCommitResult { throw new \LogicException( 'Profile cannot commit.' ); } public function rollback(): bool { return $this->inner->rollback(); } public function retire(): bool { return $this->inner->retire(); } public function is_retired(): bool { return $this->inner->is_retired(); } public function in_transaction(): bool { return $this->inner->in_transaction(); } public function validate_tables( array $tables ): bool { return $this->inner->validate_tables( $tables ); }
	public function query( string $sql ): int|false { if ( str_starts_with( $sql, 'INSERT INTO `durable_delivery_engine_delivery_quote_bindings`' ) ) { ++$this->insert_attempts; if ( $this->reject_insert ) { return false; } $result = $this->inner->query( $sql ); if ( $this->rebind_after_insert ) { $this->rebound = true; } if ( $this->throw_after_insert ) { throw new \RuntimeException( 'Synthetic insertion acknowledgment lost.' ); } return $result; } return $this->inner->query( $sql ); }
	public function get_row( string $sql ): array|null|false { if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { ++$this->clocks; return [ 'utc' => ( 3 === $this->clocks ? $this->final_time : ( 2 === $this->clocks ? $this->before_time : null ) )?->sql() ?? Q::time()->plus_seconds( 3 )->sql() ]; } return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false { if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_operation_records`' ) ) { $this->trace->events[] = 'history'; $this->history_sql[] = $sql; } elseif ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_delivery_quote_bindings`' ) ) { $this->trace->events[] = 'binding'; } return $this->inner->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); }
}
