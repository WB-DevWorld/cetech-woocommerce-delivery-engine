<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Presentation\Admin\Validation;

use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Presentation\Admin\AdminFormHelper;
use CetechDeliveryEngine\Application\Destination\WooCommerceCountryCatalog;

final class PickupLocationValidator {

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

		$name = trim( (string) ( $input['location_name'] ?? '' ) );

		if ( '' === $name ) {
			$errors['location_name'] = __( 'Location name is required.', 'cetech-woocommerce-delivery-engine' );
		}

		$country_code = WooCommerceCountryCatalog::canonical_iso2( (string) ( $input['country_code'] ?? '' ) );
		$input['country_code'] = $country_code;

		if ( '' !== $country_code && ! preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
			$errors['country_code'] = __( 'Country must be selected from the country list.', 'cetech-woocommerce-delivery-engine' );
		}

		$email = trim( (string) ( $input['contact_email'] ?? '' ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			$errors['contact_email'] = __( 'Contact email is not valid.', 'cetech-woocommerce-delivery-engine' );
		}

		$status = (string) ( $input['status'] ?? '' );

		if ( ! $this->is_valid_status( $status ) ) {
			$errors['status'] = __( 'Invalid status selected.', 'cetech-woocommerce-delivery-engine' );
		}

		return $errors;
	}

	/**
	 * @param array<string, mixed> $input
	 */
	public function encode_public_address( array $input ): ?string {
		$parts = [
			'line1'        => trim( (string) ( $input['address_line_1'] ?? '' ) ),
			'line2'        => trim( (string) ( $input['address_line_2'] ?? '' ) ),
			'city'         => trim( (string) ( $input['city'] ?? '' ) ),
			'region'       => trim( (string) ( $input['region'] ?? '' ) ),
			'country_code' => WooCommerceCountryCatalog::canonical_iso2( (string) ( $input['country_code'] ?? '' ) ),
			'postcode'     => trim( (string) ( $input['postcode'] ?? '' ) ),
		];

		if ( '' === implode( '', $parts ) ) {
			return null;
		}

		$encoded = wp_json_encode( $parts );

		return false !== $encoded ? $encoded : null;
	}

	/**
	 * @return array<string, string>
	 */
	public function decode_public_address( ?string $stored ): array {
		if ( null === $stored || '' === $stored ) {
			return $this->empty_address();
		}

		$decoded = json_decode( $stored, true );

		if ( ! is_array( $decoded ) ) {
			return [
				'address_line_1' => $stored,
				'address_line_2' => '',
				'city'           => '',
				'region'         => '',
				'country_code'   => '',
				'postcode'       => '',
			];
		}

		return [
			'address_line_1' => (string) ( $decoded['line1'] ?? '' ),
			'address_line_2' => (string) ( $decoded['line2'] ?? '' ),
			'city'           => (string) ( $decoded['city'] ?? '' ),
			'region'         => (string) ( $decoded['region'] ?? '' ),
			'country_code'   => (string) ( $decoded['country_code'] ?? '' ),
			'postcode'       => (string) ( $decoded['postcode'] ?? '' ),
		];
	}

	public function address_summary( ?string $stored ): string {
		$address = $this->decode_public_address( $stored );
		$parts   = array_filter(
			[
				$address['address_line_1'],
				$address['address_line_2'],
				trim( $address['city'] . ( '' !== $address['region'] ? ', ' . $address['region'] : '' ) . ( '' !== $address['postcode'] ? ' ' . $address['postcode'] : '' ) ),
				$address['country_code'],
			]
		);

		return [] === $parts ? '—' : implode( ' · ', $parts );
	}

	/**
	 * @return array<string, string>
	 */
	private function empty_address(): array {
		return [
			'address_line_1' => '',
			'address_line_2' => '',
			'city'           => '',
			'region'         => '',
			'country_code'   => '',
			'postcode'       => '',
		];
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
