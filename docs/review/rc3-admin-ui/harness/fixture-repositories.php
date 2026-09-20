<?php

declare(strict_types=1);

use CetechDeliveryEngine\Domain\Audit\AuditLogRepositoryInterface;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;

final class Rc3FixtureStore {

	/** @var array<string, array<int, array<string, mixed>>> */
	private array $tables = [];

	public function seed( string $table, int $id, array $row ): void {
		$row['id'] = $id;
		$this->tables[ $table ][ $id ] = $row;
	}

	public function find( string $table, int $id ): ?array {
		return $this->tables[ $table ][ $id ] ?? null;
	}

	public function findByCode( string $table, string $code, string $field = 'internal_code' ): ?array {
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			if ( (string) ( $row[ $field ] ?? '' ) === $code ) {
				return $row;
			}
		}

		return null;
	}

	public function save( string $table, array $data ): int {
		$id = (int) ( $data['id'] ?? 0 );
		if ( $id <= 0 ) {
			$ids = array_keys( $this->tables[ $table ] ?? [] );
			$id  = [] === $ids ? 1 : max( $ids ) + 1;
		}
		$data['id'] = $id;
		$this->tables[ $table ][ $id ] = $data;

		return $id;
	}

	public function list( string $table ): array {
		return array_values( $this->tables[ $table ] ?? [] );
	}

	public function delete( string $table, int $id ): bool {
		unset( $this->tables[ $table ][ $id ] );

		return true;
	}

	public function count( string $table ): int {
		return count( $this->tables[ $table ] ?? [] );
	}

	public function countWhere( string $table, string $field, int $value ): int {
		$count = 0;
		foreach ( $this->tables[ $table ] ?? [] as $row ) {
			if ( (int) ( $row[ $field ] ?? 0 ) === $value ) {
				++$count;
			}
		}

		return $count;
	}
}

final class Rc3OfferRepository implements DeliveryOfferRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'offers', $id ); }
	public function findByCode( string $code ): ?array { return $this->store->findByCode( 'offers', $code ); }
	public function save( array $data ): int { return $this->store->save( 'offers', $data ); }
	public function list( array $criteria = [] ): array { return $this->store->list( 'offers' ); }
	public function softDelete( int $id ): bool { return $this->store->delete( 'offers', $id ); }
	public function hardDelete( int $id ): bool { return $this->softDelete( $id ); }
	public function count_all(): int { return $this->store->count( 'offers' ); }
}

final class Rc3ZoneRepository implements DestinationZoneRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'zones', $id ); }
	public function findByCode( string $code ): ?array { return $this->store->findByCode( 'zones', $code ); }
	public function save( array $data ): int { return $this->store->save( 'zones', $data ); }
	public function list( array $criteria = [] ): array { return $this->store->list( 'zones' ); }
	public function page_after( int $after_id, int $limit = 100, array $criteria = [] ): array {
		$out = [];
		foreach ( $this->store->list( 'zones' ) as $zone ) {
			$id = (int) ( $zone['id'] ?? 0 );
			if ( $id <= $after_id ) {
				continue;
			}
			$out[] = $zone;
			if ( count( $out ) >= max( 1, $limit ) ) {
				break;
			}
		}
		return $out;
	}
	public function softDelete( int $id ): bool { return $this->store->delete( 'zones', $id ); }
	public function hardDelete( int $id ): bool { return $this->softDelete( $id ); }
	public function count_all(): int { return $this->store->count( 'zones' ); }
}

final class Rc3RuleRepository implements DestinationRuleRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function listByZoneId( int $zone_id ): array {
		return array_values( array_filter(
			$this->store->list( 'rules' ),
			static fn ( array $row ): bool => (int) ( $row['destination_zone_id'] ?? 0 ) === $zone_id
		) );
	}
	public function deleteByZoneId( int $zone_id ): bool {
		foreach ( $this->listByZoneId( $zone_id ) as $row ) {
			$this->store->delete( 'rules', (int) $row['id'] );
		}
		return true;
	}
	public function replaceForZone( int $zone_id, array $rules ): bool {
		$this->deleteByZoneId( $zone_id );
		foreach ( $rules as $rule ) {
			$rule['destination_zone_id'] = $zone_id;
			$this->store->save( 'rules', $rule );
		}
		return true;
	}
	public function count_all(): int { return $this->store->count( 'rules' ); }
	public function list( int $limit = 500 ): array { return array_slice( $this->store->list( 'rules' ), 0, $limit ); }
}

