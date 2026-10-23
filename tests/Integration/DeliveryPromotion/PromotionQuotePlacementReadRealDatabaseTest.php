<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Integration\DeliveryPromotion;

use CetechDeliveryEngine\Application\DeliveryPromotion\FixedPromotionBudgetAllocationService;
use CetechDeliveryEngine\Application\DeliveryQuote\{FixedPromotionQuoteAcceptCommand, QuotePreparationAttempt, QuotePreparationCommand, QuotePreparationGate};
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Domain\Contracts\RequestContext;
use CetechDeliveryEngine\Domain\DeliveryPromotion\{FixedPromotionBudgetReservation, FixedPromotionBudgetState};
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteId, QuoteOwner};
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Infrastructure\Persistence\{EmergencyControlStore, FixedPromotionBudgetRecordCodec, SqlFixedPromotionBudgetStore};
use CetechDeliveryEngine\Tests\Support\DataLifecycle\DataLifecycleProofDatabase as DB;
use CetechDeliveryEngine\Tests\Support\DeliveryCommercial\{FixedPromotionQuoteFixture as F, PromotionBudgetSqlFixture as B};
use CetechDeliveryEngine\Tests\Support\DeliveryPromotion\{PromotionBudgetAllocationSqlFixture as A, PromotionQuoteAcceptSqlStack as AcceptStack, PromotionQuoteIssueSqlFactory, PromotionQuoteIssueSqlPorts as Ports, PromotionQuoteIssueSqlStack as IssueStack, PromotionQuotePlacementReadSqlStack as Stack};
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteFixtures as Q, QuoteLifecycleProofFactory as Factory, QuoteLifecycleProofTransport as Transport};
use CetechDeliveryEngine\Tests\Support\Operation\{OperationProofBarrier, OperationProofProcess};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionQuotePlacementReadSqlStack.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryPromotion/PromotionBudgetAllocationSqlFixture.php';

/** Twelve physical accepted-promotion observation cases. Source/native/eligibility ports are synthetic, not Woo certification. */
#[Group( 'promotion-commercial-real-db' )]
final class PromotionQuotePlacementReadRealDatabaseTest extends TestCase {

	private \mysqli $database;
	private string $prefix;
	private array $fixture;
	private array $factories = [];
	private array $processes = [];
	private array $directories = [];

	protected function setUp(): void {
		$this->database = DB::connect(); $this->prefix = DB::prefix();
		DB::install( $this->database, $this->prefix ); B::install( $this->database, $this->prefix ); A::publication( $this->database, $this->prefix, 'success' );
		$session = ( new PromotionQuoteIssueSqlFactory( $this->factory() ) )->open();
		self::assertTrue( $session->begin() ); $this->fixture = Ports::capture( $session ); self::assertTrue( $session->rollback() ); self::assertTrue( $session->retire() );
		// Both 3.00 claims share this exact 6.00 pool; zero free balance must not invalidate held money.
		$state = FixedPromotionBudgetState::create( 1, $this->fixture['slots'][0]['account_key'], F::rule()->private_facts()['funding_source'], B::money( '6.00' ) );
		$values = FixedPromotionBudgetRecordCodec::account_values( $state );
		$columns = implode( ',', array_map( static fn( string $key ): string => "`{$key}`", array_keys( $values ) ) );
		$quoted = array_map( fn( mixed $value ): string => "'" . $this->database->real_escape_string( (string) $value ) . "'", array_values( $values ) );
		DB::execute( $this->database, "INSERT INTO `{$this->prefix}delivery_engine_promotion_budget_accounts` ({$columns}) VALUES (" . implode( ',', $quoted ) . ')' );
	}

	protected function tearDown(): void {
		foreach ( $this->processes as $process ) { $process->kill(); }
		foreach ( $this->factories as $factory ) { $factory->close_all(); }
		foreach ( $this->directories as $directory ) { OperationProofBarrier::cleanup( $directory ); }
		if ( isset( $this->database, $this->prefix ) ) { DB::cleanup( $this->database, $this->prefix ); $this->database->close(); }
	}

