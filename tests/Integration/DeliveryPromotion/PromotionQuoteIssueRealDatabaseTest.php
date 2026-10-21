<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteIssueCommand, FixedPromotionQuoteIssueResult, QuoteAdmissionAttempt, QuoteDurableService, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate, QuoteProviderRegistry};
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Domain\DeliveryPromotion\FixedPromotionBudgetState;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteHeader, QuoteId, QuoteOwner, QuoteReference};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionQuoteIssueMixedSession, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as Stack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuoteIssueSqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Exactly twelve mandatory physical C03/quote/admission/ledger cases. Source/native/eligibility ports are synthetic. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionQuoteIssueRealDatabaseTest extends TestCase {
	private \mysqli $database;
	private string $prefix;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];
	private array $fixture;

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix(); DB::install( $this->database, $this->prefix ); B::install( $this->database, $this->prefix ); A::publication( $this->database, $this->prefix, 'success' );
		$factory = $this->factory(); $session = ( new PromotionQuoteIssueSqlFactory( $factory ) )->open(); self::assertTrue( $session->begin() ); $this->fixture = Ports::capture( $session ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		$this->provision( '9.00' );
	}
	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); } foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}
	private function factory( int $clock = Factory::NOW ): Factory { $factory = new Factory( $this->prefix ); $factory->clock = $clock; $this->factories[] = $factory; return $factory; }
	private function stack( int $clock = Factory::NOW, ?callable $configure = null ): Stack { $stack = new Stack( $this->prefix, $this->fixture, $clock, $configure ); $this->factories[] = $stack->physical; return $stack; }
	private function token(): string { return QuoteId::generate()->value(); }
	private function provision( string $limit ): void {
		$table = $this->prefix . 'delivery_engine_promotion_budget_accounts'; DB::execute( $this->database, "DELETE FROM `{$table}`" );
		$state = FixedPromotionBudgetState::create( 1, $this->fixture['slots'][0]['account_key'], F::rule()->private_facts()['funding_source'], B::money( $limit ) ); $values = FixedPromotionBudgetRecordCodec::account_values( $state );
		$columns = implode( ',', array_map( static fn( string $key ): string => "`{$key}`", array_keys( $values ) ) ); $quoted = array_map( fn( mixed $value ): string => "'" . $this->database->real_escape_string( (string) $value ) . "'", array_values( $values ) ); DB::execute( $this->database, "INSERT INTO `{$table}` ({$columns}) VALUES (" . implode( ',', $quoted ) . ')' );
	}
	private function lease( Stack $stack, string $token ) {
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $purpose ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $purpose, null, new EmergencyControlStore( static fn(): bool => false ) );
		$early = QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'E10 server-owned synthetic draft' ), $token ); $result = $gate->admit( $early, QuotePreparationAttempt::generate() );
		self::assertSame( 'preparation_allowed', $result->status ); self::assertNotNull( $result->lease ); self::assertTrue( $result->lease->claim_preparation() ); return $result->lease->bind_issue( $stack->original( $token ) );
	}
	private function issue( Stack $stack, string $token, ?callable $stage = null ): FixedPromotionQuoteIssueResult {
		return $stack->service->issue_admitted( $stack->original( $token ), $this->lease( $stack, $token ), $stack->inputs(), RequestContext::create(), $stage ?? static fn(): bool => true );
	}
	private function rows( string $suffix ): array { $result = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" ); if ( ! $result instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned E10 row inspection failed.' ); } return $result->fetch_all( MYSQLI_ASSOC ); }
	private function effects(): array { $out = []; foreach ( [ 'delivery_quotes', 'delivery_quote_budget_windows', 'promotion_budget_accounts', 'promotion_budget_reservations', 'operation_records', 'operation_changes' ] as $suffix ) { $out[$suffix] = $this->rows( $suffix ); } return $out; }
	private function assert_accepted( FixedPromotionQuoteIssueResult $result ): void { self::assertSame( 'accepted', $result->attempt->outcome->state, json_encode( [ 'error' => $result->attempt->outcome->error?->code, 'completion' => $result->attempt->completion?->state ], JSON_THROW_ON_ERROR ) ); self::assertTrue( $result->attempt->outcome->mutation_accepted ); self::assertNotNull( $result->quote ); self::assertNotNull( $result->command ); self::assertNull( $result->command->lease() ); }
	private function assert_no_effect( array $accounts, array $admissions ): void {
		self::assertSame( [], $this->rows( 'delivery_quotes' ) ); self::assertSame( [], $this->rows( 'promotion_budget_reservations' ) ); self::assertSame( [], $this->rows( 'operation_changes' ) ); self::assertSame( $accounts, $this->rows( 'promotion_budget_accounts' ) ); self::assertSame( $admissions, $this->rows( 'delivery_quote_budget_windows' ) );
		foreach ( $this->rows( 'operation_records' ) as $record ) { self::assertNotSame( 'accepted', $record['state'] ); self::assertNull( $record['audit_id'] ); }
	}
	private function fail_effect( string $kind ): void {
		$stack = $this->stack( configure: static function( Transport $transport ) use ( $kind ): void { $transport->{'reject_' . $kind} = true; } ); $token = $this->token(); $lease = $this->lease( $stack, $token ); $accounts = $this->rows( 'promotion_budget_accounts' ); $admissions = $this->rows( 'delivery_quote_budget_windows' );
		$result = $stack->service->issue_admitted( $stack->original( $token ), $lease, $stack->inputs(), RequestContext::create(), static fn(): bool => true ); self::assertContains( $result->attempt->outcome->state, [ 'rejected', 'unconfirmed' ] ); $this->assert_no_effect( $accounts, $admissions );
		$sql = $stack->physical->transports[0]->sql; $hold_index = null; $failure_index = null;
		foreach ( $sql as $index => $statement ) {
			if ( null === $hold_index && str_starts_with( $statement, 'INSERT INTO ' ) && str_contains( $statement, 'promotion_budget_reservations' ) ) { $hold_index = $index; }
			$target = match ( $kind ) { 'quote' => str_starts_with( $statement, 'INSERT INTO ' ) && str_contains( $statement, 'delivery_engine_delivery_quotes' ), 'audit' => str_starts_with( $statement, 'INSERT INTO ' ) && str_contains( $statement, 'delivery_engine_operation_changes' ), default => str_starts_with( $statement, 'UPDATE ' ) && str_contains( $statement, 'delivery_engine_operation_records' ) && str_contains( $statement, 'completion_json =' ) };
			if ( null === $failure_index && $target ) { $failure_index = $index; }
		}
		self::assertNotNull( $hold_index, 'The rollback proof must stage actual budget holds.' ); self::assertNotNull( $failure_index, 'The injected SQL failure must be reached.' ); self::assertLessThan( $failure_index, $hold_index, 'Holds must precede the targeted failure.' );
	}
	private function control( string $state ): void { $json = EmergencyControlState::record_json( 1, $state, 2, 'enabled' === $state ? 'resume_verified' : 'operator_pause', 9, Factory::NOW ); $statement = $this->database->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value) VALUES ('cetech_de_checkout_control_v1',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" ); self::assertNotFalse( $statement ); self::assertTrue( $statement->bind_param( 's', $json ) ); self::assertTrue( $statement->execute() ); $statement->close(); }

	public function test_complete_two_component_issue_and_exact_replay_share_one_atomic_receipt(): void {
		$stack = $this->stack(); $result = $this->issue( $stack, $this->token() ); $this->assert_accepted( $result );
		self::assertCount( 1, $this->rows( 'delivery_quotes' ) ); self::assertCount( 1, $this->rows( 'operation_records' ) ); self::assertCount( 1, $this->rows( 'operation_changes' ) ); self::assertCount( 2, $this->rows( 'promotion_budget_reservations' ) );
		$account = FixedPromotionBudgetRecordCodec::account_from_row( $this->rows( 'promotion_budget_accounts' )[0] ); self::assertSame( '6.00', $account->held()->amount() ); self::assertTrue( $account->spent()->zero() );
		$record = $this->rows( 'operation_records' )[0]; self::assertSame( 'accepted', $record['state'] ); self::assertSame( '2', (string) $record['operation_version'] ); self::assertSame( $result->command->intent()->fingerprint(), $record['intent_hash'] );
		$event = json_decode( $this->rows( 'operation_changes' )[0]['event_json'], true, 16, JSON_THROW_ON_ERROR ); self::assertSame( [ 'quote', 'admission', 'promotion_budget' ], $event['changed_fields'] ); self::assertSame( 2, $event['target']['allocation_count'] ); self::assertSame( $result->command->allocation_digest(), $event['target']['allocation_digest'] );
		$admission = array_values( array_filter( $this->rows( 'delivery_quote_budget_windows' ), static fn( array $row ): bool => 'admission' === $row['slot_kind'] ) )[0]; self::assertSame( 'consumed', $admission['lease_state'] ); self::assertSame( $result->quote->header()->id()->value(), $admission['consumed_quote_uuid'] );
		// C03 records its completion with a later independent SQL clock than the final effect fence.
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET completed_at=DATE_ADD(completed_at,INTERVAL 1 MICROSECOND),updated_at=DATE_ADD(updated_at,INTERVAL 1 MICROSECOND) WHERE state='accepted'" );
		$before = $this->effects(); $captures = $this->fixture['provider']->captures; $replay = $stack->service->reconcile( $result->command, RequestContext::create() ); $this->assert_accepted( $replay ); self::assertTrue( $replay->attempt->replayed ); self::assertSame( $before, $this->effects() ); self::assertSame( $captures, $this->fixture['provider']->captures ); self::assertSame( $result->quote->header()->to_private_json(), $replay->quote->header()->to_private_json() );
		// A canonical new secret/reference under the same intent must never turn accepted history into disclosure.
		$packet = $result->command->to_recovery_array(); $reference = QuoteReference::generate( $result->command->header()->id() ); $header = QuoteHeader::issue( $reference->id(), F::owner(), $result->command->context(), $result->command->terms(), $result->command->header()->created_at(), $result->command->header()->namespace_hashes(), $result->command->header()->profile(), 1, $reference ); $packet['header_json'] = $header->to_private_json(); $packet['reference'] = $reference->public_fields();
		$tampered = $stack->service->reconcile( FixedPromotionQuoteIssueCommand::from_recovery_array( $packet ), RequestContext::create() ); self::assertSame( 'unconfirmed', $tampered->attempt->outcome->state ); self::assertNull( $tampered->quote ); self::assertSame( $before, $this->effects() ); self::assertSame( $captures, $this->fixture['provider']->captures );
	}
	public function test_shared_pool_aggregate_exhaustion_refuses_before_the_first_ledger_write(): void {
		$this->provision( '3.00' ); $stack = $this->stack(); $token = $this->token(); $lease = $this->lease( $stack, $token ); $accounts = $this->rows( 'promotion_budget_accounts' ); $admissions = $this->rows( 'delivery_quote_budget_windows' );
		$result = $stack->service->issue_admitted( $stack->original( $token ), $lease, $stack->inputs(), RequestContext::create(), static fn(): bool => true ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->assert_no_effect( $accounts, $admissions );
		foreach ( $stack->physical->transports as $transport ) { self::assertSame( [], array_values( array_filter( $transport->sql, static fn( string $sql ): bool => 1 === preg_match( '/\A(?:INSERT INTO|UPDATE|DELETE FROM) /', $sql ) && str_contains( $sql, 'promotion_budget_' ) ) ) ); }
	}
	public function test_quote_insert_failure_rolls_back_holds_admission_and_receipt(): void { $this->fail_effect( 'quote' ); }
	public function test_admission_compare_and_swap_miss_rolls_back_the_complete_effect_unit(): void {
		$stack = $this->stack( configure: function( Transport $transport ): void { $done = false; $transport->before = function( string $sql, Transport $owner ) use ( &$done ): void { if ( $done || ! str_starts_with( $sql, 'UPDATE ' ) || ! str_contains( $sql, 'delivery_engine_delivery_quote_budget_windows' ) || ! str_contains( $sql, "'consumed'" ) ) { return; } $done = true; self::assertTrue( $owner->native_execute( "UPDATE `{$this->prefix}delivery_engine_delivery_quote_budget_windows` SET revision=revision+1 WHERE slot_kind='admission'" )->acknowledged ); }; } );
		$token = $this->token(); $lease = $this->lease( $stack, $token ); $accounts = $this->rows( 'promotion_budget_accounts' ); $admissions = $this->rows( 'delivery_quote_budget_windows' ); $result = $stack->service->issue_admitted( $stack->original( $token ), $lease, $stack->inputs(), RequestContext::create(), static fn(): bool => true ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->assert_no_effect( $accounts, $admissions ); self::assertGreaterThan( 0, $stack->physical->transports[0]->quote_writes );
	}
	public function test_audit_append_failure_rolls_back_holds_quote_and_consumed_admission(): void { $this->fail_effect( 'audit' ); }
	public function test_completion_write_failure_rolls_back_holds_quote_admission_and_audit(): void { $this->fail_effect( 'completion' ); }
	public function test_effect_commit_lost_ack_reconciles_in_a_fresh_process_without_capture_or_double_hold(): void {
		$directory = OperationProofBarrier::directory(); $this->directories[] = $directory; $recovery_file = $directory . '/original-recovery.json';
		$first = true; $stack = $this->stack( configure: static function( Transport $transport ) use ( &$first ): void { if ( $first ) { $transport->fault_commit = 2; $transport->commit_fault = 'lost_ack'; $first = false; } } ); $result = $this->issue( $stack, $this->token(), static function( FixedPromotionQuoteIssueCommand $recovery ) use ( $recovery_file ): bool { self::assertNull( $recovery->lease() ); $bytes = json_encode( $recovery->to_recovery_array(), JSON_THROW_ON_ERROR ); if ( strlen( $bytes ) !== file_put_contents( $recovery_file, $bytes, LOCK_EX ) || ! chmod( $recovery_file, 0600 ) ) { return false; } return $bytes === file_get_contents( $recovery_file ); } ); self::assertSame( 'unconfirmed', $result->attempt->outcome->state ); self::assertNull( $result->quote ); self::assertNotNull( $result->command ); self::assertTrue( $stack->physical->sessions[0]->is_retired() );
		self::assertSame( 'accepted', $this->rows( 'operation_records' )[0]['state'] ); self::assertCount( 2, $this->rows( 'promotion_budget_reservations' ) ); $before = $this->effects();
		self::assertSame( $result->command->to_recovery_array(), json_decode( (string) file_get_contents( $recovery_file ), true, 32, JSON_THROW_ON_ERROR ) );
		$process = new OperationProofProcess( [ __DIR__ . '/promotion-quote-issue-process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'recovery_file' => $recovery_file ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $process; $out = $process->finish();
		self::assertNotSame( getmypid(), $out['process_id'] ); self::assertSame( 'accepted', $out['state'] ); self::assertTrue( $out['replayed'] ); self::assertSame( 0, $out['eligibility_proofs'] ); self::assertSame( 0, $out['effect_writes'] ); self::assertSame( $result->command->header()->to_private_json(), $out['header_json'] );
		$after = $this->effects(); self::assertSame( 'pending', $before['operation_records'][0]['publication_state'] ); self::assertSame( 'published', $after['operation_records'][0]['publication_state'] ); self::assertSame( (int) $before['operation_records'][0]['row_version'] + 1, (int) $after['operation_records'][0]['row_version'] );
		foreach ( [ 'publication_state', 'row_version', 'updated_at' ] as $field ) { unset( $before['operation_records'][0][$field], $after['operation_records'][0][$field] ); } self::assertSame( $before, $after );
	}
	public function test_account_mapping_and_deadline_changes_are_original_intent_conflicts(): void {
		$stack = $this->stack(); $accepted = $this->issue( $stack, $this->token() ); $this->assert_accepted( $accepted ); $before = $this->effects();
		foreach ( [ 'mapping', 'deadline' ] as $kind ) { $packet = $accepted->command->to_recovery_array(); if ( 'mapping' === $kind ) { $packet['original_issue']['account_slots'][0]['account_key'] = Q::digest( 'other owned account mapping' ); } else { $packet['original_issue']['hold_until'] = Q::time()->plus_seconds( 44 )->sql(); } $changed = FixedPromotionQuoteIssueCommand::from_recovery_array( $packet ); self::assertSame( $accepted->command->identity->namespace_digest(), $changed->identity->namespace_digest() ); self::assertNotSame( $accepted->command->intent()->fingerprint(), $changed->intent()->fingerprint() ); $result = $stack->service->reconcile( $changed, RequestContext::create() ); self::assertSame( 'rejected', $result->attempt->outcome->state ); self::assertSame( 'intent_conflict', $result->attempt->outcome->error?->code ); self::assertNull( $result->quote ); self::assertSame( $before, $this->effects() ); }
	}
	public function test_expired_lease_deadline_and_a_stalled_final_preparation_leave_no_effects(): void {
		foreach ( [ 'deadline', 'lease', 'stalled_prepare' ] as $kind ) {
			$stack = $this->stack( 'lease' === $kind ? Factory::NOW + 60 : ( 'deadline' === $kind ? Factory::NOW + 45 : Factory::NOW ) );
			if ( 'stalled_prepare' === $kind ) { $stack->eligibility->on_proof = static function( $session, int $proof ) use ( $stack ): void { if ( 3 === $proof ) { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + 45 ) )->acknowledged ); } }; }
			$token = $this->token(); $lease = $this->lease( $stack, $token ); $accounts = $this->rows( 'promotion_budget_accounts' ); $admissions = $this->rows( 'delivery_quote_budget_windows' ); $result = $stack->service->issue_admitted( $stack->original( $token ), $lease, $stack->inputs(), RequestContext::create(), static fn(): bool => true ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted, $kind ); $this->assert_no_effect( $accounts, $admissions );
			if ( 'stalled_prepare' === $kind ) { self::assertGreaterThanOrEqual( 3, count( $stack->eligibility->owners ) ); self::assertNotEmpty( array_values( array_filter( $stack->physical->transports[0]->sql, static fn( string $sql ): bool => str_starts_with( $sql, 'INSERT INTO ' ) && str_contains( $sql, 'promotion_budget_reservations' ) ) ) ); }
		}
	}
	public function test_current_pause_and_unpublished_installation_refuse_without_consuming_admission(): void {
		foreach ( [ 'pause', 'publication' ] as $kind ) { $stack = $this->stack(); $token = $this->token(); $lease = $this->lease( $stack, $token ); if ( 'pause' === $kind ) { $this->control( 'checkout_suspended' ); } else { A::publication( $this->database, $this->prefix, 'running' ); } $accounts = $this->rows( 'promotion_budget_accounts' ); $admissions = $this->rows( 'delivery_quote_budget_windows' ); $result = $stack->service->issue_admitted( $stack->original( $token ), $lease, $stack->inputs(), RequestContext::create(), static fn(): bool => true ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->assert_no_effect( $accounts, $admissions ); $this->control( 'enabled' ); A::publication( $this->database, $this->prefix, 'success' ); }
	}
	public function test_current_publication_lock_escapes_a_preopened_repeatable_read_snapshot(): void {
		$changed = false; $old_snapshot = null; $name = $this->database->real_escape_string( MigrationStatus::OPTION_NAME );
		$stack = $this->stack( configure: function( Transport $transport ) use ( &$changed, &$old_snapshot, $name ): void { $transport->before = function( string $sql, Transport $owner ) use ( &$changed, &$old_snapshot, $name ): void { if ( $changed || ! str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; } $changed = true; $old = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertTrue( $old->acknowledged ); $old_snapshot = $old->rows[0]['option_value']; A::publication( $this->database, $this->prefix, 'running' ); $again = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertTrue( $again->acknowledged ); self::assertSame( $old_snapshot, $again->rows[0]['option_value'] ); }; } );
		$token = $this->token(); $lease = $this->lease( $stack, $token ); $accounts = $this->rows( 'promotion_budget_accounts' ); $admissions = $this->rows( 'delivery_quote_budget_windows' ); $result = $stack->service->issue_admitted( $stack->original( $token ), $lease, $stack->inputs(), RequestContext::create(), static fn(): bool => true ); self::assertTrue( $changed ); self::assertSame( 'success', unserialize( $old_snapshot, [ 'allowed_classes' => false ] )['status'] ); self::assertNotSame( true, $result->attempt->outcome->mutation_accepted ); $this->assert_no_effect( $accounts, $admissions );
	}
	public function test_every_effect_uses_one_real_owner_and_existing_default_format_three_paths_stay_off(): void {
		$stack = $this->stack(); $token = $this->token(); $staged = false; $result = $this->issue( $stack, $token, static function( FixedPromotionQuoteIssueCommand $recovery ) use ( $stack, &$staged ): bool { self::assertNull( $recovery->lease() ); self::assertSame( [], $stack->physical->transports ); $staged = true; return true; } ); $this->assert_accepted( $result ); self::assertTrue( $staged );
		self::assertNotEmpty( $stack->eligibility->owners ); self::assertNotEmpty( $stack->budget_owners ); foreach ( [ ...$stack->eligibility->owners, ...$stack->budget_owners ] as $owner ) { self::assertSame( $stack->factory->sessions[0], $owner ); }
		$effect = $stack->physical->transports[0]; self::assertGreaterThan( 0, $effect->quote_writes ); self::assertGreaterThan( 0, $effect->budget_writes ); self::assertSame( 1, $effect->audit_appends ); self::assertSame( 2, $effect->sent_commits ); foreach ( array_slice( $stack->physical->transports, 1 ) as $other ) { self::assertSame( 0, $other->quote_writes + $other->budget_writes + $other->audit_appends ); }
		$before = $this->effects(); $closed = $this->factory(); $legacy = new QuoteDurableService( $closed, new QuoteProviderRegistry( [ $this->fixture['provider'] ] ), static fn(): bool => true ); self::assertSame( 'rejected', $legacy->issue( $stack->original( $token ), QuoteAdmissionAttempt::generate(), RequestContext::create() )->attempt->outcome->state ); self::assertSame( 'rejected', $legacy->accept( F::owner(), $result->command->reference(), $result->command->header(), $result->command->context(), RequestContext::create() )->attempt->outcome->state ); self::assertSame( [], $closed->transports ); self::assertSame( $before, $this->effects() );
		try { ( new QuoteProviderRegistry() )->get( 'fixed_promotion_native_v1', 1, 'fixed_promotion_native_v1', 1 ); self::fail( 'No promotion provider may be registered by default.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
	}
}
