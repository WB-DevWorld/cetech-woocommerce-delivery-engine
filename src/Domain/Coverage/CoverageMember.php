<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Coverage;

use CetechDeliveryEngine\Domain\Enum\CoverageMembership;

final class CoverageMember {

	public function __construct(
		public readonly int $id,
		public readonly int $coverage_group_id,
		public readonly int $location_id,
		public readonly CoverageMembership $membership
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow( array $row ): self {
		$membership = CoverageMembership::tryFrom( (string) ( $row['membership'] ?? '' ) ) ?? CoverageMembership::Include;

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['coverage_group_id'] ?? 0 ),
			(int) ( $row['location_id'] ?? 0 ),
			$membership
		);
	}
}
