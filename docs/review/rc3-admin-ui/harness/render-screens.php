<?php

declare(strict_types=1);

/**
 * Stage 13B owner UI review harness.
 * Renders actual admin page classes into WordPress-like HTML fixtures.
 * Capture method: FIXTURE RENDER. Not a live WordPress screenshot.
 */

use CetechDeliveryEngine\Application\Calculator\AdminRateCardTester;
use CetechDeliveryEngine\Application\Configuration\Admin\EntityLabelResolver;
use CetechDeliveryEngine\Application\Configuration\Admin\LegacyCategoryConfigurationInspector;
use CetechDeliveryEngine\Application\Configuration\Admin\ProductVariationScopeGuard;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAdminService;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationAuthorization;
use CetechDeliveryEngine\Application\Configuration\Admin\ScopedConfigurationSubmissionParser;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\Catalog\ProductExceptionsQuery;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\ContextualEntityService;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Destination\DestinationZoneMatcher;
use CetechDeliveryEngine\Application\Diagnostics\ConfigurationHealthChecker;
use CetechDeliveryEngine\Application\ProductRule\ProductDeliveryRuleResolver;
use CetechDeliveryEngine\Application\RateQuote\RateQuoteEngine;
use CetechDeliveryEngine\Application\Runtime\LegacyProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Application\Selector\ProductDeliverySelectionValidator;
use CetechDeliveryEngine\Application\Shipping\ShippingRateCalculationGate;
use CetechDeliveryEngine\Application\Shipping\WooCommerceShippingReadiness;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Requirements;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\DestinationRuleType;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Integrations\Registry\IntegrationRegistry;
use CetechDeliveryEngine\Presentation\Admin\AdminActionHandler;
use CetechDeliveryEngine\Presentation\Admin\AdminMenu;
use CetechDeliveryEngine\Presentation\Admin\AdminNoticeService;
use CetechDeliveryEngine\Presentation\Admin\AdminPageLayout;
use CetechDeliveryEngine\Presentation\Admin\AdminRecordDependencyChecker;
use CetechDeliveryEngine\Presentation\Admin\ConfigurationAuditLogger;
use CetechDeliveryEngine\Presentation\Admin\DeliveryOffersPage;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsHomePage;
use CetechDeliveryEngine\Presentation\Admin\DeliverySettingsPage;
use CetechDeliveryEngine\Presentation\Admin\DestinationZonesPage;
use CetechDeliveryEngine\Presentation\Admin\DestinationZoneTestMatcher;
use CetechDeliveryEngine\Presentation\Admin\EffectiveConfigurationPreviewPage;
use CetechDeliveryEngine\Presentation\Admin\NeedsAttentionPage;
use CetechDeliveryEngine\Presentation\Admin\OverviewPage;
use CetechDeliveryEngine\Presentation\Admin\PickupLocationsPage;
use CetechDeliveryEngine\Presentation\Admin\ProductDeliveryPanel;
use CetechDeliveryEngine\Presentation\Admin\ProductDeliveryRulesPage;
use CetechDeliveryEngine\Presentation\Admin\ProductExceptionsPage;
use CetechDeliveryEngine\Presentation\Admin\ProductTargetResolver;
use CetechDeliveryEngine\Presentation\Admin\RateCardsPage;
use CetechDeliveryEngine\Presentation\Admin\ScopedConfigurationPage;
use CetechDeliveryEngine\Presentation\Admin\SetupWizardPage;
use CetechDeliveryEngine\Presentation\Admin\SystemStatusPage;
use CetechDeliveryEngine\Presentation\Admin\Validation\DeliveryOfferValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\DestinationZoneValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\PickupLocationValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\ProductDeliveryRuleValidator;
use CetechDeliveryEngine\Presentation\Admin\Validation\RateCardValidator;
use CetechDeliveryEngine\Support\Logger;

$root = dirname( __DIR__, 4 );

require_once __DIR__ . '/wordpress-admin-stubs.php';
require_once $root . '/vendor/autoload.php';
require_once __DIR__ . '/fixture-repositories.php';

$out_dir = __DIR__ . '/html';
if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0777, true );
}

$store = new Rc3FixtureStore();
seed_records( $store );

$offers   = new Rc3OfferRepository( $store );
$zones    = new Rc3ZoneRepository( $store );
$rules    = new Rc3RuleRepository( $store );
$rates    = new Rc3RateRepository( $store );
$pickups  = new Rc3PickupRepository( $store );
$logistics = new Rc3LogisticsRepository( $store );
$suppliers = new Rc3SupplierRepository( $store );
$origins  = new Rc3OriginRepository( $store );
$legacy   = new Rc3LegacyRuleRepository( $store );
$audit    = new Rc3AuditRepository();

