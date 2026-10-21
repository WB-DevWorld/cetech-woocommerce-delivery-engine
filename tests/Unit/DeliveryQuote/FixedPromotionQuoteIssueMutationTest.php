<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryPromotion\{FixedPromotionBudgetAccountStore, FixedPromotionEligibilityBudgetGuard, FixedPromotionQuotePreparationService};
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueInputs, FixedPromotionQuoteIssueProfile, QuoteAdmissionAttempt, QuoteAdmissionLease, QuoteCurrentEvidenceValidity, QuoteIssueCommand};
use CetechDeliveryEngine\Application\Operation\{OperationReadiness, OperationStorageException};
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{DeliveryQuote, QuoteBudgetSlot, QuoteContext, QuoteHeader, QuoteId, QuoteMoney, QuoteOwner, QuoteReference, QuoteTerms, QuoteTime};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\{OperationCommitResult, OperationRefusal, OperationSession};
use CetechDeliveryEngine\Infrastructure\Persistence\{DeliveryQuoteRepository, DeliveryQuoteSchema, EmergencyControlStore};
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\FixedPromotionQuoteFixture as F;
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionQuoteIssueMixedSession, PromotionQuoteIssueSqlPorts};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory, QuoteFixtures as Q};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteDurableFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteIssueSqlPorts.php';

/** Owned SQLite quote/admission SQL and strict codecs; current source/budget ports are synthetic. */
final class FixedPromotionQuoteIssueMutationTest extends TestCase {

	private QuoteDurableFixtureFactory $db;
	private PromotionMutationOwner $owner;
	private PromotionMutationTrace $trace;
	private PromotionMutationBudget $budget;
	private PromotionMutationEligibility $eligibility;
	private PromotionMutationReadiness $ready;
	private FixedPromotionQuoteIssueCommand $command;
	private FixedPromotionQuoteIssueProfile $profile;
	private bool $authorized = true;
	private array $inputs;

	protected function setUp(): void {
		$this->db = new QuoteDurableFixtureFactory(); $this->trace = new PromotionMutationTrace(); $this->owner = new PromotionMutationOwner( new PromotionQuoteIssueMixedSession( $this->db->open() ), $this->trace ); self::assertTrue( $this->owner->begin() );
		$this->inputs = PromotionQuoteIssueSqlPorts::capture( $this->owner ); $provider = $this->inputs['provider']->inner;
		$original = QuoteIssueCommand::fixed_promotion( F::owner(), $provider->context(), '71eb41b0-d08d-4a25-9808-6ad03e6aa002', $this->inputs['slots'], Q::time()->plus_seconds( 120 ) );
		$attempt = QuoteAdmissionAttempt::generate(); self::assertTrue( $attempt->begin( $original ) ); self::assertTrue( $attempt->confirm() ); $namespace = $original->identity()->namespace_digest();
		$slot = QuoteBudgetSlot::from_row( [ 'id' => 1, 'site_id' => 1, 'format_version' => 1, 'purpose' => 'delivery_quote.issue', 'slot_kind' => 'admission', 'slot_key' => QuoteBudgetSlot::admission_slot_key( $namespace ), 'window_start' => Q::time()->sql(), 'principal_hash' => F::owner()->facts()['principal_hash'], 'attempt_count' => 1, 'revision' => 1, 'created_at' => Q::time()->sql(), 'last_seen_at' => Q::time()->sql(), 'admission_namespace_hash' => $namespace, 'admission_intent_digest' => $original->intent_digest(), 'server_attempt_digest' => $attempt->digest(), 'lease_expires_at' => Q::time()->plus_seconds( 60 )->sql(), 'lease_state' => 'granted', 'consumed_quote_uuid' => null, 'consumed_at' => null ] );
		self::assertSame( 1, ( new DeliveryQuoteRepository( $this->owner ) )->insert_budget( $slot ) ); $lease = QuoteAdmissionLease::confirmed( $slot, $original, $attempt ); self::assertTrue( $lease->claim_capture() );
		$ref = QuoteReference::generate( QuoteId::generate() ); $header = QuoteHeader::issue( $ref->id(), F::owner(), $provider->context(), $provider->terms(), Q::time(), $original->namespace_hashes( $ref->id() ), 'fixed_promotion_native_v1', 1, $ref ); $this->command = FixedPromotionQuoteIssueCommand::captured( $original, $lease, DeliveryQuote::issue( $header, $provider->context(), $provider->terms() ), $ref );
		$this->budget = new PromotionMutationBudget( $this->trace ); $request = $this->command->allocation_set()->requests()[0]; $this->budget->accounts[$request->account_key()] = FixedPromotionBudgetState::create( 1, $request->account_key(), $request->funding_source(), QuoteMoney::from_array( [ 'amount' => '6.00', 'currency' => 'GHS', 'precision' => 2 ] ) );
		$this->eligibility = new PromotionMutationEligibility( $this->trace ); $this->ready = new PromotionMutationReadiness( $this->trace );
		$this->profile = new FixedPromotionQuoteIssueProfile( $this->command, fn(): bool => $this->authorized, new FixedPromotionQuotePreparationService( $this->budget, $this->eligibility, $this->ready, static fn(): bool => true ), FixedPromotionQuoteIssueInputs::from_sources( $this->inputs['original'], $this->inputs['fresh'] ), $this->budget, $this->eligibility, new PromotionMutationControl( $this->trace ), current_readiness: $this->ready );
		$this->trace->events = []; $this->profile->transactional_tables( $this->owner );
	}
	protected function tearDown(): void { if ( isset( $this->owner ) && $this->owner->in_transaction() ) { $this->owner->rollback(); } }
	private function lock() { return $this->profile->lock_target( $this->owner, $this->command->identity, $this->command ); }
	private function mutate( $target ) { return $this->profile->mutate( $this->owner, $this->command->identity, $this->command, $target, RequestContext::create() ); }

