<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\ServicePromise\Configuration;

use CetechDeliveryEngine\Application\ServicePromise\Configuration\{PromiseStaffDraft, PromiseStaffIntent};
use CetechDeliveryEngine\Domain\Contracts\{ContractError, OperationOutcome, RequestContext};
use CetechDeliveryEngine\Domain\Operation\{OperationAttemptResult, OperationCompletion, OperationProfile, OperationSchema};
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/StaffIntentFixtures.php';

final class PromiseStaffIntentTest extends TestCase {
	private mixed $previous;
	private StaffIntentDatabase $database;
	protected function setUp(): void { $this->previous = $GLOBALS['wpdb'] ?? null; $GLOBALS['wpdb'] = $this->database = new StaffIntentDatabase(); }
	protected function tearDown(): void { if ( null === $this->previous ) { unset( $GLOBALS['wpdb'] ); } else { $GLOBALS['wpdb'] = $this->previous; } }
	private function plan( int $steps = 1 ): array {
		$commands = [];
		for ( $i = 0; $i < $steps; ++$i ) { $commands[] = [ 'operation' => 'promise.version.create', 'token' => RequestContext::create()->request_id, 'payload' => [ 'fixture' => $i, 'version_uuid' => RequestContext::create()->request_id, 'body_json' => '{"immutable":"Original"}', 'preconditions' => [ 'object_revision' => 0, 'version_revision' => 0, 'published_version_id' => 0 ] ] ]; }
		return [ 'format_version' => 1, 'owner' => 7, 'site_id' => 1, 'site_key' => 'store', 'steps' => $commands, 'cursor' => 0, 'state' => 'ready' ];
	}
	private function accepted(): OperationAttemptResult {
		$profile = $this->createMock( OperationProfile::class ); $profile->method( 'result_schema' )->willReturn( new OperationSchema( [ 'stored' => 'bool' ] ) );
		return new OperationAttemptResult( OperationOutcome::accepted(), OperationCompletion::accepted( $profile, [ 'stored' => true ] ) );
	}
	private function rejected(): OperationAttemptResult { return new OperationAttemptResult( OperationOutcome::rejected( new ContractError( 'stale_revision', RequestContext::create(), 'reload_and_submit' ) ) ); }

	public function test_duplicate_tab_cannot_replace_the_first_complete_envelope(): void {
		$store = new PromiseStaffIntent(); $first = PromiseStaffIntent::encode( $this->plan() ); $second = PromiseStaffIntent::encode( $this->plan() );
		self::assertTrue( $store->insert( 'journal', $first ) ); self::assertFalse( $store->insert( 'journal', $second ) );
		self::assertFalse( $store->replace( 'journal', strtoupper( $first ), $second ) ); self::assertSame( $first, $store->read( 'journal' ) );
		self::assertStringContainsString( 'WHERE option_name = %s AND BINARY option_name = %s AND BINARY option_value = %s', implode( "\n", $this->database->queries ) );
	}

	public function test_live_request_lock_prevents_concurrent_reconciliation_dispatch(): void {
		$store = new PromiseStaffIntent(); $bytes = PromiseStaffIntent::encode( $this->plan() ); $store->insert( 'journal', $bytes );
		$this->database->locks[ 'cetech_staff_' . substr( hash( 'sha256', 'fixture_db:wp_options:journal' ), 0, 48 ) ] = 999;
		$called = false;
		try { $store->run( 'journal', $bytes, function () use ( &$called ) { $called = true; return $this->accepted(); } ); self::fail( 'Contention must stop dispatch.' ); }
		catch ( \RuntimeException ) { self::assertFalse( $called ); self::assertSame( $bytes, $store->read( 'journal' ) ); }
	}

	public function test_process_dies_before_dispatch_then_exact_original_retry_can_finish(): void {
		$store = new PromiseStaffIntent(); $plan = $this->plan(); $original = $plan['steps'][0]; $plan['state'] = 'inflight'; $plan['claim'] = RequestContext::create()->request_id;
		$bytes = PromiseStaffIntent::encode( $plan ); $store->insert( 'journal', $bytes ); $seen = [];
		$unknown = new OperationAttemptResult( OperationOutcome::unconfirmed( new ContractError( 'outcome_unknown', RequestContext::create(), 'reconcile_original_request' ) ) );
		self::assertSame( 'uncertain', $store->run( 'journal', $bytes, function ( array $step, bool $reconcile ) use ( &$seen, $unknown ) { $seen[] = [ $step, $reconcile ]; return $unknown; } ) );
		self::assertSame( [ $original, true ], $seen[0] );
		self::assertSame( 'complete', $store->run( 'journal', $store->read( 'journal' ), function ( array $step, bool $reconcile ) use ( &$seen ) { $seen[] = [ $step, $reconcile ]; return $this->accepted(); }, true ) );
		self::assertSame( [ $original, false ], $seen[1] );
	}

	public function test_native_commit_and_lost_progress_readback_resume_at_durable_next_step(): void {
		$store = new PromiseStaffIntent(); $plan = $this->plan( 2 ); $bytes = PromiseStaffIntent::encode( $plan ); $store->insert( 'journal', $bytes );
		$effects = []; $attempts = [];
		$execute = function ( array $step, bool $reconcile ) use ( &$effects, &$attempts ) {
			$attempts[] = [ $step, $reconcile ];
			if ( ! $reconcile && ! isset( $effects[ $step['token'] ] ) ) { $effects[ $step['token'] ] = $step; }
			if ( 1 === count( $attempts ) ) { $this->database->after_write = function (): void { $this->database->fail_next_read = true; }; }
			return $this->accepted();
		};
		try { $store->run( 'journal', $bytes, $execute ); self::fail( 'Lost readback must not be accepted.' ); } catch ( \RuntimeException ) {}
		// Progress update itself committed, but the caller never received its readback.
		$durable = $store->read( 'journal' ); $progress = PromiseStaffIntent::decode( $durable );
		self::assertSame( 1, $progress['cursor'] ); self::assertCount( 1, $effects );
		self::assertSame( 'complete', $store->run( 'journal', $durable, $execute ) ); self::assertCount( 2, $effects );
		self::assertSame( $plan['steps'][0], $attempts[0][0] ); self::assertSame( $plan['steps'][1], $attempts[1][0] );
	}

