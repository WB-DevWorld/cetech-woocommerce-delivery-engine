<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual continuation collaborator with native-shaped objects and observed logger saves. */
final class NativeOrderPayContinuationReviewTest extends TestCase {
	#[DataProvider( 'acknowledged_paths' )]
	public function test_only_known_owned_read_completion_can_enter_native_payment( string $mode, int $reads ): void {
		$out = $this->probe( $mode ); self::assertTrue( $out['allowed'] ); self::assertTrue( $out['delegated'] ); self::assertSame( 1, $out['gateway_entries'] ); self::assertSame( 1, $out['owners'] ); self::assertSame( $reads, $out['reads'] ); self::assertSame( 1, $out['rollbacks'] ); self::assertSame( 1, $out['retirements'] ); self::assertTrue( $out['retired'] ); self::assertSame( 0, $out['writes'] ); self::assertSame( 0, $out['owned_native_getters'] );
	}
	public static function acknowledged_paths(): array { return [ [ 'classic_paid', 4 ], [ 'classic_free', 3 ], [ 'cpt_paid', 4 ], [ 'saved_native_method', 2 ], [ 'later_pause', 4 ] ]; }
	#[DataProvider( 'pre_owner_refusals' )]
	public function test_phase5_and_unchanged_original_acknowledged_facts_are_required_before_opening_owner( string $mode ): void {
		$out = $this->probe( $mode ); self::assertFalse( $out['allowed'], $mode ); self::assertFalse( $out['delegated'], $mode ); self::assertSame( 0, $out['gateway_entries'] ); self::assertSame( 0, $out['owners'], $mode ); self::assertSame( 0, $out['writes'] );
	}
	public static function pre_owner_refusals(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'phase4_paid', 'phase4_free', 'before5_money', 'before5_metadata', 'before5_metadata_backup', 'before5_method', 'before5_title', 'after5_money', 'after5_metadata', 'after5_date', 'after5_method', 'after5_title', 'auth_changed', 'forced_free', 'duplicate5' ] ); }
	#[DataProvider( 'owned_refusals' )]
	public function test_lost_read_rollback_retirement_or_changed_physical_facts_never_delegate_or_replace_owner( string $mode ): void {
		$out = $this->probe( $mode ); self::assertFalse( $out['allowed'], $mode ); self::assertFalse( $out['delegated'], $mode ); self::assertSame( 0, $out['gateway_entries'] ); self::assertSame( 1, $out['owners'], $mode ); self::assertTrue( $out['retired'] ); self::assertSame( 0, $out['writes'] ); self::assertSame( 0, $out['owned_native_getters'] );
	}
	public static function owned_refusals(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'physical_saved_changed', 'physical_method_changed', 'physical_logger_changed', 'physical_date_changed', 'read_unknown', 'rollback_unknown', 'retire_unknown', 'retire_mutation', 'validate_unknown', 'begin_unknown' ] ); }
	public function test_completed_logger5_receipt_cannot_rebase_a_changed_original_non_debug_fact(): void {
		$out = $this->probe( 'before5_metadata' ); self::assertTrue( $out['receipt5_raw'] ); self::assertTrue( $out['receipt5_current'] ); self::assertFalse( $out['allowed'] ); self::assertTrue( $out['observation_refused'] ); self::assertSame( 0, $out['owners'] );
	}
	private function probe( string $mode ): array {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-order-pay-continuation-review-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
	}
}
