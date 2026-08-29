<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Bulk;

use CetechDeliveryEngine\Application\Bulk\BulkJobEngine;
use CetechDeliveryEngine\Application\Bulk\BulkJobRunnerState;
use CetechDeliveryEngine\Application\Bulk\BulkJobWorker;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogFieldAction;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogScopeMutator;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTarget;
use CetechDeliveryEngine\Application\Bulk\Catalog\CatalogTargetDefinition;
use CetechDeliveryEngine\Application\Bulk\Catalog\InMemoryCatalogTargetQuery;
use CetechDeliveryEngine\Application\Bulk\Queue\InMemoryBoundedQueue;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Domain\Bulk\BulkJob;
use CetechDeliveryEngine\Domain\Bulk\BulkJobItem;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\DeliveryOffer\DeliveryOfferRepositoryInterface;
use CetechDeliveryEngine\Domain\Enum\BulkJobItemStatus;
use CetechDeliveryEngine\Domain\Enum\BulkJobStatus;
use CetechDeliveryEngine\Domain\Enum\BulkOperationType;
use CetechDeliveryEngine\Domain\Enum\BulkTargetScope;
use CetechDeliveryEngine\Domain\Enum\BulkVariationPolicy;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkAmountMath;
use CetechDeliveryEngine\Domain\RateCard\RateCardBulkMutator;
use CetechDeliveryEngine\Domain\RateCard\RateCardRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryBulkJobRepository;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Presentation\Admin\BulkJobAdminCopy;
use CetechDeliveryEngine\Presentation\Admin\BulkJobItemResultPresenter;
use CetechDeliveryEngine\Presentation\Admin\BulkJobTargetLabelResolver;
use PHPUnit\Framework\TestCase;

final class BulkJobBulk9RepairTest extends TestCase {

	private InMemoryScopedConfigurationRepository $scopes;

	private InMemoryBulkJobRepository $jobs;

	private InMemoryCatalogTargetQuery $targets;

	private InMemoryBoundedQueue $queue;

	private BulkJobEngine $engine;

	private SiteWideDefaultsService $defaults;

	private ArrayRateCardStore $rates;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();
		$this->scopes  = new InMemoryScopedConfigurationRepository();
		$this->jobs    = new InMemoryBulkJobRepository();
		$this->targets = new InMemoryCatalogTargetQuery();
		$this->queue   = new InMemoryBoundedQueue();
		$this->rates   = new ArrayRateCardStore();

