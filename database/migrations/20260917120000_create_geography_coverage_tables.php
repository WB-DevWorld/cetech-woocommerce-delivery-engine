<?php

declare(strict_types=1);

use CetechDeliveryEngine\Core\Versioning\VerifiableMigrationInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\ConfigurationTables;
use CetechDeliveryEngine\Infrastructure\Persistence\CoverageSchema;
use CetechDeliveryEngine\Infrastructure\Persistence\GeographySchema;
use CetechDeliveryEngine\Infrastructure\Persistence\TableNames;

/**
 * Schema v6: canonical geography packs/locations and Delivery Area coverage groups.
 *
 * Additive and idempotent via dbDelta. Does not drop destination_rules.
 * Does not rewrite historical order snapshots. Coverage conversion of
 * existing destination_rules is a separate verifiable runtime step.
 */
return new class implements VerifiableMigrationInterface {

	public function get_id(): string {
		return '20260917120000_create_geography_coverage_tables';
	}

	public function get_version(): string {
		return '6';
	}

	public function up(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( GeographySchema::create_table_statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		foreach ( CoverageSchema::create_table_statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}

		foreach ( array_merge( GeographySchema::SUFFIXES, CoverageSchema::SUFFIXES ) as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Geography/coverage table missing after dbDelta: %s', $suffix )
				);
			}
		}

		if ( ! ConfigurationTables::exists( 'destination_rules' ) ) {
			throw new RuntimeException( 'Schema v6 must preserve destination_rules.' );
		}
	}

	public function verify(): void {
		foreach ( array_merge( GeographySchema::SUFFIXES, CoverageSchema::SUFFIXES ) as $suffix ) {
			if ( ! ConfigurationTables::exists( $suffix ) ) {
				throw new RuntimeException(
					sprintf( 'Schema v6 verification failed: missing table suffix %s', $suffix )
				);
			}
		}

		if ( ! ConfigurationTables::exists( 'destination_rules' ) ) {
			throw new RuntimeException( 'Schema v6 verification failed: destination_rules was dropped.' );
		}

		if ( ! ConfigurationTables::exists( 'destination_zones' ) ) {
			throw new RuntimeException( 'Schema v6 verification failed: destination_zones missing.' );
		}

		$this->assert_index_present( GeographySchema::PACKS_SUFFIX, 'country_provider_dataset' );
		$this->assert_index_present( GeographySchema::PACKS_SUFFIX, 'target_token' );
		$this->assert_index_present( GeographySchema::PACKS_SUFFIX, 'lease_owner' );
		$this->assert_index_present( GeographySchema::LOCATIONS_SUFFIX, 'location_key' );
		$this->assert_index_present( GeographySchema::LOCATIONS_SUFFIX, 'country_parent_type_status' );
		$this->assert_index_present( GeographySchema::LOCATIONS_SUFFIX, 'country_normalized' );
		$this->assert_index_present( GeographySchema::LOCATIONS_SUFFIX, 'draft_generation_token' );
		$this->assert_index_present( GeographySchema::LOCATIONS_SUFFIX, 'generation_token' );
		$this->assert_index_present( GeographySchema::LOCATIONS_SUFFIX, 'prepared_generation_token' );
		$this->assert_index_present( GeographySchema::LOCATIONS_SUFFIX, 'prepared_hierarchy_root_id' );
		$this->assert_index_present( GeographySchema::ALIASES_SUFFIX, 'location_normalized_generation' );
		$this->assert_index_present( GeographySchema::ALIASES_SUFFIX, 'generation_token' );
		$this->assert_index_present( GeographySchema::MAPPINGS_SUFFIX, 'provider_external_generation' );
		$this->assert_index_present( CoverageSchema::GROUPS_SUFFIX, 'zone_status_order' );
		$this->assert_index_present( CoverageSchema::MEMBERS_SUFFIX, 'group_location_membership' );
		$this->assert_index_present( CoverageSchema::POSTCODES_SUFFIX, 'group_postcode_mode' );

		$this->assert_column_present( GeographySchema::PACKS_SUFFIX, 'target_token' );
		$this->assert_column_present( GeographySchema::PACKS_SUFFIX, 'lease_owner' );
		$this->assert_column_present( GeographySchema::PACKS_SUFFIX, 'lease_role' );
		$this->assert_column_present( GeographySchema::PACKS_SUFFIX, 'lease_acquired_at' );
		$this->assert_column_present( GeographySchema::PACKS_SUFFIX, 'lease_expires_at' );
		$this->assert_column_present( GeographySchema::LOCATIONS_SUFFIX, 'generation_token' );
		$this->assert_column_present( GeographySchema::LOCATIONS_SUFFIX, 'draft_generation_token' );
		$this->assert_column_present( GeographySchema::LOCATIONS_SUFFIX, 'prepared_generation_token' );
		$this->assert_column_present( GeographySchema::LOCATIONS_SUFFIX, 'prepared_ancestry_path' );
		$this->assert_column_present( GeographySchema::LOCATIONS_SUFFIX, 'prepared_hierarchy_root_id' );
		$this->assert_column_present( GeographySchema::ALIASES_SUFFIX, 'generation_token' );
		$this->assert_column_present( GeographySchema::MAPPINGS_SUFFIX, 'generation_token' );
	}

	private function assert_column_present( string $suffix, string $column ): void {
		global $wpdb;

		$table = TableNames::for( $suffix );
		$sql   = "SHOW COLUMNS FROM `{$table}` LIKE %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $column ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			throw new RuntimeException(
				sprintf( 'Schema v6 verification failed: missing column %s on %s.', $column, $suffix )
			);
		}
	}

	private function assert_index_present( string $suffix, string $index_name ): void {
		global $wpdb;

		$table = TableNames::for( $suffix );
		$sql   = "SHOW INDEX FROM `{$table}` WHERE Key_name = %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $index_name ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			throw new RuntimeException(
				sprintf( 'Schema v6 verification failed: missing index %s on %s.', $index_name, $suffix )
			);
		}
	}
};
