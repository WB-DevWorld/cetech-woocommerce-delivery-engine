<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Coverage;

use CetechDeliveryEngine\Domain\Enum\DestinationRuleMatchMode;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

final class CoveragePostcode {

	public function __construct(
		public readonly int $id,
		public readonly int $coverage_group_id,
		public readonly string $postcode_value,
		public readonly DestinationRuleMatchMode $match_mode,
		public readonly int $priority,
		public readonly RecordStatus $status
	) {
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow( array $row ): self {
		$mode   = DestinationRuleMatchMode::tryFrom( (string) ( $row['match_mode'] ?? '' ) ) ?? DestinationRuleMatchMode::Exact;
		$status = RecordStatus::tryFrom( (string) ( $row['status'] ?? '' ) ) ?? RecordStatus::Active;

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['coverage_group_id'] ?? 0 ),
			strtoupper( trim( (string) ( $row['postcode_value'] ?? '' ) ) ),
			$mode,
			(int) ( $row['priority'] ?? 100 ),
			$status
		);
	}

	public function matches( string $postcode ): bool {
		$postcode = strtoupper( trim( $postcode ) );
		if ( '' === $postcode || '' === $this->postcode_value ) {
			return false;
		}

		if ( DestinationRuleMatchMode::Prefix === $this->match_mode ) {
			return str_starts_with( $postcode, $this->postcode_value );
		}

		return $postcode === $this->postcode_value;
	}
}
