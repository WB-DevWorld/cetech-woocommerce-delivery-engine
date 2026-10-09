<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Deterministic stalled-read equality at the actual native continuation, without wall-clock sleeps. */
final class PromiseQuotePaymentBoundaryTest extends TestCase {
	#[DataProvider( 'payment_paths' )]
	public function test_clock_after_acknowledged_sql_retirement_is_required_before_gateway_or_free( string $storage, string $kind ): void {
		$before = $this->probe( $storage . '_' . $kind . '_before' ); self::assertTrue( $before['allowed'] ); self::assertSame( 1, $before['gateway_or_free_entries'] ); self::assertTrue( $before['delegated'] ); self::assertSame( 2, $before['clock_captures'] ); self::assertSame( 1, $before['owners'] );
		$equal = $this->probe( $storage . '_' . $kind . '_stalled_equal' ); self::assertFalse( $equal['allowed'] ); self::assertFalse( $equal['delegated'] ); self::assertSame( 0, $equal['gateway_or_free_entries'] ); self::assertSame( 2, $equal['clock_captures'] ); self::assertSame( 1, $equal['owners'] ); self::assertSame( $equal['deadline'], $equal['final_clock'] );
		foreach ( [ $before, $equal ] as $out ) { $this->assert_preserved( $out ); }
	}
	public static function payment_paths(): array { return [ [ 'hpos', 'paid' ], [ 'hpos', 'free' ], [ 'cpt', 'paid' ], [ 'cpt', 'free' ] ]; }
	public function test_initial_exclusive_equality_denies_before_an_owner_opens(): void { $out = $this->probe( 'hpos_paid_initial_equal' ); self::assertFalse( $out['allowed'] ); self::assertSame( 0, $out['gateway_or_free_entries'] ); self::assertSame( 0, $out['owners'] ); self::assertSame( 1, $out['clock_captures'] ); $this->assert_preserved( $out ); }
	#[DataProvider( 'payment_paths' )]
	public function test_source_lifecycle_expiry_after_retirement_refuses_while_original_promise_deadlines_remain_feasible( string $storage, string $kind ): void {
		$before = $this->probe( $storage . '_' . $kind . '_source_expiry_before' ); self::assertTrue( $before['allowed'] ); self::assertSame( 1, $before['gateway_or_free_entries'] );
		$equal = $this->probe( $storage . '_' . $kind . '_source_expiry_stalled_equal' ); self::assertFalse( $equal['allowed'] ); self::assertSame( 0, $equal['gateway_or_free_entries'] ); self::assertSame( $equal['source_deadline'], $equal['final_clock'] ); self::assertLessThan( $equal['deadline'], $equal['final_clock'] ); self::assertSame( 2, $equal['clock_captures'] ); self::assertSame( 1, $equal['owners'] );
		foreach ( [ $before, $equal ] as $out ) { $this->assert_preserved( $out ); }
	}
	#[DataProvider( 'owned_refusals' )]
	public function test_current_source_change_or_unknown_read_ack_never_replaces_owner_or_enters_payment( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['allowed'] ); self::assertFalse( $out['delegated'] ); self::assertSame( 0, $out['gateway_or_free_entries'] ); self::assertSame( 1, $out['owners'] ); self::assertSame( 1, $out['clock_captures'] ); $this->assert_preserved( $out ); }
	public static function owned_refusals(): array { return [ [ 'hpos_paid_source_changed' ], [ 'hpos_paid_rollback_unknown' ] ]; }
	private function assert_preserved( array $out ): void { self::assertSame( 0, $out['clock_inside_owner'] ); self::assertSame( 0, $out['owned_native_getters'] ); self::assertTrue( $out['all_retired'] ); self::assertSame( 0, $out['writes'] ); self::assertTrue( $out['original_records_unchanged'] ); self::assertTrue( $out['original_events_unchanged'] ); self::assertSame( 3, $out['binding_revision'] ); }
	private function probe( string $mode ): array { $p = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-promise-payment-boundary-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $p ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $p ), $err ); return json_decode( $out, true, 16, JSON_THROW_ON_ERROR ); }
}
