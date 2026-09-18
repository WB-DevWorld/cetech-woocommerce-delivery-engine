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
		?string $installed_at = null,
		string $expected_target_token = ''
	): void;

	/**
	 * Acquire a pack mutation lease with a conditional UPDATE.
	 * Succeeds only when no live owner exists or the current lease has expired.
	 *
	 * @return string Owner token, or empty when the lease is held by another worker.
	 */
	public function acquire_lease( int $id, string $role, int $now, int $ttl_seconds ): string;

	/**
	 * Renew only when the stored owner matches $owner.
	 */
	public function renew_lease( int $id, string $owner, int $now, int $ttl_seconds ): bool;

	/**
	 * Clear the lease only when the stored owner matches $owner.
	 */
	public function release_lease( int $id, string $owner ): bool;

	/**
	 * @return array{owner: string, role: string, acquired_at: int, expires_at: int}
	 */
	public function current_lease( int $id ): array;
}
