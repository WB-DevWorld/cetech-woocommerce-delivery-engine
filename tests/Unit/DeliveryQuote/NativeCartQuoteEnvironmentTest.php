<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NativeCartQuoteEnvironmentTest extends TestCase {
	#[DataProvider( 'supported_loaded_shapes' )]
	public function test_actual_loaded_environment_does_not_call_getters_filters_or_sql( string $mode ): void { $out = $this->probe( $mode ); self::assertTrue( $out['available'] ); self::assertTrue( $out['session_authorized'] ); self::assertFalse( $out['unknown_authorized'] ); self::assertTrue( $out['recipient_omitted'] ); self::assertSame( 0, $out['getters'] ); self::assertSame( 0, $out['queries'] ); self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $out['draft_digest'] ); }
	public static function supported_loaded_shapes(): array { return [ [ 'baseline' ], [ 'multisite' ], [ 'global_options' ], [ 'fresh_request' ], [ 'malformed_session' ] ]; }
	#[DataProvider( 'unavailable_loaded_shapes' )]
	public function test_missing_or_uncertain_native_facts_fail_before_expensive_work( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['available'] ); self::assertSame( 0, $out['getters'] ); self::assertSame( 0, $out['queries'] ); }
	public static function unavailable_loaded_shapes(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'missing_cache', 'cart_token', 'wrong_session', 'salt_hook', 'no_auth_cookie' ] ); }
	public function test_two_authenticated_sessions_of_one_user_do_not_share_owner_or_draft(): void { $out = $this->probe( 'rotate_auth' ); self::assertTrue( $out['available'] ); self::assertFalse( $out['original_authorized'] ); self::assertTrue( $out['new_owner_differs'] ); self::assertTrue( $out['new_draft_differs'] ); self::assertSame( 0, $out['getters'] ); self::assertSame( 0, $out['queries'] ); }
	public function test_same_owner_quantity_change_changes_loaded_draft_without_source_reads(): void { $out = $this->probe( 'quantity' ); self::assertTrue( $out['draft_changed'] ); self::assertTrue( $out['owner_unchanged'] ); self::assertSame( 0, $out['getters'] ); self::assertSame( 0, $out['queries'] ); }
	public function test_transient_auto_selected_rate_and_calculated_packages_do_not_renew_the_draft(): void { $out=$this->probe('auto_chosen'); self::assertTrue($out['draft_unchanged']); self::assertSame(0,$out['getters']); self::assertSame(0,$out['queries']); }
	public function test_native_customer_destination_change_invalidates_the_stable_draft_without_getters(): void { $out=$this->probe('native_destination'); self::assertTrue($out['draft_changed']); self::assertSame(0,$out['getters']); self::assertSame(0,$out['queries']); }
	public function test_original_owned_reference_still_requires_enabled_control_before_native_reconstruction():void { $out=$this->probe('paused_original'); self::assertFalse($out['evidence_available']); self::assertSame(1,$out['control_reads']); self::assertSame(0,$out['getters']); self::assertSame(0,$out['queries']); }
	public function test_changed_original_envelope_is_rejected_before_control_or_native_work():void { $out=$this->probe('changed_original'); self::assertFalse($out['evidence_available']); self::assertSame(0,$out['control_reads']); self::assertSame(0,$out['getters']); self::assertSame(0,$out['queries']); }
	private function probe( string $mode ): array { $process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-cart-environment-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR ); }
}
