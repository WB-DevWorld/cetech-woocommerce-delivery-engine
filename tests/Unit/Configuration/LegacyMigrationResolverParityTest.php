<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Tests\Unit\Configuration;

use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationResolver;
use CetechDeliveryEngine\Application\Configuration\EffectiveConfigurationValidator;
use CetechDeliveryEngine\Application\Configuration\PassthroughFulfilmentConstraintService;
use CetechDeliveryEngine\Domain\Configuration\CollectionFieldInstruction;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationFieldKey;
use CetechDeliveryEngine\Domain\Configuration\ConfigurationScope;
use CetechDeliveryEngine\Domain\Configuration\EffectiveConfigurationRequest;
use CetechDeliveryEngine\Domain\Configuration\LegacyProductRuleMigrationMapper;
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
 * Golden tests: Stage 2 migrated semantics ≡ Stage 3 effective resolution.
 */
final class LegacyMigrationResolverParityTest extends TestCase {

	private InMemoryScopedConfigurationRepository $repository;
	private EffectiveConfigurationResolver $resolver;
	private LegacyProductRuleMigrationMapper $mapper;

	protected function setUp(): void {
		$this->repository = new InMemoryScopedConfigurationRepository();
		$this->mapper     = new LegacyProductRuleMigrationMapper();
		$this->resolver   = new EffectiveConfigurationResolver(
			$this->repository,
			new EffectiveConfigurationValidator(),
			new PassthroughFulfilmentConstraintService()
		);
	}

