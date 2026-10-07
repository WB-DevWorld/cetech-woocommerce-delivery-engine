<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\QuotePreparationAttempt;
use CetechDeliveryEngine\Application\DeliveryQuote\QuotePreparationGate;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteIssueCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuotePreparationCommand;
use CetechDeliveryEngine\Application\DeliveryQuote\QuoteAdmissionAttempt;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteBudgetSlot;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteContext;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteOwner;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\EmergencyControl\EmergencyControlState;
use CetechDeliveryEngine\Domain\Operation\OperationCommitResult;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\DeliveryQuoteSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\EmergencyControlStore;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AdmissionGateTest.php';

/** SQLite transaction and transport-state proof only; native locking has separate SQL proofs. */
final class PreparationGateTest extends TestCase {
	private AdmissionUnitFactory $factory;
	private AdmissionUnitControl $control;
	private QuotePreparationGate $gate;
	protected function setUp(): void { $this->factory = new AdmissionUnitFactory(); $this->control = new AdmissionUnitControl( $this->factory ); $this->gate = $this->service(); }
	private function service( ?callable $authorize = null, ?callable $readiness = null ): QuotePreparationGate { return new QuotePreparationGate( $this->factory, $authorize ?? static fn(): bool => true, $readiness ?? static function(): void {}, $this->control ); }
	private function command( string $token = 'original_token', ?QuoteOwner $owner = null, ?QuoteContext $context = null ): QuotePreparationCommand {
		$hex = hash( 'sha256', $token ); $uuid = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-4' . substr( $hex, 13, 3 ) . '-8' . substr( $hex, 17, 3 ) . '-' . substr( $hex, 20, 12 );
		return QuotePreparationCommand::create( $owner ?? QuoteFixtures::owner(), $context?->digest() ?? QuoteFixtures::digest( 'cheap_native_draft' ), $uuid, 'fixture_v1', 1, 'fixture_v1', 1 );
	}
	private function counts(): array { $out = []; foreach ( $this->factory->rows() as $row ) { $out[$row['slot_kind']][] = (int) $row['attempt_count']; } return $out; }

