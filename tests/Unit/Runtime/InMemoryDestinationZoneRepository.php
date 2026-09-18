<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Minimal in-memory destination zone repository for admin setup repair tests.
 */
final class InMemoryDestinationZoneRepository implements DestinationZoneRepositoryInterface {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

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
		return array_values( $this->rows );
	}

	public function page_after( int $after_id, int $limit = 100, array $criteria = [] ): array {
		$status = (string) ( $criteria['status'] ?? '' );
		$rows   = [];
		foreach ( $this->rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= $after_id ) {
				continue;
			}
			if ( '' !== $status && (string) ( $row['status'] ?? '' ) !== $status ) {
				continue;
			}
			$rows[] = $row;
		}
		usort(
			$rows,
			static fn ( array $left, array $right ): int => ( (int) ( $left['id'] ?? 0 ) ) <=> ( (int) ( $right['id'] ?? 0 ) )
		);

		return array_slice( $rows, 0, max( 1, $limit ) );
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
