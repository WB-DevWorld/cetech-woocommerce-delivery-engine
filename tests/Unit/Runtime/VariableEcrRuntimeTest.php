<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Runtime;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\HardFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Application\Runtime\EcrProductDeliveryConfigurationSource;
use CetechDeliveryEngine\Application\Runtime\EcrToRuntimeConfigurationAdapter;
use CetechDeliveryEngine\Application\Runtime\RuntimeConfigurationSource;
use CetechDeliveryEngine\Application\Selector\ProductDeliveryOptionsBuilder;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\ScopedConfiguration;
use CetechDeliveryEngine\Domain\Configuration\ScalarFieldInstruction;
use CetechDeliveryEngine\Domain\Enum\ConfigurationScopeType;
use CetechDeliveryEngine\Domain\Enum\ConfigurationSource;
use CetechDeliveryEngine\Domain\Enum\EffectiveFieldState;
use CetechDeliveryEngine\Domain\Enum\FulfilmentAvailability;
use CetechDeliveryEngine\Domain\Enum\FulfilmentChoice;
use CetechDeliveryEngine\Domain\Enum\ProductTargetType;
use CetechDeliveryEngine\Domain\Enum\RecordStatus;
use CetechDeliveryEngine\Infrastructure\Persistence\InMemoryScopedConfigurationRepository;
use PHPUnit\Framework\TestCase;

/**
 * Stage 6A: ECR resolution for variable products (GLOBAL → PRODUCT → VARIATION inheritance).
 *
 * Parent product id: 202  |  Variation id: 303
 */
final class VariableEcrRuntimeTest extends TestCase {

	private const PARENT_ID    = 202;
	private const VARIATION_ID = 303;

	private InMemoryScopedConfigurationRepository $repository;
	private InMemoryDeliveryOfferRepository $offers;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->offers     = new InMemoryDeliveryOfferRepository();
		$this->offers->seed(
			7,
			[
				'status'                 => 'active',
				'public_label'           => 'Local Delivery',
				'public_description'     => 'Door delivery',
				'route'                  => 'local_delivery',
				'duration_unit'          => 'business_days',
				'default_processing_min' => 1,
				'default_processing_max' => 2,
				'default_transit_min'    => 1,
				'default_transit_max'    => 2,
				'default_final_mile_min' => 0,
				'default_final_mile_max' => 0,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Scalar inheritance
	// -------------------------------------------------------------------------

	public function test_variation_inherits_parent_entirely_when_no_variation_scope(): void {
		$this->seed_global();
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 55 );
		// No variation scope → variation inherits everything from parent.

		$source  = $this->source_with_inspector();
		$runtime = $source->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		self::assertSame( RuntimeConfigurationSource::ECR, $runtime->source );
		self::assertTrue( $runtime->result->success );

		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InStore->value ] ?? null;
		self::assertNotNull( $rule );
		self::assertSame( 55, $rule->priority );
		self::assertSame( [ 7 ], $rule->delivery_offer_ids );
		self::assertNotNull( $runtime->configuration_fingerprint );
	}

