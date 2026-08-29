<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RateCard;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

/**
 * Bounded rate-card amount mutations. Malformed numbers never become zero.
 */
final class RateCardBulkMutator {

	public function __construct(
		private readonly RateCardRepositoryInterface $rates,
		private readonly ?DeliveryOfferRepositoryInterface $offers = null,
		private readonly ?DestinationZoneRepositoryInterface $zones = null
	) {
	}

	/**
	 * @param array<string, mixed> $manifest
	 * @return array{
	 *   outcome: string,
	 *   error_code: ?string,
	 *   error_summary: ?string,
	 *   warning: bool,
	 *   before_snapshot: array<string, mixed>,
	 *   after_fingerprint: string,
	 *   precondition_fingerprint: string,
	 *   result: array<string, mixed>
	 * }
	 */
	public function process( int $rate_card_id, array $manifest, bool $dry_run ): array {
		$existing = $this->rates->findById( $rate_card_id );
		if ( ! is_array( $existing ) ) {
			return $this->fail( 'rate_card_missing', 'Delivery Charge was not found.', [] );
		}

		$before = $this->snapshot_row( $existing );
		$pre    = $this->fingerprint( $before );

		try {
			$updated = $existing;
			$op      = (string) ( $manifest['amount_op'] ?? '' );
			if ( '' !== $op ) {
				$normalized = RateCardBulkAmountMath::normalize_operation(
					$op,
					$manifest['amount_value'] ?? null
				);
				if ( 'percent' === $normalized['op'] ) {
					$updated['base_amount'] = RateCardBulkAmountMath::increase_percent(
						$existing['base_amount'] ?? null,
						$normalized['value']
					);
				} elseif ( 'fixed' === $normalized['op'] ) {
					$updated['base_amount'] = RateCardBulkAmountMath::increase_fixed(
						$existing['base_amount'] ?? null,
						$normalized['value']
					);
				}
			} elseif ( array_key_exists( 'amount_value', $manifest ) && null !== $manifest['amount_value'] && '' !== $manifest['amount_value'] ) {
				throw new \InvalidArgumentException( 'Choose an amount operation before entering a value.' );
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

		$proposed = $this->snapshot_row( $updated );
		$after_fp = $this->fingerprint( $proposed );
		$result   = $this->presentation( $before, $proposed );

		if ( $pre === $after_fp ) {
			return [
				'outcome'                  => 'unchanged',
				'error_code'               => null,
				'error_summary'            => null,
				'warning'                  => false,
				'before_snapshot'          => $before,
				'after_fingerprint'        => $after_fp,
				'precondition_fingerprint' => $pre,
				'result'                   => $result,
			];
		}

		if ( ! $dry_run ) {
			$saved = $this->rates->save( $updated );
			if ( $saved <= 0 ) {
				return $this->fail( 'rate_card_save_failed', 'Unable to save Delivery Charge.', $before );
			}
			$persisted = $this->rates->findById( $rate_card_id );
			if ( is_array( $persisted ) ) {
				$proposed = $this->snapshot_row( $persisted );
				$after_fp = $this->fingerprint( $proposed );
				$result   = $this->presentation( $before, $proposed );
			}
		}

		return [
			'outcome'                  => 'changed',
			'error_code'               => null,
			'error_summary'            => null,
			'warning'                  => false,
			'before_snapshot'          => $before,
			'after_fingerprint'        => $after_fp,
			'precondition_fingerprint' => $pre,
			'result'                   => $result,
		];
	}

	/**
	 * Restore a Delivery Charge only when the current canonical fingerprint still
	 * matches the fingerprint captured immediately after the original job.
	 *
	 * @param array<string, mixed> $before_snapshot
	 * @return array{outcome: string, error_code: ?string, error_summary: ?string}
	 */
	public function rollback( int $rate_card_id, array $before_snapshot, string $after_fingerprint ): array {
		$current = $this->rates->findById( $rate_card_id );
		if ( ! is_array( $current ) ) {
			return [
				'outcome'       => 'rollback_skipped',
				'error_code'    => 'edited_after_job',
				'error_summary' => 'This item was edited after the bulk job, so rollback skipped it.',
			];
		}

		$current_fp = $this->fingerprint( $this->snapshot_row( $current ) );
		if ( $current_fp !== $after_fingerprint ) {
			return [
				'outcome'       => 'rollback_skipped',
				'error_code'    => 'edited_after_job',
				'error_summary' => 'This item was edited after the bulk job, so rollback skipped it.',
			];
		}

		if ( [] === $before_snapshot || ! isset( $before_snapshot['base_amount'] ) ) {
			return [
				'outcome'       => 'rollback_failed',
				'error_code'    => 'missing_before_snapshot',
				'error_summary' => 'The previous Delivery Charge snapshot is missing.',
			];
		}

		$restored                  = $current;
		$restored['base_amount']   = $before_snapshot['base_amount'];
		$restored['status']        = (string) ( $before_snapshot['status'] ?? $current['status'] ?? 'active' );
		$restored['priority']      = (int) ( $before_snapshot['priority'] ?? $current['priority'] ?? 100 );
		$restored['internal_code'] = (string) ( $before_snapshot['internal_code'] ?? $current['internal_code'] ?? '' );
		$saved                     = $this->rates->save( $restored );
		if ( $saved <= 0 ) {
			return [
				'outcome'       => 'rollback_failed',
				'error_code'    => 'rate_card_save_failed',
				'error_summary' => 'Unable to restore Delivery Charge.',
			];
		}

		return [
			'outcome'       => 'rolled_back',
			'error_code'    => null,
			'error_summary' => null,
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
			'warning'                  => false,
			'before_snapshot'          => $before,
			'after_fingerprint'        => '',
			'precondition_fingerprint' => [] === $before ? '' : $this->fingerprint( $before ),
			'result'                   => $this->presentation( $before, $before ),
		];
	}

	/**
	 * Canonical comparable state. updated_at and unused quote fields are excluded
	 * so a job-authored save is not treated as a later staff edit.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function snapshot_row( array $row ): array {
		$amount = '';
		try {
			$amount = RateCardAmountFormatter::format( $row['base_amount'] ?? null );
		} catch ( \InvalidArgumentException ) {
			$amount = (string) ( $row['base_amount'] ?? '' );
		}

		$id    = (int) ( $row['id'] ?? 0 );
		$offer = $this->named_record( $this->offers, (int) ( $row['delivery_offer_id'] ?? 0 ) );
		$zone  = $this->named_record( $this->zones, (int) ( $row['destination_zone_id'] ?? 0 ) );

		return [
			'id'            => $id,
			'internal_code' => (string) ( $row['internal_code'] ?? '' ),
			'base_amount'   => $amount,
			'status'        => (string) ( $row['status'] ?? '' ),
			'priority'      => (int) ( $row['priority'] ?? 100 ),
			'currency'      => strtoupper( trim( (string) ( $row['base_currency'] ?? $row['currency'] ?? '' ) ) ),
			'offer_label'   => $offer,
			'zone_label'    => $zone,
			'display_name'  => $this->display_name( $id, $offer, $zone, (string) ( $row['internal_code'] ?? '' ) ),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function fingerprint( array $row ): string {
		$canonical = [
			'id'            => (int) ( $row['id'] ?? 0 ),
			'internal_code' => (string) ( $row['internal_code'] ?? '' ),
			'base_amount'   => (string) ( $row['base_amount'] ?? '' ),
			'status'        => (string) ( $row['status'] ?? '' ),
			'priority'      => (int) ( $row['priority'] ?? 100 ),
		];

		return hash( 'sha256', wp_json_encode( $canonical ) ?: '' );
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	private function presentation( array $before, array $after ): array {
		return [
			'entity_type'      => 'rate_card',
			'entity_label'     => (string) ( $after['display_name'] ?? $before['display_name'] ?? '' ),
			'current_amount'   => (string) ( $before['base_amount'] ?? '' ),
			'proposed_amount'  => (string) ( $after['base_amount'] ?? '' ),
			'currency'         => (string) ( $after['currency'] ?? $before['currency'] ?? '' ),
			'offer_label'      => (string) ( $after['offer_label'] ?? $before['offer_label'] ?? '' ),
			'zone_label'       => (string) ( $after['zone_label'] ?? $before['zone_label'] ?? '' ),
		];
	}

	private function named_record( DeliveryOfferRepositoryInterface|DestinationZoneRepositoryInterface|null $repository, int $id ): string {
		if ( ! $repository instanceof DeliveryOfferRepositoryInterface && ! $repository instanceof DestinationZoneRepositoryInterface ) {
			return '';
		}
		if ( $id <= 0 ) {
			return '';
		}
		$row = $repository->findById( $id );
		if ( ! is_array( $row ) ) {
			return '';
		}

		foreach ( [ 'name', 'label', 'public_label', 'internal_code' ] as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function display_name( int $id, string $offer, string $zone, string $code ): string {
		if ( '' !== $offer && '' !== $zone ) {
			return $offer . ' — ' . $zone;
		}
		if ( '' !== $offer ) {
			return $offer;
		}
		if ( '' !== $code ) {
			return $code;
		}

		return $id > 0 ? sprintf( 'Delivery Charge #%d', $id ) : 'Delivery Charge';
	}
}
