<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Coverage;

interface CoverageGroupRepositoryInterface {

	/**
	 * @return list<CoverageGroup>
	 */
	public function list_by_zone( int $zone_id ): array;

	/**
	 * @param list<int> $zone_ids
	 *
	 * @return array<int, list<CoverageGroup>>
	 */
	public function list_by_zone_ids( array $zone_ids ): array;

	public function find_by_id( int $id ): ?CoverageGroup;

	/**
	 * @param array<string, mixed> $payload
	 */
	public function save_group( array $payload ): CoverageGroup;

	public function delete_group( int $id ): void;

	public function delete_by_zone( int $zone_id ): void;

	/**
	 * @param list<array{location_id:int,membership:string}> $members
	 */
	public function replace_members( int $group_id, array $members ): void;

	/**
	 * @param list<array{postcode_value:string,match_mode:string,priority?:int,status?:string}> $postcodes
	 */
	public function replace_postcodes( int $group_id, array $postcodes ): void;

	/**
	 * Full replace of a zone's coverage configuration.
	 *
	 * @param list<array<string, mixed>> $groups
	 *
	 * @return list<CoverageGroup>
	 */
	public function replace_for_zone( int $zone_id, array $groups ): array;

	public function count_review_required(): int;
}
