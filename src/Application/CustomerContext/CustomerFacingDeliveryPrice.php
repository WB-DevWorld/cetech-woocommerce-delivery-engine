<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\CustomerContext;

use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;

/**
 * Customer-safe PDP delivery price. Contains no rate-card, supplier, origin or profile IDs.
 */
final class CustomerFacingDeliveryPrice {

	public const BASIS_FREE = 'free';

	public const BASIS_PER_ITEM = 'per_item';

	public const BASIS_PER_SHIPMENT = 'per_shipment';

	public function __construct(
		public readonly string $amount,
		public readonly string $currency,
		public readonly string $text,
		public readonly string $basis
	) {
	}

	public static function free( string $currency ): self {
		$currency = strtoupper( $currency );

		return new self(
			'0.0000',
			$currency,
			self::free_label(),
			self::BASIS_FREE
		);
	}

	public static function from_quoted_amount( string $amount, string $currency, ?string $charge_type ): self {
		$currency = strtoupper( $currency );
		$basis    = self::basis_from_charge_type( $charge_type );

		if ( self::is_zero_amount( $amount ) ) {
			return new self( self::normalize_amount( $amount ), $currency, self::free_label(), self::BASIS_FREE );
		}

		return new self(
			self::normalize_amount( $amount ),
			$currency,
			self::format_amount( $amount, $currency ),
			$basis
		);
	}

	/**
	 * @return array{
	 *     price_amount: string,
	 *     price_currency: string,
	 *     price_text: string,
	 *     price_basis: string
	 * }
	 */
	public function to_public_array(): array {
		return [
			'price_amount'   => $this->amount,
			'price_currency' => $this->currency,
			'price_text'     => $this->text,
			'price_basis'    => $this->basis,
		];
	}

	public static function is_zero_amount( string $amount ): bool {
		if ( function_exists( 'bccomp' ) ) {
			return 0 === bccomp( $amount, '0', 4 );
		}

		return abs( (float) $amount ) < 0.00005;
	}

	private static function basis_from_charge_type( ?string $charge_type ): string {
		return match ( $charge_type ) {
			RateCardChargeType::FixedPerItem->value => self::BASIS_PER_ITEM,
			RateCardChargeType::FixedPerShipment->value => self::BASIS_PER_SHIPMENT,
			default => self::BASIS_PER_SHIPMENT,
		};
	}

	private static function normalize_amount( string $amount ): string {
		if ( function_exists( 'bcadd' ) ) {
			return bcadd( $amount, '0', 4 );
		}

		return number_format( (float) $amount, 4, '.', '' );
	}

	private static function format_amount( string $amount, string $currency ): string {
		if ( function_exists( 'wc_price' ) ) {
			$formatted = (string) wc_price(
				(float) $amount,
				[
					'currency' => $currency,
				]
			);

			$plain = wp_strip_all_tags( html_entity_decode( $formatted, ENT_QUOTES, 'UTF-8' ) );
			$plain = trim( preg_replace( '/\s+/', ' ', $plain ) ?? $plain );

			if ( '' !== $plain ) {
				return $plain;
			}
		}

		return strtoupper( $currency ) . ' ' . number_format( (float) $amount, 2, '.', '' );
	}

	private static function free_label(): string {
		return __( 'Free', 'cetech-woocommerce-delivery-engine' );
	}
}
