<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogIndexInterface;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogScanScopedConfigurationRepository;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionSnapshotService;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionSnapshotStore;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryNeedsAttentionRefreshScheduler;
use PHPUnit\Framework\TestCase;

/**
 * M2.5: background refresh — resumable batches, generation race, single runner.
 */
final class NeedsAttentionSnapshotRefreshTest extends TestCase {

	private const PRODUCTS = 600;

	/** Products with their own (incomplete) settings, flagged while customers use previous rules. */
	private const FLAGGED = [ 3, 260, 599 ];

	private int $now = 1_800_000_000;

	private NeedsAttentionSnapshotStore $store;

	private InMemoryNeedsAttentionRefreshScheduler $scheduler;

	private InMemoryScopedConfigurationRepository $repository;

	private SiteWideDefaultsSettings $settings;

	/** @var null|\Closure(int): void */
	private ?\Closure $on_page = null;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();
		$this->store      = new NeedsAttentionSnapshotStore();
		$this->scheduler  = new InMemoryNeedsAttentionRefreshScheduler();
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->settings   = new SiteWideDefaultsSettings();
		foreach ( self::FLAGGED as $product_id ) {
			$this->repository->saveScopedConfiguration(
				new ScopedConfiguration(
					new ConfigurationScope( null, ConfigurationScopeType::Product, $product_id, ConfigurationScope::DEFAULT_SLICE_KEY, null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
					[ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '2 days' ) ]
				)
			);
		}
	}

	protected function tearDown(): void {
		unset( $this->repository, $this->store, $this->scheduler );
		$this->on_page                     = null;
		$GLOBALS['cetech_de_test_options'] = [];
	}

	public function test_one_run_scans_everything_and_saves_the_snapshot(): void {
		$service = $this->service();

		self::assertSame( NeedsAttentionSnapshotService::RUN_SAVED, $service->run_refresh() );

		$snapshot = $this->store->snapshot();
		self::assertNotNull( $snapshot );
		self::assertSame( count( self::FLAGGED ), $snapshot->count );
		self::assertSame( self::PRODUCTS, $snapshot->product_total );
		self::assertTrue( $snapshot->complete );
		self::assertSame( '1.0.0-m2.5', $snapshot->plugin_version );
		self::assertNull( $this->store->progress() );
		self::assertArrayNotHasKey( NeedsAttentionSnapshotStore::OPTION_LOCK, $GLOBALS['cetech_de_test_options'] );
		self::assertSame( [], $this->scheduler->scheduled );
		self::assertSame( count( self::FLAGGED ), $service->reading()->count );
		self::assertTrue( $service->reading()->fresh );
	}

	public function test_a_long_scan_continues_from_its_cursor_in_the_next_run(): void {
		$service = $this->service();

		self::assertSame( NeedsAttentionSnapshotService::RUN_CONTINUED, $service->run_refresh( 1 ) );
		self::assertSame( 250, $this->store->progress()['offset'] ?? null );
		self::assertSame( 1, $this->store->progress()['count'] ?? null );
		self::assertSame( [ $this->now ], $this->scheduler->scheduled, 'The continuation is queued immediately.' );
		self::assertNull( $this->store->snapshot() );
		self::assertArrayNotHasKey( NeedsAttentionSnapshotStore::OPTION_LOCK, $GLOBALS['cetech_de_test_options'] );

		$this->scheduler->take();
		self::assertSame( NeedsAttentionSnapshotService::RUN_CONTINUED, $service->run_refresh( 1 ) );
		self::assertSame( 500, $this->store->progress()['offset'] ?? null );
		self::assertSame( 2, $this->store->progress()['count'] ?? null );

		$this->scheduler->take();
		self::assertSame( NeedsAttentionSnapshotService::RUN_SAVED, $service->run_refresh( 1 ) );
		self::assertSame( count( self::FLAGGED ), $this->store->snapshot()?->count );
		self::assertSame( self::PRODUCTS, $this->store->snapshot()?->product_total );
	}

	public function test_an_invalidation_between_runs_restarts_the_scan_from_the_first_product(): void {
		$service = $this->service();
		$service->run_refresh( 2 );
		self::assertSame( 500, $this->store->progress()['offset'] ?? null );

		$service->mark_dirty();
		$service->flush();
		$this->scheduler->take();

		self::assertSame( NeedsAttentionSnapshotService::RUN_CONTINUED, $service->run_refresh( 1 ) );
		self::assertSame( 250, $this->store->progress()['offset'] ?? null, 'The stale cursor was discarded.' );
		self::assertSame( $service->generation(), $this->store->progress()['generation'] ?? null );
	}

	public function test_an_invalidation_during_the_last_batch_is_not_saved_as_a_stale_count(): void {
		$service       = $this->service();
		$this->on_page = function ( int $offset ): void {
			if ( 500 === $offset ) {
				$this->store->new_generation();
			}
		};

		self::assertSame( NeedsAttentionSnapshotService::RUN_RESTARTED, $service->run_refresh() );
		self::assertNull( $this->store->snapshot() );
		self::assertNull( $this->store->progress() );
		self::assertCount( 1, $this->scheduler->scheduled, 'A refresh for the new generation is queued.' );

		$this->on_page = null;
		$this->scheduler->take();
		self::assertSame( NeedsAttentionSnapshotService::RUN_SAVED, $service->run_refresh() );
		self::assertSame( $service->generation(), $this->store->snapshot()?->generation );
	}

	public function test_an_invalidation_mid_run_is_caught_before_the_cursor_is_saved(): void {
		$service       = $this->service();
		$this->on_page = function ( int $offset ): void {
			if ( 0 === $offset ) {
				$this->store->new_generation();
			}
		};

		self::assertSame( NeedsAttentionSnapshotService::RUN_RESTARTED, $service->run_refresh( 1 ) );
		self::assertNull( $this->store->progress() );
		self::assertSame( [ $this->now ], $this->scheduler->scheduled );
	}

	public function test_a_held_lock_skips_the_run_and_keeps_one_refresh_queued(): void {
		$service = $this->service();
		$GLOBALS['cetech_de_test_options'][ NeedsAttentionSnapshotStore::OPTION_LOCK ] = (string) ( $this->now - 10 );
		$pages = 0;
		$this->on_page = function () use ( &$pages ): void {
			++$pages;
		};

		self::assertSame( NeedsAttentionSnapshotService::RUN_LOCKED, $service->run_refresh() );
		self::assertSame( 0, $pages, 'No catalog work while another runner holds the lock.' );
		self::assertCount( 1, $this->scheduler->scheduled );
		self::assertSame( NeedsAttentionSnapshotService::RUN_LOCKED, $service->run_refresh() );
		self::assertCount( 1, $this->scheduler->scheduled, 'Never more than one refresh queued.' );
	}

	public function test_an_abandoned_lock_is_taken_over(): void {
		$service = $this->service();
		$GLOBALS['cetech_de_test_options'][ NeedsAttentionSnapshotStore::OPTION_LOCK ] = (string) ( $this->now - NeedsAttentionSnapshotService::LOCK_TTL_SECONDS );

		self::assertSame( NeedsAttentionSnapshotService::RUN_SAVED, $service->run_refresh() );
		self::assertArrayNotHasKey( NeedsAttentionSnapshotStore::OPTION_LOCK, $GLOBALS['cetech_de_test_options'] );
	}

	public function test_the_lock_is_released_when_the_scan_throws(): void {
		$service       = $this->service();
		$this->on_page = static function (): void {
			throw new \RuntimeException( 'database went away' );
		};

		try {
			$service->run_refresh();
			self::fail( 'Expected the scan error to surface.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'database went away', $error->getMessage() );
		}

		self::assertArrayNotHasKey( NeedsAttentionSnapshotStore::OPTION_LOCK, $GLOBALS['cetech_de_test_options'] );
	}

	public function test_a_run_stops_at_its_time_budget(): void {
		$service       = $this->service();
		$this->on_page = function (): void {
			// Each batch takes 12 seconds of wall time.
			$this->now += 12;
		};

		self::assertSame( NeedsAttentionSnapshotService::RUN_CONTINUED, $service->run_refresh() );
		self::assertSame( 500, $this->store->progress()['offset'] ?? null, 'Two 12 s batches pass the 20 s budget.' );
	}

	private function service(): NeedsAttentionSnapshotService {
		return new NeedsAttentionSnapshotService(
			$this->store,
			$this->query(),
			$this->scheduler,
			'1.0.0-m2.5',
			fn (): int => $this->now
		);
	}

	private function query(): NeedsAttentionQuery {
		$products = [];
		for ( $id = 1; $id <= self::PRODUCTS; $id++ ) {
			$products[ $id ] = [ 'label' => 'P' . $id, 'type' => 'simple' ];
		}
		$inner   = new InMemoryCatalogIndex( $products );
		$catalog = new class( $inner, fn ( int $offset ) => null !== $this->on_page ? ( $this->on_page )( $offset ) : null ) implements CatalogIndexInterface {
			public function __construct( private readonly InMemoryCatalogIndex $inner, private readonly \Closure $on_page ) {
			}

			public function count_published_products(): int {
				return $this->inner->count_published_products();
			}

			public function published_product_ids( int $offset = 0, int $limit = 200 ): array {
				( $this->on_page )( $offset );

				return $this->inner->published_product_ids( $offset, $limit );
			}

			public function product_label( int $product_id ): string {
				return $this->inner->product_label( $product_id );
			}

			public function product_edit_url( int $product_id ): string {
				return $this->inner->product_edit_url( $product_id );
			}

			public function is_variable( int $product_id ): bool {
				return $this->inner->is_variable( $product_id );
			}

			public function variation_ids( int $product_id ): array {
				return $this->inner->variation_ids( $product_id );
			}

			public function variation_label( int $variation_id ): string {
				return $this->inner->variation_label( $variation_id );
			}
		};

		$scopes   = new CatalogScanScopedConfigurationRepository( $this->repository );
		$resolver = new EffectiveConfigurationResolver( $scopes, new EffectiveConfigurationValidator(), new HardFulfilmentConstraintService(), $this->settings );
		$flags    = new FeatureFlags();
		$state    = new OperationalStateService(
			$flags,
			$this->settings,
			new SetupWizardProgress( $this->settings ),
			new ClassicCheckoutRuntimeActivation( $flags, $this->settings ),
			new SiteWideDefaultSummary( $this->repository )
		);

		return new NeedsAttentionQuery(
			$catalog,
			new OperationalReadinessAssessor( $resolver ),
			$state,
			new CatalogInheritanceClassifier( $scopes, $inner, $resolver, $this->settings ),
			$scopes
		);
	}
}