		$settings = new SiteWideDefaultsSettings();
		$offers   = new ArrayDeliveryOfferStore();
		$resolver = new EffectiveConfigurationResolver(
			$this->scopes,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $offers ),
			$settings
		);
		$catalog = new InMemoryCatalogIndex( [ 49164 => [ 'label' => 'T QA Beta Test Product', 'type' => 'simple' ] ] );
		$classifier = new CatalogInheritanceClassifier(
			$this->scopes,
			$catalog,
			$resolver,
			$settings,
			$this->legacy_repo()
		);
		$this->defaults = new SiteWideDefaultsService(
			$this->scopes,
			$settings,
			$classifier,
			$resolver,
			$catalog
		);
		$mutator = new CatalogScopeMutator(
			$this->scopes,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $offers ),
			$settings
		);
		$rate_mutator = new RateCardBulkMutator( $this->rates, $offers );
		$worker       = new BulkJobWorker( $this->jobs, $this->targets, $mutator, $this->queue, 30, $rate_mutator );
		$this->engine = new BulkJobEngine( $this->jobs, $this->queue, $worker );
		$this->seed_site_wide();
	}

	public function test_validation_scan_resolves_through_ecr_and_cannot_apply(): void {
		$this->add_products( [ 49164 ] );
		$job = $this->engine->create_preview(
			BulkOperationType::ValidationScan,
			1,
			$this->selected( [ 49164 ] ),
			[]
		);
		$this->drain();
		$job = $this->engine->find( (int) $job->id );
		self::assertSame( BulkJobStatus::Completed, $job?->status );
		self::assertTrue( $job?->dry_run );
		self::assertFalse( $job?->status->allows_apply() );
		self::assertFalse( BulkJobAdminCopy::shows_rollback( $job ) );

		$items = $this->jobs->list_items( (int) $job->id, 10, 0 );
		self::assertCount( 1, $items );
		self::assertSame( 'valid', $items[0]->result['scan_verdict'] ?? null );
		self::assertSame( 'Valid / Healthy', $items[0]->result['scan_label'] ?? null );
		self::assertNotEmpty( $items[0]->result['effective_fulfilment'] ?? '' );
		self::assertSame( [], $this->scopes->findByScope( \CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product, 49164 ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->engine->apply( (int) $job->id, 1 );
	}

	public function test_validation_scan_records_invalid_when_product_has_no_usable_path(): void {
		$this->defaults->apply_site_wide(
			[
				FulfilmentAvailability::InStore->value,
				FulfilmentAvailability::InternationalFulfilment->value,
				FulfilmentAvailability::InWarehouse->value,
			],
			FulfilmentAvailability::InWarehouse->value,
			false
		);
		$this->add_products( [ 80 ] );
		$this->scopes->saveScopedConfiguration(
			new \CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration(
				new ConfigurationScope(
					null,
					\CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product,
					80,
					'',
					null,
					\CetechDeliveryEngine\Domain\Enum\RecordStatus::Active,
					1,
					\CetechDeliveryEngine\Domain\Enum\ConfigurationSource::Native,
					null
				),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => \CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[]
					),
				]
			)
		);

		$job = $this->engine->create_preview(
			BulkOperationType::ValidationScan,
			1,
			$this->selected( [ 80 ] ),
			[]
		);
		$this->drain();
		$job   = $this->engine->find( (int) $job->id );
		$items = $this->jobs->list_items( (int) $job->id, 10, 0 );
		self::assertSame( BulkJobStatus::Completed, $job?->status );
		self::assertSame( 'invalid', $items[0]->result['scan_verdict'] ?? null );
		self::assertFalse( $job?->status->allows_apply() );
		self::assertNotNull(
			$this->scopes->findByScopeAndSlice( \CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product, 80, '' )
		);
	}

	public function test_remove_last_valid_option_is_blocked_and_valid_add_replace_remain_allowed(): void {
		$this->defaults->apply_site_wide(
			[
				FulfilmentAvailability::InStore->value,
				FulfilmentAvailability::InternationalFulfilment->value,
				FulfilmentAvailability::InWarehouse->value,
			],
			FulfilmentAvailability::InWarehouse->value,
			false
		);
		$this->add_products( [ 81, 82, 83 ] );

		$remove = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 81 ] ),
			[
				'field_actions' => [
					[
						'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						'action'    => CatalogFieldAction::COLLECTION_REMOVE,
						'members'   => [ 11 ],
					],
				],
			]
		);
		$this->drain();
		$remove = $this->engine->find( (int) $remove->id );
		$failed = $this->jobs->list_items( (int) $remove->id, 10, 0, BulkJobItemStatus::Failed );
		self::assertSame( BulkJobStatus::Ready, $remove?->status );
		self::assertSame( 1, $remove?->failed_count );
		self::assertSame( 'no_valid_delivery_path', $failed[0]->error_code ?? null );
		self::assertNull( $this->scopes->findByScopeAndSlice( \CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product, 81, '' ) );

		$invalid_replace = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 82 ] ),
			[
				'field_actions' => [
					[
						'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						'action'    => CatalogFieldAction::COLLECTION_REPLACE,
						'members'   => [ 101 ],
					],
				],
			]
		);
		$this->drain();
		$invalid_replace = $this->engine->find( (int) $invalid_replace->id );
		self::assertSame( 1, $invalid_replace?->failed_count );
		self::assertNull( $this->scopes->findByScopeAndSlice( \CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product, 82, '' ) );

		$add = $this->engine->create_preview(
			BulkOperationType::CatalogUpdate,
			1,
			$this->selected( [ 83 ] ),
			[
				'field_actions' => [
					[
						'field_key' => ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						'action'    => CatalogFieldAction::COLLECTION_ADD,
						'members'   => [ 21 ],
					],
				],
			]
		);
		$this->drain();
		$add = $this->engine->find( (int) $add->id );
		self::assertSame( 1, $add?->changed_count );
		self::assertSame( 0, $add?->failed_count );
		$this->engine->apply( (int) $add->id, 1 );
		$this->drain();
		self::assertNotNull( $this->scopes->findByScopeAndSlice( \CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType::Product, 83, '' ) );
	}

	public function test_rate_card_percent_apply_and_immediate_rollback_restores_snapshot(): void {
		$this->rates->save(
			[
				'id'            => 1,
				'internal_code' => 'accra_standard',
				'base_amount'   => '50.0000',
				'base_currency' => 'GHS',
				'status'        => 'active',
				'priority'      => 100,
			]
		);
		$job = $this->engine->create_preview(
			BulkOperationType::RateCardUpdate,
			1,
			$this->selected( [ 1 ] ),
			[
				'amount_op'    => 'increase_percent',
				'amount_value' => '10',
			]
		);
		$this->drain();
		$job   = $this->engine->find( (int) $job->id );
		$items = $this->jobs->list_items( (int) $job->id, 10, 0 );
		self::assertSame( '50.0000', $items[0]->before_snapshot['base_amount'] ?? null );
		self::assertSame( '55.0000', $items[0]->result['proposed_amount'] ?? null );
		self::assertSame( 1, $job?->changed_count );

		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();
		self::assertSame( '55.0000', $this->rates->findById( 1 )['base_amount'] ?? null );

		$rollback = $this->engine->rollback( (int) $job->id, 1 );
		$this->drain();
		$rollback = $this->engine->find( (int) $rollback->id );
		self::assertSame( BulkJobStatus::RolledBack, $rollback?->status );
		self::assertSame( 1, $rollback?->summary['rollback_restored'] ?? 0 );
		self::assertSame( '50.0000', $this->rates->findById( 1 )['base_amount'] ?? null );
	}

	public function test_genuine_post_apply_rate_card_edit_is_conflict_skipped(): void {
		$this->rates->save(
			[
				'id'            => 2,
				'internal_code' => 'accra_express',
				'base_amount'   => '50.0000',
				'base_currency' => 'GHS',
				'status'        => 'active',
				'priority'      => 100,
			]
		);
		$job = $this->engine->create_preview(
			BulkOperationType::RateCardUpdate,
			1,
			$this->selected( [ 2 ] ),
			[
				'amount_op'    => 'percent',
				'amount_value' => '10',
			]
		);
		$this->drain();
		$this->engine->apply( (int) $job->id, 1 );
		$this->drain();
		self::assertSame( '55.0000', $this->rates->findById( 2 )['base_amount'] ?? null );

		$this->rates->save(
			array_merge(
				$this->rates->findById( 2 ) ?? [],
				[ 'base_amount' => '60.0000' ]
			)
		);

		$rollback = $this->engine->rollback( (int) $job->id, 1 );
		$this->drain();
		$rollback = $this->engine->find( (int) $rollback->id );
		$items    = $this->jobs->list_items( (int) $rollback->id, 10, 0 );
		self::assertSame( BulkJobStatus::PartiallyRolledBack, $rollback?->status );
		self::assertSame( 1, $rollback?->summary['rollback_skipped'] ?? 0 );
		self::assertSame( 'edited_after_job', $items[0]->error_code ?? null );
		self::assertSame( '60.0000', $this->rates->findById( 2 )['base_amount'] ?? null );
	}

	public function test_rate_card_and_config_cards_do_not_render_as_products(): void {
		$rate_item = BulkJobItem::pending( 1, 'rate_card', 1, 'accra_standard' )->with(
			[
				'status'          => BulkJobItemStatus::Changed,
				'before_snapshot' => [
					'base_amount'  => '50.0000',
					'currency'     => 'GHS',
					'display_name' => 'Standard Delivery — Accra',
				],
				'result'          => [
					'entity_type'     => 'rate_card',
					'current_amount'  => '50.0000',
					'proposed_amount' => '55.0000',
					'currency'        => 'GHS',
					'entity_label'    => 'Standard Delivery — Accra',
				],
			]
		);
		$blocks = ( new BulkJobItemResultPresenter() )->blocks(
			$rate_item,
			\CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest::from_array( [] )
		);
		self::assertSame( 'GHS 50.00', $blocks[0]['current'] );
		self::assertSame( 'GHS 55.00', $blocks[0]['proposed'] );
		self::assertSame( 'Delivery Charge', BulkJobAdminCopy::target_type_label( 'rate_card' ) );
		$display = ( new BulkJobTargetLabelResolver() )->display( $rate_item );
		self::assertSame( 'Standard Delivery — Accra', $display['primary'] );
		self::assertStringContainsString( 'Delivery Charge', $display['secondary'] );
		self::assertStringNotContainsString( 'Product #', $display['secondary'] );

		$config_item = BulkJobItem::pending( 2, 'delivery_options', 5, 'standard_delivery' )->with(
			[
				'status' => BulkJobItemStatus::Unchanged,
				'result' => [
					'entity_type'      => 'delivery_options',
					'entity_label'     => 'Standard Delivery',
					'current_summary'  => 'Already present',
					'proposed_summary' => 'An entity with this code already exists.',
				],
			]
		);
		$config_blocks = ( new BulkJobItemResultPresenter() )->blocks(
			$config_item,
			\CetechDeliveryEngine\Application\Bulk\Catalog\CatalogActionManifest::from_array( [] )
		);
		self::assertSame( 'Delivery Option', BulkJobAdminCopy::target_type_label( 'delivery_options' ) );
		self::assertSame( 'Already present', $config_blocks[0]['current'] );
		self::assertNotSame( '', $config_blocks[0]['proposed'] );
		$config_display = ( new BulkJobTargetLabelResolver() )->display( $config_item );
		self::assertSame( 'Standard Delivery', $config_display['primary'] );
		self::assertStringContainsString( 'Delivery Option', $config_display['secondary'] );
	}

	public function test_changed_zero_job_has_no_rollback_action(): void {
		$job = BulkJob::create(
			BulkOperationType::ConfigImport,
			1,
			[ 'scope' => 'selected_ids' ],
			[]
		)->with(
			[
				'id'              => 17,
				'status'          => BulkJobStatus::Completed,
				'dry_run'         => false,
				'changed_count'   => 0,
				'skipped_count'   => 24,
				'processed_count' => 24,
				'total_count'     => 24,
				'failed_count'    => 0,
			]
		);
		self::assertTrue( $job->status->allows_rollback() );
		self::assertFalse( BulkJobAdminCopy::shows_rollback( $job ) );
		$this->jobs->save_job( $job );
		$this->expectException( \InvalidArgumentException::class );
		$this->engine->rollback( 17, 1 );
	}

	public function test_terminal_ui_waits_for_coherent_counters(): void {
		$incoherent = BulkJob::create(
			BulkOperationType::CatalogUpdate,
			1,
			[ 'scope' => 'selected_ids', 'selected_ids' => [ 1 ] ],
			[]
		)->with(
			[
				'id'              => 9,
				'status'          => BulkJobStatus::Completed,
				'dry_run'         => false,
				'total_count'     => 1,
				'processed_count' => 1,
				'changed_count'   => 0,
				'skipped_count'   => 0,
				'failed_count'    => 0,
			]
		);
		$state   = BulkJobRunnerState::from_job( $incoherent );
		$payload = BulkJobAdminCopy::progress_payload( $incoherent );
		self::assertSame( BulkJobRunnerState::PHASE_FINALIZING, $state->phase );
		self::assertFalse( $payload['terminal'] );
		self::assertFalse( $payload['coherent'] );
		self::assertSame( 'Finalizing', $payload['status_label'] );

		$coherent = $incoherent->with(
			[
				'changed_count' => 1,
			]
		);
		$ready = BulkJobRunnerState::from_job( $coherent );
		self::assertSame( BulkJobRunnerState::PHASE_COMPLETED, $ready->phase );
		self::assertTrue( BulkJobAdminCopy::progress_payload( $coherent )['terminal'] );
	}

	public function test_explicit_increase_decrease_amount_semantics(): void {
		self::assertSame(
			[ 'op' => 'percent', 'value' => '10' ],
			RateCardBulkAmountMath::normalize_operation( 'increase_percent', '10' )
		);
		self::assertSame(
			[ 'op' => 'percent', 'value' => '-10' ],
			RateCardBulkAmountMath::normalize_operation( 'decrease_percent', '10' )
		);
		self::assertSame( '55.0000', RateCardBulkAmountMath::increase_percent( '50.0000', '10' ) );
		self::assertSame( '45.0000', RateCardBulkAmountMath::increase_percent( '50.0000', '-10' ) );
		$this->expectException( \InvalidArgumentException::class );
		RateCardBulkAmountMath::normalize_operation( 'increase_percent', '' );
	}

	private function drain( int $max = 200 ): int {
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

final class ArrayDeliveryOfferStore implements DeliveryOfferRepositoryInterface {

	public function findById( int $id ): ?array {
		$route = match ( $id ) {
			11, 21 => 'local_delivery',
			101 => 'air',
			102 => 'sea',
			default => null,
		};
		if ( null === $route ) {
			return null;
		}

		return [
			'id'            => $id,
			'internal_code' => 'offer-' . $id,
			'name'          => 'Offer ' . $id,
			'route'         => $route,
			'status'        => 'active',
		];
	}

	public function findByCode( string $code ): ?array {
		return null;
	}

	public function save( array $data ): int {
		return 0;
	}

	public function list( array $criteria = [] ): array {
		return [];
	}

	public function softDelete( int $id ): bool {
		return false;
	}

	public function hardDelete( int $id ): bool {
		return false;
	}

	public function count_all(): int {
		return 0;
	}
}

final class ArrayRateCardStore implements RateCardRepositoryInterface {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

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
		$data['id']          = $id;
		$data['base_amount'] = \CetechDeliveryEngine\Domain\RateCard\RateCardAmountFormatter::format( $data['base_amount'] ?? null );
		$this->rows[ $id ]   = $data;

		return $id;
	}

	public function list( array $criteria = [] ): array {
		return array_values( $this->rows );
	}

	public function softDelete( int $id ): bool {
		return false;
	}

	public function hardDelete( int $id ): bool {
		unset( $this->rows[ $id ] );

		return true;
	}

	public function count_all(): int {
		return count( $this->rows );
	}

	public function countByDeliveryOfferId( int $delivery_offer_id ): int {
		return 0;
	}

	public function countByDestinationZoneId( int $destination_zone_id ): int {
		return 0;
	}

	public function countByLogisticsProfileId( int $logistics_profile_id ): int {
		return 0;
	}

	public function countOrderSnapshotReferences( int $rate_card_id ): int {
		return 0;
	}

	public function listActiveForQuoteMatch( int $delivery_offer_id, int $destination_zone_id, string $currency_code ): array {
		return [];
	}
}
