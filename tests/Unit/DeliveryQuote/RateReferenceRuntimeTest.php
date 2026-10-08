<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use CetechDeliveryEngine\Integrations\DeliveryQuote\QuoteRateReferenceRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RateReferenceRuntimeTest extends TestCase {
	#[DataProvider( 'published_phases' )]
	public function test_owned_exact_component_projects_only_three_inert_fields_without_changing_native_money( string $phase ): void {
		$out = $this->probe( $phase ); self::assertSame( 1, $out['rates_count'] ); self::assertTrue( $out['same_object'] );
		self::assertSame( [ QuoteRateReferenceRuntime::META_QUOTE_ID => $out['expected']['quote_id'], QuoteRateReferenceRuntime::META_COMPONENT_HANDLE => $out['expected']['component_handle'], QuoteRateReferenceRuntime::META_GENERATION => $out['expected']['generation'] ], $out['metadata'] );
		self::assertStringNotContainsString( 'PRIVATE', json_encode( $out['metadata'] ) ); $this->assert_inert( $out );
	}
	public static function published_phases(): array { return [ [ 'issued' ], [ 'confirmed' ], [ 'cache_hit' ] ]; }
	public function test_same_sku_and_label_in_distinct_native_packages_receive_their_exact_component_handles(): void { $out = $this->probe( 'two_components' ); self::assertCount( 3, $out['metadata'] ); self::assertCount( 3, $out['second_metadata'] ); self::assertSame( $out['metadata'][QuoteRateReferenceRuntime::META_QUOTE_ID], $out['second_metadata'][QuoteRateReferenceRuntime::META_QUOTE_ID] ); self::assertSame( $out['metadata'][QuoteRateReferenceRuntime::META_GENERATION], $out['second_metadata'][QuoteRateReferenceRuntime::META_GENERATION] ); self::assertNotSame( $out['metadata'][QuoteRateReferenceRuntime::META_COMPONENT_HANDLE], $out['second_metadata'][QuoteRateReferenceRuntime::META_COMPONENT_HANDLE] ); $this->assert_inert( $out ); }
	#[DataProvider( 'stale_or_unknown' )]
	public function test_changed_or_unowned_component_strips_caller_quote_metadata_without_repricing( string $mode ): void { $out = $this->probe( $mode ); self::assertSame( [], $out['metadata'] ); self::assertTrue( $out['same_object'] ); self::assertSame( 1, $out['rates_count'] ); $this->assert_inert( $out ); }
	public static function stale_or_unknown(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'unauthorized', 'changed_draft', 'quantity', 'same_sku', 'destination', 'different_group', 'rate_group', 'staged', 'expired' ] ); }
	#[DataProvider( 'disabled' )]
	public function test_unmounted_or_disabled_leaves_original_rates_exactly_untouched( string $mode ): void { $out = $this->probe( $mode ); self::assertSame( [ QuoteRateReferenceRuntime::META_PREFIX . 'private_body' => 'PRIVATE-CALLER-INJECTION' ], $out['metadata'] ); self::assertTrue( $out['same_object'] ); $this->assert_inert( $out ); }
	public static function disabled(): array { return [ [ 'off' ], [ 'gate_off' ] ]; }
	public function test_foreign_rate_subclass_is_refused_without_invoking_a_getter_or_rewriting_money(): void { $out = $this->probe( 'foreign' ); self::assertSame( 0, $out['rates_count'] ); $this->assert_inert( $out ); }
	public function test_exact_annotation_hook_coexists_with_the_exact_native_cart_session_binding(): void { $out = $this->probe( 'hook_same' ); self::assertTrue( $out['hooks_supported'] ); $this->assert_inert( $out ); }
	#[DataProvider( 'altered_hooks' )]
	public function test_altered_annotation_or_detached_native_cart_session_registration_is_refused_without_invoking_callbacks( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['hooks_supported'] ); $this->assert_inert( $out ); }
	public static function altered_hooks(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'hook_wrong_priority', 'hook_wrong_args', 'hook_static', 'hook_detached_cart_session' ] ); }
	private function assert_inert( array $out ): void { self::assertTrue( $out['same_packet'] ); self::assertSame( 'preserve', $out['foreign_note'] ); self::assertSame( 0, $out['getters'] ); foreach ( [ 'session_unchanged', 'no_prepare', 'no_evidence', 'no_operation' ] as $key ) { self::assertTrue( $out[$key], $key ); } }
	private function probe( string $mode ): array { $process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-rate-reference-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR ); }
}
