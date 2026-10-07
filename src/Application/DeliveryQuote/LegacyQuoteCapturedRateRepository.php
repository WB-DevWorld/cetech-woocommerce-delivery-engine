<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Application\DeliveryQuote;

use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;

/** The unchanged rate engine receives only captured, complete Active rows. */
final readonly class LegacyQuoteCapturedRateRepository implements RateCardRepositoryInterface, \JsonSerializable {
	private array $ranges;
	public function __construct( array $ranges ) { foreach ( $ranges as &$rows ) { foreach ( $rows as &$row ) { $row = \CetechDeliveryEngine\Domain\DeliveryQuote\QuoteJson::detach( $row ); } unset( $row ); } unset( $rows ); $this->ranges = $ranges; }
	public function listActiveForQuoteMatch( int $delivery_offer_id, int $destination_zone_id, string $currency_code ): array { $key = LegacyQuoteSourcePlan::range_key( [ 'delivery_offer_id' => $delivery_offer_id, 'destination_zone_id' => $destination_zone_id, 'base_currency' => $currency_code ] ); if ( ! array_key_exists( $key, $this->ranges ) ) { self::refuse(); } return array_values( array_filter( $this->ranges[$key], static fn( array $row ): bool => 'active' === $row['status'] ) ); }
	public function findById( int $id ): ?array { foreach ( $this->ranges as $rows ) { foreach ( $rows as $row ) { if ( $id === (int) $row['id'] ) { return $row; } } } return null; }
	public function findByCode( string $code ): ?array { foreach ( $this->ranges as $rows ) { foreach ( $rows as $row ) { if ( $code === $row['internal_code'] ) { return $row; } } } return null; }
	public function list( array $criteria = [] ): array { self::refuse(); }
	public function save( array $data ): int { self::refuse(); } public function softDelete( int $id ): bool { self::refuse(); } public function hardDelete( int $id ): bool { self::refuse(); }
	public function count_all(): int { self::refuse(); } public function countByDeliveryOfferId( int $delivery_offer_id ): int { self::refuse(); } public function countByDestinationZoneId( int $destination_zone_id ): int { self::refuse(); }
	public function countActiveByDestinationZoneId( int $destination_zone_id ): int { self::refuse(); } public function listActiveByDestinationZoneId( int $destination_zone_id ): array { self::refuse(); } public function countActiveByDeliveryOfferId( int $delivery_offer_id ): int { self::refuse(); }
	public function countByLogisticsProfileId( int $logistics_profile_id ): int { self::refuse(); } public function countOrderSnapshotReferences( int $rate_card_id ): int { self::refuse(); }
	private static function refuse(): never { throw new \LogicException( 'Captured rates support only exact retained pricing reads.' ); }
	public function jsonSerialize(): never { throw new \LogicException( 'Captured rate facts are private.' ); }
	public function __serialize(): never { throw new \LogicException( 'Captured rate facts are private.' ); }
}
