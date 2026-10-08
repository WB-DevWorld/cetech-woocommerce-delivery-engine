<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native shape regression; actual checkout qualification remains a separate proof. */
final class NativeStageReloadTest extends TestCase {
	public function test_fresh_native_reload_verifies_saved_facts_and_prewarms_absent_empty_groups_before_raw_comparison(): void {
		$out = $this->probe( 'matching' );
		self::assertFalse( $out['matched_before_prewarm'] );
		self::assertTrue( $out['matched'] );
		self::assertSame( 1, $out['saved_checks'] );
		self::assertSame( [ 'line_items', 'shipping_lines', 'tax_lines', 'fee_lines', 'coupon_lines' ], $out['loaded_groups'] );
		self::assertTrue( $out['original_unchanged'] );
	}
	#[DataProvider( 'changed_native' )]
	public function test_reload_preparation_cannot_accept_changed_original_native_facts_or_saved_digests( string $mode, int $saved_checks ): void {
		$out = $this->probe( $mode ); self::assertFalse( $out['matched'], $mode ); self::assertSame( $saved_checks, $out['saved_checks'] );
	}
	public static function changed_native(): array { return [ [ 'original_money', 0 ], [ 'method', 0 ], [ 'title', 0 ], [ 'billing', 1 ], [ 'line_money', 1 ], [ 'metadata', 1 ], [ 'fee', 1 ], [ 'coupon', 1 ], [ 'guard_method', 1 ], [ 'guard_title', 1 ], [ 'foreign_class', 1 ], [ 'snapshot', 1 ], [ 'context', 1 ] ]; }
	public function test_verified_persisted_native_baseline_survives_native_default_status_and_metadata_readback_layout(): void { $out = $this->probe( 'saved_matching' ); self::assertTrue( $out['matched'] ); self::assertSame( 1, $out['saved_checks'] ); self::assertTrue( $out['original_unchanged'] ); }
	public function test_literal_original_retains_its_exact_creation_shape(): void { $out = $this->probe( 'saved_literal' ); self::assertTrue( $out['matched'] ); self::assertSame( 1, $out['saved_checks'] ); }
	#[DataProvider( 'saved_changes' )]
	public function test_persisted_baseline_does_not_rebase_after_an_original_or_processed_object_mutation( string $mode, int $checks ): void { $out = $this->probe( $mode ); self::assertFalse( $out['matched'], $mode ); self::assertSame( $checks, $out['saved_checks'] ); }
	public static function saved_changes(): array { return [ [ 'saved_original_money', 0 ], [ 'saved_original_metadata', 0 ], [ 'saved_method', 0 ], [ 'saved_title', 0 ], [ 'saved_billing', 1 ], [ 'saved_line_money', 1 ], [ 'saved_metadata', 1 ], [ 'saved_fee', 1 ], [ 'saved_coupon', 1 ], [ 'saved_unknown_group', 1 ], [ 'saved_foreign_class', 1 ] ]; }
	private function probe( string $mode ): array {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/EmergencyControl/native-stage-reload-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr );
		return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
	}
}
