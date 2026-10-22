<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptService, QuoteOperationProfile, QuotePrivatePublication};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader, QuoteId, QuoteReference, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationConnectionFactory, OperationMaterialEvent, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteSchema, EmergencyControlStore, OperationStoreSchema, RuleLifecycleSchema, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteIssueHistoryTest.php';

/** Actual C03/receipt/accepted-row codecs; immutable synthetic SQL responses are not physical SQL proof. */
final class FixedPromotionQuoteAcceptHistoryTest extends TestCase {
	private PromotionAcceptHistoryFactory $factory;
	private FixedPromotionQuoteAcceptService $service;
	private PromotionAcceptHistoryPublication $publication;
	private bool $authorized = true;

	protected function setUp(): void {
		$this->factory = new PromotionAcceptHistoryFactory();
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'tables', 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'validity_for_requests_at' ); $eligibility->expects( $this->never() )->method( 'tables' );
		$current = $this->createMock( OperationReadiness::class ); $current->expects( $this->never() )->method( 'assert_ready' );
		$control = $this->createMock( EmergencyControlStore::class ); $control->expects( $this->never() )->method( 'assert_ready' ); $control->expects( $this->never() )->method( 'current' );
		$ready = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void { if ( $session instanceof PromotionAcceptHistorySession ) { $session->hook( 'readiness' ); } } };
		$this->publication = new PromotionAcceptHistoryPublication();
		$this->service = new FixedPromotionQuoteAcceptService( $this->factory, $store, $eligibility, fn(): bool => $this->authorized, readiness: $ready, control: $control, publication: $this->publication, current_readiness: $current );
	}

	public function test_exact_terminal_acceptance_replays_original_time_without_current_source_or_funding(): void {
		$before = [ $this->factory->quote->row(), $this->factory->records, $this->factory->events ];
		$result = $this->service->reconcile( $this->factory->command, RequestContext::create() );
		self::assertSame( 'accepted', $result->attempt->outcome->state ); self::assertTrue( $result->attempt->replayed ); self::assertNotNull( $result->quote );
		self::assertSame( $this->factory->quote->row(), $result->quote->row() ); self::assertSame( Q::time()->plus_seconds( 2 )->sql(), $result->quote->accepted_at()->sql() ); self::assertNull( $result->command->issue()->lease() );
		self::assertSame( $before, [ $this->factory->quote->row(), $this->factory->records, $this->factory->events ] ); self::assertCount( 2, $this->factory->sessions );
		foreach ( $this->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); self::assertFalse( $session->in_transaction() ); self::assertSame( 0, $session->writes ); self::assertSame( 0, $session->clock_reads ); }
	}

	public function test_failed_publication_preserves_acknowledged_acceptance_and_the_conservative_pending_outcome(): void {
		$this->factory->records[2]['publication_state'] = 'pending';
		$before = [ $this->factory->quote->row(), $this->factory->records, $this->factory->events ];
		$result = $this->service->reconcile( $this->factory->command, RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertTrue( $result->attempt->outcome->mutation_accepted ); self::assertTrue( $result->attempt->outcome->publication_pending ); self::assertSame( 'outcome_unknown', $result->attempt->outcome->error->code );
		self::assertSame( 'accepted', $result->attempt->completion->state ); self::assertTrue( $result->attempt->replayed ); self::assertNotNull( $result->quote ); self::assertSame( 'accepted', $result->quote->state() ); self::assertSame( Q::time()->plus_seconds( 2 )->sql(), $result->quote->accepted_at()->sql() );
		self::assertSame( $before, [ $this->factory->quote->row(), $this->factory->records, $this->factory->events ] ); self::assertSame( 'pending', $this->factory->records[2]['publication_state'] ); self::assertSame( 1, $this->publication->calls );
		foreach ( $this->factory->sessions as $session ) { self::assertSame( 0, $session->writes ); self::assertSame( 0, $session->clock_reads ); }
	}

	public static function owner_losses(): array { return [ [ 'readiness', 'prefix' ], [ 'begin', 'site' ], [ 'validation', 'charset' ], [ 'receipt', 'prefix' ], [ 'quote', 'site' ], [ 'rollback', 'charset' ], [ 'retire', 'prefix' ] ]; }
	#[DataProvider( 'owner_losses' )]
	public function test_owner_binding_loss_at_each_history_boundary_withholds_the_accepted_attempt( string $phase, string $binding ): void {
		$this->factory->on_ack = static function( PromotionAcceptHistorySession $session, string $point ) use ( $phase, $binding ): void {
			if ( $phase !== $point ) { return; }
			if ( 'prefix' === $binding ) { $session->prefix = 'foreign_'; } elseif ( 'site' === $binding ) { $session->site = 2; } else { $session->charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'; }
		};
		$result = $this->service->reconcile( $this->factory->command, RequestContext::create() ); $this->assert_unknown( $result ); self::assertNotNull( $result->command ); self::assertCount( 2, $this->factory->sessions );
		self::assertTrue( $this->factory->sessions[1]->is_retired() ); self::assertSame( 0, $this->factory->sessions[1]->writes );
	}

	public static function broken_ack(): array { return [ [ 'begin' ], [ 'quote' ], [ 'rollback' ], [ 'retire' ] ]; }
	#[DataProvider( 'broken_ack' )]
	public function test_failed_history_read_or_cleanup_is_genuinely_unconfirmed( string $phase ): void {
		$this->factory->on_ack = static function( PromotionAcceptHistorySession $session, string $point ) use ( $phase ): void { if ( $phase === $point ) { $session->fail = $phase; } };
		$result = $this->service->reconcile( $this->factory->command, RequestContext::create() ); $this->assert_unknown( $result );
		foreach ( $this->factory->sessions as $session ) { self::assertSame( 0, $session->writes ); }
	}

	public function test_final_authorization_loss_withholds_history_and_private_recovery(): void {
		$this->factory->on_ack = function( PromotionAcceptHistorySession $session, string $phase ): void { if ( 'retire' === $phase ) { $this->authorized = false; } };
		$result = $this->service->reconcile( $this->factory->command, RequestContext::create() ); $this->assert_unknown( $result ); self::assertNull( $result->command );
	}

	public static function broken_history(): array { return [ [ 'missing_issue' ], [ 'missing_accept' ], [ 'issue_intent' ], [ 'accept_intent' ], [ 'accept_time' ], [ 'canonical_reference' ] ]; }
	#[DataProvider( 'broken_history' )]
	public function test_mismatched_acknowledgment_does_not_return_an_accepted_attempt_with_a_missing_quote( string $case ): void {
		$done = false;
		$this->factory->on_ack = function( PromotionAcceptHistorySession $session, string $phase ) use ( $case, &$done ): void {
			if ( 'receipt' !== $phase || $done ) { return; } $done = true;
			if ( 'missing_issue' === $case ) { $this->factory->records[1] = null; }
			elseif ( 'missing_accept' === $case ) { $this->factory->records[2] = null; }
			elseif ( 'issue_intent' === $case ) { $this->factory->records[1]['intent_hash'] = Q::digest( 'other issued allocation' ); }
			elseif ( 'accept_intent' === $case ) { $this->factory->records[2]['intent_hash'] = Q::digest( 'other accepted intent' ); }
			elseif ( 'accept_time' === $case ) { $this->factory->quote = QuoteStoredRow::from_row( array_replace( $this->factory->quote->row(), [ 'accepted_at' => Q::time()->plus_seconds( 3 )->sql(), 'transition_at' => Q::time()->plus_seconds( 3 )->sql() ] ) ); }
			else {
				$c = $this->factory->command; $reference = QuoteReference::generate( $c->header()->id() );
				$header = QuoteHeader::issue( $reference->id(), $c->owner(), $c->context(), $c->terms(), $c->header()->created_at(), $c->header()->namespace_hashes(), $c->header()->profile(), 1, $reference );
				$this->factory->quote = QuoteStoredRow::from_row( array_replace( $this->factory->quote->row(), [ 'header_json' => $header->to_private_json() ] ) );
			}
		};
		$result = $this->service->reconcile( $this->factory->command, RequestContext::create() ); $this->assert_unknown( $result ); self::assertNotNull( $result->command );
		foreach ( $this->factory->sessions as $session ) { self::assertSame( 0, $session->writes ); }
	}
	private function assert_unknown( \CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuoteAcceptResult $result ): void { self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertNull( $result->quote ); self::assertSame( 'outcome_unknown', $result->attempt->outcome->error->code ); }
}

final class PromotionAcceptHistoryPublication implements QuotePrivatePublication {
	public int $calls = 0;
	public function invalidate( int $site_id, string $owner_digest, QuoteId $quote_id ): bool { ++$this->calls; return false; }
}

/** Original issue comes from the retained immutable issued-history fixture, not a recovered live capability. */
final class PromotionAcceptHistoryFactory implements OperationConnectionFactory {
	public FixedPromotionQuoteAcceptCommand $command;
	public QuoteStoredRow $quote;
	public array $records;
	public array $events;
	public array $sessions = [];
	public ?\Closure $on_ack = null;
	public function __construct() {
		$issued = new PromotionIssueHistoryFactory(); $this->command = FixedPromotionQuoteAcceptCommand::from_issue( $issued->recovery ); $c = $this->command; $identity = $c->identity;
		$this->quote = QuoteStoredRow::from_row( array_replace( $issued->quote->row(), [ 'state' => 'accepted', 'revision' => 2, 'accepted_at' => Q::time()->plus_seconds( 2 )->sql(), 'transition_at' => Q::time()->plus_seconds( 2 )->sql() ] ) );
		$profile = new QuoteOperationProfile( 'delivery_quote.accept' );
		$result = [ 'quote_id' => $c->header()->id()->value(), 'quote_revision' => 2, 'state' => 'accepted', 'body_digest' => $c->header()->body_digest(), 'owner_digest' => $c->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => Q::time()->plus_seconds( 2 )->epoch_microseconds(), 'control_revision' => 1 ];
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => 1, 'owner_digest' => $result['owner_digest'], 'quote_id' => $result['quote_id'], 'purpose' => 'private_quote_invalidation' ] );
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], [ 'quote_id' => $result['quote_id'], 'body_digest' => $result['body_digest'], 'owner_digest' => $result['owner_digest'], 'namespace_hash' => $result['namespace_hash'], 'quote_revision' => 2, 'binding_revision' => 0 ], 'quote_accepted', 1, 2, [ 'quote' ] );
		// Persistence can complete after the actual accepted_at effect fence, even after absolute expiry.
		$record = [ 'id' => 2, 'site_id' => 1, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $c->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.accept', 'operation_version' => 1, 'target_hash' => WpdbOperationRecordRepository::target_hash( $identity ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => 2, 'row_version' => 3, 'created_at' => Q::time()->sql(), 'updated_at' => Q::time()->plus_seconds( 1000 )->sql(), 'completed_at' => Q::time()->plus_seconds( 1000 )->sql() ];
		$this->records = [ 1 => $issued->record, 2 => $record ]; $this->events = [ 1 => $issued->event, 2 => [ 'id' => 2, 'site_id' => 1, 'operation_id' => 2, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => Q::time()->plus_seconds( 1000 )->sql() ] ];
	}
	public function open(): OperationSession { $session = new PromotionAcceptHistorySession( $this, [] !== $this->sessions ); $this->sessions[] = $session; return $session; }
}

