<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;
use CetechDeliveryEngine\Domain\Geography\GeographyPack;
use CetechDeliveryEngine\Domain\Geography\GeographyPackRepositoryInterface;

final class InMemoryGeographyPackRepository implements GeographyPackRepositoryInterface {

	/** @var array<int, GeographyPack> */
	private array $packs = [];

	private int $next_id = 1;

	public bool $fail_next_progress = false;

	public bool $fail_next_ready_progress = false;

	/** @var array<int, array{owner: string, role: string, acquired_at: int, expires_at: int}> */
	private array $leases = [];

	/** @var array<string, bool> */
	private array $finalize_blocked = [];

	public function find_by_id( int $id ): ?GeographyPack {
		return $this->packs[ $id ] ?? null;
	}

	public function find_by_country_provider( string $country_code, GeographyProvider $provider, string $dataset_name = 'gazetteer' ): ?GeographyPack {
		foreach ( $this->packs as $pack ) {
			if ( $pack->country_code === strtoupper( $country_code ) && $pack->provider === $provider && $pack->dataset_name === $dataset_name ) {
				return $pack;
			}
		}

		return null;
	}

	public function list_all(): array {
		return array_values( $this->packs );
	}

	public function save( array $payload ): GeographyPack {
		$id = (int) ( $payload['id'] ?? 0 );
		if ( $id > 0 && isset( $this->packs[ $id ] ) ) {
			$payload = $this->merge_existing( $this->packs[ $id ], $payload );
		}
		if ( $id <= 0 ) {
			$id = $this->next_id++;
		}
		$pack = GeographyPack::fromRow( $payload + [ 'id' => $id ] );
		$this->packs[ $id ] = $pack;

		return $pack;
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
		if ( $this->fail_next_progress || ( $this->fail_next_ready_progress && GeographyPackStatus::Ready === $status ) ) {
			$this->fail_next_progress       = false;
			$this->fail_next_ready_progress = false;
			throw new \RuntimeException( 'Simulated pack progress failure.' );
		}
		$existing = $this->packs[ $id ] ?? null;
		if ( ! $existing instanceof GeographyPack ) {
			if ( '' !== trim( $expected_target_token ) ) {
				throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException(
					'Pack target token fence rejected expected ' . trim( $expected_target_token ) . ' against a missing pack.'
				);
			}

			return;
		}
		$fence_token = trim( $expected_target_token );
		if ( '' === $fence_token ) {
			$fence_token = trim( (string) ( $progress['target_token'] ?? '' ) );
		}
		if ( '' !== $fence_token && '' !== $existing->target_token() && $existing->target_token() !== $fence_token ) {
			throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackTokenFenceException(
				'Pack target token fence rejected expected ' . $fence_token . ' against current ' . $existing->target_token() . '.'
			);
		}
		if ( GeographyPackStatus::Ready === $status && '' !== $fence_token ) {
			if ( GeographyPackStatus::Ready === $existing->status ) {
				throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackConcurrentPromotionException(
					'Pack Ready update affected zero rows for token ' . $fence_token . '.'
				);
			}
			if ( GeographyPackStatus::Importing !== $existing->status && GeographyPackStatus::Pending !== $existing->status ) {
				throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackConcurrentPromotionException(
					'Pack Ready update affected zero rows for token ' . $fence_token . '.'
				);
			}
			if ( isset( $this->finalize_blocked[ $id . ':' . $fence_token ] ) ) {
				throw new \CetechDeliveryEngine\Domain\Geography\GeographyPackConcurrentPromotionException(
					'Pack Ready update affected zero rows for token ' . $fence_token . '.'
				);
			}
			$this->finalize_blocked[ $id . ':' . $fence_token ] = true;
		}
		$progress = $existing->apply_progress_update( $progress, $status, $installed_at, $expected_target_token );
		$this->packs[ $id ] = new GeographyPack(
			$existing->id,
			$existing->country_code,
			$existing->provider,
			$existing->dataset_name,
			$existing->dataset_version,
			$existing->source_url,
			$existing->source_reference,
			$existing->checksum,
			$existing->license_name,
			$existing->license_url,
			$existing->attribution_text,
			$status,
			$cursor,
			$progress,
			$last_error,
			$installed_at ?? $existing->installed_at
		);
	}

	public function acquire_lease( int $id, string $role, int $now, int $ttl_seconds ): string {
		$existing = $this->leases[ $id ] ?? [
			'owner'       => '',
			'role'        => '',
			'acquired_at' => 0,
			'expires_at'  => 0,
		];
		if ( '' !== $existing['owner'] && $existing['expires_at'] >= $now ) {
			return '';
		}
		$owner = $role . ':' . bin2hex( random_bytes( 8 ) );
		$this->leases[ $id ] = [
			'owner'       => $owner,
			'role'        => $role,
			'acquired_at' => $now,
			'expires_at'  => $now + max( 1, $ttl_seconds ),
		];

		return $owner;
	}

	public function renew_lease( int $id, string $owner, int $now, int $ttl_seconds ): bool {
		if ( '' === $owner ) {
			return false;
		}
		$existing = $this->leases[ $id ] ?? null;
		if ( ! is_array( $existing ) || $existing['owner'] !== $owner ) {
			return false;
		}
		$existing['expires_at'] = $now + max( 1, $ttl_seconds );
		$this->leases[ $id ]    = $existing;

		return true;
	}

	public function release_lease( int $id, string $owner ): bool {
		if ( '' === $owner ) {
			return false;
		}
		$existing = $this->leases[ $id ] ?? null;
		if ( ! is_array( $existing ) || $existing['owner'] !== $owner ) {
			return false;
		}
		unset( $this->leases[ $id ] );

		return true;
	}

	public function current_lease( int $id ): array {
		return $this->leases[ $id ] ?? [
			'owner'       => '',
			'role'        => '',
			'acquired_at' => 0,
			'expires_at'  => 0,
		];
	}

	public function expire_lease( int $id, int $expired_at ): void {
		if ( ! isset( $this->leases[ $id ] ) ) {
			return;
		}
		$this->leases[ $id ]['expires_at'] = $expired_at;
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
