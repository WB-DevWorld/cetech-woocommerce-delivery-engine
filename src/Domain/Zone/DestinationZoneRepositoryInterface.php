<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Zone;

interface DestinationZoneRepositoryInterface {

	/**
	 * @return array<string, mixed>|null
	 */
	public function findById( int $id ): ?array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByCode( string $code ): ?array;

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( array $data ): int;

	/**
	 * @param array<string, mixed> $criteria
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list( array $criteria = [] ): array;

	/**
	 * Complete keyset page. $limit is page size only and must not be treated as a
	 * ceiling on the total matching set. Callers that need every applicable zone
	 * must iterate until a page is empty.
	 *
	 * @param array<string, mixed> $criteria
	 *
	 * @return list<array<string, mixed>>
	 */
	public function page_after( int $after_id, int $limit = 100, array $criteria = [] ): array;

	public function softDelete( int $id ): bool;

	public function hardDelete( int $id ): bool;

	public function count_all(): int;
}
