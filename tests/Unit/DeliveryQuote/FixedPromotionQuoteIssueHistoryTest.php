<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueProfile, FixedPromotionQuoteIssueService, QuoteAdmissionAttempt, QuoteAdmissionLease, QuoteIssueCommand, QuoteProviderRegistry};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Core\Versioning\{MigrationStatus, SchemaVersion};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteBudgetSlot, QuoteHeader, QuoteId, QuoteJson, QuoteReference, QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationConnectionFactory, OperationMaterialEvent, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteSchema, EmergencyControlStore, OperationStoreSchema, RuleLifecycleSchema, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteStorageFixtures};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryCommercial/FixedPromotionQuoteFixture.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';

/** Real C03/receipt/row codecs over explicit immutable synthetic SQL read responses. */
final class FixedPromotionQuoteIssueHistoryTest extends TestCase {
	private PromotionIssueHistoryFactory $factory;
	private FixedPromotionQuoteIssueService $service;
	private bool $authorized = true;

	protected function setUp(): void {
		$this->factory = new PromotionIssueHistoryFactory();
		$store = $this->createMock( FixedPromotionBudgetAccountStore::class ); $eligibility = $this->createMock( FixedPromotionEligibilityBudgetGuard::class );
		foreach ( [ 'lock_account', 'lock_reservation', 'lock_reservation_for_account', 'replace_account', 'insert_reservation', 'replace_reservation' ] as $method ) { $store->expects( $this->never() )->method( $method ); }
		$eligibility->expects( $this->never() )->method( 'validity_for_requests_at' );
		$current = $this->createMock( OperationReadiness::class ); $current->expects( $this->never() )->method( 'assert_ready' );
		$control = $this->createMock( EmergencyControlStore::class ); $control->expects( $this->never() )->method( 'assert_ready' ); $control->expects( $this->never() )->method( 'current' );
		$ready = new class implements OperationReadiness {
			public function assert_ready( OperationSession $session ): void { if ( $session instanceof PromotionIssueHistorySession ) { $session->hook( 'readiness' ); } }
		};
		$this->service = new FixedPromotionQuoteIssueService( $this->factory, new QuoteProviderRegistry(), $store, $eligibility, fn(): bool => $this->authorized, static function(): never { throw new \LogicException( 'No recovery allocations.' ); }, readiness: $ready, control: $control, current_readiness: $current );
	}

	public function test_exact_terminal_replay_discloses_only_issued_history_without_current_authority(): void {
		$result = $this->service->reconcile( $this->factory->recovery, RequestContext::create() );
		self::assertSame( 'accepted', $result->attempt->outcome->state ); self::assertTrue( $result->attempt->replayed ); self::assertNotNull( $result->quote ); self::assertNull( $result->command->lease() );
		self::assertSame( $this->factory->quote->row(), $result->quote->row() ); self::assertSame( 2, count( $this->factory->sessions ) );
		foreach ( $this->factory->sessions as $session ) { self::assertTrue( $session->is_retired() ); self::assertFalse( $session->in_transaction() ); self::assertSame( 0, $session->writes ); }
	}