	public function test_migrated_null_and_zero_ids_disable_beats_global_override(): void {
		$this->save_global_defaults(
			[
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 55 ),
				ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 66 ),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 77 ),
			]
		);

		$mapped = $this->mapper->map_row(
			[
				'id'                      => 11,
				'target_type'             => ProductTargetType::Product->value,
				'target_id'               => 101,
				'fulfilment_availability' => FulfilmentAvailability::InStore->value,
				'fulfilment_choice'       => FulfilmentChoice::Delivery->value,
				'supplier_id'             => null,
				'origin_id'               => 0,
				'logistics_profile_id'    => null,
				'priority'                => 100,
				'status'                  => RecordStatus::Active->value,
			],
			[ 1 ]
		);

		self::assertSame( 'migrate', $mapped['action'] );
		$this->repository->saveScopedConfiguration( $mapped['configuration'] );

		$config = $this->resolver->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InStore->value )
		);

		self::assertSame( EffectiveFieldState::Disabled, $config->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->state );
		self::assertNull( $config->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->value );
		self::assertSame( EffectiveFieldState::Disabled, $config->scalar( ConfigurationFieldKey::ORIGIN_ID )?->state );
		self::assertNull( $config->scalar( ConfigurationFieldKey::ORIGIN_ID )?->value );
		self::assertSame( EffectiveFieldState::Disabled, $config->scalar( ConfigurationFieldKey::LOGISTICS_PROFILE_ID )?->state );
		self::assertNull( $config->scalar( ConfigurationFieldKey::LOGISTICS_PROFILE_ID )?->value );
		self::assertNotSame( 55, $config->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->value );
	}

	public function test_migrated_offer_replace_does_not_merge_with_global(): void {
		$this->save_global_defaults(
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ 1, 2, 3 ]
				),
			]
		);

		$mapped = $this->mapper->map_row(
			[
				'id'                      => 12,
				'target_type'             => ProductTargetType::Product->value,
				'target_id'               => 101,
				'fulfilment_availability' => FulfilmentAvailability::InStore->value,
				'fulfilment_choice'       => FulfilmentChoice::Delivery->value,
				'supplier_id'             => 1,
				'origin_id'               => 1,
				'logistics_profile_id'    => 1,
				'priority'                => 10,
				'status'                  => RecordStatus::Active->value,
			],
			[ 4, 5 ]
		);

		$this->repository->saveScopedConfiguration( $mapped['configuration'] );

		$config = $this->resolver->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InStore->value )
		);

		self::assertSame( [ 4, 5 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertNotSame( [ 1, 2, 3, 4, 5 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_migrated_empty_offers_replace_empty_not_inherit_global(): void {
		$this->save_global_defaults(
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ 1, 2, 3 ]
				),
			]
		);

		$mapped = $this->mapper->map_row(
			[
				'id'                      => 13,
				'target_type'             => ProductTargetType::Product->value,
				'target_id'               => 101,
				'fulfilment_availability' => FulfilmentAvailability::InStore->value,
				'fulfilment_choice'       => FulfilmentChoice::StorePickup->value,
				'supplier_id'             => 1,
				'origin_id'               => 1,
				'logistics_profile_id'    => 1,
				'priority'                => 10,
				'status'                  => RecordStatus::Active->value,
			],
			[]
		);

		$this->repository->saveScopedConfiguration( $mapped['configuration'] );

		$config = $this->resolver->resolve(
			new EffectiveConfigurationRequest( 101, null, FulfilmentAvailability::InStore->value )
		);

		self::assertSame( [], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
		self::assertSame( EffectiveFieldState::Valid, $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->state );
	}

	public function test_global_supplier_change_respects_inherit_disable_and_override(): void {
		$this->save_global_defaults(
			[
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 12 ),
			]
		);

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 201, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::inherit( ConfigurationFieldKey::SUPPLIER_ID ) ],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS ),
				]
			)
		);
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 202, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::disable( ConfigurationFieldKey::SUPPLIER_ID ) ],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS ),
				]
			)
		);
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 203, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 33 ) ],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS ),
				]
			)
		);

		$this->save_global_defaults(
			[
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 44 ),
			]
		);
		$this->resolver->clearMemoization();

		$a = $this->resolver->resolve( new EffectiveConfigurationRequest( 201 ) );
		$b = $this->resolver->resolve( new EffectiveConfigurationRequest( 202 ) );
		$c = $this->resolver->resolve( new EffectiveConfigurationRequest( 203 ) );

		self::assertSame( 44, $a->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->value );
		self::assertSame( EffectiveFieldState::Disabled, $b->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->state );
		self::assertSame( 33, $c->scalar( ConfigurationFieldKey::SUPPLIER_ID )?->value );
	}

	public function test_unrelated_product_scope_change_does_not_change_fingerprint(): void {
		$this->save_global_defaults();
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 1 ) ],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS ),
				]
			)
		);

		$before = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 999, '', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[ ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 50 ) ],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::inherit( ConfigurationFieldKey::DELIVERY_OFFER_IDS ),
				]
			)
		);
		$this->resolver->clearMemoization();

		$after = $this->resolver->resolve( new EffectiveConfigurationRequest( 101 ) );

		self::assertSame( $before->version->fingerprint, $after->version->fingerprint );
	}

	public function test_product_replace_then_variation_add_collection_order(): void {
		$this->save_global_defaults(
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ 10, 20 ]
				),
			]
		);
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, 'in_store', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ 30 ]
					),
				]
			)
		);
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Variation, 501, 'in_store', 101, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ 40 ]
					),
				]
			)
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, 501, 'in_store' ) );

		self::assertSame( [ 30, 40 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_multi_level_collection_remove_then_add_preserves_order(): void {
		$this->save_global_defaults(
			[],
			[
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ 10, 20 ]
				),
			]
		);
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Product, 101, 'in_store', null, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::remove(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ 10 ]
					),
				]
			)
		);
		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration(
				new ConfigurationScope( null, ConfigurationScopeType::Variation, 501, 'in_store', 101, RecordStatus::Active, 1, ConfigurationSource::Native, null ),
				[],
				[
					ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::add(
						ConfigurationFieldKey::DELIVERY_OFFER_IDS,
						[ 10 ]
					),
				]
			)
		);

		$config = $this->resolver->resolve( new EffectiveConfigurationRequest( 101, 501, 'in_store' ) );

		self::assertSame( [ 20, 10 ], $config->collection( ConfigurationFieldKey::DELIVERY_OFFER_IDS )?->members );
	}

	public function test_category_legacy_rows_are_quarantined_not_resolved(): void {
		$mapped = $this->mapper->map_row(
			[
				'id'                      => 99,
				'target_type'             => ProductTargetType::Category->value,
				'target_id'               => 77,
				'fulfilment_availability' => FulfilmentAvailability::InWarehouse->value,
				'fulfilment_choice'       => FulfilmentChoice::Delivery->value,
				'priority'                => 100,
				'status'                  => RecordStatus::Active->value,
			],
			[ 1 ]
		);

		self::assertSame( 'quarantine', $mapped['action'] );
		self::assertNull( $mapped['configuration'] );
		self::assertNull( ConfigurationScopeType::tryFrom( 'category' ) );
	}

	/**
	 * @param array<string, ScalarFieldInstruction>     $scalar_overrides
	 * @param array<string, CollectionFieldInstruction> $collections
	 */
	private function save_global_defaults( array $scalar_overrides = [], array $collections = [] ): void {
		$scalars = array_merge(
			[
				ConfigurationFieldKey::FULFILMENT_AVAILABILITY => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_AVAILABILITY,
					FulfilmentAvailability::InStore->value
				),
				ConfigurationFieldKey::FULFILMENT_CHOICE => ScalarFieldInstruction::override(
					ConfigurationFieldKey::FULFILMENT_CHOICE,
					FulfilmentChoice::Delivery->value
				),
				ConfigurationFieldKey::LOGISTICS_PROFILE_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::LOGISTICS_PROFILE_ID, 10 ),
				ConfigurationFieldKey::SUPPLIER_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::SUPPLIER_ID, 20 ),
				ConfigurationFieldKey::ORIGIN_ID => ScalarFieldInstruction::override( ConfigurationFieldKey::ORIGIN_ID, 30 ),
				ConfigurationFieldKey::PRIORITY => ScalarFieldInstruction::override( ConfigurationFieldKey::PRIORITY, 5 ),
			],
			$scalar_overrides
		);

		if ( [] === $collections ) {
			$collections = [
				ConfigurationFieldKey::DELIVERY_OFFER_IDS => CollectionFieldInstruction::replace(
					ConfigurationFieldKey::DELIVERY_OFFER_IDS,
					[ 1 ]
				),
			];
		}

		$this->repository->saveScopedConfiguration(
			new ScopedConfiguration( ConfigurationScope::global(), $scalars, $collections )
		);
	}
}
