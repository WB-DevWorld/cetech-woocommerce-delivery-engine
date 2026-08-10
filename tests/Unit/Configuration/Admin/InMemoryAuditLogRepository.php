<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration\Admin;

use CetechDeliveryEngine\Domain\Audit\AuditLogRepositoryInterface;

/**
 * In-memory audit log for Stage 4 admin tests (no WordPress).
 */
final class InMemoryAuditLogRepository implements AuditLogRepositoryInterface {

	/** @var list<array<string, mixed>> */
	public array $entries = [];

	public function findById( int $id ): ?array {
		return $this->entries[ $id - 1 ] ?? null;
	}

	public function append( array $data ): int {
		$this->entries[] = $data;

		return count( $this->entries );
	}

	public function list( array $criteria = [] ): array {
		return $this->entries;
	}
}
