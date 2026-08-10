<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionCapture;
use CetechDeliveryEngine\Application\Cart\CartDeliverySelectionRevalidator;
use CetechDeliveryEngine\Application\Checkout\CheckoutDeliverySelectionValidator;
use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Calculator\AdminRateCardTester;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Destination\PackageDestinationZoneResolver;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotGate;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotPersister;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingIntegration;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\ConfigurationFingerprintBuilder;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\FulfilmentConstraintServiceInterface;
use CetechDeliveryEngine\Application\Configuration\LegacyConfigurationMigrator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\LegacyProductRuleMigrationMapper;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Core\AdminNoticeManager;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\FeaturesCompatibility;
use CetechDeliveryEngine\Core\Health\HealthCheckRegistry;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Core\Versioning\MigrationDiscovery;
use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Domain\Audit\AuditLogRepositoryInterface;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbAuditLogRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDeliveryOfferRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbDestinationZoneRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbLogisticsProfileRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbOriginRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbPickupLocationRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbRateCardRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbSupplierRepository;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminRecordDependencyChecker;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;
use CetechDeliveryEngine\Presentation\Admin\DeliveryOffersPage;
use CetechDeliveryEngine\Presentation\Admin\DestinationZoneTestMatcher;
use CetechDeliveryEngine\Presentation\Admin\DestinationZonesPage;
use CetechDeliveryEngine\Presentation\Admin\EffectiveConfigurationPreviewPage;
use CetechDeliveryEngine\Presentation\Admin\LogisticsProfilesPage;
use CetechDeliveryEngine\Presentation\Admin\PickupLocationsPage;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Admin\ProductDeliveryRulesPage;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use CetechDeliveryEngine\Presentation\Admin\RateCardsPage;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationAdminAssets;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;
use CetechDeliveryEngine\Presentation\Admin\SuppliersOriginsPage;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsPage;
use CetechDeliveryEngine\Presentation\Admin\SystemStatusPage;
use CetechDeliveryEngine\Presentation\Email\CustomerOrderDeliveryEmailSummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\CustomerOrderDeliverySummaryRenderer;
use CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer;
use CetechDeliveryEngine\Presentation\Admin\Validation\DeliveryOfferValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationZoneValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\LogisticsProfileValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\OriginValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\PickupLocationValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\ProductDeliveryRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\RateCardValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\SupplierValidator;
use CetechDeliveryEngine\Support\AdminNotice;
use CetechDeliveryEngine\Support\Logger;

/**
 * Main plugin bootstrap.
 */
final class Plugin {

	private static ?self $instance = null;

	private bool $booted = false;

	private ServiceContainer $container;

	private function __construct() {
		$this->container = new ServiceContainer();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		FeaturesCompatibility::register_hpos_declaration( CETECH_DE_FILE );

		$requirements = new Requirements();
		$notices      = new AdminNoticeManager();

		if ( ! $requirements->is_php_version_supported() ) {
			$notices->register(
				new AdminNotice(
					'error',
					$requirements->php_version_notice_message(),
					'cetech-de-php-version',
					false
				)
			);
			$notices->boot();

			return;
		}

		$this->register_services();
		$this->container->get( AdminNoticeManager::class )->boot();

		/** @var MigrationRunner $migration_runner */
		$migration_runner = $this->container->get( MigrationRunner::class );
		$migration_runner->run();

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
		}

		if ( ! $requirements->is_woocommerce_active() ) {
			$this->container->get( AdminNoticeManager::class )->register(
				new AdminNotice(
					'error',
					$requirements->woocommerce_missing_notice_message(),
					'cetech-de-woocommerce-missing',
					false
				)
			);

			return;
		}

		/** @var IntegrationRegistry $integrations */
		$integrations = $this->container->get( IntegrationRegistry::class );
		$integrations->detect();

		/** @var HealthCheckRegistry $health */
		$health = $this->container->get( HealthCheckRegistry::class );
		$health->run();

		$this->container->get( ProductDeliverySelectorRenderer::class )->register();
		$this->container->get( CartDeliverySelectionCapture::class )->register();
		$this->container->get( CartDeliverySelectionRevalidator::class )->register();
		$this->container->get( CheckoutDeliverySelectionValidator::class )->register();
		$this->container->get( SelectedOfferShippingIntegration::class )->register();
		$this->container->get( OrderDeliverySnapshotPersister::class )->register();
		$this->container->get( CustomerOrderDeliverySummaryRenderer::class )->register();
		$this->container->get( CustomerOrderDeliveryEmailSummaryRenderer::class )->register();

