<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\OperationalReadinessAssessor;
use CetechDeliveryEngine\Application\Configuration\SiteWideDefaultsSettings;
use CetechDeliveryEngine\Application\Runtime\EcrProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\EcrToRuntimeConfigurationAdapter;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldRegistry;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\DeliveryRoute;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Domain\FulfilmentProfile\FulfilmentProfileRegistry;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use CetechDeliveryEngine\Tests\Unit\Runtime\FixedVariationRelationshipInspector;
use CetechDeliveryEngine\Tests\Unit\Runtime\InMemoryDeliveryOfferRepository;
use PHPUnit\Framework\TestCase;

/**
 * FLAIROC owner-QA reproduction: Site-wide required delivery fields are valid, but
 * optional/private/defaultable fields were wrongly making the whole ECR unresolved.
 */
final class SiteWideOptionalFieldValidityTest extends TestCase {

	private const SIMPLE_ID      = 4156;
	private const PARENT_ID      = 39420;
	private const VARIATION_ID   = 39422;
	private const QA_PRODUCT_ID  = 39705;
	private const OFFER_ID       = 1;

	private InMemoryScopedConfigurationRepository $repository;

	private InMemoryDeliveryOfferRepository $offers;

	private SiteWideDefaultsSettings $settings;

	private EffectiveConfigurationResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cetech_de_test_options'] = [];
		FulfilmentProfileRegistry::reset_for_tests();
		ConfigurationFieldRegistry::reset_for_tests();

		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->offers     = new InMemoryDeliveryOfferRepository();
		$this->offers->seed(
			self::OFFER_ID,
			[
				'status'                 => RecordStatus::Active->value,
				'public_label'           => 'FLAIROC QA Standard Delivery',
				'public_description'     => '',
				'route'                  => DeliveryRoute::LocalDelivery->value,
				'duration_unit'          => 'business_days',
				'default_processing_min' => 1,
				'default_processing_max' => 2,
				'default_transit_min'    => 1,
				'default_transit_max'    => 3,
				'default_final_mile_min' => 0,
				'default_final_mile_max' => 0,
			]
		);
		$this->offers->seed(
			88,
			[
				'status' => RecordStatus::Active->value,
				'public_label' => 'Air Freight',
				'route' => DeliveryRoute::Air->value,
			]
		);
		$this->offers->seed(
			99,
			[
				'status' => RecordStatus::Active->value,
				'public_label' => 'Sea Freight',
				'route' => DeliveryRoute::Sea->value,
			]
		);