	private function factory( int $clock = Factory::NOW ): Factory {
		$factory = new Factory( $this->prefix ); $factory->clock = $clock; $this->factories[] = $factory; return $factory;
	}
	private function stack( int $clock = Factory::NOW + 2, ?callable $configure = null, int $site_id = 1 ): Stack {
		$stack = new Stack( $this->prefix, $this->fixture, $clock, $configure, $site_id ); $this->factories[] = $stack->physical; return $stack;
	}
	private function seed(): FixedPromotionQuoteAcceptCommand {
		$issue = new IssueStack( $this->prefix, $this->fixture ); $this->factories[] = $issue->physical; $token = QuoteId::generate()->value(); $original = $issue->original( $token );
		$gate = new QuotePreparationGate( $this->factory(), static fn( QuoteOwner $owner, string $operation ): bool => 1 === $owner->site_id() && 'delivery_quote.issue' === $operation, null, new EmergencyControlStore( static fn(): bool => false ) );
		$admission = $gate->admit( QuotePreparationCommand::fixed_promotion( F::owner(), Q::digest( 'E12 owned synthetic server draft' ), $token ), QuotePreparationAttempt::generate() );
		self::assertSame( 'preparation_allowed', $admission->status ); self::assertNotNull( $admission->lease ); self::assertTrue( $admission->lease->claim_preparation() );
		$issued = $issue->service->issue_admitted( $original, $admission->lease->bind_issue( $original ), $issue->inputs(), RequestContext::create(), static fn(): bool => true );
		self::assertSame( 'accepted', $issued->attempt->outcome->state ); self::assertNotNull( $issued->command );
		$command = FixedPromotionQuoteAcceptCommand::from_issue( $issued->command );
		$accept = new AcceptStack( $this->prefix, $this->fixture ); $this->factories[] = $accept->physical;
		$accepted = $accept->service->accept( $command, $accept->inputs(), RequestContext::create() );
		self::assertSame( 'accepted', $accepted->attempt->outcome->state ); self::assertNotNull( $accepted->quote ); self::assertSame( 2, $accepted->quote->revision() );
		return $command;
	}
	private function rows( string $suffix ): array {
		$r = $this->database->query( "SELECT * FROM `{$this->prefix}delivery_engine_{$suffix}` ORDER BY id" );
		if ( ! $r instanceof \mysqli_result ) { throw new \RuntimeException( 'Owned placement-read census failed.' ); } return $r->fetch_all( MYSQLI_ASSOC );
	}
	private function census(): array {
		$out = [];
		foreach ( [ 'delivery_quotes', 'promotion_budget_accounts', 'promotion_budget_reservations', 'delivery_quote_budget_windows', 'operation_records', 'operation_changes' ] as $suffix ) { $out[$suffix] = $this->rows( $suffix ); }
		$out['options'] = DB::options( $this->database, $this->prefix ); return $out;
	}
	private function unchanged( array $before, Stack $stack ): void { self::assertSame( $before, $this->census() ); self::assertSame( [], $stack->effect_sql() ); }
	private function unavailable( FixedPromotionQuoteAcceptCommand $command, Stack $stack, array $before ): void {
		$result = $stack->reader->current( $command, $stack->inputs(), RequestContext::create() ); self::assertSame( 'unavailable', $result->status ); self::assertNull( $result->evidence ); $this->unchanged( $before, $stack );
	}
	private function allocation( FixedPromotionQuoteAcceptCommand $command, string $action ): void {
		$session = $this->factory( Factory::NOW + 2 )->open(); self::assertTrue( $session->begin() );
		$service = new FixedPromotionBudgetAllocationService( new SqlFixedPromotionBudgetStore(), static fn(): bool => true );
		$result = 'commit' === $action ? $service->commit_all( $session, $command->allocation_set(), Q::time()->plus_seconds( 2 ) ) : $service->release_all( $session, $command->allocation_set(), Q::time()->plus_seconds( 2 ) );
		self::assertCount( 2, $result ); self::assertSame( OperationCommitResult::Acknowledged, $session->commit() ); self::assertTrue( $session->retire() );
	}
	private function control( string $state ): void {
		$json = EmergencyControlState::record_json( 1, $state, 2, 'enabled' === $state ? 'resume_verified' : 'operator_pause', 9, Factory::NOW );
		$s = $this->database->prepare( "INSERT INTO `{$this->prefix}options` (option_name,option_value) VALUES ('cetech_de_checkout_control_v1',?) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)" );
		self::assertNotFalse( $s ); self::assertTrue( $s->bind_param( 's', $json ) ); self::assertTrue( $s->execute() ); $s->close();
	}

