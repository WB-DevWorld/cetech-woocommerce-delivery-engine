<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeCartQuoteProjectionTest extends TestCase {
	public function test_restored_rate_projection_precedes_the_real_raw_native_fence_and_remains_stable(): void {
		$out = $this->probe( 'matching' );
		self::assertTrue( $out['available'] ); self::assertSame( [ 'control', 'restore', 'project', 'capture', 'project', 'confirm' ], $out['timeline'] );
		self::assertTrue( $out['projected_at_capture'] ); self::assertTrue( $out['same_selected_rate'] ); self::assertTrue( $out['money_unchanged'] ); self::assertTrue( $out['session_unchanged'] );
		self::assertTrue( $out['raw_fence_stable'] ); self::assertFalse( $out['raw_fence_after_late_change'] );
		self::assertSame( 0, $out['totals_calls'] ); self::assertSame( 0, $out['writes'] ); self::assertSame( 0, $out['queries'] );
	}
	public function test_projection_installation_is_one_time_and_same_instance_idempotent(): void {
		$out = $this->probe( 'replacement' ); self::assertTrue( $out['same_instance_allowed'] ); self::assertTrue( $out['replacement_refused'] ); self::assertTrue( $out['available'] ); self::assertSame( 0, $out['replacement_calls'] );
	}
	#[DataProvider( 'refused_before_capture' )]
	public function test_unavailable_control_cache_or_projection_cannot_enter_native_capture( string $mode, int $projections ): void {
		$out = $this->probe( $mode ); self::assertFalse( $out['available'] ); self::assertSame( $projections, $out['projection_calls'] ); self::assertSame( 0, $out['captures'] ); self::assertTrue( $out['session_unchanged'] ); self::assertSame( 0, $out['totals_calls'] ); self::assertSame( 0, $out['writes'] ); self::assertSame( 0, $out['queries'] );
	}
	public static function refused_before_capture(): array { return [ [ 'paused', 0 ], [ 'changed_cache', 0 ], [ 'projection_throws', 1 ] ]; }
	public function test_absent_optional_projection_preserves_existing_cached_evidence_path(): void {
		$out = $this->probe( 'optional' ); self::assertTrue( $out['available'] ); self::assertSame( 0, $out['projection_calls'] ); self::assertSame( 1, $out['captures'] ); self::assertTrue( $out['session_unchanged'] ); self::assertSame( 0, $out['totals_calls'] ); self::assertSame( 0, $out['queries'] );
	}
	private function probe( string $mode ): array {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-cart-projection-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
	}
}