		$this->settings = new SiteWideDefaultsSettings();
		$this->resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $this->offers ),
			$this->settings
		);
	}

	public function test_optional_private_fields_are_registry_optional(): void {
		self::assertTrue( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::LOGISTICS_PROFILE_ID ) );
		self::assertTrue( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::SUPPLIER_ID ) );
		self::assertTrue( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::ORIGIN_ID ) );
		self::assertTrue( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::PICKUP_LOCATION_ID ) );
		self::assertTrue( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::PRIORITY ) );
		self::assertFalse( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::FULFILMENT_AVAILABILITY ) );
		self::assertFalse( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::FULFILMENT_CHOICE ) );
		self::assertFalse( ConfigurationFieldRegistry::is_optional( ConfigurationFieldKey::DELIVERY_OFFER_IDS ) );
	}

	public function test_untouched_simple_product_inherits_sitewide_without_private_dimensions(): void {
		$this->complete_sitewide_warehouse_without_private_fields();

		$set    = $this->resolver->resolveAll( self::SIMPLE_ID, null );
		$config = $set->for_slice( ConfigurationScope::DEFAULT_SLICE_KEY );

		self::assertNotNull( $config );
		self::assertSame( [ ConfigurationScope::DEFAULT_SLICE_KEY ], $set->ordered_slice_keys );
		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( FulfilmentAvailability::InWarehouse->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( FulfilmentChoice::Delivery->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->value );
		self::assertSame( [ self::OFFER_ID ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( '3-5 business days', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( EffectiveFieldState::Unresolved, $config->scalar( ConfigurationFieldKey::LOGISTICS_PROFILE_ID )?->state );
		self::assertSame( EffectiveFieldState::Unresolved, $config->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->state );
		self::assertSame( EffectiveFieldState::Unresolved, $config->scalar( ConfigurationFieldKey::ORIGIN_ID )?->state );
		self::assertSame( EffectiveFieldState::Unresolved, $config->scalar( ConfigurationFieldKey::PRIORITY )?->state );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, self::SIMPLE_ID, '' ) );

		$runtime = $this->runtime_source()->resolve( ProductTargetType::Product->value, self::SIMPLE_ID );
		self::assertTrue( $runtime->result->success );
		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InWarehouse->value ] ?? null;
		self::assertNotNull( $rule );
		self::assertSame( [ self::OFFER_ID ], $rule->delivery_offer_ids );
		self::assertNull( $rule->logistics_profile_id );
		self::assertNull( $rule->supplier_id );
		self::assertNull( $rule->origin_id );
		self::assertSame( 100, $rule->priority );

		$options = ( new ProductDeliveryOptionsBuilder( $this->offers ) )->buildFromResolution( $runtime->result );
		self::assertCount( 1, $options );
		self::assertSame( 'FLAIROC QA Standard Delivery', $options[0]->delivery_offer_public_label );
		self::assertTrue( $options[0]->is_available );
	}

	public function test_untouched_variation_inherits_sitewide_through_parent(): void {
		$this->complete_sitewide_warehouse_without_private_fields();

		$set    = $this->resolver->resolveAll( self::PARENT_ID, self::VARIATION_ID );
		$config = $set->for_slice( ConfigurationScope::DEFAULT_SLICE_KEY );

		self::assertNotNull( $config );
		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Product, self::PARENT_ID, '' ) );
		self::assertNull( $this->repository->findByScopeAndSlice( ConfigurationScopeType::Variation, self::VARIATION_ID, '' ) );

		$runtime = $this->runtime_source( [ self::VARIATION_ID => self::PARENT_ID ] )
			->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );
		self::assertTrue( $runtime->result->success );
		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InWarehouse->value ] ?? null;
		self::assertNotNull( $rule );
		self::assertSame( [ self::OFFER_ID ], $rule->delivery_offer_ids );

		$options = ( new ProductDeliveryOptionsBuilder( $this->offers ) )->buildFromResolution( $runtime->result );
		self::assertCount( 1, $options );
		self::assertTrue( $options[0]->is_available );
	}

	public function test_explicit_product_exception_still_wins_field_by_field(): void {
		$this->complete_sitewide_warehouse_without_private_fields();
		$this->save_scope(
			ConfigurationScopeType::Product,
			self::QA_PRODUCT_ID,
			'',
			[
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 10 ),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 1 ),
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 1 ),
				ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 1 ),
			]
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( self::QA_PRODUCT_ID ) );

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( FulfilmentAvailability::InWarehouse->value, $config->scalar( ConfigurationFieldKey::FULFILMENT_AVAILABILITY )?->value );
		self::assertSame( [ self::OFFER_ID ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( 10, $config->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 1, $config->scalar( ConfigurationFieldKey::LOGISTICS_PROFILE_ID )?->value );
		self::assertSame( 'product', $config->scalar( ConfigurationFieldKey::PRIORITY )?->provenance->source_label );
		self::assertSame( 'global', $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->provenance->source_label );

		$runtime = $this->runtime_source()->resolve( ProductTargetType::Product->value, self::QA_PRODUCT_ID );
		self::assertTrue( $runtime->result->success );
		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InWarehouse->value ];
		self::assertSame( 10, $rule->priority );
		self::assertSame( 1, $rule->logistics_profile_id );
	}

	public function test_explicit_variation_exception_wins_only_overridden_fields(): void {
		$this->complete_sitewide_warehouse_without_private_fields();
		$this->save_scope(
			ConfigurationScopeType::Variation,
			self::VARIATION_ID,
			'',
			[
				ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::ESTIMATED_DELIVERY,
					'Next day'
				),
			],
			[],
			self::PARENT_ID
		);

		$config = $this->resolver->resolve(
			new EffectiveConfigurationRequest( self::PARENT_ID, self::VARIATION_ID, '', self::PARENT_ID )
		);

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( 'Next day', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->value );
		self::assertSame( 'variation', $config->scalar( ConfigurationFieldKey::ESTIMATED_DELIVERY )?->provenance->source_label );
		self::assertSame( [ self::OFFER_ID ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( 'global', $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->provenance->source_label );
	}

	public function test_missing_required_choice_still_fails_closed(): void {
		$this->mark_setup_complete();
		$this->save_warehouse_profile(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InWarehouse->value
				),
				ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::ESTIMATED_DELIVERY,
					'3-5 business days'
				),
			],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ self::OFFER_ID ]
				),
			]
		);

		$config  = $this->resolver->resolve( new EffectiveConfigurationRequest( self::SIMPLE_ID ) );
		$runtime = $this->runtime_source()->resolve( ProductTargetType::Product->value, self::SIMPLE_ID );

		self::assertSame( EffectiveFieldState::Unresolved, $config->state );
		self::assertSame( EffectiveFieldState::Unresolved, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );
		self::assertFalse( $runtime->result->success );
	}

	public function test_delivery_without_offers_still_fails_closed(): void {
		$this->mark_setup_complete();
		$this->save_warehouse_profile(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InWarehouse->value
				),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					FulfilmentChoice::Delivery->value
				),
				ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::ESTIMATED_DELIVERY,
					'3-5 business days'
				),
			]
		);

		$config  = $this->resolver->resolve( new EffectiveConfigurationRequest( self::SIMPLE_ID ) );
		$runtime = $this->runtime_source()->resolve( ProductTargetType::Product->value, self::SIMPLE_ID );

		self::assertSame( EffectiveFieldState::Unresolved, $config->state );
		self::assertSame( EffectiveFieldState::Unresolved, $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
		self::assertFalse( $runtime->result->success );
	}

	public function test_in_warehouse_cannot_expose_air_or_sea(): void {
		$this->mark_setup_complete();
		$this->save_warehouse_profile(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InWarehouse->value
				),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					FulfilmentChoice::Delivery->value
				),
			],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ self::OFFER_ID, 88, 99 ]
				),
			]
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( self::SIMPLE_ID ) );
		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( [ self::OFFER_ID ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_international_cannot_expose_local_or_pickup(): void {
		$this->mark_setup_complete( FulfilmentAvailability::InternationalFulfilment->value );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				ConfigurationScope::profileDefault( FulfilmentAvailability::InternationalFulfilment->value ),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
						FulfilmentAvailability::InternationalFulfilment->value
					),
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_CHOICE,
						FulfilmentChoice::StorePickup->value
					),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ self::OFFER_ID, 88 ]
					),
				]
			)
		);

		$config = $this->resolver->resolve(
			new EffectiveConfigurationRequest( self::SIMPLE_ID, null, FulfilmentAvailability::InternationalFulfilment->value )
		);

		self::assertSame( EffectiveFieldState::Invalid, $config->state );
		self::assertSame( EffectiveFieldState::Invalid, $config->scalar( ConfigurationFieldKey::FULFILMENT_CHOICE )?->state );
		self::assertSame( [ 88 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_in_store_cannot_expose_air_or_sea(): void {
		$this->mark_setup_complete( FulfilmentAvailability::InStore->value );
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				ConfigurationScope::profileDefault( FulfilmentAvailability::InStore->value ),
				[
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
						FulfilmentAvailability::InStore->value
					),
					ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
						ConfigurationFieldKey::FULFILMENT_CHOICE,
						FulfilmentChoice::Delivery->value
					),
				],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ self::OFFER_ID, 88, 99 ]
					),
				]
			)
		);

		$config = $this->resolver->resolve(
			new EffectiveConfigurationRequest( self::SIMPLE_ID, null, FulfilmentAvailability::InStore->value )
		);

		self::assertSame( EffectiveFieldState::Valid, $config->state );
		self::assertSame( [ self::OFFER_ID ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_setup_complete_can_resolve_untouched_product(): void {
		$this->complete_sitewide_warehouse_without_private_fields();
		self::assertTrue( $this->settings->is_setup_complete() );

		$readiness = ( new OperationalReadinessAssessor( $this->resolver ) )->assess( self::SIMPLE_ID );
		self::assertTrue( $readiness->is_ready );

		$runtime = $this->runtime_source()->resolve( ProductTargetType::Product->value, self::SIMPLE_ID );
		self::assertTrue( $runtime->result->success );
		self::assertNotEmpty(
			( new ProductDeliveryOptionsBuilder( $this->offers ) )->buildFromResolution( $runtime->result )
		);
	}

	private function complete_sitewide_warehouse_without_private_fields(): void {
		$this->mark_setup_complete();
		$this->save_warehouse_profile(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InWarehouse->value
				),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					FulfilmentChoice::Delivery->value
				),
				ConfigurationFieldKey::ESTIMATED_DELIVERY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::ESTIMATED_DELIVERY,
					'3-5 business days'
				),
			],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ self::OFFER_ID ]
				),
			]
		);
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function save_warehouse_profile( array $scalars, array $collections = [] ): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				ConfigurationScope::profileDefault( FulfilmentAvailability::InWarehouse->value ),
				$scalars,
				$collections
			)
		);
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function save_scope(
		ConfigurationScopeType $type,
		int $scope_id,
		string $slice_key,
		array $scalars = [],
		array $collections = [],
		?int $parent_product_id = null
	): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					$type,
					$scope_id,
					$slice_key,
					$parent_product_id,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				$scalars,
				$collections
			)
		);
	}

	private function mark_setup_complete( string $primary = FulfilmentAvailability::InWarehouse->value ): void {
		$this->settings->save(
			[
				'setup_completed' => true,
				'active_profiles' => [
					FulfilmentAvailability::InWarehouse->value,
					FulfilmentAvailability::InStore->value,
					FulfilmentAvailability::InternationalFulfilment->value,
				],
				'primary_profile' => $primary,
			]
		);
	}

	/**
	 * @param array<int, int> $variation_to_parent
	 */
	private function runtime_source( array $variation_to_parent = [] ): EcrProductDeliveryConfigurationSource {
		return new EcrProductDeliveryConfigurationSource(
			$this->resolver,
			new EcrToRuntimeConfigurationAdapter(),
			[] === $variation_to_parent ? null : new FixedVariationRelationshipInspector( $variation_to_parent )
		);
	}
}