$catalog = new InMemoryCatalogIndex(
	[
		101 => [ 'label' => 'Simple Lamp', 'type' => 'simple' ],
		201 => [
			'label'      => 'Variable Chair',
			'type'       => 'variable',
			'variations' => [
				202 => 'Chair / Oak',
				203 => 'Chair / Walnut',
			],
		],
		301 => [ 'label' => 'International Radio', 'type' => 'simple' ],
		401 => [ 'label' => 'In Store Speaker', 'type' => 'simple' ],
		501 => [ 'label' => 'Legacy Only Item', 'type' => 'simple' ],
		601 => [ 'label' => 'Incomplete Desk', 'type' => 'simple' ],
	]
);

$GLOBALS['cetech_de_test_wc_products'] = [
	101 => new WC_Product( [ 'id' => 101, 'type' => 'simple', 'name' => 'Simple Lamp' ] ),
	201 => new WC_Product( [ 'id' => 201, 'type' => 'variable', 'name' => 'Variable Chair' ] ),
	202 => new WC_Product( [ 'id' => 202, 'type' => 'variation', 'name' => 'Chair / Oak', 'parent_id' => 201 ] ),
	203 => new WC_Product( [ 'id' => 203, 'type' => 'variation', 'name' => 'Chair / Walnut', 'parent_id' => 201 ] ),
	301 => new WC_Product( [ 'id' => 301, 'type' => 'simple', 'name' => 'International Radio' ] ),
	401 => new WC_Product( [ 'id' => 401, 'type' => 'simple', 'name' => 'In Store Speaker' ] ),
	501 => new WC_Product( [ 'id' => 501, 'type' => 'simple', 'name' => 'Legacy Only Item' ] ),
	601 => new WC_Product( [ 'id' => 601, 'type' => 'simple', 'name' => 'Incomplete Desk' ] ),
];

$scopes   = new InMemoryScopedConfigurationRepository();
$settings = new SiteWideDefaultsSettings();
$resolver = new EffectiveConfigurationResolver(
	$scopes,
	new EffectiveConfigurationValidator(),
	new HardFulfilmentConstraintService(),
	$settings
);
$classifier = new CatalogInheritanceClassifier( $scopes, $catalog, $resolver, $settings, $legacy );
$defaults   = new SiteWideDefaultsService( $scopes, $settings, $classifier, $resolver, $catalog );
$labels     = new EntityLabelResolver( $offers, $logistics, $suppliers, $origins );
$summaries  = new SiteWideDefaultSummary( $scopes, $offers, $rates, $labels );
$progress   = new SetupWizardProgress( $settings );
$entities   = new ContextualEntityService(
	$offers,
	$zones,
	$rules,
	$rates,
	$pickups,
	new DeliveryOfferValidator(),
	new DestinationZoneValidator(),
	new DestinationRuleValidator(),
	new RateCardValidator( $offers, $zones, $logistics, $suppliers, $origins ),
	new PickupLocationValidator()
);
$readiness  = new OperationalReadinessAssessor( $resolver );
$flags      = new FeatureFlags();
$runtime    = new ClassicCheckoutRuntimeActivation( $flags, $settings );
$op_state   = new OperationalStateService( $flags, $settings, $progress, $runtime, $summaries );
$needs      = new NeedsAttentionQuery( $catalog, $readiness, $op_state, $classifier, $scopes );
$exceptions = new ProductExceptionsQuery( $scopes, $catalog, $classifier, $settings, $readiness );
$handler    = new AdminActionHandler( new AdminNoticeService() );
$logger     = new Logger();
$auditor    = new ConfigurationAuditLogger( $audit, $logger );
$deps       = new AdminRecordDependencyChecker( $rates, $origins, $legacy );
$requirements = new Requirements();
$target     = new ProductTargetResolver( $requirements );
$admin_service = new ScopedConfigurationAdminService(
	$scopes,
	$resolver,
	new ScopedConfigurationSubmissionParser(),
	new ProductVariationScopeGuard( $target ),
	$labels,
	new LegacyCategoryConfigurationInspector( $legacy ),
	$auditor
);
$auth = new ScopedConfigurationAuthorization(
	static fn ( string $cap ): bool => true,
	static fn ( string $action ): bool => true
);

