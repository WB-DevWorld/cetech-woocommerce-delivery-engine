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
		self::assertSame( 2, $rollback?->processed_count );
		self::assertSame( 2, $rollback?->total_count );
		self::assertSame( 0, $rollback?->changed_count );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 40, '' ) );
		self::assertSame( 'manual', $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 41, '' )?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
	}

	public function test_successful_rollback_counts_processed_and_restores_empty_scope_inheritance(): void {
		$this->add_products( [ 80 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 80 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();
		self::assertNotNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 80, '' ) );

		$rollback = $this->engine->rollback( (int) $job->id, 1 );
		$this->drain();
		$rollback = $this->engine->find( (int) $rollback->id );

		self::assertSame( BulkJobStatus::RolledBack, $rollback?->status );
		self::assertSame( 1, $rollback?->total_count );
		self::assertSame( 1, $rollback?->enumerated_count );
		self::assertSame( 1, $rollback?->processed_count );
		self::assertSame( 0, $rollback?->changed_count );
		self::assertSame( 1, $rollback?->summary['rollback_restored'] ?? 0 );
		self::assertSame( 0, $rollback?->summary['rollback_skipped'] ?? 0 );
		self::assertSame( 0, $rollback?->summary['rollback_failed'] ?? 0 );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 80, '' ) );
	}

	public function test_mixed_rollback_processed_equals_restored_skipped_and_failed(): void {
		$this->add_products( [ 81, 82, 83 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 81, 82, 83 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();

		$this->scopes->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 82, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
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
		$broken   = $this->jobs->find_item( (int) $rollback->id, 'product', 83, 'SKU-83' );
		self::assertNotNull( $broken );
		$this->jobs->save_item(
			$broken->with(
				[
					'before_snapshot' => [
						'scalars'     => [
							ConfigurationFieldKey::FULFILMENT_AVAILABILITY => [
								'mode'  => 'not_a_real_mode',
								'value' => 'x',
							],
						],
						'collections' => [
							ConfigurationFieldKey::DELIVERY_OFFER_IDS => [
								'mode'    => 'replace',
								'members' => [ 11 ],
							],
						],
					],
				]
			)
		);
		$this->drain();
		$rollback = $this->engine->find( (int) $rollback->id );

		self::assertSame( BulkJobStatus::PartiallyRolledBack, $rollback?->status );
		self::assertSame( 1, $rollback?->summary['rollback_restored'] ?? 0 );
		self::assertSame( 1, $rollback?->summary['rollback_skipped'] ?? 0 );
		self::assertSame( 1, $rollback?->summary['rollback_failed'] ?? 0 );
		self::assertSame( 3, $rollback?->processed_count );
		self::assertSame( 3, $rollback?->total_count );
		self::assertSame( 0, $rollback?->changed_count );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 81, '' ) );
		self::assertSame( 'manual', $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 82, '' )?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->value );
		self::assertNotNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 83, '' ) );
	}

	public function test_apply_does_not_append_duplicate_representative_examples(): void {
		$this->add_products( [ 84 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 84 ] ),
			$this->set_international()
		);
		$this->drain();
		$preview = $this->engine->find( (int) $job->id );
		self::assertCount( 1, $preview?->summary['examples'] ?? [] );

		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();
		$applied  = $this->engine->find( (int) $job->id );
		$examples = $applied?->summary['examples'] ?? [];
		$keys     = [];
		foreach ( $examples as $example ) {
			$keys[] = (string) ( $example['target_type'] ?? '' ) . ':' . (string) ( $example['target_id'] ?? '' );
		}

		self::assertCount( 1, $examples );
		self::assertSame( [ 'product:84' ], $keys );
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

	public function test_delayed_scheduler_does_not_start_until_continue(): void {
		$ids = range( 1, 40 );
		$this->add_products( $ids );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( $ids ),
			$this->set_international(),
			25
		);

		self::assertSame( BulkJobStatus::Previewing, $job->status );
		self::assertSame( 0, $job->processed_count );
		self::assertSame( 1, $this->queue->queued_count() );

		$job = $this->engine->continue_job( (int) $job->id );
		self::assertLessThanOrEqual( 25, $job->enumerated_count );
		self::assertLessThanOrEqual( 25, $job->processed_count );
		self::assertNotSame( BulkJobStatus::Ready, $job->status );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 1, '' ) );
	}

	public function test_scheduler_never_running_leaves_durable_preview_unfailed(): void {
		$this->add_products( [ 1, 2 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 1, 2 ] ),
			$this->set_international()
		);

		$again = $this->engine->find( (int) $job->id );
		self::assertSame( BulkJobStatus::Previewing, $again?->status );
		self::assertSame( 0, $again?->processed_count );
		self::assertNull( $again?->error_code );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 1, '' ) );
	}

	public function test_continue_is_blocked_while_another_worker_holds_the_claim(): void {
		$this->add_products( range( 1, 10 ) );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( range( 1, 10 ) ),
			$this->set_international()
		);
		$job_id = (int) $job->id;
		$held   = $this->jobs->claim_job( $job_id, 'held-worker', 300 );
		self::assertNotNull( $held );

		$after = $this->engine->continue_job( $job_id );
		self::assertSame( 0, $after->processed_count );
		self::assertSame( 'held-worker', $after->claim_token );

		$this->jobs->release_job_claim( $job_id, 'held-worker' );
		$resumed = $this->engine->continue_job( $job_id );
		self::assertGreaterThan( 0, $resumed->enumerated_count + $resumed->processed_count );
	}

	public function test_reopening_bulk_tools_does_not_cancel_and_resume_is_idempotent(): void {
		$this->add_products( [ 1 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 1 ] ),
			$this->set_international()
		);
		$job_id = (int) $job->id;

		$reopened = $this->engine->find( $job_id );
		self::assertSame( BulkJobStatus::Previewing, $reopened?->status );
		self::assertFalse( $reopened?->cancel_requested );

		$this->engine->continue_job( $job_id );
		$ready = $this->engine->continue_job( $job_id );
		self::assertSame( BulkJobStatus::Ready, $ready->status );
		self::assertSame( 1, $ready->processed_count );

		$again = $this->engine->continue_job( $job_id );
		self::assertSame( BulkJobStatus::Ready, $again->status );
		self::assertSame( 1, $again->processed_count );
		self::assertSame( 1, $again->changed_count );
	}

	public function test_apply_resume_does_not_duplicate_mutations(): void {
		$this->add_products( [ 90, 91 ] );
		$preview = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 90, 91 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $preview->id, 1 );

		$first = $this->engine->continue_job( (int) $preview->id );
		self::assertLessThanOrEqual( 25, $first->processed_count );
		$fingerprint = $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 90, '' )?->fingerprint();

		while ( $job = $this->engine->find( (int) $preview->id ) ) {
			if ( $job->status->is_terminal() ) {
				break;
			}
			$this->engine->continue_job( (int) $preview->id );
		}

		$done = $this->engine->find( (int) $preview->id );
		self::assertSame( BulkJobStatus::Completed, $done?->status );
		self::assertSame( 2, $done?->processed_count );
		self::assertSame( 2, $done?->changed_count );
		if ( null !== $fingerprint ) {
			self::assertSame(
				$fingerprint,
				$this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 90, '' )?->fingerprint()
			);
		}
	}

	public function test_rollback_resume_does_not_duplicate_restore(): void {
		$this->add_products( [ 92, 93 ] );
		$preview = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 92, 93 ] ),
			$this->set_international()
		);
		$this->drain();
		$this->engine->apply( (int) $preview->id, 1 );
		$this->drain();
		$child = $this->engine->rollback( (int) $preview->id, 1 );

		$this->engine->continue_job( (int) $child->id );
		$mid = $this->engine->find( (int) $child->id );
		self::assertNotNull( $mid );
		self::assertLessThanOrEqual( 2, $mid->processed_count );
		self::assertLessThanOrEqual( 2, (int) ( $mid->summary['rollback_restored'] ?? 0 ) );

		$this->drain();
		$done = $this->engine->find( (int) $child->id );
		self::assertTrue( in_array( $done?->status, [ BulkJobStatus::RolledBack, BulkJobStatus::PartiallyRolledBack ], true ) );
		self::assertSame( 2, $done?->processed_count );
		self::assertSame( 2, $done?->summary['rollback_restored'] ?? 0 );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 92, '' ) );
	}

	public function test_cancel_while_waiting_does_not_require_a_later_runner(): void {
		$this->add_products( [ 94, 95 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 94, 95 ] ),
			$this->set_international()
		);
		$cancelled = $this->engine->cancel( (int) $job->id );

		self::assertSame( BulkJobStatus::Cancelled, $cancelled->status );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 94, '' ) );
	}

	public function test_cancel_during_processing_keeps_counters_and_does_not_reverse(): void {
		$ids = range( 1, 40 );
		$this->add_products( $ids );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( $ids ),
			$this->set_international(),
			25
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->engine->run_next_tick( (int) $job->id );
		$mid = $this->engine->find( (int) $job->id );
		self::assertNotNull( $mid );
		self::assertGreaterThan( 0, $mid->processed_count );
		self::assertLessThan( 40, $mid->processed_count );
		$processed_before_cancel = $mid->processed_count;
		$changed_before_cancel   = $mid->changed_count;

		$cancelled = $this->engine->cancel( (int) $job->id );
		self::assertTrue(
			in_array( $cancelled->status, [ BulkJobStatus::Cancelled, BulkJobStatus::CancelRequested ], true )
		);
		self::assertGreaterThanOrEqual( $processed_before_cancel, $cancelled->processed_count );
		self::assertGreaterThanOrEqual( $changed_before_cancel, $cancelled->changed_count );

		$this->drain();
		$final = $this->engine->find( (int) $job->id );
		self::assertSame( BulkJobStatus::Cancelled, $final?->status );
		self::assertSame( $cancelled->processed_count, $final?->processed_count );
		self::assertSame( $cancelled->changed_count, $final?->changed_count );
		self::assertNotNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 1, '' ) );
		self::assertNull( $this->scopes->findByScopeAndSlice( ConfigurationScopeType::Product, 40, '' ) );
	}

	public function test_preview_continue_does_not_lose_counters(): void {
		$ids = range( 1, 40 );
		$this->add_products( $ids );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( $ids ),
			$this->set_international(),
			25
		);
		$first  = $this->engine->continue_job( (int) $job->id );
		$second = $this->engine->continue_job( (int) $job->id );

		self::assertGreaterThanOrEqual( $first->enumerated_count, $second->enumerated_count );
		self::assertGreaterThanOrEqual( $first->processed_count, $second->processed_count );

		$this->drain();
		$ready = $this->engine->find( (int) $job->id );
		self::assertSame( BulkJobStatus::Ready, $ready?->status );
		self::assertSame( 40, $ready?->processed_count );
		self::assertSame( 40, $ready?->total_count );
		self::assertSame( $ready->changed_count + $ready->skipped_count + $ready->failed_count, $ready->processed_count );
	}

	public function test_continue_never_processes_an_unbounded_request(): void {
		$ids = range( 200, 299 );
		$this->add_products( $ids );
		$job = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( $ids ),
			$this->set_international(),
			25
		);
		$after = $this->engine->continue_job( (int) $job->id );

		self::assertLessThanOrEqual( 25, $after->enumerated_count );
		self::assertLessThanOrEqual( 25, $after->processed_count );
		self::assertSame( BulkJobStatus::Previewing, $after->status );
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
