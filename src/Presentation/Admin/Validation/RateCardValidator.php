<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin\Validation;

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\RateCardChargeType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;

final class RateCardValidator {

	public function __construct(
		private DeliveryOfferRepositoryInterface $delivery_offer_repository,
		private DestinationZoneRepositoryInterface $destination_zone_repository,
		private LogisticsProfileRepositoryInterface $logistics_profile_repository,
		private SupplierRepositoryInterface $supplier_repository,
		private OriginRepositoryInterface $origin_repository
	) {
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate( array $input, ?int $existing_id = null ): array {
		unset( $existing_id );

		$errors = [];

		$code = AdminFormHelper::sanitize_code( (string) ( $input['code'] ?? '' ) );

		if ( '' === $code ) {
			$errors['code'] = __( 'Code is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! AdminFormHelper::is_valid_code( $code ) ) {
			$errors['code'] = __( 'Code may contain lowercase letters, numbers, underscores, and hyphens only.', 'cetech-woocommerce-delivery-engine' );
		}

		$delivery_offer_id = isset( $input['delivery_offer_id'] ) ? (int) $input['delivery_offer_id'] : 0;

		if ( $delivery_offer_id <= 0 ) {
			$errors['delivery_offer_id'] = __( 'Delivery offer is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( null === $this->delivery_offer_repository->findById( $delivery_offer_id ) ) {
			$errors['delivery_offer_id'] = __( 'Selected delivery offer does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$destination_zone_id = isset( $input['destination_zone_id'] ) ? (int) $input['destination_zone_id'] : 0;

		if ( $destination_zone_id <= 0 ) {
			$errors['destination_zone_id'] = __( 'Destination zone is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( null === $this->destination_zone_repository->findById( $destination_zone_id ) ) {
			$errors['destination_zone_id'] = __( 'Selected destination zone does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$logistics_profile_id = $this->nullable_positive_int( $input['logistics_profile_id'] ?? null );

		if ( null !== $logistics_profile_id && null === $this->logistics_profile_repository->findById( $logistics_profile_id ) ) {
			$errors['logistics_profile_id'] = __( 'Selected logistics profile does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$supplier_id = $this->nullable_positive_int( $input['supplier_id'] ?? null );

		if ( null !== $supplier_id && null === $this->supplier_repository->findById( $supplier_id ) ) {
			$errors['supplier_id'] = __( 'Selected supplier does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$origin_id = $this->nullable_positive_int( $input['origin_id'] ?? null );

		if ( null !== $origin_id ) {
			$origin = $this->origin_repository->findById( $origin_id );

			if ( null === $origin ) {
				$errors['origin_id'] = __( 'Selected origin does not exist.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( null !== $supplier_id && (int) ( $origin['supplier_id'] ?? 0 ) !== $supplier_id ) {
				$errors['origin_id'] = __( 'Selected origin does not belong to the selected supplier.', 'cetech-woocommerce-delivery-engine' );
			}
		}

		$charge_type = (string) ( $input['charge_type'] ?? '' );

		if ( ! $this->is_valid_charge_type( $charge_type ) ) {
			$errors['charge_type'] = __( 'Invalid charge type selected.', 'cetech-woocommerce-delivery-engine' );
		}

		$base_amount = trim( (string) ( $input['base_amount'] ?? '' ) );

		if ( '' === $base_amount || ! is_numeric( $base_amount ) ) {
			$errors['base_amount'] = __( 'Base amount must be a numeric value.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( (float) $base_amount < 0 ) {
			$errors['base_amount'] = __( 'Base amount must be zero or greater.', 'cetech-woocommerce-delivery-engine' );
		}

		$currency_code = strtoupper( trim( (string) ( $input['currency_code'] ?? '' ) ) );

		if ( '' === $currency_code ) {
			$errors['currency_code'] = __( 'Currency code is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! preg_match( '/^[A-Z]{3}$/', $currency_code ) ) {
			$errors['currency_code'] = __( 'Currency code must be a 3-letter ISO code.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( ! isset( $input['priority'] ) || '' === (string) $input['priority'] ) {
			$errors['priority'] = __( 'Priority is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! is_numeric( $input['priority'] ) ) {
			$errors['priority'] = __( 'Priority must be an integer.', 'cetech-woocommerce-delivery-engine' );
		}

		$status = (string) ( $input['status'] ?? '' );

		if ( ! $this->is_valid_status( $status ) ) {
			$errors['status'] = __( 'Invalid status selected.', 'cetech-woocommerce-delivery-engine' );
		}

		$effective_from_raw = $input['effective_from'] ?? null;
		$effective_to_raw   = $input['effective_to'] ?? null;
		$effective_from     = $this->parse_datetime( $effective_from_raw );
		$effective_to       = $this->parse_datetime( $effective_to_raw );

		if ( $this->is_supplied_date( $effective_from_raw ) && null === $effective_from ) {
			$errors['effective_from'] = __( 'Effective from must be a valid date.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( $this->is_supplied_date( $effective_to_raw ) && null === $effective_to ) {
			$errors['effective_to'] = __( 'Effective to must be a valid date.', 'cetech-woocommerce-delivery-engine' );
		}

		if ( null !== $effective_from && null !== $effective_to && $effective_from > $effective_to ) {
			$errors['effective_from'] = __( 'Effective from must not be after effective to.', 'cetech-woocommerce-delivery-engine' );
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate_test_input( array $input ): array {
		$errors = [];

		$delivery_offer_id = isset( $input['test_delivery_offer_id'] ) ? (int) $input['test_delivery_offer_id'] : 0;

		if ( $delivery_offer_id <= 0 ) {
			$errors['test_delivery_offer_id'] = __( 'Delivery offer is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( null === $this->delivery_offer_repository->findById( $delivery_offer_id ) ) {
			$errors['test_delivery_offer_id'] = __( 'Selected delivery offer does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$destination_zone_id = isset( $input['test_destination_zone_id'] ) ? (int) $input['test_destination_zone_id'] : 0;

		if ( $destination_zone_id <= 0 ) {
			$errors['test_destination_zone_id'] = __( 'Destination zone is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( null === $this->destination_zone_repository->findById( $destination_zone_id ) ) {
			$errors['test_destination_zone_id'] = __( 'Selected destination zone does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$logistics_profile_id = $this->nullable_positive_int( $input['test_logistics_profile_id'] ?? null );

		if ( null !== $logistics_profile_id && null === $this->logistics_profile_repository->findById( $logistics_profile_id ) ) {
			$errors['test_logistics_profile_id'] = __( 'Selected logistics profile does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$supplier_id = $this->nullable_positive_int( $input['test_supplier_id'] ?? null );

		if ( null !== $supplier_id && null === $this->supplier_repository->findById( $supplier_id ) ) {
			$errors['test_supplier_id'] = __( 'Selected supplier does not exist.', 'cetech-woocommerce-delivery-engine' );
		}

		$origin_id = $this->nullable_positive_int( $input['test_origin_id'] ?? null );

		if ( null !== $origin_id ) {
			$origin = $this->origin_repository->findById( $origin_id );

			if ( null === $origin ) {
				$errors['test_origin_id'] = __( 'Selected origin does not exist.', 'cetech-woocommerce-delivery-engine' );
			} elseif ( null !== $supplier_id && (int) ( $origin['supplier_id'] ?? 0 ) !== $supplier_id ) {
				$errors['test_origin_id'] = __( 'Selected origin does not belong to the selected supplier.', 'cetech-woocommerce-delivery-engine' );
			}
		}

		$quantity = isset( $input['test_quantity'] ) ? (int) $input['test_quantity'] : 0;

		if ( $quantity <= 0 ) {
			$errors['test_quantity'] = __( 'Quantity must be a positive integer.', 'cetech-woocommerce-delivery-engine' );
		}

		$currency_code = strtoupper( trim( (string) ( $input['test_currency_code'] ?? '' ) ) );

		if ( '' === $currency_code ) {
			$errors['test_currency_code'] = __( 'Currency code is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! preg_match( '/^[A-Z]{3}$/', $currency_code ) ) {
			$errors['test_currency_code'] = __( 'Currency code must be a 3-letter ISO code.', 'cetech-woocommerce-delivery-engine' );
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate_quote_test_input( array $input ): array {
		return $this->validate_test_input(
			[
				'test_delivery_offer_id'    => $input['quote_delivery_offer_id'] ?? null,
				'test_destination_zone_id'  => $input['quote_destination_zone_id'] ?? null,
				'test_logistics_profile_id' => $input['quote_logistics_profile_id'] ?? null,
				'test_supplier_id'          => $input['quote_supplier_id'] ?? null,
				'test_origin_id'            => $input['quote_origin_id'] ?? null,
				'test_quantity'             => $input['quote_quantity'] ?? null,
				'test_currency_code'        => $input['quote_currency_code'] ?? null,
			]
		);
	}

	private function nullable_positive_int( mixed $value ): ?int {
		if ( null === $value || '' === $value || '0' === (string) $value ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}

	private function is_supplied_date( mixed $value ): bool {
		if ( null === $value ) {
			return false;
		}

		return '' !== trim( (string) $value );
	}

	private function parse_datetime( mixed $value ): ?int {
		if ( ! $this->is_supplied_date( $value ) ) {
			return null;
		}

		$string = trim( (string) $value );

		if ( '' === $string ) {
			return null;
		}

		$timestamp = strtotime( $string );

		return false === $timestamp ? null : $timestamp;
	}

	private function is_valid_charge_type( string $charge_type ): bool {
		foreach ( RateCardChargeType::cases() as $case ) {
			if ( $case->value === $charge_type ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_status( string $status ): bool {
		foreach ( RecordStatus::cases() as $case ) {
			if ( $case->value === $status ) {
				return true;
			}
		}

		return false;
	}
}