seed_configuration( $defaults, $scopes, $settings, $progress );
add_filter( 'cetech_de_woocommerce_shipping_configured', static fn () => true );
$shipping_readiness = new WooCommerceShippingReadiness( $requirements );

$wizard = new SetupWizardPage(
	$progress,
	$defaults,
	$settings,
	$summaries,
	$entities,
	$scopes,
	$offers,
	$zones,
	$rates,
	$pickups,
	$needs,
	$handler,
	$runtime,
	$shipping_readiness,
	$op_state
);
$overview = new OverviewPage( $wizard, $progress, $defaults, $settings, $summaries, $needs, $handler, $op_state );
$home     = new DeliverySettingsHomePage( $defaults, $settings, $summaries, $needs, $scopes, $labels, $handler, $offers );
$offer_page = new DeliveryOffersPage( $offers, new DeliveryOfferValidator(), $handler, $auditor, $deps );
$zone_page  = new DestinationZonesPage(
	$zones,
	$rules,
	$rates,
	new DestinationZoneValidator(),
	new DestinationRuleValidator(),
	new DestinationZoneTestMatcher( new DestinationZoneMatcher( $zones, $rules ) ),
	$handler,
	$auditor,
	$deps
);
$rate_page = new RateCardsPage(
	$rates,
	$offers,
	$zones,
	$logistics,
	$suppliers,
	$origins,
	new RateCardValidator( $offers, $zones, $logistics, $suppliers, $origins ),
	new AdminRateCardTester( $rates ),
	new RateQuoteEngine( $rates ),
	$handler,
	$auditor,
	$deps
);
$pickup_page = new PickupLocationsPage( $pickups, new PickupLocationValidator(), $handler, $auditor, $deps );
$exception_page = new ProductExceptionsPage( $exceptions, $defaults, $handler );
$attention_page = new NeedsAttentionPage( $needs, $handler, $op_state );
$settings_page  = new DeliverySettingsPage(
	$flags,
	$requirements,
	$rates,
	new ShippingRateCalculationGate( $flags, $requirements ),
	$handler,
	$progress,
	$runtime,
	$shipping_readiness,
	$settings,
	$op_state
);
$legacy_page = new ProductDeliveryRulesPage(
	$legacy,
	$offers,
	$logistics,
	$suppliers,
	$origins,
	new ProductDeliveryRuleValidator( $legacy, $offers, $logistics, $suppliers, $origins, $target ),
	new ProductDeliveryRuleResolver( $legacy, $target ),
	new ProductDeliverySelectionValidator(
		$flags,
		$requirements,
		new LegacyProductDeliveryConfigurationSource( new ProductDeliveryRuleResolver( $legacy, $target ) ),
		new ProductDeliveryOptionsBuilder( $offers )
	),
	$handler,
	$auditor,
	$deps,
	$runtime,
	$classifier
);
$health = new ConfigurationHealthChecker(
	$logistics,
	$offers,
	$zones,
	$rules,
	$pickups,
	$suppliers,
	$origins,
	$rates,
	$legacy,
	$target,
	$flags
);
$status_page = new SystemStatusPage(
	$requirements,
	$flags,
	new IntegrationRegistry( $logger ),
	new Capabilities(),
	$logistics,
	$offers,
	$zones,
	$rules,
	$pickups,
	$suppliers,
	$origins,
	$rates,
	$legacy,
	$health
);
$preview_page = new EffectiveConfigurationPreviewPage( $admin_service, $target, $handler, $auth, $readiness, $catalog, $runtime, $op_state );
$scoped_page  = new ScopedConfigurationPage( $admin_service, $target, $handler, $auth, $defaults, $offers, $runtime );
$product_panel = new ProductDeliveryPanel( $admin_service, $target );

