<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
final class NativeCartQuotePreparationTest extends TestCase {
	public function test_genuine_provisional_context_uses_every_retained_member_and_observed_seed_without_rate_claim(): void { $out = $this->probe( 'baseline' ); self::assertTrue( $out['available'] ); self::assertTrue( $out['acceptable'] ); self::assertSame( 2, $out['members'] ); self::assertSame( 0, $out['candidate_count'] ); self::assertTrue( $out['observed_seed_digest'] ); self::assertSame( [ 'id' => 33, 'state' => 'known' ], $out['supplier'] ); self::assertTrue( $out['native_tax_exact'] ); self::assertSame( [ 'legacy', 'legacy' ], $out['source_routes'] ); self::assertTrue( $out['private_address_absent'] ); self::assertSame( 0, $out['factory_calls'] ); }
	#[DataProvider( 'unsupported_members' )]
	public function test_provisional_context_refuses_mixed_or_changed_native_and_source_facts( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['available'] ); self::assertTrue( $out['safe_error'] ); self::assertSame( 0, $out['factory_calls'] ); }
	public static function unsupported_members(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'mixed_supplier', 'stock_aggregate', 'selection_changed', 'address_changed', 'inactive_zone', 'missing_offer', 'native_quantity', 'native_currency' ] ); }
	#[DataProvider( 'changed_originals' )]
	public function test_original_evidence_factory_refuses_changed_identity_envelope_or_draft_without_source_work( string $mode ): void { $out = $this->probe( $mode ); self::assertFalse( $out['available'] ); self::assertSame( 0, $out['factory_calls'] ); self::assertTrue( $out['header_unchanged'] ); self::assertTrue( $out['original_context_unchanged'] ); }
	public static function changed_originals(): array { return array_map( static fn( string $mode ): array => [ $mode ], [ 'evidence_wrong_provider', 'evidence_new_token', 'evidence_new_quantity', 'evidence_wrong_owner', 'evidence_header_material' ] ); }
	private function probe( string $mode ): array { $process = proc_open( [ PHP_BINARY, dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/native-cart-context-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR ); }
}
