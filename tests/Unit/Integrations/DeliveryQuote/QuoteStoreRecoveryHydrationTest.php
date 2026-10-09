<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Integrations\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteStoreRecoveryHydrationTest extends TestCase {
	public function test_authorized_post_loads_native_session_before_its_single_evidence_read(): void {
		$out = $this->probe( 'matching' );
		self::assertSame( 1, $out['loads'] ); self::assertSame( 1, $out['reads'] ); self::assertTrue( $out['loaded_at_read'] );
		self::assertSame( [ 'nonce', 'authorization', 'native_load', 'wc_load_cart', 'hydrate', 'evidence' ], $out['sequence'] );
		self::assertTrue( $out['unavailable'] ); self::assertTrue( $out['pointer_unchanged'] );
	}
	#[DataProvider( 'refusals' )]
	public function test_unauthorized_or_unrelated_requests_never_load_cart_or_read_private_evidence( string $mode, bool $unavailable ): void {
		$out = $this->probe( $mode ); self::assertSame( 0, $out['loads'] ); self::assertSame( 0, $out['reads'] ); self::assertSame( $unavailable, $out['unavailable'] ); self::assertTrue( $out['pointer_unchanged'] );
	}
	public static function refusals(): array { return [ [ 'nonce', true ], [ 'token', true ], [ 'foreign', true ], [ 'get', false ], [ 'route', false ], [ 'no_original', false ] ]; }
	public function test_native_loader_failure_refuses_without_an_evidence_retry(): void {
		$out = $this->probe( 'loader_throw' ); self::assertSame( 1, $out['loads'] ); self::assertSame( 0, $out['reads'] ); self::assertTrue( $out['unavailable'] ); self::assertTrue( $out['pointer_unchanged'] );
	}
	private function probe( string $mode ): array {
		$p = proc_open( [ PHP_BINARY, dirname( __DIR__, 3 ) . '/Support/DeliveryQuote/native-store-recovery-hydration-probe.php', $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes );
		self::assertIsResource( $p ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ); $err = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $p ), $err ); return json_decode( $out, true, 16, JSON_THROW_ON_ERROR );
	}
}
