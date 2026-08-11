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
];

$required_interfaces = [
	'CetechDeliveryEngine\\Application\\Runtime\\VariationRelationshipInspectorInterface',
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
exit( 0 );
