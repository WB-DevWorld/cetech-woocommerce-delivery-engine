<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Shipment;

use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Capabilities\RoleAccessService;
use CetechDeliveryEngine\Domain\Enum\ShipmentStatus;
use CetechDeliveryEngine\Domain\Shipment\Shipment;
use CetechDeliveryEngine\Domain\Shipment\ShipmentItem;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdministratorAccessRecovery;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use PHPUnit\Framework\TestCase;

final class ShipmentOperationsAccessTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cetech_de_test_options']  = [ 'cetech_de_enable_shipment_records' => 1 ];
		$GLOBALS['cetech_de_test_roles']    = [];
		$GLOBALS['cetech_de_test_caps']     = [];
		$GLOBALS['cetech_de_test_is_admin'] = true;
		$GLOBALS['cetech_de_test_wp_roles'] = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $roles = [];
		};
		$GLOBALS['cetech_de_test_nonce_ok']  = true;
		$GLOBALS['cetech_de_test_redirects'] = [];
		$_POST = [];
		$_GET  = [];
	}

	public function test_access_matrix_exposes_shipment_capabilities(): void {
		$keys = array_column( RoleAccessService::permissions(), 'key' );

		self::assertContains( RoleAccessService::PERMISSION_SHIPMENTS, $keys );
		self::assertContains( RoleAccessService::PERMISSION_SHIPMENT_STATUS, $keys );
		self::assertSame( 'manage_shipments', RoleAccessService::capability_for( RoleAccessService::PERMISSION_SHIPMENTS ) );
		self::assertSame( 'update_shipment_status', RoleAccessService::capability_for( RoleAccessService::PERMISSION_SHIPMENT_STATUS ) );
	}

	public function test_administrator_remains_protected_full_access_including_shipments(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [ Capabilities::VIEW ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'administrator' => array_fill_keys(
					array_column( RoleAccessService::permissions(), 'key' ),
					false
				),
			]
		);

		self::assertTrue( $admin->has_cap( 'manage_shipments' ) );
		self::assertTrue( $admin->has_cap( 'update_shipment_status' ) );
		foreach ( Capabilities::ADMINISTRATOR_RECOVERY as $capability ) {
			self::assertTrue( $admin->has_cap( $capability ), $capability );
		}
	}

	public function test_recovery_still_depends_on_manage_options_not_shipment_caps(): void {
		$admin = $this->install_role( 'administrator', 'Administrator', [] );
		$GLOBALS['cetech_de_test_caps'] = [
			'manage_shipments'       => true,
			'update_shipment_status' => true,
		];

		$recovery = new AdministratorAccessRecovery( new Capabilities() );
		self::assertFalse( $recovery->can_recover() );

		$GLOBALS['cetech_de_test_caps'] = [ 'manage_options' => true ];
		self::assertTrue( $recovery->can_recover() );
		self::assertTrue( $recovery->restore_if_authorized() );
		self::assertTrue( $admin->has_cap( 'manage_shipments' ) );
		self::assertTrue( $admin->has_cap( 'update_shipment_status' ) );
	}

	public function test_grant_and_revoke_shipment_caps_on_subordinate_role(): void {
		$this->install_role( 'administrator', 'Administrator', Capabilities::ALL );
		$shop = $this->install_role( 'shop_manager', 'Shop Manager', [ Capabilities::VIEW ] );
		$GLOBALS['cetech_de_test_caps']['manage_options'] = true;

		( new RoleAccessService() )->apply(
			[
				'shop_manager' => [
					RoleAccessService::PERMISSION_VIEW            => true,
					RoleAccessService::PERMISSION_SHIPMENTS       => true,
					RoleAccessService::PERMISSION_SHIPMENT_STATUS => false,
				],
			]
		);

		self::assertTrue( $shop->has_cap( 'manage_shipments' ) );
		self::assertTrue( empty( $shop->capabilities['update_shipment_status'] ) );

		( new RoleAccessService() )->apply(
			[
				'shop_manager' => [
					RoleAccessService::PERMISSION_VIEW            => true,
					RoleAccessService::PERMISSION_SHIPMENTS       => true,
					RoleAccessService::PERMISSION_SHIPMENT_STATUS => true,
				],
			]
		);
		self::assertTrue( $shop->has_cap( 'update_shipment_status' ) );

		( new RoleAccessService() )->apply(
			[
				'shop_manager' => [
					RoleAccessService::PERMISSION_VIEW      => true,
					RoleAccessService::PERMISSION_SHIPMENTS => false,
				],
			]
		);
		self::assertTrue( empty( $shop->capabilities['manage_shipments'] ) );
		self::assertTrue( empty( $shop->capabilities['update_shipment_status'] ) );
	}

	public function test_inspect_without_status_cap_cannot_post_status_or_see_actions(): void {
		$repository = ShipmentCreationFixtures::repository();
		$shipment   = $repository->create(
			Shipment::create(
				4501,
				'g-4501',
				delivery_offer_public_label: 'Air',
				currency_code: 'GBP',
				customer_paid_shipping_amount: '25.00',
				shipment_number: '4501-D1'
			)
		);
		$repository->replaceItems(
			$shipment->id,
			[ ShipmentItem::create( $shipment->id, 4501, 501, 1, 10, null, 'Widget' ) ]
		);

		$status = new ShipmentStatusService( $repository, new ShipmentOperationsIssueStore() );
		$page   = new ShipmentsPage(
			new FeatureFlags(),
			new ShipmentWorkspaceQuery( $repository ),
			new AdminActionHandler( new AdminNoticeService() ),
			null,
			$status
		);

		$GLOBALS['cetech_de_test_caps'] = [
			'manage_shipments'       => true,
			'update_shipment_status' => false,
		];

		$_GET['shipment'] = (string) $shipment->id;
		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '4501-D1', $html );
		self::assertStringNotContainsString( 'Mark as Processing', $html );
		self::assertStringNotContainsString( 'Correct status', $html );

		$_POST = [
			'cetech_de_action' => ShipmentsPage::ACTION_CHANGE_STATUS,
			'cetech_de_nonce'  => 'test-nonce-' . ShipmentsPage::ACTION_CHANGE_STATUS,
			'shipment_id'      => (string) $shipment->id,
			'target_status'    => ShipmentStatus::Processing->value,
		];

		try {
			$page->handle_actions();
			self::fail( 'Status POST without capability should redirect.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 'cetech_de_test_redirect', $exception->getMessage() );
		}

		self::assertSame( ShipmentStatus::AwaitingFulfilment, $repository->findById( $shipment->id )?->status );
	}

	public function test_shipments_menu_uses_capability_not_role_name(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Presentation/Admin/AdminMenu.php' );
		self::assertStringContainsString( "current_user_can( 'manage_shipments' )", $source );
		self::assertStringContainsString( "'manage_shipments'", $source );
		self::assertStringNotContainsString( "=== 'shop_manager'", $source );
		self::assertSame( 'cetech-delivery-engine-shipments', ShipmentsPage::SLUG );
		self::assertTrue( method_exists( AdminMenu::class, 'should_show_shipments_menu' ) );
	}

	/**
	 * @param list<string> $caps
	 */
	private function install_role( string $slug, string $name, array $caps = [] ): object {
		$role = new class() {
			/** @var array<string, bool> */
			public array $capabilities = [];

			public function add_cap( string $cap ): void {
				$this->capabilities[ $cap ] = true;
			}

			public function remove_cap( string $cap ): void {
				unset( $this->capabilities[ $cap ] );
			}

			public function has_cap( string $cap ): bool {
				return ! empty( $this->capabilities[ $cap ] );
			}
		};

		foreach ( $caps as $cap ) {
			$role->add_cap( $cap );
		}

		$GLOBALS['cetech_de_test_roles'][ $slug ] = $role;
		$GLOBALS['cetech_de_test_wp_roles']->roles[ $slug ] = [
			'name'         => $name,
			'capabilities' => $role->capabilities,
		];

		return $role;
	}
}
