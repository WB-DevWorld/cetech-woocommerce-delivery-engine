<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetFilters;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Portability\ConfigurationExporter;
use CetechDeliveryEngine\Application\Bulk\Portability\EntityKeysetPager;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\LogisticsProfile\LogisticsProfileRepositoryInterface;
use CetechDeliveryEngine\Domain\Pickup\PickupLocationRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\OriginRepositoryInterface;
use CetechDeliveryEngine\Domain\Supplier\SupplierRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\Zone\DestinationZoneRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class CompleteExportAndFilterTest extends TestCase {

	public function test_keyset_pager_exports_more_than_five_hundred_without_duplicates(): void {
		$rows = [];
		for ( $i = 1; $i <= 1500; $i++ ) {
			$rows[ $i ] = [
				'id'            => $i,
				'internal_code' => 'OPT-' . $i,
				'internal_name' => 'Option ' . $i,
			];
		}
		$offers = new InMemoryKeysetOfferRepository( $rows );
		$empty  = $this->createStub( DestinationZoneRepositoryInterface::class );
		$exporter = new ConfigurationExporter(
			$offers,
			$empty,
			$this->createStub( DestinationRuleRepositoryInterface::class ),
			$this->createStub( RateCardRepositoryInterface::class ),
			$this->createStub( LogisticsProfileRepositoryInterface::class ),
			$this->createStub( PickupLocationRepositoryInterface::class ),
			$this->createStub( SupplierRepositoryInterface::class ),
			$this->createStub( OriginRepositoryInterface::class )
		);

		$package = $exporter->export( [ 'delivery_options' ], false, false );
		$exported = $package->sections['delivery_options'];
		self::assertCount( 1500, $exported );
		$codes = array_column( $exported, 'internal_code' );
		self::assertCount( 1500, array_unique( $codes ) );
		self::assertSame( 'OPT-1', $codes[0] );
		self::assertSame( 'OPT-1500', $codes[1499] );
		self::assertSame( 1500, $package->manifest['counts']['delivery_options'] );
	}

	public function test_keyset_pager_walks_in_bounded_pages(): void {
		$rows = [];
		for ( $i = 1; $i <= 250; $i++ ) {
			$rows[ $i ] = [ 'id' => $i, 'internal_code' => 'Z-' . $i ];
		}
		$repo  = new InMemoryKeysetOfferRepository( $rows );
		$seen  = [];
		$pages = 0;
		foreach ( EntityKeysetPager::iterate( $repo, [], 100 ) as $row ) {
			$seen[] = (int) $row['id'];
			if ( 0 === count( $seen ) % 100 ) {
				++$pages;
			}
		}
		self::assertSame( range( 1, 250 ), $seen );
		self::assertSame( 2, $pages );
	}

	public function test_empty_matching_filters_select_no_targets(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add( new CatalogTarget( 'product', 1, 'SKU-1' ) );
		$definition = CatalogTargetDefinition::from_array(
			[
				'scope' => BulkTargetScope::MatchingFilters->value,
			]
		);
		self::assertFalse( $definition->has_matching_criteria() );
		self::assertSame( 0, $query->count( $definition ) );
		self::assertSame( [], $query->page_after( $definition, 0, 25 ) );
	}

	public function test_native_and_delivery_engine_filters_combine(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add(
			new CatalogTarget( 'product', 10, 'SIMPLE-IN', null, 'simple in stock' ),
			[
				CatalogTargetFilters::PRODUCT_TYPE            => 'simple',
				CatalogTargetFilters::STOCK_STATUS            => 'instock',
				CatalogTargetFilters::CONFIGURED_FULFILMENT   => 'international_fulfilment',
				CatalogTargetFilters::EXCEPTION_STATE         => CatalogTargetFilters::EXCEPTION_PRODUCT,
				CatalogTargetFilters::DELIVERY_OPTION_ID      => 7,
				'delivery_option_ids'                         => [ 7 ],
				CatalogTargetFilters::LOGISTICS_PROFILE_ID    => 3,
			]
		);
		$query->add(
			new CatalogTarget( 'product', 11, 'VAR-OUT', null, 'variable oos' ),
			[
				CatalogTargetFilters::PRODUCT_TYPE          => 'variable',
				CatalogTargetFilters::STOCK_STATUS          => 'outofstock',
				CatalogTargetFilters::CONFIGURED_FULFILMENT => 'in_store',
				CatalogTargetFilters::EXCEPTION_STATE       => CatalogTargetFilters::EXCEPTION_SITE_WIDE,
			]
		);
		$query->add(
			new CatalogTarget( 'product', 12, 'SIMPLE-WH', null, 'simple warehouse' ),
			[
				CatalogTargetFilters::PRODUCT_TYPE          => 'simple',
				CatalogTargetFilters::STOCK_STATUS          => 'instock',
				CatalogTargetFilters::CONFIGURED_FULFILMENT => 'in_warehouse',
				CatalogTargetFilters::EXCEPTION_STATE       => CatalogTargetFilters::EXCEPTION_PRODUCT,
				'delivery_option_ids'                       => [ 9 ],
			]
		);

		$definition = CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => [
					CatalogTargetFilters::PRODUCT_TYPE          => 'simple',
					CatalogTargetFilters::STOCK_STATUS          => 'instock',
					CatalogTargetFilters::CONFIGURED_FULFILMENT => 'international_fulfilment',
					CatalogTargetFilters::EXCEPTION_STATE       => CatalogTargetFilters::EXCEPTION_PRODUCT,
					CatalogTargetFilters::DELIVERY_OPTION_ID    => 7,
				],
			]
		);
		$page = $query->page_after( $definition, 0, 50 );
		self::assertCount( 1, $page );
		self::assertSame( 10, $page[0]->id );
	}

	public function test_supplier_and_origin_filters_are_combination_safe(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add(
			new CatalogTarget( 'product', 21, 'PRIV-1' ),
			[
				CatalogTargetFilters::SUPPLIER_ID => 4,
				CatalogTargetFilters::ORIGIN_ID   => 8,
				CatalogTargetFilters::PRODUCT_TYPE => 'simple',
			]
		);
		$query->add(
			new CatalogTarget( 'product', 22, 'PRIV-2' ),
			[
				CatalogTargetFilters::SUPPLIER_ID => 4,
				CatalogTargetFilters::ORIGIN_ID   => 9,
				CatalogTargetFilters::PRODUCT_TYPE => 'simple',
			]
		);
		$definition = CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => [
					CatalogTargetFilters::PRODUCT_TYPE => 'simple',
					CatalogTargetFilters::SUPPLIER_ID  => 4,
					CatalogTargetFilters::ORIGIN_ID    => 8,
				],
			]
		);
		$page = $query->page_after( $definition, 0, 50 );
		self::assertCount( 1, $page );
		self::assertSame( 21, $page[0]->id );
	}

	public function test_pickup_and_invalid_effective_filters(): void {
		$query = new InMemoryCatalogTargetQuery();
		$query->add(
			new CatalogTarget( 'product', 31, 'PICK-1' ),
			[
				'in_store_pickup' => true,
				CatalogTargetFilters::PICKUP_LOCATION_ID => 5,
				CatalogTargetFilters::INVALID_EFFECTIVE => true,
			]
		);
		$query->add(
			new CatalogTarget( 'product', 32, 'PICK-2' ),
			[
				CatalogTargetFilters::INVALID_EFFECTIVE => false,
			]
		);
		$pickup = CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => [ CatalogTargetFilters::PICKUP_LOCATION_ID => 5 ],
			]
		);
		self::assertCount( 1, $query->page_after( $pickup, 0, 10 ) );
		$invalid = CatalogTargetDefinition::from_array(
			[
				'scope'   => BulkTargetScope::MatchingFilters->value,
				'filters' => [ CatalogTargetFilters::INVALID_EFFECTIVE => true ],
			]
		);
		self::assertCount( 1, $query->page_after( $invalid, 0, 10 ) );
		self::assertSame( 31, $query->page_after( $invalid, 0, 10 )[0]->id );
	}
}

