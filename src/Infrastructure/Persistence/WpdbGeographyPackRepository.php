<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\Persistence;

use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\GeographyPack;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;

final class WpdbGeographyPackRepository implements GeographyPackRepositoryInterface {

	public function find_by_id( int $id ): ?GeographyPack {
		if ( $id <= 0 ) {
			return null;
		}

		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );

		return is_array( $row ) ? GeographyPack::fromRow( $row ) : null;
	}

	public function find_by_country_provider( string $country_code, GeographyProvider $provider, string $dataset_name = 'gazetteer' ): ?GeographyPack {
		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE country_code = %s AND provider = %s AND dataset_name = %s LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( $sql, strtoupper( $country_code ), $provider->value, $dataset_name ),
			ARRAY_A
		);

		return is_array( $row ) ? GeographyPack::fromRow( $row ) : null;
	}

	public function list_all(): array {
		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` ORDER BY country_code ASC, provider ASC";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$out[] = GeographyPack::fromRow( $row );
		}

		return $out;
	}

	public function save( array $payload ): GeographyPack {
		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$id    = (int) ( $payload['id'] ?? 0 );
		$progress = isset( $payload['progress'] ) && is_array( $payload['progress'] ) ? $payload['progress'] : [];
		$encoded  = wp_json_encode( $progress );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}

		$row = [
			'country_code'      => strtoupper( (string) ( $payload['country_code'] ?? '' ) ),
			'provider'          => (string) ( $payload['provider'] ?? GeographyProvider::GeoNames->value ),
			'dataset_name'      => (string) ( $payload['dataset_name'] ?? 'gazetteer' ),
			'dataset_version'   => (string) ( $payload['dataset_version'] ?? '' ),
			'source_url'        => (string) ( $payload['source_url'] ?? '' ),
			'source_reference'  => (string) ( $payload['source_reference'] ?? '' ),
			'checksum'          => (string) ( $payload['checksum'] ?? '' ),
			'license_name'      => (string) ( $payload['license_name'] ?? '' ),
			'license_url'       => (string) ( $payload['license_url'] ?? '' ),
			'attribution_text'  => (string) ( $payload['attribution_text'] ?? '' ),
			'status'            => (string) ( $payload['status'] ?? GeographyPackStatus::Pending->value ),
			'import_cursor'     => (string) ( $payload['import_cursor'] ?? '0' ),
			'progress_json'     => $encoded,
			'last_error'        => (string) ( $payload['last_error'] ?? '' ),
			'installed_at'      => $payload['installed_at'] ?? null,
			'updated_at'        => $now,
		];

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $row, [ 'id' => $id ] );

			return $this->find_by_id( $id ) ?? GeographyPack::fromRow( $row + [ 'id' => $id ] );
		}

		$row['created_at'] = $now;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $table, $row );

		return $this->find_by_id( (int) $wpdb->insert_id ) ?? GeographyPack::fromRow( $row + [ 'id' => (int) $wpdb->insert_id ] );
	}

	public function update_progress(
		int $id,
		GeographyPackStatus $status,
		string $cursor,
		array $progress,
		string $last_error = '',
		?string $installed_at = null
	): void {
		global $wpdb;
		$table   = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$encoded = wp_json_encode( $progress );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}

		$data = [
			'status'        => $status->value,
			'import_cursor' => $cursor,
			'progress_json' => $encoded,
			'last_error'    => $last_error,
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		];
		if ( null !== $installed_at ) {
			$data['installed_at'] = $installed_at;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, $data, [ 'id' => $id ] );
	}
}