	public static function owner_losses(): array { return array_map( static fn( string $phase ): array => [ $phase ], [ 'readiness', 'begin', 'validation', 'receipt', 'quote', 'rollback', 'retire' ] ); }
	#[DataProvider( 'owner_losses' )]
	public function test_history_owner_rebinding_withholds_quote_and_accepted_attempt( string $phase ): void {
		$this->factory->on_ack = static function( PromotionIssueHistorySession $session, string $point ) use ( $phase ): void { if ( $point === $phase ) { $session->prefix = 'foreign_'; } };
		$result = $this->service->reconcile( $this->factory->recovery, RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertNull( $result->quote ); self::assertNotNull( $result->command ); self::assertCount( 2, $this->factory->sessions );
		self::assertTrue( $this->factory->sessions[1]->is_retired() ); self::assertSame( 0, $this->factory->sessions[1]->writes );
	}

	public function test_final_authorization_revocation_withholds_private_history_and_recovery(): void {
		$this->factory->on_ack = function( PromotionIssueHistorySession $session, string $point ): void { if ( 'retire' === $point ) { $this->authorized = false; } };
		$result = $this->service->reconcile( $this->factory->recovery, RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertNull( $result->quote ); self::assertNull( $result->command );
	}

	public function test_other_canonical_header_and_reference_with_same_original_intent_cannot_acknowledge_history(): void {
		$original = $this->factory->recovery->original_issue(); $lease = PromotionIssueHistoryFactory::lease( $original ); $terms = $this->factory->recovery->terms(); $id = QuoteId::generate(); $reference = QuoteReference::generate( $id );
		$header = QuoteHeader::issue( $id, $original->owner(), $original->context(), $terms, Q::time(), $original->namespace_hashes( $id ), $original->profile(), 1, $reference );
		$other = FixedPromotionQuoteIssueCommand::captured( $original, $lease, DeliveryQuote::issue( $header, $original->context(), $terms ), $reference )->as_recovery();
		self::assertSame( $this->factory->recovery->intent()->fingerprint(), $other->intent()->fingerprint() );
		$result = $this->service->reconcile( $other, RequestContext::create() );
		self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->attempt->completion ); self::assertNull( $result->quote ); self::assertSame( $other->header()->to_private_json(), $result->command->header()->to_private_json() );
		foreach ( $this->factory->sessions as $session ) { self::assertSame( 0, $session->writes ); }
	}
}

final class PromotionIssueHistoryFactory implements OperationConnectionFactory {
	public FixedPromotionQuoteIssueCommand $recovery;
	public QuoteStoredRow $quote;
	public array $record;
	public array $event;
	public array $sessions = [];
	public ?\Closure $on_ack = null;
	public function __construct() {
		$provider = F::provider(); $original = QuoteIssueCommand::fixed_promotion( F::owner(), $provider->context(), QuoteId::generate()->value(), [ [ 'component_key' => F::key(), 'account_key' => Q::digest( 'history-only funding fixture' ) ] ], Q::time()->plus_seconds( 120 ) );
		$id = QuoteId::generate(); $reference = QuoteReference::generate( $id ); $header = QuoteHeader::issue( $id, F::owner(), $provider->context(), $provider->terms(), Q::time(), $original->namespace_hashes( $id ), $original->profile(), 1, $reference );
		$this->recovery = FixedPromotionQuoteIssueCommand::captured( $original, self::lease( $original ), DeliveryQuote::issue( $header, $provider->context(), $provider->terms() ), $reference )->as_recovery();
		$this->quote = QuoteStoredRow::from_row( array_replace( QuoteStorageFixtures::quote()->row(), [ 'quote_uuid' => $id->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $provider->context()->private_facts(), 'terms' => $provider->terms()->private_facts() ] ), 'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'] ] ) );
		$command = $this->recovery; $identity = $command->identity; $profile = new FixedPromotionQuoteIssueProfile();
		$allocation = [ 'allocation_digest' => $command->allocation_digest(), 'allocation_count' => 1, 'hold_until' => $command->hold_until()->epoch_microseconds() ];
		$result = [ 'quote_id' => $id->value(), 'quote_revision' => 1, 'state' => 'issued', 'body_digest' => $header->body_digest(), 'owner_digest' => F::owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => Q::time()->plus_seconds( 1 )->epoch_microseconds(), 'control_revision' => 1, ...$allocation ];
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => 1, 'owner_digest' => F::owner()->digest(), 'quote_id' => $id->value(), 'purpose' => 'private_quote_invalidation' ] );
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], [ 'quote_id' => $id->value(), 'body_digest' => $header->body_digest(), 'owner_digest' => F::owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'quote_revision' => 1, 'binding_revision' => 0, ...$allocation ], 'quote_issued', 1, 2, [ 'quote', 'admission', 'promotion_budget' ] );
		$this->record = [ 'id' => 1, 'site_id' => 1, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $command->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => 'delivery_quote.issue', 'operation_version' => 2, 'target_hash' => WpdbOperationRecordRepository::target_hash( $identity ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => 1, 'row_version' => 3, 'created_at' => Q::time()->sql(), 'updated_at' => Q::time()->plus_seconds( 1 )->sql(), 'completed_at' => Q::time()->plus_seconds( 1 )->sql() ];
		$this->event = [ 'id' => 1, 'site_id' => 1, 'operation_id' => 1, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => Q::time()->plus_seconds( 1 )->sql() ];
	}
	/** Synthetic confirmed v2 slot; no preparation, gate, or physical claim is inferred from it. */
	public static function lease( QuoteIssueCommand $original ): QuoteAdmissionLease {
		$attempt = QuoteAdmissionAttempt::generate(); $attempt->begin( $original ); $attempt->confirm(); $namespace = $original->identity()->namespace_digest();
		$slot = QuoteBudgetSlot::from_row( array_replace( QuoteStorageFixtures::budget()->row(), [ 'admission_namespace_hash' => $namespace, 'slot_key' => QuoteBudgetSlot::admission_slot_key( $namespace ), 'admission_intent_digest' => $original->intent_digest(), 'server_attempt_digest' => $attempt->digest() ] ) );
		$lease = QuoteAdmissionLease::confirmed( $slot, $original, $attempt ); $lease->claim_capture(); return $lease;
	}
	public function open(): OperationSession { $session = new PromotionIssueHistorySession( $this, [] !== $this->sessions ); $this->sessions[] = $session; return $session; }
}

