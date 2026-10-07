<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteAdmissionAttempt,QuoteDurableCommand,QuoteIssueCommand};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteId,QuoteJson,QuoteOwner,QuoteReference};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory,QuoteFixtures,QuoteFixturePublication,QuoteStorageFixtures};
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteDurableFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

final class DurableLifecycleTest extends TestCase {
	private QuoteDurableFixtureFactory $f;
	protected function setUp(): void { $this->f = new QuoteDurableFixtureFactory(); }
	private function command( string $token = 'original_issue_one', ?QuoteContext $context = null, ?QuoteOwner $owner = null ): QuoteIssueCommand { return QuoteIssueCommand::create( $owner ?? QuoteFixtures::owner(), $context ?? QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $token ); }
	private function inventory( string $status ): QuoteContext { $data = QuoteFixtures::context()->private_facts(); $data['lines'][0]['inventory']['status'] = $status; $data['lines'][0]['inventory']['evidence_digest'] = QuoteFixtures::digest( 'stock:' . $status ); return QuoteContext::from_array( $data ); }
	private function issue( string $token = 'original_issue_one' ) { return $this->f->service()->issue( $this->command( $token ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); }
	public function test_issue_accept_replay_preserves_original_capture_and_immutable_header(): void {
		$service = $this->f->service(); $original = $this->command();
		$issued = $service->issue( $original, QuoteAdmissionAttempt::generate(), RequestContext::create() );
		self::assertSame( 'accepted', $issued->attempt->outcome->state ); self::assertNotNull( $issued->quote ); self::assertNull( $issued->reason );
		$quote = $issued->quote; $reference = $issued->command->reference(); $this->f->utc = '2026-10-07 05:00:01.000000';
		$accepted = $service->accept( $quote->header()->owner(), $reference, $quote->header(), QuoteFixtures::context(), RequestContext::create() );
		self::assertSame( 'accepted', $accepted->attempt->outcome->state ); self::assertSame( 'accepted', $accepted->quote->state() );
		$replay = $service->accept( $quote->header()->owner(), $reference, $quote->header(), QuoteFixtures::context(), RequestContext::create() );
		self::assertTrue( $replay->attempt->replayed ); self::assertSame( $accepted->attempt->completion->to_json(), $replay->attempt->completion->to_json() );
		self::assertSame( $quote->header()->to_private_json(), $replay->quote->header()->to_private_json() ); self::assertSame( 1, $this->f->captures ); self::assertSame( 2, $this->f->count( 'operation_changes' ) ); self::assertSame( 1, $this->f->count( 'delivery_quotes' ) );
		self::assertSame( $original->identity()->namespace_digest(), $quote->header()->namespace_hashes()['issue'] ); self::assertSame( $accepted->command->identity->namespace_digest(), $quote->header()->namespace_hashes()['accept'] );
	}
	public function test_expired_issue_and_accepted_replays_keep_original_identity_and_history(): void {
		$s = $this->f->service(); $c = $this->command(); $first = $s->issue( $c, QuoteAdmissionAttempt::generate(), RequestContext::create() ); $q = $first->quote; $ref = $first->command->reference();
		$this->f->utc = '2026-10-07 05:00:01.000000'; $accepted = $s->accept( $c->owner(), $ref, $q->header(), $c->context(), RequestContext::create() );
		$this->f->utc = '2026-10-07 05:05:00.000000'; $issue = $s->issue( $c, QuoteAdmissionAttempt::generate(), RequestContext::create() ); $replay = $s->accept( $c->owner(), $ref, $q->header(), $c->context(), RequestContext::create() );
		self::assertSame( $q->header()->id()->value(), $issue->quote->header()->id()->value() ); self::assertSame( $q->header()->expires_at()->sql(), $issue->quote->header()->expires_at()->sql() );
		self::assertSame( $accepted->attempt->completion->to_json(), $replay->attempt->completion->to_json() ); self::assertSame( 'quote_expired', $replay->reason ); self::assertSame( 'accepted', $replay->quote->state() ); self::assertSame( 1, $this->f->captures ); self::assertSame( 2, $this->f->count( 'operation_changes' ) );
	}
	public function test_first_accept_at_expiry_refuses_without_changing_quote_or_audit(): void {
		$r = $this->issue(); $this->f->utc = '2026-10-07 05:05:00.000000'; $a = $this->f->service()->accept( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), QuoteFixtures::context(), RequestContext::create() );
		self::assertSame( 'rejected', $a->attempt->outcome->state ); self::assertSame( 'stale_revision', $a->attempt->outcome->error->code ); self::assertSame( 1, $this->f->count( 'operation_changes' ) ); self::assertSame( 'issued', $this->f->pdo->query( 'SELECT state FROM durable_delivery_engine_delivery_quotes' )->fetchColumn() );
	}
	public function test_unknown_current_read_never_invalidates_and_confirmed_change_does_once(): void {
		$r = $this->issue(); $unknown = $this->inventory( 'unknown' );
		$a = $this->f->service()->invalidate( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), $unknown, RequestContext::create() ); self::assertSame( 'rejected', $a->attempt->outcome->state ); self::assertSame( 1, $this->f->count( 'operation_changes' ) );
		$current = $this->inventory( 'ineligible' ); $this->f->pdo->exec( "UPDATE durable_fence SET context_digest='" . $current->digest() . "'" ); $this->f->utc = '2026-10-07 05:00:01.000000';
		$b = $this->f->service()->invalidate( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), $current, RequestContext::create() ); self::assertSame( 'accepted', $b->attempt->outcome->state ); self::assertSame( 'invalidated', $b->quote->state() ); self::assertSame( 2, $this->f->count( 'operation_changes' ) );
		$replay = $this->f->service()->invalidate( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), $current, RequestContext::create() ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( $b->attempt->completion->to_json(), $replay->attempt->completion->to_json() ); self::assertSame( 2, $this->f->count( 'operation_changes' ) );
	}
	public function test_changed_issue_intent_has_no_second_capture_charge_or_effect(): void {
		$this->issue(); $current = $this->inventory( 'ineligible' ); $before = $this->f->count( 'delivery_quote_budget_windows' );
		$r = $this->f->service()->issue( $this->command( context: $current ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame( 'intent_conflict', $r->attempt->outcome->error->code ); self::assertSame( 1, $this->f->captures ); self::assertSame( $before, $this->f->count( 'delivery_quote_budget_windows' ) ); self::assertSame( 1, $this->f->count( 'operation_changes' ) );
	}
	public function test_sent_commit_lost_ack_reconciles_on_fresh_owner_without_capture_or_second_effect(): void {
		$this->f->effect_fault = 'lost_ack'; $r = $this->issue(); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->quote ); self::assertSame( 1, $this->f->count( 'delivery_quotes' ) );
		$opens = $this->f->opens; $reconciled = $this->f->service()->reconcile( $r->command, RequestContext::create() ); self::assertSame( 'accepted', $reconciled->attempt->outcome->state ); self::assertNotNull( $reconciled->quote ); self::assertGreaterThan( $opens, $this->f->opens ); self::assertSame( 1, $this->f->captures ); self::assertSame( 1, $this->f->count( 'operation_changes' ) );
	}
	public function test_unsent_commit_and_refused_audit_roll_back_quote_and_consumption(): void {
		foreach ( [ 'not_sent', 'audit' ] as $fault ) { $this->f = new QuoteDurableFixtureFactory(); if ( 'audit' === $fault ) { $this->f->reject_audit = true; } else { $this->f->effect_fault = $fault; } $r = $this->issue(); self::assertSame( 'rejected', $r->attempt->outcome->state ); self::assertSame( 0, $this->f->count( 'delivery_quotes' ) ); self::assertSame( 0, $this->f->count( 'operation_changes' ) ); self::assertSame( 'granted', $this->f->pdo->query( "SELECT lease_state FROM durable_delivery_engine_delivery_quote_budget_windows WHERE slot_kind='admission'" )->fetchColumn() ); $again = $this->issue(); self::assertSame( 'pending', $again->attempt->outcome->state ); self::assertSame( 1, $this->f->captures ); }
	}
	public function test_publication_failure_reconcile_retries_only_invalidation_and_delayed_old_publish_never_replaces(): void {
		$p = new QuoteFixturePublication(); $p->allowed = false; $s = $this->f->service( $p ); $r = $s->issue( $this->command(), QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertTrue( $r->attempt->outcome->mutation_accepted ); self::assertTrue( $r->attempt->outcome->publication_pending ); self::assertSame( 1, $this->f->count( 'delivery_quotes' ) );
		$p->allowed = true; $new = $s->issue( $this->command( 'newer_selection' ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); $new_id = $new->quote->header()->id()->value(); $again = $s->reconcile( $r->command, RequestContext::create() );
		self::assertSame( 'accepted', $again->attempt->outcome->state ); self::assertSame( $r->quote->header()->id()->value(), $again->quote->header()->id()->value() ); self::assertSame( [ $new_id, $r->quote->header()->id()->value() ], $p->invalidated ); self::assertSame( 2, $this->f->captures ); self::assertSame( 2, $this->f->count( 'operation_changes' ) ); self::assertSame( 2, $this->f->count( 'delivery_quotes' ) );
	}
	public function test_pause_and_source_fence_mismatch_never_accept_first_effect(): void {
		$this->f->paused = true; $r = $this->issue(); self::assertSame( 'checkout_suspended', $r->reason ); self::assertSame( 0, $this->f->captures ); self::assertSame( 0, $this->f->count( 'operation_records' ) );
		$this->f->paused = false; $this->f->guard_ok = false; $r = $this->issue(); self::assertSame( 'rejected', $r->attempt->outcome->state ); self::assertSame( 0, $this->f->count( 'delivery_quotes' ) ); self::assertSame( 0, $this->f->count( 'operation_changes' ) );
	}
	public function test_current_control_is_rechecked_after_capture_outside_every_owned_transaction(): void {
		$this->f->before_guard = function(): void { self::assertTrue( $this->f->pdo->inTransaction() ); }; $this->issue(); self::assertSame( 1, $this->f->captures );
		$sequence = $this->f->statements; $control = array_search( 'FIXTURE CURRENT CONTROL FOR UPDATE', $sequence, true ); self::assertIsInt( $control ); self::assertGreaterThan( $control, array_search( 'SELECT context_digest FROM durable_fence WHERE id=1 FOR UPDATE', $sequence, true ) );
	}
	public function test_producer_uses_nonlocking_immutable_prerequisites_before_control_and_current_quote_lock(): void {
		$r = $this->issue(); $this->f->statements = []; $a = $this->f->service()->accept( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), QuoteFixtures::context(), RequestContext::create() ); self::assertSame( 'accepted', $a->attempt->outcome->state );
		$receipt_queries = array_values( array_filter( $this->f->statements, static fn( string $sql ): bool => str_starts_with( $sql, 'SELECT id,site_id,namespace_hash' ) ) ); self::assertNotEmpty( $receipt_queries ); self::assertStringNotContainsString( 'FOR UPDATE', $receipt_queries[0] );
		$first = array_search( $receipt_queries[0], $this->f->statements, true ); $control = array_search( 'FIXTURE CURRENT CONTROL FOR UPDATE', $this->f->statements, true ); self::assertLessThan( $control, $first ); self::assertNotEmpty( array_filter( $this->f->statements, static fn( string $sql ): bool => str_contains( $sql, 'delivery_quotes`' ) && str_ends_with( $sql, 'FOR UPDATE' ) ) );
	}
	public function test_private_facts_hidden_if_authority_is_revoked_during_final_retirement(): void {
		$r = $this->issue(); $s = $this->f->service(); $baseline = $s->current( QuoteFixtures::owner(), $r->command->reference(), QuoteFixtures::context(), RequestContext::create() ); self::assertSame( 'ready', $baseline->status ); self::assertNotNull( $baseline->quote );
		$ran = false; $this->f->on_retire = function() use ( &$ran ): void { $ran = true; $this->f->authorized = false; }; $read = $s->current( QuoteFixtures::owner(), $r->command->reference(), QuoteFixtures::context(), RequestContext::create() );
		self::assertTrue( $ran ); self::assertFalse( $this->f->authorized ); self::assertSame( 'unavailable', $read->status ); self::assertNull( $read->quote );
	}
	public function test_original_handle_and_command_hidden_if_revoked_during_post_commit_load_release(): void {
		$this->f->on_retire = function(): void { if ( $this->f->count( 'operation_records' ) === 1 && 'published' === $this->f->pdo->query( 'SELECT publication_state FROM durable_delivery_engine_operation_records' )->fetchColumn() && $this->f->opens >= 4 ) { $this->f->authorized = false; } };
		$r = $this->issue(); self::assertSame( 1, $this->f->count( 'delivery_quotes' ) ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->command ); self::assertNull( $r->quote ); self::assertNull( $r->attempt->completion );
	}
	public function test_row_alone_cannot_claim_accepted_receipt_and_missing_body_replay_is_unconfirmed(): void {
		$r = $this->issue(); $this->f->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_changes' ); $current = $this->f->service()->current( QuoteFixtures::owner(), $r->command->reference(), QuoteFixtures::context(), RequestContext::create() ); self::assertSame( 'unavailable', $current->status ); self::assertNull( $current->quote );
		$replay = $this->issue(); self::assertSame( 'unconfirmed', $replay->attempt->outcome->state ); self::assertNull( $replay->quote ); self::assertSame( 1, $this->f->captures );
	}
	public function test_owner_handle_and_estimate_guards_deny_before_private_disclosure_or_reservation(): void {
		$r = $this->issue(); $wrong_owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'session_hash' => QuoteFixtures::digest( 'another_session' ) ] ) );
		$bad = $this->f->service()->accept( $wrong_owner, $r->command->reference(), $r->quote->header(), QuoteFixtures::context(), RequestContext::create() ); self::assertSame( 'not_authorized', $bad->attempt->outcome->error->code ); self::assertNull( $bad->command ); self::assertNull( $bad->quote );
		$wrong_ref = QuoteReference::generate( $r->quote->header()->id() ); self::assertSame( 'unavailable', $this->f->service()->current( QuoteFixtures::owner(), $wrong_ref, QuoteFixtures::context(), RequestContext::create() )->status );
		$this->f = new QuoteDurableFixtureFactory(); $estimate = QuoteFixtures::context( [ 'kind' => 'estimate' ] ); $bad = $this->f->service()->issue( $this->command( context: $estimate ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame( 'invalid_input', $bad->attempt->outcome->error->code ); self::assertSame( 0, $this->f->captures ); self::assertSame( 0, $this->f->count( 'operation_records' ) ); self::assertSame( 0, $this->f->count( 'delivery_quote_budget_windows' ) );
	}
	public function test_internal_binding_then_verified_seal_has_exact_linked_replay_without_payment_admission(): void {
		$r = $this->issue(); $this->f->utc = '2026-10-07 05:00:01.000000'; $accepted = $this->f->service()->accept( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), QuoteFixtures::context(), RequestContext::create() ); $q = $accepted->quote;
		$binding = QuoteStorageFixtures::binding( $q ); $row = $binding->row(); $names = QuoteDurableCommand::binding_namespaces( QuoteFixtures::owner(), $q->header(), $row['placement_uuid'] ); $binding = QuoteBinding::from_row( array_replace( $row, [ 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'] ] ), $q );
		$bound = $this->f->service()->bind( QuoteFixtures::owner(), $r->command->reference(), $q->header(), $binding, RequestContext::create(), QuoteFixtures::context() ); self::assertSame( 'accepted', $bound->attempt->outcome->state ); self::assertSame( 'prepared', $bound->binding->state() );
		$this->f->utc = '2026-10-07 05:00:02.000000'; $verified = QuoteBinding::from_row( array_replace( $bound->binding->row(), [ 'revision' => 2, 'snapshot_digest' => QuoteFixtures::digest( 'snapshot' ), 'context_digest' => QuoteFixtures::digest( 'sealed_context' ), 'verified_at' => $this->f->utc ] ), $q );
		$sealed = $this->f->service()->seal( QuoteFixtures::owner(), $r->command->reference(), $q->header(), $verified, RequestContext::create(), QuoteFixtures::context() ); self::assertSame( 'accepted', $sealed->attempt->outcome->state ); self::assertSame( 'sealed', $sealed->binding->state() ); self::assertSame( 3, $sealed->binding->revision() );
		$again = $this->f->service()->seal( QuoteFixtures::owner(), $r->command->reference(), $q->header(), $verified, RequestContext::create(), QuoteFixtures::context() ); self::assertTrue( $again->attempt->replayed ); self::assertSame( $sealed->attempt->completion->to_json(), $again->attempt->completion->to_json() ); self::assertSame( 4, $this->f->count( 'operation_changes' ) ); self::assertSame( 1, $this->f->count( 'delivery_quote_bindings' ) );
	}

	public function test_same_material_probe_does_not_consume_future_real_invalidation_namespace(): void {
		$r = $this->issue(); $s = $this->f->service(); $same = $s->invalidate( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), QuoteFixtures::context(), RequestContext::create() ); self::assertSame( 'invalid_input', $same->attempt->outcome->error->code ); self::assertSame( 1, $this->f->count( 'operation_records' ) );
		$current = $this->inventory( 'ineligible' ); $this->f->pdo->exec( "UPDATE durable_fence SET context_digest='" . $current->digest() . "'" ); $changed = $s->invalidate( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), $current, RequestContext::create() ); self::assertSame( 'accepted', $changed->attempt->outcome->state ); self::assertSame( 'invalidated', $changed->quote->state() ); self::assertSame( 2, $this->f->count( 'operation_records' ) );
	}
	public function test_same_live_gate_ack_recovery_can_capture_once_but_reconstructed_attempt_cannot(): void {
		$c = $this->command(); $a = QuoteAdmissionAttempt::generate(); $s = $this->f->service(); $this->f->gate_fault = 'lost_ack'; $first = $s->issue( $c, $a, RequestContext::create() ); self::assertSame( 'unconfirmed', $first->attempt->outcome->state ); self::assertSame( 0, $this->f->captures ); self::assertSame( 0, $this->f->count( 'operation_records' ) );
		$fresh = $s->issue( $c, QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame( 'pending', $fresh->attempt->outcome->state ); self::assertSame( 0, $this->f->captures );
		$recovered = $s->issue( $c, $a, RequestContext::create() ); self::assertSame( 'accepted', $recovered->attempt->outcome->state ); self::assertNotNull( $recovered->command->reference() ); self::assertSame( 1, $this->f->captures ); self::assertSame( 1, $this->f->count( 'operation_changes' ) );
		$replay = $s->issue( $c, $a, RequestContext::create() ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( 1, $this->f->captures ); self::assertNull( $replay->command->reference() );
		$probe = QuoteDurableCommand::issue_probe( $c, $recovered->command->reference(), $recovered->quote->header() ); $historical = $s->reconcile( $probe, RequestContext::create() ); self::assertSame( $recovered->command->reference()->handle(), $historical->command->reference()->handle() ); self::assertSame( 1, $this->f->captures );
	}
	public function test_advisory_delete_refusal_retaining_old_value_is_not_publication_success(): void {
		$owner = QuoteFixtures::owner(); $old = QuoteId::generate(); $new = QuoteId::generate(); $oldkey = \CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdvisoryPublication::key( 1, $owner->digest(), $old ); $newkey = \CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdvisoryPublication::key( 1, $owner->digest(), $new );
		$cache = [ $oldkey => 'old_private_generation', $newkey => 'newer_private_generation' ]; $refused = true; $forced = [];
		$p = new \CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdvisoryPublication( static function( $key, $group ) use ( &$cache, &$refused ): bool { if ( $refused ) { return false; } unset( $cache[$key] ); return true; }, static function( $key, $group, $force, &$found ) use ( &$cache, &$forced ) { $forced[] = $force; $found = array_key_exists( $key, $cache ); return $cache[$key] ?? false; } );
		self::assertFalse( $p->invalidate( 1, $owner->digest(), $old ) ); self::assertSame( 'old_private_generation', $cache[$oldkey] ); $refused = false; self::assertTrue( $p->invalidate( 1, $owner->digest(), $old ) ); self::assertArrayNotHasKey( $oldkey, $cache ); self::assertSame( 'newer_private_generation', $cache[$newkey] ); self::assertSame( [ true, true ], $forced );
	}
	public function test_finite_completion_and_event_have_no_body_money_handle_or_context_values(): void {
		$r = $this->issue(); $bytes = $r->attempt->completion->to_json() . $this->f->pdo->query( 'SELECT event_json FROM durable_delivery_engine_operation_changes' )->fetchColumn();
		foreach ( [ $r->command->reference()->handle(), 'GHS', 'Fixture delivery', 'line_one', 'destination', 'private_body_json', 'amount' ] as $private ) { self::assertStringNotContainsString( $private, $bytes ); }
		foreach ( [ $r, $r->command, $r->quote ] as $private ) { try { json_encode( $private, JSON_THROW_ON_ERROR ); self::fail( 'Private carrier was generically serialized.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
	}

	public function test_accepted_receipt_cannot_be_replayed_as_success_over_reverted_issued_row(): void {
		$r = $this->issue(); $s = $this->f->service(); $accepted = $s->accept( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), QuoteFixtures::context(), RequestContext::create() ); self::assertSame( 'accepted', $accepted->attempt->outcome->state );
		$this->f->pdo->exec( "UPDATE durable_delivery_engine_delivery_quotes SET state='issued',revision=1,accepted_at=NULL,transition_at=NULL" ); $replay = $s->accept( QuoteFixtures::owner(), $r->command->reference(), $r->quote->header(), QuoteFixtures::context(), RequestContext::create() );
		self::assertSame( 'unconfirmed', $replay->attempt->outcome->state ); self::assertNull( $replay->quote ); self::assertSame( 2, $this->f->count( 'operation_changes' ) ); self::assertSame( 1, $this->f->captures );
	}

	public function test_provider_failure_after_authority_revocation_does_not_disclose_original_command(): void {
		$this->f->before_capture = function(): void { self::assertFalse( $this->f->pdo->inTransaction() ); $this->f->authorized = false; throw new \RuntimeException( 'private fixture capture failure' ); };
		$r = $this->issue(); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->command ); self::assertNull( $r->quote ); self::assertSame( 0, $this->f->count( 'operation_records' ) ); self::assertSame( 0, $this->f->count( 'delivery_quotes' ) );
	}

}