$screens = [
	[ '01-wizard-store-setup', 'Set up Delivery', SetupWizardPage::SLUG, [ 'step' => 1 ], 'wizard', [ $wizard, 'render' ] ],
	[ '02-wizard-fulfilment-types', 'Fulfilment Types', SetupWizardPage::SLUG, [ 'step' => 2 ], 'wizard', [ $wizard, 'render' ] ],
	[ '03-wizard-warehouse-defaults', 'Site-wide Defaults', SetupWizardPage::SLUG, [ 'step' => 3, 'profile_index' => 0 ], 'wizard', [ $wizard, 'render' ] ],
	[ '04-wizard-in-store-defaults', 'In Store Defaults', SetupWizardPage::SLUG, [ 'step' => 3, 'profile_index' => 1 ], 'wizard', [ $wizard, 'render' ] ],
	[ '05-wizard-international-defaults', 'International Defaults', SetupWizardPage::SLUG, [ 'step' => 3, 'profile_index' => 2 ], 'wizard', [ $wizard, 'render' ] ],
	[ '06-wizard-areas-charges', 'Delivery Areas & Charges', SetupWizardPage::SLUG, [ 'step' => 4 ], 'wizard', [ $wizard, 'render' ] ],
	[ '07-wizard-apply-products', 'Apply Site-wide Defaults', SetupWizardPage::SLUG, [ 'step' => 5 ], 'wizard', [ $wizard, 'render' ] ],
	[ '08-wizard-finish', 'Finish', SetupWizardPage::SLUG, [ 'step' => 6 ], 'wizard', [ $wizard, 'render' ] ],
	[ '09-overview', 'Overview', AdminMenu::PARENT_SLUG, [], 'overview', [ $overview, 'render' ] ],
	[ '10-site-wide-defaults', 'Site-wide Defaults', DeliverySettingsHomePage::SLUG, [ 'profile' => 'in_warehouse' ], 'delivery-settings', [ $home, 'render' ] ],
	[ '11-delivery-options', 'Delivery Options', DeliveryOffersPage::SLUG, [], 'delivery-offers', [ $offer_page, 'render' ] ],
	[ '12-delivery-option-editor', 'Edit Delivery Option', DeliveryOffersPage::SLUG, [ 'action' => 'edit', 'id' => 11 ], 'delivery-offers', [ $offer_page, 'render' ] ],
	[ '13-delivery-areas', 'Delivery Areas', DestinationZonesPage::SLUG, [], 'destination-zones', [ $zone_page, 'render' ] ],
	[ '14-delivery-area-editor', 'Edit Delivery Area', DestinationZonesPage::SLUG, [ 'action' => 'edit', 'id' => 1 ], 'destination-zones', [ $zone_page, 'render' ] ],
	[ '15-delivery-charges', 'Delivery Charges', RateCardsPage::SLUG, [], 'rate-cards', [ $rate_page, 'render' ] ],
	[ '16-delivery-charge-editor', 'Edit Delivery Charge', RateCardsPage::SLUG, [ 'action' => 'edit', 'id' => 1 ], 'rate-cards', [ $rate_page, 'render' ] ],
	[ '17-pickup-locations', 'Pickup Locations', PickupLocationsPage::SLUG, [], 'pickup-locations', [ $pickup_page, 'render' ] ],
	[ '18-product-exceptions', 'Product Exceptions', ProductExceptionsPage::SLUG, [], 'product-exceptions', [ $exception_page, 'render' ] ],
	[ '19-needs-attention', 'Needs Attention', NeedsAttentionPage::SLUG, [], 'needs-attention', [ $attention_page, 'render' ] ],
	[ '20-settings', 'Settings', DeliverySettingsPage::SLUG, [], 'settings', [ $settings_page, 'render' ] ],
	[ '21-legacy-delivery-rules', 'Legacy Delivery Rules', ProductDeliveryRulesPage::SLUG, [], 'product-rules', [ $legacy_page, 'render' ] ],
	[ '22-technical-diagnostics', 'Technical Diagnostic Tools', AdminMenu::SYSTEM_STATUS_SLUG, [], 'system-status', [ $status_page, 'render' ] ],
	[ '27-delivery-preview-ready', 'Delivery Settings Preview', EffectiveConfigurationPreviewPage::SLUG, [ 'preview' => '1', 'product_id' => 101 ], 'effective-preview', [ $preview_page, 'render' ] ],
	[ '28-delivery-preview-needs-attention', 'Delivery Settings Preview', EffectiveConfigurationPreviewPage::SLUG, [ 'preview' => '1', 'product_id' => 601 ], 'effective-preview', [ $preview_page, 'render' ] ],
	[ '24-product-customized', 'Product Delivery Settings', ScopedConfigurationPage::SLUG, [ 'scope_type' => 'product', 'scope_id' => 301, 'customize' => '1' ], 'scoped-config', [ $scoped_page, 'render' ] ],
	[ '26-variation-customized', 'Variation Delivery Settings', ScopedConfigurationPage::SLUG, [ 'scope_type' => 'variation', 'scope_id' => 203, 'parent_product_id' => 201, 'customize' => '1' ], 'scoped-config', [ $scoped_page, 'render' ] ],
];

foreach ( $screens as $screen ) {
	write_screen( $out_dir, $screen[0], $screen[1], $screen[2], $screen[3], $screen[4], $screen[5], $progress, $settings, $flags );
}

