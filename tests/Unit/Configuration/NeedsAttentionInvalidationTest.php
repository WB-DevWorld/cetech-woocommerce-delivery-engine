<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionInvalidation;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionSnapshotService;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionSnapshotStore;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Domain\Configuration\CatalogReadinessSignal;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDeliveryOfferRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPickupLocationRepository;
use CetechDeliveryEngine\Tests\Support\InMemoryNeedsAttentionRefreshScheduler;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * M2.5: every write that can change catalog readiness marks the snapshot stale.
 */
final class NeedsAttentionInvalidationTest extends TestCase {

	private NeedsAttentionSnapshotService $service;

	private InMemoryNeedsAttentionRefreshScheduler $scheduler;

	/** @var mixed */
	private mixed $previous_wpdb;

	protected function setUp(): void {
		$this->previous_wpdb               = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['cetech_de_test_actions'] = [];
		$this->scheduler                   = new InMemoryNeedsAttentionRefreshScheduler();
		$this->service                     = new NeedsAttentionSnapshotService(
			new NeedsAttentionSnapshotStore(),
			( new ReflectionClass( NeedsAttentionQuery::class ) )->newInstanceWithoutConstructor(),
			$this->scheduler,
			'1.0.0-m2.5'
		);
		( new NeedsAttentionInvalidation( $this->service ) )->register();
	}

	protected function tearDown(): void {
		$GLOBALS['cetech_de_test_actions'] = [];
		$GLOBALS['cetech_de_test_options'] = [];
		$GLOBALS['wpdb']                   = $this->previous_wpdb;
	}

