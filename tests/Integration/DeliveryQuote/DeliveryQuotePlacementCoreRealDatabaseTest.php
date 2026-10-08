<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Integration\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{QuoteAdmissionAttempt,QuoteDurableCommand,QuoteDurableResult,QuotePlacementProof,QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Application\EmergencyControl\EmergencyCheckoutLocalBinding;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteTime};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures,QuoteLifecycleProofFactory as Factory,QuoteLifecycleProofStack as Stack,QuoteLifecycleProofTransport as Transport,QuoteStorageFixtures,QuoteStorageProofWpdb};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Physical MariaDB/C03 proofs. Native Woo gateway coverage is a separate adopter suite. */
#[Group( 'quote-lifecycle-real-db' )]
final class DeliveryQuotePlacementCoreRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $stacks = [];
	private array $globals;
	private QuoteDurableResult $original;
	protected function setUp(): void {
		$this->globals = []; foreach ( [ 'wpdb', 'blog_id' ] as $key ) { $this->globals[$key] = [ array_key_exists( $key, $GLOBALS ), $GLOBALS[$key] ?? null ]; }
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix );
		DB::execute( $this->database, "CREATE TABLE `{$this->prefix}placement_saved_facts` (order_id bigint unsigned NOT NULL PRIMARY KEY,snapshot_digest char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,context_digest char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
		DB::execute( $this->database, "INSERT INTO `{$this->prefix}placement_saved_facts` VALUES(100,'" . QuoteFixtures::digest( 'saved_snapshot' ) . "','" . QuoteFixtures::digest( 'saved_context' ) . "')" );
		$GLOBALS['wpdb'] = new QuoteStorageProofWpdb( $this->database, $this->prefix ); $GLOBALS['blog_id'] = 1;
	}
	protected function tearDown(): void {
		foreach ( $this->stacks as $s ) { $s->factory->close_all(); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
		foreach ( $this->globals as $key => [ $existed, $value ] ) { if ( $existed ) { $GLOBALS[$key] = $value; } else { unset( $GLOBALS[$key] ); } }
	}
	private function stack( int $clock = Factory::NOW, ?callable $configure = null ): Stack { $s = new Stack( $this->prefix, $clock, $configure ); $this->stacks[] = $s; return $s; }
	private function count_rows( string $suffix ): int { return (int) DB::scalar( $this->database, "SELECT COUNT(*) FROM `{$this->prefix}delivery_engine_{$suffix}`" ); }
	private function binding_row(): array { return DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_delivery_quote_bindings` LIMIT 1" ); }
	private function saved(): QuotePlacementSavedEvidenceGuard { return new class( $this->prefix ) implements QuotePlacementSavedEvidenceGuard {
		public function __construct( private string $prefix ) {}
		public function tables( OperationSession $session ): array { return [ $this->prefix . 'placement_saved_facts' ]; }
		public function verify( OperationSession $session, QuoteBinding $binding ): bool {
			if ( ! $session->in_transaction() || $session->is_retired() || $session->table_prefix() !== $this->prefix ) { return false; }
			$r = $session->get_row( $session->prepare( "SELECT snapshot_digest,context_digest FROM `{$this->prefix}placement_saved_facts` WHERE order_id=%d FOR UPDATE", $binding->row()['order_id'] ) );
			return is_array( $r ) && $r['snapshot_digest'] === $binding->row()['snapshot_digest'] && $r['context_digest'] === $binding->row()['context_digest'];
		}
	}; }
	private function prepare(): QuoteDurableResult {
		$s = $this->stack(); $this->original = $s->service->issue( Stack::command( 'placement_core' ), QuoteAdmissionAttempt::generate(), RequestContext::create() ); self::assertSame( 'accepted', $this->original->attempt->outcome->state ); $q = $this->original->quote;
		$a = $s->service->accept( $q->header()->owner(), $this->original->command->reference(), $q->header(), $q->context(), RequestContext::create() ); self::assertSame( 'accepted', $a->attempt->outcome->state ); $q = $a->quote;
		$row = QuoteStorageFixtures::binding( $q )->row(); $names = QuoteDurableCommand::binding_namespaces( $q->header()->owner(), $q->header(), $row['placement_uuid'], true );
		$b = QuoteBinding::from_row( array_replace( $row, [ 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'] ] ), $q );
		$r = $s->service->bind( $q->header()->owner(), $this->original->command->reference(), $q->header(), $b, RequestContext::create(), $q->context() ); self::assertSame( 'accepted', $r->attempt->outcome->state ); return $r;
	}
	private function input( QuoteDurableResult $p ): QuoteBinding { return QuoteBinding::from_row( array_replace( $p->binding->row(), [ 'revision' => 2, 'snapshot_digest' => QuoteFixtures::digest( 'saved_snapshot' ), 'context_digest' => QuoteFixtures::digest( 'saved_context' ), 'verified_at' => QuoteFixtures::time()->sql() ] ), $p->quote ); }
	private function verify( QuoteDurableResult $p, ?Stack $s = null ): QuoteDurableResult { $q = $p->quote; return ( $s ?? $this->stack() )->service->verify_binding( $q->header()->owner(), $this->original->command->reference(), $q->header(), $this->input( $p ), RequestContext::create(), $q->context(), $this->saved() ); }
	private function proof( QuoteBinding $binding ): QuotePlacementProof { $local = EmergencyCheckoutLocalBinding::capture( new \WC_Order( [ 'id' => 100 ] ) ); self::assertNotNull( $local ); return QuotePlacementProof::capture( $binding, 1, $local, $this->saved() ); }
	private function seal( QuoteDurableResult $v, Stack $s ): QuoteDurableResult { $q = $v->quote; return $s->service->seal_placement( $q->header()->owner(), $this->original->command->reference(), $q->header(), $v->binding, RequestContext::create(), $q->context(), $this->proof( $v->binding ) ); }
	private function disposition( QuoteDurableResult $verified, ?Stack $stack = null ): bool { $q = $verified->quote; return ( $stack ?? $this->stack() )->service->known_rejected_placement( $q->header()->owner(), $this->original->command->reference(), $q->header(), $verified->binding, $this->saved() ); }

	public function test_physical_prepared_two_is_separate_durable_receipt_and_exact_final_three(): void {
		$p = $this->prepare(); $v = $this->verify( $p ); self::assertSame( 'accepted', $v->attempt->outcome->state ); self::assertSame( 2, $v->binding->revision() ); self::assertSame( 'prepared', $this->binding_row()['state'] ); self::assertNull( $this->binding_row()['sealed_at'] ); self::assertSame( 4, $this->count_rows( 'operation_changes' ) );
		// A well-formed physical rollback cannot erase an accepted verification.
		$verified_row = $this->binding_row(); DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_delivery_quote_bindings` SET revision=1,snapshot_digest=NULL,context_digest=NULL,verified_at=NULL" );
		self::assertSame( 'unavailable', $this->stack()->service->current( $v->quote->header()->owner(), $this->original->command->reference(), $v->quote->context(), RequestContext::create() )->status );
		$restore = $this->database->prepare( "UPDATE `{$this->prefix}delivery_engine_delivery_quote_bindings` SET revision=2,snapshot_digest=?,context_digest=?,verified_at=?" ); self::assertInstanceOf( \mysqli_stmt::class, $restore ); $restore->bind_param( 'sss', $verified_row['snapshot_digest'], $verified_row['context_digest'], $verified_row['verified_at'] ); self::assertTrue( $restore->execute() ); $restore->close(); self::assertSame( $verified_row, $this->binding_row() );
		$sealed = $this->seal( $v, $this->stack() ); self::assertSame( 'accepted', $sealed->attempt->outcome->state ); self::assertSame( 'sealed', $this->binding_row()['state'] ); self::assertSame( 3, $sealed->binding->revision() ); self::assertSame( 2, $sealed->command->identity->operation_version ); self::assertSame( 5, $this->count_rows( 'operation_changes' ) );
		$before = $this->binding_row(); $replay = $this->stack()->service->reconcile( $sealed->command, RequestContext::create() ); self::assertSame( 'accepted', $replay->attempt->outcome->state ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( $sealed->attempt->completion->to_json(), $replay->attempt->completion->to_json() ); self::assertSame( $before, $this->binding_row() ); self::assertSame( 5, $this->count_rows( 'operation_changes' ) );
		self::assertFalse( $this->disposition( $v ) ); self::assertSame( $before, $this->binding_row() );
	}
	public function test_physical_terminal_rejection_disposition_is_readonly_and_refuses_pending_corrupt_or_changed_history(): void {
		$v = $this->verify( $this->prepare() ); self::assertSame( 'accepted', $v->attempt->outcome->state ); self::assertFalse( $this->disposition( $v ) );
		$denied = $this->seal( $v, $this->stack( Factory::NOW + 300 ) ); self::assertSame( 'rejected', $denied->attempt->outcome->state ); $before = $this->binding_row(); $records = $this->count_rows( 'operation_records' );
		$read = $this->stack( Factory::NOW + 600 ); self::assertTrue( $this->disposition( $v, $read ) ); self::assertSame( $before, $this->binding_row() ); self::assertSame( $records, $this->count_rows( 'operation_records' ) ); self::assertSame( 4, $this->count_rows( 'operation_changes' ) );
		$record = DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_records` WHERE operation='delivery_quote.seal'" );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET state='pending',completion_json=NULL,completed_at=NULL WHERE id=" . $record['id'] ); self::assertFalse( $this->disposition( $v ) );
		$restore = $this->database->prepare( "UPDATE `{$this->prefix}delivery_engine_operation_records` SET state='rejected',completion_json=?,completed_at=? WHERE id=?" ); self::assertInstanceOf( \mysqli_stmt::class, $restore ); $restore->bind_param( 'ssi', $record['completion_json'], $record['completed_at'], $record['id'] ); self::assertTrue( $restore->execute() ); self::assertTrue( $this->disposition( $v ) );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET completion_json='{}' WHERE id=" . $record['id'] ); self::assertFalse( $this->disposition( $v ) ); self::assertTrue( $restore->execute() ); $restore->close();
		DB::execute( $this->database, "UPDATE `{$this->prefix}placement_saved_facts` SET context_digest='" . QuoteFixtures::digest( 'different_original' ) . "' WHERE order_id=100" ); self::assertFalse( $this->disposition( $v ) );
		DB::execute( $this->database, "UPDATE `{$this->prefix}placement_saved_facts` SET context_digest='" . QuoteFixtures::digest( 'saved_context' ) . "' WHERE order_id=100" ); self::assertTrue( $this->disposition( $v ) );
		$event = DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_changes` WHERE operation_id=(SELECT id FROM `{$this->prefix}delivery_engine_operation_records` WHERE operation='delivery_quote.verify_binding')" ); DB::execute( $this->database, "DELETE FROM `{$this->prefix}delivery_engine_operation_changes` WHERE id=" . $event['id'] ); self::assertFalse( $this->disposition( $v ) );
		$restore_event = $this->database->prepare( "INSERT INTO `{$this->prefix}delivery_engine_operation_changes`(id,site_id,operation_id,event_format,event_json,created_at) VALUES(?,?,?,?,?,?)" ); self::assertInstanceOf( \mysqli_stmt::class, $restore_event ); $restore_event->bind_param( 'iiiiss', $event['id'], $event['site_id'], $event['operation_id'], $event['event_format'], $event['event_json'], $event['created_at'] ); self::assertTrue( $restore_event->execute() ); $restore_event->close(); self::assertTrue( $this->disposition( $v ) );
		self::assertSame( $before, $this->binding_row() ); self::assertSame( $record, DB::row( $this->database, "SELECT * FROM `{$this->prefix}delivery_engine_operation_records` WHERE id=" . $record['id'] ) ); self::assertSame( $records, $this->count_rows( 'operation_records' ) ); self::assertSame( 4, $this->count_rows( 'operation_changes' ) );
		foreach ( $read->factory->transports as $transport ) { self::assertSame( 0, $transport->quote_writes ); self::assertSame( 0, $transport->record_writes ); self::assertSame( 0, $transport->audit_appends ); } foreach ( $read->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
	}
	public function test_actual_disposition_read_rollback_ack_loss_refuses_release_without_any_effect_or_replacement(): void {
		$v = $this->verify( $this->prepare() ); self::assertSame( 'accepted', $v->attempt->outcome->state ); self::assertSame( 'rejected', $this->seal( $v, $this->stack( Factory::NOW + 300 ) )->attempt->outcome->state ); $before = $this->binding_row(); $records = $this->count_rows( 'operation_records' );
		$read = $this->stack( configure: static function( Transport $transport ): void { $transport->lose_rollback_ack = true; } );
		self::assertFalse( $this->disposition( $v, $read ) ); self::assertCount( 1, $read->factory->sessions ); self::assertTrue( $read->factory->sessions[0]->is_retired() ); $transport = $read->factory->transports[0]; self::assertFalse( $transport->lose_rollback_ack ); self::assertSame( 1, count( array_filter( $transport->sql, static fn( string $sql ): bool => 'ROLLBACK' === $sql ) ) );
		self::assertSame( 0, $transport->quote_writes ); self::assertSame( 0, $transport->record_writes ); self::assertSame( 0, $transport->audit_appends ); self::assertSame( $before, $this->binding_row() ); self::assertSame( $records, $this->count_rows( 'operation_records' ) ); self::assertSame( 4, $this->count_rows( 'operation_changes' ) ); self::assertTrue( $this->disposition( $v ) );
	}
	public function test_second_connection_saved_mutation_is_seen_by_current_lock_before_verified_two(): void {
		$p = $this->prepare(); $done = false; $prefix = $this->prefix; $db = $this->database;
		$s = $this->stack( configure: static function( Transport $t ) use ( &$done, $prefix, $db ): void { $t->before = static function( string $sql ) use ( &$done, $prefix, $db ): void { if ( ! $done && str_contains( $sql, 'placement_saved_facts' ) && str_contains( $sql, 'FOR UPDATE' ) ) { $done = true; DB::execute( $db, "UPDATE `{$prefix}placement_saved_facts` SET snapshot_digest='" . QuoteFixtures::digest( 'other_connection' ) . "' WHERE order_id=100" ); } }; } );
		$r = $this->verify( $p, $s ); self::assertTrue( $done ); self::assertSame( 'rejected', $r->attempt->outcome->state ); self::assertSame( '1', (string) $this->binding_row()['revision'] ); self::assertSame( 3, $this->count_rows( 'operation_changes' ) );
	}
	public function test_physical_unsent_verified_commit_preserves_prepared_one_and_rolls_back_event(): void {
		$p = $this->prepare(); $before = $this->binding_row(); $s = $this->stack( configure: static function( Transport $t, int $n ): void { if ( 1 === $n ) { $t->fault_commit = 2; $t->commit_fault = 'unsent'; } } );
		$r = $this->verify( $p, $s ); self::assertNotSame( true, $r->attempt->outcome->mutation_accepted ); self::assertSame( $before, $this->binding_row() ); self::assertSame( 3, $this->count_rows( 'operation_changes' ) ); self::assertTrue( $s->factory->sessions[0]->is_retired() );
	}
	public function test_physical_verified_lost_ack_reconciles_only_original_receipt_without_second_saved_effect(): void {
		$p = $this->prepare(); $s = $this->stack( configure: static function( Transport $t, int $n ): void { if ( 1 === $n ) { $t->fault_commit = 2; $t->commit_fault = 'lost_ack'; } } );
		$r = $this->verify( $p, $s ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->binding ); self::assertSame( '2', (string) $this->binding_row()['revision'] ); self::assertTrue( $s->factory->sessions[0]->is_retired() );
		$before = $this->binding_row(); $replay = $this->stack()->service->reconcile( $r->command, RequestContext::create() ); self::assertSame( 'accepted', $replay->attempt->outcome->state ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( $before, $this->binding_row() ); self::assertSame( 4, $this->count_rows( 'operation_changes' ) );
	}
	public function test_physical_final_lost_ack_keeps_sealed_bytes_private_until_original_reconciliation(): void {
		$v = $this->verify( $this->prepare() ); self::assertSame( 'accepted', $v->attempt->outcome->state ); $s = $this->stack( configure: static function( Transport $t, int $n ): void { if ( 1 === $n ) { $t->fault_commit = 2; $t->commit_fault = 'lost_ack'; } } );
		$r = $this->seal( $v, $s ); self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->binding ); self::assertSame( 'sealed', $this->binding_row()['state'] ); self::assertSame( 5, $this->count_rows( 'operation_changes' ) ); self::assertTrue( $s->factory->sessions[0]->is_retired() );
		$before = $this->binding_row(); $replay = $this->stack()->service->reconcile( $r->command, RequestContext::create() ); self::assertSame( 'accepted', $replay->attempt->outcome->state ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( $before, $this->binding_row() ); self::assertSame( 5, $this->count_rows( 'operation_changes' ) );
	}
	public function test_physical_final_at_absolute_expiry_does_not_change_verified_snapshot_history(): void {
		$v = $this->verify( $this->prepare() ); self::assertSame( 'accepted', $v->attempt->outcome->state ); $before = $this->binding_row(); $r = $this->seal( $v, $this->stack( Factory::NOW + 300 ) );
		self::assertSame( 'rejected', $r->attempt->outcome->state ); self::assertSame( 'stale_revision', $r->attempt->outcome->error->code ); self::assertSame( $before, $this->binding_row() ); self::assertSame( 4, $this->count_rows( 'operation_changes' ) );
	}
	public function test_actual_referenced_row_lock_timeout_refuses_verified_effect_within_the_fixed_deadline(): void {
		$p = $this->prepare(); $before = $this->binding_row(); $owner = DB::connect(); $stack = $this->stack();
		try {
			self::assertTrue( $owner->begin_transaction() ); DB::row( $owner, "SELECT order_id FROM `{$this->prefix}placement_saved_facts` WHERE order_id=100 FOR UPDATE" );
			$start = hrtime( true ); $result = $this->verify( $p, $stack ); $elapsed = ( hrtime( true ) - $start ) / 1000000000;
			$timeouts = array_sum( array_map( static fn( Transport $transport ): int => $transport->lock_timeouts, $stack->factory->transports ) );
			self::assertGreaterThanOrEqual( 1, $timeouts ); self::assertGreaterThanOrEqual( 1.5, $elapsed ); self::assertLessThan( 20, $elapsed ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); self::assertNull( $result->binding );
			self::assertSame( $before, $this->binding_row() ); self::assertSame( 3, $this->count_rows( 'operation_changes' ) ); foreach ( $stack->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); }
			fwrite( STDERR, json_encode( [ 'case' => 'q06_saved_row_lock_timeout', 'elapsed_ms' => (int) round( $elapsed * 1000 ), 'native_lock_timeouts' => $timeouts, 'binding_revision' => 1, 'binding_effects' => 0, 'audit_appends' => 0, 'all_owners_retired' => true ], JSON_THROW_ON_ERROR ) . "\n" );
		} finally { $owner->rollback(); $owner->close(); }
	}
	public function test_actual_stalled_native_read_times_out_without_replacement_or_any_verified_effect(): void {
		$p = $this->prepare(); $before = $this->binding_row(); $queries = 0; $errno = 0; $sent = false; $acknowledged = true;
		$stack = $this->stack( configure: static function( Transport $transport ) use ( &$queries, &$errno, &$sent, &$acknowledged ): void {
			$transport->before = static function( string $sql, Transport $transport ) use ( &$queries, &$errno, &$sent, &$acknowledged ): void {
				if ( 0 !== $queries || ! str_contains( $sql, 'placement_saved_facts' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; }
				// Stall the same actual driver handle at the saved-fence seam. SLEEP
				// remains excluded from the production OperationSession SQL grammar.
				++$queries; $response = $transport->native_execute( 'SELECT SLEEP(8) AS delayed_read' ); $errno = $response->errno; $sent = $response->sent; $acknowledged = $response->acknowledged;
			};
		} );
		$start = hrtime( true ); $result = $this->verify( $p, $stack ); $elapsed = ( hrtime( true ) - $start ) / 1000000000;
		self::assertSame( 1, $queries ); self::assertTrue( $sent ); self::assertFalse( $acknowledged ); self::assertContains( $errno, [ 2006, 2013 ] ); self::assertGreaterThanOrEqual( 4, $elapsed ); self::assertLessThan( 7.5, $elapsed ); self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->binding ); self::assertCount( 1, $stack->factory->sessions ); self::assertTrue( $stack->factory->sessions[0]->is_retired() ); self::assertSame( $before, $this->binding_row() ); self::assertSame( 3, $this->count_rows( 'operation_changes' ) );
		fwrite( STDERR, json_encode( [ 'case' => 'q06_stalled_native_read_timeout', 'elapsed_ms' => (int) round( $elapsed * 1000 ), 'native_errno' => $errno, 'stalled_queries' => $queries, 'query_sent' => $sent, 'query_acknowledged' => $acknowledged, 'owner_connections' => count( $stack->factory->sessions ), 'implicit_replacements' => 0, 'binding_revision' => 1, 'binding_effects' => 0, 'audit_appends' => 0, 'all_owners_retired' => true ], JSON_THROW_ON_ERROR ) . "\n" );
	}
}
