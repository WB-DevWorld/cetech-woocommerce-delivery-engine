<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\EmergencyControl;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Production logger evidence and raw bindings; actual native HTTP proof is separate. */
final class NativeCheckoutLoggingEvidenceTest extends TestCase {
	public function test_native_phase4_save_redeclared_order_data_and_locked_physical_facts_match(): void {
		$out = $this->probe( 'classic4' ); self::assertTrue( $out['began'] ); self::assertSame( [ 4 ], $out['phases'] ); self::assertSame( [], $out['errors'] ); self::assertSame( [ true ], $out['current_after_steps'] );
		self::assertTrue( $out['raw_before'] ); self::assertTrue( $out['raw_after'] ); self::assertTrue( $out['matches_saved'] ); self::assertTrue( $out['verified'] ); self::assertSame( 2, $out['reads'] ); self::assertSame( 1, $out['saved_checks'] ); self::assertSame( [ 'owned_saved_facts', 'owned_wc_orders', 'owned_wc_orders_meta' ], $out['tables'] ); self::assertSame( 0, $out['pure_getter_delta'] );
	}
	#[DataProvider( 'native_stores' )]
	public function test_only_observed_phase5_row_replacement_succeeds_without_rebasing_other_native_facts( string $mode, array $tables ): void {
		$out = $this->probe( $mode ); self::assertSame( [ 4, 5 ], $out['phases'] ); self::assertSame( [ true, true ], $out['current_after_steps'] ); self::assertTrue( $out['successor'] ); self::assertFalse( $out['phase4_raw_after_phase5'] ); self::assertTrue( $out['raw_after'] ); self::assertTrue( $out['logging_same'] ); self::assertTrue( $out['native_typed_same'] ); self::assertFalse( $out['strict_same'], 'Ordinary order-pay retains the original exact debug metadata.' ); self::assertTrue( $out['matches_saved'] ); self::assertTrue( $out['verified'] ); self::assertSame( $tables, $out['tables'] ); self::assertSame( 0, $out['pure_getter_delta'] );
	}
	public static function native_stores(): array { return [ [ 'classic5', [ 'owned_saved_facts', 'owned_wc_orders', 'owned_wc_orders_meta' ] ], [ 'cpt', [ 'owned_saved_facts', 'owned_wc_orders', 'owned_posts', 'owned_postmeta' ] ] ]; }
	#[DataProvider( 'invalid_episodes' )]
	public function test_missing_disabled_malformed_or_forged_native_episode_cannot_begin( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['began'], $mode ); self::assertFalse( $out['verified'] ); }
	public static function invalid_episodes(): array { return array_map( static fn ( string $mode ): array => [ $mode ], [ 'logging_disabled', 'bad_uid', 'bad_short', 'duplicate_steps', 'foreign_steps', 'malformed_steps', 'foreign_logger_path' ] ); }
	#[DataProvider( 'unobserved_saves' )]
	public function test_matching_debug_value_is_not_authority_without_exact_native_checkout_save_provenance( string $mode ): void { $out = $this->probe( $mode ); self::assertTrue( $out['began'] ); self::assertNotEmpty( $out['phases'] ); self::assertSame( [ null ], array_values( array_unique( $out['phases'] ) ) ); self::assertFalse( $out['verified'] ); self::assertFalse( $out['raw_after'] ); }
	public static function unobserved_saves(): array { return array_map( static fn ( string $mode ): array => [ $mode ], [ 'external_observer', 'foreign_caller', 'wrong_checkout_method', 'foreign_checkout', 'foreign_order', 'recursive_save' ] ); }
	#[DataProvider( 'malformed_saved_rows' )]
	public function test_saved_logger_row_requires_one_exact_complete_native_member_and_completed_save( string $mode ): void { $out = $this->probe( $mode ); self::assertTrue( $out['began'] ); self::assertNotContains( 4, $out['phases'] ); self::assertFalse( $out['verified'] ); }
	public static function malformed_saved_rows(): array { return array_map( static fn ( string $mode ): array => [ $mode ], [ 'unsaved_row', 'wrong_source', 'row_extra_shape', 'foreign_meta', 'pending_row', 'tombstone_row', 'duplicate_row', 'pending_changes', 'date_string' ] ); }
	#[DataProvider( 'other_changes' )]
	public function test_logger_observation_does_not_exempt_any_other_raw_native_change( string $mode ): void { $out = $this->probe( 'mutate_' . $mode ); self::assertSame( [ 4 ], $out['phases'] ); self::assertTrue( $out['raw_before'] ); self::assertFalse( $out['raw_after'], $mode ); self::assertFalse( $out['verified'], $mode ); self::assertFalse( $out['logging_same'], $mode ); self::assertSame( 0, $out['pure_getter_delta'] ); }
	public static function other_changes(): array { return array_map( static fn ( string $mode ): array => [ $mode ], [ 'money', 'billing', 'key', 'status', 'method', 'title', 'date', 'line_money', 'line_tax', 'metadata', 'metadata_type', 'metadata_backup', 'metadata_current_null', 'metadata_order', 'debug', 'fee' ] ); }
	#[DataProvider( 'physical_refusals' )]
	public function test_physical_saved_facts_and_native_logger_row_date_require_known_exact_owned_reads( string $mode ): void { $out = $this->probe( $mode ); self::assertSame( [ 4 ], $out['phases'] ); self::assertTrue( $out['raw_after'] ); self::assertFalse( $out['verified'], $mode ); self::assertSame( 0, $out['pure_getter_delta'] ); }
	public static function physical_refusals(): array { return array_map( static fn ( string $mode ): array => [ $mode ], [ 'sql_row_changed', 'sql_row_id_changed', 'sql_duplicate', 'sql_missing', 'sql_extra_shape', 'sql_date_changed', 'sql_unknown', 'sql_retired', 'sql_not_transaction', 'sql_foreign_site', 'sql_saved_changed', 'sql_retired_after_read' ] ); }
	public function test_locked_comparison_uses_captured_raw_facts_without_reading_function_statics_or_calling_native_getters(): void { $out = $this->probe( 'sql_statics_changed' ); self::assertTrue( $out['raw_after'] ); self::assertTrue( $out['verified'] ); self::assertSame( 2, $out['reads'] ); self::assertSame( 0, $out['pure_getter_delta'] ); }
	public function test_native_evidence_cannot_be_serialized_or_projected_to_json(): void { $out = $this->probe( 'classic5' ); self::assertTrue( $out['serialization_refused'] ); self::assertTrue( $out['json_refused'] ); }
	#[DataProvider( 'acknowledged_continuations' )]
	public function test_acknowledged_paid_or_free_continuation_requires_completed_phase5_and_known_owned_read_completion( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['phase4_payment'] ); self::assertFalse( $out['phase4_free'] ); self::assertTrue( $out['continuation_verified'] ); self::assertSame( 1, $out['continuation_owners'] ); self::assertTrue( $out['continuation_retired'] ); self::assertFalse( $out['relay_refused'] ); }
	public static function acknowledged_continuations(): array { return [ [ 'continuation_paid' ], [ 'free_paid' ] ]; }
	#[DataProvider( 'changed_before5' )]
	public function test_new_phase5_raw_receipt_cannot_rebase_changed_phase4_acknowledged_native_facts( string $mode ): void { $out = $this->probe( 'pre5_' . $mode ); self::assertSame( [ 4, 5 ], $out['phases'] ); self::assertTrue( $out['raw_after'] ); self::assertTrue( $out['successor'] ); self::assertSame( in_array( $mode, [ 'method', 'title' ], true ), $out['logging_same'], 'Payment method/title retain their separate original admission comparison.' ); self::assertTrue( $out['relay_refused'] ); self::assertFalse( $out['continuation_verified'] ); self::assertSame( 0, $out['continuation_owners'] ); }
	public static function changed_before5(): array { return [ [ 'money' ], [ 'metadata' ], [ 'line_money' ], [ 'method' ], [ 'title' ] ]; }
	#[DataProvider( 'changed_after5' )]
	public function test_paid_and_free_continuations_reject_unsaved_money_billing_or_native_metadata_changes_after_logger5( string $mode ): void { $out = $this->probe( $mode ); self::assertSame( [ 4, 5 ], $out['phases'] ); self::assertFalse( $out['continuation_verified'] ); self::assertSame( 0, $out['continuation_owners'] ); }
	public static function changed_after5(): array { return array_map( static fn ( string $mode ): array => [ $mode ], [ 'post5_money', 'post5_billing', 'post5_metadata', 'post5_metadata_backup', 'post5_method', 'post5_title', 'free_post5_money', 'free_post5_method', 'free_post5_title' ] ); }
	#[DataProvider( 'unknown_completions' )]
	public function test_logger_continuation_retires_one_owner_and_denies_unknown_read_rollback_or_retire_acknowledgement( string $mode ): void { $out = $this->probe( $mode ); self::assertSame( [ 4, 5 ], $out['phases'] ); self::assertTrue( $out['raw_after'] ); self::assertFalse( $out['continuation_verified'] ); self::assertSame( 1, $out['continuation_owners'] ); self::assertTrue( $out['continuation_retired'] ); }
	public static function unknown_completions(): array { return [ [ 'continuation_unknown' ], [ 'continuation_rollback_unknown' ], [ 'continuation_retire_unknown' ], [ 'free_rollback_unknown' ], [ 'free_retire_unknown' ] ]; }
	private function probe( string $mode ): array {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/EmergencyControl/native-checkout-logging-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
	}
}