	public function test_confirmed_grant_charges_once_and_allows_only_one_live_capture(): void {
		$command = $this->command(); $attempt = QuotePreparationAttempt::generate(); $result = $this->gate->admit( $command, $attempt );
		self::assertSame( 'preparation_allowed', $result->status ); self::assertSame( [ 'site_minute' => [ 1 ], 'session_minute' => [ 1 ], 'admission' => [ 1 ] ], $this->counts() );
		self::assertSame( $command->identity()->namespace_digest(), $result->lease->namespace_hash() ); self::assertSame( $command->intent_digest(), $result->lease->intent_digest() );
		self::assertSame( $command->owner()->digest(), $result->lease->owner_digest() ); self::assertSame( $attempt->digest(), $result->lease->server_attempt_digest() );
		self::assertSame( '2026-10-07 05:01:00.000000', $result->lease->expires_at()->sql() ); self::assertTrue( $result->lease->claim_preparation() ); self::assertFalse( $result->lease->claim_preparation() );
		self::assertSame( 'pending', $this->gate->admit( $command, $attempt )->status ); self::assertSame( 'pending', $this->gate->reconcile( $command, $attempt )->status );
		self::assertSame( 3, count( $this->factory->rows() ) ); self::assertSame( 1, $this->factory->commits ); self::assertSame( 0, $this->factory->c03_statements() );
		self::assertSame( [ 'control', 'site_minute', 'session_minute', 'admission' ], array_slice( $this->factory->lock_order, 0, 4 ) );
	}
	public function test_twenty_session_attempts_then_denial_has_no_new_lease_or_capture(): void {
		for ( $i = 0; $i < 20; ++$i ) { self::assertSame( 'preparation_allowed', $this->gate->admit( $this->command( 'token_' . $i ), QuotePreparationAttempt::generate() )->status ); }
		$before = $this->factory->rows(); $writes = $this->factory->writes; $denied = $this->gate->admit( $this->command( 'overflow' ), QuotePreparationAttempt::generate() );
		self::assertSame( 'denied', $denied->status ); self::assertSame( 'budget_exhausted', $denied->reason ); self::assertNull( $denied->lease ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes ); self::assertSame( 0, $this->factory->c03_statements() );
	}
	public function test_two_hundred_site_attempts_across_sessions_then_denial_is_atomic(): void {
		for ( $i = 0; $i < 200; ++$i ) { $owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'session_hash' => QuoteFixtures::digest( 'session_' . $i ) ] ) ); self::assertSame( 'preparation_allowed', $this->gate->admit( $this->command( 'token_' . $i, $owner ), QuotePreparationAttempt::generate() )->status ); }
		$before = $this->factory->rows(); $owner = QuoteOwner::from_array( array_replace( QuoteFixtures::owner()->facts(), [ 'session_hash' => QuoteFixtures::digest( 'overflow_session' ) ] ) );
		$result = $this->gate->admit( $this->command( 'overflow', $owner ), QuotePreparationAttempt::generate() ); self::assertSame( 'budget_exhausted', $result->reason ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( 401, count( $before ) );
	}
	public function test_lost_gate_ack_reconciles_same_live_attempt_once_without_recapture_or_budget(): void {
		$command = $this->command(); $attempt = QuotePreparationAttempt::generate(); $this->factory->commit_mode = 'lost_ack';
		$result = $this->gate->admit( $command, $attempt ); self::assertSame( 'unconfirmed', $result->status ); self::assertNull( $result->lease ); self::assertSame( 3, count( $this->factory->rows() ) );
		$rows = $this->factory->rows(); $writes = $this->factory->writes; $this->factory->commit_mode = 'acknowledged';
		self::assertSame( 'pending', $this->gate->reconcile( $command )->status );
		$resolved = $this->gate->reconcile( $command, $attempt ); self::assertSame( 'preparation_allowed', $resolved->status ); self::assertSame( $rows, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes );
		self::assertTrue( $resolved->lease->claim_preparation() ); self::assertSame( 'pending', $this->gate->reconcile( $command, $attempt )->status ); self::assertSame( 1, $this->factory->commits ); self::assertGreaterThan( 1, $this->factory->opens );
	}
	public function test_sent_commit_exception_is_unknown_not_an_unsent_denial(): void {
		$this->factory->commit_mode = 'throw_after_commit'; $attempt = QuotePreparationAttempt::generate(); $command = $this->command(); $result = $this->gate->admit( $command, $attempt );
		self::assertSame( 'unconfirmed', $result->status ); self::assertSame( 3, count( $this->factory->rows() ) ); $this->factory->commit_mode = 'acknowledged'; self::assertSame( 'preparation_allowed', $this->gate->reconcile( $command, $attempt )->status );
	}
	public function test_pause_after_lost_gate_ack_prevents_reconciled_capture(): void {
		$command = $this->command(); $attempt = QuotePreparationAttempt::generate(); $this->factory->commit_mode = 'lost_ack'; self::assertSame( 'unconfirmed', $this->gate->admit( $command, $attempt )->status );
		$this->factory->commit_mode = 'acknowledged'; $this->control->paused = true; $before = $this->factory->rows(); $result = $this->gate->reconcile( $command, $attempt );
		self::assertSame( 'denied', $result->status ); self::assertSame( 'checkout_suspended', $result->reason ); self::assertNull( $result->lease ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( 0, $this->factory->c03_statements() );
	}
	public function test_definitely_unsent_commit_rolls_back_no_lease_no_budget_and_no_implicit_retry(): void {
		$this->factory->commit_mode = 'not_sent'; $attempt = QuotePreparationAttempt::generate(); $command = $this->command(); $result = $this->gate->admit( $command, $attempt );
		self::assertSame( 'commit_not_sent', $result->reason ); self::assertSame( [], $this->factory->rows() ); $this->factory->commit_mode = 'acknowledged'; self::assertSame( 'no_admission', $this->gate->admit( $command, $attempt )->reason ); self::assertSame( [], $this->factory->rows() );
	}
	#[DataProvider( 'refused_statement' )]
	public function test_refused_counter_or_grant_rolls_back_whole_gate( int $statement ): void {
		$this->factory->fail_write = $statement; $result = $this->gate->admit( $this->command(), QuotePreparationAttempt::generate() ); self::assertSame( 'storage_unavailable', $result->reason ); self::assertSame( [], $this->factory->rows() ); self::assertSame( 0, $this->factory->commits );
	}
	public static function refused_statement(): array { return [ 'site' => [ 1 ], 'session' => [ 2 ], 'lease' => [ 3 ] ]; }
	public function test_refused_cas_keeps_previously_accepted_counters_and_grant_bytes(): void {
		$this->gate->admit( $this->command(), QuotePreparationAttempt::generate() ); $before = $this->factory->rows(); $this->factory->fail_write = $this->factory->writes + 2;
		self::assertSame( 'storage_unavailable', $this->gate->admit( $this->command( 'second' ), QuotePreparationAttempt::generate() )->reason ); self::assertSame( $before, $this->factory->rows() );
	}
	public function test_fresh_replay_after_minute_turnover_does_not_charge_or_capture(): void {
		$command = $this->command(); $first = $this->gate->admit( $command, QuotePreparationAttempt::generate() ); $before = $this->factory->rows(); $this->factory->utc = '2026-10-07 05:01:00.000000';
		$result = $this->gate->admit( $command, QuotePreparationAttempt::generate() ); self::assertSame( 'lease_expired', $result->reason ); self::assertNull( $result->lease ); self::assertSame( $before, $this->factory->rows() ); self::assertSame( '2026-10-07 05:01:00.000000', $first->lease->expires_at()->sql() );
	}
	public function test_expired_original_is_terminated_once_and_never_resurrected(): void {
		$command = $this->command(); $this->gate->admit( $command, QuotePreparationAttempt::generate() ); $this->factory->utc = '2026-10-07 05:01:00.000000'; $result = $this->gate->reconcile( $command );
		self::assertSame( 'lease_terminated', $result->reason ); $rows = $this->factory->rows(); $writes = $this->factory->writes; self::assertSame( 'lease_terminated', $this->gate->admit( $command, QuotePreparationAttempt::generate() )->reason ); self::assertSame( 'lease_terminated', $this->gate->reconcile( $command )->reason );
		self::assertSame( $rows, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes ); self::assertSame( 'preparation_allowed', $this->gate->admit( $this->command( 'explicit_new' ), QuotePreparationAttempt::generate() )->status );
	}
	public function test_original_token_with_changed_intent_conflicts_without_new_budget(): void {
		$this->gate->admit( $this->command(), QuotePreparationAttempt::generate() ); $rows = $this->factory->rows(); $context = QuoteFixtures::context( [ 'selection_digest' => QuoteFixtures::digest( 'changed' ) ] );
		$result = $this->gate->admit( $this->command( context: $context ), QuotePreparationAttempt::generate() ); self::assertSame( 'intent_conflict', $result->reason ); self::assertSame( $rows, $this->factory->rows() );
	}
	public function test_authorization_is_required_before_any_lookup_and_rechecked_before_disclosure(): void {
		$result = $this->service( static fn(): bool => false )->admit( $this->command(), QuotePreparationAttempt::generate() ); self::assertSame( 'not_authorized', $result->reason ); self::assertSame( 0, $this->factory->opens );
		$result = $this->service( static fn(): bool => false )->inspect( $this->command() ); self::assertSame( 'not_authorized', $result->reason ); self::assertSame( 0, $this->factory->opens );
		$checks = 0; $gate = $this->service( static function() use ( &$checks ): bool { return ++$checks < 7; } ); $result = $gate->admit( $this->command(), QuotePreparationAttempt::generate() );
		self::assertSame( 'not_authorized', $result->reason ); self::assertNull( $result->lease ); self::assertSame( 3, count( $this->factory->rows() ) );
	}
	public function test_revocation_before_mutation_rolls_back_and_runs_no_provider(): void {
		$checks = 0; $gate = $this->service( static function() use ( &$checks ): bool { return ++$checks < 4; } ); $result = $gate->admit( $this->command(), QuotePreparationAttempt::generate() );
		self::assertSame( 'not_authorized', $result->reason ); self::assertNull( $result->lease ); self::assertSame( [], $this->factory->rows() ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_paused_or_unknown_control_denies_before_any_budget_lookup(): void {
		$this->control->paused = true; $result = $this->gate->admit( $this->command(), QuotePreparationAttempt::generate() ); self::assertSame( 'checkout_suspended', $result->reason ); self::assertSame( [], $this->factory->rows() ); self::assertSame( [ 'control' ], $this->factory->lock_order );
		$this->control->paused = false; $this->control->failed = true; self::assertSame( 'storage_unavailable', $this->gate->admit( $this->command( 'unknown' ), QuotePreparationAttempt::generate() )->reason ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_wait_crossing_fixed_window_refuses_without_recapturing_minute(): void {
		$this->factory->after_admission_read = function(): void { $this->factory->utc = '2026-10-07 05:01:00.000000'; }; $result = $this->gate->admit( $this->command(), QuotePreparationAttempt::generate() ); self::assertSame( 'storage_unavailable', $result->reason ); self::assertSame( [], $this->factory->rows() );
	}
	public function test_rollback_or_retirement_uncertainty_withholds_capture(): void {
		$this->factory->commit_mode = 'not_sent'; $this->factory->rollback_ok = false; self::assertSame( 'unconfirmed', $this->gate->admit( $this->command(), QuotePreparationAttempt::generate() )->status );
		$this->factory = new AdmissionUnitFactory(); $this->control = new AdmissionUnitControl( $this->factory ); $this->factory->retire_ok = false; $gate = $this->service(); $attempt = QuotePreparationAttempt::generate(); $command = $this->command(); self::assertSame( 'unconfirmed', $gate->admit( $command, $attempt )->status ); self::assertSame( 3, count( $this->factory->rows() ) );
		$this->factory->retire_ok = true; self::assertSame( 'preparation_allowed', $gate->reconcile( $command, $attempt )->status );
	}
	public function test_ambient_owner_is_not_rolled_back_or_used(): void {
		$this->factory->ambient = true; self::assertSame( 'denied', $this->gate->admit( $this->command(), QuotePreparationAttempt::generate() )->status ); self::assertSame( 0, $this->factory->rollbacks ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_corrupt_read_and_failed_readiness_never_become_absence(): void {
		$this->factory->fail_reads = true; self::assertSame( 'storage_unavailable', $this->gate->admit( $this->command(), QuotePreparationAttempt::generate() )->reason ); self::assertSame( [], $this->factory->rows() );
		$this->factory->fail_reads = false; $gate = $this->service( readiness: static function(): void { throw new \RuntimeException( 'private database message' ); } ); self::assertSame( [ 'status' => 'denied', 'reason' => 'storage_unavailable' ], $gate->admit( $this->command( 'readiness' ), QuotePreparationAttempt::generate() )->safe() ); self::assertSame( 0, $this->factory->writes );
	}
	public function test_capture_capability_cannot_be_serialized_cloned_or_transferred_to_other_attempt(): void {
		$command = $this->command(); $attempt = QuotePreparationAttempt::generate(); $grant = $this->gate->admit( $command, $attempt ); $other = QuotePreparationAttempt::generate(); self::assertSame( 'pending', $this->gate->reconcile( $command, $other )->status );
		foreach ( [ $attempt, $grant->lease, $grant ] as $private ) { try { json_encode( $private, JSON_THROW_ON_ERROR ); self::fail( 'Private carrier was serialized.' ); } catch ( \LogicException ) { self::assertTrue( true ); } try { serialize( $private ); self::fail( 'Private capability was persisted.' ); } catch ( \LogicException ) { self::assertTrue( true ); } }
		try { $copy = clone $attempt; self::fail( 'Capability copied.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
		self::assertTrue( $grant->lease->claim_preparation() ); self::assertFalse( $attempt->claim_preparation() );
	}

	public function test_handoff_binds_full_material_once_without_rewriting_early_intent_or_charging_again(): void {
		$command = $this->command(); $attempt = QuotePreparationAttempt::generate(); $result = $this->gate->admit( $command, $attempt ); $rows = $this->factory->rows(); $writes = $this->factory->writes;
		self::assertTrue( $result->lease->claim_preparation() );
		$final = QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $command->original_token() );
		$lease = $result->lease->bind_issue( $final );
		self::assertSame( $command->identity()->namespace_digest(), $final->identity()->namespace_digest() ); self::assertNotSame( $command->intent_digest(), $final->intent_digest() );
		self::assertSame( $command->intent_digest(), $lease->admission_intent_digest() ); self::assertSame( $rows, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes );
		self::assertSame( $attempt->digest(), $lease->server_attempt_digest() ); self::assertTrue( $lease->matches( $final ) ); self::assertTrue( $lease->claim_capture() ); self::assertFalse( $lease->claim_capture() );
		$changed = QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context( [ 'selection_digest' => QuoteFixtures::digest( 'different_material' ) ] ), 'fixture_v1', 1, 'fixture_v1', 1, $command->original_token() );
		self::assertFalse( $lease->matches( $changed ) );
		try { $result->lease->bind_issue( $changed ); self::fail( 'Second handoff was allowed.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		try { QuoteAdmissionAttempt::from_preparation( $attempt, $final ); self::fail( 'Factory minted a second capture.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		self::assertSame( 'pending', $this->gate->inspect( $command )->status ); self::assertSame( 0, $this->factory->c03_statements() );
	}
	public function test_binding_requires_claimed_preparation_and_exact_owner_namespace_and_provider(): void {
		$command = $this->command(); $grant = $this->gate->admit( $command, QuotePreparationAttempt::generate() );
		$final = QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $command->original_token() );
		try { $grant->lease->bind_issue( $final ); self::fail( 'Unstarted preparation was transferred.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		self::assertTrue( $grant->lease->claim_preparation() );
		foreach ( [
			QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context(), 'other_provider', 1, 'fixture_v1', 1, $command->original_token() ),
			QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $this->command( 'other_token' )->original_token() ),
		] as $wrong ) { try { $grant->lease->bind_issue( $wrong ); self::fail( 'Wrong original request was transferred.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); } }
		self::assertTrue( $grant->lease->bind_issue( $final )->matches( $final ) );
	}
	public function test_original_envelope_roundtrip_reconstructs_status_without_a_live_capability(): void {
		$command = $this->command(); $this->gate->admit( $command, QuotePreparationAttempt::generate() ); $rows = $this->factory->rows();
		$restored = QuotePreparationCommand::from_private_array( $command->to_private_array() );
		self::assertSame( $command->identity()->namespace_digest(), $restored->identity()->namespace_digest() ); self::assertSame( $command->intent_digest(), $restored->intent_digest() );
		$replay = $this->gate->admit( $restored, QuotePreparationAttempt::generate() ); self::assertSame( 'pending', $replay->status ); self::assertNull( $replay->lease ); self::assertSame( $rows, $this->factory->rows() );
		try { json_encode( $restored, JSON_THROW_ON_ERROR ); self::fail( 'Original envelope leaked.' ); } catch ( \LogicException ) { self::assertTrue( true ); }
	}
	public function test_key_epoch_rotation_requires_current_authority_before_original_lookup(): void {
		$command = $this->command(); $this->gate->admit( $command, QuotePreparationAttempt::generate() ); $opens = $this->factory->opens;
		$current = QuoteOwner::from_array( array_replace( $command->owner()->facts(), [ 'key_epoch' => 'native_quote_v1:new_epoch' ] ) );
		$gate = $this->service( static fn( QuoteOwner $owner ): bool => $owner->equals( $current ) );
		self::assertSame( 'not_authorized', $gate->reconcile( $command )->reason ); self::assertSame( $opens, $this->factory->opens );
	}
	public function test_unknown_or_malformed_original_envelopes_refuse_before_gate_io(): void {
		$original = $this->command()->to_private_array();
		foreach ( [ array_replace( $original, [ 'format_version' => 2 ] ), array_replace( $original, [ 'original_token' => 'unbounded_non_uuid_token' ] ), array_replace( $original, [ 'draft_digest' => str_repeat( 'g', 64 ) ] ), $original + [ 'untrusted_material' => [] ] ] as $invalid ) {
			try { QuotePreparationCommand::from_private_array( $invalid ); self::fail( 'Invalid preparation envelope was accepted.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		}
		self::assertSame( 0, $this->factory->opens );
	}
	public function test_known_preparation_failure_terminates_without_refund_or_later_handoff(): void {
		$command = $this->command(); $grant = $this->gate->admit( $command, QuotePreparationAttempt::generate() ); self::assertTrue( $grant->lease->claim_preparation() );
		$failed = $this->gate->terminate( $command, $grant->lease ); self::assertSame( 'lease_terminated', $failed->reason ); self::assertSame( [ 'site_minute' => [ 1 ], 'session_minute' => [ 1 ], 'admission' => [ 1 ] ], $this->counts() );
		self::assertSame( 'terminated', $this->factory->rows()[2]['lease_state'] ); self::assertSame( 2, (int) $this->factory->rows()[2]['revision'] ); self::assertSame( 'lease_terminated', $this->gate->admit( $command, QuotePreparationAttempt::generate() )->reason );
		$final = QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $command->original_token() );
		try { $grant->lease->bind_issue( $final ); self::fail( 'Failed preparation was issued.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		self::assertSame( 0, $this->factory->c03_statements() );
	}
	public function test_termination_never_changes_a_handoff_that_may_have_a_c03_outcome(): void {
		$command = $this->command(); $grant = $this->gate->admit( $command, QuotePreparationAttempt::generate() ); $grant->lease->claim_preparation();
		$final = QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $command->original_token() ); $lease = $grant->lease->bind_issue( $final ); $rows = $this->factory->rows(); $writes = $this->factory->writes; $opens = $this->factory->opens;
		self::assertSame( 'pending', $this->gate->terminate( $command, $grant->lease )->status ); self::assertSame( $rows, $this->factory->rows() ); self::assertSame( $writes, $this->factory->writes ); self::assertSame( $opens, $this->factory->opens ); self::assertTrue( $lease->claim_capture() );
	}
	public function test_unknown_termination_ack_closes_live_preparation_and_requires_original_status_read(): void {
		$command = $this->command(); $grant = $this->gate->admit( $command, QuotePreparationAttempt::generate() ); $grant->lease->claim_preparation(); $this->factory->commit_mode = 'lost_ack';
		self::assertSame( 'unconfirmed', $this->gate->terminate( $command, $grant->lease )->status ); self::assertSame( 'terminated', $this->factory->rows()[2]['lease_state'] ); self::assertFalse( $grant->lease->claim_preparation() );
		$final = QuoteIssueCommand::create( $command->owner(), QuoteFixtures::context(), 'fixture_v1', 1, 'fixture_v1', 1, $command->original_token() );
		try { $grant->lease->bind_issue( $final ); self::fail( 'Uncertain termination retained issue capability.' ); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); }
		$this->factory->commit_mode = 'acknowledged'; self::assertSame( 'lease_terminated', $this->gate->inspect( $command )->reason );
	}
	public function test_refused_termination_retains_durable_slot_but_never_restarts_closed_preparation(): void {
		$command = $this->command(); $grant = $this->gate->admit( $command, QuotePreparationAttempt::generate() ); $grant->lease->claim_preparation(); $rows = $this->factory->rows(); $this->factory->fail_write = $this->factory->writes + 1;
		self::assertSame( 'storage_unavailable', $this->gate->terminate( $command, $grant->lease )->reason ); self::assertSame( $rows, $this->factory->rows() ); self::assertFalse( $grant->lease->claim_preparation() ); self::assertSame( 'pending', $this->gate->inspect( $command )->status );
	}

}
