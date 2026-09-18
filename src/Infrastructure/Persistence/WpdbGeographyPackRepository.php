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
		if ( $id > 0 ) {
			$existing = $this->find_by_id( $id );
			if ( $existing instanceof GeographyPack ) {
				$payload = $this->merge_existing( $existing, $payload );
			}
		}
		$progress = isset( $payload['progress'] ) && is_array( $payload['progress'] ) ? $payload['progress'] : [];
		$encoded  = wp_json_encode( $progress );
		if ( ! is_string( $encoded ) ) {
			$encoded = '{}';
		}
		$target_token = trim( (string) ( $progress['target_token'] ?? $payload['target_token'] ?? '' ) );

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
			'installed_at'      => array_key_exists( 'installed_at', $payload ) ? ( $payload['installed_at'] ?? null ) : null,
			'target_token'      => $target_token,
			'updated_at'        => $now,
		];

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update( $table, $row, [ 'id' => $id ] );
			if ( false === $result ) {
				throw new \RuntimeException( 'Failed to update geography pack ' . $id . '.' );
			}

			return $this->find_by_id( $id ) ?? GeographyPack::fromRow( $row + [ 'id' => $id ] );
		}

		$row['created_at']        = $now;
		$row['lease_owner']       = '';
		$row['lease_role']        = '';
		$row['lease_acquired_at'] = 0;
		$row['lease_expires_at']  = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $table, $row );
		if ( false === $inserted ) {
			throw new \RuntimeException( 'Failed to insert geography pack.' );
		}

		return $this->find_by_id( (int) $wpdb->insert_id ) ?? GeographyPack::fromRow( $row + [ 'id' => (int) $wpdb->insert_id ] );
	}

	public function update_progress(
		int $id,
		GeographyPackStatus $status,
		string $cursor,
		array $progress,
		string $last_error = '',
		?string $installed_at = null,
		string $expected_target_token = ''
	): void {
		global $wpdb;
		$existing = $this->find_by_id( $id );
		if ( $existing instanceof GeographyPack ) {
			$progress = $existing->apply_progress_update( $progress, $status, $installed_at, $expected_target_token );
		} elseif ( '' !== trim( $expected_target_token ) ) {
			throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException(
				'Pack target token fence rejected expected ' . trim( $expected_target_token ) . ' against a missing pack.'
			);
		}
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
		$fence_token = trim( $expected_target_token );
		if ( '' === $fence_token ) {
			$fence_token = trim( (string) ( $progress['target_token'] ?? '' ) );
		}
		if ( '' !== $fence_token ) {
			$data['target_token'] = $fence_token;
		}

		$where = [ 'id' => $id ];
		$current_token = $existing instanceof GeographyPack ? $existing->target_token() : '';
		if ( '' !== $fence_token && '' !== $current_token ) {
			$where['target_token'] = $fence_token;
		}
		if ( GeographyPackStatus::Ready === $status && '' !== $fence_token ) {
			$where['status'] = GeographyPackStatus::Importing->value;
			if ( $existing instanceof GeographyPack && GeographyPackStatus::Pending === $existing->status ) {
				$where['status'] = GeographyPackStatus::Pending->value;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update( $table, $data, $where );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to update geography pack progress for pack ' . $id . '.' );
		}
		if ( 0 === (int) $result && '' !== $fence_token ) {
			$this->throw_fenced_update_failure( $id, $status, $fence_token );
		}
	}

	public function acquire_lease( int $id, string $role, int $now, int $ttl_seconds ): string {
		if ( $id <= 0 ) {
			return '';
		}
		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$owner = $role . ':' . bin2hex( random_bytes( 8 ) );
		$ttl   = max( 1, $ttl_seconds );
		$sql   = "UPDATE `{$table}` SET `lease_owner` = %s, `lease_role` = %s, `lease_acquired_at` = %d, `lease_expires_at` = %d, `updated_at` = %s WHERE `id` = %d AND (`lease_owner` = %s OR `lease_expires_at` < %d)";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$owner,
				$role,
				$now,
				$now + $ttl,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				'',
				$now
			)
		);
		if ( false === $affected ) {
			throw new \RuntimeException( 'Failed to acquire geography pack lease for pack ' . $id . '.' );
		}

		return (int) $affected > 0 ? $owner : '';
	}

	public function renew_lease( int $id, string $owner, int $now, int $ttl_seconds ): bool {
		if ( $id <= 0 || '' === $owner ) {
			return false;
		}
		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$sql   = "UPDATE `{$table}` SET `lease_expires_at` = %d, `updated_at` = %s WHERE `id` = %d AND `lease_owner` = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				$sql,
				$now + max( 1, $ttl_seconds ),
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				$owner
			)
		);
		if ( false === $affected ) {
			throw new \RuntimeException( 'Failed to renew geography pack lease for pack ' . $id . '.' );
		}

		return (int) $affected > 0;
	}

	public function release_lease( int $id, string $owner ): bool {
		if ( $id <= 0 || '' === $owner ) {
			return false;
		}
		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$sql   = "UPDATE `{$table}` SET `lease_owner` = %s, `lease_role` = %s, `lease_acquired_at` = %d, `lease_expires_at` = %d, `updated_at` = %s WHERE `id` = %d AND `lease_owner` = %s";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$affected = $wpdb->query(
			$wpdb->prepare(
				$sql,
				'',
				'',
				0,
				0,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				$owner
			)
		);
		if ( false === $affected ) {
			throw new \RuntimeException( 'Failed to release geography pack lease for pack ' . $id . '.' );
		}

		return (int) $affected > 0;
	}

	public function current_lease( int $id ): array {
		if ( $id <= 0 ) {
			return [
				'owner'       => '',
				'role'        => '',
				'acquired_at' => 0,
				'expires_at'  => 0,
			];
		}
		global $wpdb;
		$table = TableNames::for( GeographySchema::PACKS_SUFFIX );
		$sql   = "SELECT * FROM `{$table}` WHERE `id` = %d LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return [
				'owner'       => '',
				'role'        => '',
				'acquired_at' => 0,
				'expires_at'  => 0,
			];
		}

		return [
			'owner'       => (string) ( $row['lease_owner'] ?? '' ),
			'role'        => (string) ( $row['lease_role'] ?? '' ),
			'acquired_at' => (int) ( $row['lease_acquired_at'] ?? 0 ),
			'expires_at'  => (int) ( $row['lease_expires_at'] ?? 0 ),
		];
	}

	private function throw_fenced_update_failure( int $id, GeographyPackStatus $status, string $expected_token ): void {
		$fresh = $this->find_by_id( $id );
		if ( ! $fresh instanceof GeographyPack || $fresh->target_token() !== $expected_token ) {
			throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException(
				'Pack target token fence rejected expected ' . $expected_token . ' against current ' . ( $fresh instanceof GeographyPack ? $fresh->target_token() : '' ) . '.'
			);
		}
		if ( GeographyPackStatus::Ready === $status ) {
			throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackConcurrentPromotionException(
				'Pack Ready update affected zero rows for token ' . $expected_token . '.'
			);
		}
		throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException(
			'Pack target token fence rejected expected ' . $expected_token . ' against current ' . $fresh->target_token() . '.'
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @return array<string, mixed>
	 */
	private function merge_existing( GeographyPack $existing, array $payload ): array {
		$merged = [
			'id'               => $existing->id,
			'country_code'     => $existing->country_code,
			'provider'         => $existing->provider->value,
			'dataset_name'     => $existing->dataset_name,
			'dataset_version'  => $existing->dataset_version,
			'source_url'       => $existing->source_url,
			'source_reference' => $existing->source_reference,
			'checksum'         => $existing->checksum,
			'license_name'     => $existing->license_name,
			'license_url'      => $existing->license_url,
			'attribution_text' => $existing->attribution_text,
			'status'           => $existing->status->value,
			'import_cursor'    => $existing->import_cursor,
			'progress'         => $existing->progress,
			'last_error'       => $existing->last_error,
			'installed_at'     => $existing->installed_at,
		];
		foreach ( $payload as $key => $value ) {
			if ( 'progress' === $key && is_array( $value ) ) {
				$progress = $existing->progress;
				foreach ( $value as $progress_key => $progress_value ) {
					$progress[ $progress_key ] = $progress_value;
				}
				if ( ! array_key_exists( 'last_successful', $value ) && isset( $existing->progress['last_successful'] ) ) {
					$progress['last_successful'] = $existing->progress['last_successful'];
				}
				$merged['progress'] = $progress;
				continue;
			}
			$merged[ $key ] = $value;
		}
		if ( ! array_key_exists( 'installed_at', $payload ) ) {
			$merged['installed_at'] = $existing->installed_at;
		}

		return $merged;
	}
}
