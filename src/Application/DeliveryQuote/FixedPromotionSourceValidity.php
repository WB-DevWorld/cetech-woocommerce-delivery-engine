<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\DeliveryQuote\QuoteTime;
use CetechDeliveryEngine\Infrastructure\Persistence\LegacyQuoteSourceSnapshotReader;

/** Pure bounded legacy-source interval; no clock, pricing, SQL or admission authority. */
final class FixedPromotionSourceValidity {
	public static function validity_at( LegacyQuoteSourceSnapshot $snapshot, QuoteTime $at, QuoteTime $ceiling ): ?QuoteCurrentEvidenceValidity {
		try {
			if ( $at->compare( $snapshot->captured_at() ) < 0 || $at->compare( $ceiling ) >= 0 ) { return null; }
			$count = 0;
			foreach ( $snapshot->plan()->rate_ranges() as $range ) {
				$count += $snapshot->candidate_count( $range['delivery_offer_id'], $range['destination_zone_id'], $range['base_currency'] );
				if ( $count > LegacyQuoteSourceSnapshotReader::MAX_CANDIDATES ) { return null; }
			}
			if ( ! $snapshot->applicable_at( $at ) ) { return null; }
			$until = $ceiling; $repository = $snapshot->active_repository();
			foreach ( $snapshot->plan()->rate_ranges() as $range ) {
				foreach ( $repository->listActiveForQuoteMatch( $range['delivery_offer_id'], $range['destination_zone_id'], $range['base_currency'] ) as $row ) {
					foreach ( [ 'effective_from', 'effective_to' ] as $field ) {
						if ( null === ( $row[$field] ?? null ) ) { continue; }
						if ( ! is_string( $row[$field] ) ) { return null; }
						$boundary = QuoteTime::parse( $row[$field] . '.000000' );
						// Retained Rate Cards include every microsecond of the effective_to second.
						if ( 'effective_to' === $field ) { $boundary = $boundary->plus_seconds( 1 ); }
						if ( $boundary->compare( $at ) > 0 && $boundary->compare( $until ) < 0 ) { $until = $boundary; }
					}
				}
			}
			return QuoteCurrentEvidenceValidity::capture( $at, $until );
		} catch ( \Throwable ) { return null; }
	}
}
