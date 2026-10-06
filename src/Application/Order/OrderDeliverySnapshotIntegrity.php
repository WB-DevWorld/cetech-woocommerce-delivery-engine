<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Order;

/**
 * Classifies order delivery snapshot integrity/status (read-only; no repair or recalculation).
 */
final class OrderDeliverySnapshotIntegrity {

	public const STATUS_PRESENT_VALID = 'present_valid';

	public const STATUS_MISSING = 'missing';

	public const STATUS_MALFORMED = 'malformed';

	public const STATUS_VERSION_MISMATCH = 'version_mismatch';

	public const STATUS_PARTIAL = 'partial';

	public const STATUS_QUOTE_MISSING = 'quote_missing';

	public const STATUS_SELECTION_ONLY = 'selection_only';

	public function classify_line( OrderDeliveryLineReadResult $read ): string {
		if ( ! $read->has_meta ) {
			return self::STATUS_MISSING;
		}

		if ( OrderDeliveryLineReadResult::ERROR_MALFORMED === $read->error ) {
			return self::STATUS_MALFORMED;
		}

		if ( OrderDeliveryLineReadResult::ERROR_VERSION_MISMATCH === $read->error ) {
			return self::STATUS_VERSION_MISMATCH;
		}

		if ( OrderDeliveryLineReadResult::ERROR_PARTIAL === $read->error ) {
			return self::STATUS_PARTIAL;
		}

		if ( null === $read->snapshot ) {
			return self::STATUS_MALFORMED;
		}

		return $this->classify_line_snapshot( $read->snapshot );
	}

	public function classify_package( OrderDeliveryPackageReadResult $read ): string {
		if ( ! $read->has_meta ) {
			return self::STATUS_MISSING;
		}

		if ( OrderDeliveryPackageReadResult::ERROR_MALFORMED === $read->error ) {
			return self::STATUS_MALFORMED;
		}

		if ( OrderDeliveryPackageReadResult::ERROR_VERSION_MISMATCH === $read->error ) {
			return self::STATUS_VERSION_MISMATCH;
		}

		if ( OrderDeliveryPackageReadResult::ERROR_PARTIAL === $read->error ) {
			return self::STATUS_PARTIAL;
		}

		if ( null === $read->snapshot ) {
			return self::STATUS_MALFORMED;
		}

		$status = $read->snapshot->quote_status;
		if ( ! in_array( $status, [ 'success', 'not_applicable', 'failure', 'quoted' ], true ) ) {
			return self::STATUS_PARTIAL;
		}
		$amount = $read->snapshot->package_total_delivery_amount;
		if ( null !== $amount && ! OrderDeliverySnapshotJson::is_decimal_amount( $amount ) ) {
			return self::STATUS_PARTIAL;
		}
		foreach ( $read->snapshot->groups as $group ) {
			if ( ! $group instanceof OrderDeliveryGroupSnapshot
				|| ( null !== $group->package_total_delivery_amount && ! OrderDeliverySnapshotJson::is_decimal_amount( $group->package_total_delivery_amount ) ) ) {
				return self::STATUS_PARTIAL;
			}
		}
		if ( in_array( $status, [ 'success', 'quoted' ], true ) && null === $amount ) {
			return self::STATUS_QUOTE_MISSING;
		}

		return self::STATUS_PRESENT_VALID;
	}

	/**
	 * @param list<OrderDeliveryLineReadResult> $line_reads
	 */
	public function classify_order_lines( array $line_reads ): string {
		if ( [] === $line_reads ) {
			return self::STATUS_MISSING;
		}

		$statuses = [];

		foreach ( $line_reads as $read ) {
			$statuses[] = $this->classify_line( $read );
		}

		if ( $this->all_match( $statuses, self::STATUS_MISSING ) ) {
			return self::STATUS_MISSING;
		}

		foreach ( [ self::STATUS_MALFORMED, self::STATUS_VERSION_MISMATCH, self::STATUS_PARTIAL, self::STATUS_QUOTE_MISSING ] as $priority ) {
			if ( in_array( $priority, $statuses, true ) ) {
				return $priority;
			}
		}

		if ( $this->all_match( $statuses, self::STATUS_SELECTION_ONLY ) ) {
			return self::STATUS_SELECTION_ONLY;
		}

		if ( in_array( self::STATUS_PRESENT_VALID, $statuses, true ) ) {
			return self::STATUS_PRESENT_VALID;
		}

		return self::STATUS_SELECTION_ONLY;
	}

	private function classify_line_snapshot( OrderDeliveryLineSnapshot $snapshot ): string {
		if ( null !== $snapshot->quoted_amount && ! OrderDeliverySnapshotJson::is_decimal_amount( $snapshot->quoted_amount ) ) {
			return self::STATUS_PARTIAL;
		}

		if ( OrderDeliverySnapshot::QUOTE_STATUS_SELECTION_ONLY === $snapshot->quote_status ) {
			if ( null !== $snapshot->delivery_offer_id && $snapshot->delivery_offer_id > 0 ) {
				return self::STATUS_QUOTE_MISSING;
			}

			return self::STATUS_SELECTION_ONLY;
		}

		if ( OrderDeliverySnapshot::QUOTE_STATUS_QUOTED === $snapshot->quote_status ) {
			if ( null === $snapshot->quoted_amount || '' === trim( $snapshot->quoted_amount ) ) {
				return self::STATUS_QUOTE_MISSING;
			}

			return self::STATUS_PRESENT_VALID;
		}

		if ( OrderDeliverySnapshot::QUOTE_STATUS_SKIPPED === $snapshot->quote_status ) {
			return self::STATUS_SELECTION_ONLY;
		}

		return self::STATUS_PARTIAL;
	}

	/**
	 * @param list<string> $statuses
	 */
	private function all_match( array $statuses, string $expected ): bool {
		foreach ( $statuses as $status ) {
			if ( $status !== $expected ) {
				return false;
			}
		}

		return true;
	}
}
