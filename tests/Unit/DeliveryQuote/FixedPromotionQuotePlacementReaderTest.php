<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, FixedPromotionQuoteAcceptInputs, FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueProfile, FixedPromotionQuotePlacementEvidenceReader, FixedPromotionQuotePlacementReadResult, QuoteIssueCommand, QuoteOperationProfile};
use CetechDeliveryEngine\Application\Operation\OperationReadiness;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteHeader, QuoteId, QuoteJson, QuoteMoney, QuoteReference, QuoteStoredRow, QuoteTime};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationCompletion, OperationConnectionFactory, OperationMaterialEvent, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, EmergencyControlStore, WpdbOperationRecordRepository};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteIssueMixedSession, PromotionQuoteIssueSqlPorts};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory, QuoteFixtures as Q};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuoteAcceptProfileTest.php';

/** Real SQLite quote/receipt codecs with explicitly synthetic source/native/policy/budget ports. */
final class FixedPromotionQuotePlacementReaderTest extends TestCase {
	public function test_fully_held_shared_pool_returns_detached_accepted_facts_without_a_single_write(): void {
		$f = new PromotionPlacementReaderFixture(); $before = $f->bytes(); $result = $f->read();
		self::assertSame( 'ready', $result->status ); self::assertNull( $result->reason ); self::assertNotNull( $result->evidence );
		self::assertSame( $f->quote()->row(), $result->evidence->quote_record()->row() ); self::assertSame( Q::time()->plus_seconds( 2 )->sql(), $result->evidence->accepted_at()->sql() ); self::assertSame( Q::time()->plus_seconds( 3 )->sql(), $result->evidence->evaluated_at()->sql() );
		self::assertSame( $f->command->context()->to_private_json(), $result->evidence->context()->to_private_json() ); self::assertSame( $f->command->terms()->to_private_json(), $result->evidence->terms()->to_private_json() );
		self::assertTrue( array_values( $f->budget->accounts )[0]->remaining()->zero() ); self::assertSame( $before, $f->bytes() ); self::assertSame( 0, $f->budget->writes );
		self::assertCount( 1, $f->sessions ); self::assertSame( 0, $f->sessions[0]->writes ); self::assertSame( 3, $f->sessions[0]->clock_reads ); self::assertTrue( $f->sessions[0]->is_retired() ); self::assertFalse( $f->sessions[0]->in_transaction() );
		foreach ( $f->auth_operations as $operation ) { self::assertSame( 'delivery_quote.read', $operation ); }
	}

	public function test_all_history_locks_precede_control_source_all_accounts_claims_and_quote(): void {
		$f = new PromotionPlacementReaderFixture( true ); self::assertSame( 'ready', $f->read()->status ); $events = $f->trace->events;
		foreach ( [ 'receipt', 'control', 'publication', 'source', 'eligibility', 'account', 'claim', 'quote' ] as $event ) { self::assertContains( $event, $events ); }
		self::assertLessThan( array_search( 'control', $events, true ), max( array_keys( $events, 'receipt', true ) ) );
		foreach ( [ [ 'control', 'publication' ], [ 'publication', 'source' ], [ 'source', 'eligibility' ], [ 'eligibility', 'account' ], [ 'account', 'claim' ], [ 'claim', 'quote' ] ] as [ $a, $b ] ) { self::assertLessThan( array_search( $b, $events, true ), array_search( $a, $events, true ) ); }
		$accounts = array_keys( $f->budget->accounts ); sort( $accounts, SORT_STRING ); $claims = array_keys( $f->budget->claims ); sort( $claims, SORT_STRING );
		self::assertSame( $accounts, array_slice( $f->budget->account_reads, 0, 2 ) ); self::assertSame( $claims, array_slice( $f->budget->claim_reads, 0, 2 ) );
		foreach ( $f->sessions[0]->receipt_sql as $sql ) { self::assertStringContainsString( 'FOR UPDATE', $sql ); }
	}

