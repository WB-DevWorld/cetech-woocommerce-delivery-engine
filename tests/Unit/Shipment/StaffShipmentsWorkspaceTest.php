<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventSource;
use CetechDeliveryEngine\Domain\Enum\ShipmentEventType;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentEvent;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Presentation\Admin\AdminLanguage;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\ShipmentPresentation;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

final class StaffShipmentsWorkspaceTest extends TestCase {

	private WpdbShipmentRepository $repository;

	private FakeWpdb $wpdb;

	private ShipmentWorkspaceQuery $query;

	private ShipmentsPage $page;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']              = [];
		$GLOBALS['cetech_de_test_caps']                 = [ 'manage_shipments' => true, 'edit_shop_orders' => true ];
		$GLOBALS['cetech_de_test_wc_orders']            = [];
		$GLOBALS['cetech_de_test_wc_get_orders_calls']  = [];
		$GLOBALS['cetech_de_test_submenus']             = [];
		$GLOBALS['cetech_de_test_menus']                = [];
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
		$this->query      = new ShipmentWorkspaceQuery( $this->repository );
		$this->page       = new ShipmentsPage( new FeatureFlags(), $this->query );
	}

	public function test_feature_off_hides_shipments_menu(): void {
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = true;
		$menu = $this->menu();
		$menu->add_menus();

		self::assertFalse( $menu->should_show_shipments_menu() );
		self::assertNotContains( 'Shipments', $this->submenu_titles() );
	}

	public function test_feature_on_authorized_shows_shipments_menu_between_exceptions_and_needs_attention(): void {
		$this->enable_flag();
		$GLOBALS['cetech_de_test_caps']['manage_shipments']            = true;
		$GLOBALS['cetech_de_test_caps']['manage_product_delivery_rules'] = true;

		$menu = $this->menu();
		self::assertTrue( $menu->should_show_shipments_menu() );
		$menu->add_menus();

		$titles = $this->normal_submenu_titles();
		self::assertContains( 'Shipments', $titles );
		self::assertSame(
			[ 'Product Exceptions', 'Shipments', 'Needs Attention' ],
			array_values(
				array_intersect( $titles, [ 'Product Exceptions', 'Shipments', 'Needs Attention' ] )
			)
		);
		self::assertSame( 'Shipments', AdminLanguage::menu_shipments() );
		self::assertContains( 'Shipments', AdminMenu::normal_menu_titles() );
		self::assertNotContains( 'Legacy Delivery Rules', AdminMenu::normal_menu_titles() );
		self::assertNotContains( 'Technical Diagnostic Tools', AdminMenu::normal_menu_titles() );
	}

	public function test_unauthorized_user_has_no_shipments_menu(): void {
		$this->enable_flag();
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = false;
		$menu = $this->menu();
		$menu->add_menus();

		self::assertFalse( $menu->should_show_shipments_menu() );
		self::assertNotContains( 'Shipments', $this->submenu_titles() );
	}

	public function test_unauthorized_direct_url_is_rejected(): void {
		$this->enable_flag();
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = false;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->page->render();
	}

	public function test_feature_off_direct_url_is_rejected(): void {
		$GLOBALS['cetech_de_test_caps']['manage_shipments'] = true;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		$this->page->render();
	}

	public function test_empty_state_when_feature_on_and_no_shipments(): void {
		$this->enable_flag();
		$html = $this->render_html();

		self::assertStringContainsString( 'No shipments have been created yet.', $html );
		self::assertStringContainsString( 'Paid Delivery Engine orders will appear here', $html );
	}

	public function test_list_shows_historical_label_status_items_and_tracking_state(): void {
		$this->enable_flag();
		$shipment = $this->store_shipment(
			order_id: 1001,
			group: 'g-air',
			number: '1001-D1',
			label: 'International Air',
			item_count: 3
		);
		$this->store_order( 1001, 'Ada Lovelace' );

		$html = $this->render_html();

		self::assertStringContainsString( '1001-D1', $html );
		self::assertStringContainsString( 'International Air', $html );
		self::assertStringContainsString( 'Awaiting fulfilment', $html );
		self::assertStringContainsString( '3 items', $html );
		self::assertStringContainsString( 'Not added', $html );
		self::assertStringContainsString( 'Ada Lovelace', $html );
		self::assertStringContainsString( 'Order 1001', $html );
		self::assertStringNotContainsString( 'Today Air Express', $html );
		self::assertStringNotContainsString( $shipment->idempotency_key, $html );
		self::assertStringNotContainsString( 'name="status_action"', $html );
	}

	public function test_status_filter_uses_machine_codes_not_translated_labels(): void {
		$this->enable_flag();
		$first = $this->store_shipment( 11, 'g-a', '11-D1', 'Air' );
		$second = $this->store_shipment( 12, 'g-b', '12-D1', 'Sea' );
		$this->repository->update( $second->withStatus( ShipmentStatus::Dispatched ) );

		$filtered = $this->query->list( '', ShipmentStatus::Dispatched->value, 1, 20 );
		self::assertSame( 1, $filtered->total );
		self::assertSame( 12, $filtered->rows[0]->shipment->order_id );

		$by_label = $this->query->list( '', 'Dispatched', 1, 20 );
		self::assertSame( 0, $by_label->total );

		$html = $this->render_html( [ 'status' => ShipmentStatus::Dispatched->value ] );
		self::assertStringContainsString( '12-D1', $html );
		self::assertStringNotContainsString( '11-D1', $html );
		self::assertStringContainsString( 'value="awaiting_fulfilment"', $this->render_html() );
		self::assertStringContainsString( 'Delayed / issue', $this->render_html() );
		unset( $first );
	}

	public function test_search_matches_reference_order_id_and_tracking_prefix(): void {
		$this->enable_flag();
		$plain = $this->store_shipment( 44, 'g-1', '44-D1', 'Air' );
		$tracked = $this->store_shipment( 55, 'g-2', '55-D1', 'Sea' );
		$this->repository->update(
			$tracked->withTracking( 'TRK-999', 'https://example.test/t/TRK-999', 'Courier' )
		);

		$by_number = $this->query->list( '55-D', '', 1, 20 );
		self::assertSame( 1, $by_number->total );
		self::assertSame( '55-D1', $by_number->rows[0]->shipment->shipment_number );

		$by_order = $this->query->list( '44', '', 1, 20 );
		self::assertSame( 1, $by_order->total );
		self::assertSame( 44, $by_order->rows[0]->shipment->order_id );

		$by_tracking = $this->query->list( 'TRK-9', '', 1, 20 );
		self::assertSame( 1, $by_tracking->total );
		self::assertSame( 'Tracking available', ShipmentPresentation::tracking_state_label( $by_tracking->rows[0]->shipment ) );
		unset( $plain );
	}

	public function test_pagination_happens_in_sql_and_list_does_not_load_events_or_all_rows(): void {
		$this->enable_flag();

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->store_shipment( 200 + $i, 'g-' . $i, '20' . $i . '-D1', 'Air', 1 );
		}

		$this->wpdb->query_count = 0;
		$this->wpdb->sql_log     = [];
		$GLOBALS['cetech_de_test_wc_get_orders_calls'] = [];

		$result = $this->query->list( '', '', 1, 2 );

		self::assertSame( 5, $result->total );
		self::assertCount( 2, $result->rows );
		self::assertSame( 1, $result->page );
		self::assertSame( 2, $result->per_page );

		$sql = implode( "\n", $this->wpdb->sql_log );
		self::assertStringContainsString( 'LIMIT 2', $sql );
		self::assertStringContainsString( 'COUNT(*)', $sql );
		self::assertStringContainsString( 'GROUP BY', $sql );
		self::assertStringNotContainsString( 'shipment_events', $sql );
		self::assertCount( 1, $GLOBALS['cetech_de_test_wc_get_orders_calls'] );
		self::assertSame( 2, $this->count_sql_matching( '/FROM `wp_delivery_engine_shipments`/' ) );
	}

	public function test_authorized_detail_shows_order_items_eta_amount_and_history(): void {
		$this->enable_flag();
		$shipment = $this->store_shipment(
			order_id: 77,
			group: 'g-detail',
			number: '77-D1',
			label: 'International Air',
			item_count: 1,
			amount: '25.00',
			eta_original: '5-7 business days',
			eta_current: '8-10 business days',
			product_name: 'Historical Widget'
		);
		$this->repository->appendEvent(
			ShipmentEvent::create(
				$shipment->id,
				ShipmentEventType::Created,
				ShipmentEventSource::System,
				null,
				ShipmentStatus::AwaitingFulfilment
			)
		);
		$this->store_order(
			77,
			'Ada Lovelace',
			[ new WC_Order_Item_Product( [ 'id' => 501, 'name' => 'Current Name After Edit', 'sku' => 'WID-1' ] ) ]
		);

		$html = $this->render_html( [ 'shipment' => (string) $shipment->id ] );

		self::assertStringContainsString( '77-D1', $html );
		self::assertStringContainsString( 'Historical Widget', $html );
		self::assertStringContainsString( 'WID-1', $html );
		self::assertStringContainsString( 'International Air', $html );
		self::assertStringContainsString( 'Original estimate', $html );
		self::assertStringContainsString( 'Current estimate', $html );
		self::assertStringContainsString( '5-7 business days', $html );
		self::assertStringContainsString( '8-10 business days', $html );
		self::assertStringContainsString( 'Customer delivery charge', $html );
		self::assertStringContainsString( '25.00', $html );
		self::assertStringContainsString( 'GBP', $html );
		self::assertStringContainsString( 'Awaiting fulfilment', $html );
		self::assertStringContainsString( 'Not added', $html );
		self::assertStringContainsString( 'Order 77', $html );
		self::assertStringNotContainsString( 'Shipment cost', $html );
		self::assertStringNotContainsString( '>Supplier<', $html );
		self::assertStringNotContainsString( '>Origin<', $html );
		self::assertStringNotContainsString( 'name="tracking_number"', $html );
		self::assertStringNotContainsString( 'Track Shipment', $html );
	}

	public function test_deleted_product_still_shows_snapshot_name(): void {
		$this->enable_flag();
		$shipment = $this->store_shipment(
			88,
			'g-del',
			'88-D1',
			'Air',
			1,
			'10.00',
			'2 days',
			'2 days',
			'Saved order item name'
		);
		$this->store_order( 88, 'Guest Buyer' );

		$html = $this->render_html( [ 'shipment' => (string) $shipment->id ] );
		self::assertStringContainsString( 'Saved order item name', $html );
	}

	public function test_private_note_is_hidden_without_private_capability(): void {
		$this->enable_flag();
		$created = $this->repository->create(
			Shipment::create(
				order_id: 90,
				delivery_group_id: 'g-priv',
				delivery_offer_public_label: 'Air',
				currency_code: 'GBP',
				customer_paid_shipping_amount: '1.00',
				shipment_number: '90-D1',
				private_note: 'Use pallet wrap'
			)
		);
		$this->repository->replaceItems(
			$created->id,
			[ ShipmentItem::create( $created->id, 90, 601, 1, 10, null, 'Widget' ) ]
		);

		$html = $this->render_html( [ 'shipment' => (string) $created->id ] );
		self::assertStringNotContainsString( 'Use pallet wrap', $html );
		self::assertStringNotContainsString( 'Private note', $html );

		$GLOBALS['cetech_de_test_caps']['view_private_delivery_costs'] = true;
		$private = $this->render_html( [ 'shipment' => (string) $created->id ] );
		self::assertStringContainsString( 'Use pallet wrap', $private );
	}

	public function test_unknown_event_code_has_safe_fallback_label(): void {
		self::assertSame(
			'Shipment update',
			ShipmentPresentation::event_type_label( 'future_mystery_event' )
		);
		self::assertSame(
			'Shipment created',
			ShipmentPresentation::event_type_label( ShipmentEventType::Created->value )
		);
		self::assertSame( '1 item', ShipmentPresentation::item_count_label( 1 ) );
		self::assertSame( '2 items', ShipmentPresentation::item_count_label( 2 ) );
	}

	public function test_history_shows_staff_display_name_and_system_label(): void {
		$this->enable_flag();
		$GLOBALS['cetech_de_test_users'][3] = (object) [ 'display_name' => 'Jane Love' ];
		$shipment = $this->store_shipment( 1301, 'g-air', '1301-D1', 'Air Shipping' );
		$this->repository->appendEvent(
			ShipmentEvent::create(
				$shipment->id,
				ShipmentEventType::Created,
				ShipmentEventSource::System
			)
		);
		$this->repository->appendEvent(
			ShipmentEvent::create(
				$shipment->id,
				ShipmentEventType::StatusChanged,
				ShipmentEventSource::Staff,
				ShipmentStatus::AwaitingFulfilment,
				ShipmentStatus::Processing,
				null,
				null,
				3
			)
		);

		$html = $this->render_html( [ 'shipment' => (string) $shipment->id ] );

		self::assertStringContainsString( 'History', $html );
		self::assertStringContainsString( 'Jane Love (Staff)', $html );
		self::assertStringContainsString( 'System', $html );
		self::assertStringNotContainsString( 'Automatic', $html );
	}

	public function test_workspace_query_does_not_reference_current_resolver(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Application/Shipment/ShipmentWorkspaceQuery.php' );
		self::assertStringNotContainsString( 'use CetechDeliveryEngine\\Application\\Configuration\\EffectiveConfigurationResolver', $source );
		self::assertStringNotContainsString( 'ProductDeliveryRule', $source );
		self::assertStringNotContainsString( 'RateCard', $source );

		$page = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/ShipmentsPage.php' );
		self::assertStringNotContainsString( 'EffectiveConfigurationResolver', $page );
		self::assertStringNotContainsString( 'wc_get_product', $page );
	}

	public function test_customer_facing_files_do_not_gain_shipment_workspace(): void {
		$root = dirname( __DIR__, 3 );
		$files = [
			$root . '/src/Presentation/Frontend/ProductDeliverySelectorRenderer.php',
			$root . '/src/Presentation/Frontend/CustomerOrderDeliverySummaryRenderer.php',
			$root . '/src/Presentation/Email/CustomerOrderDeliveryEmailSummaryRenderer.php',
			$root . '/src/Application/Cart/CartDeliverySelectionCapture.php',
			$root . '/src/Application/Checkout/CheckoutDeliverySelectionValidator.php',
		];

		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			self::assertStringNotContainsString( 'ShipmentsPage', $source, $file );
			self::assertStringNotContainsString( 'ShipmentWorkspaceQuery', $source, $file );
		}
	}

	private function enable_flag(): void {
		$GLOBALS['cetech_de_test_options']['cetech_de_enable_shipment_records'] = 1;
		$this->page = new ShipmentsPage( new FeatureFlags(), $this->query );
	}

	/**
	 * @param array<string, string> $get
	 */
	private function render_html( array $get = [] ): string {
		$_GET = $get;
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	private function store_shipment(
		int $order_id,
		string $group,
		string $number,
		string $label,
		int $item_count = 1,
		string $amount = '25.00',
		string $eta_original = '5-7 business days',
		?string $eta_current = null,
		string $product_name = 'Widget'
	): Shipment {
		$created = $this->repository->create(
			Shipment::create(
				order_id: $order_id,
				delivery_group_id: $group,
				delivery_offer_public_label: $label,
				currency_code: 'GBP',
				customer_paid_shipping_amount: $amount,
				eta_original: $eta_original,
				eta_current: $eta_current,
				shipment_number: $number
			)
		);

		$items = [];

		for ( $i = 0; $i < $item_count; $i++ ) {
			$items[] = ShipmentItem::create(
				$created->id,
				$order_id,
				501 + $i,
				1,
				10 + $i,
				null,
				$product_name
			);
		}

		$this->repository->replaceItems( $created->id, $items );

		return $created;
	}

	/**
	 * @param list<WC_Order_Item_Product> $items
	 */
	private function store_order( int $order_id, string $name, array $items = [] ): void {
		$GLOBALS['cetech_de_test_wc_orders'][ $order_id ] = new WC_Order(
			[
				'id'                 => $order_id,
				'order_number'       => (string) $order_id,
				'billing_full_name'  => $name,
				'items'              => $items,
			]
		);
	}

	private function menu(): AdminMenu {
		$reflection = new \ReflectionClass( AdminMenu::class );
		$menu       = $reflection->newInstanceWithoutConstructor();

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
				$property->setValue( $menu, $this->page );
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
					( new \ReflectionClass( $type->getName() ) )->newInstanceWithoutConstructor()
				);
			}
		}

		return $menu;
	}

	private function submenu_titles(): array {
		$titles = [];

		foreach ( $GLOBALS['cetech_de_test_submenus'] ?? [] as $item ) {
			$titles[] = (string) $item['menu_title'];
		}

		return $titles;
	}

	/**
	 * @return list<string>
	 */
	private function normal_submenu_titles(): array {
		$titles = [];

		foreach ( $GLOBALS['cetech_de_test_submenus'] ?? [] as $item ) {
			if ( AdminMenu::HIDDEN_PARENT === ( $item['parent'] ?? '' ) ) {
				continue;
			}

			$titles[] = (string) $item['menu_title'];
		}

		return $titles;
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
