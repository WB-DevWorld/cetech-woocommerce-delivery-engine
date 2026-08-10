<?php

declare(strict_types=1);

use CetechDeliveryEngine\Application\Configuration\LegacyConfigurationMigrator;
use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Domain\Configuration\LegacyProductRuleMigrationMapper;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\ScopedConfigurationSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbProductDeliveryRuleRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\WpdbScopedConfigurationRepository;
use CetechDeliveryEngine\Support\Logger;

/**
 * Schema v3: scoped configuration storage + legacy RC backfill.
 *
 * Non-destructive to product_delivery_rules. Runtime path unchanged.
 */
return new class implements VerifiableMigrationInterface {

	public function get_id(): string {
		return '20260810160000_create_scoped_configuration_tables';
	}

	public function get_version(): string {
		return '3';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( ScopedConfigurationSchema::create_table_statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		foreach ( ScopedConfigurationSchema::SUFFIXES as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Scoped configuration table missing after dbDelta: %s', $suffix )
				);
			}
		}

		$repository = new WpdbScopedConfigurationRepository();
		$repository->ensureGlobalScope();

		$migrator = new LegacyConfigurationMigrator(
			new WpdbProductDeliveryRuleRepository(),
			$repository,
			new LegacyProductRuleMigrationMapper(),
			new Logger()
		);

		$migrator->migrate();
	}

	public function verify(): void {
		foreach ( ScopedConfigurationSchema::SUFFIXES as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Schema v3 verification failed: missing table suffix %s', $suffix )
				);
			}
		}

		if ( ! ConfigurationTables::exists( 'product_delivery_rules' ) ) {
			throw new RuntimeException(
				'Schema v3 verification failed: legacy product_delivery_rules table is missing.'
			);
		}

		$repository = new WpdbScopedConfigurationRepository();
		$global     = $repository->getGlobalConfiguration();

		if ( null === $global ) {
			throw new RuntimeException( 'Schema v3 verification failed: global configuration scope missing.' );
		}

		$forbidden = [ 'shipments', 'shipment_items', 'shipment_events' ];

		foreach ( $forbidden as $suffix ) {
			if ( ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Schema v3 verification failed: unexpected shipment table created (%s).', $suffix )
				);
			}
		}
	}
};
