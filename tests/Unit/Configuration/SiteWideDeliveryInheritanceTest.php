<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\Catalog\CatalogInheritanceClassifier;
use CetechDeliveryEngine\Application\Configuration\Catalog\InMemoryCatalogIndex;
use CetechDeliveryEngine\Application\Configuration\Catalog\NeedsAttentionQuery;
use CetechDeliveryEngine\Application\Configuration\ClassicCheckoutRuntimeActivation;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\InMemorySiteWideDefaultsPolicy;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\OperationalStateService;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\SetupWizardProgress;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultSummary;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsService;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Bootstrap\FeatureFlags;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\CollectionConfigurationMode;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\Enum\ScalarConfigurationMode;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfile;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Domain\ProductRule\ProductDeliveryRuleRepositoryInterface;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

final class SiteWideDeliveryInheritanceTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;

	private InMemoryCatalogIndex $catalog;

	private SiteWideDefaultsSettings $settings;

	private SiteWideDefaultsService $defaults;

	private EffectiveConfigurationResolver $resolver;

	protected function setUp(): void {
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->catalog    = new InMemoryCatalogIndex(
			[
				101 => [ 'label' => 'Simple Lamp', 'type' => 'simple' ],
				201 => [
					'label'      => 'Variable Chair',
					'type'       => 'variable',
					'variations' => [
						202 => 'Chair / Oak',
						203 => 'Chair / Walnut',
					],
				],
				301 => [ 'label' => 'International Radio', 'type' => 'simple' ],
				401 => [ 'label' => 'In Store Speaker', 'type' => 'simple' ],
				501 => [ 'label' => 'Legacy Only Item', 'type' => 'simple' ],
			]
		);
		$this->settings = new SiteWideDefaultsSettings();
		$this->resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService(),
			$this->settings
		);
		$classifier     = new CatalogInheritanceClassifier(
			$this->repository,
			$this->catalog,
			$this->resolver,
			$this->settings,
			$this->legacy_repo()
		);
		$this->defaults = new SiteWideDefaultsService(
			$this->repository,
			$this->settings,
			$classifier,
			$this->resolver,
			$this->catalog
		);
	}

	public function test_fresh_install_primary_warehouse_existing_simple_product_inherits(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( FulfilmentAvailability::InWarehouse->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( EffectiveFieldState::Valid, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->state );
		self::assertSame( [ 11 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( '3–5 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( 'global', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->provenance->source_label );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, '' ) );
	}

	public function test_fresh_install_primary_warehouse_existing_variable_parent_inherits(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 201 ) );

		self::assertSame( FulfilmentAvailability::InWarehouse->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( [ 11 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_variation_with_no_override_inherits_parent_default(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 201, 202 ) );

		self::assertSame( '3–5 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( 'global', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->provenance->source_label );
	}

	public function test_product_classified_international_uses_international_default(): void {
		$this->seed_warehouse_defaults();
		$this->seed_international_defaults();
		$this->defaults->apply_site_wide(
			[ FulfilmentAvailability::InWarehouse->value, FulfilmentAvailability::InternationalFulfilment->value ],
			FulfilmentAvailability::InWarehouse->value,
			false
		);
		$this->defaults->classify_product( 301, FulfilmentAvailability::InternationalFulfilment->value );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 301 ) );

		self::assertSame( FulfilmentAvailability::InternationalFulfilment->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( [ 88 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( '7–21 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
	}

	public function test_product_classified_in_store_uses_in_store_default(): void {
		$this->seed_warehouse_defaults();
		$this->seed_in_store_defaults();
		$this->defaults->apply_site_wide(
			[ FulfilmentAvailability::InWarehouse->value, FulfilmentAvailability::InStore->value ],
			FulfilmentAvailability::InWarehouse->value,
			false
		);
		$this->defaults->classify_product( 401, FulfilmentAvailability::InStore->value );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 401 ) );

		self::assertSame( FulfilmentAvailability::InStore->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( FulfilmentChoice::Delivery->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->value );
		self::assertSame( [ 21 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_new_product_after_setup_inherits_automatically(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 999 ) );

		self::assertSame( FulfilmentAvailability::InWarehouse->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( '3–5 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
	}

	public function test_site_wide_eta_change_updates_inheriting_products(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );

		$before = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( '3–5 days', $before->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );

		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2–4 days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ 11 ] ],
			]
		);

		$after = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( '2–4 days', $after->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
	}

	public function test_product_eta_override_survives_site_wide_eta_change(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->save_product_eta( 101, '7–10 days' );

		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2–4 days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ 11 ] ],
			]
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( '7–10 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( 'product', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->provenance->source_label );
	}

	public function test_product_eta_override_still_inherits_updated_offers(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->save_product_eta( 101, '7–10 days' );

		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2–4 days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ 12 ] ],
			]
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( '7–10 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( [ 12 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( 'global', $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->provenance->source_label );
	}

	public function test_variation_override_survives_product_and_site_wide_change(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Variation, 202, '', 201, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'Next day' ),
				]
			)
		);

		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2–4 days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ 11 ] ],
			]
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 201, 202 ) );
		self::assertSame( 'Next day', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( 'variation', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->provenance->source_label );
		self::assertSame( [ 11 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_reset_product_restores_inheritance(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->save_product_eta( 101, '7–10 days' );

		self::assertTrue( $this->defaults->reset_product_to_site_wide( 101 ) );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( '3–5 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( 'global', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->provenance->source_label );
	}

	public function test_reset_variation_restores_product_inheritance(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Variation, 202, '', 201, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, 'Next day' ),
				]
			)
		);

		self::assertTrue( $this->defaults->reset_variation_to_product( 202 ) );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 201, 202 ) );
		self::assertSame( '3–5 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
	}

	public function test_collections_preserve_add_remove_replace(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 15 ] ),
				]
			)
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( [ 11, 15 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 2, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::remove( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 11 ] ),
				]
			)
		);
		$this->resolver->clearMemoization();
		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( [], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 3, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 19 ] ),
				]
			)
		);
		$this->resolver->clearMemoization();
		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( [ 19 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_hard_constraints_outrank_defaults(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_CHOICE,
						FulfilmentChoice::StorePickup->value
					),
				]
			)
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( EffectiveFieldState::Invalid, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );
	}

	public function test_legacy_dependent_item_is_not_silently_overwritten(): void {
		$this->seed_warehouse_defaults();
		$preview = $this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, true );

		self::assertSame( 1, $preview['skipped_legacy'] );
		self::assertNotNull( $this->legacy_repo()->findByTarget( ProductTargetType::Product->value, 501 ) );
	}

	public function test_migration_preview_counts_are_calculated(): void {
		$this->seed_warehouse_defaults();
		$this->save_product_eta( 101, '7–10 days' );
		$preview = $this->defaults->preview();

		self::assertSame( 5, $preview->published_products );
		self::assertGreaterThanOrEqual( 1, $preview->product_exceptions );
		self::assertGreaterThanOrEqual( 1, $preview->legacy_dependent );
		self::assertGreaterThanOrEqual( 1, $preview->can_safely_inherit );
	}

	public function test_historical_snapshot_unchanged_after_global_default_change(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );

		$snapshot = [
			'fulfilment' => FulfilmentAvailability::InWarehouse->value,
			'eta'        => $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) )->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value,
			'offers'     => $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) )->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members,
		];

		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => [ 'mode' => 'override', 'value' => '2–4 days' ],
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => [ 'mode' => 'replace', 'members' => [ 12 ] ],
			]
		);

		self::assertSame( '3–5 days', $snapshot['eta'] );
		self::assertSame( [ 11 ], $snapshot['offers'] );
		self::assertSame( '2–4 days', $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) )->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
	}

	public function test_apply_does_not_copy_defaults_onto_unconfigured_products(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, true );

		self::assertSame( [], $this->repository->findByScope( ConfigurationScopeType::Product, 101 ) );
		self::assertSame( [], $this->repository->findByScope( ConfigurationScopeType::Product, 201 ) );
	}

	public function test_matching_migrated_overrides_convert_to_inherit(): void {
		$this->seed_warehouse_defaults();
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, FulfilmentAvailability::InWarehouse->value, null, RecordStatus::Active, 1, ConfigurationSource::Migrated, 9 ),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override( ConfigurationFieldKey::FULFILMENT_AVAILABILITY, FulfilmentAvailability::InWarehouse->value ),
					ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, '3–5 days' ),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [ 11 ] ),
				]
			)
		);

		$result = $this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, true );
		self::assertSame( 1, $result['converted_products'] );

		$scope = $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, 101, FulfilmentAvailability::InWarehouse->value );
		self::assertSame( ScalarConfigurationMode::Inherit, $scope?->scalars[ ConfigurationFieldKey::ESTIMATED_DELIVERY ]->mode );
		self::assertSame( CollectionConfigurationMode::Inherit, $scope?->collections[ ConfigurationFieldKey::DELIVERY_OFFER_IDS ]->mode );

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InWarehouse->value ) );
		self::assertSame( '3–5 days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( 'global', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->provenance->source_label );
	}

	public function test_registry_can_register_additional_profile(): void {
		FulfilmentProfileRegistry::register(
			new FulfilmentProfile(
				'supplier_direct',
				'Supplier Direct',
				'Supplier',
				FulfilmentAvailability::InWarehouse->value,
				true,
				false,
				false,
				'In Warehouse',
				'Future supported fulfilment type.'
			)
		);

		self::assertTrue( FulfilmentProfileRegistry::has( 'supplier_direct' ) );
		self::assertSame( 'Supplier Direct', FulfilmentProfileRegistry::get( 'supplier_direct' )?->label );
	}

	public function test_existing_empty_global_fallback_does_not_break_legacy_resolver_tests(): void {
		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService(),
			new InMemorySiteWideDefaultsPolicy()
		);
		$this->repository->ensureGlobalScope();
		$config = $resolver->resolve( new EffectiveConfigurationRequest( 101 ) );
		self::assertSame( EffectiveFieldState::Unresolved, $config->state );
	}

	public function test_needs_attention_reports_missing_offers(): void {
		foreach ( ClassicCheckoutRuntimeActivation::CHAIN as $flag ) {
			$GLOBALS['cetech_de_test_options'][ 'cetech_de_' . $flag ] = 1;
		}
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$query = $this->needs_attention_query();
		$items = $query->list();
		self::assertNotEmpty( $items );
		self::assertSame( 'Fulfilment type is incomplete.', $items[0]['reason'] );
	}

	public function test_preview_readiness_matches_needs_attention_for_empty_delivery_options(): void {
		$this->seed_warehouse_defaults();
		$this->defaults->apply_site_wide( [ FulfilmentAvailability::InWarehouse->value ], FulfilmentAvailability::InWarehouse->value, false );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace( ConfigurationFieldKey::DELIVERY_OFFER_IDS, [] ),
				]
			)
		);

		$assessor = new OperationalReadinessAssessor( $this->resolver );
		$preview  = $assessor->assess( 101 );
		$query    = $this->needs_attention_query( $assessor );
		$items    = $query->list();
		$match    = null;
		foreach ( $items as $item ) {
			if ( 101 === $item['id'] ) {
				$match = $item;
				break;
			}
		}

		self::assertFalse( $preview->is_ready );
		self::assertSame( 'Needs Attention', $preview->status_label );
		self::assertSame( 'No usable delivery option is configured.', $preview->reason );
		self::assertNotNull( $match );
		self::assertSame( $preview->reason, $match['reason'] );
		$field = $assessor->assess_field_for( 101, null, ConfigurationFieldKey::DELIVERY_OFFER_IDS );
		self::assertFalse( $field->is_ready );
		self::assertSame( $preview->reason, $field->reason );
	}

	private function needs_attention_query( ?OperationalReadinessAssessor $assessor = null ): NeedsAttentionQuery {
		$assessor ??= new OperationalReadinessAssessor( $this->resolver );
		$flags      = new FeatureFlags();
		$summaries  = new SiteWideDefaultSummary( $this->repository );
		$state      = new OperationalStateService(
			$flags,
			$this->settings,
			new SetupWizardProgress( $this->settings ),
			new ClassicCheckoutRuntimeActivation( $flags, $this->settings ),
			$summaries
		);
		$classifier = new CatalogInheritanceClassifier(
			$this->repository,
			$this->catalog,
			$this->resolver,
			$this->settings,
			$this->legacy_repo()
		);

		return new NeedsAttentionQuery( $this->catalog, $assessor, $state, $classifier, $this->repository );
	}

	private function seed_warehouse_defaults(): void {
		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InWarehouse->value,
			$this->complete_profile_fields(
				FulfilmentChoice::Delivery->value,
				'3–5 days',
				[ 11 ]
			)
		);
	}

	private function seed_international_defaults(): void {
		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InternationalFulfilment->value,
			$this->complete_profile_fields(
				FulfilmentChoice::Delivery->value,
				'7–21 days',
				[ 88 ]
			)
		);
	}

	private function seed_in_store_defaults(): void {
		$this->defaults->save_profile_defaults(
			FulfilmentAvailability::InStore->value,
			$this->complete_profile_fields(
				FulfilmentChoice::Delivery->value,
				'1–2 days',
				[ 21 ]
			)
		);
	}

	/**
	 * @param list<int> $offer_ids
	 *
	 * @return array<string, array{mode: string, value?: mixed, members?: list<int>}>
	 */
	private function complete_profile_fields( string $choice, string $eta, array $offer_ids ): array {
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

	private function save_product_eta( int $product_id, string $eta ): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, $product_id, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[
					ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override( ConfigurationFieldKey::ESTIMATED_DELIVERY, $eta ),
				]
			)
		);
	}

	private function legacy_repo(): ProductDeliveryRuleRepositoryInterface {
		return new class() implements ProductDeliveryRuleRepositoryInterface {
			public function findById( int $id ): ?array {
				return null;
			}

			public function findByTarget( string $target_type, int $target_id ): array {
				if ( ProductTargetType::Product->value === $target_type && 501 === $target_id ) {
					return [
						[
							'id' => 77,
							'target_type' => $target_type,
							'target_id' => $target_id,
							'fulfilment_availability' => FulfilmentAvailability::InWarehouse->value,
						],
					];
				}

				return [];
			}

			public function findByTargetAndAvailability( string $target_type, int $target_id, string $availability ): array {
				return [];
			}

			public function list( array $filters = [] ): array {
				return $this->findByTarget( ProductTargetType::Product->value, 501 );
			}

			public function listActive( array $filters = [] ): array {
				return $this->list( $filters );
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
				return 1;
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