write_product_panel( $out_dir, '23-product-using-defaults', 'Simple Lamp', 101, null, $product_panel, 'product' );
write_product_panel( $out_dir, '25-variation-inherited', 'Variable Chair — Oak', 202, 201, $product_panel, 'variation' );

echo "Rendered fixture HTML into {$out_dir}\n";

function seed_records( Rc3FixtureStore $store ): void {
	$store->seed( 'offers', 11, [
		'internal_code' => 'standard-delivery',
		'internal_name' => 'Standard Delivery',
		'public_label' => 'Standard Delivery',
		'public_description' => 'Local delivery from our warehouse.',
		'route' => DeliveryRoute::LocalDelivery->value,
		'service_level' => '3–5 business days',
		'status' => RecordStatus::Active->value,
		'display_priority' => 10,
		'default_processing_min' => 1,
		'default_processing_max' => 2,
		'default_transit_min' => 2,
		'default_transit_max' => 3,
	] );
	$store->seed( 'offers', 21, [
		'internal_code' => 'store-pickup',
		'internal_name' => 'Store Pickup',
		'public_label' => 'Store Pickup',
		'public_description' => 'Collect from the Accra showroom.',
		'route' => DeliveryRoute::StorePickup->value,
		'service_level' => 'Ready in 2 hours',
		'status' => RecordStatus::Active->value,
		'display_priority' => 20,
	] );
	$store->seed( 'offers', 88, [
		'internal_code' => 'air-shipping',
		'internal_name' => 'Air Shipping',
		'public_label' => 'Air Shipping',
		'public_description' => 'Faster international delivery by air.',
		'route' => DeliveryRoute::Air->value,
		'service_level' => '7–12 business days',
		'status' => RecordStatus::Active->value,
		'display_priority' => 30,
		'default_processing_min' => 2,
		'default_processing_max' => 4,
		'default_transit_min' => 5,
		'default_transit_max' => 8,
	] );
	$store->seed( 'offers', 89, [
		'internal_code' => 'sea-shipping',
		'internal_name' => 'Sea Shipping',
		'public_label' => 'Sea Shipping',
		'public_description' => 'Economical international delivery by sea.',
		'route' => DeliveryRoute::Sea->value,
		'service_level' => '21–35 business days',
		'status' => RecordStatus::Active->value,
		'display_priority' => 40,
		'default_processing_min' => 3,
		'default_processing_max' => 5,
		'default_transit_min' => 18,
		'default_transit_max' => 30,
	] );

	$store->seed( 'zones', 1, [
		'internal_code' => 'greater-accra',
		'internal_name' => 'Greater Accra',
		'public_label' => 'Greater Accra',
		'status' => RecordStatus::Active->value,
	] );
	$store->seed( 'zones', 2, [
		'internal_code' => 'international',
		'internal_name' => 'International',
		'public_label' => 'International',
		'status' => RecordStatus::Active->value,
	] );
	$store->seed( 'rules', 1, [
		'destination_zone_id' => 1,
		'rule_type' => DestinationRuleType::Country->value,
		'rule_value' => 'GH',
		'match_mode' => DestinationRuleMatchMode::Exact->value,
		'priority' => 10,
	] );
	$store->seed( 'rules', 2, [
		'destination_zone_id' => 1,
		'rule_type' => DestinationRuleType::City->value,
		'rule_value' => 'Accra',
		'match_mode' => DestinationRuleMatchMode::Exact->value,
		'priority' => 20,
	] );
	$store->seed( 'rules', 3, [
		'destination_zone_id' => 2,
		'rule_type' => DestinationRuleType::Country->value,
		'rule_value' => 'US',
		'match_mode' => DestinationRuleMatchMode::Exact->value,
		'priority' => 10,
	] );

	$store->seed( 'rates', 1, [
		'internal_code' => 'accra-standard-flat',
		'charge_type' => RateCardChargeType::FixedPerShipment->value,
		'base_amount' => '60.00',
		'base_currency' => 'GHS',
		'delivery_offer_id' => 11,
		'destination_zone_id' => 1,
		'status' => RecordStatus::Active->value,
	] );
	$store->seed( 'rates', 2, [
		'internal_code' => 'air-shipping-flat',
		'charge_type' => RateCardChargeType::FixedPerShipment->value,
		'base_amount' => '280.00',
		'base_currency' => 'GHS',
		'delivery_offer_id' => 88,
		'destination_zone_id' => 2,
		'status' => RecordStatus::Active->value,
	] );
	$store->seed( 'rates', 3, [
		'internal_code' => 'sea-shipping-per-item',
		'charge_type' => RateCardChargeType::FixedPerItem->value,
		'base_amount' => '95.00',
		'base_currency' => 'GHS',
		'delivery_offer_id' => 89,
		'destination_zone_id' => 2,
		'status' => RecordStatus::Active->value,
	] );

	$store->seed( 'pickups', 1, [
		'internal_code' => 'accra-showroom',
		'location_name' => 'Accra Showroom',
		'public_address' => wp_json_encode( [
			'line1' => '12 Independence Avenue',
			'line2' => '',
			'city' => 'Accra',
			'region' => 'Greater Accra',
			'country_code' => 'GH',
			'postcode' => '',
		] ),
		'public_pickup_instructions' => 'Ready in 2 hours',
		'status' => RecordStatus::Active->value,
	] );

	$store->seed( 'logistics', 10, [
		'internal_code' => 'local-warehouse',
		'internal_name' => 'Local Warehouse',
		'status' => RecordStatus::Active->value,
	] );
	$store->seed( 'suppliers', 20, [
		'internal_code' => 'fixture-supplier',
		'internal_name' => 'Fixture Supplier',
		'status' => RecordStatus::Active->value,
	] );
	$store->seed( 'origins', 30, [
		'internal_code' => 'accra-origin',
		'internal_name' => 'Accra Origin',
		'supplier_id' => 20,
		'status' => RecordStatus::Active->value,
	] );
	$store->seed( 'legacy', 77, [
		'target_type' => ProductTargetType::Product->value,
		'target_id' => 501,
		'fulfilment_availability' => FulfilmentAvailability::InWarehouse->value,
		'status' => RecordStatus::Active->value,
		'internal_code' => 'legacy-lamp-rule',
	] );
}