	public function test_exact_held_shared_pool_yields_detached_evidence_without_any_effect_sql(): void {
		$command = $this->seed(); $before = $this->census(); $captures = $this->fixture['provider']->captures; $reads = $this->fixture['source']->reads; $stack = $this->stack();
		$account = FixedPromotionBudgetRecordCodec::account_from_row( $before['promotion_budget_accounts'][0] ); self::assertSame( '0.00', $account->remaining()->amount() );
		$result = $stack->reader->current( $command, $stack->inputs(), RequestContext::create() );
		self::assertSame( 'ready', $result->status ); self::assertNull( $result->reason ); self::assertNotNull( $result->evidence );
		self::assertSame( $command->header()->to_private_json(), $result->evidence->header()->to_private_json() ); self::assertSame( $command->context()->to_private_json(), $result->evidence->context()->to_private_json() ); self::assertSame( $command->terms()->to_private_json(), $result->evidence->terms()->to_private_json() );
		self::assertSame( $before['delivery_quotes'][0]['accepted_at'], $result->evidence->accepted_at()->sql() ); self::assertSame( Q::time()->plus_seconds( 2 )->sql(), $result->evidence->evaluated_at()->sql() );
		self::assertSame( $captures, $this->fixture['provider']->captures ); self::assertSame( $reads, $this->fixture['source']->reads );
		self::assertCount( 1, $stack->factory->sessions ); self::assertTrue( $stack->factory->sessions[0]->is_retired() ); self::assertFalse( $stack->factory->sessions[0]->in_transaction() );
		self::assertGreaterThanOrEqual( 2, count( $stack->eligibility->owners ) ); foreach ( $stack->eligibility->owners as $owner ) { self::assertSame( $stack->factory->sessions[0], $owner ); }
		$this->unchanged( $before, $stack );
	}
	public function test_saved_recovery_is_reread_by_a_fresh_process_without_reissuing_or_reaccepting(): void {
		$command = $this->seed(); $before = $this->census(); $directory = OperationProofBarrier::directory(); $this->directories[] = $directory;
		$file = $directory . '/placement-read-recovery.json'; $bytes = json_encode( $command->to_recovery_array(), JSON_THROW_ON_ERROR ); self::assertSame( strlen( $bytes ), file_put_contents( $file, $bytes, LOCK_EX ) ); self::assertTrue( chmod( $file, 0600 ) );
		$worker = new OperationProofProcess( [ __DIR__ . '/promotion-quote-placement-read-process-worker.php', json_encode( [ 'prefix' => $this->prefix, 'command_file' => $file ], JSON_THROW_ON_ERROR ) ] ); $this->processes[] = $worker;
		$out = $worker->finish(); self::assertNotSame( getmypid(), $out['process_id'] ); self::assertSame( 'ready', $out['status'] ); self::assertSame( $command->header()->body_digest(), $out['body_digest'] ); self::assertSame( $before['delivery_quotes'][0]['accepted_at'], $out['accepted_at'] ); self::assertSame( [], $out['effect_sql'] ); self::assertTrue( $out['retired'] ); self::assertSame( 1, $out['synthetic_native_captures'] ); self::assertSame( $before, $this->census() );
	}
	public function test_released_claims_refuse_without_reallocation_or_quote_changes(): void {
		$command = $this->seed(); $this->allocation( $command, 'release' ); $this->unavailable( $command, $this->stack(), $this->census() );
	}
	public function test_committed_claims_are_history_and_do_not_become_current_placement_authority(): void {
		$command = $this->seed(); $this->allocation( $command, 'commit' ); $this->unavailable( $command, $this->stack(), $this->census() );
	}
	public function test_missing_original_claim_is_not_recreated_by_the_reader(): void {
		$command = $this->seed(); DB::execute( $this->database, "DELETE FROM `{$this->prefix}delivery_engine_promotion_budget_reservations` ORDER BY id LIMIT 1" ); $this->unavailable( $command, $this->stack(), $this->census() );
	}
	public function test_corrupt_claim_and_account_mirrors_refuse_without_repair(): void {
		$command = $this->seed();
		foreach ( [ 'promotion_budget_reservations' => 'reservation_digest', 'promotion_budget_accounts' => 'state_digest' ] as $table => $field ) {
			$row = $this->rows( $table )[0]; DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_{$table}` SET {$field}='" . Q::digest( 'E12 corrupted mirror' ) . "' WHERE id=" . (int) $row['id'] );
			$this->unavailable( $command, $this->stack(), $this->census() );
			DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_{$table}` SET {$field}='" . $row[$field] . "' WHERE id=" . (int) $row['id'] );
		}
		$row = $this->rows( 'promotion_budget_reservations' )[0]; $original = FixedPromotionBudgetRecordCodec::reservation_from_row( $row );
		foreach ( [ 44, 46 ] as $seconds ) {
			$changed = FixedPromotionBudgetReservation::from_array( array_replace( $original->private_facts(), [ 'expires_at' => Q::time()->plus_seconds( $seconds )->sql() ] ) );
			$values = FixedPromotionBudgetRecordCodec::reservation_values( $changed ); $assignments = [];
			foreach ( $values as $field => $value ) { $assignments[] = "`{$field}`='" . $this->database->real_escape_string( (string) $value ) . "'"; }
			DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_promotion_budget_reservations` SET " . implode( ',', $assignments ) . ' WHERE id=' . (int) $row['id'] );
			$this->unavailable( $command, $this->stack(), $this->census() );
		}
	}
	public function test_current_publication_refuses_a_running_migration_hidden_from_old_repeatable_read(): void {
		$command = $this->seed(); $changed = false; $old = null; $name = $this->database->real_escape_string( MigrationStatus::OPTION_NAME );
		$stack = $this->stack( configure: function( Transport $transport ) use ( &$changed, &$old, $name ): void {
			$transport->before = function( string $sql, Transport $owner ) use ( &$changed, &$old, $name ): void {
				if ( $changed || ! str_starts_with( $sql, 'SELECT option_name, CASE WHEN' ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; }
				$changed = true; $snapshot = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows[0]['option_value'];
				A::publication( $this->database, $this->prefix, 'running' ); $again = $owner->native_execute( "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" ); self::assertSame( $old, $again->rows[0]['option_value'] );
			};
		} );
		$result = $stack->reader->current( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( 'success', unserialize( $old, [ 'allowed_classes' => false ] )['status'] ); self::assertSame( 'unavailable', $result->status ); self::assertNull( $result->evidence ); self::assertSame( [], $stack->effect_sql() ); self::assertSame( 'running', unserialize( DB::row( $this->database, "SELECT option_value FROM `{$this->prefix}options` WHERE option_name='{$name}'" )['option_value'], [ 'allowed_classes' => false ] )['status'] );
	}
	public function test_current_claim_lock_refuses_a_release_hidden_from_old_repeatable_read(): void {
		$command = $this->seed(); $changed = false; $old = null;
		$stack = $this->stack( configure: function( Transport $transport ) use ( $command, &$changed, &$old ): void {
			$transport->before = function( string $sql, Transport $owner ) use ( $command, &$changed, &$old ): void {
				if ( $changed || ! str_contains( $sql, "FROM `{$this->prefix}delivery_engine_promotion_budget_accounts`" ) || ! str_contains( $sql, 'FOR UPDATE' ) ) { return; }
				$changed = true; $snapshot = $owner->native_execute( "SELECT state FROM `{$this->prefix}delivery_engine_promotion_budget_reservations` ORDER BY id" ); self::assertTrue( $snapshot->acknowledged ); $old = $snapshot->rows;
				$this->allocation( $command, 'release' ); $again = $owner->native_execute( "SELECT state FROM `{$this->prefix}delivery_engine_promotion_budget_reservations` ORDER BY id" ); self::assertSame( $old, $again->rows );
			};
		} );
		$result = $stack->reader->current( $command, $stack->inputs(), RequestContext::create() ); self::assertTrue( $changed ); self::assertSame( [ [ 'state' => 'held' ], [ 'state' => 'held' ] ], $old ); self::assertSame( 'unavailable', $result->status ); self::assertNull( $result->evidence ); self::assertSame( [], $stack->effect_sql() ); self::assertSame( [ 'released', 'released' ], array_column( $this->rows( 'promotion_budget_reservations' ), 'state' ) );
	}
	public function test_pause_changed_source_and_current_eligibility_denial_preserve_accepted_history(): void {
		$command = $this->seed();
		foreach ( [ 'pause', 'source', 'eligibility' ] as $kind ) {
			$stack = $this->stack(); if ( 'pause' === $kind ) { $this->control( 'checkout_suspended' ); } elseif ( 'source' === $kind ) { $this->fixture['source']->same = false; } else { $stack->eligibility->allow = false; }
			$this->unavailable( $command, $stack, $this->census() ); $this->fixture['source']->same = true; $this->control( 'enabled' );
		}
	}
	public function test_original_deadline_and_a_stalled_final_proof_refuse_without_extension(): void {
		$command = $this->seed(); $before = $this->census();
		foreach ( [ 'deadline', 'slow' ] as $kind ) {
			$stack = $this->stack( 'deadline' === $kind ? Factory::NOW + 45 : Factory::NOW + 2 );
			if ( 'slow' === $kind ) { $stack->eligibility->on_proof = static function( $session, int $proof ) use ( $stack ): void { if ( 2 === $proof ) { self::assertTrue( $stack->physical->transports[0]->native_execute( 'SET timestamp=' . ( Factory::NOW + 45 ) )->acknowledged ); } }; }
			$this->unavailable( $command, $stack, $before ); if ( 'slow' === $kind ) { self::assertGreaterThanOrEqual( 2, count( $stack->eligibility->owners ) ); }
		}
	}
	public function test_corrupt_original_receipt_and_cross_site_owner_never_expose_evidence(): void {
		$command = $this->seed(); DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET intent_hash='" . Q::digest( 'E12 corrupt issue receipt' ) . "' WHERE operation='delivery_quote.issue'" ); $this->unavailable( $command, $this->stack(), $this->census() );
		DB::execute( $this->database, "UPDATE `{$this->prefix}delivery_engine_operation_records` SET intent_hash='" . $command->issue()->intent()->fingerprint() . "' WHERE operation='delivery_quote.issue'" ); $this->unavailable( $command, $this->stack( site_id: 2 ), $this->census() );
	}
	public function test_lost_rollback_acknowledgement_close_failure_and_late_authorization_loss_withhold_facts(): void {
		$command = $this->seed(); $before = $this->census();
		foreach ( [ 'rollback_ack', 'close', 'authorization' ] as $kind ) {
			$stack = $this->stack( configure: static function( Transport $transport ) use ( $kind ): void { if ( 'rollback_ack' === $kind ) { $transport->lose_rollback_ack = true; } elseif ( 'close' === $kind ) { $transport->reject_close = true; } } );
			if ( 'authorization' === $kind ) { $stack->physical->configure = static function( Transport $transport ) use ( $stack ): void { $transport->after = static function( string $sql ) use ( $stack ): void { if ( 1 === preg_match( '/\AROLLBACK\b/', $sql ) ) { $stack->allow = false; } }; }; }
			$this->unavailable( $command, $stack, $before );
		}
	}
}
