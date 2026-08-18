<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bootstrap;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationHealthChecker;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotPersister;
use CetechDeliveryEngine\Application\Shipment\PaidOrderShipmentSubscriber;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingIntegration;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Bootstrap\Plugin;
use CetechDeliveryEngine\Bootstrap\ServiceContainer;
use CetechDeliveryEngine\Core\AdminNoticeManager;
use CetechDeliveryEngine\Core\Health\HealthCheckRegistry;
use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\EffectiveConfigurationPreviewPage;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;
use CetechDeliveryEngine\Presentation\Admin\SystemStatusPage;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer;
use CetechDeliveryEngine\Presentation\Frontend\VariableDeliverySelectorAssets;
use CetechDeliveryEngine\Application\Selector\VariationDeliveryOptionsEndpoint;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Catches missing imports / broken boot wiring that pure domain unit tests miss.
 *
 * Remaining difference from real WordPress boot: no WP/WC runtime, no hook
 * registration, no DB queries — only container registration + eager construction
 * of the services Plugin::boot() resolves.
 */
final class PluginBootServiceGraphTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];

		if ( ! defined( 'CETECH_DE_PATH' ) ) {
			define( 'CETECH_DE_PATH', dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR );
		}

		if ( ! defined( 'CETECH_DE_FILE' ) ) {
			define( 'CETECH_DE_FILE', CETECH_DE_PATH . 'cetech-woocommerce-delivery-engine.php' );
		}

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', CETECH_DE_PATH );
		}
	}

	public function test_configuration_health_checker_resolves_through_container_factory(): void {
		$container = $this->register_services_container();

		self::assertTrue( $container->has( ConfigurationHealthChecker::class ) );

		$checker = $container->get( ConfigurationHealthChecker::class );

		self::assertInstanceOf( ConfigurationHealthChecker::class, $checker );
		self::assertSame(
			'CetechDeliveryEngine\\Application\\Diagnostics\\ConfigurationHealthChecker',
			$checker::class
		);
	}

	public function test_admin_menu_boot_path_resolves_system_status_and_health_checker(): void {
		$container = $this->register_services_container();

		// Mirrors Plugin::boot() admin path: AdminMenu → SystemStatusPage → ConfigurationHealthChecker.
		$menu = $container->get( AdminMenu::class );
		self::assertInstanceOf( AdminMenu::class, $menu );

		$status = $container->get( SystemStatusPage::class );
		self::assertInstanceOf( SystemStatusPage::class, $status );

		$checker = $container->get( ConfigurationHealthChecker::class );
		self::assertInstanceOf( ConfigurationHealthChecker::class, $checker );

		$shipments = $container->get( ShipmentRepositoryInterface::class );
		self::assertInstanceOf( WpdbShipmentRepository::class, $shipments );
	}

	public function test_boot_eager_service_graph_constructs_without_class_not_found(): void {
		$container = $this->register_services_container();

		$eager_ids = [
			AdminNoticeManager::class,
			MigrationRunner::class,
			AdminMenu::class,
			IntegrationRegistry::class,
			HealthCheckRegistry::class,
			ProductDeliverySelectorRenderer::class,
			VariableDeliverySelectorAssets::class,
			VariationDeliveryOptionsEndpoint::class,
			CartDeliverySelectionCapture::class,
			CartDeliverySelectionRevalidator::class,
			CheckoutDeliverySelectionValidator::class,
			ShippingPackageBuilder::class,
			SelectedOfferShippingIntegration::class,
			PaidOrderShipmentSubscriber::class,
			OrderDeliverySnapshotPersister::class,
			CustomerOrderDeliverySummaryRenderer::class,
			CustomerOrderDeliveryEmailSummaryRenderer::class,
			OrderDeliverySnapshotAdminDisplay::class,
			ConfigurationHealthChecker::class,
			EffectiveConfigurationPreviewPage::class,
			ScopedConfigurationPage::class,
			SystemStatusPage::class,
			\CetechDeliveryEngine\Presentation\Admin\DeliverySettingsHomePage::class,
			\CetechDeliveryEngine\Presentation\Admin\ProductExceptionsPage::class,
			\CetechDeliveryEngine\Presentation\Admin\NeedsAttentionPage::class,
			\CetechDeliveryEngine\Presentation\Admin\OverviewPage::class,
			\CetechDeliveryEngine\Presentation\Admin\SetupWizardPage::class,
			\CetechDeliveryEngine\Presentation\Admin\ProductDeliveryPanel::class,
		];

		foreach ( $eager_ids as $id ) {
			self::assertTrue( $container->has( $id ), "Missing registration for {$id}" );
			$service = $container->get( $id );
			self::assertIsObject( $service, "Failed to construct {$id}" );
		}
	}

	public function test_plugin_php_imports_configuration_health_checker_from_diagnostics_namespace(): void {
		$plugin_source = file_get_contents( CETECH_DE_PATH . 'src/Bootstrap/Plugin.php' );
		self::assertIsString( $plugin_source );
		self::assertStringContainsString(
			'use CetechDeliveryEngine\\Application\\Diagnostics\\ConfigurationHealthChecker;',
			$plugin_source
		);
		self::assertSame(
			'CetechDeliveryEngine\\Application\\Diagnostics\\ConfigurationHealthChecker',
			ConfigurationHealthChecker::class
		);
		self::assertNotSame(
			'CetechDeliveryEngine\\Bootstrap\\ConfigurationHealthChecker',
			ConfigurationHealthChecker::class
		);
	}

	private function register_services_container(): ServiceContainer {
		$reflection = new ReflectionClass( Plugin::class );
		$plugin     = $reflection->newInstanceWithoutConstructor();

		$container_property = $reflection->getProperty( 'container' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$container_property->setAccessible( true );
		}
		$container_property->setValue( $plugin, new ServiceContainer() );

		$register = $reflection->getMethod( 'register_services' );
		if ( \PHP_VERSION_ID < 80500 ) {
			$register->setAccessible( true );
		}
		$register->invoke( $plugin );

		/** @var ServiceContainer $container */
		$container = $container_property->getValue( $plugin );

		return $container;
	}
}