final class PromotionIssueHistorySession implements OperationSession {
	public string $prefix = 'wp_';
	public int $writes = 0;
	private bool $transaction = false;
	private bool $retired = false;
	public function __construct( private PromotionIssueHistoryFactory $f, private bool $ack ) {}
	public function hook( string $phase ): void { if ( $this->ack && null !== $this->f->on_ack ) { ( $this->f->on_ack )( $this, $phase ); } }
	public function site_id(): int { return 1; }
	public function table_prefix(): string { return $this->prefix; }
	public function charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
	public function begin(): bool { $this->transaction = true; $this->hook( 'begin' ); return true; }
	public function commit(): OperationCommitResult { throw new \LogicException( 'History cannot commit.' ); }
	public function rollback(): bool { $this->transaction = false; $this->hook( 'rollback' ); return true; }
	public function retire(): bool { $this->transaction = false; $this->retired = true; $this->hook( 'retire' ); return true; }
	public function is_retired(): bool { return $this->retired; }
	public function in_transaction(): bool { return $this->transaction; }
	public function validate_tables( array $tables ): bool { $this->hook( 'validation' ); return true; }
	public function query( string $sql ): int|false { ++$this->writes; throw new \LogicException( 'History cannot write.' ); }
	public function prepare( string $sql, mixed ...$args ): string { $index = 0; return preg_replace_callback( '/%[ds]/', static function( array $match ) use ( &$index, $args ): string { $value = $args[$index++]; return '%d' === $match[0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'"; }, $sql ); }
	public function errno(): int { return 0; }
	public function insert_id(): int { return 0; }
	public function get_row( string $sql ): array|null|false {
		if ( str_starts_with( $sql, 'SHOW TABLE STATUS' ) ) { return [ 'Engine' => 'InnoDB', 'Collation' => 'utf8mb4_unicode_ci' ]; }
		if ( str_contains( $sql, SchemaVersion::OPTION_NAME ) ) { return [ 'option_value' => '12' ]; }
		if ( str_contains( $sql, MigrationStatus::OPTION_NAME ) ) { return [ 'option_value' => serialize( [ 'status' => 'success', 'to_version' => '12', 'migration_id' => 'history_test_schema12' ] ) ]; }
		if ( str_contains( $sql, 'operation_records' ) ) { return str_starts_with( $sql, 'SELECT state, audit_id' ) ? [ 'state' => 'accepted', 'audit_id' => 1 ] : $this->f->record; }
		if ( str_contains( $sql, 'operation_changes' ) ) { return $this->f->event; }
		throw new \LogicException( 'Unexpected history fixture row read.' );
	}
	public function get_results( string $sql ): array|false {
		if ( str_starts_with( $sql, 'SHOW FULL COLUMNS' ) || str_starts_with( $sql, 'SHOW INDEX' ) ) { return $this->metadata( $sql ); }
		if ( str_contains( $sql, 'operation_records' ) ) { $this->hook( 'receipt' ); return [ $this->f->record + [ 'oversized' => 0 ] ]; }
		if ( str_contains( $sql, 'operation_changes' ) ) { return [ $this->f->event + [ 'oversized' => 0 ] ]; }
		if ( str_contains( $sql, 'delivery_quotes' ) ) { $this->hook( 'quote' ); return [ $this->f->quote->row() + [ 'payload_oversized' => 0 ] ]; }
		throw new \LogicException( 'Unexpected history fixture collection read.' );
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
