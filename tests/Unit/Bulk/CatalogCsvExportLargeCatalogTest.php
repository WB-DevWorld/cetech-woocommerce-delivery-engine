<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\ImportExport\CatalogCsvExportService;
use CetechDeliveryEngine\Application\Bulk\Portability\EntityCodeResolver;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogScanScopedConfigurationRepository;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryPickupLocationRepository;
use PHPUnit\Framework\TestCase;

/**
 * The whole-catalog CSV download timed out on a store of a few thousand products: every row cost
 * several scope lookups plus a full resolution, nothing was sent until the last row, and the
 * request ran under the normal admin time limit. These tests pin the batched export: same rows as
 * the row-by-row export, lookups that grow with the number of pages rather than the number of
 * products, and a per-page callback the download uses to flush.
 */
final class CatalogCsvExportLargeCatalogTest extends TestCase {

	private const PRODUCTS            = 250;
	private const VARIATIONS_PER_TENTH = 3;

	private InMemoryScopedConfigurationRepository $scopes;
	private InMemoryDeliveryOfferRepository $offers;
	private InMemoryPickupLocationRepository $pickups;
	private InMemoryCatalogTargetQuery $targets;
	private EntityCodeResolver $codes;
	private int $variation_count = 0;

	protected function setUp(): void {
		$this->scopes = new InMemoryScopedConfigurationRepository();
		$this->offers = new InMemoryDeliveryOfferRepository();
		$this->offers->seed( 1, [ 'internal_code' => 'STD', 'route' => 'local_delivery', 'status' => 'active', 'public_label' => 'Standard' ] );
		$this->offers->seed( 2, [ 'internal_code' => 'EXP', 'route' => 'local_delivery', 'status' => 'active', 'public_label' => 'Express' ] );
		$this->pickups = new InMemoryPickupLocationRepository();
		$this->pickups->seed( 7, [ 'internal_code' => 'MAIN', 'status' => RecordStatus::Active->value, 'location_name' => 'Main showroom' ] );
		$this->codes   = new EntityCodeResolver( $this->offers, null, null, null, $this->pickups );
		$this->targets = new InMemoryCatalogTargetQuery();

		$this->seed_site_wide_configuration();
		$this->seed_large_catalog();
	}

	public function test_the_batched_export_writes_exactly_the_rows_of_the_row_by_row_export(): void {
		$plain   = $this->export( $this->plain_service() );
		$batched = $this->export( $this->batched_service() );

		self::assertSame( $plain, $batched, 'batching changes how rows are read, never what is written' );

		$lines = array_values( array_filter( explode( "\n", $batched ), static fn ( string $line ): bool => '' !== trim( $line ) ) );
		// Header + 1 customised site-wide row + every product + every variation + the extra slice row of product 5.
		self::assertCount( 1 + 1 + self::PRODUCTS + $this->variation_count + 1, $lines );
	}

	public function test_scope_lookups_grow_with_pages_and_customised_targets_not_with_catalog_size(): void {
		$this->scopes->resetReadCallCounts();
		$this->export( $this->plain_service() );
		$plain_reads = array_sum( $this->scopes->getReadCallCounts() );

		$this->scopes->resetReadCallCounts();
		$this->export( $this->batched_service() );
		$counts        = $this->scopes->getReadCallCounts();
		$batched_reads = array_sum( $counts );

		$product_pages   = (int) ceil( self::PRODUCTS / CatalogCsvExportService::PAGE_SIZE );
		$variation_pages = (int) ceil( $this->variation_count / CatalogCsvExportService::PAGE_SIZE );

		// One presence query per product page; a variation page asks for its variations and their parents.
		self::assertSame( $product_pages + 2 * $variation_pages, $counts['scopeIdsWithRows'] ?? 0 );

		// Only the three customised targets (products 5 and 20, variation of product 30) reach findByScope,
		// each a bounded number of times; the other ~320 targets never do.
		self::assertLessThanOrEqual( 12, $counts['findByScope'] ?? 0 );

		self::assertGreaterThan( 3 * self::PRODUCTS, $plain_reads, 'the row-by-row export reads scopes several times per target' );
		self::assertLessThan( $plain_reads / 10, $batched_reads, 'the batched export is at least ten times lighter on scope reads' );
	}