	public function test_live_prelocks_are_read_only_and_order_accounts_claims_before_targets(): void {
		$this->lock(); self::assertSame( 0, $this->budget->writes ); self::assertSame( [], $this->budget->claims ); self::assertSame( 0, $this->db->count( 'delivery_quotes' ) );
		$events = $this->trace->events; $control = array_search( 'control', $events, true ); $source = array_search( 'source', $events, true ); $eligible = array_search( 'eligibility', $events, true ); $account = array_search( 'account', $events, true ); $claim = array_search( 'claim', $events, true ); $admission = array_search( 'admission', $events, true ); $quote = array_search( 'quote', $events, true );
		foreach ( [ $control, $source, $eligible, $account, $claim, $admission, $quote ] as $position ) { self::assertIsInt( $position ); }
		self::assertLessThan( $source, $control ); self::assertLessThan( $eligible, $source ); self::assertLessThan( $account, $eligible ); self::assertLessThan( $claim, $account ); self::assertLessThan( $admission, $claim ); self::assertLessThan( $quote, $admission );
	}
	public function test_two_component_shared_pool_and_exact_quote_admission_stage_together(): void {
		$result = $this->mutate( $this->lock() ); self::assertTrue( $result->changed ); self::assertTrue( $this->owner->in_transaction() ); self::assertCount( 2, $this->budget->claims ); self::assertSame( 4, $this->budget->writes );
		self::assertSame( '6.00', array_values( $this->budget->accounts )[0]->held()->amount() ); self::assertSame( 1, $this->db->count( 'delivery_quotes' ) ); self::assertSame( 'consumed', $this->db->pdo->query( 'SELECT lease_state FROM durable_delivery_engine_delivery_quote_budget_windows' )->fetchColumn() );
		self::assertSame( 2, $result->completion->result['allocation_count'] ); self::assertSame( $this->command->allocation_digest(), $result->event->target['allocation_digest'] ); self::assertTrue( $this->profile->validate_accepted_facts( $result->completion, $result->event ) ); self::assertSame( 3, $this->owner->clocks ); self::assertSame( 3, $this->ready->calls );
		$stored = ( new DeliveryQuoteRepository( $this->owner ) )->find_quote( $this->command->header()->id() ); self::assertSame( $this->command->header()->to_private_json(), $stored->header()->to_private_json() ); self::assertSame( $this->command->terms()->to_private_json(), $stored->terms()->to_private_json() );
	}
	public static function late_failures(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'admission expiry', 'hold expiry', 'backward clock', 'proof expiry', 'authorization', 'owner rebound', 'quote insert', 'admission cas' ] ); }
	#[DataProvider( 'late_failures' )]
	public function test_every_failure_after_preparation_requires_owned_rollback( string $case ): void {
		$target = $this->lock();
		if ( 'admission expiry' === $case ) { $this->owner->final_time = Q::time()->plus_seconds( 60 ); }
		elseif ( 'hold expiry' === $case ) { $this->owner->final_time = $this->command->hold_until(); }
		elseif ( 'backward clock' === $case ) { $this->owner->final_time = Q::time()->plus_seconds( -1 ); }
		elseif ( 'proof expiry' === $case ) { $this->eligibility->seconds = 1; $this->owner->final_time = Q::time()->plus_seconds( 1 ); }
		elseif ( 'authorization' === $case ) { $this->budget->on_write = function(): void { $this->authorized = false; }; }
		elseif ( 'owner rebound' === $case ) { $this->budget->on_write = function(): void { $this->owner->rebound = true; }; }
		elseif ( 'quote insert' === $case ) { $this->owner->fail_quote = true; }
		elseif ( 'admission cas' === $case ) { $this->owner->fail_admission = true; }
		try { $this->mutate( $target ); self::fail( 'A prepared unit must not report acceptance.' ); } catch ( OperationStorageException ) { self::assertGreaterThan( 0, $this->budget->writes ); self::assertTrue( $this->owner->in_transaction() ); }
		self::assertTrue( $this->owner->rollback() ); self::assertSame( 0, $this->db->count( 'delivery_quotes' ) ); self::assertSame( 0, $this->db->count( 'delivery_quote_budget_windows' ) );
	}
	public function test_changed_original_source_refuses_before_any_budget_or_target_lock(): void {
		$this->owner->inner->source->rows['wp_posts'][0]['post_modified_gmt'] = '2026-10-07 05:00:01';
		try { $this->lock(); self::fail( 'Changed current source must refuse.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->budget->writes ); self::assertNotContains( 'account', $this->trace->events ); self::assertNotContains( 'admission', $this->trace->events ); }
	}
	public function test_late_clock_after_target_wait_refuses_before_preparation_writes(): void {
		$target = $this->lock(); $this->owner->before_time = Q::time()->plus_seconds( 60 );
		try { $this->mutate( $target ); self::fail( 'An expired live lease must refuse.' ); } catch ( OperationRefusal ) { self::assertSame( 0, $this->budget->writes ); self::assertSame( 0, $this->db->count( 'delivery_quotes' ) ); }
	}
}

final class PromotionMutationTrace { public array $events = []; }
final class PromotionMutationOwner implements OperationSession {
	public int $clocks = 0; public ?QuoteTime $final_time = null; public ?QuoteTime $before_time = null; public bool $rebound = false; public bool $fail_quote = false; public bool $fail_admission = false;
	public function __construct( public readonly PromotionQuoteIssueMixedSession $inner, private PromotionMutationTrace $trace ) {}
	public function site_id(): int { return $this->inner->site_id(); } public function table_prefix(): string { return $this->inner->table_prefix(); } public function charset_collate(): string { return $this->rebound ? 'utf8mb4_bin' : $this->inner->charset_collate(); }
	public function begin(): bool { return $this->inner->begin(); } public function commit(): OperationCommitResult { throw new \LogicException( 'Profile cannot commit.' ); } public function rollback(): bool { return $this->inner->rollback(); } public function retire(): bool { return $this->inner->retire(); }
	public function is_retired(): bool { return $this->inner->is_retired(); } public function in_transaction(): bool { return $this->inner->in_transaction(); } public function validate_tables( array $tables ): bool { return $this->in_transaction(); }
	public function query( string $sql ): int|false { if ( $this->fail_quote && str_starts_with( $sql, 'INSERT INTO `durable_delivery_engine_delivery_quotes`' ) || $this->fail_admission && str_starts_with( $sql, 'UPDATE `durable_delivery_engine_delivery_quote_budget_windows`' ) ) { return false; } return $this->inner->query( $sql ); }
	public function get_row( string $sql ): array|null|false { if ( 'SELECT UTC_TIMESTAMP(6) AS utc' === $sql ) { ++$this->clocks; return [ 'utc' => ( 3 === $this->clocks ? $this->final_time : ( 2 === $this->clocks ? $this->before_time : null ) )?->sql() ?? Q::time()->sql() ]; } return $this->inner->get_row( $sql ); }
	public function get_results( string $sql ): array|false {
		if ( 'SHOW FULL COLUMNS FROM `durable_options`' === $sql ) { return $this->inner->source->get_results( 'SHOW FULL COLUMNS FROM `wp_options`' ); }
		if ( 'SHOW FULL COLUMNS FROM `durable_delivery_engine_rate_cards`' === $sql ) {
			$columns = $this->inner->source->get_results( 'SHOW FULL COLUMNS FROM `wp_delivery_engine_rate_cards`' ); $required = DeliveryQuoteSchema::rate_columns();
			foreach ( $columns as &$column ) { if ( isset( $required[$column['Field']] ) ) { [ $type, $nullable, $default, $extra, $collation ] = $required[$column['Field']]; $column = [ 'Field' => $column['Field'], 'Type' => $type, 'Null' => $nullable ? 'YES' : 'NO', 'Default' => $default, 'Extra' => $extra, 'Collation' => 'site' === $collation ? 'utf8mb4_unicode_ci' : $collation ]; } } unset( $column ); return $columns;
		}
		if ( 'SHOW INDEX FROM `durable_delivery_engine_rate_cards`' === $sql ) {
			$out = []; foreach ( [ 'PRIMARY' => [ 'unique' => true, 'columns' => [ 'id' ] ], DeliveryQuoteSchema::RATE_INDEX => DeliveryQuoteSchema::rate_index() ] as $name => $index ) { foreach ( $index['columns'] as $position => $field ) { $out[] = [ 'Key_name' => $name, 'Seq_in_index' => $position + 1, 'Non_unique' => $index['unique'] ? 0 : 1, 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A', 'Column_name' => $field, 'Expression' => null, 'Visible' => 'YES', 'Ignored' => 'NO' ]; } } return $out;
		}
		if ( str_contains( $sql, 'source_oversized' ) ) { $this->trace->events[] = 'source'; }
		if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_delivery_quote_budget_windows`' ) ) { $this->trace->events[] = 'admission'; }
		if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, 'FROM `durable_delivery_engine_delivery_quotes`' ) ) { $this->trace->events[] = 'quote'; }
		return $this->inner->get_results( $sql );
	}
	public function prepare( string $sql, mixed ...$args ): string { return $this->inner->prepare( $sql, ...$args ); } public function errno(): int { return $this->inner->errno(); } public function insert_id(): int { return $this->inner->insert_id(); }
}
final class PromotionMutationControl extends EmergencyControlStore {
	public function __construct( private PromotionMutationTrace $trace ) { parent::__construct(); }
	public function assert_ready( OperationSession $session, int $site ): void {}
	public function current( OperationSession $session ): EmergencyControlState { $this->trace->events[] = 'control'; return EmergencyControlState::absent( 1 ); }
}
final class PromotionMutationReadiness implements OperationReadiness {
	public int $calls = 0; public function __construct( private PromotionMutationTrace $trace ) {}
	public function assert_ready( OperationSession $session ): void { ++$this->calls; $this->trace->events[] = 'publication'; if ( ! $session->in_transaction() ) { throw new \LogicException( 'Current publication requires owned BEGIN.' ); } }
}
final class PromotionMutationEligibility implements FixedPromotionEligibilityBudgetGuard {
	public int $seconds = 300; public function __construct( private PromotionMutationTrace $trace ) {}
	public function tables( OperationSession $session ): array { return [ $session->table_prefix() . 'synthetic_policies' ]; }
	public function validity_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { throw new \LogicException( 'Account-aware eligibility required.' ); }
	public function validity_for_requests_at( OperationSession $s, QuoteOwner $o, QuoteHeader $h, QuoteContext $c, QuoteTerms $t, array $requests, QuoteTime $at ): ?QuoteCurrentEvidenceValidity { $this->trace->events[] = 'eligibility'; return QuoteCurrentEvidenceValidity::capture( $at, $at->plus_seconds( $this->seconds ) ); }
}
final class PromotionMutationBudget implements FixedPromotionBudgetAccountStore {
	public array $accounts = []; public array $claims = []; public int $writes = 0; public ?\Closure $on_write = null;
	public function __construct( private PromotionMutationTrace $trace ) {}
	public function tables( OperationSession $s ): array { return [ $s->table_prefix() . 'synthetic_accounts', $s->table_prefix() . 'synthetic_claims' ]; }
	public function lock_account( OperationSession $s, string $key ): ?FixedPromotionBudgetState { $this->trace->events[] = 'account'; return $this->accounts[$key] ?? null; }
	public function lock_reservation( OperationSession $s, string $key ): ?FixedPromotionBudgetReservation { throw new \LogicException( 'Exact account scope is required.' ); }
	public function lock_reservation_for_account( OperationSession $s, string $key, string $account ): ?FixedPromotionBudgetReservation { $this->trace->events[] = 'claim'; return $this->claims[$key] ?? null; }
	private function writing(): void { ++$this->writes; if ( null !== $this->on_write ) { ( $this->on_write )(); } }
	public function replace_account( OperationSession $s, FixedPromotionBudgetState $expected, FixedPromotionBudgetState $next ): bool { $this->writing(); if ( ( $this->accounts[$expected->account_key()] ?? null )?->to_private_json() !== $expected->to_private_json() ) { return false; } $this->accounts[$next->account_key()] = $next; return true; }
	public function insert_reservation( OperationSession $s, FixedPromotionBudgetReservation $claim ): bool { $this->writing(); $key = $claim->request()->claim_key(); if ( isset( $this->claims[$key] ) ) { return false; } $this->claims[$key] = $claim; return true; }
	public function replace_reservation( OperationSession $s, FixedPromotionBudgetReservation $expected, FixedPromotionBudgetReservation $next ): bool { throw new \LogicException( 'Issue never commits or releases a reservation.' ); }
}
