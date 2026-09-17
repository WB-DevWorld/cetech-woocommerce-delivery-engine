<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface;

final class WpdbProviderMappingRepository implements ProviderMappingRepositoryInterface {

	public function find_location_id( GeographyProvider $provider, string $external_id ): ?int {
		$row = $this->find_mapping( $provider, $external_id );

		if ( ! is_array( $row ) ) {
			return null;
		}

		$id = (int) ( $row['location_id'] ?? 0 );

		return $id > 0 ? $id : null;
	}

	public function find_external_id( int $location_id, GeographyProvider $provider ): ?string {
		if ( $location_id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$sql   = "SELECT external_id FROM `{$table}` WHERE location_id = %d AND provider = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( $sql, $location_id, $provider->value ) );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	public function upsert(
		int $location_id,
		GeographyProvider $provider,
		string $external_id,
		?int $pack_id,
		string $dataset_version,
		string $provider_parent_reference = '',
		string $feature_class = '',
		string $feature_code = '',
		array $metadata = []
	): void {
		if ( $location_id <= 0 || '' === $external_id ) {
			return;
		}

		global $wpdb;
		$table   = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$now     = gmdate( 'Y-m-d H:i:s' );
		$encoded = wp_json_encode( $metadata );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$table}` (location_id, provider, external_id, pack_id, dataset_version, provider_parent_reference, feature_class, feature_code, provider_metadata_json, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE location_id = VALUES(location_id), pack_id = VALUES(pack_id), dataset_version = VALUES(dataset_version), provider_parent_reference = VALUES(provider_parent_reference), feature_class = VALUES(feature_class), feature_code = VALUES(feature_code), provider_metadata_json = VALUES(provider_metadata_json), updated_at = VALUES(updated_at)",
				$location_id,
				$provider->value,
				$external_id,
				null === $pack_id ? null : $pack_id,
				$dataset_version,
				$provider_parent_reference,
				$feature_class,
				$feature_code,
				$encoded,
				$now,
				$now
			)
		);
	}

	public function find_mapping( GeographyProvider $provider, string $external_id ): ?array {
		$external_id = trim( $external_id );
		if ( '' === $external_id ) {
			return null;
		}

		global $wpdb;
		$table = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE provider = %s AND external_id = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $provider->value, $external_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