	public function test_accepted_commit_without_progress_write_is_reconciled_without_second_effect(): void {
		$store = new PromiseStaffIntent(); $plan = $this->plan(); $bytes = PromiseStaffIntent::encode( $plan ); $store->insert( 'journal', $bytes ); $effects = 0;
		self::assertSame( 'uncertain', $store->run( 'journal', $bytes, function ( array $step, bool $reconcile ) use ( &$effects ) { ++$effects; $this->database->fail_writes = true; return $this->accepted(); } ) );
		$this->database->fail_writes = false; $before = $store->read( 'journal' ); self::assertSame( 'inflight', PromiseStaffIntent::decode( $before )['state'] );
		self::assertSame( 'complete', $store->run( 'journal', $before, function ( array $step, bool $reconcile ) use ( $plan ) { self::assertTrue( $reconcile ); self::assertSame( $plan['steps'][0], $step ); return $this->accepted(); } ) );
		self::assertSame( 1, $effects );
	}

	public function test_rejection_after_uncertainty_never_becomes_discardable(): void {
		$store = new PromiseStaffIntent(); $plan = $this->plan(); $plan['state'] = 'inflight'; $bytes = PromiseStaffIntent::encode( $plan ); $store->insert( 'journal', $bytes );
		self::assertSame( 'uncertain', $store->run( 'journal', $bytes, fn() => $this->rejected() ) );
		$pending = PromiseStaffIntent::decode( $store->read( 'journal' ) ); self::assertTrue( $pending['had_uncertainty'] ); self::assertSame( $plan['steps'], $pending['steps'] );
	}

	public function test_connection_replacement_during_native_dispatch_leaves_original_inflight(): void {
		$store = new PromiseStaffIntent(); $plan = $this->plan(); $bytes = PromiseStaffIntent::encode( $plan ); $store->insert( 'journal', $bytes );
		try { $store->run( 'journal', $bytes, function () { ++$this->database->connection; return $this->accepted(); } ); self::fail( 'Lost connection fence must stop journal progress.' ); } catch ( \RuntimeException ) {}
		$pending = PromiseStaffIntent::decode( $store->read( 'journal' ) ); self::assertSame( 'inflight', $pending['state'] ); self::assertSame( $plan['steps'], $pending['steps'] );
	}

	public function test_shared_index_merge_preserves_other_rule_and_refuses_changed_same_rule(): void {
		$store = new PromiseStaffIntent(); $this->database->rows['index'] = serialize( [ 'first' => [ 'version' => 1 ] ] );
		$this->database->before_write = function (): void { $this->database->rows['index'] = serialize( [ 'first' => [ 'version' => 1 ], 'other-tab' => [ 'version' => 3 ] ] ); };
		self::assertTrue( $store->merge_index( 'index', 'second', null, [ 'version' => 2 ] ) );
		self::assertSame( [ 'first', 'other-tab', 'second' ], array_keys( unserialize( $store->read( 'index' ) ) ) );
		self::assertFalse( $store->merge_index( 'index', 'first', [ 'version' => 0 ], [ 'version' => 4 ] ) );
	}

	public function test_policy_successor_and_first_calendar_have_independent_sequences_and_exact_reference(): void {
		$draft = PromiseStaffDraft::assemble( [ 'unit' => 'business_days', 'processing_min' => '0', 'processing_max' => '1', 'delivery_min' => '1', 'delivery_max' => '2', 'scope' => 'global', 'estimate_required' => 'no', 'timezone' => 'UTC', 'mon_open' => '09:00', 'mon_close' => '17:00' ], [ 'public_label' => 'Van', 'internal_code' => 'van', 'route' => 'local_delivery' ], 2, 1, '2026-10-01 00:00:00.000000' );
		self::assertSame( 2, $draft['policy']->private_facts()['version'] ); self::assertSame( 1, $draft['calendar']->private_facts()['version'] );
		self::assertSame( $draft['calendar']->reference()->private_facts(), $draft['policy']->private_facts()['calendars'][0] );
	}
	public function test_third_policy_version_references_second_calendar_version_exactly(): void {
		$draft = PromiseStaffDraft::assemble( [ 'unit' => 'business_days', 'processing_min' => '0', 'processing_max' => '1', 'delivery_min' => '1', 'delivery_max' => '2', 'scope' => 'global', 'estimate_required' => 'no', 'timezone' => 'UTC', 'mon_open' => '09:00', 'mon_close' => '17:00' ], [ 'public_label' => 'Van', 'internal_code' => 'van', 'route' => 'local_delivery' ], 3, 2, '2026-10-01 00:00:00.000000' );
		self::assertSame( 3, $draft['policy']->private_facts()['version'] ); self::assertSame( 2, $draft['calendar']->private_facts()['version'] );
		self::assertSame( $draft['calendar']->reference()->private_facts(), $draft['policy']->private_facts()['calendars'][0] );
	}

}