	public function test_variation_overrides_one_scalar_priority(): void {
		$this->seed_global();
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 100 );
		$this->seed_variation(
			self::PARENT_ID,
			self::VARIATION_ID,
			'in_store',
			scalars: [
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 25 ),
			]
		);

		$runtime = $this->source_with_inspector()->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		self::assertTrue( $runtime->result->success );
		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InStore->value ];
		self::assertSame( 25, $rule->priority );
		// Offer IDs still inherited from parent/global.
		self::assertSame( [ 7 ], $rule->delivery_offer_ids );
	}

	public function test_variation_disables_supplier_scalar(): void {
		$this->seed_global();
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 10 );
		$this->seed_variation(
			self::PARENT_ID,
			self::VARIATION_ID,
			'in_store',
			scalars: [
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::disable( ConfigurationFieldKey::SUPPLIER_ID ),
			]
		);

		// Use PassthroughFulfilmentConstraintService so constraint doesn't block.
		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);
		$source  = new EcrProductDeliveryConfigurationSource(
			$resolver,
			new EcrToRuntimeConfigurationAdapter(),
			new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] )
		);
		$runtime = $source->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		// The rule adapts successfully; supplier_id resolved to null (disabled at variation scope).
		self::assertTrue( $runtime->result->success );
		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InStore->value ];
		self::assertNull( $rule->supplier_id );
	}

	// -------------------------------------------------------------------------
	// Collection operations
	// -------------------------------------------------------------------------

	public function test_variation_collection_add_removes_and_replace(): void {
		// Global offers: [1,2,3]; Product adds [4]; Variation removes [2].
		$this->seed_global( offer_ids: [ 1, 2, 3 ] );
		$this->seed_product(
			self::PARENT_ID,
			'in_store',
			priority: 5,
			collections: [
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ 4 ]
				),
			]
		);
		$this->seed_variation(
			self::PARENT_ID,
			self::VARIATION_ID,
			'in_store',
			collections: [
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::remove(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ 2 ]
				),
			]
		);

		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);
		$config  = $resolver->resolve( new EffectiveConfigurationRequest( self::PARENT_ID, self::VARIATION_ID, 'in_store' ) );

		self::assertSame( [ 1, 3, 4 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( EffectiveFieldState::Valid, $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
	}

	public function test_variation_collection_replace_with_empty(): void {
		$this->seed_global( offer_ids: [ 1, 2, 3 ] );
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 5 );
		$this->seed_variation(
			self::PARENT_ID,
			self::VARIATION_ID,
			'in_store',
			collections: [
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[]
				),
			]
		);

		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);
		$config = $resolver->resolve( new EffectiveConfigurationRequest( self::PARENT_ID, self::VARIATION_ID, 'in_store' ) );

		self::assertSame( [], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( EffectiveFieldState::Valid, $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
	}

	// -------------------------------------------------------------------------
	// Multi-slice isolation
	// -------------------------------------------------------------------------

	public function test_multiple_slices_do_not_cross_merge(): void {
		// Global uses the default (empty) slice key as base.
		$this->seed_global( offer_ids: [ 7 ] );
		// Two product-level slices with different availability contexts.
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 1 );
		$this->seed_product( self::PARENT_ID, 'international_fulfilment', priority: 9 );

		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);
		$in_store      = $resolver->resolve( new EffectiveConfigurationRequest( self::PARENT_ID, null, 'in_store' ) );
		$international = $resolver->resolve( new EffectiveConfigurationRequest( self::PARENT_ID, null, 'international_fulfilment' ) );

		self::assertSame( 1, $in_store->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		self::assertSame( 9, $international->scalar( ConfigurationFieldKey::PRIORITY )?->value );
		// Slice fingerprints must differ.
		self::assertNotSame( $in_store->version->fingerprint, $international->version->fingerprint );
	}

	// -------------------------------------------------------------------------
	// Fail-closed on invalid/unresolved
	// -------------------------------------------------------------------------

	public function test_invalid_variation_relationship_fails_closed_as_ecr(): void {
		// Inspector has no mapping → inspect() returns ok=false.
		$inspector = new FixedVariationRelationshipInspector( [] );
		$source    = new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver(
				$this->repository,
				new EffectiveConfigurationValidator(),
				new PassthroughFulfilmentConstraintService()
			),
			new EcrToRuntimeConfigurationAdapter(),
			$inspector
		);

		$runtime = $source->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		self::assertSame( RuntimeConfigurationSource::ECR, $runtime->source );
		self::assertFalse( $runtime->result->success );
	}

	public function test_unresolved_global_fails_closed_as_ecr(): void {
		$this->repository->ensureGlobalScope();
		// Global scope exists but has no fields → config unresolved.

		$source  = $this->source_with_inspector();
		$runtime = $source->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		self::assertSame( RuntimeConfigurationSource::ECR, $runtime->source );
		self::assertFalse( $runtime->result->success );
	}

	public function test_no_variation_inspector_fails_closed_as_ecr(): void {
		$this->seed_global();
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 5 );

		// No inspector injected → resolve_variation returns failure.
		$source  = new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver(
				$this->repository,
				new EffectiveConfigurationValidator(),
				new PassthroughFulfilmentConstraintService()
			),
			new EcrToRuntimeConfigurationAdapter()
			// variation_inspector not passed → null
		);
		$runtime = $source->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		self::assertSame( RuntimeConfigurationSource::ECR, $runtime->source );
		self::assertFalse( $runtime->result->success );
	}

	// -------------------------------------------------------------------------
	// Admin/runtime resolver consistency
	// -------------------------------------------------------------------------

	public function test_admin_runtime_consistency_for_variation(): void {
		$this->seed_global();
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 5 );
		$this->seed_variation(
			self::PARENT_ID,
			self::VARIATION_ID,
			'in_store',
			scalars: [
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 3 ),
			]
		);

		$resolver = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new HardFulfilmentConstraintService( $this->offers )
		);

		$admin = $resolver->resolve(
			new EffectiveConfigurationRequest( self::PARENT_ID, self::VARIATION_ID, FulfilmentAvailability::InStore->value )
		);

		$runtime = ( new EcrProductDeliveryConfigurationSource(
			$resolver,
			new EcrToRuntimeConfigurationAdapter(),
			new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] )
		) )->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		self::assertTrue( $runtime->result->success );
		$rule = $runtime->result->chosen_rules[ FulfilmentAvailability::InStore->value ];

		self::assertSame( $admin->scalar( ConfigurationFieldKey::PRIORITY )?->value, $rule->priority );
		self::assertSame(
			$admin->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members,
			$rule->delivery_offer_ids
		);
	}

	public function test_variation_fingerprint_is_present_and_non_null(): void {
		$this->seed_global();
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 5 );

		$runtime = $this->source_with_inspector()->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );

		self::assertTrue( $runtime->result->success );
		self::assertNotNull( $runtime->configuration_fingerprint );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $runtime->configuration_fingerprint );
	}

	public function test_privacy_no_supplier_origin_in_public_options(): void {
		$this->seed_global();
		$this->seed_product( self::PARENT_ID, 'in_store', priority: 5 );

		$runtime = $this->source_with_inspector()->resolve( ProductTargetType::Variation->value, self::VARIATION_ID );
		$builder = new ProductDeliveryOptionsBuilder( $this->offers );
		$options = $builder->buildFromResolution( $runtime->result );

		self::assertNotEmpty( $options );
		$serialized = wp_json_encode( $options[0]->toArray() );
		self::assertIsString( $serialized );
		self::assertStringNotContainsString( 'supplier', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'origin', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'logistics', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'fingerprint', strtolower( $serialized ) );
		self::assertStringNotContainsString( 'provenance', strtolower( $serialized ) );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function source_with_inspector(): EcrProductDeliveryConfigurationSource {
		return new EcrProductDeliveryConfigurationSource(
			new EffectiveConfigurationResolver(
				$this->repository,
				new EffectiveConfigurationValidator(),
				new HardFulfilmentConstraintService( $this->offers )
			),
			new EcrToRuntimeConfigurationAdapter(),
			new FixedVariationRelationshipInspector( [ self::VARIATION_ID => self::PARENT_ID ] )
		);
	}

	/**
	 * @param list<int>                                 $offer_ids
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function seed_global(
		array $offer_ids = [ 7 ],
		array $scalars = [],
		array $collections = []
	): void {
		$base_scalars = array_merge(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InStore->value
				),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					FulfilmentChoice::Delivery->value
				),
				ConfigurationFieldKey::PRIORITY              => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 100 ),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
				ConfigurationFieldKey::SUPPLIER_ID          => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
				ConfigurationFieldKey::ORIGIN_ID            => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
			],
			$scalars
		);

		$base_collections = array_merge(
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					$offer_ids
				),
			],
			$collections
		);

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration( ConfigurationScope::global(), $base_scalars, $base_collections )
		);
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function seed_product(
		int $product_id,
		string $slice_key,
		int $priority = 100,
		array $scalars = [],
		array $collections = []
	): void {
		$base_scalars = array_merge(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InStore->value
				),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					FulfilmentChoice::Delivery->value
				),
				ConfigurationFieldKey::PRIORITY              => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, $priority ),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
				ConfigurationFieldKey::SUPPLIER_ID          => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
				ConfigurationFieldKey::ORIGIN_ID            => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
			],
			$scalars
		);

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Product,
					$product_id,
					$slice_key,
					null,
					RecordStatus::Active,
					1,
					ConfigurationSource::Native,
					null
				),
				$base_scalars,
				$collections
			)
		);
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalars
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function seed_variation(
		int $parent_id,
		int $variation_id,
		string $slice_key,
		array $scalars = [],
		array $collections = []
	): void {
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope(
					null,
					ConfigurationScopeType::Variation,
					$variation_id,
					$slice_key,
					$parent_id,
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
}
