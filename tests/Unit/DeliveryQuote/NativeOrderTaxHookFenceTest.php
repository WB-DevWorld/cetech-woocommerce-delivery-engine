<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeOrderTaxHookFenceTest extends TestCase {
	public function test_exact_native_order_tax_controller_is_supported_and_hook_only_fence_is_pure(): void { $out = $this->probe( 'default' ); self::assertTrue( $out['supported_before'] ); self::assertTrue( $out['captured'] ); self::assertTrue( $out['before'] ); self::assertTrue( $out['current'] ); $this->inert( $out ); }
	#[DataProvider( 'alterations' )]
	public function test_unknown_or_altered_order_tax_registration_is_refused_before_capture( string $variation ): void { $out = $this->probe( $variation ); self::assertFalse( $out['supported_before'] ); self::assertFalse( $out['captured'] ); $this->inert( $out ); }
	#[DataProvider( 'alterations' )]
	public function test_unknown_or_altered_order_tax_registration_invalidates_the_original_hook_fence( string $variation ): void { $out = $this->probe( 'late_' . $variation ); self::assertTrue( $out['captured'] ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); $this->inert( $out ); }
	public static function alterations(): array { return array_map( static fn( string $value ): array => [ $value ], [ 'priority', 'arity', 'bool_arity', 'method', 'foreign_instance', 'subclass', 'static', 'key', 'duplicate', 'foreign' ] ); }
	public function test_late_native_callback_removal_invalidates_the_original_fence_without_a_new_read(): void { $out = $this->probe( 'late_remove' ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); self::assertTrue( $out['supported'] ); $this->inert( $out ); }
	public function test_replacing_every_controller_registration_with_same_class_cannot_reuse_the_original_fence(): void { $out = $this->probe( 'late_replacement_all' ); self::assertTrue( $out['supported'] ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); self::assertTrue( $out['original_retained'] ); $this->inert( $out ); }
	public function test_late_option_filter_refuses_the_captured_hook_only_fence_without_invoking_it(): void { $out = $this->probe( 'late_option' ); self::assertTrue( $out['before'] ); self::assertFalse( $out['supported'] ); self::assertFalse( $out['current'] ); $this->inert( $out ); }
	public function test_original_native_source_maximum_option_scope_is_supported(): void { $out = $this->probe( 'scope_full' ); self::assertTrue( $out['captured'] ); self::assertTrue( $out['before'] ); self::assertTrue( $out['current'] ); $this->inert( $out ); }
	#[DataProvider( 'option_prefixes' )]
	public function test_dynamic_selected_method_option_hook_is_refused_before_capture( string $prefix ): void { $out = $this->probe( 'settings_before_' . $prefix ); self::assertFalse( $out['captured'] ); $this->inert( $out ); }
	#[DataProvider( 'option_prefixes' )]
	public function test_dynamic_selected_method_option_hook_invalidates_the_held_original_scope( string $prefix ): void { $out = $this->probe( 'settings_after_' . $prefix ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); $this->inert( $out ); }
	public static function option_prefixes(): array { return [ [ 'pre_option_' ], [ 'option_' ], [ 'default_option_' ] ]; }
	public function test_mutating_input_scope_cannot_detach_the_held_original_option_fence(): void { $out = $this->probe( 'settings_scope_alias' ); self::assertTrue( $out['before'] ); self::assertFalse( $out['current'] ); $this->inert( $out ); }
	#[DataProvider( 'invalid_scopes' )]
	public function test_malformed_or_unbounded_option_scope_is_refused_before_any_native_call( string $variation ): void { $out = $this->probe( 'scope_invalid_' . $variation ); self::assertFalse( $out['captured'] ); $this->inert( $out ); }
	public static function invalid_scopes(): array { return array_map( static fn( string $value ): array => [ $value ], [ 'bool', 'foreign', 'whitespace', 'negative', 'duplicate', 'associative', 'oversize' ] ); }
	private function inert( array $out ): void { self::assertSame( 0, $out['callbacks'] ); self::assertSame( 0, $out['getters'] ); self::assertTrue( $out['registry_unchanged'] ); }
	private function probe( string $mode ): array { $process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-order-tax-hook-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $err ); return json_decode( $out, true, 8, JSON_THROW_ON_ERROR ); }
}
