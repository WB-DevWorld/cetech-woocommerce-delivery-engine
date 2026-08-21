<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RateCard;

/**
 * Bounded rate-card amount mutations. Malformed numbers never become zero.
 */
final class RateCardBulkMutator {

	public function __construct(
		private readonly RateCardRepositoryInterface $rates
	) {
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array{
	 *   outcome: string,
	 *   error_code: ?string,
	 *   error_summary: ?string,
	 *   before_snapshot: array<string, mixed>,
	 *   after_fingerprint: string,
	 *   precondition_fingerprint: string
	 * }
	 */
	public function process( int $rate_card_id, array $manifest, bool $dry_run ): array {
		$existing = $this->rates->findById( $rate_card_id );
		if ( ! is_array( $existing ) ) {
			return $this->fail( 'rate_card_missing', 'Delivery Charge was not found.', [] );
		}

		$before = [
			'id'            => (int) ( $existing['id'] ?? 0 ),
			'internal_code' => (string) ( $existing['internal_code'] ?? '' ),
			'base_amount'   => (string) ( $existing['base_amount'] ?? '' ),
			'status'        => (string) ( $existing['status'] ?? '' ),
			'priority'      => (int) ( $existing['priority'] ?? 100 ),
		];
		$pre = $this->fingerprint( $before );

		try {
			$updated = $existing;
			$op      = (string) ( $manifest['amount_op'] ?? '' );
			if ( 'percent' === $op ) {
				$updated['base_amount'] = RateCardBulkAmountMath::increase_percent(
					$existing['base_amount'] ?? null,
					$manifest['amount_value'] ?? null
				);
			} elseif ( 'fixed' === $op ) {
				$updated['base_amount'] = RateCardBulkAmountMath::increase_fixed(
					$existing['base_amount'] ?? null,
					$manifest['amount_value'] ?? null
				);
			}
			$status_action = (string) ( $manifest['status_action'] ?? '' );
			if ( 'activate' === $status_action ) {
				$updated['status'] = 'active';
			} elseif ( 'deactivate' === $status_action ) {
				$updated['status'] = 'inactive';
			}
			if ( isset( $manifest['priority'] ) && is_numeric( $manifest['priority'] ) ) {
				$updated['priority'] = (int) $manifest['priority'];
			}
		} catch ( \InvalidArgumentException $exception ) {
			return $this->fail( 'invalid_amount', $exception->getMessage(), $before );
		}

		$after = [
			'id'            => (int) ( $updated['id'] ?? 0 ),
			'internal_code' => (string) ( $updated['internal_code'] ?? '' ),
			'base_amount'   => RateCardAmountFormatter::format( $updated['base_amount'] ?? null ),
			'status'        => (string) ( $updated['status'] ?? '' ),
			'priority'      => (int) ( $updated['priority'] ?? 100 ),
		];
		$after_fp = $this->fingerprint( $after );
		if ( $pre === $after_fp ) {
			return [
				'outcome'                  => 'unchanged',
				'error_code'               => null,
				'error_summary'            => null,
				'before_snapshot'          => $before,
				'after_fingerprint'        => $after_fp,
				'precondition_fingerprint' => $pre,
			];
		}

		if ( ! $dry_run ) {
			$saved = $this->rates->save( $updated );
			if ( $saved <= 0 ) {
				return $this->fail( 'rate_card_save_failed', 'Unable to save Delivery Charge.', $before );
			}
		}

		return [
			'outcome'                  => 'changed',
			'error_code'               => null,
			'error_summary'            => null,
			'before_snapshot'          => $before,
			'after_fingerprint'        => $after_fp,
			'precondition_fingerprint' => $pre,
		];
	}

	/**
	 * @param array<string, mixed> $before
	 * @return array<string, mixed>
	 */
	private function fail( string $code, string $summary, array $before ): array {
		return [
			'outcome'                  => 'failed',
			'error_code'               => $code,
			'error_summary'            => $summary,
			'before_snapshot'          => $before,
			'after_fingerprint'        => '',
			'precondition_fingerprint' => $this->fingerprint( $before ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function fingerprint( array $row ): string {
		return hash( 'sha256', wp_json_encode( $row ) ?: '' );
	}
}
