<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin\Validation;

use CetechDeliveryEngine\Domain\Enum\CarrierVisibility;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;

final class DeliveryOfferValidator {

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate( array $input, ?int $existing_id = null ): array {
		$errors = [];

		$raw  = trim( (string) ( $input['code'] ?? '' ) );
		$code = AdminFormHelper::sanitize_code( $raw );

		if ( '' === $code ) {
			$errors['code'] = '' !== $raw
				? __( 'Code may contain lowercase letters, numbers, underscores, and hyphens only.', 'cetech-woocommerce-delivery-engine' )
				: __( 'Code is required.', 'cetech-woocommerce-delivery-engine' );
		} elseif ( ! AdminFormHelper::is_valid_code( $code ) ) {
			$errors['code'] = __( 'Code may contain lowercase letters, numbers, underscores, and hyphens only.', 'cetech-woocommerce-delivery-engine' );
		}

		$public_label = trim( (string) ( $input['public_label'] ?? '' ) );

		if ( '' === $public_label ) {
			$errors['public_label'] = __( 'Public label is required.', 'cetech-woocommerce-delivery-engine' );
		}

		$route = (string) ( $input['route'] ?? '' );

		if ( ! $this->is_valid_route( $route ) ) {
			$errors['route'] = __( 'Invalid route selected.', 'cetech-woocommerce-delivery-engine' );
		}

		$carrier_visibility = (string) ( $input['carrier_visibility'] ?? '' );

		if ( ! $this->is_valid_carrier_visibility( $carrier_visibility ) ) {
			$errors['carrier_visibility'] = __( 'Invalid carrier visibility selected.', 'cetech-woocommerce-delivery-engine' );
		}

		$carrier_name = trim( (string) ( $input['carrier_display_name'] ?? '' ) );

		if ( CarrierVisibility::Named->value === $carrier_visibility && '' === $carrier_name ) {
			$errors['carrier_display_name'] = __( 'Carrier display name is required when carrier visibility is Named.', 'cetech-woocommerce-delivery-engine' );
		}

		$status = (string) ( $input['status'] ?? '' );

		if ( ! $this->is_valid_status( $status ) ) {
			$errors['status'] = __( 'Invalid status selected.', 'cetech-woocommerce-delivery-engine' );
		}

		$this->validate_day_range( $input, 'processing_min_days', 'processing_max_days', __( 'Processing', 'cetech-woocommerce-delivery-engine' ), $errors );
		$this->validate_day_range( $input, 'transit_min_days', 'transit_max_days', __( 'Transit', 'cetech-woocommerce-delivery-engine' ), $errors );
		$this->validate_day_range( $input, 'final_mile_min_days', 'final_mile_max_days', __( 'Final mile', 'cetech-woocommerce-delivery-engine' ), $errors );

		$priority = $input['display_priority'] ?? '';

		if ( '' !== $priority && ( ! is_numeric( $priority ) || (int) $priority < 0 ) ) {
			$errors['display_priority'] = __( 'Display priority must be a non-negative integer.', 'cetech-woocommerce-delivery-engine' );
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, string> $errors
	 */
	private function validate_day_range(
		array $input,
		string $min_key,
		string $max_key,
		string $label,
		array &$errors
	): void {
		$min_raw = $input[ $min_key ] ?? '';
		$max_raw = $input[ $max_key ] ?? '';

		if ( '' === $min_raw && '' === $max_raw ) {
			return;
		}

		if ( '' !== $min_raw && ( ! is_numeric( $min_raw ) || (int) $min_raw < 0 ) ) {
			$errors[ $min_key ] = sprintf(
				/* translators: %s: duration label */
				__( '%s minimum days must be a non-negative integer.', 'cetech-woocommerce-delivery-engine' ),
				$label
			);
		}

		if ( '' !== $max_raw && ( ! is_numeric( $max_raw ) || (int) $max_raw < 0 ) ) {
			$errors[ $max_key ] = sprintf(
				/* translators: %s: duration label */
				__( '%s maximum days must be a non-negative integer.', 'cetech-woocommerce-delivery-engine' ),
				$label
			);
		}

		if ( isset( $errors[ $min_key ] ) || isset( $errors[ $max_key ] ) ) {
			return;
		}

		if ( '' !== $min_raw && '' !== $max_raw && (int) $min_raw > (int) $max_raw ) {
			$errors[ $min_key ] = sprintf(
				/* translators: %s: duration label */
				__( '%s minimum days cannot exceed maximum days.', 'cetech-woocommerce-delivery-engine' ),
				$label
			);
		}
	}

	private function is_valid_route( string $route ): bool {
		foreach ( DeliveryRoute::cases() as $case ) {
			if ( $case->value === $route ) {
				return true;
			}
		}

		return false;
	}

	private function is_valid_carrier_visibility( string $visibility ): bool {
		foreach ( CarrierVisibility::cases() as $case ) {
			if ( $case->value === $visibility ) {
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

	public static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return max( 0, (int) $value );
	}
}
