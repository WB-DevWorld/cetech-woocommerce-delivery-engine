<?php

declare(strict_types=1);

use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Tests\Support\RateCardActiveListingTrait;

/**
 * Observable Delivery Charge row used only by the COR-006 worker proof.
 */
final class Cor006ObservableRateStore implements RateCardRepositoryInterface {

	use RateCardActiveListingTrait;

	public function __construct( private readonly PDO $pdo ) {
	}

	public function findById( int $id ): ?array {
		$statement = $this->pdo->prepare( 'SELECT * FROM cor006_source_amount WHERE id = ?' );
		$statement->execute( [ $id ] );
		$row = $statement->fetch( PDO::FETCH_ASSOC );

		return is_array( $row ) ? $row : null;
	}

	public function findByCode( string $code ): ?array {
		return null;
	}

	public function save( array $data ): int {
		$statement = $this->pdo->prepare( 'UPDATE cor006_source_amount SET base_amount = ?, status = ?, priority = ?, internal_code = ? WHERE id = ?' );
		$statement->execute(
			[
				(string) ( $data['base_amount'] ?? '' ),
				(string) ( $data['status'] ?? 'active' ),
				(int) ( $data['priority'] ?? 100 ),
				(string) ( $data['internal_code'] ?? '' ),
				(int) ( $data['id'] ?? 0 ),
			]
		);

		return (int) ( $data['id'] ?? 0 );
	}

	public function list( array $criteria = [] ): array {
		unset( $criteria );

		return [];
	}

	public function softDelete( int $id ): bool {
		unset( $id );

		return false;
	}

	public function hardDelete( int $id ): bool {
		unset( $id );

		return false;
	}

	public function count_all(): int {
		return 1;
	}

	public function countByDeliveryOfferId( int $delivery_offer_id ): int {
		unset( $delivery_offer_id );

		return 0;
	}

	public function countByDestinationZoneId( int $destination_zone_id ): int {
		unset( $destination_zone_id );

		return 0;
	}

	public function countByLogisticsProfileId( int $logistics_profile_id ): int {
		unset( $logistics_profile_id );

		return 0;
	}

	public function countOrderSnapshotReferences( int $rate_card_id ): int {
		unset( $rate_card_id );

		return 0;
	}

	public function listActiveForQuoteMatch( int $delivery_offer_id, int $destination_zone_id, string $currency_code ): array {
		unset( $delivery_offer_id, $destination_zone_id, $currency_code );

		return [];
	}
}

final class Cor006OfferStore implements DeliveryOfferRepositoryInterface {

	public function findById( int $id ): ?array {
		unset( $id );

		return null;
	}

	public function findByCode( string $code ): ?array {
		unset( $code );

		return null;
	}

	public function save( array $data ): int {
		unset( $data );

		return 0;
	}

	public function list( array $criteria = [] ): array {
		unset( $criteria );

		return [];
	}

	public function softDelete( int $id ): bool {
		unset( $id );

		return false;
	}

	public function hardDelete( int $id ): bool {
		unset( $id );

		return false;
	}

	public function count_all(): int {
		return 0;
	}
}
