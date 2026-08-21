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
use CetechDeliveryEngine\Application\Destination\RegionCodeLabelMatcher;
use CetechDeliveryEngine\Application\Destination\WooCommerceStateCatalogInterface;
use CetechDeliveryEngine\Infrastructure\WooCommerce\Destination\WooCommerceStateCatalog;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Order\CustomerOrderDeliverySummaryBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotBuilder;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotGate;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotIntegrity;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotPersister;
use CetechDeliveryEngine\Application\Order\OrderDeliverySnapshotReader;
use CetechDeliveryEngine\Application\Shipping\DefaultCartLineShippingAssessor;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingIntegration;
use CetechDeliveryEngine\Application\Shipping\SelectedOfferShippingRateCalculator;
use CetechDeliveryEngine\Application\Shipping\ShippingPackageBuilder;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Application\Shipping\WooCommerceShippingReadiness;
use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\ConfigurationFingerprintBuilder;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogIndexInterface;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionCountQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\ProductExceptionsQuery;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\Catalog\WooCommerceCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\FulfilmentConstraintServiceInterface;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\LegacyConfigurationMigrator;
use CetechDeliveryEngine\Application\Configuration\ContextualEntityService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsPolicyInterface;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationHealthChecker;
use CetechDeliveryEngine\Application\Runtime\EcrProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\EcrToRuntimeConfigurationAdapter;
use CetechDeliveryEngine\Application\Runtime\LegacyCategoryRuntimeCompatibilityGuard;
use CetechDeliveryEngine\Application\Runtime\LegacyCategoryRuntimeCompatibilityGuardInterface;
use CetechDeliveryEngine\Application\Runtime\LegacyProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryConfigurationSourceInterface;
use CetechDeliveryEngine\Application\Runtime\ProductDeliveryRuntimeConfigurationRouter;
use CetechDeliveryEngine\Application\Runtime\ProductTypeInspectorInterface;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\WooCommerceProductTypeInspector;
use CetechDeliveryEngine\Domain\Configuration\LegacyProductRuleMigrationMapper;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Core\AdminNoticeManager;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Capabilities\RoleAccessService;
use CetechDeliveryEngine\Presentation\Admin\AdministratorAccessRecovery;
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
use CetechDeliveryEngine\Domain\Shipment\ShipmentRepositoryInterface;
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
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbShipmentRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbSupplierRepository;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;
use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetQueryInterface;
use CetechDeliveryEngine\Application\Bulk\Catalog\WooCommerceCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvExportService;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvMapper;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationExporter;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationImporter;
use CetechDeliveryEngine\Application\Bulk\Portability\EntityCodeResolver;
use CetechDeliveryEngine\Application\Bulk\Queue\ActionSchedulerQueue;
use CetechDeliveryEngine\Application\Bulk\Queue\BackgroundQueueInterface;
use CetechDeliveryEngine\Domain\Bulk\BulkJobRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkMutator;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbBulkJobRepository;
use CetechDeliveryEngine\Presentation\Admin\BulkJobProgressEndpoint;
use CetechDeliveryEngine\Presentation\Admin\BulkToolsPage;
use CetechDeliveryEngine\Presentation\Cli\BulkJobCliCommand;
use CetechDeliveryEngine\Presentation\Admin\AdminRecordDependencyChecker;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdminUxAssets;
use CetechDeliveryEngine\Presentation\Admin\OverviewPage;
use CetechDeliveryEngine\Presentation\Admin\ProductDeliveryPanel;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;
use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;
use CetechDeliveryEngine\Presentation\Admin\DeliveryOffersPage;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsHomePage;
use CetechDeliveryEngine\Presentation\Admin\NeedsAttentionPage;
use CetechDeliveryEngine\Presentation\Admin\ProductExceptionsPage;
use CetechDeliveryEngine\Presentation\Admin\ShipmentsPage;
use CetechDeliveryEngine\Presentation\Admin\DestinationZoneTestMatcher;
use CetechDeliveryEngine\Presentation\Admin\DestinationZonesPage;
use CetechDeliveryEngine\Presentation\Admin\EffectiveConfigurationPreviewPage;
use CetechDeliveryEngine\Presentation\Admin\PreviewVariationsEndpoint;
use CetechDeliveryEngine\Presentation\Admin\LogisticsProfilesPage;
use CetechDeliveryEngine\Presentation\Admin\PickupLocationsPage;
use CetechDeliveryEngine\Presentation\Admin\OrderDeliverySnapshotAdminDisplay;
use CetechDeliveryEngine\Presentation\Admin\OrderShippingItemPresentationGuard;
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
use CetechDeliveryEngine\Presentation\Frontend\CustomerShipmentRenderer;
use CetechDeliveryEngine\Presentation\Frontend\ProductDeliverySelectorRenderer;
use CetechDeliveryEngine\Presentation\Frontend\VariableDeliverySelectorAssets;
use CetechDeliveryEngine\Application\Selector\VariationDeliveryOptionsEndpoint;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentEvaluator;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore;
use CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentSubscriber;
use CetechDeliveryEngine\Application\Shipment\ManualShipmentCreationPreviewFactory;
use CetechDeliveryEngine\Application\Shipment\ShipmentActivityCursor;
use CetechDeliveryEngine\Application\Shipment\CustomerShipmentQuery;
use CetechDeliveryEngine\Application\Shipment\HistoricalOrderShipmentContextFactory;
use CetechDeliveryEngine\Application\Shipment\HistoricalShipmentPlanner;
use CetechDeliveryEngine\Application\Shipment\OrderShipmentOperationsSubscriber;
use CetechDeliveryEngine\Application\Shipment\PaidOrderShipmentSubscriber;
use CetechDeliveryEngine\Application\Shipment\ShipmentRefundInspector;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentCreationIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentEtaService;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueQuery;
use CetechDeliveryEngine\Application\Shipment\ShipmentOperationsIssueStore;
use CetechDeliveryEngine\Application\Shipment\ShipmentService;
use CetechDeliveryEngine\Application\Shipment\ShipmentStatusService;
use CetechDeliveryEngine\Application\Shipment\ShipmentTrackingService;
use CetechDeliveryEngine\Application\Shipment\ShipmentWorkspaceQuery;
use CetechDeliveryEngine\Application\Runtime\VariationRelationshipInspectorInterface;
use CetechDeliveryEngine\Application\Runtime\WooCommerceVariationRelationshipInspector;
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

		// Capability matrix must self-heal when an active plugin folder is replaced
		// without reactivation (activation hooks do not run in that path).
		$this->container->get( Capabilities::class )->ensure_current();

		if ( is_admin() ) {
			$this->container->get( AdminMenu::class )->register();
			$this->container->get( AdministratorAccessRecovery::class )->register();
			$this->container->get( ProductDeliveryPanel::class )->register();
			$this->container->get( PreviewVariationsEndpoint::class )->register();
			$this->container->get( BulkJobProgressEndpoint::class )->register();
		}

		$this->container->get( BulkJobCliCommand::class )->register();
		add_action(
			ActionSchedulerQueue::HOOK,
			function ( $job_id ): void {
				$this->container->get( BulkJobWorker::class )->tick( (int) $job_id );
			}
		);

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
		$this->container->get( VariableDeliverySelectorAssets::class )->register();
		$this->container->get( VariationDeliveryOptionsEndpoint::class )->register();
		$this->container->get( CartDeliverySelectionCapture::class )->register();
		$this->container->get( CartDeliverySelectionRevalidator::class )->register();
		$this->container->get( CheckoutDeliverySelectionValidator::class )->register();
		$this->container->get( ShippingPackageBuilder::class )->register();
		$this->container->get( SelectedOfferShippingIntegration::class )->register();
		$this->container->get( OrderDeliverySnapshotPersister::class )->register();
		$this->container->get( PaidOrderShipmentSubscriber::class )->register();
		$this->container->get( CodAwaitingShipmentSubscriber::class )->register();
		$this->container->get( OrderShipmentOperationsSubscriber::class )->register();
		$this->container->get( OrderShippingItemPresentationGuard::class )->register();
		$this->container->get( CustomerOrderDeliverySummaryRenderer::class )->register();
		$this->container->get( CustomerShipmentRenderer::class )->register();
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
			RoleAccessService::class,
			static fn (): RoleAccessService => new RoleAccessService()
		);

		$this->container->singleton(
			AdministratorAccessRecovery::class,
			static fn ( ServiceContainer $container ): AdministratorAccessRecovery => new AdministratorAccessRecovery(
				$container->get( Capabilities::class )
			)
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
			WooCommerceStateCatalogInterface::class,
			static fn (): WooCommerceStateCatalogInterface => new WooCommerceStateCatalog()
		);

		$this->container->singleton(
			RegionCodeLabelMatcher::class,
			static fn ( ServiceContainer $container ): RegionCodeLabelMatcher => new RegionCodeLabelMatcher(
				$container->get( WooCommerceStateCatalogInterface::class )
			)
		);

		$this->container->singleton(
			DestinationZoneMatcher::class,
			static fn ( ServiceContainer $container ): DestinationZoneMatcher => new DestinationZoneMatcher(
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class ),
				$container->get( RegionCodeLabelMatcher::class )
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
				$container->get( ProductDeliveryConfigurationSourceInterface::class ),
				$container->get( ProductDeliveryOptionsBuilder::class )
			)
		);

		$this->container->singleton(
			CartDeliverySelectionCapture::class,
			static fn ( ServiceContainer $container ): CartDeliverySelectionCapture => new CartDeliverySelectionCapture(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( ProductDeliveryConfigurationSourceInterface::class ),
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
				new DefaultCartLineShippingAssessor(
					$container->get( CartDeliverySelectionCapture::class ),
					$container->get( CartDeliverySelectionRevalidator::class )
				),
				$container->get( RateQuoteEngine::class ),
				$container->get( ProductDeliveryRuleRepositoryInterface::class ),
				$container->get( Logger::class ),
				$container->get( ProductDeliveryConfigurationSourceInterface::class )
			)
		);

		$this->container->singleton(
			ShippingPackageBuilder::class,
			static fn ( ServiceContainer $container ): ShippingPackageBuilder => new ShippingPackageBuilder(
				$container->get( ShippingRateCalculationGate::class ),
				$container->get( CartDeliverySelectionCapture::class )
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
			HistoricalShipmentPlanner::class,
			static fn (): HistoricalShipmentPlanner => new HistoricalShipmentPlanner()
		);

		$this->container->singleton(
			HistoricalOrderShipmentContextFactory::class,
			static fn ( ServiceContainer $container ): HistoricalOrderShipmentContextFactory => new HistoricalOrderShipmentContextFactory(
				$container->get( OrderDeliverySnapshotReader::class )
			)
		);

		$this->container->singleton(
			ShipmentCreationFailureStore::class,
			static fn (): ShipmentCreationFailureStore => new ShipmentCreationFailureStore()
		);

		$this->container->singleton(
			CodAwaitingShipmentStore::class,
			static fn (): CodAwaitingShipmentStore => new CodAwaitingShipmentStore()
		);

		$this->container->singleton(
			CodAwaitingShipmentEvaluator::class,
			static fn ( ServiceContainer $container ): CodAwaitingShipmentEvaluator => new CodAwaitingShipmentEvaluator(
				$container->get( FeatureFlags::class ),
				$container->get( HistoricalOrderShipmentContextFactory::class ),
				$container->get( HistoricalShipmentPlanner::class ),
				$container->get( ShipmentRepositoryInterface::class ),
				$container->get( CodAwaitingShipmentStore::class )
			)
		);

		$this->container->singleton(
			ShipmentService::class,
			static fn ( ServiceContainer $container ): ShipmentService => new ShipmentService(
				$container->get( FeatureFlags::class ),
				$container->get( HistoricalOrderShipmentContextFactory::class ),
				$container->get( HistoricalShipmentPlanner::class ),
				$container->get( ShipmentRepositoryInterface::class ),
				$container->get( ShipmentCreationFailureStore::class ),
				$container->get( AuditLogRepositoryInterface::class ),
				$container->get( Logger::class ),
				$container->get( CodAwaitingShipmentEvaluator::class )
			)
		);

		$this->container->singleton(
			ManualShipmentCreationPreviewFactory::class,
			static fn ( ServiceContainer $container ): ManualShipmentCreationPreviewFactory => new ManualShipmentCreationPreviewFactory(
				$container->get( FeatureFlags::class ),
				$container->get( HistoricalOrderShipmentContextFactory::class ),
				$container->get( HistoricalShipmentPlanner::class ),
				$container->get( ShipmentRepositoryInterface::class ),
				$container->get( CodAwaitingShipmentEvaluator::class )
			)
		);

		$this->container->singleton(
			PaidOrderShipmentSubscriber::class,
			static fn ( ServiceContainer $container ): PaidOrderShipmentSubscriber => new PaidOrderShipmentSubscriber(
				$container->get( ShipmentService::class )
			)
		);

		$this->container->singleton(
			CodAwaitingShipmentSubscriber::class,
			static fn ( ServiceContainer $container ): CodAwaitingShipmentSubscriber => new CodAwaitingShipmentSubscriber(
				$container->get( CodAwaitingShipmentEvaluator::class )
			)
		);

		$this->container->singleton(
			ShipmentOperationsIssueStore::class,
			static fn (): ShipmentOperationsIssueStore => new ShipmentOperationsIssueStore()
		);

		$this->container->singleton(
			ShipmentStatusService::class,
			static fn ( ServiceContainer $container ): ShipmentStatusService => new ShipmentStatusService(
				$container->get( ShipmentRepositoryInterface::class ),
				$container->get( ShipmentOperationsIssueStore::class )
			)
		);

		$this->container->singleton(
			ShipmentEtaService::class,
			static fn ( ServiceContainer $container ): ShipmentEtaService => new ShipmentEtaService(
				$container->get( ShipmentRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			OrderShipmentOperationsSubscriber::class,
			static fn ( ServiceContainer $container ): OrderShipmentOperationsSubscriber => new OrderShipmentOperationsSubscriber(
				$container->get( FeatureFlags::class ),
				$container->get( ShipmentRepositoryInterface::class ),
				$container->get( ShipmentStatusService::class ),
				new ShipmentRefundInspector(),
				$container->get( ShipmentOperationsIssueStore::class )
			)
		);

		$this->container->singleton(
			ShipmentOperationsIssueQuery::class,
			static fn ( ServiceContainer $container ): ShipmentOperationsIssueQuery => new ShipmentOperationsIssueQuery(
				$container->get( FeatureFlags::class ),
				$container->get( ShipmentRepositoryInterface::class ),
				$container->get( ShipmentOperationsIssueStore::class )
			)
		);

		$this->container->singleton(
			ShipmentCreationIssueQuery::class,
			static fn ( ServiceContainer $container ): ShipmentCreationIssueQuery => new ShipmentCreationIssueQuery(
				$container->get( ShipmentCreationFailureStore::class )
			)
		);

		$this->container->singleton(
			CodAwaitingShipmentQuery::class,
			static fn ( ServiceContainer $container ): CodAwaitingShipmentQuery => new CodAwaitingShipmentQuery(
				$container->get( CodAwaitingShipmentStore::class ),
				$container->get( CodAwaitingShipmentEvaluator::class )
			)
		);

		$this->container->singleton(
			ShipmentActivityCursor::class,
			static fn ( ServiceContainer $container ): ShipmentActivityCursor => new ShipmentActivityCursor(
				$container->get( FeatureFlags::class ),
				$container->get( ShipmentRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			ShipmentWorkspaceQuery::class,
			static fn ( ServiceContainer $container ): ShipmentWorkspaceQuery => new ShipmentWorkspaceQuery(
				$container->get( ShipmentRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			ShipmentTrackingService::class,
			static fn ( ServiceContainer $container ): ShipmentTrackingService => new ShipmentTrackingService(
				$container->get( ShipmentRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			CustomerShipmentQuery::class,
			static fn ( ServiceContainer $container ): CustomerShipmentQuery => new CustomerShipmentQuery(
				$container->get( FeatureFlags::class ),
				$container->get( ShipmentRepositoryInterface::class )
			)
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
			OrderShippingItemPresentationGuard::class,
			static fn (): OrderShippingItemPresentationGuard => new OrderShippingItemPresentationGuard()
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
				$container->get( CustomerOrderDeliverySummaryBuilder::class ),
				$container->get( CustomerShipmentQuery::class )
			)
		);

		$this->container->singleton(
			CustomerShipmentRenderer::class,
			static fn ( ServiceContainer $container ): CustomerShipmentRenderer => new CustomerShipmentRenderer(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( CustomerShipmentQuery::class )
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
				$container->get( ProductDeliveryConfigurationSourceInterface::class ),
				$container->get( ProductDeliveryOptionsBuilder::class )
			)
		);

		$this->container->singleton(
			VariableDeliverySelectorAssets::class,
			static fn ( ServiceContainer $container ): VariableDeliverySelectorAssets => new VariableDeliverySelectorAssets(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class )
			)
		);

		$this->container->singleton(
			VariationDeliveryOptionsEndpoint::class,
			static fn ( ServiceContainer $container ): VariationDeliveryOptionsEndpoint => new VariationDeliveryOptionsEndpoint(
				$container->get( FeatureFlags::class ),
				$container->get( Requirements::class ),
				$container->get( ProductDeliveryConfigurationSourceInterface::class ),
				$container->get( ProductDeliveryOptionsBuilder::class ),
				$container->get( VariationRelationshipInspectorInterface::class )
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
				$container->get( AdminRecordDependencyChecker::class ),
				$container->get( ClassicCheckoutRuntimeActivation::class ),
				$container->get( CatalogInheritanceClassifier::class )
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
				$container->get( ScopedConfigurationAuthorization::class ),
				$container->get( SiteWideDefaultsService::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( ClassicCheckoutRuntimeActivation::class )
			)
		);

		$this->container->singleton(
			EffectiveConfigurationPreviewPage::class,
			static fn ( ServiceContainer $container ): EffectiveConfigurationPreviewPage => new EffectiveConfigurationPreviewPage(
				$container->get( ScopedConfigurationAdminService::class ),
				$container->get( ProductTargetResolver::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ScopedConfigurationAuthorization::class ),
				$container->get( OperationalReadinessAssessor::class ),
				$container->get( CatalogIndexInterface::class ),
				$container->get( ClassicCheckoutRuntimeActivation::class ),
				$container->get( OperationalStateService::class )
			)
		);

		$this->container->singleton(
			PreviewVariationsEndpoint::class,
			static fn ( ServiceContainer $container ): PreviewVariationsEndpoint => new PreviewVariationsEndpoint(
				$container->get( CatalogIndexInterface::class ),
				$container->get( ScopedConfigurationAuthorization::class )
			)
		);

		$this->container->singleton(
			DeliverySettingsHomePage::class,
			static fn ( ServiceContainer $container ): DeliverySettingsHomePage => new DeliverySettingsHomePage(
				$container->get( SiteWideDefaultsService::class ),
				$container->get( SiteWideDefaultsSettings::class ),
				$container->get( SiteWideDefaultSummary::class ),
				$container->get( NeedsAttentionQuery::class ),
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( EntityLabelResolver::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( DeliveryOfferRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			ProductExceptionsPage::class,
			static fn ( ServiceContainer $container ): ProductExceptionsPage => new ProductExceptionsPage(
				$container->get( ProductExceptionsQuery::class ),
				$container->get( SiteWideDefaultsService::class ),
				$container->get( AdminActionHandler::class )
			)
		);

		$this->container->singleton(
			NeedsAttentionPage::class,
			static fn ( ServiceContainer $container ): NeedsAttentionPage => new NeedsAttentionPage(
				$container->get( NeedsAttentionQuery::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( OperationalStateService::class ),
				$container->get( ShipmentCreationIssueQuery::class ),
				$container->get( ShipmentService::class ),
				$container->get( FeatureFlags::class ),
				$container->get( ShipmentOperationsIssueQuery::class ),
				$container->get( CodAwaitingShipmentQuery::class )
			)
		);

		$this->container->singleton(
			ShipmentsPage::class,
			static fn ( ServiceContainer $container ): ShipmentsPage => new ShipmentsPage(
				$container->get( FeatureFlags::class ),
				$container->get( ShipmentWorkspaceQuery::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ShipmentTrackingService::class ),
				$container->get( ShipmentStatusService::class ),
				$container->get( ShipmentEtaService::class ),
				$container->get( ShipmentActivityCursor::class ),
				$container->get( ShipmentService::class ),
				$container->get( ManualShipmentCreationPreviewFactory::class )
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
				$container->get( AdminActionHandler::class ),
				$container->get( SetupWizardProgress::class ),
				$container->get( ClassicCheckoutRuntimeActivation::class ),
				$container->get( WooCommerceShippingReadiness::class ),
				$container->get( SiteWideDefaultsSettings::class ),
				$container->get( OperationalStateService::class ),
				$container->get( RoleAccessService::class )
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
			AdminUxAssets::class,
			static fn (): AdminUxAssets => new AdminUxAssets()
		);

		$this->container->singleton(
			SetupWizardProgress::class,
			static fn ( ServiceContainer $container ): SetupWizardProgress => new SetupWizardProgress(
				$container->get( SiteWideDefaultsSettings::class )
			)
		);

		$this->container->singleton(
			ContextualEntityService::class,
			static fn ( ServiceContainer $container ): ContextualEntityService => new ContextualEntityService(
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( PickupLocationRepositoryInterface::class ),
				$container->get( DeliveryOfferValidator::class ),
				$container->get( DestinationZoneValidator::class ),
				$container->get( DestinationRuleValidator::class ),
				$container->get( RateCardValidator::class ),
				$container->get( PickupLocationValidator::class )
			)
		);

		$this->container->singleton(
			SetupWizardPage::class,
			static fn ( ServiceContainer $container ): SetupWizardPage => new SetupWizardPage(
				$container->get( SetupWizardProgress::class ),
				$container->get( SiteWideDefaultsService::class ),
				$container->get( SiteWideDefaultsSettings::class ),
				$container->get( SiteWideDefaultSummary::class ),
				$container->get( ContextualEntityService::class ),
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( PickupLocationRepositoryInterface::class ),
				$container->get( NeedsAttentionQuery::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ClassicCheckoutRuntimeActivation::class ),
				$container->get( WooCommerceShippingReadiness::class ),
				$container->get( OperationalStateService::class )
			)
		);

		$this->container->singleton(
			OverviewPage::class,
			static fn ( ServiceContainer $container ): OverviewPage => new OverviewPage(
				$container->get( SetupWizardPage::class ),
				$container->get( SetupWizardProgress::class ),
				$container->get( SiteWideDefaultsService::class ),
				$container->get( SiteWideDefaultsSettings::class ),
				$container->get( SiteWideDefaultSummary::class ),
				$container->get( NeedsAttentionQuery::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( OperationalStateService::class )
			)
		);

		$this->container->singleton(
			ProductDeliveryPanel::class,
			static fn ( ServiceContainer $container ): ProductDeliveryPanel => new ProductDeliveryPanel(
				$container->get( ScopedConfigurationAdminService::class ),
				$container->get( ProductTargetResolver::class )
			)
		);

		$this->container->singleton(
			BackgroundQueueInterface::class,
			static fn (): BackgroundQueueInterface => new ActionSchedulerQueue()
		);

		$this->container->singleton(
			CatalogTargetQueryInterface::class,
			static fn ( ServiceContainer $container ): CatalogTargetQueryInterface => new WooCommerceCatalogTargetQuery(
				$container->get( EffectiveConfigurationResolver::class ),
				$container->get( OperationalReadinessAssessor::class )
			)
		);

		$this->container->singleton(
			CatalogCsvMapper::class,
			static fn (): CatalogCsvMapper => new CatalogCsvMapper()
		);

		$this->container->singleton(
			CatalogScopeMutator::class,
			static fn ( ServiceContainer $container ): CatalogScopeMutator => new CatalogScopeMutator(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( EffectiveConfigurationValidator::class ),
				$container->get( FulfilmentConstraintServiceInterface::class ),
				$container->get( SiteWideDefaultsPolicyInterface::class ),
				$container->get( EntityCodeResolver::class )
			)
		);

		$this->container->singleton(
			EntityCodeResolver::class,
			static fn ( ServiceContainer $container ): EntityCodeResolver => new EntityCodeResolver(
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( PickupLocationRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			RateCardBulkMutator::class,
			static fn ( ServiceContainer $container ): RateCardBulkMutator => new RateCardBulkMutator(
				$container->get( RateCardRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			ConfigurationImporter::class,
			static fn ( ServiceContainer $container ): ConfigurationImporter => new ConfigurationImporter(
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( PickupLocationRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( SiteWideDefaultsSettings::class )
			)
		);

		$this->container->singleton(
			BulkJobWorker::class,
			static fn ( ServiceContainer $container ): BulkJobWorker => new BulkJobWorker(
				$container->get( BulkJobRepositoryInterface::class ),
				$container->get( CatalogTargetQueryInterface::class ),
				$container->get( CatalogScopeMutator::class ),
				$container->get( BackgroundQueueInterface::class ),
				8,
				$container->get( RateCardBulkMutator::class ),
				$container->get( ConfigurationImporter::class ),
				$container->get( CatalogCsvMapper::class )
			)
		);

		$this->container->singleton(
			BulkJobEngine::class,
			static fn ( ServiceContainer $container ): BulkJobEngine => new BulkJobEngine(
				$container->get( BulkJobRepositoryInterface::class ),
				$container->get( BackgroundQueueInterface::class ),
				$container->get( BulkJobWorker::class )
			)
		);

		$this->container->singleton(
			ConfigurationExporter::class,
			static fn ( ServiceContainer $container ): ConfigurationExporter => new ConfigurationExporter(
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( DestinationZoneRepositoryInterface::class ),
				$container->get( DestinationRuleRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( LogisticsProfileRepositoryInterface::class ),
				$container->get( PickupLocationRepositoryInterface::class ),
				$container->get( SupplierRepositoryInterface::class ),
				$container->get( OriginRepositoryInterface::class ),
				$container->get( SiteWideDefaultsSettings::class ),
				$container->get( ScopedConfigurationRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			CatalogCsvExportService::class,
			static fn ( ServiceContainer $container ): CatalogCsvExportService => new CatalogCsvExportService(
				$container->get( CatalogTargetQueryInterface::class ),
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( EffectiveConfigurationResolver::class ),
				$container->get( EntityCodeResolver::class )
			)
		);

		$this->container->singleton(
			BulkToolsPage::class,
			static fn ( ServiceContainer $container ): BulkToolsPage => new BulkToolsPage(
				$container->get( BulkJobEngine::class ),
				$container->get( BulkJobRepositoryInterface::class ),
				$container->get( AdminActionHandler::class ),
				$container->get( ConfigurationExporter::class ),
				$container->get( CatalogCsvMapper::class ),
				$container->get( CatalogCsvExportService::class )
			)
		);

		$this->container->singleton(
			BulkJobProgressEndpoint::class,
			static fn ( ServiceContainer $container ): BulkJobProgressEndpoint => new BulkJobProgressEndpoint(
				$container->get( BulkJobEngine::class )
			)
		);

		$this->container->singleton(
			BulkJobCliCommand::class,
			static fn ( ServiceContainer $container ): BulkJobCliCommand => new BulkJobCliCommand(
				$container->get( BulkJobEngine::class ),
				$container->get( ConfigurationExporter::class )
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
				$container->get( DeliverySettingsHomePage::class ),
				$container->get( ProductExceptionsPage::class ),
				$container->get( NeedsAttentionPage::class ),
				$container->get( OverviewPage::class ),
				$container->get( SetupWizardPage::class ),
				$container->get( ScopedConfigurationAdminAssets::class ),
				$container->get( AdminUxAssets::class ),
				$container->get( SetupWizardProgress::class ),
				$container->get( FeatureFlags::class ),
				$container->get( ShipmentsPage::class ),
				$container->get( BulkToolsPage::class ),
				$container->get( NeedsAttentionCountQuery::class ),
				$container->get( ShipmentActivityCursor::class )
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
			ShipmentRepositoryInterface::class,
			static fn (): ShipmentRepositoryInterface => new WpdbShipmentRepository()
		);

		$this->container->singleton(
			ScopedConfigurationRepositoryInterface::class,
			static fn (): ScopedConfigurationRepositoryInterface => new WpdbScopedConfigurationRepository()
		);

		$this->container->singleton(
			BulkJobRepositoryInterface::class,
			static fn (): BulkJobRepositoryInterface => new WpdbBulkJobRepository()
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
			static fn ( ServiceContainer $container ): FulfilmentConstraintServiceInterface => new HardFulfilmentConstraintService(
				$container->get( DeliveryOfferRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			SiteWideDefaultsSettings::class,
			static fn (): SiteWideDefaultsSettings => new SiteWideDefaultsSettings()
		);

		$this->container->singleton(
			SiteWideDefaultsPolicyInterface::class,
			static fn ( ServiceContainer $container ): SiteWideDefaultsPolicyInterface => $container->get( SiteWideDefaultsSettings::class )
		);

		$this->container->singleton(
			CatalogIndexInterface::class,
			static fn (): CatalogIndexInterface => new WooCommerceCatalogIndex()
		);

		$this->container->singleton(
			EffectiveConfigurationResolver::class,
			static fn ( ServiceContainer $container ): EffectiveConfigurationResolver => new EffectiveConfigurationResolver(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( EffectiveConfigurationValidator::class ),
				$container->get( FulfilmentConstraintServiceInterface::class ),
				$container->get( SiteWideDefaultsPolicyInterface::class )
			)
		);

		$this->container->singleton(
			CatalogInheritanceClassifier::class,
			static fn ( ServiceContainer $container ): CatalogInheritanceClassifier => new CatalogInheritanceClassifier(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( CatalogIndexInterface::class ),
				$container->get( EffectiveConfigurationResolver::class ),
				$container->get( SiteWideDefaultsPolicyInterface::class ),
				$container->get( ProductDeliveryRuleRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			OperationalReadinessAssessor::class,
			static fn ( ServiceContainer $container ): OperationalReadinessAssessor => new OperationalReadinessAssessor(
				$container->get( EffectiveConfigurationResolver::class )
			)
		);

		$this->container->singleton(
			ClassicCheckoutRuntimeActivation::class,
			static fn ( ServiceContainer $container ): ClassicCheckoutRuntimeActivation => new ClassicCheckoutRuntimeActivation(
				$container->get( FeatureFlags::class ),
				$container->get( SiteWideDefaultsSettings::class )
			)
		);

		$this->container->singleton(
			WooCommerceShippingReadiness::class,
			static fn ( ServiceContainer $container ): WooCommerceShippingReadiness => new WooCommerceShippingReadiness(
				$container->get( Requirements::class )
			)
		);

		$this->container->singleton(
			NeedsAttentionQuery::class,
			static fn ( ServiceContainer $container ): NeedsAttentionQuery => new NeedsAttentionQuery(
				$container->get( CatalogIndexInterface::class ),
				$container->get( OperationalReadinessAssessor::class ),
				$container->get( OperationalStateService::class ),
				$container->get( CatalogInheritanceClassifier::class ),
				$container->get( ScopedConfigurationRepositoryInterface::class )
			)
		);

		$this->container->singleton(
			NeedsAttentionCountQuery::class,
			static fn ( ServiceContainer $container ): NeedsAttentionCountQuery => new NeedsAttentionCountQuery(
				$container->get( NeedsAttentionQuery::class ),
				$container->get( ShipmentCreationIssueQuery::class ),
				$container->get( ShipmentOperationsIssueQuery::class ),
				$container->get( FeatureFlags::class ),
				$container->get( CodAwaitingShipmentQuery::class )
			)
		);

		$this->container->singleton(
			ProductExceptionsQuery::class,
			static fn ( ServiceContainer $container ): ProductExceptionsQuery => new ProductExceptionsQuery(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( CatalogIndexInterface::class ),
				$container->get( CatalogInheritanceClassifier::class ),
				$container->get( SiteWideDefaultsPolicyInterface::class ),
				$container->get( OperationalReadinessAssessor::class )
			)
		);

		$this->container->singleton(
			SiteWideDefaultSummary::class,
			static fn ( ServiceContainer $container ): SiteWideDefaultSummary => new SiteWideDefaultSummary(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( DeliveryOfferRepositoryInterface::class ),
				$container->get( RateCardRepositoryInterface::class ),
				$container->get( EntityLabelResolver::class )
			)
		);

		$this->container->singleton(
			OperationalStateService::class,
			static fn ( ServiceContainer $container ): OperationalStateService => new OperationalStateService(
				$container->get( FeatureFlags::class ),
				$container->get( SiteWideDefaultsSettings::class ),
				$container->get( SetupWizardProgress::class ),
				$container->get( ClassicCheckoutRuntimeActivation::class ),
				$container->get( SiteWideDefaultSummary::class )
			)
		);

		$this->container->singleton(
			SiteWideDefaultsService::class,
			static fn ( ServiceContainer $container ): SiteWideDefaultsService => new SiteWideDefaultsService(
				$container->get( ScopedConfigurationRepositoryInterface::class ),
				$container->get( SiteWideDefaultsSettings::class ),
				$container->get( CatalogInheritanceClassifier::class ),
				$container->get( EffectiveConfigurationResolver::class ),
				$container->get( CatalogIndexInterface::class )
			)
		);

		$this->container->singleton(
			EcrToRuntimeConfigurationAdapter::class,
			static fn (): EcrToRuntimeConfigurationAdapter => new EcrToRuntimeConfigurationAdapter()
		);

		$this->container->singleton(
			LegacyCategoryRuntimeCompatibilityGuardInterface::class,
			static fn ( ServiceContainer $container ): LegacyCategoryRuntimeCompatibilityGuardInterface => new LegacyCategoryRuntimeCompatibilityGuard(
				$container->get( ProductDeliveryRuleResolver::class )
			)
		);

		$this->container->singleton(
			ProductTypeInspectorInterface::class,
			static fn (): ProductTypeInspectorInterface => new WooCommerceProductTypeInspector()
		);

		$this->container->singleton(
			VariationRelationshipInspectorInterface::class,
			static fn (): VariationRelationshipInspectorInterface => new WooCommerceVariationRelationshipInspector()
		);

		$this->container->singleton(
			ProductDeliveryConfigurationSourceInterface::class,
			static fn ( ServiceContainer $container ): ProductDeliveryConfigurationSourceInterface => new ProductDeliveryRuntimeConfigurationRouter(
				$container->get( FeatureFlags::class ),
				new LegacyProductDeliveryConfigurationSource(
					$container->get( ProductDeliveryRuleResolver::class ),
					RuntimeConfigurationSource::LEGACY
				),
				new EcrProductDeliveryConfigurationSource(
					$container->get( EffectiveConfigurationResolver::class ),
					$container->get( EcrToRuntimeConfigurationAdapter::class ),
					$container->get( VariationRelationshipInspectorInterface::class )
				),
				$container->get( LegacyCategoryRuntimeCompatibilityGuardInterface::class ),
				$container->get( ProductTypeInspectorInterface::class ),
				new LegacyProductDeliveryConfigurationSource(
					$container->get( ProductDeliveryRuleResolver::class ),
					RuntimeConfigurationSource::LEGACY_CATEGORY_COMPATIBILITY
				),
				$container->get( VariationRelationshipInspectorInterface::class )
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
