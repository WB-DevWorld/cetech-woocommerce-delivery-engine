<?php
declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\Integrations\DeliveryQuote;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual native no-effect capture/stager/guard classes; transport and Woo execution are qualified separately. */
final class QuotePlacementNoEffectNativeGuardTest extends TestCase {

	#[DataProvider( 'original_placements' )]
	public function test_absent_or_original_prepared1_can_be_fenced_without_creating_verified_payment_authority( string $store, string $kind ): void {
		$out = $this->probe( $store, $kind, 'matching' );
		self::assertTrue( $out['captured'] ); self::assertTrue( $out['verified'] ); self::assertTrue( $out['unchanged'] );
		self::assertSame( 'prepared1' === $kind, $out['initial_owned'] ); self::assertSame( 'prepared1' === $kind, $out['history_supported'] );
		self::assertSame( 1, $out['binding_revision'] ); self::assertTrue( $out['binding_digests_absent'] );
		self::assertSame( 'prepared1' === $kind ? 2 : 1, $out['capture_owners'] ); self::assertNotContains( false, $out['capture_retired'] );
		self::assertSame( 'hpos' === $store ? 6 : 4, $out['reads'] ); self::assertNotContains( false, $out['fences'] );
		self::assertSame( 'hpos' === $store ? [ 'wp_woocommerce_order_items', 'wp_woocommerce_order_itemmeta', 'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data' ] : [ 'wp_woocommerce_order_items', 'wp_woocommerce_order_itemmeta', 'wp_posts', 'wp_postmeta' ], $out['tables'] );
		self::assertSame( 0, $out['locked_getter_delta'] ); self::assertSame( 0, $out['locked_getters'] ); self::assertTrue( $out['serialization_refused'] ); self::assertTrue( $out['json_refused'] );
	}
	public static function original_placements(): array { return [ [ 'hpos', 'none' ], [ 'cpt', 'none' ], [ 'hpos', 'prepared1' ], [ 'cpt', 'prepared1' ] ]; }

	#[DataProvider( 'raw_mutations' )]
	public function test_every_original_raw_native_fact_remains_fenced_before_any_locked_read( string $store, string $kind, string $mode ): void {
		$out = $this->probe( $store, $kind, 'raw_' . $mode ); self::assertTrue( $out['captured'] ); self::assertFalse( $out['unchanged'], $mode ); self::assertFalse( $out['verified'], $mode ); self::assertSame( 0, $out['reads'] ); self::assertSame( 0, $out['locked_getter_delta'] ); self::assertSame( 0, $out['locked_getters'] );
	}
	public static function raw_mutations(): array { return self::matrix( [ 'money', 'line_money', 'line_tax', 'billing', 'status', 'date', 'key', 'method', 'metadata', 'metadata_backup', 'metadata_order', 'metadata_null', 'quote_meta', 'fee', 'id' ] ); }

	#[DataProvider( 'physical_mutations' )]
	public function test_current_native_sql_detects_saved_money_address_packet_item_and_multiplicity_drift( string $store, string $kind, string $mode ): void {
		$out = $this->probe( $store, $kind, 'physical_' . $mode ); self::assertTrue( $out['captured'] ); self::assertTrue( $out['unchanged'] ); self::assertFalse( $out['verified'], $mode ); self::assertGreaterThan( 0, $out['reads'] ); self::assertNotContains( false, $out['fences'] ); self::assertSame( 0, $out['locked_getter_delta'] ); self::assertSame( 0, $out['locked_getters'] );
	}
	public static function physical_mutations(): array { return self::matrix( [ 'money', 'item_money', 'duplicate', 'missing', 'metadata', 'address' ] ); }