final class PromotionAcceptHistorySession implements OperationSession {
	public string $prefix = 'wp_';
	public int $site = 1;
	public string $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	public int $writes = 0;
	public int $clock_reads = 0;
	public string $fail = '';
	private bool $transaction = false;
	private bool $retired = false;
	public function __construct( private PromotionAcceptHistoryFactory $f, private bool $ack ) {}
	public function hook( string $phase ): void { if ( $this->ack && null !== $this->f->on_ack ) { ( $this->f->on_ack )( $this, $phase ); } }
	public function site_id(): int { return $this->site; }
	public function table_prefix(): string { return $this->prefix; }
	public function charset_collate(): string { return $this->charset; }
	public function begin(): bool { $this->hook( 'begin' ); if ( 'begin' === $this->fail ) { return false; } $this->transaction = true; return true; }
	public function commit(): OperationCommitResult { throw new \LogicException( 'Historical acknowledgment cannot commit.' ); }
	public function rollback(): bool { $this->transaction = false; $this->hook( 'rollback' ); return 'rollback' !== $this->fail; }
	public function retire(): bool { $this->transaction = false; $this->retired = true; $this->hook( 'retire' ); return 'retire' !== $this->fail; }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->transaction; }
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
		if ( str_contains( $sql, 'operation_records' ) ) { return str_starts_with( $sql, 'SELECT state, audit_id' ) ? [ 'state' => 'accepted', 'audit_id' => 2 ] : $this->f->records[2]; }
		if ( str_contains( $sql, 'operation_changes' ) ) { return $this->f->events[2]; }
		throw new \LogicException( 'Unexpected acceptance history row read.' );
	}
	public function get_results( string $sql ): array|false {
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) || str_starts_with( $sql, 'SHOW INDEX' ) ) { return $this->metadata( $sql ); }
		if ( str_contains( $sql, 'operation_records' ) ) { $this->hook( 'receipt' ); foreach ( $this->f->records as $record ) { if ( null !== $record && str_contains( $sql, $record['namespace_hash'] ) ) { return [ $record + [ 'oversized' => 0 ] ]; } } return []; }
		if ( str_contains( $sql, 'operation_changes' ) ) { foreach ( $this->f->events as $event ) { if ( 1 === preg_match( '/operation_id\s*=\s*' . $event['operation_id'] . '\b/', $sql ) ) { return [ $event + [ 'oversized' => 0 ] ]; } } return []; }
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
