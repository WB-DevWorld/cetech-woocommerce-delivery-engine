<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Bootstrap;

use CetechDeliveryEngine\Core\Capabilities\Capabilities;
use CetechDeliveryEngine\Core\Versioning\MigrationStatus;
use CetechDeliveryEngine\Core\Versioning\SchemaVersion;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;

/**
 * Removes plugin options, capabilities, and plugin tables when delete-data uninstall is enabled.
 *
 * Shipment tables are dropped only on this explicit uninstall path, never on deactivation.
 */
final class Uninstaller {

	public const DELETE_DATA_OPTION = 'cetech_de_delete_data_on_uninstall';

	public static function uninstall(): void {
		$delete_data = (bool) (int) get_option( self::DELETE_DATA_OPTION, 0 );

		if ( ! $delete_data ) {
			return;
		}

		self::drop_configuration_tables();
		self::remove_capabilities();
		self::remove_options();
	}

	private static function drop_configuration_tables(): void {
		global $wpdb;

		foreach ( ConfigurationTables::all() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	private static function remove_capabilities(): void {
		$capabilities = new Capabilities();
		$capabilities->unregister();
	}

	private static function remove_options(): void {
		$feature_flags = new FeatureFlags();

		foreach ( array_keys( $feature_flags->defaults() ) as $flag ) {
			delete_option( $feature_flags->option_name( $flag ) );
		}

		delete_option( SchemaVersion::OPTION_NAME );
		delete_option( MigrationStatus::OPTION_NAME );
		delete_option( self::DELETE_DATA_OPTION );
		delete_option( \CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings::OPTION_NAME );
		delete_option( \CetechDeliveryEngine\Application\Configuration\SetupWizardProgress::OPTION_NAME );
		delete_option( \CetechDeliveryEngine\Application\Shipment\ShipmentCreationFailureStore::INDEX_OPTION );
		delete_option( \CetechDeliveryEngine\Application\Shipment\CodAwaitingShipmentStore::INDEX_OPTION );
		delete_option( \CetechDeliveryEngine\Application\Geography\Schema6CoverageUpgradeService::OPTION_KEY );
		delete_option( \CetechDeliveryEngine\Application\Geography\CountryIdentityReconciler::OPTION_KEY );
		delete_option( \CetechDeliveryEngine\Application\Geography\CountryIdentityReconciler::LOCK_OPTION_KEY );
		delete_option( 'cetech_de_country_identity_repair' );
	}
}
