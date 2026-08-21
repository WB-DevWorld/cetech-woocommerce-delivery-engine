<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\Bulk\Portability;

use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;

/**
 * Maps stable internal_code values to local database IDs. Source-site IDs are never identity.
 */
final class EntityCodeResolver {

	public function __construct(
		private readonly ?DeliveryOfferRepositoryInterface $offers = null,
		private readonly ?LogisticsProfileRepositoryInterface $logistics = null,
		private readonly ?SupplierRepositoryInterface $suppliers = null,
		private readonly ?OriginRepositoryInterface $origins = null,
		private readonly ?PickupLocationRepositoryInterface $pickups = null
	) {
	}

	/**
	 * @param list<int|string> $members
	 * @return list<int>
	 */
	public function resolve_offer_ids( array $members ): array {
		$ids = [];
		foreach ( $members as $member ) {
			$id = $this->resolve_offer_id( $member );
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public function resolve_offer_id( mixed $member ): ?int {
		if ( is_int( $member ) || ( is_string( $member ) && ctype_digit( $member ) ) ) {
			$id = (int) $member;
			return $id > 0 ? $id : null;
		}
		$code = trim( (string) $member );
		if ( '' === $code || ! $this->offers instanceof DeliveryOfferRepositoryInterface ) {
			return null;
		}
		$row = $this->offers->findByCode( $code );

		return is_array( $row ) ? max( 0, (int) ( $row['id'] ?? 0 ) ) ?: null : null;
	}

	public function resolve_scalar_value( string $field_key, mixed $value ): mixed {
		if ( null === $value || '' === $value ) {
			return $value;
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return (int) $value;
		}

		$code = trim( (string) $value );
		$row  = match ( $field_key ) {
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => $this->logistics?->findByCode( $code ),
			ConfigurationFieldKey::SUPPLIER_ID => $this->suppliers?->findByCode( $code ),
			ConfigurationFieldKey::ORIGIN_ID => $this->origins?->findByCode( $code ),
			default => null,
		};

		if ( ! is_array( $row ) ) {
			return $value;
		}

		$id = (int) ( $row['id'] ?? 0 );

		return $id > 0 ? $id : $value;
	}

	public function code_for_id( string $kind, int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}
		$row = match ( $kind ) {
			'offer' => $this->offers?->findById( $id ),
			'logistics' => $this->logistics?->findById( $id ),
			'supplier' => $this->suppliers?->findById( $id ),
			'origin' => $this->origins?->findById( $id ),
			'pickup' => $this->pickups?->findById( $id ),
			default => null,
		};

		return is_array( $row ) ? (string) ( $row['internal_code'] ?? '' ) : '';
	}
}