	public function test_inherited_rows_resolve_once_while_customised_rows_keep_their_own_result(): void {
		$rows = $this->rows_by_key( $this->export( $this->batched_service() ) );

		// Products without saved scopes inherit the site-wide In Store default.
		self::assertSame( FulfilmentAvailability::InStore->value, $rows['product|1|']['de_effective_fulfilment'] );
		self::assertSame( $rows['product|1|']['de_effective_source'], $rows['product|249|']['de_effective_source'] );

		// Product 20 overrides fulfilment and sits between inherited rows on the same page: it must not
		// pick up the memoised inherited result, and the rows after it must not pick up its result.
		self::assertSame( FulfilmentAvailability::InWarehouse->value, $rows['product|20|']['de_effective_fulfilment'] );
		self::assertSame( 'override', $rows['product|20|']['de_fulfilment_mode'] );
		self::assertSame( FulfilmentAvailability::InStore->value, $rows['product|21|']['de_effective_fulfilment'] );

		// A variation whose parent is customised is resolved for real, not from the inherited memo.
		$child_of_20 = $rows[ 'variation|' . $this->variation_id( 20, 1 ) . '|' ];
		self::assertSame( FulfilmentAvailability::InWarehouse->value, $child_of_20['de_effective_fulfilment'] );
		self::assertSame( 'SKU-20', $child_of_20['sku'], 'a variation row carries its parent SKU' );
		self::assertSame( 'SKU-20-V1', $child_of_20['variation_sku'] );

		// A customised variation under an uncustomised parent keeps its saved instruction.
		$custom_variation = $rows[ 'variation|' . $this->variation_id( 30, 2 ) . '|' ];
		self::assertSame( 'override', $custom_variation['de_eta_mode'] );
		self::assertSame( 'Next day', $custom_variation['de_eta_value'] );

		// The extra slice of product 5 is still written.
		self::assertSame( 'Next day', $rows['product|5|in_store']['de_eta_value'] );
	}

	public function test_the_download_is_told_after_every_page_with_the_running_row_count(): void {
		$seen   = [];
		$handle = fopen( 'php://temp', 'r+' );
		self::assertNotFalse( $handle );

		$total = $this->batched_service()->write_stream(
			$handle,
			$this->entire_catalog(),
			false,
			static function ( int $rows_so_far ) use ( &$seen ): void {
				$seen[] = $rows_so_far;
			}
		);
		fclose( $handle );

		$product_pages   = (int) ceil( self::PRODUCTS / CatalogCsvExportService::PAGE_SIZE );
		$variation_pages = (int) ceil( $this->variation_count / CatalogCsvExportService::PAGE_SIZE );

		self::assertCount( $product_pages + $variation_pages, $seen, 'one call per page of products and of variations' );
		self::assertSame( $seen, array_values( array_unique( $seen ) ), 'the running count only moves forward' );
		self::assertSame( $total, end( $seen ) );
		// 1 site-wide row + the first 100 products + the extra slice of product 5.
		self::assertSame( 1 + CatalogCsvExportService::PAGE_SIZE + 1, $seen[0] );
	}

	public function test_the_batch_view_answers_absent_variations_without_a_lookup_and_forgets_after_reset(): void {
		$view = new CatalogScanScopedConfigurationRepository( $this->scopes );
		$kept = $this->variation_id( 30, 2 );
		$bare = $this->variation_id( 30, 1 );

		$view->prime_variations( [ $kept, $bare ] );
		$this->scopes->resetReadCallCounts();

		self::assertTrue( $view->is_known_absent( ConfigurationScopeType::Variation, $bare ) );
		self::assertFalse( $view->is_known_absent( ConfigurationScopeType::Variation, $kept ) );
		self::assertFalse( $view->is_known_absent( ConfigurationScopeType::Product, $bare ), 'absence is recorded per scope type' );
		self::assertSame( [], $view->findByScope( ConfigurationScopeType::Variation, $bare ) );
		self::assertNull( $view->findByScopeAndSlice( ConfigurationScopeType::Variation, $bare, '' ) );
		self::assertSame( [], $this->scopes->getReadCallCounts(), 'a variation proven absent costs no lookup' );

		self::assertCount( 1, $view->findByScope( ConfigurationScopeType::Variation, $kept ) );

		$view->reset();
		self::assertFalse( $view->is_known_absent( ConfigurationScopeType::Variation, $bare ), 'nothing outlives the batch' );
	}

	private function plain_service(): CatalogCsvExportService {
		return $this->service( $this->scopes );
	}

