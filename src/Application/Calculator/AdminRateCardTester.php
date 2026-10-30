<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Calculator;

use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;

/**
 * Admin-only rate card matching and amount preview.
 *
 * Not wired to cart, checkout, WooCommerce shipping, or customer-facing prices.
 */
final class AdminRateCardTester {

	public function __construct(
		private RateCardRepositoryInterface $rate_card_repository
	) {
	}

	/**
	 * @return array{
	 *     matched: bool,
	 *     rate_card_id: int|null,
	 *     rate_card_code: string|null,
	 *     charge_type: string|null,
	 *     amount: string|null,
	 *     currency: string,
	 *     explanation: string
	 * }
	 */
	public function test(
		int $delivery_offer_id,
		int $destination_zone_id,
		?int $logistics_profile_id,
		?int $supplier_id,
		?int $origin_id,
		int $quantity,
		string $currency_code
	): array {
		$currency_code = strtoupper( trim( $currency_code ) );
		$now           = gmdate( 'Y-m-d H:i:s' );

		$candidates = [];

		foreach ( $this->rate_card_repository->list( [ 'status' => RecordStatus::Active->value, 'limit' => 500 ] ) as $card ) {
			if ( ! $this->is_effective( $card, $now ) ) {
				continue;
			}

			if ( (int) ( $card['delivery_offer_id'] ?? 0 ) !== $delivery_offer_id ) {
				continue;
			}

			if ( (int) ( $card['destination_zone_id'] ?? 0 ) !== $destination_zone_id ) {
				continue;
			}

			if ( strtoupper( (string) ( $card['base_currency'] ?? '' ) ) !== $currency_code ) {
				continue;
			}

			if ( ! $this->optional_dimension_matches( $logistics_profile_id, $card['logistics_profile_id'] ?? null ) ) {
				continue;
			}

			if ( ! $this->optional_dimension_matches( $supplier_id, $card['supplier_id'] ?? null ) ) {
				continue;
			}

			if ( ! $this->optional_dimension_matches( $origin_id, $card['origin_id'] ?? null ) ) {
				continue;
			}

			$candidates[] = [
				'card'        => $card,
				'specificity' => $this->specificity_score(
					$card,
					$logistics_profile_id,
					$supplier_id,
					$origin_id
				),
			];
		}

		if ( [] === $candidates ) {
			return [
				'matched'         => false,
				'rate_card_id'    => null,
				'rate_card_code'  => null,
				'charge_type'     => null,
				'amount'          => null,
				'currency'        => $currency_code,
				'explanation'     => __( 'No matching active rate card.', 'cetech-woocommerce-delivery-engine' ),
			];
		}

		usort(
			$candidates,
			static function ( array $left, array $right ): int {
				$left_specificity  = (int) ( $left['specificity'] ?? 0 );
				$right_specificity = (int) ( $right['specificity'] ?? 0 );

				if ( $left_specificity !== $right_specificity ) {
					return $right_specificity <=> $left_specificity;
				}

				$left_card  = $left['card'];
				$right_card = $right['card'];
				$left_prio  = (int) ( $left_card['priority'] ?? 100 );
				$right_prio = (int) ( $right_card['priority'] ?? 100 );

				if ( $left_prio !== $right_prio ) {
					return $left_prio <=> $right_prio;
				}

				return (int) ( $left_card['id'] ?? 0 ) <=> (int) ( $right_card['id'] ?? 0 );
			}
		);

		$winner     = $candidates[0]['card'];
		$charge_type = (string) ( $winner['charge_type'] ?? '' );
		$base_amount = (string) ( $winner['base_amount'] ?? '0' );
		$amount      = $this->calculate_amount( $charge_type, $base_amount, $quantity );

		return [
			'matched'        => true,
			'rate_card_id'   => (int) ( $winner['id'] ?? 0 ),
			'rate_card_code' => (string) ( $winner['internal_code'] ?? '' ),
			'charge_type'    => $charge_type,
			'amount'         => $amount,
			'currency'       => $currency_code,
			'explanation'    => $this->build_explanation( $winner, (int) $candidates[0]['specificity'], count( $candidates ) ),
		];
	}

