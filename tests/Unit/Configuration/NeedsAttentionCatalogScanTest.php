<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogScanScopedConfigurationRepository;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfigurationRepositoryInterface;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

/**
 * M2.5: the batched, cheap-check-first catalog scan reports exactly what the
 * m2.4 scan reported, without the 5,000-product cap.
 */
final class NeedsAttentionCatalogScanTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;

	private SiteWideDefaultsSettings $settings;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->settings   = new SiteWideDefaultsSettings();
	}

	protected function tearDown(): void {
		// PHPUnit keeps test objects until the run ends; release the large fixtures.
		unset( $this->repository, $this->settings );
		$GLOBALS['cetech_de_test_options'] = [];
	}

	/**
	 * @return iterable<string, array{bool, bool, bool}>
	 */
	public static function scenarios(): iterable {
		foreach ( [ true, false ] as $defaults ) {
			foreach ( [ true, false ] as $scan_as_customer ) {
				foreach ( [ true, false ] as $decorated ) {
					$name = sprintf(
						'%s, %s, %s',
						$defaults ? 'defaults seeded' : 'no defaults',
						$scan_as_customer ? 'ECR on' : 'ECR off',
						$decorated ? 'batch view' : 'plain repository'
					);
					yield $name => [ $defaults, $scan_as_customer, $decorated ];
				}
			}
		}
	}

	/**
	 * @dataProvider scenarios
	 */
	public function test_new_scan_matches_the_m2_4_scan_on_mixed_fixtures( bool $defaults, bool $scan_as_customer, bool $decorated ): void {
		$catalog = $this->mixed_catalog();
		if ( $defaults ) {
			$this->seed_warehouse_defaults( $catalog );
		}
		$this->seed_product_scopes();
		if ( $scan_as_customer ) {
			$this->enable_ecr_chain();
		}

		$reference = $this->m2_4_list( $catalog );
		$query     = $this->query( $catalog, $decorated );

		self::assertSame( $reference, $query->list( 5000 ) );
		self::assertSame( count( $reference ), $query->count() );
		self::assertSame( array_slice( $reference, 0, 2 ), $query->list( 2 ) );

		$listing = $query->listing( 200 );
		self::assertTrue( $listing->complete );
		self::assertSame( count( $catalog->published_product_ids( 0, 100 ) ), $listing->scanned );
		self::assertSame( $reference, $listing->items );

		if ( ! $defaults && $scan_as_customer ) {
			self::assertCount( 8, $reference, 'With no defaults every product is incomplete once ECR is on.' );
		}
		if ( $defaults && ! $scan_as_customer ) {
			self::assertNotSame( [], $reference, 'Product-specific problems stay visible while customers use previous rules.' );
			self::assertLessThan( 8, count( $reference ) );
		}
	}

	public function test_count_covers_more_than_five_thousand_products(): void {
		$products = [];
		for ( $id = 1; $id <= 6001; $id++ ) {
			$products[ $id ] = [ 'label' => 'P' . $id, 'type' => 'simple' ];
		}
		$catalog = new InMemoryCatalogIndex( $products );
		$this->enable_ecr_chain();

		$query = $this->query( $catalog, true );

		self::assertSame( 6001, $query->count(), 'The m2.4 scan read published_product_ids( 0, 5000 ) and stopped at 5,000.' );

		self::assertFalse( $query->listing( 6001 )->complete, 'Hitting the row limit never claims the catalog was covered.' );

		$listing = $query->listing( 6002 );
		self::assertTrue( $listing->complete );
		self::assertSame( 6001, $listing->scanned );
		self::assertCount( 6001, $listing->items );
		self::assertSame( 6001, $listing->items[6000]['id'] );
	}

	public function test_count_batches_resume_from_an_offset_and_report_the_end(): void {
		$products = [];
		for ( $id = 1; $id <= 600; $id++ ) {
			$products[ $id ] = [ 'label' => 'P' . $id, 'type' => 'simple' ];
		}
		$catalog = new InMemoryCatalogIndex( $products );
		$this->enable_ecr_chain();
		$query = $this->query( $catalog, true );

		$first  = $query->count_batch( 0 );
		$second = $query->count_batch( 250 );
		$third  = $query->count_batch( 500 );

		self::assertSame( [ 250, 250, false ], [ $first->flagged, $first->scanned, $first->reached_end ] );
		self::assertSame( [ 250, 250, false ], [ $second->flagged, $second->scanned, $second->reached_end ] );
		self::assertSame( [ 100, 100, true ], [ $third->flagged, $third->scanned, $third->reached_end ] );
		self::assertTrue( $query->count_batch( 600 )->reached_end );
	}

	public function test_ecr_off_skips_the_resolve_for_products_without_product_scopes(): void {
		$products = [];
		for ( $id = 1; $id <= 3763; $id++ ) {
			$products[ $id ] = [ 'label' => 'P' . $id, 'type' => 'simple' ];
		}
		$catalog = new InMemoryCatalogIndex( $products );
		$this->repository->ensureGlobalScope();
		$this->repository->resetReadCallCounts();

		$query = $this->query( $catalog, true );

		self::assertSame( 0, $query->count() );
		$reads = $this->repository->getReadCallCounts();
		self::assertSame( 16, $reads['scopeIdsWithRows'] ?? 0, 'One presence query per batch of 250.' );
		self::assertSame( 0, $reads['findByScope'] ?? 0 );
		self::assertSame( 0, $reads['getGlobalConfiguration'] ?? 0 );
		self::assertSame( 0, $reads['findByScopeAndSlice'] ?? 0 );
	}

	public function test_batch_view_loads_the_global_scope_once_per_batch_when_ecr_is_on(): void {
		$products = [];
		for ( $id = 1; $id <= 500; $id++ ) {
			$products[ $id ] = [ 'label' => 'P' . $id, 'type' => 'simple' ];
		}
		$catalog = new InMemoryCatalogIndex( $products );
		$this->repository->ensureGlobalScope();
		$this->enable_ecr_chain();
		$plain_count = $this->query( $catalog, false )->count();
		$this->repository->resetReadCallCounts();

		self::assertSame( $plain_count, $this->query( $catalog, true )->count() );

		$reads = $this->repository->getReadCallCounts();
		self::assertSame( 0, $reads['findByScope'] ?? 0, 'No product scopes exist, so the presence query answers every product.' );
		self::assertLessThanOrEqual( 3 * 3, (int) ( $reads['findByScopeAndSlice'] ?? 0 ), 'Global and profile slices are read once per batch, not once per product.' );
	}

	public function test_batch_view_forgets_its_memo_after_a_write(): void {
		$this->repository->ensureGlobalScope();
		$view = new CatalogScanScopedConfigurationRepository( $this->repository );
		$view->prime_products( [ 7 ] );
		self::assertSame( [], $view->findByScope( ConfigurationScopeType::Product, 7 ) );

		$view->saveScopedConfiguration( $this->product_scope( 7, [ ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '2 days' ) ] ) );

		self::assertCount( 1, $view->findByScope( ConfigurationScopeType::Product, 7 ) );
	}

	/**
	 * The m2.4 algorithm, kept here as the equivalence oracle: resolve first,
	 * then discard products without product-specific settings, first 5,000 only.
	 *
	 * @return list<array{id: int, label: string, url: string, reason: string}>
	 */
	private function m2_4_list( InMemoryCatalogIndex $catalog ): array {
		$assessor   = new OperationalReadinessAssessor( $this->resolver( $this->repository ) );
		$state      = $this->state();
		$classifier = $this->classifier( $catalog, $this->repository );
		$items      = [];

		foreach ( $catalog->published_product_ids( 0, 5000 ) as $product_id ) {
			$reason = $assessor->reason_for( $product_id );
			if ( null === $reason ) {
				continue;
			}
			if ( ! $state->current()->scan_catalog_as_customer_problems ) {
				$scopes = $this->repository->findByScope( ConfigurationScopeType::Product, $product_id );
				if ( ! $classifier->has_custom_fields( $scopes ) ) {
					continue;
				}
			}
			$items[] = [
				'id'     => $product_id,
				'label'  => $catalog->product_label( $product_id ),
				'url'    => $catalog->product_edit_url( $product_id ),
				'reason' => $reason,
			];
		}

		return $items;
	}

	private function query( InMemoryCatalogIndex $catalog, bool $decorated ): NeedsAttentionQuery {
		$scopes = $decorated ? new CatalogScanScopedConfigurationRepository( $this->repository ) : $this->repository;

		return new NeedsAttentionQuery(
			$catalog,
			new OperationalReadinessAssessor( $this->resolver( $scopes ) ),
			$this->state(),
			$this->classifier( $catalog, $scopes ),
			$scopes
		);
	}

	private function resolver( ScopedConfigurationRepositoryInterface $scopes ): EffectiveConfigurationResolver {
		return new EffectiveConfigurationResolver(
			$scopes,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService(),
			$this->settings
		);
	}

	private function classifier( InMemoryCatalogIndex $catalog, ScopedConfigurationRepositoryInterface $scopes ): CatalogInheritanceClassifier {
		return new CatalogInheritanceClassifier( $scopes, $catalog, $this->resolver( $scopes ), $this->settings );
	}

	private function state(): OperationalStateService {
		$flags = new FeatureFlags();

		return new OperationalStateService(
			$flags,
			$this->settings,
			new SetupWizardProgress( $this->settings ),
			new ClassicCheckoutRuntimeActivation( $flags, $this->settings ),
			new SiteWideDefaultSummary( $this->repository )
		);
	}

	private function enable_ecr_chain(): void {
		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			$GLOBALS['cetech_de_test_options'][ 'cetech_de_' . $flag ] = 1;
		}
	}

	private function mixed_catalog(): InMemoryCatalogIndex {
		$products = [];
		foreach ( [ 101, 102, 103, 104, 105, 106, 107, 108 ] as $id ) {
			$products[ $id ] = [ 'label' => 'Product ' . $id, 'type' => 'simple' ];
		}

		return new InMemoryCatalogIndex( $products );
	}

	private function seed_warehouse_defaults( InMemoryCatalogIndex $catalog ): void {
		$service = new SiteWideDefaultsService(
			$this->repository,
			$this->settings,
			$this->classifier( $catalog, $this->repository ),
			$this->resolver( $this->repository ),
			$catalog
		);
		$service->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			[
				ConfigurationFieldKey::FULFILMENT_CHOICE    => [ 'mode' => 'override', 'value' => FulfilmentChoice::Delivery->value ],
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'override', 'value' => 10 ],
				ConfigurationFieldKey::SUPPLIER_ID          => [ 'mode' => 'override', 'value' => 20 ],
				ConfigurationFieldKey::ORIGIN_ID            => [ 'mode' => 'override', 'value' => 30 ],
				ConfigurationFieldKey::PRIORITY             => [ 'mode' => 'override', 'value' => 10 ],
				ConfigurationFieldKey::ESTIMATED_DELIVERY   => [ 'mode' => 'override', 'value' => '3–5 days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS   => [ 'mode' => 'replace', 'members' => [ 11 ] ],
			]
		);
		$service->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
	}

	/**
	 * 101 none; 102 ETA override; 103 availability-only override; 104 empty
	 * offers; 105 inactive ETA override; 106 inherit-only; 107 pickup with no
	 * location; 108 international with no international defaults.
	 */
	private function seed_product_scopes(): void {
		$eta = ConfigurationFieldKey::ESTIMATED_DELIVERY;

		$this->repository->saveScopedConfiguration( $this->product_scope( 102, [ $eta => ScalarFieldInstruction::override( $eta, '1 day' ) ] ) );
		$this->repository->saveScopedConfiguration(
			$this->product_scope(
				103,
				[ ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InternationalFulfilment->value ) ]
			)
		);
		$this->repository->saveScopedConfiguration(
			$this->product_scope( 104, [], [ ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [] ) ] )
		);
		$this->repository->saveScopedConfiguration( $this->product_scope( 105, [ $eta => ScalarFieldInstruction::override( $eta, '9 days' ) ], [], RecordStatus::Inactive ) );
		$this->repository->saveScopedConfiguration( $this->product_scope( 106, [ $eta => ScalarFieldInstruction::inherit( $eta ) ] ) );
		$this->repository->saveScopedConfiguration(
			$this->product_scope(
				107,
				[ ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_CHOICE, FulfilmentChoice::StorePickup->value ) ]
			)
		);
		$this->repository->saveScopedConfiguration(
			$this->product_scope(
				108,
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InternationalFulfilment->value ),
					$eta => ScalarFieldInstruction::override( $eta, '3 weeks' ),
				]
			)
		);
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function product_scope( int $product_id, array $scalars, array $collections = [], RecordStatus $status = RecordStatus::Active ): ScopedConfiguration {
		return new ScopedConfiguration(
			new ConfigurationScope( null, ConfigurationScopeType::Product, $product_id, ConfigurationScope::DEFAULT_SLICE_KEY, null, $status, 1, ConfigurationSource::Native, null ),
			$scalars,
			$collections
		);
	}
}
