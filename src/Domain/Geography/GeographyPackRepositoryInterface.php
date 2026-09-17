<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyPackStatus;
use CetechDeliveryEngine\Domain\Enum\GeographyProvider;

interface GeographyPackRepositoryInterface {

	public function find_by_id( int $id ): ?GeographyPack;

	public function find_by_country_provider( string $country_code, GeographyProvider $provider, string $dataset_name = 'gazetteer' ): ?GeographyPack;

	/**
	 * @return list<GeographyPack>
	 */
	public function list_all(): array;

	/**
	 * @param array<string, mixed> $payload
	 */
	public function save( array $payload ): GeographyPack;

	public function update_progress(
		int $id,
		GeographyPackStatus $status,
		string $cursor,
		array $progress,
		string $last_error = '',
		?string $installed_at = null
	): void;
}
