<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Coverage;

use CetechDeliveryEngine\Domain\Coverage\CoverageGroupRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Permanent Delivery Area delete: coverage rows, then rules, then the zone.
 *
 * No cross-database FK. Failure rolls back so a half-deleted record is not left.
 */
final class DeliveryAreaCoverageCleanup {

	public function __construct(
		private CoverageGroupRepositoryInterface $coverage_groups,
		private DestinationRuleRepositoryInterface $rules,
		private DestinationZoneRepositoryInterface $zones
	) {
	}

	public function hard_delete_zone( int $zone_id ): bool {
		if ( $zone_id <= 0 ) {
			return false;
		}

		$wpdb = $GLOBALS['wpdb'] ?? null;
		$use_txn = is_object( $wpdb ) && method_exists( $wpdb, 'query' );
		if ( $use_txn ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		try {
			$this->coverage_groups->delete_by_zone( $zone_id );
			if ( ! $this->rules->deleteByZoneId( $zone_id ) ) {
				throw new \RuntimeException( 'Destination rule cleanup failed.' );
			}
			if ( ! $this->zones->hardDelete( $zone_id ) ) {
				throw new \RuntimeException( 'Destination zone delete failed.' );
			}
			if ( $use_txn ) {
				$wpdb->query( 'COMMIT' );
			}

			return true;
		} catch ( \Throwable $e ) {
			if ( $use_txn ) {
				$wpdb->query( 'ROLLBACK' );
			}

			return false;
		}
	}
}
