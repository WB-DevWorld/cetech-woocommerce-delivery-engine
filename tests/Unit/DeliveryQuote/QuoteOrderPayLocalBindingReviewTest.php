<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native shape protocol regressions; never substitute for the actual pay handler proof. */
final class QuoteOrderPayLocalBindingReviewTest extends TestCase {
	#[DataProvider( 'native_method_steps' )]
	public function test_only_exact_native_selected_method_and_save_bookkeeping_leave_acknowledged_facts_unchanged( string $mode ): void { $out = $this->probe( $mode ); self::assertTrue( $out['unchanged'] ); self::assertTrue( $out['selected_method'] ); self::assertSame( 2, $out['private_encodings_refused'] ); }
	public static function native_method_steps(): array { return [ [ 'method' ], [ 'native_save' ] ]; }
	#[DataProvider( 'unsaved_mutations' )]
	public function test_unsaved_price_address_tax_map_metadata_and_unsupported_fee_mutations_refuse( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['unchanged'], $mode ); self::assertSame( 2, $out['private_encodings_refused'] ); }
	public static function unsaved_mutations(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'line_money', 'billing', 'tax_map_remove', 'protected_meta', 'fee', 'site' ] ); }
	public function test_native_classic_reload_matches_exact_native_fields_without_transferring_object_identity(): void { $out = $this->probe( 'same' ); self::assertTrue( $out['unchanged'] ); self::assertTrue( $out['same_native'] ); self::assertFalse( $out['selected_method'] ); }
	#[DataProvider( 'changed_reload' )]
	public function test_changed_native_reload_or_foreign_subclass_cannot_inherit_stage_authority( string $mode ): void { self::assertFalse( $this->probe( $mode )['same_native'] ); }
	public static function changed_reload(): array { return [ [ 'reload_changed' ], [ 'reload_foreign' ] ]; }
	private function probe( string $mode ): array { $process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-pay-local-review-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR ); }
}
