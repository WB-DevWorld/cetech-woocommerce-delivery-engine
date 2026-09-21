<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyProvider;

interface ProviderMappingRepositoryInterface {

	public function find_location_id( GeographyProvider $provider, string $external_id, string $target_token = '' ): ?int;

	public function find_external_id( int $location_id, GeographyProvider $provider ): ?string;

	/**
	 * @param array<string, mixed> $metadata
	 */
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
	): void;

	/**
	 * @return array<string, mixed>|null
	 */
	public function find_mapping( GeographyProvider $provider, string $external_id ): ?array;

	/**
	 * Live or staged mappings attached to one canonical location.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list_mappings_for_location( int $location_id, string $generation_token = '' ): array;

	public function delete_mapping( GeographyProvider $provider, string $external_id, string $generation_token = '' ): void;
}
