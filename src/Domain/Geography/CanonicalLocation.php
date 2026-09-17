<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\Geography;

use CetechDeliveryEngine\Domain\Enum\GeographyLocationType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;

/**
 * Provider-neutral canonical place identity.
 *
 * GeoNames IDs, WooCommerce codes and OSM IDs are mappings, never this key.
 */
final class CanonicalLocation {

	public function __construct(
		public readonly int $id,
		public readonly string $location_key,
		public readonly string $country_code,
		public readonly ?int $parent_location_id,
		public readonly GeographyLocationType $location_type,
		public readonly ?int $administrative_level,
		public readonly string $canonical_name,
		public readonly string $normalized_name,
		public readonly string $ascii_name,
		public readonly ?float $latitude,
		public readonly ?float $longitude,
		public readonly RecordStatus $status,
		public readonly string $ancestry_path,
		public readonly int $generation = 0,
		public readonly string $draft_json = '',
		public readonly string $generation_token = '',
		public readonly string $draft_generation_token = ''
	) {
	}

	public function isActive(): bool {
		return RecordStatus::Active === $this->status;
	}

	public function isCountry(): bool {
		return GeographyLocationType::Country === $this->location_type;
	}

	public function isAdministrative(): bool {
		return GeographyLocationType::Administrative === $this->location_type;
	}

	public function isLocality(): bool {
		return GeographyLocationType::Locality === $this->location_type;
	}

	public function specificityRank(): int {
		if ( $this->isLocality() ) {
			return 3;
		}

		if ( $this->isAdministrative() ) {
			$level = $this->administrative_level ?? 1;

			return 1 + min( 2, max( 1, $level ) );
		}

		return 1;
	}

	/**
	 * Customer-safe location fields. No provider internals, numeric IDs, or cost data.
	 *
	 * @return array<string, mixed>
	 */
	public function publicPayload(): array {
		return [
			'key'     => $this->location_key,
			'country' => $this->country_code,
			'type'    => $this->location_type->value,
			'name'    => $this->canonical_name,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow( array $row ): self {
		$type = GeographyLocationType::tryFrom( (string) ( $row['location_type'] ?? '' ) ) ?? GeographyLocationType::Locality;
		$status = RecordStatus::tryFrom( (string) ( $row['status'] ?? '' ) ) ?? RecordStatus::Inactive;
		$parent = isset( $row['parent_location_id'] ) && '' !== (string) $row['parent_location_id']
			? (int) $row['parent_location_id']
			: null;
		$level = isset( $row['administrative_level'] ) && '' !== (string) $row['administrative_level']
			? (int) $row['administrative_level']
			: null;

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['location_key'] ?? '' ),
			strtoupper( (string) ( $row['country_code'] ?? '' ) ),
			( $parent ?? 0 ) > 0 ? $parent : null,
			$type,
			( $level ?? 0 ) > 0 ? $level : null,
			(string) ( $row['canonical_name'] ?? '' ),
			(string) ( $row['normalized_name'] ?? '' ),
			(string) ( $row['ascii_name'] ?? '' ),
			isset( $row['latitude'] ) && '' !== (string) $row['latitude'] ? (float) $row['latitude'] : null,
			isset( $row['longitude'] ) && '' !== (string) $row['longitude'] ? (float) $row['longitude'] : null,
			$status,
			(string) ( $row['ancestry_path'] ?? '' ),
			(int) ( $row['generation'] ?? 0 ),
			(string) ( $row['draft_json'] ?? '' ),
			(string) ( $row['generation_token'] ?? '' ),
			(string) ( $row['draft_generation_token'] ?? '' )
		);
	}
}
