<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteAdmissionAttempt,QuoteDurableCommand,QuoteDurableResult,QuoteIssueCommand,QuoteOperationProfile,QuotePlacementEvidence,QuotePlacementNoEffectDisposition,QuotePlacementNoEffectEvidenceGuard,QuotePlacementSavedEvidenceGuard,QuotePlacementService};
use CetechDeliveryEngine\Application\Operation\{OperationCoordinator,OperationReadiness};
use CetechDeliveryEngine\Domain\Contracts\{ContractError,RequestContext};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteStoredRow,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\{OperationProfileRegistry,OperationSession};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{EarlyPlacementDispositionProbe,QuoteDurableFixtureFactory,QuoteFixtureControl,QuoteFixtureEvidence,QuoteFixtures,QuoteStorageFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteDurableFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/EarlyPlacementDispositionProbe.php';

/** Real coordinator/receipt rows in the SQLite fixture; the native fence is a prewarmed SQL port. */
final class EarlyPlacementDispositionTest extends TestCase {
	private QuoteDurableFixtureFactory $f;
	private QuoteDurableResult $original;
	private QuotePlacementEvidence $replacement;
	private ?QuoteBinding $binding = null;
	private EarlyPlacementNativeFence $native;
	private string $placement;
	private QuoteDurableCommand $rejected_command;
	private int $clock = 0;
	private bool $authorization_inside_unit = false;
	protected function setUp(): void {
		$GLOBALS['blog_id'] = 1; $this->f = new QuoteDurableFixtureFactory();
		$this->f->pdo->exec( 'CREATE TABLE durable_original_native(order_id INTEGER PRIMARY KEY,physical_bytes TEXT NOT NULL)' );
		$this->f->pdo->exec( "INSERT INTO durable_original_native VALUES(100,'original_native_exact')" );
		$this->native = new EarlyPlacementNativeFence();
	}
	protected function tearDown(): void { unset( $GLOBALS['blog_id'] ); }
	private function tick(): void { $this->f->utc = sprintf( '2026-10-07 05:00:%02d.000000', ++$this->clock ); }
	private function reviewed( string $key ): array {
		$this->tick(); $s = $this->f->service(); $issued = $s->issue( QuoteIssueCommand::create( QuoteFixtures::owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $key ), QuoteAdmissionAttempt::generate(), RequestContext::create() );
		self::assertSame( 'accepted', $issued->attempt->outcome->state ); $this->tick(); $q = $issued->quote;
		$accepted = $s->accept( $q->header()->owner(), $issued->command->reference(), $q->header(), $q->context(), RequestContext::create() ); self::assertSame( 'accepted', $accepted->attempt->outcome->state );
		$q = $accepted->quote; $evidence = new QuotePlacementEvidence( $q, $issued->command->reference(), $q->header(), $q->context(), new QuoteFixtureEvidence( $this->f ), function(): bool { if ( $this->f->pdo->inTransaction() ) { $this->authorization_inside_unit = true; return false; } return $this->f->authorized; }, QuoteTime::parse( $this->f->utc ) );
		return [ $accepted, $evidence ];
	}
	private function arrange( string $phase = 'bind' ): void {
		[ $this->original, $old ] = $this->reviewed( 'early_original' ); $service = new QuotePlacementService( $this->f->service(), $old ); $this->placement = $service->placement_id(); $this->tick();
		$this->f->paused = 'bind' === $phase; $prepared = $service->prepare( 100, QuoteStorageFixtures::binding( $old->quote_record() )->mapping(), RequestContext::create() );
		if ( 'bind' === $phase ) { self::assertSame( 'rejected', $prepared->attempt->outcome->state ); self::assertNull( $prepared->binding ); $this->rejected_command = $prepared->command; }
		else {
			self::assertSame( 'accepted', $prepared->attempt->outcome->state ); $this->binding = $prepared->binding; $this->tick();
			$input = $service->verified_binding( $this->binding, QuoteFixtures::digest( 'saved_snapshot' ), QuoteFixtures::digest( 'saved_context' ), QuoteTime::parse( $this->f->utc ) ); $this->f->paused = true;
			$saved = new class implements QuotePlacementSavedEvidenceGuard { public function tables( OperationSession $session ): array { return [ 'durable_original_native' ]; } public function verify( OperationSession $session, QuoteBinding $binding ): bool { return $session->in_transaction() && ! $session->is_retired() && is_array( $session->get_row( 'SELECT physical_bytes FROM durable_original_native WHERE order_id=100 FOR UPDATE' ) ); } };
			$rejected = $service->verify( $input, $saved, RequestContext::create() ); self::assertSame( 'rejected', $rejected->attempt->outcome->state ); $this->rejected_command = $rejected->command;
		}
		$this->f->paused = false; [ , $this->replacement ] = $this->reviewed( 'early_replacement' ); $this->native->expected_binding = $this->binding?->row();
	}
	private function dispose( ?QuotePlacementEvidence $replacement = null, ?QuoteStoredRow $original = null, int $order_id = 100, ?string $placement = null, ?QuoteBinding $binding = null ): bool {
		return $this->f->service()->known_rejected_original_placement( $replacement ?? $this->replacement, $original ?? $this->original->quote, $order_id, $placement ?? $this->placement, $binding ?? $this->binding, $this->native );
	}
	private function history(): array {
		$out = []; foreach ( [ 'operation_records', 'operation_changes', 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows' ] as $suffix ) { $out[$suffix] = $this->f->pdo->query( 'SELECT * FROM durable_delivery_engine_' . $suffix . ' ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); }
		$out['native'] = $this->f->pdo->query( 'SELECT * FROM durable_original_native' )->fetchAll( \PDO::FETCH_ASSOC ); return $out;
	}
	public static function phases(): array { return [ 'terminal original bind without binding' => [ 'bind' ], 'terminal original verify with prepared one' => [ 'verify_binding' ] ]; }
	#[DataProvider( 'phases' )]
	public function test_known_early_rejection_and_new_review_allow_only_acknowledged_readonly_disposition( string $phase ): void {
		$this->arrange( $phase ); $history = $this->history(); $opens = $this->f->opens; $captures = $this->f->captures; $this->f->statements = [];
		self::assertTrue( $this->dispose() ); self::assertSame( $opens + 1, $this->f->opens ); self::assertSame( $history, $this->history() ); self::assertSame( $captures, $this->f->captures ); self::assertFalse( $this->authorization_inside_unit ); self::assertSame( 2, $this->native->reads );
		$names = []; foreach ( $this->f->statements as $sql ) { if ( str_contains( $sql, 'FROM `durable_delivery_engine_operation_records`' ) && preg_match( "/namespace_hash='([a-f0-9]{64})'/", $sql, $m ) ) { $names[] = $m[1]; self::assertStringEndsWith( ' FOR UPDATE', $sql ); } self::assertDoesNotMatchRegularExpression( '/\\A(?:INSERT|UPDATE|DELETE|REPLACE) /', $sql ); }
		self::assertCount( 9, $names ); $sorted = $names; sort( $sorted, SORT_STRING ); self::assertSame( $sorted, $names );
		foreach ( $this->f->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
		self::assertTrue( $this->dispose() ); self::assertSame( $history, $this->history() );
	}
	#[DataProvider( 'phases' )]
	public function test_same_original_quote_never_becomes_an_explicit_new_intent( string $phase ): void {
		$this->arrange( $phase ); $q = $this->original->quote; $same = new QuotePlacementEvidence( $q, $this->original->command->reference(), $q->header(), $q->context(), new QuoteFixtureEvidence( $this->f ), fn(): bool => true, QuoteTime::parse( $this->f->utc ) ); $opens = $this->f->opens;
		self::assertFalse( $this->dispose( $same ) ); self::assertSame( $opens, $this->f->opens );
	}
	#[DataProvider( 'phases' )]
	public function test_missing_pending_or_malformed_original_terminal_receipt_denies( string $phase ): void {
		$this->arrange( $phase ); $statement = $this->f->pdo->prepare( 'SELECT * FROM durable_delivery_engine_operation_records WHERE operation=?' ); $statement->execute( [ 'delivery_quote.' . $phase ] ); $row = $statement->fetch( \PDO::FETCH_ASSOC );
		$this->f->pdo->exec( 'UPDATE durable_delivery_engine_operation_records SET state=\'pending\',completion_json=NULL,completed_at=NULL WHERE id=' . $row['id'] ); self::assertFalse( $this->dispose() );
		$restore = $this->f->pdo->prepare( 'UPDATE durable_delivery_engine_operation_records SET state=?,completion_json=?,completed_at=? WHERE id=?' ); $restore->execute( [ $row['state'], '{}', $row['completed_at'], $row['id'] ] ); self::assertFalse( $this->dispose() );
		$restore->execute( [ $row['state'], $row['completion_json'], $row['completed_at'], $row['id'] ] ); self::assertTrue( $this->dispose() );
		$this->f->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_records WHERE id=' . $row['id'] ); self::assertFalse( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_terminal_no_effect_receipt_cannot_hide_an_audit_or_publication( string $phase ): void {
		$this->arrange( $phase ); $this->f->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET publication_state='pending' WHERE operation='delivery_quote." . $phase . "'" ); self::assertFalse( $this->dispose() );
		$this->f->pdo->exec( "UPDATE durable_delivery_engine_operation_records SET publication_state='none',audit_id=777 WHERE operation='delivery_quote." . $phase . "'" ); self::assertFalse( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_missing_original_accepted_event_and_new_accepted_event_deny( string $phase ): void {
		$this->arrange( $phase ); $this->f->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=(SELECT id FROM durable_delivery_engine_operation_records WHERE namespace_hash=\'' . $this->original->quote->header()->namespace_hashes()['accept'] . '\')' ); self::assertFalse( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_missing_new_accepted_event_denies_known_replacement( string $phase ): void {
		$this->arrange( $phase ); $this->f->pdo->exec( 'DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=(SELECT id FROM durable_delivery_engine_operation_records WHERE namespace_hash=\'' . $this->replacement->header()->namespace_hashes()['accept'] . '\')' ); self::assertFalse( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_replacement_physical_rollback_cannot_reuse_accepted_receipt( string $phase ): void {
		$this->arrange( $phase ); $id = $this->replacement->quote_record()->id(); $this->f->pdo->exec( "UPDATE durable_delivery_engine_delivery_quotes SET state='issued',revision=1,accepted_at=NULL,transition_at=NULL WHERE id=" . $id ); self::assertFalse( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_stale_private_native_and_current_evidence_refuse_without_writes( string $phase ): void {
		$this->arrange( $phase ); $history = $this->history(); $this->native->raw_ok = false; self::assertFalse( $this->dispose() ); $this->native->raw_ok = true; $this->f->guard_ok = false; self::assertFalse( $this->dispose() ); $this->f->guard_ok = true;
		self::assertSame( $history, $this->history() ); $this->f->pdo->exec( "UPDATE durable_original_native SET physical_bytes='changed_saved_bytes'" ); self::assertFalse( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_lost_read_rollback_ack_never_releases_or_opens_replacement_owner( string $phase ): void {
		$this->arrange( $phase ); $history = $this->history(); $opens = $this->f->opens; $this->f->lose_read_rollback_ack = true; self::assertFalse( $this->dispose() ); self::assertSame( $opens + 1, $this->f->opens ); self::assertSame( $history, $this->history() ); self::assertTrue( $this->f->sessions[array_key_last( $this->f->sessions )]->is_retired() ); self::assertTrue( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_raw_or_authorization_change_at_retirement_denies_acknowledged_read( string $phase ): void {
		$this->arrange( $phase ); $this->f->on_retire = function(): void { $this->native->raw_ok = false; }; self::assertFalse( $this->dispose() ); $this->native->raw_ok = true;
		$this->f->on_retire = function(): void { $this->f->authorized = false; }; self::assertFalse( $this->dispose() );
	}
	#[DataProvider( 'phases' )]
	public function test_wrong_order_and_wrong_deterministic_pointer_deny_before_sql( string $phase ): void {
		$this->arrange( $phase ); $opens = $this->f->opens; self::assertFalse( $this->dispose( order_id: 101 ) ); self::assertFalse( $this->dispose( placement: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' ) ); self::assertSame( $opens, $this->f->opens );
	}
	#[DataProvider( 'phases' )]
	public function test_expired_old_quote_can_dispose_but_expired_new_review_cannot( string $phase ): void {
		$this->arrange( $phase ); $this->f->utc = $this->original->quote->header()->expires_at()->sql(); self::assertTrue( $this->dispose() ); $this->f->utc = $this->replacement->header()->expires_at()->sql(); self::assertFalse( $this->dispose() );
	}
	public function test_prepared_one_without_terminal_verification_never_disposes(): void {
		$this->arrange( 'verify_binding' ); $this->f->pdo->exec( "DELETE FROM durable_delivery_engine_operation_records WHERE operation='delivery_quote.verify_binding'" ); self::assertFalse( $this->dispose() ); self::assertSame( 1, $this->binding->revision() );
	}
	public function test_terminal_bind_no_effect_contradicted_by_physical_binding_denies(): void {
		$this->arrange(); $q = $this->original->quote; $raw = QuoteStorageFixtures::binding( $q )->row(); $names = QuoteDurableCommand::binding_namespaces( $q->header()->owner(), $q->header(), $this->placement, true ); $raw = array_replace( $raw, [ 'placement_uuid' => $this->placement, 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'] ] ); $b = QuoteBinding::from_row( $raw, $q );
		$columns = array_keys( $b->row() ); $insert = $this->f->pdo->prepare( 'INSERT INTO durable_delivery_engine_delivery_quote_bindings(' . implode( ',', $columns ) . ') VALUES(' . implode( ',', array_fill( 0, count( $columns ), '?' ) ) . ')' ); $insert->execute( array_values( $b->row() ) ); self::assertFalse( $this->dispose() );
	}
	private function pending_namespace( string $phase, int $version, string $namespace ): void {
		$record = $this->f->pdo->query( "SELECT * FROM durable_delivery_engine_operation_records WHERE state='rejected' LIMIT 1" )->fetch( \PDO::FETCH_ASSOC ); unset( $record['id'] );
		$record = array_replace( $record, [ 'operation' => 'delivery_quote.' . $phase, 'operation_version' => $version, 'namespace_hash' => $namespace, 'state' => 'pending', 'completion_json' => null, 'completed_at' => null, 'audit_id' => null, 'publication_state' => 'none' ] );
		$insert = $this->f->pdo->prepare( 'INSERT INTO durable_delivery_engine_operation_records(' . implode( ',', array_keys( $record ) ) . ') VALUES(' . implode( ',', array_fill( 0, count( $record ), '?' ) ) . ')' ); $insert->execute( array_values( $record ) );
	}
	#[DataProvider( 'phases' )]
	public function test_even_pending_original_final_namespace_prevents_disposition( string $phase ): void {
		$this->arrange( $phase ); $namespace = QuoteDurableCommand::binding_namespaces( $this->original->quote->header()->owner(), $this->original->quote->header(), $this->placement, true )['seal']; $this->pending_namespace( 'seal', 2, $namespace ); self::assertFalse( $this->dispose() );
	}
	public function test_pending_verification_prevents_bind_no_effect_disposition(): void {
		$this->arrange(); $namespace = QuoteDurableCommand::verification_namespace( $this->original->quote->header()->owner(), $this->original->quote->header(), $this->placement ); $this->pending_namespace( 'verify_binding', 1, $namespace ); self::assertFalse( $this->dispose() );
	}
	public static function read_faults(): array { $out = []; foreach ( [ 'bind', 'verify_binding' ] as $phase ) { foreach ( [ 'receipt_read', 'native_read', 'retire_ack' ] as $fault ) { $out[$phase . ':' . $fault] = [ $phase, $fault ]; } } return $out; }
	#[DataProvider( 'read_faults' )]
	public function test_unknown_read_or_retirement_ack_never_creates_replacement_owner( string $phase, string $fault ): void {
		$this->arrange( $phase ); $history = $this->history(); $probe = new EarlyPlacementDispositionProbe( $this->f, $fault );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		$helper = new QuotePlacementNoEffectDisposition( $probe, $ready, fn(): bool => $this->f->authorized );
		self::assertFalse( $helper->known( $this->replacement, $this->original->quote, 100, $this->placement, $this->binding, $this->native ) ); self::assertSame( 1, $probe->opens ); self::assertSame( $history, $this->history() ); self::assertTrue( $this->f->sessions[array_key_last( $this->f->sessions )]->is_retired() );
	}
	#[DataProvider( 'phases' )]
	public function test_original_terminal_namespace_replay_remains_rejected_and_cannot_reissue( string $phase ): void {
		$this->arrange( $phase ); self::assertTrue( $this->dispose() ); $q = $this->original->quote; $same = new QuotePlacementEvidence( $q, $this->original->command->reference(), $q->header(), $q->context(), new QuoteFixtureEvidence( $this->f ), fn(): bool => true, QuoteTime::parse( $this->f->utc ) );
		$history = $this->history(); $service = new QuotePlacementService( $this->f->service(), $same );
		if ( 'bind' === $phase ) { $replayed = $service->prepare( 100, QuoteStorageFixtures::binding( $q )->mapping(), RequestContext::create() ); }
		else {
			$input = $service->verified_binding( $this->binding, QuoteFixtures::digest( 'saved_snapshot' ), QuoteFixtures::digest( 'saved_context' ), QuoteTime::parse( '2026-10-07 05:00:04.000000' ) );
			// Table inventory is prewarmed before receipt replay; its contents are inert. Target verification must not run.
			$saved = new class implements QuotePlacementSavedEvidenceGuard { public function tables( OperationSession $session ): array { return [ 'durable_original_native' ]; } public function verify( OperationSession $session, QuoteBinding $binding ): bool { throw new \LogicException( 'Immutable refusal cannot enter saved target.' ); } };
			$replayed = $service->verify( $input, $saved, RequestContext::create() );
		}
		self::assertSame( $history, $this->history() ); self::assertSame( 'rejected', $replayed->attempt->outcome->state ); self::assertTrue( $replayed->attempt->replayed, 'Ordinary original retry: ' . ( $replayed->attempt->outcome->error?->code ?? 'none' ) ); self::assertNull( $replayed->binding );
	}
	public function test_legacy_internal_bind_refusal_remains_retryable_after_control_resume(): void {
		[ $original ] = $this->reviewed( 'legacy_retry' ); $q = $original->quote; $raw = QuoteStorageFixtures::binding( $q )->row(); $names = QuoteDurableCommand::binding_namespaces( $q->header()->owner(), $q->header(), $raw['placement_uuid'] ); $binding = QuoteBinding::from_row( array_replace( $raw, [ 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'] ] ), $q );
		$this->f->paused = true; $denied = $this->f->service()->bind( $q->header()->owner(), $original->command->reference(), $q->header(), $binding, RequestContext::create(), $q->context() ); self::assertSame( 'rejected', $denied->attempt->outcome->state );
		$this->f->paused = false; $this->tick(); $retry = $this->f->service()->bind( $q->header()->owner(), $original->command->reference(), $q->header(), $binding, RequestContext::create(), $q->context() ); self::assertSame( 'accepted', $retry->attempt->outcome->state ); self::assertSame( 1, $retry->binding->revision() ); self::assertSame( 1, $this->f->count( 'delivery_quote_bindings' ) ); self::assertSame( 3, $this->f->count( 'operation_changes' ) );
	}
	#[DataProvider( 'phases' )]
	public function test_already_started_refusal_recorder_replays_first_terminal_completion_without_rewriting_it( string $phase ): void {
		$this->arrange( $phase ); $command = $this->rejected_command; $history = $this->history();
		$profile = new QuoteOperationProfile( $command->identity->operation, $command, fn(): bool => $this->f->authorized, control: new QuoteFixtureControl( $this->f ) );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void {} };
		$coordinator = new OperationCoordinator( new OperationProfileRegistry( [ $profile ] ), $this->f, $ready );
		// An in-flight attempt may have refused before a competing recorder publishes the first refusal.
		// Enter that actual recorder with a different later error after the exact original terminal row exists.
		$recorder = new \ReflectionMethod( $coordinator, 'record_rejection' ); $opens = $this->f->opens;
		$result = $recorder->invoke( $coordinator, $profile, $command->identity, $command->intent(), RequestContext::create(), new ContractError( 'invalid_input', RequestContext::create(), 'reload_and_submit' ) );
		self::assertSame( 'rejected', $result->outcome->state ); self::assertSame( 'temporarily_unavailable', $result->outcome->error->code ); self::assertTrue( $result->replayed ); self::assertSame( $opens + 1, $this->f->opens ); self::assertSame( $history, $this->history() );
	}
}

final class EarlyPlacementNativeFence implements QuotePlacementNoEffectEvidenceGuard {
	public bool $raw_ok = true;
	public int $reads = 0;
	public ?array $expected_binding = null;
	public function site_id(): int { return 1; }
	public function order_id(): int { return 100; }
	public function tables( OperationSession $session ): array { return [ 'durable_original_native' ]; }
	public function unchanged(): bool { return $this->raw_ok; }
	public function verify( OperationSession $session, QuoteStoredRow $original, ?QuoteBinding $binding ): bool {
		++$this->reads; if ( ! $session->in_transaction() || $session->is_retired() || ! $this->raw_ok || $original->site_id() !== $this->site_id() || $binding?->row() !== $this->expected_binding ) { return false; }
		$row = $session->get_row( 'SELECT physical_bytes FROM durable_original_native WHERE order_id=100 FOR UPDATE' ); return is_array( $row ) && $row['physical_bytes'] === 'original_native_exact';
	}
}
