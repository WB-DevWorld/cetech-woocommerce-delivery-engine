<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;

/**
 * In-memory rate cards for quote and overlap tests.
 */
final class InMemoryQuoteRateCardRepository implements RateCardRepositoryInterface {

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	public function __construct(
		private array $rows = []
	) {
	}

	public function findById( int $id ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				return $row;
			}
		}

		return null;
	}

	public function findByCode( string $code ): ?array {
		return null;
	}

	public function save( array $data ): int {
		return 0;
	}

	public function list( array $criteria = [] ): array {
		return $this->rows;
	}

	public function softDelete( int $id ): bool {
		return false;
	}

	public function hardDelete( int $id ): bool {
		return false;
	}

	public function count_all(): int {
		return count( $this->rows );
	}

	public function countByDeliveryOfferId( int $delivery_offer_id ): int {
		return 0;
	}

	public function countByDestinationZoneId( int $destination_zone_id ): int {
		return 0;
	}

	public function countOrderSnapshotReferences( int $rate_card_id ): int {
		return 0;
	}

	public function countByLogisticsProfileId( int $logistics_profile_id ): int {
		return 0;
	}

	public function listActiveForQuoteMatch(
		int $delivery_offer_id,
		int $destination_zone_id,
		string $currency_code
	): array {
		$out = [];

		foreach ( $this->rows as $row ) {
			if (
				(int) ( $row['delivery_offer_id'] ?? 0 ) === $delivery_offer_id
				&& (int) ( $row['destination_zone_id'] ?? 0 ) === $destination_zone_id
				&& strtoupper( (string) ( $row['base_currency'] ?? '' ) ) === strtoupper( $currency_code )
				&& 'active' === (string) ( $row['status'] ?? '' )
			) {
				$out[] = $row;
			}
		}

		return $out;
	}
}
