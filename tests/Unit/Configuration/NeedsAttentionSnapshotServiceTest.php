<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionCatalogSnapshot;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionSnapshotService;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionSnapshotStore;
use CetechDeliveryEngine\Tests\Support\InMemoryNeedsAttentionRefreshScheduler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * M2.5: snapshot missing / stale / fresh, version identity and debounce.
 */
final class NeedsAttentionSnapshotServiceTest extends TestCase {

	private int $now = 1_800_000_000;

	private NeedsAttentionSnapshotStore $store;

	private InMemoryNeedsAttentionRefreshScheduler $scheduler;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		$this->store                       = new NeedsAttentionSnapshotStore();
		$this->scheduler                   = new InMemoryNeedsAttentionRefreshScheduler();
	}

	protected function tearDown(): void {
		$GLOBALS['cetech_de_test_options'] = [];
	}

	public function test_missing_snapshot_reads_as_pending_and_queues_one_refresh(): void {
		$service = $this->service();

		$first  = $service->reading();
		$second = $service->reading();

		self::assertTrue( $first->is_pending() );
		self::assertNull( $first->count );
		self::assertNull( $first->computed_at );
		self::assertFalse( $first->fresh );
		self::assertTrue( $second->is_pending() );
		self::assertSame( [ $this->now + NeedsAttentionSnapshotService::REFRESH_DELAY_SECONDS ], $this->scheduler->scheduled, 'Debounced: two readers queue one refresh.' );
	}

	public function test_fresh_snapshot_is_returned_without_queueing_work(): void {
		$service = $this->service();
		self::assertTrue( $service->record_complete_scan( 7, 3763, $service->generation() ) );

		$this->now += 120;
		$reading    = $service->reading();

		self::assertSame( 7, $reading->count );
		self::assertSame( $this->now - 120, $reading->computed_at );
		self::assertTrue( $reading->fresh );
		self::assertSame( [], $this->scheduler->scheduled );
		self::assertSame( 3763, $this->store->snapshot()?->product_total );
	}

	public function test_snapshot_older_than_the_ttl_is_shown_but_refreshed(): void {
		$service = $this->service();
		$service->record_complete_scan( 5, 10, $service->generation() );

		$this->now += NeedsAttentionSnapshotService::TTL_SECONDS;
		$reading    = $service->reading();

		self::assertSame( 5, $reading->count );
		self::assertFalse( $reading->fresh );
		self::assertCount( 1, $this->scheduler->scheduled );
	}

	public function test_invalidation_makes_the_snapshot_stale_and_queues_a_refresh(): void {
		$service = $this->service();
		$service->record_complete_scan( 5, 10, $service->generation() );

		$service->mark_dirty();
		$service->mark_dirty();
		self::assertTrue( $service->is_dirty() );
		self::assertTrue( $service->reading()->fresh, 'The generation moves once, at the end of the request.' );

		$generation = $service->generation();
		$service->flush();

		self::assertFalse( $service->is_dirty() );
		self::assertNotSame( $generation, $service->generation() );
		self::assertCount( 1, $this->scheduler->scheduled );
		$reading = $service->reading();
		self::assertSame( 5, $reading->count );
		self::assertFalse( $reading->fresh );

		$service->flush();
		self::assertCount( 1, $this->scheduler->scheduled, 'Flushing a clean request does nothing.' );
	}

	public function test_a_scan_that_started_before_an_invalidation_is_not_saved(): void {
		$service = $this->service();
		$started = $service->generation();

		$service->mark_dirty();
		$service->flush();
		$this->scheduler->take();

		self::assertFalse( $service->record_complete_scan( 9, 10, $started ) );
		self::assertNull( $this->store->snapshot() );
		self::assertCount( 1, $this->scheduler->scheduled );
	}

	public function test_snapshot_from_another_plugin_version_is_ignored(): void {
		$this->store->save_snapshot( new NeedsAttentionCatalogSnapshot( 4, 10, $this->now, 0, true, '1.0.0-m2.4' ) );

		self::assertTrue( $this->service()->reading()->is_pending() );
	}

	public function test_incomplete_or_malformed_snapshot_is_ignored(): void {
		$this->store->save_snapshot( new NeedsAttentionCatalogSnapshot( 4, 10, $this->now, 0, false, '1.0.0-m2.5' ) );
		self::assertTrue( $this->service()->reading()->is_pending() );

		$GLOBALS['cetech_de_test_options'][ NeedsAttentionSnapshotStore::OPTION_SNAPSHOT ] = [ 'count' => 'many' ];
		self::assertNull( $this->store->snapshot() );
	}

	public function test_store_options_are_written_without_autoload(): void {
		$written = [];
		$service = $this->service();
		$service->record_complete_scan( 1, 1, $service->generation() );
		$service->mark_dirty();
		$service->flush();

		foreach ( [ NeedsAttentionSnapshotStore::OPTION_SNAPSHOT, NeedsAttentionSnapshotStore::OPTION_GENERATION ] as $option ) {
			$written[ $option ] = array_key_exists( $option, $GLOBALS['cetech_de_test_options'] );
		}

		// The option stubs do not record autoload, so the autoload=false arguments are checked in the source.
		self::assertSame( [ NeedsAttentionSnapshotStore::OPTION_SNAPSHOT => true, NeedsAttentionSnapshotStore::OPTION_GENERATION => true ], $written );
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Configuration/Catalog/NeedsAttentionSnapshotStore.php' );
		self::assertStringContainsString( "add_option( \$option, \$value, '', false )", $source );
		self::assertStringContainsString( 'update_option( $option, $value, false )', $source );
	}

	private function service(): NeedsAttentionSnapshotService {
		return new NeedsAttentionSnapshotService(
			$this->store,
			( new ReflectionClass( NeedsAttentionQuery::class ) )->newInstanceWithoutConstructor(),
			$this->scheduler,
			'1.0.0-m2.5',
			fn (): int => $this->now
		);
	}
}