/**
 * @internal
 */
final class InMemoryKeysetOfferRepository implements DeliveryOfferRepositoryInterface {

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	public function __construct( private array $rows ) {
	}

	public function findById( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}

	public function findByCode( string $code ): ?array {
		foreach ( $this->rows as $row ) {
			if ( (string) ( $row['internal_code'] ?? '' ) === $code ) {
				return $row;
			}
		}

		return null;
	}

	public function save( array $data ): int {
		$id = (int) ( $data['id'] ?? 0 );
		if ( $id <= 0 ) {
			$id = $this->rows === [] ? 1 : max( array_keys( $this->rows ) ) + 1;
		}
		$data['id']        = $id;
		$this->rows[ $id ] = $data;

		return $id;
	}

	public function list( array $criteria = [] ): array {
		return array_values( $this->rows );
	}

	/**
	 * @param array<string, mixed> $criteria
	 *
	 * @return list<array<string, mixed>>
	 */
	public function page_after( int $after_id, int $limit = 100, array $criteria = [] ): array {
		unset( $criteria );
		$out = [];
		ksort( $this->rows );
		foreach ( $this->rows as $id => $row ) {
			if ( $id <= $after_id ) {
				continue;
			}
			$out[] = $row;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}

	public function softDelete( int $id ): bool {
		unset( $this->rows[ $id ] );

		return true;
	}

	public function hardDelete( int $id ): bool {
		return $this->softDelete( $id );
	}

	public function count_all(): int {
		return count( $this->rows );
	}
}