function seed_configuration(
	SiteWideDefaultsService $defaults,
	InMemoryScopedConfigurationRepository $scopes,
	SiteWideDefaultsSettings $settings,
	SetupWizardProgress $progress
): void {
	$GLOBALS['cetech_de_test_options']['cetech_de_db_version'] = '3';
	$GLOBALS['cetech_de_test_options']['woocommerce_default_country'] = 'GH';
	$GLOBALS['cetech_de_test_options']['woocommerce_currency'] = 'GHS';

	$defaults->save_profile_defaults(
		FulfilmentAvailability::InWarehouse->value,
		profile_fields( FulfilmentChoice::Delivery->value, '3–5 business days', [ 11 ] )
	);
	$defaults->save_profile_defaults(
		FulfilmentAvailability::InStore->value,
		profile_fields( FulfilmentChoice::Delivery->value, '1–2 business days', [ 11, 21 ] )
	);
	$defaults->save_profile_defaults(
		FulfilmentAvailability::InternationalFulfilment->value,
		profile_fields( FulfilmentChoice::Delivery->value, '7–21 business days', [ 88, 89 ] )
	);

	$defaults->apply_site_wide(
		[
			FulfilmentAvailability::InWarehouse->value,
			FulfilmentAvailability::InStore->value,
			FulfilmentAvailability::InternationalFulfilment->value,
		],
		FulfilmentAvailability::InWarehouse->value,
		true
	);

	$scopes->saveScopedConfiguration(
		new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, 301, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '10–14 business days' ),
			]
		)
	);
	$scopes->saveScopedConfiguration(
		new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Variation, 203, '', 201, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'Next business day' ),
			]
		)
	);
	$scopes->saveScopedConfiguration(
		new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, 601, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [] ),
			]
		)
	);

	$progress->save(
		[
			'status'        => SetupWizardProgress::STATUS_COMPLETE,
			'step'          => 6,
			'profile_index' => 0,
			'review_mode'   => false,
			'draft'         => [
				'primary_profile'    => FulfilmentAvailability::InWarehouse->value,
				'active_profiles'    => [
					FulfilmentAvailability::InWarehouse->value,
					FulfilmentAvailability::InStore->value,
					FulfilmentAvailability::InternationalFulfilment->value,
				],
				'pickup_location_ids' => [
					FulfilmentAvailability::InStore->value => 1,
				],
			],
		]
	);
}

function profile_fields( string $choice, string $eta, array $offer_ids ): array {
	return [
		ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'override', 'value' => $choice ],
		ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'override', 'value' => 10 ],
		ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'override', 'value' => 20 ],
		ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'override', 'value' => 30 ],
		ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => 10 ],
		ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => $eta ],
		ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => $offer_ids ],
	];
}