	#[DataProvider( 'identity_and_unknown_reads' )]
	public function test_foreign_original_source_order_site_or_unknown_current_read_cannot_authorize_disposition( string $store, string $kind, string $mode ): void {
		$out = $this->probe( $store, $kind, $mode ); self::assertTrue( $out['captured'] ); self::assertFalse( $out['verified'], $mode ); self::assertSame( 0, $out['locked_getter_delta'] ); self::assertSame( 0, $out['locked_getters'] );
	}
	public static function identity_and_unknown_reads(): array { $out = self::matrix( [ 'global_site', 'quote_drift', 'binding_drift', 'binding_foreign', 'sql_unknown', 'retired_after_read', 'transaction_lost', 'foreign_session', 'retired', 'no_transaction' ] ); foreach ( [ 'hpos', 'cpt' ] as $store ) { $out[] = [ $store, 'prepared1', 'binding_absent' ]; } return $out; }

	#[DataProvider( 'unknown_capture' )]
	public function test_capture_requires_known_read_rollback_retirement_and_exact_native_coordinates( string $store, string $kind, string $mode ): void {
		$out = $this->probe( $store, $kind, 'capture_' . $mode ); self::assertFalse( $out['captured'], $mode ); self::assertFalse( $out['verified'] ); self::assertNotContains( false, $out['capture_retired'] ); self::assertSame( 0, $out['locked_getter_delta'] );
	}
	public static function unknown_capture(): array { return self::matrix( [ 'rollback_unknown', 'retire_unknown', 'sql_unknown', 'retired_after_read', 'transaction_lost', 'invalid_tables', 'foreign_order', 'foreign_site', 'native_marker' ] ); }

	#[DataProvider( 'stale_native_ownership' )]
	public function test_none_requires_quote_ownership_absent_in_physical_rows_even_when_loaded_order_is_stale( string $store, string $mode ): void {
		$out = $this->probe( $store, 'none', 'capture_physical_' . $mode ); self::assertFalse( $out['initial_owned'], 'The stale loaded object carries no quote ownership.' ); self::assertFalse( $out['captured'], $mode ); self::assertFalse( $out['verified'] ); self::assertNotContains( false, $out['capture_retired'] );
	}
	public static function stale_native_ownership(): array { $out = []; foreach ( [ 'hpos', 'cpt' ] as $store ) { foreach ( [ 'marker', 'reference', 'draft', 'tax_source', 'packet', 'line_marker', 'line_packet', 'unknown' ] as $mode ) { $out[] = [ $store, $mode ]; } } return $out; }

	#[DataProvider( 'native_stores' )]
	public function test_line_key_alone_does_not_claim_quote_ownership_or_prevent_known_no_effect_capture( string $store ): void {
		$out = $this->probe( $store, 'none', 'capture_physical_linekey' ); self::assertFalse( $out['initial_owned'] ); self::assertTrue( $out['captured'] ); self::assertTrue( $out['verified'] ); self::assertSame( 0, $out['locked_getter_delta'] );
	}
	#[DataProvider( 'native_stores' )]
	public function test_quote_reference_inserted_after_capture_is_refused_by_the_current_physical_fence( string $store ): void {
		$out = $this->probe( $store, 'none', 'physical_metadata' ); self::assertFalse( $out['initial_owned'] ); self::assertTrue( $out['captured'] ); self::assertTrue( $out['unchanged'] ); self::assertFalse( $out['verified'] ); self::assertNotContains( false, $out['fences'] ); self::assertSame( 0, $out['locked_getter_delta'] );
	}
	public static function native_stores(): array { return [ [ 'hpos' ], [ 'cpt' ] ]; }

	private static function matrix( array $modes ): array { $out = []; foreach ( self::original_placements() as [ $store, $kind ] ) { foreach ( $modes as $mode ) { $out[] = [ $store, $kind, $mode ]; } } return $out; }
	private function probe( string $store, string $kind, string $mode ): array {
		$process = proc_open( [ PHP_BINARY, dirname( __DIR__, 3 ) . '/Support/DeliveryQuote/native-no-effect-guard-probe.php', $store, $kind, $mode ], [ [ 'pipe', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes ); self::assertIsResource( $process ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); self::assertSame( 0, proc_close( $process ), $stderr ); return json_decode( $stdout, true, 16, JSON_THROW_ON_ERROR );
	}
}