	public function test_every_invalidation_hook_is_registered(): void {
		$expected = [ CatalogReadinessSignal::ACTION, 'transition_post_status', 'deleted_post', 'set_object_terms', 'shutdown' ];
		foreach ( NeedsAttentionInvalidation::watched_options() as $option ) {
			$expected[] = 'add_option_' . $option;
			$expected[] = 'update_option_' . $option;
			$expected[] = 'delete_option_' . $option;
		}

		foreach ( $expected as $hook ) {
			self::assertArrayHasKey( $hook, $GLOBALS['cetech_de_test_actions'], $hook );
		}

		self::assertContains( SiteWideDefaultsSettings::OPTION_NAME, NeedsAttentionInvalidation::watched_options() );
		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			self::assertContains( 'cetech_de_' . $flag, NeedsAttentionInvalidation::watched_options() );
		}
	}

	/**
	 * @return iterable<string, array{string, list<mixed>, bool}>
	 */
	public static function events(): iterable {
		$product = (object) [ 'post_type' => 'product' ];
		$page    = (object) [ 'post_type' => 'page' ];

		yield 'scoped configuration, offer or pickup write' => [ CatalogReadinessSignal::ACTION, [ 'scoped_configuration' ], true ];
		yield 'site-wide defaults saved' => [ 'update_option_cetech_de_sitewide_defaults', [ [], [ 'setup_completed' => true ], 'cetech_de_sitewide_defaults' ], true ];
		yield 'site-wide defaults first saved' => [ 'add_option_cetech_de_sitewide_defaults', [ 'cetech_de_sitewide_defaults', [] ], true ];
		yield 'site-wide defaults deleted' => [ 'delete_option_cetech_de_sitewide_defaults', [ 'cetech_de_sitewide_defaults' ], true ];
		yield 'checkout runtime switched on' => [ 'update_option_cetech_de_enable_effective_configuration_runtime', [ 0, 1, 'cetech_de_enable_effective_configuration_runtime' ], true ];
		yield 'legacy selector flag changed' => [ 'update_option_cetech_de_enable_product_delivery_selector', [ 1, 0, 'cetech_de_enable_product_delivery_selector' ], true ];
		yield 'unrelated flag' => [ 'update_option_cetech_de_enable_tracking_links', [ 0, 1, 'cetech_de_enable_tracking_links' ], false ];
		yield 'product published' => [ 'transition_post_status', [ 'publish', 'draft', $product ], true ];
		yield 'product unpublished' => [ 'transition_post_status', [ 'draft', 'publish', $product ], true ];
		yield 'product trashed' => [ 'transition_post_status', [ 'trash', 'publish', $product ], true ];
		yield 'product draft to pending' => [ 'transition_post_status', [ 'pending', 'draft', $product ], false ];
		yield 'product updated while published' => [ 'transition_post_status', [ 'publish', 'publish', $product ], false ];
		yield 'page published' => [ 'transition_post_status', [ 'publish', 'draft', $page ], false ];
		yield 'product deleted' => [ 'deleted_post', [ 42, $product ], true ];
		yield 'page deleted' => [ 'deleted_post', [ 43, $page ], false ];
		yield 'product type changed' => [ 'set_object_terms', [ 42, [ 'variable' ], [ 7 ], 'product_type', false, [ 3 ] ], true ];
		yield 'product type unchanged' => [ 'set_object_terms', [ 42, [ 'simple' ], [ 3 ], 'product_type', false, [ 3 ] ], false ];
		yield 'category assigned' => [ 'set_object_terms', [ 42, [ 'lamps' ], [ 9 ], 'product_cat', false, [] ], false ];
	}

	/**
	 * @dataProvider events
	 *
	 * @param list<mixed> $args
	 */
	public function test_event_marks_the_snapshot_stale( string $hook, array $args, bool $expected ): void {
		do_action( $hook, ...$args );

		self::assertSame( $expected, $this->service->is_dirty() );
	}

	public function test_shutdown_moves_the_generation_once_and_queues_one_refresh(): void {
		$before = $this->service->generation();
		CatalogReadinessSignal::changed( 'delivery_offer' );
		CatalogReadinessSignal::changed( 'pickup_location' );
		do_action( 'transition_post_status', 'publish', 'draft', (object) [ 'post_type' => 'product' ] );
		self::assertSame( $before, $this->service->generation() );

		do_action( 'shutdown' );

		self::assertNotSame( $before, $this->service->generation() );
		self::assertCount( 1, $this->scheduler->scheduled );
		self::assertFalse( $this->service->is_dirty() );
	}

	public function test_delivery_option_and_pickup_location_writes_fire_the_signal(): void {
		$wpdb = new FakeWpdb();
		$wpdb->create_table( 'wp_delivery_engine_delivery_offers', [ [ 'internal_code' ] ] );
		$wpdb->create_table( 'wp_delivery_engine_pickup_locations', [ [ 'internal_code' ] ] );
		$GLOBALS['wpdb'] = $wpdb;
		$offers          = new WpdbDeliveryOfferRepository();
		$pickups         = new WpdbPickupLocationRepository();

		$cases = [
			'offer insert'   => fn () => $offers->save( [ 'internal_code' => 'van', 'internal_name' => 'Van', 'public_label' => 'Van', 'route' => 'local_delivery' ] ),
			'offer update'   => fn () => $offers->save( [ 'id' => 1, 'internal_code' => 'van', 'internal_name' => 'Van 2', 'public_label' => 'Van', 'route' => 'local_delivery' ] ),
			'offer soft'     => fn () => $offers->softDelete( 1 ),
			'offer hard'     => fn () => $offers->hardDelete( 1 ),
			'pickup insert'  => fn () => $pickups->save( [ 'internal_code' => 'acc', 'location_name' => 'Accra' ] ),
			'pickup update'  => fn () => $pickups->save( [ 'id' => 1, 'internal_code' => 'acc', 'location_name' => 'Accra Mall' ] ),
			'pickup soft'    => fn () => $pickups->softDelete( 1 ),
			'pickup hard'    => fn () => $pickups->hardDelete( 1 ),
		];

		foreach ( $cases as $label => $write ) {
			$this->service->flush();
			$this->scheduler->clear();
			$result = $write();
			self::assertNotEmpty( $result, $label . ' should succeed on the fake database.' );
			self::assertTrue( $this->service->is_dirty(), $label );
		}
	}

	public function test_scoped_configuration_writes_fire_the_signal(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Infrastructure/Persistence/WpdbScopedConfigurationRepository.php' );

		// Insert, update and delete each signal; the unchanged-content early return does not.
		self::assertSame( 3, substr_count( $source, "CatalogReadinessSignal::changed( 'scoped_configuration' );" ) );
		self::assertMatchesRegularExpression( '/fingerprint\(\) === \$existing->fingerprint\(\) \) \{\s*return \$existing;/', $source );
	}
}
