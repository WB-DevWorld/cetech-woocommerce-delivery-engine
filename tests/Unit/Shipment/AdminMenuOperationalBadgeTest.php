<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionCountQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentActivityCursor;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusChangeRequest;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\AdminMenuBadgeMarkup;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AdminMenuOperationalBadgeTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private FakeWpdb $wpdb;

	private ShipmentOperationsIssueStore $issues;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']             = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_caps']                = [
			'manage_shipments'              => true,
			'manage_product_delivery_rules' => false,
			'edit_shop_orders'              => true,
		];
		$GLOBALS['cetech_de_test_user_id']             = 3;
		$GLOBALS['cetech_de_test_user_meta']           = [];
		$GLOBALS['cetech_de_test_submenus']            = [];
		$GLOBALS['cetech_de_test_menus']               = [];
		$GLOBALS['cetech_de_test_wc_get_orders_calls'] = [];
		$_GET = [];

		$this->wpdb = new FakeWpdb();
		$this->wpdb->create_table(
			'wp_delivery_engine_shipments',
			[
				[ 'idempotency_key' ],
				[ 'order_id', 'delivery_group_id' ],
			]
		);
		$this->wpdb->create_table(
			'wp_delivery_engine_shipment_items',
			[
				[ 'shipment_id', 'order_item_id' ],
			]
		);
		$this->wpdb->create_table( 'wp_delivery_engine_shipment_events' );
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->repository = new WpdbShipmentRepository();
		$this->issues     = new ShipmentOperationsIssueStore();
	}

	public function test_needs_attention_badge_shows_unresolved_delayed_and_clears_after_recovery(): void {
		$delayed = $this->store_shipment( 5101, ShipmentStatus::Delayed );
		$counter = $this->attention_counter();

		self::assertSame( 1, $counter->unresolved_count_for_current_user() );

		$html = $this->menu_titles_html( $counter, $this->activity() );
		self::assertStringContainsString( 'Needs Attention', $html );
		self::assertStringContainsString( 'awaiting-mod', $html );
		self::assertStringContainsString( '>1<', $html );
		self::assertStringContainsString( 'item needs attention', $html );
		self::assertStringContainsString( 'Delivery Engine', $html );

		( new ShipmentStatusService( $this->repository, $this->issues ) )->change(
			$delayed->id,
			ShipmentStatus::InTransit,
			ShipmentStatusChangeRequest::staff_normal( 'Moving again', 3 )
		);

		self::assertSame( 0, $counter->unresolved_count_for_current_user() );
		$after = $this->menu_titles_html( $counter, $this->activity() );
		self::assertSame( 'Needs Attention', $this->submenu_title_starting( 'Needs Attention' ) );
		self::assertStringNotContainsString( 'awaiting-mod', $this->submenu_title_starting( 'Needs Attention' ) );
		unset( $after );
	}

	public function test_awaiting_fulfilment_is_not_needs_attention(): void {
		$this->store_shipment( 5102, ShipmentStatus::AwaitingFulfilment );
		$this->store_shipment( 5103, ShipmentStatus::Processing );

		self::assertSame( 0, $this->attention_counter()->unresolved_count_for_current_user() );
	}

	public function test_unauthorized_user_sees_no_operational_counts(): void {
		$this->store_shipment( 5104, ShipmentStatus::Delayed );
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = false;
		$GLOBALS['cetech_de_test_caps']['manage_product_delivery_rules'] = false;

		$counter  = $this->attention_counter();
		$activity = $this->activity();

		self::assertFalse( $counter->current_user_can_see_needs_attention() );
		self::assertSame( 0, $counter->unresolved_count_for_current_user() );
		self::assertSame( 0, $activity->unreviewed_shipment_count_for_current_user() );

		$this->menu( $counter, $activity )->add_menus();
		self::assertNotContains( 'Shipments', $this->plain_submenu_titles() );
		self::assertNotContains( 'Needs Attention', $this->plain_submenu_titles() );
	}

	public function test_shipments_activity_badge_is_user_specific_and_uses_distinct_shipments(): void {
		$first  = $this->store_shipment( 5201, ShipmentStatus::AwaitingFulfilment );
		$second = $this->store_shipment( 5202, ShipmentStatus::AwaitingFulfilment );
		$this->append_event( $first->id, ShipmentEventType::Created );
		$this->append_event( $second->id, ShipmentEventType::Created );
		$this->append_event( $first->id, ShipmentEventType::StatusChanged );

		$activity = $this->activity();
		self::assertSame( 2, $activity->unreviewed_shipment_count_for_current_user() );

		$before_queries = $this->wpdb->query_count;
		self::assertSame( 2, $activity->unreviewed_shipment_count_for_current_user() );
		self::assertSame( 1, $this->wpdb->query_count - $before_queries );
		self::assertGreaterThan( 0, $this->count_sql_matching( '/COUNT\(\s*DISTINCT\s+`shipment_id`\s*\)/' ) );
		self::assertSame( [], $GLOBALS['cetech_de_test_wc_get_orders_calls'] );

		$html = $this->menu_titles_html( $this->attention_counter(), $activity );
		self::assertStringContainsString( 'shipments with new activity', $html );

		$page = new ShipmentsPage(
			new FeatureFlags(),
			new ShipmentWorkspaceQuery( $this->repository ),
			null,
			null,
			null,
			null,
			$activity
		);
		ob_start();
		$page->render();
		ob_end_clean();

		self::assertSame( 0, $activity->unreviewed_shipment_count_for_current_user() );

		$GLOBALS['cetech_de_test_user_id'] = 4;
		self::assertSame( 2, $activity->unreviewed_shipment_count_for_current_user() );

		$GLOBALS['cetech_de_test_user_id'] = 3;
		$this->append_event( $second->id, ShipmentEventType::TrackingAdded );
		self::assertSame( 1, $activity->unreviewed_shipment_count_for_current_user() );
	}

	public function test_badge_markup_caps_at_ninety_nine_plus(): void {
		$html = AdminMenuBadgeMarkup::append( 'Shipments', 120, '120 shipments with new activity' );
		self::assertStringContainsString( '99+', $html );
		self::assertStringContainsString( 'screen-reader-text', $html );
	}

	private function attention_counter(): NeedsAttentionCountQuery {
		$catalog = ( new ReflectionClass( NeedsAttentionQuery::class ) )->newInstanceWithoutConstructor();

		return new NeedsAttentionCountQuery(
			$catalog,
			new ShipmentCreationIssueQuery( new ShipmentCreationFailureStore() ),
			new ShipmentOperationsIssueQuery( new FeatureFlags(), $this->repository, $this->issues ),
			new FeatureFlags()
		);
	}

	private function activity(): ShipmentActivityCursor {
		return new ShipmentActivityCursor( new FeatureFlags(), $this->repository );
	}

	private function menu( NeedsAttentionCountQuery $counter, ShipmentActivityCursor $activity ): AdminMenu {
		$reflection = new ReflectionClass( AdminMenu::class );
		$menu       = $reflection->newInstanceWithoutConstructor();
		$page       = new ShipmentsPage( new FeatureFlags(), new ShipmentWorkspaceQuery( $this->repository ) );

		foreach ( $reflection->getProperties() as $property ) {
			$name = $property->getName();

			if ( 'feature_flags' === $name ) {
				$property->setValue( $menu, new FeatureFlags() );
				continue;
			}

			if ( 'wizard_progress' === $name ) {
				$property->setValue(
					$menu,
					new \CetechDeliveryEngine\Application\Configuration\SetupWizardProgress(
						new \CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings()
					)
				);
				continue;
			}

			if ( 'shipments_page' === $name ) {
				$property->setValue( $menu, $page );
				continue;
			}

			if ( 'needs_attention_count' === $name ) {
				$property->setValue( $menu, $counter );
				continue;
			}

			if ( 'shipment_activity' === $name ) {
				$property->setValue( $menu, $activity );
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

	private function menu_titles_html( NeedsAttentionCountQuery $counter, ShipmentActivityCursor $activity ): string {
		$GLOBALS['cetech_de_test_menus']    = [];
		$GLOBALS['cetech_de_test_submenus'] = [];
		$this->menu( $counter, $activity )->add_menus();

		$parts = [];

		foreach ( $GLOBALS['cetech_de_test_menus'] as $item ) {
			$parts[] = (string) ( $item[1] ?? '' );
		}

		foreach ( $GLOBALS['cetech_de_test_submenus'] as $item ) {
			$parts[] = (string) ( $item['menu_title'] ?? '' );
		}

		return implode( "\n", $parts );
	}

	private function submenu_title_starting( string $prefix ): string {
		foreach ( $GLOBALS['cetech_de_test_submenus'] as $item ) {
			$title = (string) ( $item['menu_title'] ?? '' );

			if ( str_starts_with( strip_tags( $title ), $prefix ) ) {
				return $title;
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

	private function store_shipment( int $order_id, ShipmentStatus $status ): Shipment {
		$created = $this->repository->create(
			Shipment::create(
				$order_id,
				'g-' . $order_id,
				$status,
				delivery_offer_public_label: 'Air Shipping',
				shipment_number: $order_id . '-D1'
			)
		);
		$this->repository->replaceItems(
			$created->id,
			[ ShipmentItem::create( $created->id, $order_id, 501, 1, 10, null, 'Widget' ) ]
		);

		return $created;
	}

	private function append_event( int $shipment_id, ShipmentEventType $type ): void {
		$this->repository->appendEvent(
			ShipmentEvent::create(
				$shipment_id,
				$type,
				ShipmentEventSource::Staff,
				null,
				null,
				null,
				null,
				3
			)
		);
	}

	private function count_sql_matching( string $pattern ): int {
		$count = 0;

		foreach ( $this->wpdb->sql_log as $sql ) {
			if ( 1 === preg_match( $pattern, $sql ) ) {
				++$count;
			}
		}

		return $count;
	}
}