	public static function invalid_holds(): array { return array_map( static fn( string $v ): array => [ $v ], [ 'missing', 'released', 'committed', 'shorter', 'extended', 'other request', 'aggregate shortage', 'funding mismatch' ] ); }
	#[DataProvider( 'invalid_holds' )]
	public function test_every_exact_held_claim_and_current_shared_pool_is_required( string $case ): void {
		$f = new PromotionPlacementReaderFixture(); $key = array_key_first( $f->budget->claims ); $claim = $f->budget->claims[$key]; $account = $claim->request()->account_key();
		if ( 'missing' === $case ) { unset( $f->budget->claims[$key] ); }
		elseif ( 'released' === $case ) { $f->budget->claims[$key] = $claim->release( Q::time()->plus_seconds( 3 ) ); }
		elseif ( 'committed' === $case ) { $f->budget->claims[$key] = $claim->commit( Q::time()->plus_seconds( 3 ) ); }
		elseif ( in_array( $case, [ 'shorter', 'extended' ], true ) ) { $f->budget->claims[$key] = FixedPromotionBudgetReservation::hold( $claim->request(), Q::time(), Q::time()->plus_seconds( 'shorter' === $case ? 119 : 121 ) ); }
		elseif ( 'other request' === $case ) { $f->budget->claims[$key] = array_values( $f->budget->claims )[1]; }
		else { $data = $f->budget->accounts[$account]->private_facts(); if ( 'aggregate shortage' === $case ) { $data['held']['amount'] = '3.00'; } else { $data['funding_source']['code'] = 'foreign_funding'; } $f->budget->accounts[$account] = FixedPromotionBudgetState::from_array( $data ); }
		$before = $f->bytes(); $this->assert_unavailable( $f->read() ); self::assertSame( $before, $f->bytes() ); self::assertSame( 0, $f->budget->writes ); self::assertSame( 0, $f->sessions[0]->writes );
	}