	/**
	 * @param array<string, mixed> $card
	 */
	private function is_effective( array $card, string $now ): bool {
		$from = isset( $card['effective_from'] ) ? trim( (string) $card['effective_from'] ) : '';
		$to   = isset( $card['effective_to'] ) ? trim( (string) $card['effective_to'] ) : '';

		if ( '' !== $from && $now < $from ) {
			return false;
		}

		if ( '' !== $to && $now > $to ) {
			return false;
		}

		return true;
	}

	/**
	 * D1: a card scoped to a specific profile, supplier or origin matches only an item that carries
	 * that same value. An unscoped card matches any item. An item without a value never matches a
	 * scoped card.
	 */
	private function optional_dimension_matches( ?int $test_value, mixed $card_value ): bool {
		$card_int = null === $card_value || '' === $card_value ? null : (int) $card_value;

		if ( null === $card_int || $card_int <= 0 ) {
			return true;
		}

		if ( null === $test_value || $test_value <= 0 ) {
			return false;
		}

		return $test_value === $card_int;
	}

	/**
	 * @param array<string, mixed> $card
	 */
	private function specificity_score(
		array $card,
		?int $logistics_profile_id,
		?int $supplier_id,
		?int $origin_id
	): int {
		$score = 0;

		if ( null !== $logistics_profile_id && $logistics_profile_id > 0 ) {
			$card_lp = isset( $card['logistics_profile_id'] ) ? (int) $card['logistics_profile_id'] : 0;

			if ( $card_lp > 0 && $card_lp === $logistics_profile_id ) {
				++$score;
			}
		}

		if ( null !== $supplier_id && $supplier_id > 0 ) {
			$card_supplier = isset( $card['supplier_id'] ) ? (int) $card['supplier_id'] : 0;

			if ( $card_supplier > 0 && $card_supplier === $supplier_id ) {
				++$score;
			}
		}

		if ( null !== $origin_id && $origin_id > 0 ) {
			$card_origin = isset( $card['origin_id'] ) ? (int) $card['origin_id'] : 0;

			if ( $card_origin > 0 && $card_origin === $origin_id ) {
				++$score;
			}
		}

		return $score;
	}

	private function calculate_amount( string $charge_type, string $base_amount, int $quantity ): string {
		if ( RateCardChargeType::FixedPerItem->value === $charge_type ) {
			if ( function_exists( 'bcmul' ) ) {
				return bcmul( $base_amount, (string) $quantity, 4 );
			}

			return number_format( (float) $base_amount * $quantity, 4, '.', '' );
		}

		return number_format( (float) $base_amount, 4, '.', '' );
	}

	/**
	 * @param array<string, mixed> $card
	 */
	private function build_explanation( array $card, int $specificity, int $candidate_count ): string {
		$parts = [
			sprintf(
				/* translators: 1: rate card code, 2: numeric priority */
				__( 'Matched rate card "%1$s" with priority %2$d.', 'cetech-woocommerce-delivery-engine' ),
				(string) ( $card['internal_code'] ?? '' ),
				(int) ( $card['priority'] ?? 100 )
			),
		];

		if ( $specificity > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of exact optional dimension matches */
				_n(
				 '%d exact optional dimension match.',
				 '%d exact optional dimension matches.',
				 $specificity,
				 'cetech-woocommerce-delivery-engine'
				),
				$specificity
			);
		} else {
			$parts[] = __( 'Selected from wildcard/fallback optional dimensions.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $candidate_count > 1 ) {
			$parts[] = sprintf(
				/* translators: %d: number of candidate rate cards */
				_n(
				 '%d candidate rate card was considered.',
				 '%d candidate rate cards were considered.',
				 $candidate_count,
				 'cetech-woocommerce-delivery-engine'
				),
				$candidate_count
			);
		}

		return implode( ' ', $parts );
	}
}
