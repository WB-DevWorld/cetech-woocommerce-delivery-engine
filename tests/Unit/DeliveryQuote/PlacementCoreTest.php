<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteAdmissionAttempt,QuoteDurableCommand,QuoteDurableResult,QuoteIssueCommand,QuotePlacementEvidence,QuotePlacementProof,QuotePlacementSavedEvidenceGuard,QuotePlacementService};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory,QuoteFixtureEvidence,QuoteFixtures,QuoteStorageFixtures};
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteDurableFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

final class PlacementCoreTest extends TestCase {
	private QuoteDurableFixtureFactory $f;
	private ?QuoteDurableResult $original = null;
	private ?QuoteDurableResult $accepted = null;
	protected function setUp(): void {
		$GLOBALS['blog_id'] = 1; $this->f = new QuoteDurableFixtureFactory();
		$this->f->pdo->exec( 'CREATE TABLE durable_order_facts(order_id INTEGER PRIMARY KEY,snapshot_digest TEXT NOT NULL,context_digest TEXT NOT NULL)' );
		$this->f->pdo->exec( "INSERT INTO durable_order_facts VALUES(100,'" . QuoteFixtures::digest( 'saved_snapshot' ) . "','" . QuoteFixtures::digest( 'saved_context' ) . "')" );
	}
	protected function tearDown(): void { unset( $GLOBALS['blog_id'] ); }
	private function saved(): QuotePlacementSavedEvidenceGuard { return new class implements QuotePlacementSavedEvidenceGuard {
		public function tables( OperationSession $session ): array { return [ 'durable_order_facts' ]; }
		public function verify( OperationSession $session, QuoteBinding $binding ): bool {
			if ( ! $session->in_transaction() || $session->is_retired() ) { return false; }
			$r = $session->get_row( $session->prepare( 'SELECT snapshot_digest,context_digest FROM durable_order_facts WHERE order_id=%d FOR UPDATE', $binding->row()['order_id'] ) );
			return is_array( $r ) && $r['snapshot_digest'] === $binding->row()['snapshot_digest'] && $r['context_digest'] === $binding->row()['context_digest'];
		}
	}; }
	private function prepare(): QuoteDurableResult {
		$s = $this->f->service(); $command = QuoteIssueCommand::create( QuoteFixtures::owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, 'placement_original' );
		$this->original = $s->issue( $command, QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame( 'accepted', $this->original->attempt->outcome->state );
		$this->f->utc = '2026-10-07 05:00:01.000000'; $q = $this->original->quote;
		$this->accepted = $s->accept( $q->header()->owner(), $this->original->command->reference(), $q->header(), $q->context(), RequestContext::create() ); self::assertSame( 'accepted', $this->accepted->attempt->outcome->state );
		$q = $this->accepted->quote; $raw = QuoteStorageFixtures::binding( $q )->row(); $names = QuoteDurableCommand::binding_namespaces( $q->header()->owner(), $q->header(), $raw['placement_uuid'], true );
		$b = QuoteBinding::from_row( array_replace( $raw, [ 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'] ] ), $q );
		$this->f->utc = '2026-10-07 05:00:02.000000'; $r = $s->bind( $q->header()->owner(), $this->original->command->reference(), $q->header(), $b, RequestContext::create(), $q->context() ); self::assertSame( 'accepted', $r->attempt->outcome->state ); return $r;
	}
	private function verified_input( QuoteDurableResult $prepared ): QuoteBinding {
		$this->f->utc = '2026-10-07 05:00:03.000000';
		return QuoteBinding::from_row( array_replace( $prepared->binding->row(), [ 'revision' => 2, 'snapshot_digest' => QuoteFixtures::digest( 'saved_snapshot' ), 'context_digest' => QuoteFixtures::digest( 'saved_context' ), 'verified_at' => $this->f->utc ] ), $prepared->quote );
	}
	private function verify( QuoteDurableResult $prepared ): QuoteDurableResult {
		$q = $prepared->quote; $r = $this->f->service()->verify_binding( $q->header()->owner(), $this->original->command->reference(), $q->header(), $this->verified_input( $prepared ), RequestContext::create(), $q->context(), $this->saved() ); self::assertSame( 'accepted', $r->attempt->outcome->state ); return $r;
	}
	private function proof( QuoteBinding $binding, ?\WC_Order $order = null, int $revision = 1 ): QuotePlacementProof { $local = EmergencyCheckoutLocalBinding::capture( $order ?? new \WC_Order( [ 'id' => 100 ] ) ); self::assertNotNull( $local ); return QuotePlacementProof::capture( $binding, $revision, $local, $this->saved() ); }
	private function seal( QuoteDurableResult $verified, QuotePlacementProof $proof ): QuoteDurableResult { $q = $verified->quote; return $this->f->service()->seal_placement( $q->header()->owner(), $this->original->command->reference(), $q->header(), $verified->binding, RequestContext::create(), $q->context(), $proof ); }
	private function revision(): int { return (int) $this->f->pdo->query( 'SELECT revision FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn(); }
	private function disposition( QuoteDurableResult $verified ): bool { $q = $verified->quote; return $this->f->service()->known_rejected_placement( $q->header()->owner(), $this->original->command->reference(), $q->header(), $verified->binding, $this->saved() ); }

	public function test_known_terminal_rejection_allows_only_readonly_original_prepared_two_disposition(): void {
		$v = $this->verify( $this->prepare() ); self::assertFalse( $this->disposition( $v ) ); $this->f->paused = true;
		$denied = $this->seal( $v, $this->proof( $v->binding ) ); self::assertSame( 'rejected', $denied->attempt->outcome->state );
		$rows = $this->f->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetchAll( \PDO::FETCH_ASSOC ); $records = $this->f->count( 'operation_records' ); $events = $this->f->count( 'operation_changes' );
		self::assertTrue( $this->disposition( $v ) ); self::assertTrue( $this->disposition( $v ) ); self::assertSame( $rows, $this->f->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quote_bindings' )->fetchAll( \PDO::FETCH_ASSOC ) ); self::assertSame( $records, $this->f->count( 'operation_records' ) ); self::assertSame( $events, $this->f->count( 'operation_changes' ) );
		$this->f->pdo->exec( "UPDATE durable_order_facts SET context_digest='" . QuoteFixtures::digest( 'changed_original' ) . "'" ); self::assertFalse( $this->disposition( $v ) );
		foreach ( $this->f->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}
	public function test_pending_or_malformed_final_receipt_cannot_release_original_prepared_history(): void {
		$v = $this->verify( $this->prepare() ); $this->f->paused = true; $denied = $this->seal( $v, $this->proof( $v->binding ) ); self::assertSame( 'rejected', $denied->attempt->outcome->state );
		$record = $this->f->pdo->query( "SELECT * FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.seal'" )->fetch( \PDO::FETCH_ASSOC );
		$this->f->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET state='pending',completion_json=NULL,completed_at=NULL WHERE operation='delivery_quote.seal'" ); self::assertFalse( $this->disposition( $v ) );
		$restore = $this->f->pdo->prepare( "UPDATE durable_delivery_engine_operation_records SET state='rejected',completion_json=?,completed_at=? WHERE operation='delivery_quote.seal'" ); $restore->execute( [ $record['completion_json'], $record['completed_at'] ] ); self::assertTrue( $this->disposition( $v ) );
		$this->f->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET completion_json='{}' WHERE operation='delivery_quote.seal'" ); self::assertFalse( $this->disposition( $v ) ); $restore->execute( [ $record['completion_json'], $record['completed_at'] ] ); self::assertTrue( $this->disposition( $v ) );
		$this->f->pdo->exec( "DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=(SELECT id FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.verify_binding')" ); self::assertFalse( $this->disposition( $v ) ); self::assertSame( 2, $this->revision() ); self::assertSame( 3, $this->f->count( 'operation_changes' ) );
	}
	public function test_accepted_final_receipt_and_missing_original_verified_event_never_claim_rejected_disposition(): void {
		$v = $this->verify( $this->prepare() ); self::assertSame( 'accepted', $this->seal( $v, $this->proof( $v->binding ) )->attempt->outcome->state ); self::assertFalse( $this->disposition( $v ) ); self::assertSame( 3, $this->revision() );
		$this->f->pdo->exec( "DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=(SELECT id FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.verify_binding')" ); self::assertFalse( $this->disposition( $v ) );
	}
	public function test_read_rollback_ack_loss_never_reports_known_rejected_disposition_or_replaces_owner(): void {
		$v = $this->verify( $this->prepare() ); $this->f->paused = true; self::assertSame( 'rejected', $this->seal( $v, $this->proof( $v->binding ) )->attempt->outcome->state ); $before = $this->f->opens; $this->f->lose_read_rollback_ack = true;
		self::assertFalse( $this->disposition( $v ) ); self::assertSame( $before + 1, $this->f->opens ); self::assertSame( 2, $this->revision() ); self::assertSame( 4, $this->f->count( 'operation_changes' ) ); self::assertTrue( $this->f->sessions[array_key_last( $this->f->sessions )]->is_retired() ); self::assertTrue( $this->disposition( $v ) );
	}

	public function test_saved_verification_is_a_separate_prepared_two_receipt_before_final_seal(): void {
		$r = $this->verify( $this->prepare() ); self::assertSame( 'prepared', $r->binding->state() ); self::assertSame( 2, $this->revision() ); self::assertNull( $r->binding->row()['sealed_at'] ); self::assertSame( 4, $this->f->count( 'operation_changes' ) );
		self::assertSame( 'delivery_quote.verify_binding', $r->command->identity->operation ); self::assertSame( QuoteDurableCommand::verification_namespace( $r->quote->header()->owner(), $r->quote->header(), $r->binding->row()['placement_uuid'] ), $r->command->identity->namespace_digest() );
		$again = $this->f->service()->reconcile( $r->command, RequestContext::create() ); self::assertTrue( $again->attempt->replayed ); self::assertSame( $r->attempt->completion->to_json(), $again->attempt->completion->to_json() ); self::assertSame( 4, $this->f->count( 'operation_changes' ) );
		$sealed = $this->seal( $r, $this->proof( $r->binding ) ); self::assertSame( 'accepted', $sealed->attempt->outcome->state ); self::assertSame( 2, $sealed->command->identity->operation_version ); self::assertSame( 'sealed', $sealed->binding->state() ); self::assertSame( 3, $this->revision() );
		$facts = $sealed->attempt->completion->result; foreach ( [ 'snapshot_digest', 'context_digest', 'native_money_digest' ] as $field ) { self::assertSame( $r->binding->row()[$field], $facts[$field] ); } self::assertSame( 1, $facts['control_revision'] ); self::assertSame( 5, $this->f->count( 'operation_changes' ) );
	}
	public function test_legacy_internal_seal_cannot_bypass_required_shopper_final_proof(): void {
		$r = $this->verify( $this->prepare() ); $q = $r->quote; $before = $this->f->count( 'operation_records' );
		$denied = $this->f->service()->seal( $q->header()->owner(), $this->original->command->reference(), $q->header(), $r->binding, RequestContext::create(), $q->context() ); self::assertSame( 'rejected', $denied->attempt->outcome->state ); self::assertSame( $before, $this->f->count( 'operation_records' ) ); self::assertSame( 2, $this->revision() );
	}
	public function test_unguarded_saved_bytes_cannot_claim_the_verified_two_stage(): void {
		$p = $this->prepare(); $q = $p->quote; $input = $this->verified_input( $p ); $this->f->pdo->exec( "UPDATE durable_order_facts SET snapshot_digest='" . QuoteFixtures::digest( 'different_snapshot' ) . "'" );
		$r = $this->f->service()->verify_binding( $q->header()->owner(), $this->original->command->reference(), $q->header(), $input, RequestContext::create(), $q->context(), $this->saved() ); self::assertSame( 'rejected', $r->attempt->outcome->state ); self::assertSame( 1, $this->revision() ); self::assertSame( 3, $this->f->count( 'operation_changes' ) );
	}
	public function test_overlapping_saved_guard_tables_count_once_within_the_existing_physical_union_limit(): void {
		$p = $this->prepare(); $q = $p->quote; $saved = $this->saved(); $repeated = new class( $saved ) implements QuotePlacementSavedEvidenceGuard {
			public function __construct( private QuotePlacementSavedEvidenceGuard $saved ) {}
			public function tables( OperationSession $session ): array { return array_fill( 0, 30, $this->saved->tables( $session )[0] ); }
			public function verify( OperationSession $session, QuoteBinding $binding ): bool { return $this->saved->verify( $session, $binding ); }
		};
		$r = $this->f->service()->verify_binding( $q->header()->owner(), $this->original->command->reference(), $q->header(), $this->verified_input( $p ), RequestContext::create(), $q->context(), $repeated ); self::assertSame( 'accepted', $r->attempt->outcome->state ); self::assertSame( 2, $r->binding->revision() );
	}
	public function test_retired_saved_read_stays_unknown_without_opening_a_replacement_rejection_owner(): void {
		$p = $this->prepare(); $q = $p->quote; $before = $this->f->opens; $saved = new class implements QuotePlacementSavedEvidenceGuard {
			public function tables( OperationSession $session ): array { return [ 'durable_order_facts' ]; }
			public function verify( OperationSession $session, QuoteBinding $binding ): bool { $session->retire(); return false; }
		};
		$r = $this->f->service()->verify_binding( $q->header()->owner(), $this->original->command->reference(), $q->header(), $this->verified_input( $p ), RequestContext::create(), $q->context(), $saved ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->binding ); self::assertSame( $before + 1, $this->f->opens ); self::assertSame( 1, $this->revision() ); self::assertSame( 3, $this->f->count( 'operation_changes' ) );
	}
	public function test_missing_verified_receipt_is_unavailable_despite_well_formed_prepared_two_row(): void {
		$r = $this->verify( $this->prepare() ); $this->f->pdo->exec( "DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=(SELECT id FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.verify_binding')" );
		$q = $r->quote; self::assertSame( 'unavailable', $this->f->service()->current( $q->header()->owner(), $this->original->command->reference(), $q->context(), RequestContext::create() )->status ); self::assertNotSame( 'accepted', $this->seal( $r, $this->proof( $r->binding ) )->attempt->outcome->state ); self::assertSame( 2, $this->revision() );
	}
	public function test_exact_c07_revision_and_pause_are_mandatory_at_final_point(): void {
		$r = $this->verify( $this->prepare() ); $denied = $this->seal( $r, $this->proof( $r->binding, revision: 2 ) ); self::assertSame( 'rejected', $denied->attempt->outcome->state ); self::assertSame( 2, $this->revision() );
		// A different proof/revision cannot replace the original one-shot final intent.
		$conflict = $this->seal( $r, $this->proof( $r->binding ) ); self::assertSame( 'intent_conflict', $conflict->attempt->outcome->error->code ); self::assertSame( 4, $this->f->count( 'operation_changes' ) );
	}
	public function test_pause_before_final_placement_does_not_seal_or_publish_an_admission(): void {
		$r = $this->verify( $this->prepare() ); $proof = $this->proof( $r->binding ); $this->f->paused = true; self::assertSame( 'rejected', $this->seal( $r, $proof )->attempt->outcome->state ); self::assertSame( 2, $this->revision() ); self::assertSame( 4, $this->f->count( 'operation_changes' ) );
	}
	public function test_exact_expiry_wins_before_final_placement(): void {
		$r = $this->verify( $this->prepare() ); $proof = $this->proof( $r->binding ); $this->f->utc = $r->quote->header()->expires_at()->sql(); $sealed = $this->seal( $r, $proof ); self::assertSame( 'rejected', $sealed->attempt->outcome->state ); self::assertSame( 'stale_revision', $sealed->attempt->outcome->error->code ); self::assertSame( 2, $this->revision() );
	}
	public function test_raw_order_mutation_after_c07_preparation_is_detected_inside_owned_unit(): void {
		$r = $this->verify( $this->prepare() ); $order = new \WC_Order( [ 'id' => 100 ] ); $proof = $this->proof( $r->binding, $order ); $this->f->before_guard = static function() use ( $order ): void { $order->set_status( 'cancelled' ); };
		self::assertSame( 'rejected', $this->seal( $r, $proof )->attempt->outcome->state ); self::assertSame( 2, $this->revision() );
	}
	public function test_current_locked_saved_snapshot_mutation_denies_without_sealing(): void {
		$r = $this->verify( $this->prepare() ); $proof = $this->proof( $r->binding ); $this->f->pdo->exec( "UPDATE durable_order_facts SET context_digest='" . QuoteFixtures::digest( 'different_context' ) . "'" );
		self::assertSame( 'rejected', $this->seal( $r, $proof )->attempt->outcome->state ); self::assertSame( 2, $this->revision() );
	}
	public function test_raw_mutation_after_acknowledged_commit_returns_unconfirmed_with_historical_seal_intact(): void {
		$r = $this->verify( $this->prepare() ); $order = new \WC_Order( [ 'id' => 100 ] ); $proof = $this->proof( $r->binding, $order );
		$this->f->on_retire = function() use ( $order ): void { if ( 3 === $this->revision() ) { $order->set_status( 'cancelled' ); } };
		$sealed = $this->seal( $r, $proof ); self::assertSame( 'unconfirmed', $sealed->attempt->outcome->state ); self::assertNull( $sealed->binding ); self::assertSame( 3, $this->revision() ); self::assertSame( 5, $this->f->count( 'operation_changes' ) );
	}
	public function test_new_saved_order_pay_attempt_requires_live_quote_and_revision_but_exact_continuation_receipt_is_historical(): void {
		$r = $this->verify( $this->prepare() ); $order = new \WC_Order( [ 'id' => 100 ] ); $local = EmergencyCheckoutLocalBinding::capture( $order ); $proof = QuotePlacementProof::capture( $r->binding, 1, $local, $this->saved() ); $sealed = $this->seal( $r, $proof ); self::assertSame( 'accepted', $sealed->attempt->outcome->state );
		$q = $sealed->quote; $s = $this->f->service(); $arguments = [ $q->header()->owner(), $this->original->command->reference(), $q->header(), $sealed->binding, RequestContext::create(), $q->context(), 1, $local, $this->saved() ]; self::assertTrue( $s->admit_sealed_placement( ...$arguments ) );
		$this->f->paused = true; self::assertFalse( $s->admit_sealed_placement( ...$arguments ) ); $replay = $s->reconcile( $sealed->command, RequestContext::create() ); self::assertSame( 'accepted', $replay->attempt->outcome->state ); self::assertSame( $sealed->attempt->completion->to_json(), $replay->attempt->completion->to_json() );
		$this->f->paused = false; $this->f->utc = $q->header()->expires_at()->sql(); self::assertFalse( $s->admit_sealed_placement( ...$arguments ) ); self::assertSame( 5, $this->f->count( 'operation_changes' ) ); self::assertSame( 3, $this->revision() );
	}
	public function test_replaying_verified_receipt_after_forged_snapshot_row_refuses_private_success(): void {
		$r = $this->verify( $this->prepare() ); $this->f->pdo->exec( "UPDATE durable_delivery_engine_delivery_quote_bindings SET snapshot_digest='" . QuoteFixtures::digest( 'tampered_row' ) . "'" );
		$replay = $this->f->service()->reconcile( $r->command, RequestContext::create() ); self::assertSame( 'unconfirmed', $replay->attempt->outcome->state ); self::assertNull( $replay->binding ); self::assertSame( 4, $this->f->count( 'operation_changes' ) );
	}
	public function test_accepted_verification_receipt_cannot_be_hidden_by_a_syntactically_valid_prepared_one_rollback(): void {
		$r = $this->verify( $this->prepare() ); $this->f->pdo->exec( 'UPDATE durable_delivery_engine_delivery_quote_bindings SET revision=1,snapshot_digest=NULL,context_digest=NULL,verified_at=NULL' );
		$q = $r->quote; self::assertSame( 'unavailable', $this->f->service()->current( $q->header()->owner(), $this->original->command->reference(), $q->context(), RequestContext::create() )->status ); $replay = $this->f->service()->reconcile( $r->command, RequestContext::create() ); self::assertSame( 'unconfirmed', $replay->attempt->outcome->state ); self::assertNull( $replay->binding ); self::assertSame( 4, $this->f->count( 'operation_changes' ) );
	}
	public function test_lost_verified_commit_ack_retains_prepared_two_and_reconciles_original_namespace_once(): void {
		$p = $this->prepare(); $q = $p->quote; $input = $this->verified_input( $p ); $this->f->effect_fault = 'lost_ack';
		$r = $this->f->service()->verify_binding( $q->header()->owner(), $this->original->command->reference(), $q->header(), $input, RequestContext::create(), $q->context(), $this->saved() ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->binding ); self::assertSame( 2, $this->revision() );
		$reconciled = $this->f->service()->reconcile( $r->command, RequestContext::create() ); self::assertSame( 'accepted', $reconciled->attempt->outcome->state ); self::assertTrue( $reconciled->attempt->replayed ); self::assertSame( 2, $reconciled->binding->revision() ); self::assertSame( 4, $this->f->count( 'operation_changes' ) ); self::assertSame( 4, $this->f->count( 'operation_records' ) );
	}
	public function test_unsent_verified_commit_rolls_back_snapshot_digests_and_prepared_two(): void {
		$p = $this->prepare(); $q = $p->quote; $input = $this->verified_input( $p ); $this->f->effect_fault = 'not_sent';
		$r = $this->f->service()->verify_binding( $q->header()->owner(), $this->original->command->reference(), $q->header(), $input, RequestContext::create(), $q->context(), $this->saved() ); self::assertNotSame( true, $r->attempt->outcome->mutation_accepted ); self::assertSame( 1, $this->revision() ); self::assertNull( $this->f->pdo->query( 'SELECT snapshot_digest FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn() ); self::assertSame( 3, $this->f->count( 'operation_changes' ) );
	}
	public function test_lost_final_commit_ack_requires_original_reconciliation_and_cannot_admit_from_unknown_result(): void {
		$r = $this->verify( $this->prepare() ); $proof = $this->proof( $r->binding ); $this->f->effect_fault = 'lost_ack'; $sealed = $this->seal( $r, $proof );
		self::assertSame( 'unconfirmed', $sealed->attempt->outcome->state ); self::assertNull( $sealed->binding ); self::assertNull( $sealed->attempt->completion ); self::assertSame( 3, $this->revision() );
		$reconciled = $this->f->service()->reconcile( $sealed->command, RequestContext::create() ); self::assertSame( 'accepted', $reconciled->attempt->outcome->state ); self::assertTrue( $reconciled->attempt->replayed ); self::assertSame( 3, $reconciled->binding->revision() ); self::assertSame( 5, $this->f->count( 'operation_changes' ) ); self::assertSame( 5, $this->f->count( 'operation_records' ) );
		$again = $this->f->service()->reconcile( $sealed->command, RequestContext::create() ); self::assertSame( $reconciled->attempt->completion->to_json(), $again->attempt->completion->to_json() ); self::assertSame( 5, $this->f->count( 'operation_changes' ) );
	}
	public function test_unsent_final_commit_keeps_only_verified_prepared_two_without_sealed_history(): void {
		$r = $this->verify( $this->prepare() ); $this->f->effect_fault = 'not_sent'; $sealed = $this->seal( $r, $this->proof( $r->binding ) ); self::assertNotSame( true, $sealed->attempt->outcome->mutation_accepted ); self::assertSame( 2, $this->revision() ); self::assertNull( $this->f->pdo->query( 'SELECT sealed_at FROM durable_delivery_engine_delivery_quote_bindings' )->fetchColumn() ); self::assertSame( 4, $this->f->count( 'operation_changes' ) );
	}
	public function test_replacement_process_uses_original_deterministic_placement_and_changed_order_conflicts(): void {
		$s = $this->f->service(); $original = $s->issue( QuoteIssueCommand::create( QuoteFixtures::owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, 'deterministic_placement' ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame( 'accepted', $original->attempt->outcome->state ); $q = $original->quote;
		$a = $s->accept( $q->header()->owner(), $original->command->reference(), $q->header(), $q->context(), RequestContext::create() ); self::assertSame( 'accepted', $a->attempt->outcome->state ); $q = $a->quote;
		$evidence = new QuotePlacementEvidence( $q, $original->command->reference(), $q->header(), $q->context(), new QuoteFixtureEvidence( $this->f ), fn(): bool => $this->f->authorized, QuoteTime::parse( $this->f->utc ) ); $mapping = QuoteStorageFixtures::binding( $q )->mapping();
		$first = new QuotePlacementService( $this->f->service(), $evidence ); $second = new QuotePlacementService( $this->f->service(), $evidence ); self::assertSame( $first->placement_id(), $second->placement_id() );
		$prepared = $first->prepare( 100, $mapping, RequestContext::create() ); self::assertSame( 'accepted', $prepared->attempt->outcome->state ); $replay = $second->prepare( 100, $mapping, RequestContext::create() ); self::assertSame( 'accepted', $replay->attempt->outcome->state ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( $prepared->binding->row(), $replay->binding->row() );
		$conflict = $second->prepare( 101, $mapping, RequestContext::create() ); self::assertSame( 'intent_conflict', $conflict->attempt->outcome->error->code ); self::assertSame( 1, $this->f->count( 'delivery_quote_bindings' ) ); self::assertSame( 3, $this->f->count( 'operation_changes' ) );
	}
}
