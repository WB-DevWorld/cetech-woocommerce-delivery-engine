<?php

declare(strict_types=1);
namespace CetechDeliveryEngine\Tests\Unit\DeliveryQuote;

use CetechDeliveryEngine\Application\DeliveryQuote\{LegacyQuoteSourcePlan,QuoteCurrentEvidenceGuard,QuoteDurableCommand,QuoteOperationProfile,QuotePlacementSavedEvidenceGuard};
use CetechDeliveryEngine\Application\Operation\OperationStorageException;
use CetechDeliveryEngine\Domain\DeliveryQuote\{QuoteBinding,QuoteContext,QuoteOwner,QuoteStoredRow};
use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOperationRecordRepository;
use CetechDeliveryEngine\Tests\Support\DeliveryQuote\{QuoteDurableFixtureFactory,QuoteFixtureControl,QuoteFixtures,QuoteStorageFixtures};
use CetechDeliveryEngine\Tests\Support\ServicePromise\Handoff\PromiseHandoffFixture as F;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteDurableFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/DeliveryQuote/QuoteStorageFixtures.php';
require_once dirname( __DIR__, 2 ) . '/Support/ServicePromise/Handoff/PromiseHandoffFixture.php';

/** The complete finite native participant census, independently of SQL transport. */
final class PromiseQuoteParticipantTablesTest extends TestCase {

	private function native_source_tables( OperationSession $session, bool $promise ): array {
		$tables = [ WpdbOperationRecordRepository::table_name( $session, 'rate_cards' ) ];
		foreach ( array_keys( LegacyQuoteSourcePlan::SOURCES ) as $source ) { $tables[] = LegacyQuoteSourcePlan::table( $session, $source ); }
		foreach ( [ 'options', 'woocommerce_tax_rates', 'wc_tax_rate_classes', 'woocommerce_tax_rate_locations', 'woocommerce_shipping_zone_methods', 'woocommerce_sessions', 'usermeta' ] as $suffix ) { $tables[] = $session->table_prefix() . $suffix; }
		if ( $promise ) { foreach ( [ 'promise_objects', 'promise_versions', 'promise_assignments', 'operation_records', 'operation_changes' ] as $suffix ) { $tables[] = WpdbOperationRecordRepository::table_name( $session, $suffix ); } }
		return array_values( array_unique( $tables ) );
	}
	private function profile( OperationSession $session, QuoteDurableFixtureFactory $factory, bool $promise, int $extra = 0, bool $duplicates = false ): QuoteOperationProfile {
		$quote = $promise ? QuoteStoredRow::from_row( F::row( 'accepted' ) ) : QuoteStorageFixtures::quote( state: 'accepted' );
		$header = $quote->header(); $raw = QuoteStorageFixtures::binding( $quote )->row();
		$names = QuoteDurableCommand::binding_namespaces( $header->owner(), $header, $raw['placement_uuid'], true );
		$binding = QuoteBinding::from_row( array_replace( $raw, [ 'bind_namespace_hash' => $names['bind'], 'seal_namespace_hash' => $names['seal'], 'revision' => 2, 'snapshot_digest' => QuoteFixtures::digest( 'snapshot' ), 'context_digest' => QuoteFixtures::digest( 'context' ), 'verified_at' => $header->created_at()->plus_seconds( 1 )->sql() ] ), $quote );
		$source = $this->native_source_tables( $session, $promise );
		$saved = array_map( static fn( string $suffix ): string => $session->table_prefix() . $suffix, [ 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ] );
		for ( $i = 1; $i <= $extra; ++$i ) { $saved[] = $session->table_prefix() . 'extra_' . $i; }
		if ( $duplicates ) { $saved = [ ...$saved, ...$source, ...$saved ]; }
		$evidence = new class( $source ) implements QuoteCurrentEvidenceGuard {
			public function __construct( private array $tables ) {}
			public function tables( OperationSession $session ): array { return $this->tables; }
			public function verify( OperationSession $session, QuoteOwner $owner, QuoteContext $context ): bool { return false; }
		};
		$guard = new class( $saved ) implements QuotePlacementSavedEvidenceGuard {
			public function __construct( private array $tables ) {}
			public function tables( OperationSession $session ): array { return $this->tables; }
			public function verify( OperationSession $session, QuoteBinding $binding ): bool { return false; }
		};
		$command = QuoteDurableCommand::verify_binding( $header->owner(), QuoteFixtures::reference( $header->id() ), $header, $binding, $guard, $quote->context() );
		return new QuoteOperationProfile( 'delivery_quote.verify_binding', $command, static fn(): bool => true, $evidence, new QuoteFixtureControl( $factory ) );
	}
	public function test_promise_native_full_union_retains_every_price_tax_session_order_and_source_table(): void {
		$factory = new QuoteDurableFixtureFactory(); $session = $factory->open();
		$profile = $this->profile( $session, $factory, true );
		$tables = $profile->transactional_tables( $session );
		self::assertCount( 41, $tables );
		foreach ( [ ...$this->native_source_tables( $session, true ), ...array_map( static fn( string $suffix ): string => 'durable_' . $suffix, [ 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data' ] ) ] as $table ) { self::assertContains( $table, $tables ); }
		self::assertSame( $tables, $this->profile( $session, $factory, true, duplicates: true )->transactional_tables( $session ) );
		self::assertFalse( $session->in_transaction() ); self::assertTrue( $session->retire() );
	}
	public function test_promise_extra_participant_still_refuses_before_table_validation(): void {
		$factory = new QuoteDurableFixtureFactory(); $session = $factory->open();
		$this->expectException( OperationStorageException::class );
		$this->profile( $session, $factory, true, extra: 1 )->transactional_tables( $session );
	}
	public function test_retained_legacy_extra_ceiling_is_unchanged(): void {
		$factory = new QuoteDurableFixtureFactory(); $session = $factory->open();
		$tables = $this->profile( $session, $factory, false, extra: 2 )->transactional_tables( $session );
		self::assertCount( 40, array_unique( [ ...$tables, WpdbOperationRecordRepository::table_name( $session, 'operation_records' ), WpdbOperationRecordRepository::table_name( $session, 'operation_changes' ) ] ) );
		$this->expectException( OperationStorageException::class );
		$this->profile( $session, $factory, false, extra: 3 )->transactional_tables( $session );
	}
}
