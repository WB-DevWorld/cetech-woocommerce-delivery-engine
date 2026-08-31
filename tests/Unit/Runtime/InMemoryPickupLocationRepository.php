<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;

/**
 * @param array<string, mixed> $row
 */
final class InMemoryPickupLocationRepository implements PickupLocationRepositoryInterface {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	/**
	 * @param array<string, mixed> $row
	 */
	public function seed( int $id, array $row ): void {
		$row['id']         = $id;
		$this->rows[ $id ] = $row;
	}

	public function findById( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	public function findByCode( string $code ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (string) ( $row['internal_code'] ?? '' ) === $code ) {
				return $row;
			}
		}

		return null;
	}

	public function save( array $data ): int {
		$id = (int) ( $data['id'] ?? 0 );

		if ( $id <= 0 ) {
			$id = empty( $this->rows ) ? 1 : max( array_keys( $this->rows ) ) + 1;
		}

		$data['id']        = $id;
		$this->rows[ $id ] = $data;

		return $id;
	}

	public function list( array $criteria = [] ): array {
		$status = isset( $criteria['status'] ) ? (string) $criteria['status'] : '';
		$out    = [];

		foreach ( $this->rows as $row ) {
			if ( '' !== $status && (string) ( $row['status'] ?? '' ) !== $status ) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}

	public function softDelete( int $id ): bool {
		unset( $this->rows[ $id ] );

		return true;
	}

	public function hardDelete( int $id ): bool {
		return $this->softDelete( $id );
	}

	public function count_all(): int {
		return count( $this->rows );
	}
}
