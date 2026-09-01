<?php

declare(strict_types=1);

/**
 * Production-package autoload / boot-wiring verification for Stage 5B/6B packaging.
 *
 * Usage:
 *   php scripts/verify-production-package-autoload.php /path/to/staged/plugin-root
 *
 * Expects composer install --no-dev --optimize-autoloader already completed.
 * Does not boot WordPress. Exits 0 on success, 1 on failure.
 */

$package_root = isset( $argv[1] ) ? (string) $argv[1] : '';

if ( '' === $package_root || ! is_dir( $package_root ) ) {
	fwrite( STDERR, "Usage: php scripts/verify-production-package-autoload.php <package-root>\n" );
	exit( 1 );
}

$package_root = rtrim( str_replace( '\\', '/', $package_root ), '/' );
$autoload     = $package_root . '/vendor/autoload.php';
$plugin_php   = $package_root . '/src/Bootstrap/Plugin.php';
$health_php   = $package_root . '/src/Application/Diagnostics/ConfigurationHealthChecker.php';

$failures = [];

if ( ! is_readable( $autoload ) ) {
	$failures[] = 'Missing vendor/autoload.php (run composer install --no-dev first).';
}

if ( ! is_readable( $plugin_php ) ) {
	$failures[] = 'Missing src/Bootstrap/Plugin.php';
}

if ( ! is_readable( $health_php ) ) {
	$failures[] = 'Missing src/Application/Diagnostics/ConfigurationHealthChecker.php';
}

