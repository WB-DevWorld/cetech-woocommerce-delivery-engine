<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Support;

use CetechDeliveryEngine\Domain\Enum\RecordStatus;

/**
 * Complete per-zone/per-offer Active rate-card listing for test doubles.
 * Production WpdbRateCardRepository uses unbounded SQL instead.
 */
trait RateCardActiveListingTrait {

	public function countActiveByDestinationZoneId( int $destination_zone_id ): int {
		return count( $this->listActiveByDestinationZoneId( $destination_zone_id ) );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function listActiveByDestinationZoneId( int $destination_zone_id ): array {
		if ( $destination_zone_id <= 0 ) {
			return [];
		}
		$out = [];
		foreach ( $this->list( [] ) as $card ) {
			if ( (int) ( $card['destination_zone_id'] ?? 0 ) !== $destination_zone_id ) {
				continue;
			}
			if ( RecordStatus::Active->value !== (string) ( $card['status'] ?? '' ) ) {
				continue;
			}
			$out[] = $card;
		}

		return $out;
	}

	public function countActiveByDeliveryOfferId( int $delivery_offer_id ): int {
		if ( $delivery_offer_id <= 0 ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->list( [] ) as $card ) {
			if ( (int) ( $card['delivery_offer_id'] ?? 0 ) !== $delivery_offer_id ) {
				continue;
			}
			if ( RecordStatus::Active->value !== (string) ( $card['status'] ?? '' ) ) {
				continue;
			}
			++$count;
		}

		return $count;
	}
}
