<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Versioning\MigrationDiscovery;
use CetechDeliveryEngine\Core\Versioning\MigrationRunner;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Integrations\WCFM\WcfmVendorIsolation;
use CetechDeliveryEngine\Support\Logger;

/**
 * Plugin activation handler.
 */
final class Activator {

	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( plugin_basename( CETECH_DE_FILE ) );

			wp_die(
				esc_html__(
					'CETECH WooCommerce Delivery Engine requires PHP 8.1 or higher.',
					'cetech-woocommerce-delivery-engine'
				),
				esc_html__( 'Plugin Activation Error', 'cetech-woocommerce-delivery-engine' ),
				[ 'back_link' => true ]
			);
		}

		$capabilities = new Capabilities();
		$capabilities->register();
		( new WcfmVendorIsolation() )->harden_vendor_role_capabilities();

		$feature_flags = new FeatureFlags();
		$feature_flags->ensure_defaults();

		SchemaVersion::ensure_initialized();

		$migration_runner = new MigrationRunner( new Logger() );
		$migration_runner->set_migrations(
			MigrationDiscovery::discover(
				CETECH_DE_PATH . 'database/migrations',
				new Logger()
			)
		);
		$migration_runner->run();

		set_transient( 'cetech_de_activation_notice', 1, MINUTE_IN_SECONDS );

		flush_rewrite_rules();
	}
}
