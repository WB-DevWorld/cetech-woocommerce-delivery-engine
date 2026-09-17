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
		?string $installed_at = null
	): void {
		if ( $this->fail_next_progress || ( $this->fail_next_ready_progress && GeographyPackStatus::Ready === $status ) ) {
			$this->fail_next_progress       = false;
			$this->fail_next_ready_progress = false;
			throw new \RuntimeException( 'Simulated pack progress failure.' );
		}
		$existing = $this->packs[ $id ] ?? null;
		if ( ! $existing instanceof GeographyPack ) {
			return;
		}
		if ( ! array_key_exists( 'last_successful', $progress ) && isset( $existing->progress['last_successful'] ) ) {
			$progress['last_successful'] = $existing->progress['last_successful'];
		}
		if ( GeographyPackStatus::Ready === $status ) {
			$progress['last_successful'] = [
				'checksum'         => '' !== (string) ( $progress['dataset_checksum'] ?? '' ) ? (string) $progress['dataset_checksum'] : $existing->checksum,
				'dataset_version'  => $existing->dataset_version,
				'source_reference' => $existing->source_reference,
				'installed_at'     => $installed_at ?? $existing->installed_at,
			];
		}
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
