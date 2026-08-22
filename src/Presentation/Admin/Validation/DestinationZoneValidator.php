<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin\Validation;

use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;

final class DestinationZoneValidator {

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, string>
	 */
	public function validate( array $input, ?int $existing_id = null ): array {
		unset( $existing_id );

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

		$name = trim( (string) ( $input['name'] ?? '' ) );

		if ( '' === $name ) {
			$errors['name'] = __( 'Name is required.', 'cetech-woocommerce-delivery-engine' );
		}

		$status = (string) ( $input['status'] ?? '' );

		if ( ! $this->is_valid_status( $status ) ) {
			$errors['status'] = __( 'Invalid status selected.', 'cetech-woocommerce-delivery-engine' );
		}

		$priority = $input['priority'] ?? '';

		if ( '' !== $priority && ! is_numeric( $priority ) ) {
			$errors['priority'] = __( 'Priority must be an integer.', 'cetech-woocommerce-delivery-engine' );
		}

		return $errors;
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