function reset_layout_styles(): void {
	$property = new ReflectionProperty( AdminPageLayout::class, 'styles_rendered' );
	$property->setAccessible( true );
	$property->setValue( null, false );
}

function write_screen(
	string $out_dir,
	string $filename,
	string $title,
	string $slug,
	array $query,
	string $menu,
	callable $render,
	SetupWizardProgress $progress,
	SiteWideDefaultsSettings $settings,
	FeatureFlags $flags
): void {
	$_GET  = array_merge( [ 'page' => $slug ], $query );
	$_POST = [];
	prepare_screen_state( $filename, $progress, $settings, $flags );
	reset_layout_styles();
	ob_start();
	try {
		$render();
	} catch ( Throwable $e ) {
		echo '<div class="notice notice-error"><p>Fixture render error: ' . esc_html( $e->getMessage() ) . '</p></div>';
	}
	$body = (string) ob_get_clean();
	$setup_open = (bool) preg_match( '/^0[1-8]-/', $filename );
	file_put_contents( $out_dir . '/' . $filename . '.html', wrap_admin( $title, $slug, $menu, $body, false, $setup_open ) );
	echo "Wrote {$filename}.html\n";
}

function prepare_screen_state(
	string $filename,
	SetupWizardProgress $progress,
	SiteWideDefaultsSettings $settings,
	FeatureFlags $flags
): void {
	$wizard_open = (bool) preg_match( '/^0[1-8]-/', $filename );
	$settings->save( [ 'setup_completed' => ! $wizard_open || str_starts_with( $filename, '08-' ) ] );

	$draft = $progress->read()['draft'];
	if ( $wizard_open && ! str_starts_with( $filename, '08-' ) ) {
		$progress->save(
			[
				'status'        => SetupWizardProgress::STATUS_IN_PROGRESS,
				'step'          => 1,
				'profile_index' => 0,
				'review_mode'   => false,
				'draft'         => $draft,
			]
		);
		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			$flags->set( $flag, false );
		}
		return;
	}

	$progress->save(
		[
			'status'        => SetupWizardProgress::STATUS_COMPLETE,
			'step'          => 6,
			'profile_index' => 0,
			'review_mode'   => false,
			'draft'         => $draft,
		]
	);
	foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
		$flags->set( $flag, true );
	}
}

function write_product_panel( string $out_dir, string $filename, string $title, int $id, ?int $parent_id, ProductDeliveryPanel $panel, string $kind ): void {
	reset_layout_styles();
	ob_start();
	if ( 'variation' === $kind ) {
		$variation = new WP_Post();
		$variation->ID = $id;
		$variation->post_parent = (int) $parent_id;
		$panel->render_variation_panel( 0, [], $variation );
	} else {
		$post = new WP_Post();
		$post->ID = $id;
		$post->post_title = $title;
		$GLOBALS['post'] = $post;
		$panel->render_product_panel();
	}
	$panel_html = (string) ob_get_clean();
	$body = wc_product_chrome( $title, $kind, $panel_html );
	file_put_contents( $out_dir . '/' . $filename . '.html', wrap_admin( $title, 'product', 'woocommerce', $body, true ) );
	echo "Wrote {$filename}.html\n";
}

function wc_product_chrome( string $title, string $kind, string $panel_html ): string {
	$tabs = [ 'General', 'Inventory', 'Shipping', 'Linked Products', 'Delivery', 'Advanced' ];
	$html  = '<div class="wrap"><h1 class="wp-heading-inline">Edit product</h1>';
	$html .= '<p class="description">WooCommerce product editor fixture · sample catalog item: ' . esc_html( $title ) . '</p>';
	$html .= '<div id="woocommerce-product-data" class="product-data-wrapper cetech-de-delivery-tab-active"><ul class="product-data-tabs wc-tabs">';
	foreach ( $tabs as $tab ) {
		$class = 'Delivery' === $tab ? ' class="active"' : '';
		$html .= '<li' . $class . '><a href="#">' . esc_html( $tab ) . '</a></li>';
	}
	$html .= '</ul><div class="product-data-panels"><div id="cetech_de_delivery_product_data" class="woocommerce_options_panel" style="display:block">';
	$html .= $panel_html;
	$html .= '</div></div></div></div>';

	return $html;
}

