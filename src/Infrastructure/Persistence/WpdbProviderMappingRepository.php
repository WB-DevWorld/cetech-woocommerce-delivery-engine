<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\ProviderMappingRepositoryInterface;

final class WpdbProviderMappingRepository implements ProviderMappingRepositoryInterface {

	public function find_location_id( GeographyProvider $provider, string $external_id, string $target_token = '' ): ?int {
		$row = $this->find_mapping( $provider, $external_id, '' );
		if ( ! is_array( $row ) && '' !== $target_token ) {
			$row = $this->find_mapping( $provider, $external_id, $target_token );
		}

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
		$sql   = "SELECT external_id FROM `{$table}` WHERE location_id = %d AND provider = %s AND generation_token = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( $wpdb->prepare( $sql, $location_id, $provider->value, '' ) );

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
		array $metadata = [],
		string $generation_token = ''
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

		$sql = "SELECT * FROM `{$table}` WHERE provider = %s AND external_id = %s AND generation_token = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row( $wpdb->prepare( $sql, $provider->value, $external_id, $generation_token ), ARRAY_A );
		$row      = [
			'location_id'               => $location_id,
			'provider'                  => $provider->value,
			'external_id'               => $external_id,
			'pack_id'                   => $pack_id,
			'dataset_version'           => $dataset_version,
			'provider_parent_reference' => $provider_parent_reference,
			'feature_class'             => $feature_class,
			'feature_code'              => $feature_code,
			'provider_metadata_json'    => $encoded,
			'generation_token'          => $generation_token,
			'updated_at'                => $now,
		];
		if ( is_array( $existing ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $row, [ 'id' => (int) ( $existing['id'] ?? 0 ) ] );
		} else {
			$row['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->insert( $table, $row );
		}
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to write provider mapping for ' . $external_id . '.' );
		}
	}

	public function find_mapping( GeographyProvider $provider, string $external_id, string $generation_token = '' ): ?array {
		$external_id = trim( $external_id );
		if ( '' === $external_id ) {
			return null;
		}

		global $wpdb;
		$table = TableNames::for( GeographySchema::MAPPINGS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE provider = %s AND external_id = %s AND generation_token = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $provider->value, $external_id, $generation_token ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}
}