	public function test_current_pause_refuses_before_source_budget_or_quote(): void {
		$f = new PromotionPlacementReaderFixture(); $f->paused = true; $this->assert_unavailable( $f->read() ); self::assertNotContains( 'source', $f->trace->events ); self::assertNotContains( 'account', $f->trace->events ); self::assertNotContains( 'quote', $f->trace->events );
	}
	public function test_missing_current_publication_refuses_before_source_or_budget(): void {
		$f = new PromotionPlacementReaderFixture(); $f->published = false; $this->assert_unavailable( $f->read() ); self::assertNotContains( 'source', $f->trace->events ); self::assertNotContains( 'account', $f->trace->events );
	}
	public function test_unknown_current_eligibility_refuses_before_budget_and_quote(): void {
		$f = new PromotionPlacementReaderFixture(); $f->eligibility->seconds = 0; $before = $f->bytes(); $this->assert_unavailable( $f->read() ); self::assertNotContains( 'account', $f->trace->events ); self::assertNotContains( 'quote', $f->trace->events ); self::assertSame( $before, $f->bytes() );
	}
	public function test_changed_original_source_refuses_before_budget_and_quote(): void {
		$f = new PromotionPlacementReaderFixture(); $f->on_hook = static function( PromotionPlacementReaderSession $s, string $phase ): void { if ( 'begin' === $phase ) { $s->inner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01'; } };
		$this->assert_unavailable( $f->read() ); self::assertNotContains( 'account', $f->trace->events ); self::assertNotContains( 'quote', $f->trace->events );
	}
	public function test_initial_authorization_refusal_never_opens_an_owner(): void {
		$f = new PromotionPlacementReaderFixture(); $f->authorized = false; $result = $f->read(); self::assertSame( 'not_authorized', $result->reason ); $this->assert_unavailable( $result ); self::assertSame( [], $f->sessions );
	}
	private function assert_unavailable( FixedPromotionQuotePlacementReadResult $r ): void { self::assertSame( 'unavailable', $r->status ); self::assertNull( $r->evidence ); }
}

/** Shared NEW fixture for the reader and adversarial lane; no inherited fixture is modified. */
final class PromotionPlacementReaderFixture implements OperationConnectionFactory {
	public QuoteDurableFixtureFactory $db;
	public PromotionMutationTrace $trace;
	public PromotionAcceptMutationBudget $budget;
	public PromotionAcceptMutationEligibility $eligibility;
	public FixedPromotionQuoteAcceptCommand $command;
	public FixedPromotionQuoteAcceptInputs $inputs;
	public array $sessions = [];
	public array $auth_operations = [];
	public bool $authorized = true;
	public bool $paused = false;
	public bool $published = true;
	public ?\Closure $on_hook = null;
	public ?\Closure $on_authorize = null;
	public ?\Closure $on_open = null;
	public function __construct( bool $separate_accounts = false ) {
		$this->db = new QuoteDurableFixtureFactory(); $this->trace = new PromotionMutationTrace(); $owner = new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->db->open() ), $this->trace ); $owner->begin();
		$capture = PromotionQuoteIssueSqlPorts::capture( $owner ); if ( $separate_accounts ) { $capture['slots'][1]['account_key'] = Q::digest( 'E12 distinct second pool' ); } $provider = $capture['provider']->inner;
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $provider->context(), QuoteId::generate()->value(), $capture['slots'], Q::time()->plus_seconds( 120 ) ); $id = QuoteId::generate(); $reference = QuoteReference::generate( $id );
		$header = QuoteHeader::issue( $id, F::owner(), $provider->context(), $provider->terms(), Q::time(), $original->namespace_hashes( $id ), $original->profile(), 1, $reference );
		$issue = FixedPromotionQuoteIssueCommand::captured( $original, PromotionIssueHistoryFactory::lease( $original ), DeliveryQuote::issue( $header, $provider->context(), $provider->terms() ), $reference );
		$this->command = FixedPromotionQuoteAcceptCommand::from_issue( $issue ); $this->inputs = FixedPromotionQuoteAcceptInputs::from_sources( $capture['original'], $capture['fresh'] );
		$row = [ 'id' => 1, 'site_id' => 1, 'quote_uuid' => $id->value(), 'format_version' => 3, 'profile_code' => $header->profile(), 'profile_version' => 1, 'purpose' => $header->purpose(), 'principal_hash' => F::owner()->facts()['principal_hash'], 'owner_digest' => F::owner()->digest(), 'material_digest' => $header->material_digest(), 'body_digest' => $header->body_digest(), 'header_json' => $header->to_private_json(), 'private_body_json' => QuoteJson::encode( [ 'context' => $provider->context()->private_facts(), 'terms' => $provider->terms()->private_facts() ] ), 'issue_namespace_hash' => $header->namespace_hashes()['issue'], 'accept_namespace_hash' => $header->namespace_hashes()['accept'], 'invalidate_namespace_hash' => $header->namespace_hashes()['invalidate'], 'state' => 'accepted', 'revision' => 2, 'retention_revision' => 1, 'created_at' => Q::time()->sql(), 'expires_at' => $header->expires_at()->sql(), 'accepted_at' => Q::time()->plus_seconds( 2 )->sql(), 'transition_at' => Q::time()->plus_seconds( 2 )->sql() ];
		$this->insert( 'delivery_quotes', QuoteStoredRow::from_row( $row )->row() );
		$this->seed_receipt( 1, $issue, new FixedPromotionQuoteIssueProfile(), 'quote_issued', 1, 'issued', Q::time()->plus_seconds( 1 ) );
		$this->seed_receipt( 2, $this->command, new QuoteOperationProfile( 'delivery_quote.accept' ), 'quote_accepted', 2, 'accepted', Q::time()->plus_seconds( 2 ) );
		$this->db->pdo->commit(); $owner->retire();
		$this->budget = new PromotionAcceptMutationBudget( $this->trace );
		foreach ( $issue->allocation_set()->requests() as $request ) { $key = $request->account_key(); $account = $this->budget->accounts[$key] ?? FixedPromotionBudgetState::create( 1, $key, $request->funding_source(), QuoteMoney::from_array( [ 'amount' => '6.00', 'currency' => 'GHS', 'precision' => 2 ] ) ); $this->budget->accounts[$key] = $account->reserve( $request->amount() ); $this->budget->claims[$request->claim_key()] = FixedPromotionBudgetReservation::hold( $request, Q::time(), $issue->hold_until() ); }
		$this->eligibility = new PromotionAcceptMutationEligibility( $this->trace ); $this->trace->events = []; $this->db->statements = [];
	}
	private function seed_receipt( int $id, $command, $profile, string $event_name, int $revision, string $state, QuoteTime $time ): void {
		$identity = $command->identity; $header = $command->header(); $allocation = 1 === $id ? [ 'allocation_digest' => $command->allocation_digest(), 'allocation_count' => count( $command->allocation_set()->requests() ), 'hold_until' => $command->hold_until()->epoch_microseconds() ] : [];
		$result = [ 'quote_id' => $header->id()->value(), 'quote_revision' => $revision, 'state' => $state, 'body_digest' => $header->body_digest(), 'owner_digest' => $command->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'completed_at' => $time->epoch_microseconds(), 'control_revision' => 1, ...$allocation ];
		$completion = OperationCompletion::accepted( $profile, $result, [ 'site_id' => 1, 'owner_digest' => $command->owner()->digest(), 'quote_id' => $header->id()->value(), 'purpose' => 'private_quote_invalidation' ] );
		$event = OperationMaterialEvent::from_mutation( $profile, $identity, RequestContext::create(), [ 'authority_hash' => hash( 'sha256', $identity->authority ), 'principal_hash' => hash( 'sha256', $identity->principal ) ], [ 'quote_id' => $header->id()->value(), 'body_digest' => $header->body_digest(), 'owner_digest' => $command->owner()->digest(), 'namespace_hash' => $identity->namespace_digest(), 'quote_revision' => $revision, 'binding_revision' => 0, ...$allocation ], $event_name, 1, 2, 1 === $id ? [ 'quote', 'admission', 'promotion_budget' ] : [ 'quote' ] );
		$this->insert( 'operation_records', [ 'id' => $id, 'site_id' => 1, 'namespace_hash' => $identity->namespace_digest(), 'intent_hash' => $command->intent()->fingerprint(), 'namespace_format' => 1, 'intent_format' => 1, 'record_format' => 1, 'operation' => $identity->operation, 'operation_version' => $identity->operation_version, 'target_hash' => WpdbOperationRecordRepository::target_hash( $identity ), 'state' => 'accepted', 'publication_state' => 'published', 'completion_json' => $completion->to_json(), 'audit_id' => $id, 'row_version' => 3, 'created_at' => Q::time()->sql(), 'updated_at' => $time->sql(), 'completed_at' => $time->sql() ] );
		$this->insert( 'operation_changes', [ 'id' => $id, 'site_id' => 1, 'operation_id' => $id, 'event_format' => 1, 'event_json' => $event->to_json(), 'created_at' => $time->sql() ] );
	}
	public function insert( string $suffix, array $row ): void { $columns = implode( ',', array_keys( $row ) ); $placeholders = implode( ',', array_fill( 0, count( $row ), '?' ) ); $this->db->pdo->prepare( "INSERT INTO durable_delivery_engine_{$suffix} ({$columns}) VALUES ({$placeholders})" )->execute( array_values( $row ) ); }
	public function open(): OperationSession { $session = new PromotionPlacementReaderSession( new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->db->open() ), $this->trace ), $this ); $this->sessions[] = $session; if ( null !== $this->on_open ) { ( $this->on_open )( $session ); } return $session; }
	public function reader(): FixedPromotionQuotePlacementEvidenceReader {
		$pre = new class implements OperationReadiness { public function assert_ready( OperationSession $session ): void { $session->hook( 'readiness' ); } };
		$control = new class( $this ) extends EmergencyControlStore { public function __construct( private PromotionPlacementReaderFixture $f ) {} public function assert_ready( OperationSession $session, int $site ): void { $session->hook( 'control_ready' ); } public function current( OperationSession $session ): EmergencyControlState { $this->f->trace->events[] = 'control'; $session->hook( 'control' ); return $this->f->paused ? EmergencyControlState::from_physical( 1, 1, EmergencyControlState::record_json( 1, 'checkout_suspended', 2, 'operator_pause', 1, 1791349200 ) ) : EmergencyControlState::absent( 1 ); } };
		$current = new class( $this ) implements OperationReadiness { public function __construct( private PromotionPlacementReaderFixture $f ) {} public function assert_ready( OperationSession $session ): void { $this->f->trace->events[] = 'publication'; $session->hook( 'publication' ); if ( ! $this->f->published ) { throw new \RuntimeException( 'Synthetic publication unavailable.' ); } } };
		return new FixedPromotionQuotePlacementEvidenceReader( $this, $this->budget, $this->eligibility, function( $owner, string $operation ): bool { $this->auth_operations[] = $operation; if ( null !== $this->on_authorize ) { ( $this->on_authorize )( $owner, $operation, $this ); } return $this->authorized; }, $pre, $control, $current );
	}
	public function read(): FixedPromotionQuotePlacementReadResult { return $this->reader()->current( $this->command, $this->inputs, RequestContext::create() ); }
	public function quote(): QuoteStoredRow { return QuoteStoredRow::from_row( $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_delivery_quotes' )->fetch( \PDO::FETCH_ASSOC ) ); }
	public function bytes(): array { return [ $this->quote()->row(), $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_operation_records ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ), $this->db->pdo->query( 'SELECT * FROM durable_delivery_engine_operation_changes ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ), array_map( static fn( $v ): string => $v->to_private_json(), $this->budget->accounts ), array_map( static fn( $v ): string => $v->to_private_json(), $this->budget->claims ) ]; }
}

final class PromotionPlacementReaderSession implements OperationSession {
	public string $prefix = 'durable_'; public int $site = 1; public string $charset = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; public string $fail = ''; public array $clock_times = []; public int $clock_reads = 0; public int $writes = 0; public array $receipt_sql = [];
	public function __construct( public readonly PromotionMutationOwner $inner, private PromotionPlacementReaderFixture $f ) {}
	public function hook( string $phase ): void { if ( null !== $this->f->on_hook ) { ( $this->f->on_hook )( $this, $phase ); } if ( $this->fail === 'throw:' . $phase ) { throw new \RuntimeException( 'Synthetic boundary failure.' ); } }
	public function site_id(): int { return $this->site; } public function table_prefix(): string { return $this->prefix; } public function charset_collate(): string { return $this->charset; }
	public function begin(): bool { $this->hook( 'begin' ); return 'begin' !== $this->fail && $this->inner->begin(); }
	public function commit(): OperationCommitResult { throw new \LogicException( 'Read-only placement cannot commit.' ); }
	public function rollback(): bool { $result = $this->inner->rollback(); $this->hook( 'rollback' ); return 'rollback' !== $this->fail && $result; }
	public function retire(): bool { $result = $this->inner->retire(); $this->hook( 'retire' ); return 'retire' !== $this->fail && $result; }
	public function is_retired(): bool { $this->hook( 'is_retired' ); return $this->inner->is_retired(); } public function in_transaction(): bool { $this->hook( 'in_transaction' ); return $this->inner->in_transaction(); }
	public function validate_tables( array $tables ): bool { $this->hook( 'validation' ); return 'validation' !== $this->fail && $this->inner->validate_tables( $tables ); }
	public function query( string $sql ): int|false { ++$this->writes; throw new \LogicException( 'Read-only evidence cannot mutate.' ); }
	public function get_row( string $sql ): array|null|false { if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { ++$this->clock_reads; $this->hook( 'clock' ); return [ 'utc' => ( $this->clock_times[$this->clock_reads - 1] ?? Q::time()->plus_seconds( 3 ) )->sql() ]; } return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false { if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_operation_records`' ) ) { $this->f->trace->events[] = 'receipt'; $this->receipt_sql[] = $sql; $this->hook( 'receipt' ); } elseif ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_delivery_quotes`' ) ) { $this->hook( 'quote' ); } return $this->inner->get_results( $sql ); }
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); }
}