final class Rc3RateRepository implements RateCardRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'rates', $id ); }
	public function findByCode( string $code ): ?array { return $this->store->findByCode( 'rates', $code ); }
	public function save( array $data ): int { return $this->store->save( 'rates', $data ); }
	public function list( array $criteria = [] ): array { return $this->store->list( 'rates' ); }
	public function softDelete( int $id ): bool { return $this->store->delete( 'rates', $id ); }
	public function hardDelete( int $id ): bool { return $this->softDelete( $id ); }
	public function count_all(): int { return $this->store->count( 'rates' ); }
	public function countByDeliveryOfferId( int $delivery_offer_id ): int { return $this->store->countWhere( 'rates', 'delivery_offer_id', $delivery_offer_id ); }
	public function countByDestinationZoneId( int $destination_zone_id ): int { return $this->store->countWhere( 'rates', 'destination_zone_id', $destination_zone_id ); }
	public function countByLogisticsProfileId( int $logistics_profile_id ): int { return $this->store->countWhere( 'rates', 'logistics_profile_id', $logistics_profile_id ); }
	public function countOrderSnapshotReferences( int $rate_card_id ): int { return 0; }
	public function listActiveForQuoteMatch( int $delivery_offer_id, int $destination_zone_id, string $currency_code ): array {
		return array_values( array_filter(
			$this->list(),
			static fn ( array $row ): bool =>
				(int) ( $row['delivery_offer_id'] ?? 0 ) === $delivery_offer_id
				&& (int) ( $row['destination_zone_id'] ?? 0 ) === $destination_zone_id
		) );
	}
}

final class Rc3PickupRepository implements PickupLocationRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'pickups', $id ); }
	public function findByCode( string $code ): ?array { return $this->store->findByCode( 'pickups', $code ); }
	public function save( array $data ): int { return $this->store->save( 'pickups', $data ); }
	public function list( array $criteria = [] ): array { return $this->store->list( 'pickups' ); }
	public function softDelete( int $id ): bool { return $this->store->delete( 'pickups', $id ); }
	public function hardDelete( int $id ): bool { return $this->softDelete( $id ); }
	public function count_all(): int { return $this->store->count( 'pickups' ); }
}

final class Rc3LogisticsRepository implements LogisticsProfileRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'logistics', $id ); }
	public function findByCode( string $code ): ?array { return $this->store->findByCode( 'logistics', $code ); }
	public function save( array $data ): int { return $this->store->save( 'logistics', $data ); }
	public function list( array $criteria = [] ): array { return $this->store->list( 'logistics' ); }
	public function softDelete( int $id ): bool { return $this->store->delete( 'logistics', $id ); }
	public function hardDelete( int $id ): bool { return $this->softDelete( $id ); }
	public function count_all(): int { return $this->store->count( 'logistics' ); }
}

final class Rc3SupplierRepository implements SupplierRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'suppliers', $id ); }
	public function findByCode( string $code ): ?array { return $this->store->findByCode( 'suppliers', $code ); }
	public function save( array $data ): int { return $this->store->save( 'suppliers', $data ); }
	public function list( array $criteria = [] ): array { return $this->store->list( 'suppliers' ); }
	public function deactivate( int $id ): bool { return $this->store->delete( 'suppliers', $id ); }
	public function hardDelete( int $id ): bool { return $this->deactivate( $id ); }
	public function count_all(): int { return $this->store->count( 'suppliers' ); }
}

final class Rc3OriginRepository implements OriginRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'origins', $id ); }
	public function findByCode( string $code ): ?array { return $this->store->findByCode( 'origins', $code ); }
	public function save( array $data ): int { return $this->store->save( 'origins', $data ); }
	public function list( array $criteria = [] ): array { return $this->store->list( 'origins' ); }
	public function deactivate( int $id ): bool { return $this->store->delete( 'origins', $id ); }
	public function hardDelete( int $id ): bool { return $this->deactivate( $id ); }
	public function count_all(): int { return $this->store->count( 'origins' ); }
	public function countBySupplierId( int $supplier_id ): int { return $this->store->countWhere( 'origins', 'supplier_id', $supplier_id ); }
}

final class Rc3LegacyRuleRepository implements ProductDeliveryRuleRepositoryInterface {
	public function __construct( private Rc3FixtureStore $store ) {}
	public function findById( int $id ): ?array { return $this->store->find( 'legacy', $id ); }
	public function findByTarget( string $target_type, int $target_id ): array {
		return array_values( array_filter(
			$this->store->list( 'legacy' ),
			static fn ( array $row ): bool => (string) ( $row['target_type'] ?? '' ) === $target_type && (int) ( $row['target_id'] ?? 0 ) === $target_id
		) );
	}
	public function findByTargetAndAvailability( string $target_type, int $target_id, string $availability ): array {
		return array_values( array_filter(
			$this->findByTarget( $target_type, $target_id ),
			static fn ( array $row ): bool => (string) ( $row['fulfilment_availability'] ?? '' ) === $availability
		) );
	}
	public function list( array $filters = [] ): array { return $this->store->list( 'legacy' ); }
	public function listActive( array $filters = [] ): array { return $this->list( $filters ); }
	public function findActiveByTargets( array $targets ): array { return []; }
	public function save( array $data ): int { return $this->store->save( 'legacy', $data ); }
	public function deactivate( int $id ): bool { return true; }
	public function hardDelete( int $id ): bool { return $this->store->delete( 'legacy', $id ); }
	public function count_all(): int { return $this->store->count( 'legacy' ); }
	public function countBySupplierId( int $supplier_id ): int { return 0; }
	public function countByOriginId( int $origin_id ): int { return 0; }
	public function countByLogisticsProfileId( int $logistics_profile_id ): int { return 0; }
}

final class Rc3AuditRepository implements AuditLogRepositoryInterface {
	public function findById( int $id ): ?array { return null; }
	public function append( array $data ): int { return 1; }
	public function list( array $criteria = [] ): array { return []; }
}
