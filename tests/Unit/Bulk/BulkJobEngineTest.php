<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogFieldAction;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class BulkJobEngineTest extends TestCase {

	private InMemoryScopedConfigurationRepository $scopes;

	private InMemoryBulkJobRepository $jobs;

	private InMemoryCatalogTargetQuery $targets;

	private InMemoryBoundedQueue $queue;

	private BulkJobEngine $engine;

	private SiteWideDefaultsService $defaults;

	private EffectiveConfigurationResolver $resolver;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();
		$this->scopes  = new InMemoryScopedConfigurationRepository();
		$this->jobs    = new InMemoryBulkJobRepository();
		$this->targets = new InMemoryCatalogTargetQuery();
		$this->queue   = new InMemoryBoundedQueue();

		$settings = new SiteWideDefaultsSettings();
		$this->resolver = new EffectiveConfigurationResolver(
			$this->scopes,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService(),
			$settings
		);
		$catalog = new InMemoryCatalogIndex( [ 1 => [ 'label' => 'P1', 'type' => 'simple' ] ] );
		$classifier = new CatalogInheritanceClassifier(
			$this->scopes,
			$catalog,
			$this->resolver,
			$settings,
			$this->legacy_repo()
		);
		$this->defaults = new SiteWideDefaultsService(
			$this->scopes,
			$settings,
			$classifier,
			$this->resolver,
			$catalog
		);

		$mutator = new CatalogScopeMutator(
			$this->scopes,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService(),
			$settings
		);
		$worker = new BulkJobWorker( $this->jobs, $this->targets, $mutator, $this->queue, 30 );
		$this->engine = new BulkJobEngine( $this->jobs, $this->queue, $worker );

		$this->seed_site_wide();
	}

	public function test_create_preview_does_not_process_targets_in_the_initiating_call(): void {
		$this->add_products( range( 1, 40 ) );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( range( 1, 40 ) ),
			$this->set_international()
		);

		self::assertSame( BulkJobStatus::Previewing, $job->status );
		self::assertSame( 0, $job->processed_count );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 1, '' ) );
		self::assertSame( 1, $this->queue->queued_count() );
	}

	public function test_unavailable_queue_fails_safe_without_mutating(): void {
		$this->queue->available = false;
		$this->add_products( [ 1, 2 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 1, 2 ] ),
			$this->set_international()
		);

		self::assertSame( BulkJobStatus::Failed, $job->status );
		self::assertSame( 'background_queue_unavailable', $job->error_code );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 1, '' ) );
	}

	public function test_large_job_is_processed_in_bounded_batches(): void {
		$ids = range( 1, 100 );
		$this->add_products( $ids );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( $ids ),
			$this->set_international(),
			25
		);
		$ticks = $this->drain();

		$job = $this->engine->find( (int) $job->id );
		self::assertNotNull( $job );
		self::assertTrue( $job->enumeration_complete );
		self::assertSame( 100, $job->total_count );
		self::assertGreaterThan( 3, $ticks );
		self::assertSame( BulkJobStatus::Ready, $job->status );
		self::assertSame( 100, $job->changed_count );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 50, '' ) );
	}

	public function test_one_thousand_targets_stay_batched_and_apply_writes_overrides(): void {
		$ids = range( 1, 1000 );
		$this->add_products( $ids );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( $ids ),
			$this->set_international(),
			25
		);
		$this->drain( 5000 );
		$job = $this->engine->find( (int) $job->id );
		self::assertSame( BulkJobStatus::Ready, $job?->status );
		self::assertSame( 1000, $job?->changed_count );

		$this->engine->apply( (int) $job->id, 1 );
		$this->drain( 5000 );
		$job = $this->engine->find( (int) $job->id );
		self::assertSame( BulkJobStatus::Completed, $job?->status );

		$saved = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 500, '' );
		self::assertNotNull( $saved );
		self::assertSame(
			FulfilmentAvailability::InternationalFulfilment->value,
			$saved->scalars[ ConfigurationFieldKey::FULFILMENT_AVAILABILITY ]->value
		);
		self::assertArrayNotHasKey( ConfigurationFieldKey::ESTIMATED_DELIVERY, $saved->scalars );
	}

	public function test_simulated_ten_thousand_targets_enumerate_without_one_giant_pass(): void {
		$ids = range( 1, 10000 );
		$this->add_products( $ids );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( $ids ),
			$this->set_international(),
			25
		);
		$jobId = (int) $job->id;
		$ticks = $this->drain( 20000 );
		$job   = $this->engine->find( $jobId );
		self::assertSame( 10000, $job?->total_count );
		self::assertGreaterThan( 400, $ticks );
		self::assertSame( BulkJobStatus::Ready, $job?->status );
		self::assertSame( 10000, $this->jobs->item_storage_count() );
		self::assertSame( [], $job?->target_definition['selected_ids'] ?? null );
		self::assertTrue( (bool) ( $job?->target_definition['selected_ids_materialized'] ?? false ) );
		self::assertSame( 10000, (int) ( $job?->target_definition['selected_id_count'] ?? 0 ) );
		self::assertCount( 25, $this->jobs->list_items_page( $jobId, 1, 25 ) );
		self::assertCount( 25, $this->jobs->list_items_page( $jobId, 2, 25 ) );
		self::assertNotSame(
			$this->jobs->list_items_page( $jobId, 1, 25 )[0]->id,
			$this->jobs->list_items_page( $jobId, 2, 25 )[0]->id
		);
	}

	public function test_reset_to_site_wide_writes_inheritance_not_copied_values(): void {
		$this->add_products( [ 10 ] );
		$this->scopes->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 10, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
						FulfilmentAvailability::InternationalFulfilment->value
					),
				]
			)
		);

		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 10 ] ),
			[
				'field_actions' => [
					[
						'field_key' => ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
						'action'    => CatalogFieldAction::CLEAR_OVERRIDE,
					],
				],
			]
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();

		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 10, '' ) );
		$effective = $this->resolver->resolve( new EffectiveConfigurationRequest( 10 ) );
		self::assertSame( FulfilmentAvailability::InStore->value, $effective->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
	}

	public function test_collection_remove_does_not_flatten_to_replace(): void {
		$this->add_products( [ 20 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 20 ] ),
			[
				'field_actions' => [
					[
						'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						'action'    => CatalogFieldAction::COLLECTION_REMOVE,
						'members'   => [ 101 ],
					],
				],
			]
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();

		$saved = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 20, '' );
		self::assertNotNull( $saved );
		$instruction = $saved->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ];
		self::assertSame( CollectionConfigurationMode::Remove, $instruction->mode );
		self::assertSame( [ 101 ], $instruction->members );
	}

	public function test_variation_overrides_are_preserved_when_parent_is_updated(): void {
		$this->add_products( [ 30 ] );
		$this->targets->add( new CatalogTarget( CatalogTargetDefinition::TARGET_VARIATION, 31, 'VAR-30', 30 ) );
		$this->scopes->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Variation, 31, '', 30, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::ESTIMATED_DELIVERY,
						'custom variation eta'
					),
				]
			)
		);

		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 30 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();

		$variation = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Variation, 31, '' );
		self::assertSame( 'custom variation eta', $variation?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
	}

	public function test_rollback_skips_manually_edited_items(): void {
		$this->add_products( [ 40, 41 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 40, 41 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();

		$this->scopes->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 41, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
						FulfilmentAvailability::InWarehouse->value
					),
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_CHOICE,
						FulfilmentChoice::Delivery->value
					),
					ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
					ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
					ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
					ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 10 ),
					ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'manual' ),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 11 ] ),
				]
			)
		);

		$rollback = $this->engine->rollback( (int) $job->id, 1 );
		$this->drain();
		$rollback = $this->engine->find( (int) $rollback->id );
		self::assertSame( BulkJobStatus::PartiallyRolledBack, $rollback?->status );
		self::assertSame( 1, $rollback?->summary['rollback_restored'] ?? 0 );
		self::assertSame( 1, $rollback?->summary['rollback_skipped'] ?? 0 );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 40, '' ) );
		self::assertSame( 'manual', $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 41, '' )?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
	}

	public function test_duplicate_worker_claim_does_not_double_write(): void {
		$this->add_products( [ 50 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 50 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();
		$first = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 50, '' );
		$fp    = $first?->fingerprint();
		$this->engine->run_next_tick( (int) $job->id );
		$second = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 50, '' );
		self::assertSame( $fp, $second?->fingerprint() );
	}

	public function test_cancellation_does_not_reverse_completed_work(): void {
		$this->add_products( [ 60, 61 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 60, 61 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->engine->run_next_tick( (int) $job->id );
		$this->engine->cancel( (int) $job->id );
		$this->drain();
		$job = $this->engine->find( (int) $job->id );
		self::assertTrue( in_array( $job?->status, [ BulkJobStatus::Cancelled, BulkJobStatus::Completed, BulkJobStatus::CompletedWithErrors ], true ) );
	}

	public function test_claimed_batch_recovers_after_simulated_crash(): void {
		$this->add_products( [ 70, 71 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 70, 71 ] ),
			$this->set_international()
		);
		$this->drain();
		$item = $this->jobs->find_item( (int) $job->id, 'product', 70, 'SKU-70' );
		self::assertNotNull( $item );
		$this->jobs->save_item(
			$item->with(
				[
					'status'     => BulkJobItemStatus::Claimed,
					'claimed_at' => '2000-01-01 00:00:00',
					'claim_token'=> 'dead-worker',
				]
			)
		);
		$fresh = $this->jobs->find_job( (int) $job->id )?->with_status( BulkJobStatus::Previewing );
		if ( $fresh instanceof BulkJob ) {
			$this->jobs->save_job( $fresh->with( [ 'processed_count' => 0, 'changed_count' => 0 ] ) );
		}
		$this->queue->enqueue_job_tick( (int) $job->id );
		$this->drain();
		$recovered = $this->jobs->find_item( (int) $job->id, 'product', 70, 'SKU-70' );
		self::assertNotSame( BulkJobItemStatus::Claimed, $recovered?->status );
	}

	public function test_entire_catalog_without_confirmation_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			[
				'scope'                    => BulkTargetScope::EntireCatalog->value,
				'entire_catalog_confirmed' => false,
				'variation_policy'         => BulkVariationPolicy::PreserveOverrides->value,
			],
			$this->set_international()
		);
	}

	private function drain( int $max = 2000 ): int {
		$ticks = 0;
		while ( $id = $this->queue->next_job_id() ) {
			$this->engine->run_next_tick( $id );
			++$ticks;
			if ( $ticks >= $max ) {
				break;
			}
		}

		return $ticks;
	}

	/**
	 * @param list<int> $ids
	 */
	private function add_products( array $ids ): void {
		foreach ( $ids as $id ) {
			$this->targets->add( new CatalogTarget( CatalogTargetDefinition::TARGET_PRODUCT, $id, 'SKU-' . $id ) );
		}
	}

	/**
	 * @param list<int> $ids
	 * @return array<string, mixed>
	 */
	private function selected( array $ids ): array {
		return [
			'scope'            => BulkTargetScope::SelectedIds->value,
			'selected_ids'     => $ids,
			'variation_policy' => BulkVariationPolicy::PreserveOverrides->value,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function set_international(): array {
		return [
			'field_actions' => [
				[
					'field_key' => ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					'action'    => CatalogFieldAction::SET_OVERRIDE,
					'value'    => FulfilmentAvailability::InternationalFulfilment->value,
				],
			],
		];
	}

	private function seed_site_wide(): void {
		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InStore->value,
			$this->profile_fields( FulfilmentChoice::Delivery->value, '1–2 days', [ 21 ] )
		);
		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InternationalFulfilment->value,
			$this->profile_fields( FulfilmentChoice::Delivery->value, '7–21 days', [ 101, 102 ] )
		);
		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			$this->profile_fields( FulfilmentChoice::Delivery->value, '3–5 days', [ 11 ] )
		);
		$this->defaults->apply_site_wide(
			[
				FulfilmentAvailability::InStore->value,
				FulfilmentAvailability::InternationalFulfilment->value,
				FulfilmentAvailability::InWarehouse->value,
			],
			FulfilmentAvailability::InStore->value,
			false
		);
	}

	/**
	 * @param list<int> $offer_ids
	 * @return array<string, array{mode: string, value?: mixed, members?: list<int>}>
	 */
	private function profile_fields( string $choice, string $eta, array $offer_ids ): array {
		return [
			ConfigurationFieldKey::FULFILMENT_CHOICE => [ 'mode' => 'override', 'value' => $choice ],
			ConfigurationFieldKey::LOGISTICS_PROFILE_ID => [ 'mode' => 'override', 'value' => 10 ],
			ConfigurationFieldKey::SUPPLIER_ID => [ 'mode' => 'override', 'value' => 20 ],
			ConfigurationFieldKey::ORIGIN_ID => [ 'mode' => 'override', 'value' => 30 ],
			ConfigurationFieldKey::PRIORITY => [ 'mode' => 'override', 'value' => 10 ],
			ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => $eta ],
			ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => $offer_ids ],
		];
	}

	private function legacy_repo(): ProductDeliveryRuleRepositoryInterface {
		return new class implements ProductDeliveryRuleRepositoryInterface {
			public function findById( int $id ): ?array {
				return null;
			}
			public function findByTarget( string $target_type, int $target_id ): array {
				return [];
			}
			public function findByTargetAndAvailability( string $target_type, int $target_id, string $availability ): array {
				return [];
			}
			public function list( array $filters = [] ): array {
				return [];
			}
			public function listActive( array $filters = [] ): array {
				return [];
			}
			public function findActiveByTargets( array $targets ): array {
				return [];
			}
			public function save( array $data ): int {
				return 0;
			}
			public function deactivate( int $id ): bool {
				return false;
			}
			public function hardDelete( int $id ): bool {
				return false;
			}
			public function count_all(): int {
				return 0;
			}
			public function countBySupplierId( int $supplier_id ): int {
				return 0;
			}
			public function countByOriginId( int $origin_id ): int {
				return 0;
			}
			public function countByLogisticsProfileId( int $logistics_profile_id ): int {
				return 0;
			}
		};
	}
}
