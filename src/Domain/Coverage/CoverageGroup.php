<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Coverage;

use CetechDeliveryEngine\Domain\Enum\CoverageMembership;
use CetechDeliveryEngine\Domain\Enum\CoverageMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

final class CoverageGroup {

	/**
	 * @param list<CoverageMember>   $members
	 * @param list<CoveragePostcode> $postcodes
	 * @param array<string, mixed>   $legacy_migration
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $zone_id,
		public readonly int $root_location_id,
		public readonly CoverageMode $mode,
		public readonly int $sort_order,
		public readonly RecordStatus $status,
		public readonly bool $review_required,
		public readonly array $legacy_migration,
		public readonly array $members = [],
		public readonly array $postcodes = []
	) {
	}

	public function isUsable(): bool {
		return RecordStatus::Active === $this->status && $this->root_location_id > 0;
	}

	/**
	 * @return list<CoverageMember>
	 */
	public function members_of( CoverageMembership $membership ): array {
		$out = [];
		foreach ( $this->members as $member ) {
			if ( $member->membership === $membership ) {
				$out[] = $member;
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<CoverageMember> $members
	 * @param list<CoveragePostcode> $postcodes
	 */
	public static function fromRow( array $row, array $members = [], array $postcodes = [] ): self {
		$mode   = CoverageMode::tryFrom( (string) ( $row['coverage_mode'] ?? '' ) ) ?? CoverageMode::EntireArea;
		$status = RecordStatus::tryFrom( (string) ( $row['status'] ?? '' ) ) ?? RecordStatus::Inactive;
		$legacy = [];
		if ( isset( $row['legacy_migration_json'] ) && is_string( $row['legacy_migration_json'] ) && '' !== $row['legacy_migration_json'] ) {
			$decoded = json_decode( $row['legacy_migration_json'], true );
			$legacy  = is_array( $decoded ) ? $decoded : [];
		} elseif ( isset( $row['legacy_migration'] ) && is_array( $row['legacy_migration'] ) ) {
			$legacy = $row['legacy_migration'];
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['zone_id'] ?? 0 ),
			(int) ( $row['root_location_id'] ?? 0 ),
			$mode,
			(int) ( $row['sort_order'] ?? 100 ),
			$status,
			! empty( $row['review_required'] ),
			$legacy,
			$members,
			$postcodes
		);
	}
}
