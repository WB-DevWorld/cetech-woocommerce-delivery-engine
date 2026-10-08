<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\LegacyQuoteSourceSeedRows;
use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/DeliveryQuote/QuoteFixtures.php';
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\QuoteFixtures;

final class SourceSeedTest extends TestCase {
	public function test_observed_digest_is_detached_full_row_evidence_and_does_not_depend_on_capture_clock(): void {
		$status = 'publish'; $rows = [ 'product' => [ [ 'ID' => '10', 'post_status' => &$status ] ] ]; $seed = new LegacyQuoteSourceSeedRows( $rows, QuoteTime::parse( '2026-10-07 00:00:00.000001' ) ); $status = 'draft';
		self::assertSame( 'publish', $seed->rows_for( 'product' )[0]['post_status'] ); self::assertSame( $seed->digest(), ( new LegacyQuoteSourceSeedRows( [ 'product' => [ [ 'post_status' => 'publish', 'ID' => '10' ] ] ], QuoteTime::parse( '2026-10-08 00:00:00.000001' ) ) )->digest() );
		self::assertNotSame( $seed->digest(), ( new LegacyQuoteSourceSeedRows( $rows, $seed->observed_at() ) )->digest() ); self::assertSame( [], $seed->rows_for( 'rate_cards' ) ); self::assertFalse( method_exists( $seed, 'guard' ) );
	}
	#[DataProvider( 'invalid_rows' )]
	public function test_unknown_nested_or_unbounded_observed_packets_refuse( array $rows ): void { $this->expectException( \InvalidArgumentException::class ); new LegacyQuoteSourceSeedRows( $rows, QuoteTime::now() ); }
	public static function invalid_rows(): array { return [ [ [ 'rate_cards' => [] ] ], [ [ 'product' => [ [ 'ID' => [ '10' ] ] ] ] ], [ [ 'product' => [ [ 'ID' => false ] ] ] ], [ [ 'zones' => array_fill( 0, 201, [ 'id' => '1' ] ) ] ], [ [ 'product_meta' => array_fill( 0, 1000, [ 'id' => '1', 'meta_value' => str_repeat( 'x', 8192 ) ] ) ] ], [ [ 'product' => array_fill( 0, 1000, [ 'ID' => '1' ] ), 'product_meta' => array_fill( 0, 1000, [ 'id' => '1' ] ), 'offers' => array_fill( 0, 501, [ 'id' => '1' ] ) ] ] ]; }
	public function test_selector_validation_refuses_duplicates_and_arbitrary_sql(): void {
		foreach ( [ [ [ 'source' => 'product', 'ids' => [ 10, 10 ] ] ], [ [ 'source' => 'product', 'ids' => [ '10 OR 1=1' ] ] ], [ [ 'source' => 'options', 'names' => [ 'unknown_secret_option' ] ] ], [ [ 'source' => 'zones', 'where' => '1=1' ] ], [ [ 'source' => 'zones' ], [ 'source' => 'zones' ] ] ] as $fences ) { try { LegacyQuoteSourceSeedRows::checked_fences( $fences ); self::fail(); } catch ( \InvalidArgumentException ) { self::assertTrue( true ); } }
	}
	public function test_native_seed_reader_owns_only_bounded_source_reads_and_never_reads_rates(): void {
		$queries = []; $session = $this->session(); $session->expects( self::once() )->method( 'validate_tables' )->with( [ 'proof_posts' ] )->willReturn( true );
		$session->method( 'get_results' )->willReturnCallback( static function ( string $sql ) use ( &$queries ): array { $queries[] = $sql; return str_starts_with( $sql, 'SHOW ' ) ? array_map( static fn( string $field ): array => [ 'Field' => $field, 'Type' => 'varchar(191)' ], [ 'ID', 'post_parent', 'post_type', 'post_status', 'post_modified_gmt' ] ) : [ [ 'ID' => '10', 'post_parent' => '0', 'post_type' => 'product', 'post_status' => 'publish', 'post_modified_gmt' => '2026-10-07 00:00:00', 'source_oversized' => '0' ] ]; } );
		$session->method( 'get_row' )->willReturn( [ 'utc' => '2026-10-07 00:00:00.000001' ] ); $seed = ( new LegacyQuoteSourceSnapshotReader() )->capture_seed( $session, QuoteFixtures::owner(), [ [ 'source' => 'product', 'ids' => [ 10 ] ] ] );
		self::assertSame( '10', $seed->rows_for( 'product' )[0]['ID'] ); self::assertCount( 2, $queries ); self::assertStringContainsString( 'LIMIT 1001 FOR UPDATE', $queries[1] ); foreach ( $queries as $sql ) { self::assertStringNotContainsString( 'rate_cards', $sql ); } self::assertSame( 65536, \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::MAX_BYTES );
	}
	public function test_refused_owner_or_engine_validation_precedes_source_read(): void {
		$session = $this->session(); $session->method( 'site_id' )->willReturn( 2 ); $session->expects( self::never() )->method( 'get_results' ); $this->expectException( \RuntimeException::class ); ( new LegacyQuoteSourceSnapshotReader() )->capture_seed( $session, QuoteFixtures::owner(), [ [ 'source' => 'product', 'ids' => [ 10 ] ] ] );
	}
	public function test_seed_has_no_generic_private_payload_export(): void { $seed = new LegacyQuoteSourceSeedRows( [ 'product_meta' => [ [ 'meta_value' => 'PRIVATE' ] ] ], QuoteTime::now() ); foreach ( [ 'json', 'php' ] as $format ) { try { if ( 'json' === $format ) { json_encode( $seed, JSON_THROW_ON_ERROR ); } else { serialize( $seed ); } self::fail(); } catch ( \LogicException $e ) { self::assertStringNotContainsString( 'PRIVATE', $e->getMessage() ); } } }
	private function session(): OperationSession { $s = $this->createMock( OperationSession::class ); $s->method( 'site_id' )->willReturn( 1 ); $s->method( 'table_prefix' )->willReturn( 'proof_' ); $s->method( 'in_transaction' )->willReturn( true ); $s->expects( self::never() )->method( 'query' ); $s->expects( self::never() )->method( 'commit' ); return $s; }
}
