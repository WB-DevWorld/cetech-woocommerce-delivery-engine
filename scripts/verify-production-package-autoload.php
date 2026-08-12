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
];

$required_interfaces = [
	'CetechDeliveryEngine\\Application\\Runtime\\VariationRelationshipInspectorInterface',
	'CetechDeliveryEngine\\Core\\Versioning\\VerifiableMigrationInterface',
	'CetechDeliveryEngine\\Core\\Versioning\\MigrationInterface',
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

if ( class_exists( 'CetechDeliveryEngine\\Core\\Versioning\\SchemaVersion' ) ) {
	$target = ( new ReflectionClass( 'CetechDeliveryEngine\\Core\\Versioning\\SchemaVersion' ) )->getConstant( 'TARGET' );
	if ( '3' !== $target ) {
		$failures[] = 'SchemaVersion::TARGET must be 3 for this package.';
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

fwrite( STDOUT, "Package verification OK\n" );
fwrite( STDOUT, "- ConfigurationHealthChecker autoloads from Application\\Diagnostics\n" );
fwrite( STDOUT, "- Plugin.php import present\n" );
fwrite( STDOUT, "- Boot factory class references resolve\n" );
fwrite( STDOUT, "- Schema target 3; main ECR + variable ECR flags default OFF\n" );
fwrite( STDOUT, "- Stage 6 variation endpoint/router/inspector/assets present\n" );
fwrite( STDOUT, "- Variable frontend JS/CSS present with found_variation/reset_data/requestToken\n" );
fwrite( STDOUT, "- No PHPUnit in production vendor\n" );
fwrite( STDOUT, "- Runtime contracts loaded; VariationRelationshipInspectorInterface present\n" );
fwrite( STDOUT, "- VerifiableMigrationInterface present; schema v3 migration require path OK\n" );
fwrite( STDOUT, "- Linux-case PSR-4 classmap paths verified\n" );
exit( 0 );
