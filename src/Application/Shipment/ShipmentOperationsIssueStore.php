<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Shipment;

/**
 * Compact open operational-issue index. Does not scan all shipments or orders.
 *
 * COR-033: every mutation re-reads the index under AttentionIndexLock and writes
 * it back once, so independent writers (two orders cancelled at the same time,
 * a refund hook racing a status change) keep each other's entries. Mutators
 * return false when the index could not be locked or written.
 */
final class ShipmentOperationsIssueStore {

	public const INDEX_OPTION = 'cetech_de_shipment_ops_issues';

	public const CODE_ORDER_CANCELLED_AFTER_PROGRESS = 'order_cancelled_after_progress';
	public const CODE_REFUND_REQUIRES_REVIEW         = 'refund_requires_review';
	public const CODE_STATUS_SYNC_FAILED             = 'status_sync_failed';

	public function add( int $shipment_id, int $order_id, string $code ): bool {
		if ( $shipment_id <= 0 || $order_id <= 0 || '' === $code ) {
			return false;
		}

		return $this->mutate_index(
			static function ( array $index ) use ( $shipment_id, $order_id, $code ): array {
				$row = $index[ $shipment_id ] ?? [
					'order_id'    => $order_id,
					'codes'       => [],
					'updated_at'  => gmdate( 'c' ),
				];

				$codes = is_array( $row['codes'] ?? null ) ? $row['codes'] : [];

				if ( in_array( $code, $codes, true ) ) {
					return $index;
				}

				$codes[] = $code;
				$row['order_id']   = $order_id;
				$row['codes']      = array_values( $codes );
				$row['updated_at'] = gmdate( 'c' );
				$index[ $shipment_id ] = $row;

				return $index;
			}
		);
	}

	public function clear_shipment( int $shipment_id ): bool {
		return $this->mutate_index(
			static function ( array $index ) use ( $shipment_id ): array {
				unset( $index[ $shipment_id ] );

				return $index;
			}
		);
	}

	public function remove_code( int $shipment_id, string $code ): bool {
		return $this->mutate_index(
			static function ( array $index ) use ( $shipment_id, $code ): array {
				if ( ! isset( $index[ $shipment_id ] ) ) {
					return $index;
				}

				$codes = is_array( $index[ $shipment_id ]['codes'] ?? null ) ? $index[ $shipment_id ]['codes'] : [];
				$codes = array_values(
					array_filter(
						$codes,
						static fn ( mixed $item ): bool => $code !== (string) $item
					)
				);

				if ( [] === $codes ) {
					unset( $index[ $shipment_id ] );
				} else {
					$index[ $shipment_id ]['codes']      = $codes;
					$index[ $shipment_id ]['updated_at'] = gmdate( 'c' );
				}

				return $index;
			}
		);
	}

	public function count(): int {
		return count( $this->all() );
	}

	/**
	 * @param callable(array<int, array{order_id: int, codes: list<string>, updated_at: string}>): array<int, array{order_id: int, codes: list<string>, updated_at: string}> $change
	 */
	private function mutate_index( callable $change ): bool {
		try {
			return AttentionIndexLock::run(
				self::INDEX_OPTION,
				function () use ( $change ): bool {
					$current = $this->all();
					$next    = $change( $current );

					if ( $next === $current ) {
						return true;
					}

					return (bool) update_option( self::INDEX_OPTION, $next, false );
				}
			);
		} catch ( AttentionIndexBusyException ) {
			return false;
		}
	}

	/**
	 * @return array<int, array{order_id: int, codes: list<string>, updated_at: string}>
	 */
	public function all(): array {
		$stored = get_option( self::INDEX_OPTION, [] );

		if ( ! is_array( $stored ) ) {
			return [];
		}

		$index = [];

		foreach ( $stored as $shipment_id => $row ) {
			$id = (int) $shipment_id;

			if ( $id <= 0 || ! is_array( $row ) ) {
				continue;
			}

			$codes = [];

			foreach ( (array) ( $row['codes'] ?? [] ) as $code ) {
				$code = sanitize_key( (string) $code );

				if ( '' !== $code ) {
					$codes[] = $code;
				}
			}

			if ( [] === $codes ) {
				continue;
			}

			$index[ $id ] = [
				'order_id'   => (int) ( $row['order_id'] ?? 0 ),
				'codes'      => array_values( array_unique( $codes ) ),
				'updated_at' => (string) ( $row['updated_at'] ?? '' ),
			];
		}

		return $index;
	}
}
