<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;

trait Cor006UnusedStore {

	public function findById( int $id ): ?array {
		unset( $id );

		return null;
	}

	public function findByCode( string $code ): ?array {
		unset( $code );

		return null;
	}

	public function save( array $data ): int {
		unset( $data );

		return 0;
	}

	public function list( array $criteria = [] ): array {
		unset( $criteria );

		return [];
	}

	public function hardDelete( int $id ): bool {
		unset( $id );

		return false;
	}

	public function count_all(): int {
		return 0;
	}
}

final class Cor006LogisticsStore implements LogisticsProfileRepositoryInterface {
	use Cor006UnusedStore;

	public function softDelete( int $id ): bool {
		unset( $id );

		return false;
	}
}

final class Cor006PickupStore implements PickupLocationRepositoryInterface {
	use Cor006UnusedStore;

	public function softDelete( int $id ): bool {
		unset( $id );

		return false;
	}
}

final class Cor006SupplierStore implements SupplierRepositoryInterface {
	use Cor006UnusedStore;

	public function deactivate( int $id ): bool {
		unset( $id );

		return false;
	}
}

final class Cor006OriginStore implements OriginRepositoryInterface {
	use Cor006UnusedStore;

	public function deactivate( int $id ): bool {
		unset( $id );

		return false;
	}

	public function countBySupplierId( int $supplier_id ): int {
		unset( $supplier_id );

		return 0;
	}
}
