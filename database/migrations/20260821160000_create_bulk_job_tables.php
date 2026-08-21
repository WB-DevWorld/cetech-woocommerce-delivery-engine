<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\BulkJobSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

/**
 * Schema v5: bulk job engine persistence.
 *
 * Forward-only, idempotent via dbDelta. Does not alter schema 4 shipment
 * or configuration tables. Does not rewrite historical orders.
 */
return new class implements VerifiableMigrationInterface {

	public function get_id(): string {
		return '20260821160000_create_bulk_job_tables';
	}

	public function get_version(): string {
		return '5';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( BulkJobSchema::create_table_statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		foreach ( BulkJobSchema::SUFFIXES as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Bulk job table missing after dbDelta: %s', $suffix )
				);
			}
		}
	}

	public function verify(): void {
		foreach ( BulkJobSchema::SUFFIXES as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Schema v5 verification failed: missing table suffix %s', $suffix )
				);
			}
		}

		$this->assert_index_present( BulkJobSchema::JOBS_SUFFIX, 'job_uuid' );
		$this->assert_index_present( BulkJobSchema::JOBS_SUFFIX, 'job_code' );
		$this->assert_index_present( BulkJobSchema::JOBS_SUFFIX, 'status' );
		$this->assert_index_present( BulkJobSchema::JOBS_SUFFIX, 'status_updated' );
		$this->assert_index_present( BulkJobSchema::ITEMS_SUFFIX, 'job_target' );
		$this->assert_index_present( BulkJobSchema::ITEMS_SUFFIX, 'job_status_id' );
		$this->assert_index_present( BulkJobSchema::RECIPES_SUFFIX, 'recipe_code' );
	}

	private function assert_index_present( string $suffix, string $index_name ): void {
		global $wpdb;

		$table = TableNames::for( $suffix );
		$sql   = "SHOW INDEX FROM `{$table}` WHERE Key_name = %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $index_name ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			throw new RuntimeException(
				sprintf( 'Schema v5 verification failed: missing index %s on %s.', $index_name, $suffix )
			);
		}
	}
};
