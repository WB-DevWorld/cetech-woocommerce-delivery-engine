<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\RateCard;

interface RateCardRepositoryInterface {

	/**
	 * @return array<string, mixed>|null
	 */
	public function findById( int $id ): ?array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function findByCode( string $code ): ?array;

	/**
	 * @param array<string, mixed> $data
	 */
	public function save( array $data ): int;

	/**
	 * @param array<string, mixed> $criteria
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list( array $criteria = [] ): array;

	public function softDelete( int $id ): bool;

	public function hardDelete( int $id ): bool;

	public function count_all(): int;

	public function countByDeliveryOfferId( int $delivery_offer_id ): int;

	public function countByDestinationZoneId( int $destination_zone_id ): int;

	/**
	 * Complete Active rate-card count for one Delivery Area. Not clamped by list().
	 */
	public function countActiveByDestinationZoneId( int $destination_zone_id ): int;

	/**
	 * Complete Active rate cards for one Delivery Area. Not clamped by list().
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listActiveByDestinationZoneId( int $destination_zone_id ): array;

	/**
	 * Complete Active rate-card count for one Delivery Option. Not clamped by list().
	 */
	public function countActiveByDeliveryOfferId( int $delivery_offer_id ): int;

	public function countByLogisticsProfileId( int $logistics_profile_id ): int;

	public function countOrderSnapshotReferences( int $rate_card_id ): int;

	/**
	 * Active rate cards matching exact offer, zone, and currency (quote engine prefilter).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listActiveForQuoteMatch(
		int $delivery_offer_id,
		int $destination_zone_id,
		string $currency_code
	): array;
}