function wrap_admin( string $title, string $slug, string $menu, string $body, bool $woocommerce = false, bool $setup_open = false ): string {
	$css_plugin = '../../../../../assets/admin/delivery-engine-admin.css';
	$css_scoped = '../../../../../assets/admin/scoped-configuration.css';
	$css_chrome = '../wp-admin-chrome.css';

	return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8" />'
		. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
		. '<title>' . esc_html( $title ) . ' — Delivery Engine fixture</title>'
		. '<link rel="stylesheet" href="https://s.w.org/wp-includes/css/dashicons.min.css" />'
		. '<link rel="stylesheet" href="' . esc_attr( $css_chrome ) . '" />'
		. '<link rel="stylesheet" href="' . esc_attr( $css_plugin ) . '" />'
		. '<link rel="stylesheet" href="' . esc_attr( $css_scoped ) . '" />'
		. '<script src="../../../../../assets/admin/delivery-engine-admin.js" defer></script>'
		. '</head><body class="wp-admin wp-core-ui">'
		. '<div id="wpadminbar"><span class="ab-label">Fixture Store</span><span class="ab-label">Dashboard</span><span class="ab-label">Jane Admin</span></div>'
		. '<div class="cetech-de-fixture-banner">Fixture render — sample catalog data — not a live WordPress screenshot</div>'
		. '<div id="wpwrap"><div id="wpcontent">'
		. admin_menu_html( $slug, $woocommerce, $setup_open )
		. '<div id="wpbody"><div id="wpbody-content">' . $body . '</div></div>'
		. '</div></div></body></html>';
}

function admin_menu_html( string $slug, bool $woocommerce, bool $setup_open = false ): string {
	$engine_subs = $setup_open
		? [
			[ 'Setup Guide', SetupWizardPage::SLUG ],
			[ 'Overview', AdminMenu::PARENT_SLUG ],
		]
		: [
			[ 'Overview', AdminMenu::PARENT_SLUG ],
		];
	$engine_subs = array_merge(
		$engine_subs,
		[
			[ 'Site-wide Defaults', DeliverySettingsHomePage::SLUG ],
			[ 'Delivery Options', DeliveryOffersPage::SLUG ],
			[ 'Delivery Areas', DestinationZonesPage::SLUG ],
			[ 'Delivery Charges', RateCardsPage::SLUG ],
			[ 'Pickup Locations', PickupLocationsPage::SLUG ],
			[ 'Product Exceptions', ProductExceptionsPage::SLUG ],
			[ 'Needs Attention', NeedsAttentionPage::SLUG ],
			[ 'Settings', DeliverySettingsPage::SLUG ],
			[ 'Legacy Delivery Rules', ProductDeliveryRulesPage::SLUG ],
			[ 'Technical Diagnostic Tools', AdminMenu::SYSTEM_STATUS_SLUG ],
		]
	);

	$items = [
		[ 'dashicons-dashboard', 'Dashboard', false, [] ],
		[ 'dashicons-admin-post', 'Posts', false, [] ],
		[ 'dashicons-cart', 'WooCommerce', $woocommerce, [ 'Orders', 'Customers' ] ],
		[ 'dashicons-products', 'Products', $woocommerce && 'product' === $slug, [] ],
		[
			'dashicons-location-alt',
			'Delivery Engine',
			! $woocommerce,
			$engine_subs,
		],
		[ 'dashicons-admin-appearance', 'Appearance', false, [] ],
		[ 'dashicons-admin-plugins', 'Plugins', false, [] ],
		[ 'dashicons-admin-settings', 'Settings', false, [] ],
	];

	$html = '<div id="adminmenumain"><ul id="adminmenu">';
	foreach ( $items as $item ) {
		$current = $item[2] ? ' wp-has-current-submenu current' : '';
		$html   .= '<li class="' . trim( $current ) . '"><a href="#"><span class="dashicons ' . esc_attr( $item[0] ) . '"></span><span class="wp-menu-name">' . esc_html( $item[1] ) . '</span></a>';
		if ( $item[2] && [] !== $item[3] ) {
			$html .= '<ul class="wp-submenu">';
			foreach ( $item[3] as $sub ) {
				if ( is_array( $sub ) ) {
					$class = '';
					if ( $slug === $sub[1] || ( SetupWizardPage::SLUG === $slug && 'Setup Guide' === $sub[0] ) ) {
						$class = ' class="current"';
					}
					$html .= '<li' . $class . '><a href="#">' . esc_html( $sub[0] ) . '</a></li>';
				} else {
					$html .= '<li><a href="#">' . esc_html( $sub ) . '</a></li>';
				}
			}
			$html .= '</ul>';
		}
		$html .= '</li>';
	}
	$html .= '</ul></div>';

	return $html;
}