		if ( is_admin() ) {
			$this->container->get( OrderDeliverySnapshotAdminDisplay::class )->register();
		}

		$this->maybe_show_activation_notice();
	}

	public function container(): ServiceContainer {
		return $this->container;
	}

	private function register_services(): void {
		$this->container->singleton(
			FeatureFlags::class,
			static fn (): FeatureFlags => new FeatureFlags()
		);

		$this->container->singleton(
			Logger::class,
			static fn (): Logger => new Logger()
		);

		$this->container->singleton(
			AdminNoticeManager::class,
			static fn (): AdminNoticeManager => new AdminNoticeManager()
		);

		$this->container->singleton(
			Requirements::class,
			static fn (): Requirements => new Requirements()
		);

		$this->container->singleton(
			Capabilities::class,
			static fn (): Capabilities => new Capabilities()
		);

		$this->container->singleton(
			IntegrationRegistry::class,
			static fn ( ServiceContainer $container ): IntegrationRegistry => new IntegrationRegistry(
				$container->get( Logger::class )
			)
		);

		$this->container->singleton(
			MigrationRunner::class,
			static function ( ServiceContainer $container ): MigrationRunner {
				$runner = new MigrationRunner( $container->get( Logger::class ) );
				$runner->set_migrations(
					MigrationDiscovery::discover(
						CETECH_DE_PATH . 'database/migrations',
						$container->get( Logger::class )
					)
				);

				return $runner;
			}
		);

		$this->register_repository_bindings();

		$this->container->singleton(
			HealthCheckRegistry::class,
			static fn ( ServiceContainer $container ): HealthCheckRegistry => new HealthCheckRegistry(
				$container->get( Requirements::class ),
				$container->get( FeatureFlags::class ),
				$container->get( IntegrationRegistry::class )
			)
		);

		$this->container->singleton(
			AdminNoticeService::class,
			static fn (): AdminNoticeService => new AdminNoticeService()
		);

		$this->container->singleton(
			AdminActionHandler::class,
			static fn ( ServiceContainer $container ): AdminActionHandler => new AdminActionHandler(
				$container->get( AdminNoticeService::class )
			)
		);

		$this->container->singleton(
			ConfigurationAuditLogger::class,
			static fn ( ServiceContainer $container ): ConfigurationAuditLogger => new ConfigurationAuditLogger(
				$container->get( AuditLogRepositoryInterface::class ),
				$container->get( Logger::class )
			)
		);

		$this->container->singleton(
			AdminRecordDependencyChecker::class,
			static fn ( ServiceContainer $container ): AdminRecordDependencyChecker => new AdminRecordDependencyChecker(
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( ProductDeliveryRuleRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			LogisticsProfileValidator::class,
			static fn (): LogisticsProfileValidator => new LogisticsProfileValidator()
		);

		$this->container->singleton(
			DeliveryOfferValidator::class,
			static fn (): DeliveryOfferValidator => new DeliveryOfferValidator()
		);

		$this->container->singleton(
			DestinationZoneValidator::class,
			static fn (): DestinationZoneValidator => new DestinationZoneValidator()
		);

		$this->container->singleton(
			DestinationRuleValidator::class,
			static fn (): DestinationRuleValidator => new DestinationRuleValidator()
		);

		$this->container->singleton(
			PickupLocationValidator::class,
			static fn (): PickupLocationValidator => new PickupLocationValidator()
		);

		$this->container->singleton(
			SupplierValidator::class,
			static fn (): SupplierValidator => new SupplierValidator()
		);

		$this->container->singleton(
			OriginValidator::class,
			static fn ( ServiceContainer $container ): OriginValidator => new OriginValidator(
				$container->get( SupplierRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			RateCardValidator::class,
			static fn ( ServiceContainer $container ): RateCardValidator => new RateCardValidator(
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			DestinationZoneMatcher::class,
			static fn ( ServiceContainer $container ): DestinationZoneMatcher => new DestinationZoneMatcher(
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			PackageDestinationZoneResolver::class,
			static fn ( ServiceContainer $container ): PackageDestinationZoneResolver => new PackageDestinationZoneResolver(
				$container->get( DestinationZoneMatcher::class )
			)
		);

		$this->container->singleton(
			DestinationZoneTestMatcher::class,
			static fn ( ServiceContainer $container ): DestinationZoneTestMatcher => new DestinationZoneTestMatcher(
				$container->get( DestinationZoneMatcher::class )
			)
		);

		$this->container->singleton(
			AdminRateCardTester::class,
			static fn ( ServiceContainer $container ): AdminRateCardTester => new AdminRateCardTester(
				$container->get( RateCardRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			RateQuoteEngine::class,
			static fn ( ServiceContainer $container ): RateQuoteEngine => new RateQuoteEngine(
				$container->get( RateCardRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			ProductTargetResolver::class,
			static fn ( ServiceContainer $container ): ProductTargetResolver => new ProductTargetResolver(
				$container->get( Requirements::class )
			)
		);

		$this->container->singleton(
			ProductDeliveryRuleResolver::class,
			static fn ( ServiceContainer $container ): ProductDeliveryRuleResolver => new ProductDeliveryRuleResolver(
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( ProductTargetResolver::class )
			)
		);

		$this->container->singleton(
			ProductDeliveryRuleValidator::class,
			static fn ( ServiceContainer $container ): ProductDeliveryRuleValidator => new ProductDeliveryRuleValidator(
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( ProductTargetResolver::class )
			)
		);

		$this->container->singleton(
			ConfigurationHealthChecker::class,
			static fn ( ServiceContainer $container ): ConfigurationHealthChecker => new ConfigurationHealthChecker(
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class ),
				$container->get( PickupLocationRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( ProductTargetResolver::class ),
				$container->get( FeatureFlags::class )
			)
		);

		$this->container->singleton(
			ProductDeliveryOptionsBuilder::class,
			static fn ( ServiceContainer $container ): ProductDeliveryOptionsBuilder => new ProductDeliveryOptionsBuilder(
				$container->get( DeliveryOfferRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			ProductDeliverySelectionValidator::class,
			static fn ( ServiceContainer $container ): ProductDeliverySelectionValidator => new ProductDeliverySelectionValidator(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( ProductDeliveryRuleResolver::class ),
				$container->get( ProductDeliveryOptionsBuilder::class )
			)
		);

		$this->container->singleton(
			CartDeliverySelectionCapture::class,
			static fn ( ServiceContainer $container ): CartDeliverySelectionCapture => new CartDeliverySelectionCapture(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( ProductDeliveryRuleResolver::class ),
				$container->get( ProductDeliveryOptionsBuilder::class ),
				$container->get( ProductDeliverySelectionValidator::class )
			)
		);

		$this->container->singleton(
			CartDeliverySelectionRevalidator::class,
			static fn ( ServiceContainer $container ): CartDeliverySelectionRevalidator => new CartDeliverySelectionRevalidator(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( ProductDeliverySelectionValidator::class )
			)
		);

		$this->container->singleton(
			CheckoutDeliverySelectionValidator::class,
			static fn ( ServiceContainer $container ): CheckoutDeliverySelectionValidator => new CheckoutDeliverySelectionValidator(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( CartDeliverySelectionCapture::class ),
				$container->get( CartDeliverySelectionRevalidator::class )
			)
		);

		$this->container->singleton(
			ShippingRateCalculationGate::class,
			static fn ( ServiceContainer $container ): ShippingRateCalculationGate => new ShippingRateCalculationGate(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class )
			)
		);

		$this->container->singleton(
			SelectedOfferShippingRateCalculator::class,
			static fn ( ServiceContainer $container ): SelectedOfferShippingRateCalculator => new SelectedOfferShippingRateCalculator(
				$container->get( ShippingRateCalculationGate::class ),
				$container->get( PackageDestinationZoneResolver::class ),
				$container->get( CartDeliverySelectionCapture::class ),
				$container->get( CartDeliverySelectionRevalidator::class ),
				$container->get( RateQuoteEngine::class ),
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( Logger::class )
			)
		);

		$this->container->singleton(
			SelectedOfferShippingIntegration::class,
			static fn ( ServiceContainer $container ): SelectedOfferShippingIntegration => new SelectedOfferShippingIntegration(
				$container->get( ShippingRateCalculationGate::class )
			)
		);

		$this->container->singleton(
			OrderDeliverySnapshotGate::class,
			static fn ( ServiceContainer $container ): OrderDeliverySnapshotGate => new OrderDeliverySnapshotGate(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( ShippingRateCalculationGate::class )
			)
		);

		$this->container->singleton(
			OrderDeliverySnapshotBuilder::class,
			static fn ( ServiceContainer $container ): OrderDeliverySnapshotBuilder => new OrderDeliverySnapshotBuilder(
				$container->get( CartDeliverySelectionRevalidator::class ),
				$container->get( PackageDestinationZoneResolver::class ),
				$container->get( SelectedOfferShippingRateCalculator::class ),
				$container->get( RateQuoteEngine::class ),
				$container->get( DeliveryOfferRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			OrderDeliverySnapshotPersister::class,
			static fn ( ServiceContainer $container ): OrderDeliverySnapshotPersister => new OrderDeliverySnapshotPersister(
				$container->get( OrderDeliverySnapshotGate::class ),
				$container->get( OrderDeliverySnapshotBuilder::class ),
				$container->get( Logger::class )
			)
		);

		$this->container->singleton(
			OrderDeliverySnapshotReader::class,
			static fn (): OrderDeliverySnapshotReader => new OrderDeliverySnapshotReader()
		);

		$this->container->singleton(
			OrderDeliverySnapshotIntegrity::class,
			static fn (): OrderDeliverySnapshotIntegrity => new OrderDeliverySnapshotIntegrity()
		);

		$this->container->singleton(
			OrderDeliverySnapshotAdminDisplay::class,
			static fn ( ServiceContainer $container ): OrderDeliverySnapshotAdminDisplay => new OrderDeliverySnapshotAdminDisplay(
				$container->get( OrderDeliverySnapshotReader::class ),
				$container->get( OrderDeliverySnapshotIntegrity::class )
			)
		);

		$this->container->singleton(
			CustomerOrderDeliverySummaryBuilder::class,
			static fn ( ServiceContainer $container ): CustomerOrderDeliverySummaryBuilder => new CustomerOrderDeliverySummaryBuilder(
				$container->get( OrderDeliverySnapshotReader::class ),
				$container->get( OrderDeliverySnapshotIntegrity::class )
			)
		);

		$this->container->singleton(
			CustomerOrderDeliverySummaryRenderer::class,
			static fn ( ServiceContainer $container ): CustomerOrderDeliverySummaryRenderer => new CustomerOrderDeliverySummaryRenderer(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( CustomerOrderDeliverySummaryBuilder::class )
			)
		);

		$this->container->singleton(
			CustomerOrderDeliveryEmailSummaryRenderer::class,
			static fn ( ServiceContainer $container ): CustomerOrderDeliveryEmailSummaryRenderer => new CustomerOrderDeliveryEmailSummaryRenderer(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( CustomerOrderDeliverySummaryBuilder::class )
			)
		);

		$this->container->singleton(
			ProductDeliverySelectorRenderer::class,
			static fn ( ServiceContainer $container ): ProductDeliverySelectorRenderer => new ProductDeliverySelectorRenderer(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( ProductDeliveryRuleResolver::class ),
				$container->get( ProductDeliveryOptionsBuilder::class )
			)
		);

		$this->container->singleton(
			LogisticsProfilesPage::class,
			static fn ( ServiceContainer $container ): LogisticsProfilesPage => new LogisticsProfilesPage(
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( LogisticsProfileValidator::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationAuditLogger::class ),
				$container->get( AdminRecordDependencyChecker::class )
			)
		);

		$this->container->singleton(
			DeliveryOffersPage::class,
			static fn ( ServiceContainer $container ): DeliveryOffersPage => new DeliveryOffersPage(
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DeliveryOfferValidator::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationAuditLogger::class ),
				$container->get( AdminRecordDependencyChecker::class )
			)
		);

		$this->container->singleton(
			DestinationZonesPage::class,
			static fn ( ServiceContainer $container ): DestinationZonesPage => new DestinationZonesPage(
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( DestinationZoneValidator::class ),
				$container->get( DestinationRuleValidator::class ),
				$container->get( DestinationZoneTestMatcher::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationAuditLogger::class ),
				$container->get( AdminRecordDependencyChecker::class )
			)
		);

		$this->container->singleton(
			PickupLocationsPage::class,
			static fn ( ServiceContainer $container ): PickupLocationsPage => new PickupLocationsPage(
				$container->get( PickupLocationRepositoryInterface::class ),
				$container->get( PickupLocationValidator::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationAuditLogger::class ),
				$container->get( AdminRecordDependencyChecker::class )
			)
		);

		$this->container->singleton(
			SuppliersOriginsPage::class,
			static fn ( ServiceContainer $container ): SuppliersOriginsPage => new SuppliersOriginsPage(
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( SupplierValidator::class ),
				$container->get( OriginValidator::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationAuditLogger::class ),
				$container->get( AdminRecordDependencyChecker::class )
			)
		);

		$this->container->singleton(
			RateCardsPage::class,
			static fn ( ServiceContainer $container ): RateCardsPage => new RateCardsPage(
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( RateCardValidator::class ),
				$container->get( AdminRateCardTester::class ),
				$container->get( RateQuoteEngine::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationAuditLogger::class ),
				$container->get( AdminRecordDependencyChecker::class )
			)
		);

		$this->container->singleton(
			ProductDeliveryRulesPage::class,
			static fn ( ServiceContainer $container ): ProductDeliveryRulesPage => new ProductDeliveryRulesPage(
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( ProductDeliveryRuleValidator::class ),
				$container->get( ProductDeliveryRuleResolver::class ),
				$container->get( ProductDeliverySelectionValidator::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationAuditLogger::class ),
				$container->get( AdminRecordDependencyChecker::class )
			)
		);

		$this->container->singleton(
			ScopedConfigurationSubmissionParser::class,
			static fn (): ScopedConfigurationSubmissionParser => new ScopedConfigurationSubmissionParser()
		);

		$this->container->singleton(
			ProductVariationScopeGuard::class,
			static fn ( ServiceContainer $container ): ProductVariationScopeGuard => new ProductVariationScopeGuard(
				$container->get( ProductTargetResolver::class )
			)
		);

		$this->container->singleton(
			EntityLabelResolver::class,
			static fn ( ServiceContainer $container ): EntityLabelResolver => new EntityLabelResolver(
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			LegacyCategoryConfigurationInspector::class,
			static fn ( ServiceContainer $container ): LegacyCategoryConfigurationInspector => new LegacyCategoryConfigurationInspector(
				$container->get( ProductDeliveryRuleRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			ScopedConfigurationAuthorization::class,
			static fn (): ScopedConfigurationAuthorization => new ScopedConfigurationAuthorization()
		);

		$this->container->singleton(
			ScopedConfigurationAdminService::class,
			static fn ( ServiceContainer $container ): ScopedConfigurationAdminService => new ScopedConfigurationAdminService(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( EffectiveConfigurationResolver::class ),
				$container->get( ScopedConfigurationSubmissionParser::class ),
				$container->get( ProductVariationScopeGuard::class ),
				$container->get( EntityLabelResolver::class ),
				$container->get( LegacyCategoryConfigurationInspector::class ),
				$container->get( ConfigurationAuditLogger::class )
			)
		);

		$this->container->singleton(
			ScopedConfigurationAdminAssets::class,
			static fn (): ScopedConfigurationAdminAssets => new ScopedConfigurationAdminAssets()
		);

		$this->container->singleton(
			ScopedConfigurationPage::class,
			static fn ( ServiceContainer $container ): ScopedConfigurationPage => new ScopedConfigurationPage(
				$container->get( ScopedConfigurationAdminService::class ),
				$container->get( ProductTargetResolver::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ScopedConfigurationAuthorization::class )
			)
		);

		$this->container->singleton(
			EffectiveConfigurationPreviewPage::class,
			static fn ( ServiceContainer $container ): EffectiveConfigurationPreviewPage => new EffectiveConfigurationPreviewPage(
				$container->get( ScopedConfigurationAdminService::class ),
				$container->get( ProductTargetResolver::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ScopedConfigurationAuthorization::class )
			)
		);

		$this->container->singleton(
			DeliverySettingsPage::class,
			static fn ( ServiceContainer $container ): DeliverySettingsPage => new DeliverySettingsPage(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( RateCardRepositoryInterface::class ),
				new ShippingRateCalculationGate(
					$container->get( FeatureFlags::class ),
					$container->get( Requirements::class )
				),
				$container->get( AdminActionHandler::class )
			)
		);

		$this->container->singleton(
			SystemStatusPage::class,
			static fn ( ServiceContainer $container ): SystemStatusPage => new SystemStatusPage(
				$container->get( Requirements::class ),
				$container->get( FeatureFlags::class ),
				$container->get( IntegrationRegistry::class ),
				$container->get( Capabilities::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class ),
				$container->get( PickupLocationRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( ConfigurationHealthChecker::class )
			)
		);

		$this->container->singleton(
			AdminMenu::class,
			static fn ( ServiceContainer $container ): AdminMenu => new AdminMenu(
				$container->get( SystemStatusPage::class ),
				$container->get( DeliverySettingsPage::class ),
				$container->get( LogisticsProfilesPage::class ),
				$container->get( DeliveryOffersPage::class ),
				$container->get( DestinationZonesPage::class ),
				$container->get( PickupLocationsPage::class ),
				$container->get( SuppliersOriginsPage::class ),
				$container->get( RateCardsPage::class ),
				$container->get( ProductDeliveryRulesPage::class ),
				$container->get( ScopedConfigurationPage::class ),
				$container->get( EffectiveConfigurationPreviewPage::class ),
				$container->get( ScopedConfigurationAdminAssets::class )
			)
		);
	}

	private function register_repository_bindings(): void {
		$this->container->singleton(
			DeliveryOfferRepositoryInterface::class,
			static fn (): DeliveryOfferRepositoryInterface => new WpdbDeliveryOfferRepository()
		);

		$this->container->singleton(
			DestinationZoneRepositoryInterface::class,
			static fn (): DestinationZoneRepositoryInterface => new WpdbDestinationZoneRepository()
		);

		$this->container->singleton(
			DestinationRuleRepositoryInterface::class,
			static fn (): DestinationRuleRepositoryInterface => new WpdbDestinationRuleRepository()
		);

		$this->container->singleton(
			LogisticsProfileRepositoryInterface::class,
			static fn (): LogisticsProfileRepositoryInterface => new WpdbLogisticsProfileRepository()
		);

		$this->container->singleton(
			SupplierRepositoryInterface::class,
			static fn (): SupplierRepositoryInterface => new WpdbSupplierRepository()
		);

		$this->container->singleton(
			OriginRepositoryInterface::class,
			static fn (): OriginRepositoryInterface => new WpdbOriginRepository()
		);

		$this->container->singleton(
			PickupLocationRepositoryInterface::class,
			static fn (): PickupLocationRepositoryInterface => new WpdbPickupLocationRepository()
		);

		$this->container->singleton(
			RateCardRepositoryInterface::class,
			static fn (): RateCardRepositoryInterface => new WpdbRateCardRepository()
		);

		$this->container->singleton(
			ProductDeliveryRuleRepositoryInterface::class,
			static fn (): ProductDeliveryRuleRepositoryInterface => new WpdbProductDeliveryRuleRepository()
		);

		$this->container->singleton(
			AuditLogRepositoryInterface::class,
			static fn (): AuditLogRepositoryInterface => new WpdbAuditLogRepository()
		);

		$this->container->singleton(
			ScopedConfigurationRepositoryInterface::class,
			static fn (): ScopedConfigurationRepositoryInterface => new WpdbScopedConfigurationRepository()
		);

		$this->container->singleton(
			EffectiveConfigurationValidator::class,
			static fn (): EffectiveConfigurationValidator => new EffectiveConfigurationValidator()
		);

		$this->container->singleton(
			ConfigurationFingerprintBuilder::class,
			static fn (): ConfigurationFingerprintBuilder => new ConfigurationFingerprintBuilder()
		);

		$this->container->singleton(
			FulfilmentConstraintServiceInterface::class,
			static fn (): FulfilmentConstraintServiceInterface => new PassthroughFulfilmentConstraintService()
		);

		$this->container->singleton(
			EffectiveConfigurationResolver::class,
			static fn ( ServiceContainer $container ): EffectiveConfigurationResolver => new EffectiveConfigurationResolver(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( EffectiveConfigurationValidator::class ),
				$container->get( FulfilmentConstraintServiceInterface::class )
			)
		);

		$this->container->singleton(
			LegacyProductRuleMigrationMapper::class,
			static fn (): LegacyProductRuleMigrationMapper => new LegacyProductRuleMigrationMapper()
		);

		$this->container->singleton(
			LegacyConfigurationMigrator::class,
			static fn ( ServiceContainer $container ): LegacyConfigurationMigrator => new LegacyConfigurationMigrator(
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( LegacyProductRuleMigrationMapper::class ),
				$container->get( Logger::class )
			)
		);
	}

	private function maybe_show_activation_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_transient( 'cetech_de_activation_notice' ) ) {
			return;
		}

		delete_transient( 'cetech_de_activation_notice' );

		$this->container->get( AdminNoticeManager::class )->register(
			new AdminNotice(
				'success',
				__(
					'CETECH WooCommerce Delivery Engine core foundation is active. Delivery features are not enabled yet.',
					'cetech-woocommerce-delivery-engine'
				),
				'cetech-de-activated',
				true
			)
		);
	}
}
