<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\CustomerContext;

/**
 * Versioned, key-ordered, insertion-order-independent location identity hashes.
 *
 * Raw addresses, recipient names, company and phone never appear in the
 * canonical payload beyond the non-PII geography/street identity fields.
 */
final class LocationIdentity {

	public const CANONICAL_VERSION = 1;

	public const KIND_MATCHING = 'matching';

	public const KIND_DELIVERY_LOCATION = 'delivery_location';

	public const DESTINATION_SEGMENT_LENGTH = 16;

	/**
	 * @param array<string, string> $fields
	 */
	public static function hash( string $kind, array $fields ): string {
		$payload = [
			'kind' => $kind,
			'v'    => self::CANONICAL_VERSION,
		];

		foreach ( $fields as $key => $value ) {
			$payload[ (string) $key ] = (string) $value;
		}

		ksort( $payload, SORT_STRING );

		$encoded = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			throw new \RuntimeException( 'Location identity canonical encoding failed.' );
		}

		return hash( 'sha256', $encoded );
	}

	public static function matching(
		string $country,
		string $state,
		string $city,
		string $postcode,
		string $canonical_location_key = ''
	): string {
		return self::hash(
			self::KIND_MATCHING,
			[
				'canonical_location_key' => $canonical_location_key,
				'city'                   => $city,
				'country'                => $country,
				'postcode'               => $postcode,
				'state'                  => $state,
			]
		);
	}

	public static function deliveryLocation(
		string $country,
		string $state,
		string $city,
		string $postcode,
		string $address_1,
		string $address_2
	): string {
		return self::hash(
			self::KIND_DELIVERY_LOCATION,
			[
				'address_1' => $address_1,
				'address_2' => $address_2,
				'city'      => $city,
				'country'   => $country,
				'postcode'  => $postcode,
				'state'     => $state,
			]
		);
	}

	public static function destinationSegment( string $identity_hash, string $prefix = '' ): string {
		$hex = strtolower( $identity_hash );

		return $prefix . substr( $hex, 0, self::DESTINATION_SEGMENT_LENGTH );
	}

	public static function containsRawPii( string $value ): bool {
		$lower = strtolower( $value );

		foreach ( [ 'address_1', 'address_2', 'first_name', 'last_name', 'phone', 'company' ] as $needle ) {
			if ( str_contains( $lower, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
