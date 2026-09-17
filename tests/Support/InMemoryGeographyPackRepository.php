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
		$existing = $this->packs[ $id ] ?? null;
		if ( ! $existing instanceof GeographyPack ) {
			return;
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
}
