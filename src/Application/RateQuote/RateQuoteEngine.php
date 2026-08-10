<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\RateQuote;

use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\ValueObject\Money;

/**
 * Server-side rate quote engine.
 *
 * Used by admin quote tests and guarded WooCommerce shipping calculation (Phase 2G2+).
 */
final class RateQuoteEngine {

	public const ERROR_NO_MATCHING_RATE_CARD = 'no_matching_rate_card';

	public const ERROR_NEGATIVE_AMOUNT = 'negative_amount';

	public const ERROR_INVALID_AMOUNT = 'invalid_amount';

	public const ERROR_UNSUPPORTED_CHARGE_TYPE = 'unsupported_charge_type';

	public function __construct(
		private RateCardRepositoryInterface $rate_card_repository
	) {
	}

	public function quote( RateQuoteRequest $request ): RateQuoteResult {
		$now        = gmdate( 'Y-m-d H:i:s' );
		$candidates = [];

		foreach (
			$this->rate_card_repository->listActiveForQuoteMatch(
				$request->delivery_offer_id,
				$request->destination_zone_id,
				$request->currency->value()
			) as $card
		) {
			if ( ! $this->is_effective( $card, $now ) ) {
				continue;
			}

			if ( ! $this->optional_dimension_matches( $request->logistics_profile_id, $card['logistics_profile_id'] ?? null ) ) {
				continue;
			}

			if ( ! $this->optional_dimension_matches( $request->supplier_id, $card['supplier_id'] ?? null ) ) {
				continue;
			}

			if ( ! $this->optional_dimension_matches( $request->origin_id, $card['origin_id'] ?? null ) ) {
				continue;
			}

			$candidates[] = [
				'card'        => $card,
				'specificity' => $this->specificity_score(
					$card,
					$request->logistics_profile_id,
					$request->supplier_id,
					$request->origin_id
				),
			];
		}

		if ( [] === $candidates ) {
			return RateQuoteResult::failure(
				self::ERROR_NO_MATCHING_RATE_CARD,
				__( 'No matching active rate card found. Delivery cannot be priced.', 'cetech-woocommerce-delivery-engine' )
			);
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

		$winner      = $candidates[0]['card'];
		$charge_type = (string) ( $winner['charge_type'] ?? '' );

		if ( ! array_key_exists( 'base_amount', $winner ) || null === $winner['base_amount'] || '' === $winner['base_amount'] ) {
			return RateQuoteResult::failure(
				self::ERROR_INVALID_AMOUNT,
				__( 'Matched rate card is missing a monetary base amount. Delivery cannot be priced.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$base_amount = (string) $winner['base_amount'];

		if ( ! is_numeric( $base_amount ) ) {
			return RateQuoteResult::failure(
				self::ERROR_INVALID_AMOUNT,
				__( 'Matched rate card has a non-numeric base amount. Delivery cannot be priced as free.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if ( (float) $base_amount < 0 ) {
			return RateQuoteResult::failure(
				self::ERROR_NEGATIVE_AMOUNT,
				__( 'Matched rate card has an invalid or negative base amount.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		if (
			RateCardChargeType::FixedPerShipment->value !== $charge_type
			&& RateCardChargeType::FixedPerItem->value !== $charge_type
		) {
			return RateQuoteResult::failure(
				self::ERROR_UNSUPPORTED_CHARGE_TYPE,
				__( 'Matched rate card uses an unsupported charge type.', 'cetech-woocommerce-delivery-engine' )
			);
		}

		$quoted_amount = $this->calculate_amount( $charge_type, $base_amount, $request->quantity );
		$money         = new Money( $quoted_amount, $request->currency );
		$line          = new RateQuoteLine( $charge_type, $money, $request->quantity );

		return RateQuoteResult::success(
			$money,
			$line,
			(int) ( $winner['id'] ?? 0 ),
			(string) ( $winner['internal_code'] ?? '' ),
			$charge_type,
			$this->build_explanation( $winner, (int) $candidates[0]['specificity'], count( $candidates ) )
		);
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

	private function optional_dimension_matches( ?int $test_value, mixed $card_value ): bool {
		$card_int = null === $card_value || '' === $card_value ? null : (int) $card_value;

		if ( null === $test_value || $test_value <= 0 ) {
			return true;
		}

		if ( null === $card_int || $card_int <= 0 ) {
			return true;
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
			$parts[] = __( 'Selected from wildcard optional dimensions.', 'cetech-woocommerce-delivery-engine' );
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
