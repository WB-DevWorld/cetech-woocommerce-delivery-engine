<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\FixedPromotionQuotePlacementReadResult;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures as Q;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FixedPromotionQuotePlacementReaderTest.php';

/** Actual quote/operation codecs over owned SQLite; dependency and failure ports are explicitly synthetic. */
final class FixedPromotionQuotePlacementOwnershipTest extends TestCase {
	private PromotionPlacementReaderFixture $f;

	protected function setUp(): void { $this->f = new PromotionPlacementReaderFixture(); }

	public static function binding_losses(): array {
		$cases = [];
		foreach ( [ 'readiness', 'begin', 'control_ready', 'validation', 'receipt', 'control', 'publication', 'quote', 'clock', 'rollback', 'retire' ] as $phase ) {
			foreach ( [ 'site', 'prefix', 'charset' ] as $field ) { $cases[ $phase . ' loses ' . $field ] = [ $phase, $field ]; }
		}
		return $cases;
	}

	#[DataProvider( 'binding_losses' )]
	public function test_owner_binding_loss_after_every_reader_boundary_withholds_all_evidence( string $phase, string $field ): void {
		$reached = false;
		$this->f->on_hook = static function( $session, string $point ) use ( $phase, $field, &$reached ): void {
			if ( $point !== $phase || $reached ) { return; } $reached = true;
			$session->$field = match ( $field ) { 'site' => 2, 'prefix' => 'foreign_', default => 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' };
		};
		$before = $this->protected_bytes(); $result = $this->f->read();
		self::assertTrue( $reached, 'The injected owner boundary must actually execute.' );
		$this->assert_withheld( $result, $before );
	}

	public static function authorization_losses(): array { return array_map( static fn( string $phase ): array => [ $phase ], [ 'readiness', 'begin', 'validation', 'receipt', 'quote', 'clock', 'rollback', 'retire' ] ); }

	#[DataProvider( 'authorization_losses' )]
	public function test_authorization_lost_during_the_owned_read_or_cleanup_never_returns_private_facts( string $phase ): void {
		$reached = false;
		$this->f->on_hook = function( $session, string $point ) use ( $phase, &$reached ): void { if ( $point === $phase ) { $reached = true; $this->f->authorized = false; } };
		$before = $this->protected_bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
	}

	public function test_denial_before_open_is_private_and_does_not_observe_current_dependencies(): void {
		$this->f->authorized = false; $before = $this->protected_bytes(); $result = $this->f->read();
		$this->assert_withheld( $result, $before ); self::assertSame( [], $this->f->sessions );
	}

	public static function cleanup_failures(): array { return [ 'begin not acknowledged' => [ 'begin' ], 'rollback not acknowledged' => [ 'rollback' ], 'retirement not acknowledged' => [ 'retire' ] ]; }

	#[DataProvider( 'cleanup_failures' )]
	public function test_failed_transaction_or_cleanup_acknowledgment_withholds_a_ready_result( string $phase ): void {
		$reached = false;
		$this->f->on_hook = static function( $session, string $point ) use ( $phase, &$reached ): void { if ( $point === $phase ) { $reached = true; $session->fail = $phase; } };
		$before = $this->protected_bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
	}

	public static function corrupt_history(): array { return array_map( static fn( string $kind ): array => [ $kind ], [ 'missing issue', 'missing acceptance', 'issue intent', 'acceptance intent', 'issue audit linkage', 'acceptance audit linkage', 'acceptance time', 'acceptance event' ] ); }

	#[DataProvider( 'corrupt_history' )]
	public function test_structurally_decodable_accepted_row_is_not_enough_when_original_history_is_broken( string $kind ): void {
		$pdo = $this->f->db->pdo;
		if ( 'missing issue' === $kind ) { $pdo->exec( 'DELETE FROM durable_delivery_engine_operation_records WHERE id=1' ); }
		elseif ( 'missing acceptance' === $kind ) { $pdo->exec( 'DELETE FROM durable_delivery_engine_operation_records WHERE id=2' ); }
		elseif ( 'issue intent' === $kind || 'acceptance intent' === $kind ) { $stmt = $pdo->prepare( 'UPDATE durable_delivery_engine_operation_records SET intent_hash=? WHERE id=?' ); $stmt->execute( [ Q::digest( 'synthetic foreign intent' ), 'issue intent' === $kind ? 1 : 2 ] ); }
		elseif ( 'issue audit linkage' === $kind || 'acceptance audit linkage' === $kind ) { $id = 'issue audit linkage' === $kind ? 1 : 2; $pdo->exec( 'UPDATE durable_delivery_engine_operation_records SET audit_id=99 WHERE id=' . $id ); }
		elseif ( 'acceptance time' === $kind ) { $at = QuoteTime::parse( (string) $pdo->query( 'SELECT accepted_at FROM durable_delivery_engine_delivery_quotes' )->fetchColumn() )->plus_seconds( 1 )->sql(); $stmt = $pdo->prepare( 'UPDATE durable_delivery_engine_delivery_quotes SET accepted_at=?,transition_at=?' ); $stmt->execute( [ $at, $at ] ); }
		else { $pdo->exec( 'DELETE FROM durable_delivery_engine_operation_changes WHERE operation_id=2' ); }
		$before = $this->protected_bytes(); $this->assert_withheld( $this->f->read(), $before );
	}


	public static function final_authorization_binding_losses(): array { return [ [ 'site' ], [ 'prefix' ], [ 'charset' ] ]; }

	#[DataProvider( 'final_authorization_binding_losses' )]
	public function test_true_final_authorizer_cannot_replace_retired_owner_binding( string $field ): void {
		$reached = false;
		$this->f->on_authorize = static function( $owner, string $operation, PromotionPlacementReaderFixture $f ) use ( $field, &$reached ): void {
			$session = $f->sessions[0] ?? null; if ( null === $session || ! $session->inner->is_retired() ) { return; } $reached = true;
			$session->$field = match ( $field ) { 'site' => 2, 'prefix' => 'foreign_', default => 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' };
		};
		$before = $this->protected_bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); self::assertTrue( $this->f->authorized ); self::assertCount( 3, $this->f->auth_operations ); $this->assert_withheld( $result, $before );
	}

	public static function failing_metadata(): array { return [ [ 'is_retired' ], [ 'in_transaction' ] ]; }

	#[DataProvider( 'failing_metadata' )]
	public function test_throwing_owner_metadata_still_attempts_unconditional_cleanup_and_never_escapes( string $metadata ): void {
		$reached = false;
		$this->f->on_hook = static function( PromotionPlacementReaderSession $session, string $phase ) use ( $metadata, &$reached ): void { if ( 'receipt' === $phase ) { $reached = true; $session->fail = 'throw:' . $metadata; } };
		$before = $this->protected_bytes(); $result = $this->f->read();
		self::assertTrue( $reached ); $this->assert_withheld( $result, $before );
		self::assertTrue( $this->f->sessions[0]->inner->is_retired() ); self::assertFalse( $this->f->db->pdo->inTransaction() );
	}

	public static function failed_clock_fences(): array { return array_map( static fn( string $case ): array => [ $case ], [ 'before accepted effect', 'initial hold expiry', 'proof backwards', 'proof hold expiry', 'final backwards', 'final hold expiry', 'final header expiry' ] ); }

	#[DataProvider( 'failed_clock_fences' )]
	public function test_no_current_observation_survives_clock_reversal_or_original_exclusive_deadlines( string $case ): void {
		$initial = Q::time()->plus_seconds( 3 ); $proof = $initial; $final = $initial;
		if ( 'before accepted effect' === $case ) { $initial = $proof = $final = Q::time()->plus_seconds( 1 ); }
		elseif ( 'initial hold expiry' === $case ) { $initial = $proof = $final = $this->f->command->hold_until(); }
		elseif ( 'proof backwards' === $case ) { $proof = Q::time()->plus_seconds( 2 ); }
		elseif ( 'proof hold expiry' === $case ) { $proof = $final = $this->f->command->hold_until(); }
		elseif ( 'final backwards' === $case ) { $proof = Q::time()->plus_seconds( 4 ); }
		elseif ( 'final hold expiry' === $case ) { $final = $this->f->command->hold_until(); }
		else { $final = $this->f->command->header()->expires_at(); }
		$this->f->on_open = static function( PromotionPlacementReaderSession $session ) use ( $initial, $proof, $final ): void { $session->clock_times = [ $initial, $proof, $final ]; };
		$before = $this->protected_bytes(); $this->assert_withheld( $this->f->read(), $before );
		self::assertGreaterThanOrEqual( 1, $this->f->sessions[0]->clock_reads ); self::assertTrue( $this->f->sessions[0]->inner->is_retired() );
	}

	public function test_later_longer_policy_proof_cannot_extend_the_earliest_observed_fence(): void {
		$this->f->eligibility->seconds = 1;
		$this->f->on_open = static function( PromotionPlacementReaderSession $session ): void { $session->clock_times = [ Q::time()->plus_seconds( 3 ), Q::time()->plus_seconds( 3 ), Q::time()->plus_seconds( 4 ) ]; };
		$this->f->on_hook = function( $session, string $phase ): void { if ( 'quote' === $phase ) { $this->f->eligibility->seconds = 300; } };
		$before = $this->protected_bytes(); $this->assert_withheld( $this->f->read(), $before );
		self::assertSame( 2, $this->f->eligibility->calls ); self::assertSame( 3, $this->f->sessions[0]->clock_reads );
	}

	private function protected_bytes(): array {
		$rows = [];
		foreach ( [ 'delivery_quotes', 'delivery_quote_bindings', 'delivery_quote_budget_windows', 'operation_records', 'operation_changes' ] as $suffix ) { $rows[$suffix] = $this->f->db->pdo->query( 'SELECT * FROM durable_delivery_engine_' . $suffix . ' ORDER BY id' )->fetchAll( \PDO::FETCH_ASSOC ); }
		return [ $rows, array_map( static fn( $account ): string => $account->to_private_json(), $this->f->budget->accounts ), array_map( static fn( $claim ): string => $claim->to_private_json(), $this->f->budget->claims ) ];
	}

	private function assert_withheld( FixedPromotionQuotePlacementReadResult $result, array $before ): void {
		self::assertSame( 'unavailable', $result->status ); self::assertNull( $result->evidence ); self::assertSame( $before, $this->protected_bytes() ); self::assertSame( 0, $this->f->budget->writes );
		foreach ( $this->f->sessions as $session ) { self::assertSame( 0, $session->writes ); }
	}
}
