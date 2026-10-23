<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteSealCommand, FixedPromotionQuoteSealResult, FixedPromotionQuoteSealService, QuoteOperationProfile, QuotePlacementService, QuotePrivatePublication};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding, QuoteId, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationConnectionFactory, OperationMaterialEvent, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteSchema, EmergencyControlStore, OperationStoreSchema, RuleLifecycleSchema, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteVerifyHistoryTest.php';

/** Real C03 and receipt codecs over immutable synthetic rows; physical concurrency is separate. */
final class FixedPromotionQuoteSealHistoryTest extends TestCase {
	private PromotionSealHistoryFactory $factory;
	private FixedPromotionQuoteSealService $service;
	private PromotionSealHistoryPublication $publication;
	private bool $authorized = true;
	private ?\Closure $on_authorize = null;
	protected function setUp(): void {
		$this->factory = new PromotionSealHistoryFactory();
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'tables', 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'tables' ); $eligibility->expects( $this->never() )->method( 'validity_for_requests_at' );
		$current = $this->createMock( OperationReadiness::class ); $current->expects( $this->never() )->method( 'assert_ready' );
		$control = $this->createMock( EmergencyControlStore::class ); $control->expects( $this->never() )->method( 'assert_ready' ); $control->expects( $this->never() )->method( 'current' );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $s ): void { if ( $s instanceof PromotionSealHistorySession ) { $s->hook( 'readiness' ); } } };
		$this->publication = new PromotionSealHistoryPublication();
		$this->service = new FixedPromotionQuoteSealService( $this->factory, $store, $eligibility, function( $owner, string $operation ): bool { if ( 'delivery_quote.seal' !== $operation ) { throw new \LogicException( 'Wrong historical authority.' ); } if ( null !== $this->on_authorize ) { ( $this->on_authorize )(); } return $this->authorized; }, readiness: $ready, control: $control, publication: $this->publication, current_readiness: $current );
	}
	public function test_exact_sealed_binding_replays_after_expiry_without_current_order_source_saved_or_funding(): void {
		$before = $this->bytes(); $result = $this->read();
		self::assertSame( 'accepted', $result->attempt->outcome->state ); self::assertTrue( $result->attempt->replayed ); self::assertSame( $this->factory->quote->row(), $result->quote->row() ); self::assertSame( $this->factory->binding->row(), $result->binding->row() );
		self::assertSame( Q::time()->plus_seconds( 3 )->sql(), $result->binding->row()['created_at'] ); self::assertSame( 'sealed', $result->binding->state() ); self::assertSame( 3, $result->binding->revision() ); self::assertSame( Q::time()->plus_seconds( 4 )->sql(), $result->binding->row()['verified_at'] ); self::assertSame( Q::time()->plus_seconds( 5 )->sql(), $result->binding->row()['sealed_at'] ); self::assertNull( $result->command->verification()->binding_command()->acceptance()->issue()->lease() ); self::assertSame( $before, $this->bytes() ); self::assertCount( 2, $this->factory->sessions );
		foreach ( $this->factory->sessions as $s ) { self::assertTrue( $s->is_retired() ); self::assertFalse( $s->in_transaction() ); self::assertSame( 0, $s->writes ); self::assertSame( 0, $s->clock_reads ); }
	}
	public function test_pending_advisory_publication_preserves_only_acknowledged_sealed_history(): void {
		$this->factory->records[5]['publication_state'] = 'pending'; $before = $this->bytes(); $result = $this->read();
		self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertTrue( $result->attempt->outcome->mutation_accepted ); self::assertTrue( $result->attempt->outcome->publication_pending ); self::assertSame( 'accepted', $result->attempt->completion->state ); self::assertNotNull( $result->quote ); self::assertNotNull( $result->binding ); self::assertSame( $before, $this->bytes() ); self::assertSame( 1, $this->publication->calls );
	}
	public static function owner_losses(): array { return [ [ 'readiness', 'prefix' ], [ 'begin', 'site' ], [ 'validation', 'charset' ], [ 'receipt', 'prefix' ], [ 'quote', 'site' ], [ 'binding', 'charset' ], [ 'rollback', 'prefix' ], [ 'retire', 'site' ] ]; }
	#[DataProvider( 'owner_losses' )]
	public function test_persistent_owner_drift_at_every_history_phase_withholds_all_facts( string $phase, string $field ): void {
		$reached = false; $this->factory->on_ack = static function( PromotionSealHistorySession $s, string $point ) use ( $phase, $field, &$reached ): void { if ( $phase !== $point ) { return; } $reached = true; $s->$field = match( $field ) { 'site' => 2, 'prefix' => 'foreign_', default => 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' }; };
		$before = $this->bytes(); $this->assert_unknown( $this->read() ); self::assertTrue( $reached ); self::assertSame( $before, $this->bytes() ); self::assertTrue( $this->factory->sessions[1]->is_retired() );
	}
	public static function read_failures(): array { return [ [ 'begin' ], [ 'quote' ], [ 'binding' ], [ 'rollback' ], [ 'retire' ] ]; }
	#[DataProvider( 'read_failures' )]
	public function test_failed_acknowledgment_or_cleanup_is_genuinely_unknown( string $phase ): void {
		$this->factory->on_ack = static function( PromotionSealHistorySession $s, string $point ) use ( $phase ): void { if ( $phase === $point ) { $s->fail = $phase; } }; $before = $this->bytes(); $result = $this->read(); $this->assert_unknown( $result ); self::assertNotNull( $result->command ); self::assertSame( $before, $this->bytes() );
	}
	public static function metadata_failures(): array { return [ [ 'is_retired' ], [ 'in_transaction' ] ]; }
	#[DataProvider( 'metadata_failures' )]
	public function test_throwing_owner_metadata_never_suppresses_physical_cleanup( string $metadata ): void {
		$this->factory->on_ack = static function( PromotionSealHistorySession $s, string $phase ) use ( $metadata ): void { if ( 'receipt' === $phase ) { $s->fail = 'throw:' . $metadata; } };
		$this->assert_unknown( $this->read() ); $s = $this->factory->sessions[1]; $s->fail = ''; self::assertTrue( $s->is_retired() ); self::assertFalse( $s->in_transaction() ); self::assertGreaterThanOrEqual( 1, $s->rollback_calls ); self::assertGreaterThanOrEqual( 1, $s->retire_calls );
	}
	public function test_authorization_lost_after_retirement_withholds_recovery_and_both_facts(): void {
		$this->factory->on_ack = function( PromotionSealHistorySession $s, string $point ): void { if ( 'retire' === $point ) { $this->authorized = false; } }; $result = $this->read(); $this->assert_unknown( $result ); self::assertNull( $result->command );
	}
	public function test_success_has_no_authorizer_callback_after_the_final_retired_owner_check(): void {
		$retired_authorizations = 0;
		$this->on_authorize = function() use ( &$retired_authorizations ): void { $s = $this->factory->sessions[1] ?? null; if ( null !== $s && $s->is_retired() && ++$retired_authorizations > 1 ) { $s->charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'; } };
		$result = $this->read(); self::assertSame( 'accepted', $result->attempt->outcome->state ); self::assertSame( 1, $retired_authorizations ); self::assertSame( 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->factory->sessions[1]->charset );
	}
	public static function final_drift(): array { return [ [ 'site' ], [ 'prefix' ], [ 'charset' ] ]; }
	#[DataProvider( 'final_drift' )]
	public function test_true_final_authorizer_cannot_replace_the_retired_pinned_owner( string $field ): void {
		$this->on_authorize = function() use ( $field ): void { $s = $this->factory->sessions[1] ?? null; if ( null !== $s && $s->is_retired() ) { $s->$field = match( $field ) { 'site' => 2, 'prefix' => 'foreign_', default => 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' }; } };
		$this->assert_unknown( $this->read() );
	}
	public static function corruptions(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'missing issue', 'missing accept', 'missing bind', 'missing verify', 'missing seal', 'issue intent', 'accept intent', 'bind intent', 'verify intent', 'seal intent', 'accept time', 'binding time', 'binding order', 'missing binding', 'early event', 'early bind event', 'early seal event', 'snapshot digest', 'context digest', 'verified time', 'binding id', 'prepared revision', 'sealed time', 'control revision' ] ); }
	#[DataProvider( 'corruptions' )]
	public function test_changed_or_missing_acknowledgment_linkage_cannot_return_accepted_facts( string $kind ): void {
		$done = false; $this->factory->on_ack = function( PromotionSealHistorySession $s, string $phase ) use ( $kind, &$done ): void {
			if ( $done || 'receipt' !== $phase ) { return; } $done = true;
			if ( str_starts_with( $kind, 'missing ' ) && 'missing binding' !== $kind ) { $id = match( $kind ) { 'missing issue' => 1, 'missing accept' => 2, 'missing bind' => 3, 'missing verify' => 4, default => 5 }; $this->factory->records[$id] = null; }
			elseif ( str_ends_with( $kind, 'intent' ) ) { $id = match( $kind ) { 'issue intent' => 1, 'accept intent' => 2, 'bind intent' => 3, 'verify intent' => 4, default => 5 }; $this->factory->records[$id]['intent_hash'] = Q::digest( 'synthetic different history' ); }
			elseif ( 'accept time' === $kind ) { $at = Q::time()->plus_seconds( 4 )->sql(); $this->factory->quote = QuoteStoredRow::from_row( array_replace( $this->factory->quote->row(), [ 'accepted_at' => $at, 'transition_at' => $at ] ) ); }
			elseif ( 'binding time' === $kind || 'binding order' === $kind ) { $changes = 'binding time' === $kind ? [ 'created_at' => Q::time()->plus_seconds( 4 )->sql() ] : [ 'order_id' => 678 ]; $this->factory->binding = QuoteBinding::from_row( array_replace( $this->factory->binding->row(), $changes ), $this->factory->quote ); }
			elseif ( 'control revision' === $kind ) { $profile = new QuoteOperationProfile( 'delivery_quote.seal', profile_version: 2 ); $completion = OperationCompletion::from_json( $this->factory->records[5]['completion_json'], $profile ); $this->factory->records[5]['completion_json'] = OperationCompletion::accepted( $profile, array_replace( $completion->result, [ 'control_revision' => 2 ] ), $completion->publication )->to_json(); }
			elseif ( in_array( $kind, [ 'early event', 'early bind event', 'early seal event' ], true ) ) { $this->factory->events[match( $kind ) { 'early event' => 4, 'early bind event' => 3, default => 5 }]['created_at'] = Q::time()->plus_seconds( 2 )->sql(); }
			elseif ( in_array( $kind, [ 'snapshot digest', 'context digest', 'verified time', 'binding id', 'prepared revision', 'sealed time' ], true ) ) { $changes = match( $kind ) { 'snapshot digest' => [ 'snapshot_digest' => Q::digest( 'different saved snapshot' ) ], 'context digest' => [ 'context_digest' => Q::digest( 'different saved context' ) ], 'verified time' => [ 'verified_at' => Q::time()->plus_seconds( 5 )->sql() ], 'binding id' => [ 'id' => 8 ], 'sealed time' => [ 'sealed_at' => Q::time()->plus_seconds( 6 )->sql() ], default => [ 'state' => 'prepared', 'revision' => 2, 'sealed_at' => null ] }; $this->factory->binding = QuoteBinding::from_row( array_replace( $this->factory->binding->row(), $changes ), $this->factory->quote ); }
			else { $this->factory->binding = null; }
		}; $result = $this->read(); $this->assert_unknown( $result );
	}
	private function read(): FixedPromotionQuoteSealResult { return $this->service->reconcile( $this->factory->command, RequestContext::create() ); }
	private function bytes(): array { return [ $this->factory->quote->row(), $this->factory->binding?->row(), $this->factory->records, $this->factory->events ]; }
	private function assert_unknown( FixedPromotionQuoteSealResult $r ): void { self::assertSame( 'unconfirmed', $r->attempt->outcome->state ); self::assertNull( $r->attempt->completion ); self::assertNull( $r->quote ); self::assertNull( $r->binding ); self::assertSame( 'outcome_unknown', $r->attempt->outcome->error->code ); foreach ( $this->factory->sessions as $s ) { self::assertSame( 0, $s->writes ); self::assertSame( 0, $s->clock_reads ); } }
}

final class PromotionSealHistoryPublication implements QuotePrivatePublication {
	public int $calls = 0;
	public function invalidate( int $site_id, string $owner_digest, QuoteId $quote_id ): bool { ++$this->calls; return false; }
}

/** Complete original issue/accept/bind/verify receipts plus one seal-v2 receipt; no source/order/saved capability is recovered. */
final class PromotionSealHistoryFactory implements OperationConnectionFactory {
	public FixedPromotionQuoteSealCommand $command;
	public QuoteStoredRow $quote;
	public ?QuoteBinding $binding;
	public array $records;
	public array $events;
	public array $sessions = [];
	public ?\Closure $on_ack = null;
	public function __construct() {
		$base = new PromotionVerifyHistoryFactory(); $this->quote = $base->quote; $this->records = $base->records; $this->events = $base->events;
		$this->command = FixedPromotionQuoteSealCommand::create( $base->command, $base->binding, 1 );
		$c = $this->command; $identity = $c->identity; $this->binding = QuoteBinding::from_row( array_replace( $c->binding()->row(), [ 'sealed_at' => Q::time()->plus_seconds( 5 )->sql() ] ), $this->quote );
		$profile = new QuoteOperationProfile( 'delivery_quote.seal', profile_version: 2 ); $row = $this->binding->row();
		$result = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 2, 'state' => 'sealed', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => Q::time()->plus_seconds( 5 )->epoch_microseconds(), 'control_revision' => 1, 'binding_id' => 7, 'placement_id' => $row['placement_uuid'], 'order_id' => $row['order_id'], 'binding_revision' => 3, 'manifest_digest' => $row['managed_group_manifest_digest'], 'snapshot_digest' => $row['snapshot_digest'], 'context_digest' => $row['context_digest'], 'native_money_digest' => $row['native_money_digest'] ];
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => 1, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] );
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], [ 'quote_id' => $result['quote_id'], 'body_digest' => $result['body_digest'], 'owner_digest' => $result['owner_digest'], 'namespace_hash' => $result['namespace_hash'], 'quote_revision' => 2, 'binding_revision' => 3 ], 'quote_sealed', 2, 3, [ 'binding' ] );
		$this->records[5] = [ 'id' => 5, 'site_id' => 1, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $c->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.seal', 'operation_version' => 2, 'target_hash' => WpdbOperationRecordRepository::target_hash( $identity ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => 5, 'row_version' => 3, 'created_at' => Q::time()->sql(), 'updated_at' => Q::time()->plus_seconds( 1000 )->sql(), 'completed_at' => Q::time()->plus_seconds( 1000 )->sql() ];
		$this->events[5] = [ 'id' => 5, 'site_id' => 1, 'operation_id' => 5, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => Q::time()->plus_seconds( 1000 )->sql() ];
	}
	public function open(): OperationSession { $s = new PromotionSealHistorySession( $this, [] !== $this->sessions ); $this->sessions[] = $s; return $s; }
}
final class PromotionSealHistorySession implements OperationSession {
	public string $prefix = 'wp_';
	public int $site = 1;
	public string $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	public int $writes = 0;
	public int $clock_reads = 0;
	public int $rollback_calls = 0; public int $retire_calls = 0;
	public string $fail = '';
	private bool $transaction = false;
	private bool $retired = false;
	public function __construct( private PromotionSealHistoryFactory $f, private bool $ack ) {}
	public function hook( string $phase ): void { if ( $this->ack && null !== $this->f->on_ack ) { ( $this->f->on_ack )( $this, $phase ); } }
	public function site_id(): int { return $this->site; }
	public function table_prefix(): string { return $this->prefix; }
	public function charset_collate(): string { return $this->charset; }
	public function begin(): bool { $this->hook( 'begin' ); if ( 'begin' === $this->fail ) { return false; } $this->transaction = true; return true; }
	public function commit(): OperationCommitResult { throw new \LogicException( 'Historical acknowledgment cannot commit.' ); }
	public function rollback(): bool { ++$this->rollback_calls; $this->transaction = false; $this->hook( 'rollback' ); return 'rollback' !== $this->fail; }
	public function retire(): bool { ++$this->retire_calls; $this->transaction = false; $this->retired = true; $this->hook( 'retire' ); return 'retire' !== $this->fail; }
	public function is_retired(): bool { if ( 'throw:is_retired' === $this->fail ) { throw new \RuntimeException( 'Synthetic failed retirement metadata.' ); } return $this->retired; }
	public function in_transaction(): bool { if ( 'throw:in_transaction' === $this->fail ) { throw new \RuntimeException( 'Synthetic failed transaction metadata.' ); } return $this->transaction; }
	public function validate_tables( array $tables ): bool { $this->hook( 'validation' ); return true; }
	public function query( string $sql ): int|false { ++$this->writes; throw new \LogicException( 'History cannot mutate budget, quote or receipt.' ); }
	public function prepare( string $sql, mixed ...$args ): string { $index = 0; return preg_replace_callback( '/%[ds]/', static function( array $match ) use ( &$index, $args ): string { $value = $args[$index++]; return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'"; }, $sql ); }
	public function errno(): int { return 0; }
	public function insert_id(): int { return 0; }
	public function get_row( string $sql ): array|null|false {
		if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { return [ 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ]; }
		if ( str_contains( $sql, SchemaVersion::OPTION_NAME ) ) { return [ 'option_value' => '12' ]; }
		if ( str_contains( $sql, MigrationStatus::OPTION_NAME ) ) { return [ 'option_value' => serialize( [ 'status' => 'success', 'to_version' => '12', 'migration_id' => 'history_test_schema12' ] ) ]; }
		if ( str_contains( $sql, 'UTC_TIMESTAMP' ) ) { ++$this->clock_reads; return [ 'now_utc' => Q::time()->plus_seconds( 86400 )->sql() ]; }
		if ( str_contains( $sql, 'operation_records' ) ) { return str_starts_with( $sql, 'SELECT state, audit_id' ) ? [ 'state' => 'accepted', 'audit_id' => 5 ] : $this->f->records[5]; }
		if ( str_contains( $sql, 'operation_changes' ) ) { return $this->f->events[5]; }
		throw new \LogicException( 'Unexpected acceptance history row read.' );
	}
	public function get_results( string $sql ): array|false {
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) || str_starts_with( $sql, 'SHOW INDEX' ) ) { return $this->metadata( $sql ); }
		if ( str_contains( $sql, 'operation_records' ) ) { $this->hook( 'receipt' ); foreach ( $this->f->records as $record ) { if ( null !== $record && str_contains( $sql, $record['namespace_hash'] ) ) { return [ $record + [ 'oversized' => 0 ] ]; } } return []; }
		if ( str_contains( $sql, 'operation_changes' ) ) { foreach ( $this->f->events as $event ) { if ( 1 === preg_match( '/operation_id\s*=\s*' . $event['operation_id'] . '\b/', $sql ) ) { return [ $event + [ 'oversized' => 0 ] ]; } } return []; }
		if ( str_contains( $sql, 'delivery_quote_bindings' ) ) { $this->hook( 'binding' ); return 'binding' === $this->fail ? false : ( null === $this->f->binding ? [] : [ $this->f->binding->row() + [ 'payload_oversized' => 0 ] ] ); }
		if ( str_contains( $sql, 'delivery_quotes' ) ) { $this->hook( 'quote' ); return 'quote' === $this->fail ? false : [ $this->f->quote->row() + [ 'payload_oversized' => 0 ] ]; }
		throw new \LogicException( 'Unexpected acceptance history collection read.' );
	}
	private function metadata( string $sql ): array {
		preg_match( '/wp_delivery_engine_([a-z_]+)`/', $sql, $match ); $suffix = $match[1] ?? '';
		if ( in_array( $suffix, DeliveryQuoteSchema::SUFFIXES, true ) ) { $columns = DeliveryQuoteSchema::columns( $suffix ); $indexes = DeliveryQuoteSchema::indexes( $suffix ); }
		elseif ( in_array( $suffix, RuleLifecycleSchema::SUFFIXES, true ) ) { $columns = RuleLifecycleSchema::columns( $suffix ); $indexes = RuleLifecycleSchema::indexes( $suffix ); }
		elseif ( in_array( $suffix, OperationStoreSchema::SUFFIXES, true ) ) { $columns = OperationStoreSchema::columns( $suffix ); $indexes = OperationStoreSchema::indexes( $suffix ); }
		else { $columns = DeliveryQuoteSchema::rate_columns(); $indexes = [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ], DeliveryQuoteSchema::RATE_INDEX => DeliveryQuoteSchema::rate_index() ]; }
		$out = [];
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) ) { foreach ( $columns as $field => [ $type, $nullable, $default, $extra, $collation ] ) { $out[] = [ 'Field' => $field, 'Type' => $type, 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ]; } }
		else { foreach ( $indexes as $name => $definition ) { foreach ( $definition['columns'] as $position => $field ) { $out[] = [ 'Key_name' => $name, 'Seq_in_index' => $position + 1, 'Non_unique' => $definition['unique'] ? 0 : 1, 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $field ]; } } }
		return $out;
	}
}
