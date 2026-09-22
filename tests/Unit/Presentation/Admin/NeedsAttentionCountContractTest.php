<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Presentation\Admin;

use CetechDeliveryEngine\Application\Bulk\BulkStaleJobQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionCountQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentEvaluator;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusChangeRequest;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\ShipmentCreationErrorCode;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\NeedsAttentionPage;
use CetechDeliveryEngine\Presentation\Admin\OverviewPage;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;
use CetechDeliveryEngine\Support\Logger;
use CetechDeliveryEngine\Tests\Unit\Configuration\Admin\InMemoryAuditLogRepository;
use CetechDeliveryEngine\Tests\Unit\Shipment\FakeWpdb;
use CetechDeliveryEngine\Tests\Unit\Shipment\ShipmentCreationFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class NeedsAttentionCountContractTest extends TestCase {

	private FeatureFlags $flags;

	private WpdbShipmentRepository $shipments;

	private FakeWpdb $wpdb;

	private ShipmentOperationsIssueStore $operation_store;

	private ShipmentCreationFailureStore $failures;

	private CodAwaitingShipmentStore $cod_store;

	private CodAwaitingShipmentQuery $cod;

	private ShipmentService $shipment_service;

	private InMemoryBulkJobRepository $bulk_jobs;

	private SiteWideDefaultsSettings $settings;

	private SetupWizardProgress $progress;

	private SiteWideDefaultsService $defaults;

	private OperationalStateService $operational;

	protected function setUp(): void {
		parent::setUp();

		FulfilmentProfileRegistry::reset_for_tests();
		$GLOBALS['cetech_de_test_options']             = [
			'cetech_de_enable_shipment_records' => 1,
			'cetech_de_sitewide_defaults'       => [
				'setup_completed' => true,
				'active_profiles' => [],
				'primary_profile' => '',
			],
		];
		$GLOBALS['cetech_de_test_caps']                = [];
		$GLOBALS['cetech_de_test_user_id']             = 3;
		$GLOBALS['cetech_de_test_is_admin']            = false;
		$GLOBALS['cetech_de_test_wc_orders']           = [];
		$GLOBALS['cetech_de_test_wc_get_order_calls']  = [];
		$GLOBALS['cetech_de_test_menus']               = [];
		$GLOBALS['cetech_de_test_submenus']            = [];
		$_GET                                          = [];
		$_POST                                         = [];

		$this->flags           = new FeatureFlags();
		$this->shipments       = ShipmentCreationFixtures::repository();
		$this->wpdb            = $GLOBALS['wpdb'];
		$this->operation_store = new ShipmentOperationsIssueStore();
		$this->failures        = new ShipmentCreationFailureStore();
		$this->cod_store       = new CodAwaitingShipmentStore();
		$factory               = new HistoricalOrderShipmentContextFactory( new OrderDeliverySnapshotReader() );
		$planner               = new HistoricalShipmentPlanner();
		$evaluator             = new CodAwaitingShipmentEvaluator(
			$this->flags,
			$factory,
			$planner,
			$this->shipments,
			$this->cod_store
		);
		$this->cod              = new CodAwaitingShipmentQuery( $this->cod_store, $evaluator );
		$this->shipment_service = new ShipmentService(
			$this->flags,
			$factory,
			$planner,
			$this->shipments,
			$this->failures,
			new InMemoryAuditLogRepository(),
			new Logger(),
			$evaluator
		);
		$this->bulk_jobs = new InMemoryBulkJobRepository();
		$this->settings  = new SiteWideDefaultsSettings();
		$this->progress  = new SetupWizardProgress( $this->settings );
		$scopes          = new InMemoryScopedConfigurationRepository();
		$catalog         = new InMemoryCatalogIndex( [] );
		$resolver        = new EffectiveConfigurationResolver(
			$scopes,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService(),
			$this->settings
		);
		$classifier      = new CatalogInheritanceClassifier(
			$scopes,
			$catalog,
			$resolver,
			$this->settings,
			$this->empty_legacy_rules()
		);
		$this->defaults = new SiteWideDefaultsService( $scopes, $this->settings, $classifier, $resolver, $catalog );
		$summaries       = new SiteWideDefaultSummary( $scopes );
		$this->operational = new OperationalStateService(
			$this->flags,
			$this->settings,
			$this->progress,
			new ClassicCheckoutRuntimeActivation( $this->flags, $this->settings ),
			$summaries
		);
		$this->flags->set( 'enable_shipment_records', true );
	}

	public function test_overview_uses_canonical_counter_for_shipment_only_staff(): void {
		$this->grant( false, true );
		$delayed = $this->store_shipment( 9101, ShipmentStatus::Delayed );
		$counter = $this->counter( $this->uninitialized_catalog() );

		self::assertTrue( $counter->current_user_can_see_shipment_attention() );
		self::assertFalse( $counter->current_user_can_see_catalog_attention() );
		self::assertSame( 1, $counter->unresolved_count_for_current_user() );

		$html = $this->render_overview( $counter );
		self::assertStringContainsString( 'Needs attention', $html );
		self::assertStringContainsString( '1 item', $html );
		self::assertStringContainsString( 'View items', $html );
		self::assertStringContainsString( NeedsAttentionPage::SLUG, $html );
		self::assertStringContainsString( 'cetech-de-stat-card is-attention', $html );
		self::assertStringContainsString( 'Site-wide defaults', $html );

		$menu = $this->menu_html( $counter );
		self::assertStringContainsString( '>1<', $menu );
		self::assertStringContainsString( 'Needs Attention', $menu );

		$page = $this->render_page( $counter, $this->uninitialized_catalog() );
		self::assertStringContainsString( 'Shipment operations', $page );
		self::assertStringContainsString( $delayed->shipment_number, $page );
		self::assertStringNotContainsString( 'Bulk jobs waiting for background processing', $page );
		self::assertStringNotContainsString( 'Fix Now', $page );
		self::assertStringNotContainsString( 'Paid order shipment problems', $page );
		self::assertStringNotContainsString( 'Cash on Delivery — action required', $page );

		$property = ( new ReflectionClass( OverviewPage::class ) )->getProperty( 'needs_attention_count' );
		self::assertSame( NeedsAttentionCountQuery::class, (string) $property->getType() );
		$plugin = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Bootstrap/Plugin.php' );
		$start  = strpos( $plugin, 'static fn ( ServiceContainer $container ): OverviewPage => new OverviewPage(' );
		self::assertNotFalse( $start );
		$chunk = substr( $plugin, (int) $start, 700 );
		self::assertStringContainsString( 'NeedsAttentionCountQuery::class', $chunk );
		self::assertStringNotContainsString( 'NeedsAttentionQuery::class', $chunk );
		$overview = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/Presentation/Admin/OverviewPage.php' );
		self::assertStringContainsString( 'unresolved_count_for_current_user()', $overview );
		self::assertStringNotContainsString( 'needs_attention->count()', $overview );
	}

	public function test_product_only_staff_excludes_shipment_sources_from_count_and_page(): void {
		$this->grant( true, false );
		$catalog = $this->catalog_attention_query();
		$this->store_stale_bulk_job();
		$this->store_fresh_bulk_job();
		$order = $this->paid_delivery_order( 9201, 'bacs' );
		$this->failures->mark_failed( $order, ShipmentCreationErrorCode::MalformedGroupSnapshot );
		$this->store_shipment( 9202, ShipmentStatus::Delayed );
		$this->seed_cod( 9203 );

		$counter = $this->counter( $catalog );
		self::assertTrue( $counter->current_user_can_see_catalog_attention() );
		self::assertFalse( $counter->current_user_can_see_shipment_attention() );
		self::assertSame( 1, $catalog->count() );
		self::assertSame( 1, $this->bulk()->count() );
		self::assertSame( 1, $this->creation()->count() );
		self::assertSame( 1, $this->operations()->count() );
		self::assertSame( 1, $this->cod->count() );
		self::assertSame( 2, $counter->unresolved_count_for_current_user() );

		$this->wpdb->sql_log     = [];
		$this->wpdb->query_count = 0;
		$GLOBALS['cetech_de_test_wc_get_order_calls'] = [];

		$html = $this->render_overview( $counter );
		self::assertStringContainsString( '2 items', $html );
		self::assertSame( '2 items', $this->attention_tile_value( $html ) );

		$page = $this->render_page( $counter, $catalog );
		self::assertStringContainsString( 'Catalog Lamp', $page );
		self::assertStringContainsString( 'Bulk jobs waiting for background processing', $page );
		self::assertStringNotContainsString( 'Paid order shipment problems', $page );
		self::assertStringNotContainsString( 'Shipment operations', $page );
		self::assertStringNotContainsString( 'Cash on Delivery — action required', $page );
		self::assertSame( [], $this->wpdb->sql_log );
		self::assertSame( [], $GLOBALS['cetech_de_test_wc_get_order_calls'] );

		self::assertSame( 2, $this->badge_count( $counter ) );
	}

	public function test_both_capabilities_sum_every_canonical_source(): void {
		$this->grant( true, true );
		$catalog = $this->catalog_attention_query();
		$this->store_stale_bulk_job();
		$order = $this->paid_delivery_order( 9301, 'bacs' );
		$this->failures->mark_failed( $order, ShipmentCreationErrorCode::MissingSnapshot );
		$this->store_shipment( 9302, ShipmentStatus::Delayed );
		$this->seed_cod( 9303 );

		$a = $catalog->count();
		$b = $this->bulk()->count();
		$c = $this->creation()->count();
		$d = $this->operations()->count();
		$e = $this->cod->count();
		$counter = $this->counter( $catalog );

		self::assertSame( 1, $a );
		self::assertSame( 1, $b );
		self::assertSame( 1, $c );
		self::assertSame( 1, $d );
		self::assertSame( 1, $e );
		self::assertSame( $a + $b + $c + $d + $e, $counter->unresolved_count_for_current_user() );

		$html = $this->render_overview( $counter );
		self::assertSame( '5 items', $this->attention_tile_value( $html ) );
		self::assertSame( 5, $this->badge_count( $counter ) );

		$page = $this->render_page( $counter, $catalog );
		self::assertStringContainsString( 'Catalog Lamp', $page );
		self::assertStringContainsString( 'Bulk jobs waiting for background processing', $page );
		self::assertStringContainsString( 'Paid order shipment problems', $page );
		self::assertStringContainsString( 'Shipment operations', $page );
		self::assertStringContainsString( 'Cash on Delivery — action required', $page );
	}

	public function test_shipment_feature_off_hides_attention_from_shipment_only_staff(): void {
		$this->grant( false, true );
		$this->flags->set( 'enable_shipment_records', false );
		$this->store_shipment( 9401, ShipmentStatus::Delayed );
		$counter = $this->counter( $this->uninitialized_catalog() );

		self::assertFalse( $counter->current_user_can_see_shipment_attention() );
		self::assertFalse( $counter->current_user_can_see_needs_attention() );
		self::assertSame( 0, $counter->unresolved_count_for_current_user() );
		self::assertSame( 0, $this->creation()->count() );
		self::assertSame( 0, $this->operations()->count() );
		self::assertSame( 0, $this->cod->count() );

		$html = $this->render_overview( $counter );
		self::assertStringNotContainsString( 'Needs attention', $html );
		self::assertStringContainsString( 'Site-wide defaults', $html );
		self::assertStringContainsString( 'Product-level exceptions', $html );

		$this->register_menu( $counter );
		self::assertNotContains( 'Needs Attention', $this->plain_submenu_titles() );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->render_page( $counter, $this->uninitialized_catalog() );
	}

	public function test_neither_attention_capability_exposes_no_queue(): void {
		$GLOBALS['cetech_de_test_caps'] = [
			Capabilities::VIEW          => true,
			'manage_delivery_zones'     => true,
			'manage_product_delivery_rules' => false,
			'manage_shipments'          => false,
		];
		$this->store_shipment( 9501, ShipmentStatus::Delayed );
		$this->failures->mark_failed(
			$this->paid_delivery_order( 9502, 'bacs' ),
			ShipmentCreationErrorCode::MissingOrderItem
		);
		$counter = $this->counter( $this->uninitialized_catalog() );

		$queries_before = $this->wpdb->query_count;
		$order_calls    = $GLOBALS['cetech_de_test_wc_get_order_calls'];
		self::assertFalse( $counter->current_user_can_see_needs_attention() );
		self::assertSame( 0, $counter->unresolved_count_for_current_user() );
		self::assertSame( $queries_before, $this->wpdb->query_count );
		self::assertSame( $order_calls, $GLOBALS['cetech_de_test_wc_get_order_calls'] );

		$html = $this->render_overview( $counter );
		self::assertStringNotContainsString( 'Needs attention', $html );
		self::assertStringNotContainsString( NeedsAttentionPage::SLUG, $html );
		self::assertStringNotContainsString( '9501-D1', $html );

		$this->register_menu( $counter );
		self::assertNotContains( 'Needs Attention', $this->plain_submenu_titles() );
		self::assertSame( 0, $this->badge_count( $counter ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->render_page( $counter, $this->uninitialized_catalog() );
	}

	public function test_ordinary_awaiting_and_processing_shipments_are_not_needs_attention(): void {
		$this->grant( false, true );
		$this->store_shipment( 9601, ShipmentStatus::AwaitingFulfilment );
		$this->store_shipment( 9602, ShipmentStatus::Processing );
		$counter = $this->counter( $this->uninitialized_catalog() );

		self::assertSame( 0, $counter->unresolved_count_for_current_user() );
		$html = $this->render_overview( $counter );
		self::assertSame( '0 items', $this->attention_tile_value( $html ) );
		self::assertStringNotContainsString( 'cetech-de-stat-card is-attention', $html );

		$this->register_menu( $counter );
		self::assertSame( 'Needs Attention', $this->submenu_title_starting( 'Needs Attention' ) );
	}

	public function test_delayed_shipment_counts_until_status_recovers(): void {
		$this->grant( false, true );
		$delayed = $this->store_shipment( 9701, ShipmentStatus::Delayed );
		$counter = $this->counter( $this->uninitialized_catalog() );

		self::assertSame( 1, $counter->unresolved_count_for_current_user() );
		self::assertSame( '1 item', $this->attention_tile_value( $this->render_overview( $counter ) ) );
		self::assertSame( 1, $this->badge_count( $counter ) );
		self::assertStringContainsString( 'Shipment operations', $this->render_page( $counter, $this->uninitialized_catalog() ) );

		( new ShipmentStatusService( $this->shipments, $this->operation_store ) )->change(
			$delayed->id,
			ShipmentStatus::InTransit,
			ShipmentStatusChangeRequest::staff_normal( 'Moving again', 3 )
		);

		self::assertSame( 0, $counter->unresolved_count_for_current_user() );
		self::assertSame( '0 items', $this->attention_tile_value( $this->render_overview( $counter ) ) );
		$this->register_menu( $counter );
		self::assertSame( 'Needs Attention', $this->submenu_title_starting( 'Needs Attention' ) );
		self::assertStringNotContainsString( 'Shipment operations', $this->render_page( $counter, $this->uninitialized_catalog() ) );
	}

	public function test_visibility_methods_match_catalog_or_enabled_shipment_permission(): void {
		$this->grant( true, false );
		$counter = $this->counter( $this->safe_empty_catalog() );
		self::assertTrue( $counter->current_user_can_see_catalog_attention() );
		self::assertFalse( $counter->current_user_can_see_shipment_attention() );
		self::assertTrue( $counter->current_user_can_see_needs_attention() );

		$this->grant( false, true );
		self::assertFalse( $counter->current_user_can_see_catalog_attention() );
		self::assertTrue( $counter->current_user_can_see_shipment_attention() );

		$this->flags->set( 'enable_shipment_records', false );
		self::assertFalse( $counter->current_user_can_see_shipment_attention() );
		self::assertFalse( $counter->current_user_can_see_needs_attention() );
	}

	private function grant( bool $catalog, bool $shipments ): void {
		$GLOBALS['cetech_de_test_caps'] = [
			Capabilities::VIEW                 => true,
			'manage_product_delivery_rules'    => $catalog,
			'manage_shipments'                 => $shipments,
			'edit_shop_orders'                 => true,
		];
	}

	private function counter( NeedsAttentionQuery $catalog ): NeedsAttentionCountQuery {
		return new NeedsAttentionCountQuery(
			$catalog,
			$this->creation(),
			$this->operations(),
			$this->flags,
			$this->cod,
			$this->bulk()
		);
	}

	private function creation(): ShipmentCreationIssueQuery {
		return new ShipmentCreationIssueQuery( $this->failures );
	}

	private function operations(): ShipmentOperationsIssueQuery {
		return new ShipmentOperationsIssueQuery( $this->flags, $this->shipments, $this->operation_store );
	}

	private function bulk(): BulkStaleJobQuery {
		return new BulkStaleJobQuery( $this->bulk_jobs );
	}

	private function uninitialized_catalog(): NeedsAttentionQuery {
		return ( new ReflectionClass( NeedsAttentionQuery::class ) )->newInstanceWithoutConstructor();
	}

	private function safe_empty_catalog(): NeedsAttentionQuery {
		return new NeedsAttentionQuery(
			new InMemoryCatalogIndex( [] ),
			( new ReflectionClass( OperationalReadinessAssessor::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( OperationalStateService::class ) )->newInstanceWithoutConstructor(),
			( new ReflectionClass( CatalogInheritanceClassifier::class ) )->newInstanceWithoutConstructor(),
			new InMemoryScopedConfigurationRepository()
		);
	}

	private function catalog_attention_query(): NeedsAttentionQuery {
		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			$GLOBALS['cetech_de_test_options'][ 'cetech_de_' . $flag ] = 1;
		}

		$repository = new InMemoryScopedConfigurationRepository();
		$catalog    = new InMemoryCatalogIndex(
			[
				8801 => [ 'label' => 'Catalog Lamp', 'type' => 'simple' ],
			]
		);
		$resolver   = new EffectiveConfigurationResolver(
			$repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService(),
			$this->settings
		);
		$classifier = new CatalogInheritanceClassifier(
			$repository,
			$catalog,
			$resolver,
			$this->settings,
			$this->empty_legacy_rules()
		);
		$defaults   = new SiteWideDefaultsService( $repository, $this->settings, $classifier, $resolver, $catalog );
		$defaults->apply_site_wide(
			[ FulfilmentAvailability::InWarehouse->value ],
			FulfilmentAvailability::InWarehouse->value,
			false
		);

		$state = new OperationalStateService(
			$this->flags,
			$this->settings,
			new SetupWizardProgress( $this->settings ),
			new ClassicCheckoutRuntimeActivation( $this->flags, $this->settings ),
			new SiteWideDefaultSummary( $repository )
		);

		return new NeedsAttentionQuery(
			$catalog,
			new OperationalReadinessAssessor( $resolver ),
			$state,
			$classifier,
			$repository
		);
	}

	private function render_overview( NeedsAttentionCountQuery $counter ): string {
		$wizard = ( new ReflectionClass( SetupWizardPage::class ) )->newInstanceWithoutConstructor();
		$page   = new OverviewPage(
			$wizard,
			$this->progress,
			$this->defaults,
			$this->settings,
			new SiteWideDefaultSummary( new InMemoryScopedConfigurationRepository() ),
			$counter,
			new AdminActionHandler( new AdminNoticeService() ),
			$this->operational
		);

		ob_start();
		try {
			$page->render();
			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			ob_end_clean();
			throw $error;
		}
	}

	private function render_page( NeedsAttentionCountQuery $counter, NeedsAttentionQuery $catalog ): string {
		$service = ( new ReflectionClass( ShipmentService::class ) )->newInstanceWithoutConstructor();
		$page    = new NeedsAttentionPage(
			$catalog,
			new AdminActionHandler( new AdminNoticeService() ),
			$this->operational,
			$this->creation(),
			$service,
			$this->flags,
			$this->operations(),
			$counter,
			$this->cod,
			$this->bulk()
		);

		ob_start();
		try {
			$page->render();
			return (string) ob_get_clean();
		} catch ( \Throwable $error ) {
			ob_end_clean();
			throw $error;
		}
	}

	private function attention_tile_value( string $html ): string {
		self::assertSame( 1, preg_match( '/Needs attention.*?<p class="cetech-de-stat-card-value">([^<]*)<\/p>/s', $html, $match ) );

		return trim( (string) $match[1] );
	}

	private function store_shipment( int $order_id, ShipmentStatus $status ): Shipment {
		$created = $this->shipments->create(
			Shipment::create(
				$order_id,
				'g-' . $order_id,
				$status,
				delivery_offer_public_label: 'Air Shipping',
				shipment_number: $order_id . '-D1'
			)
		);
		$this->shipments->replaceItems(
			$created->id,
			[ ShipmentItem::create( $created->id, $order_id, 501, 1, 10, null, 'Widget' ) ]
		);

		return $created;
	}

	private function store_stale_bulk_job(): void {
		$saved = $this->bulk_jobs->save_job(
			BulkJob::create( BulkOperationType::CatalogUpdate, 3, [ 'target' => 'products' ], [ 'action' => 'scan' ] )
				->with_status( BulkJobStatus::Queued )
		);
		$this->bulk_jobs->save_job(
			$saved->with(
				[
					'updated_at'  => gmdate( 'Y-m-d H:i:s', time() - 700 ),
					'created_at'  => gmdate( 'Y-m-d H:i:s', time() - 700 ),
					'claim_token' => null,
					'claimed_at'  => null,
				]
			)
		);
	}

	private function store_fresh_bulk_job(): void {
		$this->bulk_jobs->save_job(
			BulkJob::create( BulkOperationType::ValidationScan, 3, [ 'target' => 'products' ], [ 'action' => 'scan' ] )
				->with_status( BulkJobStatus::Queued )
		);
	}

	private function seed_cod( int $order_id ): void {
		$this->shipment_service->create_for_paid_order( $this->paid_delivery_order( $order_id, 'cod' ) );
	}

	private function paid_delivery_order( int $order_id, string $payment_method ): \WC_Order {
		$group_id = 'international|delivery|12';
		$line     = ShipmentCreationFixtures::line( $group_id );

		return ShipmentCreationFixtures::paid_order(
			$order_id,
			[ ShipmentCreationFixtures::product_item_from_line( $line ) ],
			[ ShipmentCreationFixtures::wc_shipping_line( $group_id ) ],
			ShipmentCreationFixtures::package( [ ShipmentCreationFixtures::group( $group_id ) ] ),
			true,
			'processing',
			null,
			$payment_method
		);
	}

	private function register_menu( NeedsAttentionCountQuery $counter ): void {
		$GLOBALS['cetech_de_test_menus']    = [];
		$GLOBALS['cetech_de_test_submenus'] = [];
		$this->menu( $counter )->add_menus();
	}

	private function menu_html( NeedsAttentionCountQuery $counter ): string {
		$this->register_menu( $counter );
		$parts = [];
		foreach ( $GLOBALS['cetech_de_test_menus'] as $item ) {
			$parts[] = (string) ( $item[1] ?? '' );
		}
		foreach ( $GLOBALS['cetech_de_test_submenus'] as $item ) {
			$parts[] = (string) ( $item['menu_title'] ?? '' );
		}

		return implode( "\n", $parts );
	}

	private function badge_count( NeedsAttentionCountQuery $counter ): int {
		$menu = $this->menu( $counter );
		$method = new \ReflectionMethod( $menu, 'needs_attention_badge_count' );

		return (int) $method->invoke( $menu );
	}

	private function menu( NeedsAttentionCountQuery $counter ): AdminMenu {
		$reflection = new ReflectionClass( AdminMenu::class );
		$menu       = $reflection->newInstanceWithoutConstructor();

		foreach ( $reflection->getProperties() as $property ) {
			$name = $property->getName();
			if ( 'feature_flags' === $name ) {
				$property->setValue( $menu, $this->flags );
				continue;
			}
			if ( 'wizard_progress' === $name ) {
				$property->setValue( $menu, $this->progress );
				continue;
			}
			if ( 'needs_attention_count' === $name ) {
				$property->setValue( $menu, $counter );
				continue;
			}
			$type = $property->getType();
			if ( $type instanceof \ReflectionNamedType && $type->allowsNull() ) {
				$property->setValue( $menu, null );
				continue;
			}
			if ( $type instanceof \ReflectionNamedType && ! $type->isBuiltin() ) {
				$property->setValue(
					$menu,
					( new ReflectionClass( $type->getName() ) )->newInstanceWithoutConstructor()
				);
			}
		}

		return $menu;
	}

	private function submenu_title_starting( string $prefix ): string {
		foreach ( $GLOBALS['cetech_de_test_submenus'] as $item ) {
			$title = (string) ( $item['menu_title'] ?? '' );
			if ( str_starts_with( trim( strip_tags( $title ) ), $prefix ) ) {
				return trim( strip_tags( $title ) );
			}
		}

		return '';
	}

	/**
	 * @return list<string>
	 */
	private function plain_submenu_titles(): array {
		$titles = [];
		foreach ( $GLOBALS['cetech_de_test_submenus'] as $item ) {
			$titles[] = trim( strip_tags( (string) ( $item['menu_title'] ?? '' ) ) );
		}

		return $titles;
	}

	private function empty_legacy_rules(): ProductDeliveryRuleRepositoryInterface {
		return new class() implements ProductDeliveryRuleRepositoryInterface {
			public function findById( int $id ): ?array {
				return null;
			}

			public function findByTarget( string $target_type, int $target_id ): array {
				return [];
			}

			public function findByTargetAndAvailability( string $target_type, int $target_id, string $availability ): array {
				return [];
			}

			public function list( array $filters = [] ): array {
				return [];
			}

			public function listActive( array $filters = [] ): array {
				return [];
			}

			public function findActiveByTargets( array $targets ): array {
				return [];
			}

			public function save( array $data ): int {
				return 0;
			}

			public function deactivate( int $id ): bool {
				return false;
			}

			public function hardDelete( int $id ): bool {
				return false;
			}

			public function count_all(): int {
				return 0;
			}

			public function countBySupplierId( int $supplier_id ): int {
				return 0;
			}

			public function countByOriginId( int $origin_id ): int {
				return 0;
			}

			public function countByLogisticsProfileId( int $logistics_profile_id ): int {
				return 0;
			}
		};
	}
}