	private function batched_service(): CatalogCsvExportService {
		return $this->service( new CatalogScanScopedConfigurationRepository( $this->scopes ) );
	}

	private function service( ScopedConfigurationRepositoryInterface $scopes ): CatalogCsvExportService {
		return new CatalogCsvExportService(
			$this->targets,
			$scopes,
			new EffectiveConfigurationResolver(
				$scopes,
				new EffectiveConfigurationValidator(),
				new HardFulfilmentConstraintService( $this->offers, $this->pickups )
			),
			$this->codes
		);
	}

	private function entire_catalog(): CatalogTargetDefinition {
		return CatalogTargetDefinition::from_array( [ 'scope' => BulkTargetScope::EntireCatalog->value, 'entire_catalog_confirmed' => true ] );
	}

	private function export( CatalogCsvExportService $service ): string {
		$handle = fopen( 'php://temp', 'r+' );
		self::assertNotFalse( $handle );
		$service->write_stream( $handle, $this->entire_catalog() );
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );

		return $csv;
	}

	private function variation_id( int $product_id, int $index ): int {
		return 100000 + $product_id * 10 + $index;
	}

	private function seed_site_wide_configuration(): void {
		$this->scopes->ensureGlobalScope();
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration(
			ConfigurationScope::global(),
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InStore->value ),
				ConfigurationFieldKey::FULFILMENT_CHOICE       => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::Delivery->value ),
				ConfigurationFieldKey::ESTIMATED_DELIVERY      => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '2-3 business days' ),
				ConfigurationFieldKey::PRIORITY                => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 100 ),
			],
			[ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 1, 2 ] ) ]
		) );
	}

	/**
	 * 250 products; every tenth is variable with three variations. Three targets carry saved scopes:
	 * product 5 (default slice and an In Store slice), product 20 (fulfilment override) and one
	 * variation of product 30.
	 */
	private function seed_large_catalog(): void {
		for ( $id = 1; $id <= self::PRODUCTS; $id++ ) {
			$this->targets->add( new CatalogTarget( CatalogTargetDefinition::TARGET_PRODUCT, $id, 'SKU-' . $id ) );
			if ( 0 !== $id % 10 ) {
				continue;
			}
			for ( $v = 1; $v <= self::VARIATIONS_PER_TENTH; $v++ ) {
				$this->targets->add( new CatalogTarget( CatalogTargetDefinition::TARGET_VARIATION, $this->variation_id( $id, $v ), 'SKU-' . $id . '-V' . $v, $id ) );
				++$this->variation_count;
			}
		}

		$this->save_scope( ConfigurationScopeType::Product, 5, '', null, [ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 0 ) ] );
		$this->save_scope( ConfigurationScopeType::Product, 5, 'in_store', null, [ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'Next day' ) ] );
		$this->save_scope( ConfigurationScopeType::Product, 20, '', null, [ ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InWarehouse->value ) ] );
		$this->save_scope( ConfigurationScopeType::Variation, $this->variation_id( 30, 2 ), '', 30, [ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'Next day' ) ] );
	}

	/**
	 * @param array<string, ScalarFieldInstruction> $scalars
	 */
	private function save_scope( ConfigurationScopeType $type, int $scope_id, string $slice, ?int $parent_id, array $scalars ): void {
		$this->scopes->saveScopedConfiguration( new ScopedConfiguration(
			new ConfigurationScope( null, $type, $scope_id, $slice, $parent_id, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
			$scalars,
			[]
		) );
	}

	/**
	 * @return array<string, array<string, string>> keyed "scope|id|slice".
	 */
	private function rows_by_key( string $csv ): array {
		$lines  = array_values( array_filter( explode( "\n", $csv ), static fn ( string $line ): bool => '' !== trim( $line ) ) );
		$header = str_getcsv( (string) array_shift( $lines ), ',', '"', '\\' );
		$rows   = [];
		foreach ( $lines as $line ) {
			$cells = str_getcsv( $line, ',', '"', '\\' );
			$row   = [];
			foreach ( $header as $i => $name ) {
				$row[ (string) $name ] = (string) ( $cells[ $i ] ?? '' );
			}
			$key = 'global' === $row['de_scope']
				? 'global|' . $row['de_slice']
				: $row['de_scope'] . '|' . ( 'variation' === $row['de_scope'] ? $row['variation_id'] : $row['product_id'] ) . '|' . $row['de_slice'];
			$rows[ $key ] = $row;
		}

		return $rows;
	}
}