if ( [] !== $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

$plugin_source = (string) file_get_contents( $plugin_php );
if ( ! str_contains( $plugin_source, 'use CetechDeliveryEngine\\Application\\Diagnostics\\ConfigurationHealthChecker;' ) ) {
	$failures[] = 'Plugin.php missing Diagnostics\\ConfigurationHealthChecker import (Bootstrap namespace collision risk).';
}

$health_source = (string) file_get_contents( $health_php );
if ( ! str_contains( $health_source, 'namespace CetechDeliveryEngine\\Application\\Diagnostics;' ) ) {
	$failures[] = 'ConfigurationHealthChecker.php has unexpected namespace.';
}

require_once $autoload;

$required_classes = [
	'CetechDeliveryEngine\\Application\\Diagnostics\\ConfigurationHealthChecker',
	'CetechDeliveryEngine\\Bootstrap\\Plugin',
	'CetechDeliveryEngine\\Bootstrap\\ServiceContainer',
	'CetechDeliveryEngine\\Bootstrap\\FeatureFlags',
	'CetechDeliveryEngine\\Core\\Versioning\\SchemaVersion',
	'CetechDeliveryEngine\\Presentation\\Admin\\AdminMenu',
	'CetechDeliveryEngine\\Presentation\\Admin\\SystemStatusPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\ScopedConfigurationPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\EffectiveConfigurationPreviewPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\DeliverySettingsHomePage',
	'CetechDeliveryEngine\\Presentation\\Admin\\ProductExceptionsPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\NeedsAttentionPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\OverviewPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\SetupWizardPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\ProductDeliveryPanel',
	'CetechDeliveryEngine\\Presentation\\Admin\\AdminUxAssets',
	'CetechDeliveryEngine\\Presentation\\Admin\\PreviewVariationsEndpoint',
	'CetechDeliveryEngine\\Application\\Configuration\\SetupWizardProgress',
	'CetechDeliveryEngine\\Application\\Configuration\\ContextualEntityService',
	'CetechDeliveryEngine\\Domain\\FulfilmentProfile\\FulfilmentProfileRegistry',
	'CetechDeliveryEngine\\Application\\Configuration\\SiteWideDefaultsService',
	'CetechDeliveryEngine\\Application\\Configuration\\EffectiveConfigurationResolver',
	'CetechDeliveryEngine\\Presentation\\Admin\\ProductTargetResolver',
	'CetechDeliveryEngine\\Application\\Configuration\\Admin\\ProductVariationScopeGuard',
	'CetechDeliveryEngine\\Application\\Selector\\ProductDeliverySelectionValidator',
	'CetechDeliveryEngine\\Application\\Runtime\\ProductDeliveryRuntimeConfigurationRouter',
	'CetechDeliveryEngine\\Application\\Runtime\\WooCommerceVariationRelationshipInspector',
	'CetechDeliveryEngine\\Application\\Selector\\VariationDeliveryOptionsEndpoint',
	'CetechDeliveryEngine\\Presentation\\Frontend\\VariableDeliverySelectorAssets',
	'CetechDeliveryEngine\\Domain\\RateCard\\RateCardAmountFormatter',
	'CetechDeliveryEngine\\Application\\Cart\\CartDeliverySelectionSessionData',
	'CetechDeliveryEngine\\Bootstrap\\RuntimeContracts',
	'CetechDeliveryEngine\\Core\\Versioning\\MigrationDiscovery',
	'CetechDeliveryEngine\\Application\\Shipping\\DeliveryGroupIdentity',
	'CetechDeliveryEngine\\Application\\Shipping\\ShippingPackageBuilder',
	'CetechDeliveryEngine\\Application\\Shipping\\DefaultCartLineShippingAssessor',
	'CetechDeliveryEngine\\Application\\Shipping\\SelectedOfferShippingIntegration',
	'CetechDeliveryEngine\\Application\\Shipping\\SelectedOfferShippingRateCalculator',
	'CetechDeliveryEngine\\Application\\Order\\OrderDeliveryGroupSnapshot',
	'CetechDeliveryEngine\\Presentation\\Admin\\OrderDeliverySnapshotAdminDisplay',
	'CetechDeliveryEngine\\Presentation\\Admin\\OrderShippingItemPresentationGuard',
	'CetechDeliveryEngine\\Application\\Configuration\\DeliveryOptionCompatibility',
	'CetechDeliveryEngine\\Application\\Configuration\\Admin\\StaffChargeSummary',
	'CetechDeliveryEngine\\Presentation\\Admin\\StaffDeliveryCustomizeView',
	'CetechDeliveryEngine\\Application\\Configuration\\OperationalReadinessAssessor',
	'CetechDeliveryEngine\\Application\\Configuration\\OperationalState',
	'CetechDeliveryEngine\\Application\\Configuration\\OperationalStateService',
	'CetechDeliveryEngine\\Application\\Configuration\\Admin\\AdminMoneyFormatter',
	'CetechDeliveryEngine\\Application\\Configuration\\Admin\\StoreAwareExamples',
	'CetechDeliveryEngine\\Application\\Configuration\\Admin\\OfferEstimatedDeliveryDisplay',
	'CetechDeliveryEngine\\Core\\Capabilities\\Capabilities',
	'CetechDeliveryEngine\\Core\\Capabilities\\RoleAccessService',
	'CetechDeliveryEngine\\Presentation\\Admin\\AdministratorAccessRecovery',
	'CetechDeliveryEngine\\Application\\Configuration\\ClassicCheckoutRuntimeActivation',
	'CetechDeliveryEngine\\Application\\Configuration\\SetupWizardProgress',
	'CetechDeliveryEngine\\Application\\Shipping\\WooCommerceShippingReadiness',
	'CetechDeliveryEngine\\Infrastructure\\Persistence\\ShipmentSchema',
	'CetechDeliveryEngine\\Infrastructure\\Persistence\\WpdbShipmentRepository',
	'CetechDeliveryEngine\\Application\\Shipment\\ShipmentStatusService',
	'CetechDeliveryEngine\\Application\\Shipment\\PaidOrderShipmentSubscriber',
	'CetechDeliveryEngine\\Application\\Shipment\\CodAwaitingShipmentStore',
	'CetechDeliveryEngine\\Application\\Shipment\\CodAwaitingShipmentEvaluator',
	'CetechDeliveryEngine\\Application\\Shipment\\CodAwaitingShipmentQuery',
	'CetechDeliveryEngine\\Application\\Shipment\\CodAwaitingShipmentSubscriber',
	'CetechDeliveryEngine\\Application\\Shipment\\ManualShipmentCreationPreviewFactory',
	'CetechDeliveryEngine\\Application\\Shipment\\ShipmentService',
	'CetechDeliveryEngine\\Application\\Shipment\\CustomerShipmentQuery',
	'CetechDeliveryEngine\\Presentation\\Admin\\ShipmentsPage',
	'CetechDeliveryEngine\\Presentation\\Frontend\\CustomerShipmentRenderer',
	'CetechDeliveryEngine\\Application\\Shipment\\ShipmentActivityCursor',
	'CetechDeliveryEngine\\Application\\Configuration\\Catalog\\NeedsAttentionCountQuery',
	'CetechDeliveryEngine\\Presentation\\Admin\\AdminMenuBadgeMarkup',
	'CetechDeliveryEngine\\Presentation\\Admin\\BulkToolsPage',
	'CetechDeliveryEngine\\Presentation\\Admin\\BulkCatalogAdminChoices',
	'CetechDeliveryEngine\\Presentation\\Admin\\BulkJobAdminCopy',
	'CetechDeliveryEngine\\Presentation\\Admin\\BulkJobItemResultPresenter',
	'CetechDeliveryEngine\\Presentation\\Admin\\BulkJobTargetLabelResolver',
	'CetechDeliveryEngine\\Presentation\\Admin\\BulkAdminListPreferences',
	'CetechDeliveryEngine\\Application\\Bulk\\BulkJobEngine',
	'CetechDeliveryEngine\\Application\\Bulk\\BulkJobWorker',
	'CetechDeliveryEngine\\Application\\Bulk\\BulkJobRunnerState',
	'CetechDeliveryEngine\\Application\\Bulk\\BulkQueueHealth',
	'CetechDeliveryEngine\\Application\\Bulk\\BulkStaleJobQuery',
	'CetechDeliveryEngine\\Application\\Bulk\\Queue\\ActionSchedulerQueue',
	'CetechDeliveryEngine\\Application\\Bulk\\Queue\\WpActionSchedulerGateway',
	'CetechDeliveryEngine\\Infrastructure\\Persistence\\BulkJobSchema',
];

$required_interfaces = [
	'CetechDeliveryEngine\\Application\\Runtime\\VariationRelationshipInspectorInterface',
	'CetechDeliveryEngine\\Core\\Versioning\\VerifiableMigrationInterface',
	'CetechDeliveryEngine\\Core\\Versioning\\MigrationInterface',
	'CetechDeliveryEngine\\Application\\Shipping\\CartLineShippingAssessorInterface',
	'CetechDeliveryEngine\\Application\\Destination\\PackageDestinationZoneResolverInterface',
	'CetechDeliveryEngine\\Application\\Bulk\\Queue\\ActionSchedulerGateway',
];

foreach ( $required_classes as $class ) {
	if ( ! class_exists( $class ) ) {
		$failures[] = "Autoload failed for required runtime class: {$class}";
	}
}

foreach ( $required_interfaces as $interface ) {
	if ( ! interface_exists( $interface ) ) {
		$failures[] = "Autoload failed for required runtime interface: {$interface}";
	}
}

$preview_php = $package_root . '/src/Presentation/Admin/EffectiveConfigurationPreviewPage.php';
if ( ! is_readable( $preview_php ) ) {
	$failures[] = 'Missing EffectiveConfigurationPreviewPage.php';
} else {
	$preview_source = (string) file_get_contents( $preview_php );
	if ( ! str_contains( $preview_source, 'instanceof \\WC_Product' ) && ! str_contains( $preview_source, 'instanceof WC_Product' ) ) {
		$failures[] = 'EffectiveConfigurationPreviewPage missing WC_Product instanceof guard (false-vs-null risk).';
	}
	if ( str_contains( $preview_source, 'null === $product' ) ) {
		$failures[] = 'EffectiveConfigurationPreviewPage still uses null-only product absence check.';
	}
}

if ( class_exists( 'CetechDeliveryEngine\\Bootstrap\\ConfigurationHealthChecker', false ) ) {
	$failures[] = 'Unexpected Bootstrap\\ConfigurationHealthChecker class exists.';
}

if ( class_exists( 'PHPUnit\\Framework\\TestCase' ) ) {
	$failures[] = 'PHPUnit appears present in production vendor (dev dependency leak).';
}

if ( class_exists( 'CetechDeliveryEngine\\Bootstrap\\FeatureFlags' ) ) {
	$reflection = new ReflectionClass( 'CetechDeliveryEngine\\Bootstrap\\FeatureFlags' );
	$defaults   = $reflection->getConstant( 'DEFAULTS' );
	if ( ! is_array( $defaults ) || ! array_key_exists( 'enable_effective_configuration_runtime', $defaults ) ) {
		$failures[] = 'FeatureFlags missing enable_effective_configuration_runtime default.';
	} elseif ( true === $defaults['enable_effective_configuration_runtime'] ) {
		$failures[] = 'enable_effective_configuration_runtime default must be false.';
	}
	if ( ! is_array( $defaults ) || ! array_key_exists( 'enable_variable_product_ecr_runtime', $defaults ) ) {
		$failures[] = 'FeatureFlags missing enable_variable_product_ecr_runtime default.';
	} elseif ( true === $defaults['enable_variable_product_ecr_runtime'] ) {
		$failures[] = 'enable_variable_product_ecr_runtime default must be false.';
	}

	foreach ( [ 'enable_shipment_records', 'enable_tracking_links', 'enable_customer_timeline' ] as $shipment_flag ) {
		if ( ! is_array( $defaults ) || ! array_key_exists( $shipment_flag, $defaults ) ) {
			$failures[] = "FeatureFlags missing {$shipment_flag} default.";
		} elseif ( true === $defaults[ $shipment_flag ] ) {
			$failures[] = "{$shipment_flag} default must be false.";
		}
	}
}

$variable_js  = $package_root . '/assets/frontend/variable-delivery-selector.js';
$variable_css = $package_root . '/assets/frontend/variable-delivery-selector.css';
if ( ! is_readable( $variable_js ) ) {
	$failures[] = 'Missing assets/frontend/variable-delivery-selector.js';
} else {
	$js_source = (string) file_get_contents( $variable_js );
	if ( ! str_contains( $js_source, 'found_variation' ) ) {
		$failures[] = 'variable-delivery-selector.js missing found_variation listener.';
	}
	if ( ! str_contains( $js_source, 'reset_data' ) ) {
		$failures[] = 'variable-delivery-selector.js missing reset_data listener.';
	}
	if ( ! str_contains( $js_source, 'requestToken' ) ) {
		$failures[] = 'variable-delivery-selector.js missing stale-request protection (requestToken).';
	}
}
if ( ! is_readable( $variable_css ) ) {
	$failures[] = 'Missing assets/frontend/variable-delivery-selector.css';
}

$product_css = $package_root . '/assets/frontend/product-delivery-selector.css';
if ( ! is_readable( $product_css ) ) {
	$failures[] = 'Missing assets/frontend/product-delivery-selector.css';
}

$admin_css = $package_root . '/assets/admin/delivery-engine-admin.css';
$admin_js  = $package_root . '/assets/admin/delivery-engine-admin.js';
if ( ! is_readable( $admin_css ) ) {
	$failures[] = 'Missing assets/admin/delivery-engine-admin.css';
} else {
	$admin_css_source = (string) file_get_contents( $admin_css );
	if ( ! str_contains( $admin_css_source, 'cetech-de-delivery-tab-active' ) ) {
		$failures[] = 'Admin CSS missing scoped Delivery product-panel responsive class.';
	}
	if ( ! str_contains( $admin_css_source, 'auto-fit' ) ) {
		$failures[] = 'Admin CSS missing responsive auto-fit grid.';
	}
}
if ( ! is_readable( $admin_js ) ) {
	$failures[] = 'Missing assets/admin/delivery-engine-admin.js';
}

$plugin_header = $package_root . '/cetech-woocommerce-delivery-engine.php';
if ( is_readable( $plugin_header ) ) {
	$header_source = (string) file_get_contents( $plugin_header );
	if ( ! str_contains( $header_source, "define( 'CETECH_DE_VERSION'" ) ) {
		$failures[] = 'Plugin bootstrap missing CETECH_DE_VERSION.';
	}
	if ( preg_match( '/code[_ -]?snippets/i', $header_source ) ) {
		$failures[] = 'Plugin bootstrap must not depend on Code Snippets.';
	}
}

$header_source = is_readable( $plugin_header ) ? (string) file_get_contents( $plugin_header ) : (string) ( $header_source ?? '' );
$is_schema5_release = str_contains( $header_source, '1.0.0-dev.bulk' )
	|| str_contains( $header_source, '1.0.0-dev.fulfilment' )
	|| str_contains( $header_source, '1.0.0-dev.blocks' )
	|| str_contains( $header_source, '1.0.0-dev.cartstate' )
	|| str_contains( $header_source, '1.0.0-dev.peritem' )
	|| str_contains( $header_source, '1.0.0-dev.wcfm' )
	|| str_contains( $header_source, '1.0.0-dev.integrated' )
	|| str_contains( $header_source, '1.0.0-rc.7' )
	|| str_contains( $header_source, '1.0.0-rc.8' )
	|| str_contains( $header_source, '1.0.0-rc.9' );

if ( class_exists( 'CetechDeliveryEngine\\Core\\Versioning\\SchemaVersion' ) ) {
	$target = ( new ReflectionClass( 'CetechDeliveryEngine\\Core\\Versioning\\SchemaVersion' ) )->getConstant( 'TARGET' );
	if ( $is_schema5_release ) {
		if ( '5' !== $target ) {
			$failures[] = 'SchemaVersion::TARGET must be 5 for this schema-5 package.';
		}
	} elseif ( '4' !== $target ) {
		$failures[] = 'SchemaVersion::TARGET must be 4 for this package.';
	}
}

$bulk_js = $package_root . '/assets/admin/bulk-tools.js';
if ( $is_schema5_release && ! is_readable( $bulk_js ) ) {
	$failures[] = 'Missing assets/admin/bulk-tools.js';
} elseif ( is_readable( $bulk_js ) && ( str_contains( $header_source, '1.0.0-dev.bulk.9' ) || str_contains( $header_source, '1.0.0-dev.fulfilment' ) || str_contains( $header_source, '1.0.0-dev.blocks' ) || str_contains( $header_source, '1.0.0-dev.cartstate' ) || str_contains( $header_source, '1.0.0-dev.peritem' ) || str_contains( $header_source, '1.0.0-dev.wcfm' ) || str_contains( $header_source, '1.0.0-dev.integrated' ) || str_contains( $header_source, '1.0.0-rc.7' ) || str_contains( $header_source, '1.0.0-rc.8' ) || str_contains( $header_source, '1.0.0-rc.9' ) ) ) {
	$bulk_js_source = (string) file_get_contents( $bulk_js );
	if ( ! str_contains( $bulk_js_source, "body.set('advance', '1')" ) ) {
		$failures[] = 'bulk-tools.js missing bounded AJAX continue (advance=1).';
	}
	$as_gateway = $package_root . '/src/Application/Bulk/Queue/WpActionSchedulerGateway.php';
	if ( ! is_readable( $as_gateway ) ) {
		$failures[] = 'Missing WpActionSchedulerGateway.php';
	} else {
		$as_source = (string) file_get_contents( $as_gateway );
		if ( ! str_contains( $as_source, 'as_enqueue_async_action' ) || ! str_contains( $as_source, 'maybe_dispatch' ) ) {
			$failures[] = 'WpActionSchedulerGateway missing async enqueue or best-effort kick.';
		}
		if ( ! str_contains( $as_source, 'if ( null !== $args )' ) ) {
			$failures[] = 'WpActionSchedulerGateway pending_count must not treat group diagnostics as empty args.';
		}
	}
}

$forbidden = [
	'/tests',
	'/phpunit.xml',
	'/docs/RC6-ADVERSARIAL-SECURITY-AUDIT.md',
	'/docs/audit',
	'/.env',
	'/.env.local',
];
foreach ( $forbidden as $rel ) {
	if ( file_exists( $package_root . $rel ) ) {
		$failures[] = 'Package must not include development/security-audit path: ' . $rel;
	}
}

// Boot-graph class-reference audit: every Xxx::class short name in Plugin.php must import or be Bootstrap-local.
if ( preg_match_all( '/\b([A-Z][A-Za-z0-9_]+)::class\b/', $plugin_source, $matches ) ) {
	$use_map = [];
	if ( preg_match_all( '/^use\s+([^;]+);/m', $plugin_source, $use_matches ) ) {
		foreach ( $use_matches[1] as $fqcn ) {
			$fqcn = trim( $fqcn );
			if ( preg_match( '/\bas\s+([A-Za-z_][A-Za-z0-9_]*)$/', $fqcn, $alias_match ) ) {
				$short = $alias_match[1];
			} else {
				$parts = explode( '\\', $fqcn );
				$short = (string) end( $parts );
			}
			$use_map[ $short ] = $fqcn;
		}
	}

	$short_names = array_unique( $matches[1] );
	sort( $short_names );

	foreach ( $short_names as $short ) {
		if ( isset( $use_map[ $short ] ) ) {
			continue;
		}

		$bootstrap_file = $package_root . '/src/Bootstrap/' . $short . '.php';
		if ( is_readable( $bootstrap_file ) ) {
			continue;
		}

		$failures[] = "Plugin.php references {$short}::class without import and without Bootstrap/{$short}.php";
	}
}

$contracts_class = 'CetechDeliveryEngine\\Bootstrap\\RuntimeContracts';
if ( class_exists( $contracts_class ) ) {
	$missing_contracts = $contracts_class::missing_paths( $package_root );
	foreach ( $missing_contracts as $missing_contract ) {
		$failures[] = "Runtime contract file missing from package: {$missing_contract}";
	}

	if ( [] === $missing_contracts && ! $contracts_class::load( $package_root ) ) {
		$failures[] = 'RuntimeContracts::load() failed against the extracted package.';
	}

	foreach ( $contracts_class::interface_names() as $contract_name ) {
		if ( ! interface_exists( $contract_name ) ) {
			$failures[] = "Runtime contract interface not defined after load: {$contract_name}";
		}
	}
} else {
	$failures[] = 'RuntimeContracts class did not autoload.';
}

$inspector_class = 'CetechDeliveryEngine\\Application\\Runtime\\WooCommerceVariationRelationshipInspector';
if ( class_exists( $inspector_class ) ) {
	try {
		$inspector = new $inspector_class();
		if ( ! $inspector instanceof \CetechDeliveryEngine\Application\Runtime\VariationRelationshipInspectorInterface ) {
			$failures[] = 'WooCommerceVariationRelationshipInspector does not implement VariationRelationshipInspectorInterface.';
		}
	} catch ( \Throwable $exception ) {
		$failures[] = 'Failed to instantiate WooCommerceVariationRelationshipInspector: ' . $exception->getMessage();
	}
}

$schema3_migration = $package_root . '/database/migrations/20260810160000_create_scoped_configuration_tables.php';
if ( ! is_readable( $schema3_migration ) ) {
	$failures[] = 'Missing schema v3 migration file.';
} else {
	try {
		$loaded_migration = require $schema3_migration;
		if ( ! $loaded_migration instanceof \CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface ) {
			$failures[] = 'Schema v3 migration did not return VerifiableMigrationInterface after package autoload.';
		} elseif ( '3' !== $loaded_migration->get_version() ) {
			$failures[] = 'Schema v3 migration get_version() must be 3.';
		}
	} catch ( \Throwable $exception ) {
		$failures[] = 'Schema v3 migration failed to load via production require path: ' . $exception->getMessage();
	}
}

$classmap_file = $package_root . '/vendor/composer/autoload_classmap.php';
if ( ! is_readable( $classmap_file ) ) {
	$failures[] = 'Missing vendor/composer/autoload_classmap.php.';
} else {
	$classmap = require $classmap_file;
	if ( ! is_array( $classmap ) ) {
		$failures[] = 'Composer classmap is not an array.';
	} else {
		$required_map = [
			'CetechDeliveryEngine\\Application\\Runtime\\VariationRelationshipInspectorInterface' => 'src/Application/Runtime/VariationRelationshipInspectorInterface.php',
			'CetechDeliveryEngine\\Application\\Runtime\\WooCommerceVariationRelationshipInspector' => 'src/Application/Runtime/WooCommerceVariationRelationshipInspector.php',
			'CetechDeliveryEngine\\Core\\Versioning\\VerifiableMigrationInterface' => 'src/Core/Versioning/VerifiableMigrationInterface.php',
			'CetechDeliveryEngine\\Core\\Versioning\\MigrationInterface' => 'src/Core/Versioning/MigrationInterface.php',
		];

		foreach ( $required_map as $fqcn => $expected_relative ) {
			if ( ! isset( $classmap[ $fqcn ] ) ) {
				$failures[] = "Composer classmap missing {$fqcn}";
				continue;
			}

			$mapped = str_replace( '\\', '/', (string) $classmap[ $fqcn ] );
			if ( ! str_ends_with( $mapped, '/' . $expected_relative ) && ! str_ends_with( $mapped, $expected_relative ) ) {
				$failures[] = "Composer classmap path for {$fqcn} is not Linux-case {$expected_relative}: {$mapped}";
			}
		}

		foreach ( $classmap as $fqcn => $file_path ) {
			if ( ! is_string( $fqcn ) || ! str_starts_with( $fqcn, 'CetechDeliveryEngine\\' ) ) {
				continue;
			}

			$mapped = str_replace( '\\', '/', (string) $file_path );
			if ( ! is_readable( (string) $file_path ) ) {
				$failures[] = "Composer classmap file missing for {$fqcn}: {$mapped}";
				continue;
			}

			$short    = substr( $fqcn, (int) strrpos( $fqcn, '\\' ) + 1 );
			$basename = basename( $mapped, '.php' );

			// Secondary types may share a primary class file. Only the primary
			// type is required to match the PSR-4 filename.
			if ( $basename !== $short ) {
				continue;
			}

			$relative = 'src/' . str_replace( '\\', '/', substr( $fqcn, strlen( 'CetechDeliveryEngine\\' ) ) ) . '.php';

			if ( ! str_ends_with( $mapped, '/' . $relative ) && ! str_ends_with( $mapped, $relative ) ) {
				$failures[] = "Linux-case PSR-4 mismatch for {$fqcn}; expected suffix {$relative}";
			}
		}
	}
}

$autoload_static = $package_root . '/vendor/composer/autoload_static.php';
if ( is_readable( $autoload_static ) ) {
	$static_source = (string) file_get_contents( $autoload_static );
	foreach (
		[
			'src/Core/Versioning/VerifiableMigrationInterface.php',
			'src/Application/Runtime/VariationRelationshipInspectorInterface.php',
		] as $files_autoload_path
	) {
		if ( ! str_contains( $static_source, $files_autoload_path ) ) {
			$failures[] = "Composer files autoload missing {$files_autoload_path}";
		}
	}
}

if ( [] !== $failures ) {
	fwrite( STDERR, "Package verification FAILED:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

// Stage 13C-R1 repair presence checks (source inspection inside package).
$caps_php = $package_root . '/src/Core/Capabilities/Capabilities.php';
if ( ! is_readable( $caps_php ) ) {
	$failures[] = 'Missing Capabilities.php';
} else {
	$caps_source = (string) file_get_contents( $caps_php );
	if ( ! str_contains( $caps_source, 'function ensure_current' ) || ! str_contains( $caps_source, 'VERSION_OPTION' ) ) {
		$failures[] = 'Capabilities self-heal (ensure_current / VERSION_OPTION) missing from package.';
	}
}

$plugin_boot = (string) file_get_contents( $plugin_php );
if ( ! str_contains( $plugin_boot, 'ensure_current()' ) ) {
	$failures[] = 'Plugin.php does not call Capabilities::ensure_current() on boot.';
}

$preview_source = is_readable( $preview_php ) ? (string) file_get_contents( $preview_php ) : '';
if ( '' !== $preview_source && ! str_contains( $preview_source, 'should_handle_posted_action' ) ) {
	$failures[] = 'Preview page gate should_handle_posted_action missing from package.';
}

$wizard_php = $package_root . '/src/Presentation/Admin/SetupWizardPage.php';
if ( ! is_readable( $wizard_php ) ) {
	$failures[] = 'Missing SetupWizardPage.php';
} else {
	$wizard_source = (string) file_get_contents( $wizard_php );
	if ( ! str_contains( $wizard_source, 'continue_redirect_args' ) ) {
		$failures[] = 'SetupWizardPage continue_redirect_args missing from package.';
	}
	if ( ! str_contains( $wizard_source, 'validate_fulfilment_default_selection' ) ) {
		$failures[] = 'SetupWizardPage R3 fulfilment validation missing from package.';
	}
	if ( ! str_contains( $wizard_source, 'Select at least one Delivery Option before continuing.' ) ) {
		$failures[] = 'SetupWizardPage R3 Delivery Option validation message missing from package.';
	}
	if ( ! str_contains( $wizard_source, 'Inline create panels must stay outside the save form' ) ) {
		$failures[] = 'SetupWizardPage R3 nested-form repair missing from package.';
	}
	if ( ! str_contains( $wizard_source, 'Create Air or Sea Shipping option' ) ) {
		$failures[] = 'SetupWizardPage R3 International Air/Sea guided create missing from package.';
	}
}

$menu_php = $package_root . '/src/Presentation/Admin/AdminMenu.php';
if ( is_readable( $menu_php ) ) {
	$menu_source = (string) file_get_contents( $menu_php );
	if ( ! str_contains( $menu_source, "'options.php'" ) ) {
		$failures[] = 'AdminMenu missing options.php registration for Product Delivery Settings.';
	}
}

$needs_php = $package_root . '/src/Application/Configuration/Catalog/NeedsAttentionQuery.php';
if ( is_readable( $needs_php ) ) {
	$needs_source = (string) file_get_contents( $needs_php );
	if ( ! str_contains( $needs_source, 'scan_catalog_as_customer_problems' ) ) {
		$failures[] = 'NeedsAttentionQuery missing upgrade-aware scan gate.';
	}
}

$assessor_php = $package_root . '/src/Application/Configuration/OperationalReadinessAssessor.php';
if ( is_readable( $assessor_php ) ) {
	$assessor_source = (string) file_get_contents( $assessor_php );
	if ( ! str_contains( $assessor_source, 'null !== $slice_key' )
		|| ! str_contains( $assessor_source, 'DEFAULT_SLICE_KEY' )
	) {
		$failures[] = 'OperationalReadinessAssessor missing explicit null-vs-empty slice semantics.';
	}
	if ( ! str_contains( $assessor_source, 'Private/technical unresolved fields' ) ) {
		$failures[] = 'OperationalReadinessAssessor missing R3 private-field readiness guard.';
	}
}

$exceptions_php = $package_root . '/src/Application/Configuration/Catalog/ProductExceptionsQuery.php';
if ( is_readable( $exceptions_php ) ) {
	$exceptions_source = (string) file_get_contents( $exceptions_php );
	if ( ! str_contains( $exceptions_source, 'technical_delivery_details_label' ) ) {
		$failures[] = 'ProductExceptionsQuery missing Technical delivery details privacy summarization.';
	}
	if ( preg_match( '/customized_labels[\s\S]{0,800}ConfigurationFieldCatalog::label\(\s*\$field_key\s*\)/', $exceptions_source )
		&& ! str_contains( $exceptions_source, 'private_field_keys' )
	) {
		$failures[] = 'ProductExceptionsQuery still appears to dump every field label including private fields.';
	}
}

$eta_php = $package_root . '/src/Application/Configuration/Admin/OfferEstimatedDeliveryDisplay.php';
if ( ! is_readable( $eta_php ) ) {
	$failures[] = 'Missing OfferEstimatedDeliveryDisplay.php';
} else {
	$eta_source = (string) file_get_contents( $eta_php );
	if ( ! str_contains( $eta_source, 'INTERNAL_SERVICE_LEVEL_CODES' ) || ! str_contains( $eta_source, 'standard' ) ) {
		$failures[] = 'OfferEstimatedDeliveryDisplay must refuse internal service_level codes as ETA.';
	}
}

$money_php = $package_root . '/src/Application/Configuration/Admin/AdminMoneyFormatter.php';
if ( ! is_readable( $money_php ) ) {
	$failures[] = 'Missing AdminMoneyFormatter.php';
}

$preview_variations_php = $package_root . '/src/Presentation/Admin/PreviewVariationsEndpoint.php';
if ( ! is_readable( $preview_variations_php ) ) {
	$failures[] = 'Missing PreviewVariationsEndpoint.php';
} else {
	$preview_variations_source = (string) file_get_contents( $preview_variations_php );
	if ( ! str_contains( $preview_variations_source, 'cetech_de_preview_variations' )
		|| ! str_contains( $preview_variations_source, 'check_ajax_referer' )
		|| ! str_contains( $preview_variations_source, 'can_preview' )
	) {
		$failures[] = 'PreviewVariationsEndpoint missing AJAX action, nonce, or capability checks.';
	}
}

if ( ! str_contains( $plugin_boot, 'PreviewVariationsEndpoint' ) ) {
	$failures[] = 'Plugin.php does not register PreviewVariationsEndpoint.';
}

$resolver_php = $package_root . '/src/Application/Configuration/EffectiveConfigurationResolver.php';
if ( is_readable( $resolver_php ) ) {
	$resolver_source = (string) file_get_contents( $resolver_php );
	if ( ! str_contains( $resolver_source, 'Stage 13 root (empty slice) → sole Stage 5/6 profile item scope' )
		&& ! str_contains( $resolver_source, 'sole Stage 5/6 profile item scope' )
	) {
		$failures[] = 'EffectiveConfigurationResolver missing Stage 6 ↔ Stage 13 item-scope bridge.';
	}
}

$admin_js_source = is_readable( $admin_js ) ? (string) file_get_contents( $admin_js ) : '';
if ( '' === $admin_js_source || ! str_contains( $admin_js_source, 'loadPreviewVariations' ) || ! str_contains( $admin_js_source, 'cetech_de_preview_variations' ) ) {
	$failures[] = 'Admin JS missing Preview variation refresh logic.';
}

$access_php = $package_root . '/src/Core/Capabilities/RoleAccessService.php';
if ( ! is_readable( $access_php ) ) {
	$failures[] = 'Missing RoleAccessService.php';
} else {
	$access_source = (string) file_get_contents( $access_php );
	if ( ! str_contains( $access_source, 'function apply' ) || ! str_contains( $access_source, 'locked_for_administrator' ) ) {
		$failures[] = 'RoleAccessService missing apply() or administrator lockout protection.';
	}
	if ( ! str_contains( $access_source, 'function editable_roles' ) || ! str_contains( $access_source, 'function protect_administrator' ) ) {
		$failures[] = 'RoleAccessService missing editable_roles()/protect_administrator() Stage 13D-R1 hardening.';
	}
}

$recovery_php = $package_root . '/src/Presentation/Admin/AdministratorAccessRecovery.php';
if ( ! is_readable( $recovery_php ) ) {
	$failures[] = 'Missing AdministratorAccessRecovery.php';
} else {
	$recovery_source = (string) file_get_contents( $recovery_php );
	if ( ! str_contains( $recovery_source, "manage_options" ) || ! str_contains( $recovery_source, 'check_admin_referer' ) ) {
		$failures[] = 'AdministratorAccessRecovery missing manage_options + nonce protection.';
	}
}

$caps_source = is_readable( $caps_php ) ? (string) file_get_contents( $caps_php ) : '';
if ( '' !== $caps_source ) {
	if ( ! str_contains( $caps_source, "const VIEW = 'view_delivery_engine'" ) ) {
		$failures[] = 'Capabilities missing view_delivery_engine.';
	}
	if ( ! str_contains( $caps_source, 'const VERSION = 4' ) ) {
		$failures[] = 'Capabilities VERSION is not 4 in the packaged plugin.';
	}
	if ( ! str_contains( $caps_source, 'administrator_missing_required_capabilities' ) ) {
		$failures[] = 'Capabilities missing administrator_missing_required_capabilities() self-heal probe.';
	}
}

$menu_source = is_readable( $menu_php ) ? (string) file_get_contents( $menu_php ) : '';
if ( '' !== $menu_source ) {
	if ( str_contains( $menu_source, "__( 'Legacy Delivery Rules'" ) ) {
		$failures[] = 'AdminMenu still registers a normal Legacy Delivery Rules submenu.';
	}
	if ( str_contains( $menu_source, "__( 'Technical Diagnostic Tools'" ) ) {
		$failures[] = 'AdminMenu still registers Technical Diagnostic Tools as a normal submenu title.';
	}
	if ( ! str_contains( $menu_source, 'register_hidden_page' ) || ! str_contains( $menu_source, 'HIDDEN_PARENT' ) ) {
		$failures[] = 'AdminMenu missing hidden support-page registration.';
	}
	if ( str_contains( $menu_source, 'ProductDeliveryRulesPage::SLUG' ) ) {
		$failures[] = 'AdminMenu still registers the Legacy product-rules page.';
	}
}

$rate_card_validator_php = $package_root . '/src/Presentation/Admin/Validation/RateCardValidator.php';
if ( ! is_readable( $rate_card_validator_php ) ) {
	$failures[] = 'Missing RateCardValidator.php';
} else {
	$rate_card_validator_source = (string) file_get_contents( $rate_card_validator_php );
	if ( ! str_contains( $rate_card_validator_source, "\$input['effective_from'] ?? null" )
		|| ! str_contains( $rate_card_validator_source, "\$input['effective_to'] ?? null" )
		|| ! str_contains( $rate_card_validator_source, 'function is_supplied_date' )
	) {
		$failures[] = 'RateCardValidator missing optional effective_from/effective_to warning repair.';
	}
}

if ( ! str_contains( $plugin_boot, 'RoleAccessService' ) ) {
	$failures[] = 'Plugin.php does not wire RoleAccessService.';
}
if ( ! str_contains( $plugin_boot, 'AdministratorAccessRecovery' ) ) {
	$failures[] = 'Plugin.php does not wire AdministratorAccessRecovery.';
}

$settings_php = $package_root . '/src/Presentation/Admin/DeliverySettingsPage.php';
if ( is_readable( $settings_php ) ) {
	$settings_source = (string) file_get_contents( $settings_php );
	if ( ! str_contains( $settings_source, "__( 'Access'" ) || ! str_contains( $settings_source, 'role_access->apply' ) ) {
		$failures[] = 'Settings page missing real Access role permissions save.';
	}
}

$system_status_php = $package_root . '/src/Presentation/Admin/SystemStatusPage.php';
if ( is_readable( $system_status_php ) ) {
	$system_status_source = (string) file_get_contents( $system_status_php );
	if ( ! str_contains( $system_status_source, 'Capabilities::DIAGNOSTICS' ) ) {
		$failures[] = 'SystemStatusPage missing view_delivery_diagnostics enforcement.';
	}
}

if ( [] !== $failures ) {
	fwrite( STDERR, "Package verification FAILED:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "Package verification OK\n" );
fwrite( STDOUT, "- ConfigurationHealthChecker autoloads from Application\\Diagnostics\n" );
fwrite( STDOUT, "- Plugin.php import present\n" );
fwrite( STDOUT, "- Boot factory class references resolve\n" );
fwrite( STDOUT, "- Schema target 4; main ECR + variable ECR flags default OFF\n" );
fwrite( STDOUT, "- Stage 6 variation endpoint/router/inspector/assets present\n" );
fwrite( STDOUT, "- Variable frontend JS/CSS present with found_variation/reset_data/requestToken\n" );
fwrite( STDOUT, "- Stage 8 grouping/shipping/order snapshot classes autoload\n" );
fwrite( STDOUT, "- No PHPUnit in production vendor\n" );
fwrite( STDOUT, "- Runtime contracts loaded; VariationRelationshipInspectorInterface present\n" );
fwrite( STDOUT, "- VerifiableMigrationInterface present; schema v3 migration require path OK\n" );
fwrite( STDOUT, "- Stage 13B-R2 compatibility/customize/readiness/admin CSS-JS present\n" );
fwrite( STDOUT, "- Stage 13C-R1 operational state / capability / wizard / preview repairs present\n" );
fwrite( STDOUT, "- Stage 13C-R3 wizard validation / International Air-Sea / Preview variations / variable readiness present\n" );
fwrite( STDOUT, "- Stage 13D RoleAccessService / hidden diagnostics / Legacy menu retirement / RateCard optional dates present\n" );
fwrite( STDOUT, "- Linux-case PSR-4 classmap paths verified\n" );
exit( 0 );
