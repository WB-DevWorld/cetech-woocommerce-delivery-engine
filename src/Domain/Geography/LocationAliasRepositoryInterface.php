<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

interface LocationAliasRepositoryInterface {

	public function find_exact( string $country_code, string $normalized_alias, ?int $parent_id = null ): ?CanonicalLocation;

	/**
	 * @return list<string>
	 */
	public function list_for_location( int $location_id ): array;

	public function add_alias( int $location_id, string $alias, string $normalized_alias, string $language_code = '', string $alias_type = 'alternate', bool $preferred = false, string $generation_token = '' ): void;
}
