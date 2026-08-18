<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\ShipmentSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

/**
 * Schema v4: shipment persistence foundation.
 *
 * Creates shipment / shipment_item / shipment_event tables only.
 * Does not create shipments, mutate RC.4 order snapshots, or alter checkout.
 */
return new class implements VerifiableMigrationInterface {

	public function get_id(): string {
		return '20260818140000_create_shipment_tables';
	}

	public function get_version(): string {
		return '4';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( ShipmentSchema::create_table_statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		foreach ( ShipmentSchema::SUFFIXES as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Shipment table missing after dbDelta: %s', $suffix )
				);
			}
		}
	}

	public function verify(): void {
		foreach ( ShipmentSchema::SUFFIXES as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Schema v4 verification failed: missing table suffix %s', $suffix )
				);
			}
		}

		$this->assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'idempotency_key' );
		$this->assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'order_group' );
		$this->assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'order_id' );
		$this->assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'status' );
		$this->assert_index_present( ShipmentSchema::SHIPMENTS_SUFFIX, 'status_updated' );
		$this->assert_index_present( ShipmentSchema::ITEMS_SUFFIX, 'shipment_item' );
		$this->assert_index_present( ShipmentSchema::ITEMS_SUFFIX, 'order_item_id' );
		$this->assert_index_present( ShipmentSchema::EVENTS_SUFFIX, 'shipment_time' );
	}

	private function assert_index_present( string $suffix, string $index_name ): void {
		global $wpdb;

		$table = TableNames::for( $suffix );
		$sql   = "SHOW INDEX FROM `{$table}` WHERE Key_name = %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $index_name ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			throw new RuntimeException(
				sprintf( 'Schema v4 verification failed: missing index %s on %s.', $index_name, $suffix )
			);
		}
	}
};
